<?php
/**
 * 有給 CSVインポート（付与履歴 / 消化履歴）
 *
 * ■ 付与インポート（grants）
 *   CSV列: 社員番号, 有給発生日, 付与日数 （末尾の余分な空カラムは無視）
 *   - 失効日(expiry_date) は「付与日 + expiration_years年」で自動計算
 *   - 失効日 < 今日 → is_expired=1 / remaining_days=0
 *   - 失効日 >= 今日 → is_expired=0 / remaining_days=付与日数（満額）
 *
 * ■ 消化インポート（consumptions）
 *   CSV列: 社員番号, 消化日 （全休=1.0固定。末尾の余分な空カラムは無視）
 *   - 充当は「消化日基準FIFO」: grant_date <= 消化日 <= expiry_date の付与を
 *     grant_date の古い順に充当（消化日の古い順に処理）
 *   - 失効済み付与への充当: 消化レコードのみ記録し remaining_days(=0) は据え置き
 *   - 有効な付与への充当: remaining_days を減算（案A）
 *   - 充当先が無い / 残不足の行はエラー（取込まない）
 *   - (社員番号, 消化日) が既存なら重複スキップ
 *   ※ 付与インポートが完了している前提（先に付与、後で消化）
 *
 * いずれも 2段階方式（プレビュー → 本実行）。取込対象は transient に一時保存。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Import {

    /** 表示するエラー/スキップ明細の上限 */
    const MAX_DETAIL_ROWS = 200;

    /** transient キー（ユーザー単位） */
    const TRANSIENT_PREFIX         = 'pl_import_rows_';          // 付与
    const TRANSIENT_PREFIX_CONSUME = 'pl_import_consume_rows_';  // 消化
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    /** アップロード許容サイズ（30MB） */
    const MAX_UPLOAD_BYTES = 31457280;

    // =====================================================
    //  初期化
    // =====================================================
    public static function init() {
        // 付与
        add_action( 'wp_ajax_pl_import_preview', array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_pl_import_execute', array( __CLASS__, 'ajax_execute' ) );
        // 消化
        add_action( 'wp_ajax_pl_consume_import_preview', array( __CLASS__, 'ajax_consume_preview' ) );
        add_action( 'wp_ajax_pl_consume_import_execute', array( __CLASS__, 'ajax_consume_execute' ) );
    }

    // =====================================================
    //  共通: アップロード検証
    // =====================================================
    private static function validate_upload() {
        if ( empty( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['tmp_name'] ) ) {
            return new WP_Error( 'no_file', 'ファイルが選択されていません。' );
        }
        $file = $_FILES['csv_file'];
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'upload_error', 'アップロードに失敗しました（エラーコード: ' . (int) $file['error'] . '）。' );
        }
        if ( $file['size'] > self::MAX_UPLOAD_BYTES ) {
            return new WP_Error( 'too_large', 'ファイルサイズが大きすぎます（上限 ' . round( self::MAX_UPLOAD_BYTES / 1048576 ) . 'MB）。' );
        }
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' && $ext !== 'txt' ) {
            return new WP_Error( 'bad_ext', 'CSVファイル（.csv）を選択してください。' );
        }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'not_uploaded', '不正なアップロードです。' );
        }
        return $file['tmp_name'];
    }

    // =====================================================
    //  付与: プレビュー
    // =====================================================
    public static function ajax_preview() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
        $tmp = self::validate_upload();
        if ( is_wp_error( $tmp ) ) wp_send_json_error( array( 'message' => $tmp->get_error_message() ) );

        $result = self::parse_file( $tmp );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );

        $uid = get_current_user_id();
        set_transient( self::TRANSIENT_PREFIX . $uid, $result['valid_rows'], self::TRANSIENT_TTL );

        wp_send_json_success( array(
            'summary'    => $result['summary'],
            'errors'     => array_slice( $result['errors'], 0, self::MAX_DETAIL_ROWS ),
            'dups'       => array_slice( $result['dups'],   0, self::MAX_DETAIL_ROWS ),
            'sample'     => array_slice( $result['valid_rows'], 0, 20 ),
            'can_import' => count( $result['valid_rows'] ) > 0,
        ) );
    }

    // =====================================================
    //  付与: 本実行
    // =====================================================
    public static function ajax_execute() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $uid  = get_current_user_id();
        $rows = get_transient( self::TRANSIENT_PREFIX . $uid );
        if ( empty( $rows ) || ! is_array( $rows ) ) {
            wp_send_json_error( array( 'message' => 'プレビュー結果が見つかりません（有効期限切れの可能性があります）。もう一度ファイルを選択してプレビューしてください。' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_grants';
        $now   = current_time( 'mysql' );

        $existing = self::get_existing_grant_keys();

        $inserted = 0; $skipped = 0; $valid_cnt = 0; $expired_cnt = 0; $failed = array();

        foreach ( $rows as $r ) {
            $key = $r['employee_code'] . '|' . $r['grant_date'];
            if ( isset( $existing[ $key ] ) ) { $skipped++; continue; }

            $ok = $wpdb->insert(
                $table,
                array(
                    'employee_code'             => $r['employee_code'],
                    'tenure_months'             => $r['tenure_months'],
                    'weekly_work_days_at_grant' => $r['weekly_days'],
                    'grant_date'                => $r['grant_date'],
                    'expiry_date'               => $r['expiry_date'],
                    'granted_days'              => $r['granted_days'],
                    'remaining_days'            => $r['remaining_days'],
                    'is_expired'                => $r['is_expired'],
                    'created_at'                => $now,
                    'updated_at'                => $now,
                ),
                array( '%s','%d','%d','%s','%s','%f','%f','%d','%s','%s' )
            );
            if ( $ok === false ) { $failed[] = $r['employee_code'] . ' / ' . $r['grant_date']; continue; }

            $existing[ $key ] = true;
            $inserted++;
            if ( $r['is_expired'] ) $expired_cnt++; else $valid_cnt++;
        }

        delete_transient( self::TRANSIENT_PREFIX . $uid );

        $msg = $inserted . ' 件を登録しました。';
        if ( $skipped > 0 ) $msg .= '（既存と重複のため ' . $skipped . ' 件スキップ）';

        wp_send_json_success( array(
            'message'      => $msg,
            'inserted'     => $inserted,
            'valid_cnt'    => $valid_cnt,
            'expired_cnt'  => $expired_cnt,
            'skipped'      => $skipped,
            'failed'       => array_slice( $failed, 0, self::MAX_DETAIL_ROWS ),
            'failed_count' => count( $failed ),
        ) );
    }

    // =====================================================
    //  付与: CSV解析
    // =====================================================
    private static function parse_file( $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) return new WP_Error( 'open_failed', 'ファイルを開けませんでした。' );

        $expiration_years = (int) PL_Rules::get_setting( 'expiration_years', 2 );
        if ( $expiration_years <= 0 ) $expiration_years = 2;
        $today = date( 'Y-m-d' );

        $existing  = self::get_existing_grant_keys();
        $emp_cache = array();

        $valid_rows = array(); $errors = array(); $dups = array();
        $line_no = 0; $data_rows = 0; $blank_skipped = 0; $seen_in_file = array();

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $line_no++;
            if ( $line_no === 1 ) continue; // header

            $code = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
            $code = self::strip_bom( $code );
            if ( $code === '' ) { $blank_skipped++; continue; }
            $data_rows++;

            $grant_raw = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
            $days_raw  = isset( $row[2] ) ? trim( (string) $row[2] ) : '';

            $grant_date = self::normalize_date( $grant_raw );
            if ( $grant_date === null ) {
                $errors[] = self::err( $line_no, $code, '有給発生日が不正です（' . $grant_raw . '）' );
                continue;
            }
            if ( $days_raw === '' || ! is_numeric( $days_raw ) || (float) $days_raw <= 0 ) {
                $errors[] = self::err( $line_no, $code, '付与日数が不正です（' . $days_raw . '）' );
                continue;
            }
            $granted_days = (float) $days_raw;

            if ( ! array_key_exists( $code, $emp_cache ) ) {
                $emp_cache[ $code ] = PL_Employee_Bridge::get_by_code( $code );
            }
            $emp = $emp_cache[ $code ];
            if ( ! $emp ) {
                $errors[] = self::err( $line_no, $code, '社員マスタに存在しない社員番号です' );
                continue;
            }

            $key = $code . '|' . $grant_date;
            if ( isset( $existing[ $key ] ) || isset( $seen_in_file[ $key ] ) ) {
                $dups[] = self::err( $line_no, $code, '既に登録済み（付与日 ' . $grant_date . '）' );
                continue;
            }
            $seen_in_file[ $key ] = true;

            $expiry_date = date( 'Y-m-d', strtotime( $grant_date . ' +' . $expiration_years . ' years -1 day' ) );
            $is_expired  = ( $expiry_date < $today ) ? 1 : 0;
            $remaining   = $is_expired ? 0.0 : $granted_days;

            $tenure_months = self::calc_tenure_months( isset( $emp->hire_date ) ? $emp->hire_date : '', $grant_date );
            $weekly_days   = (int) ( isset( $emp->weekly_work_days ) ? $emp->weekly_work_days : 5 );
            if ( $weekly_days <= 0 ) $weekly_days = 5;

            $valid_rows[] = array(
                'employee_code'  => $code,
                'name'           => isset( $emp->name ) ? $emp->name : '',
                'grant_date'     => $grant_date,
                'granted_days'   => $granted_days,
                'expiry_date'    => $expiry_date,
                'is_expired'     => $is_expired,
                'remaining_days' => $remaining,
                'tenure_months'  => $tenure_months,
                'weekly_days'    => $weekly_days,
            );
        }
        fclose( $fh );

        $expired_in_valid = 0;
        foreach ( $valid_rows as $vr ) { if ( $vr['is_expired'] ) $expired_in_valid++; }

        $summary = array(
            'data_rows'     => $data_rows,
            'blank_skipped' => $blank_skipped,
            'valid'         => count( $valid_rows ),
            'valid_active'  => count( $valid_rows ) - $expired_in_valid,
            'valid_expired' => $expired_in_valid,
            'error'         => count( $errors ),
            'dup'           => count( $dups ),
        );

        return array( 'summary' => $summary, 'valid_rows' => $valid_rows, 'errors' => $errors, 'dups' => $dups );
    }

    // =====================================================
    //  消化: プレビュー
    // =====================================================
    public static function ajax_consume_preview() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
        $tmp = self::validate_upload();
        if ( is_wp_error( $tmp ) ) wp_send_json_error( array( 'message' => $tmp->get_error_message() ) );

        $result = self::parse_consume_file( $tmp );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );

        // transient には最小情報（社員番号・消化日）のみ保存。本実行で充当を再計算する
        $minimal = array();
        foreach ( $result['valid_rows'] as $vr ) {
            $minimal[] = array( 'employee_code' => $vr['employee_code'], 'consumed_date' => $vr['consumed_date'] );
        }
        $uid = get_current_user_id();
        set_transient( self::TRANSIENT_PREFIX_CONSUME . $uid, $minimal, self::TRANSIENT_TTL );

        wp_send_json_success( array(
            'summary'    => $result['summary'],
            'errors'     => array_slice( $result['errors'], 0, self::MAX_DETAIL_ROWS ),
            'dups'       => array_slice( $result['dups'],   0, self::MAX_DETAIL_ROWS ),
            'sample'     => array_slice( $result['valid_rows'], 0, 20 ),
            'can_import' => count( $result['valid_rows'] ) > 0,
        ) );
    }

    // =====================================================
    //  消化: 本実行
    // =====================================================
    public static function ajax_consume_execute() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $uid  = get_current_user_id();
        $rows = get_transient( self::TRANSIENT_PREFIX_CONSUME . $uid );
        if ( empty( $rows ) || ! is_array( $rows ) ) {
            wp_send_json_error( array( 'message' => 'プレビュー結果が見つかりません（有効期限切れの可能性があります）。もう一度ファイルを選択してプレビューしてください。' ) );
        }

        global $wpdb;
        $ctable = $wpdb->prefix . 'paidleave_consumptions';
        $gtable = $wpdb->prefix . 'paidleave_grants';
        $now    = current_time( 'mysql' );

        $existing   = self::get_existing_consumption_keys();
        $emp_grants = array();

        $inserted = 0; $skipped = 0; $failed = array();
        $to_valid = 0.0; $to_expired = 0.0; // 充当先の内訳（日数）

        foreach ( $rows as $r ) {
            $code  = $r['employee_code'];
            $cdate = $r['consumed_date'];
            $key   = $code . '|' . $cdate;

            if ( isset( $existing[ $key ] ) ) { $skipped++; continue; }

            if ( ! isset( $emp_grants[ $code ] ) ) {
                $emp_grants[ $code ] = self::load_employee_grants( $code );
            }
            $alloc = self::allocate_consumption( $emp_grants[ $code ], $cdate, 1.0 );
            if ( ! $alloc['ok'] ) { $failed[] = $code . ' / ' . $cdate; continue; }

            foreach ( $alloc['allocations'] as $a ) {
                $wpdb->insert(
                    $ctable,
                    array(
                        'grant_id'      => $a['grant_id'],
                        'employee_code' => $code,
                        'consumed_date' => $cdate,
                        'consumed_days' => $a['amount'],
                        'unit_type'     => 'day',
                        'note'          => '履歴インポート',
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ),
                    array( '%d','%s','%s','%f','%s','%s','%s','%s' )
                );

                // 有効な付与のみ remaining_days を減算（失効済みは据え置き）
                if ( (int) $a['is_expired'] === 0 ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$gtable} SET remaining_days = GREATEST(0, remaining_days - %f), updated_at = %s WHERE id = %d",
                        $a['amount'], $now, $a['grant_id']
                    ) );
                    $to_valid += $a['amount'];
                } else {
                    $to_expired += $a['amount'];
                }
            }

            $existing[ $key ] = true;
            $inserted++;
        }

        delete_transient( self::TRANSIENT_PREFIX_CONSUME . $uid );

        $msg = $inserted . ' 件の消化を登録しました。';
        if ( $skipped > 0 ) $msg .= '（既存と重複のため ' . $skipped . ' 件スキップ）';

        wp_send_json_success( array(
            'message'      => $msg,
            'inserted'     => $inserted,
            'to_valid'     => $to_valid,
            'to_expired'   => $to_expired,
            'skipped'      => $skipped,
            'failed'       => array_slice( $failed, 0, self::MAX_DETAIL_ROWS ),
            'failed_count' => count( $failed ),
        ) );
    }

    // =====================================================
    //  消化: CSV解析（充当シミュレーション込み）
    // =====================================================
    private static function parse_consume_file( $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) return new WP_Error( 'open_failed', 'ファイルを開けませんでした。' );

        $existing_consume = self::get_existing_consumption_keys();
        $emp_cache = array();

        $candidates = array(); // 基本チェックを通過した行: [code, date, line, name]
        $errors = array(); $dups = array();
        $line_no = 0; $data_rows = 0; $blank_skipped = 0; $seen_in_file = array();

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $line_no++;
            if ( $line_no === 1 ) continue; // header

            $code = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
            $code = self::strip_bom( $code );
            if ( $code === '' ) { $blank_skipped++; continue; }
            $data_rows++;

            $date_raw = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
            $cdate = self::normalize_date( $date_raw );
            if ( $cdate === null ) {
                $errors[] = self::err( $line_no, $code, '消化日が不正です（' . $date_raw . '）' );
                continue;
            }

            if ( ! array_key_exists( $code, $emp_cache ) ) {
                $emp_cache[ $code ] = PL_Employee_Bridge::get_by_code( $code );
            }
            $emp = $emp_cache[ $code ];
            if ( ! $emp ) {
                $errors[] = self::err( $line_no, $code, '社員マスタに存在しない社員番号です' );
                continue;
            }

            $key = $code . '|' . $cdate;
            if ( isset( $existing_consume[ $key ] ) || isset( $seen_in_file[ $key ] ) ) {
                $dups[] = self::err( $line_no, $code, '既に消化登録済み（' . $cdate . '）' );
                continue;
            }
            $seen_in_file[ $key ] = true;

            $candidates[] = array(
                'code' => $code,
                'date' => $cdate,
                'line' => $line_no,
                'name' => isset( $emp->name ) ? $emp->name : '',
            );
        }
        fclose( $fh );

        // 消化日の古い順（社員ごと）に処理してFIFO充当
        usort( $candidates, function( $a, $b ) {
            if ( $a['code'] === $b['code'] ) return strcmp( $a['date'], $b['date'] );
            return strcmp( $a['code'], $b['code'] );
        } );

        $emp_grants = array();
        $valid_rows = array();

        foreach ( $candidates as $cand ) {
            $code = $cand['code'];
            if ( ! isset( $emp_grants[ $code ] ) ) {
                $emp_grants[ $code ] = self::load_employee_grants( $code );
            }
            $alloc = self::allocate_consumption( $emp_grants[ $code ], $cand['date'], 1.0 );
            if ( ! $alloc['ok'] ) {
                $errors[] = self::err( $cand['line'], $code, $alloc['reason'] );
                continue;
            }

            // サンプル表示用に充当先（先頭）の付与日・失効状態を控える
            $first = $alloc['allocations'][0];
            $valid_rows[] = array(
                'employee_code'    => $code,
                'name'             => $cand['name'],
                'consumed_date'    => $cand['date'],
                'consumed_days'    => 1.0,
                'target_grant'     => $first['grant_date'],
                'target_expired'   => (int) $first['is_expired'],
            );
        }

        $summary = array(
            'data_rows'     => $data_rows,
            'blank_skipped' => $blank_skipped,
            'valid'         => count( $valid_rows ),
            'error'         => count( $errors ),
            'dup'           => count( $dups ),
        );

        return array( 'summary' => $summary, 'valid_rows' => $valid_rows, 'errors' => $errors, 'dups' => $dups );
    }

    /**
     * 消化日基準FIFOで 1件分（need日）の充当先を決める。
     * $grants の capacity を消費（成功時）する。失敗時はロールバック。
     *
     * @return array ok=true: { ok, allocations:[ {grant_id, amount, is_expired, grant_date} ] }
     *               ok=false: { ok, reason }
     */
    private static function allocate_consumption( &$grants, $consumed_date, $need ) {
        $eligible_exists = false;
        $allocations = array();
        $remaining_need = (float) $need;

        foreach ( $grants as $i => $g ) {
            if ( $g['grant_date'] <= $consumed_date && $consumed_date <= $g['expiry_date'] ) {
                $eligible_exists = true;
                if ( $g['capacity'] <= 0 ) continue;
                if ( $remaining_need <= 0 ) break;

                $take = min( $g['capacity'], $remaining_need );
                $grants[ $i ]['capacity'] = $g['capacity'] - $take;
                $allocations[] = array(
                    'grant_id'   => $g['id'],
                    'amount'     => $take,
                    'is_expired' => (int) $g['is_expired'],
                    'grant_date' => $g['grant_date'],
                );
                $remaining_need -= $take;
            }
        }

        if ( $remaining_need > 0.0001 ) {
            // 充当しきれなかった場合は capacity を元に戻す
            foreach ( $allocations as $a ) {
                foreach ( $grants as $i => $g ) {
                    if ( $g['id'] === $a['grant_id'] ) {
                        $grants[ $i ]['capacity'] = $g['capacity'] + $a['amount'];
                        break;
                    }
                }
            }
            $reason = $eligible_exists
                ? '消化日に有効な付与の残が不足しています（' . $consumed_date . '）'
                : '消化日に有効だった付与が見つかりません（' . $consumed_date . '）';
            return array( 'ok' => false, 'reason' => $reason );
        }

        return array( 'ok' => true, 'allocations' => $allocations );
    }

    /**
     * 社員の付与一覧を「充当可能容量(capacity)」付きで取得（grant_date 昇順）。
     * capacity = 付与日数 − 既存の消化合計（失効済みでも実容量で算出）
     */
    private static function load_employee_grants( $code ) {
        global $wpdb;
        $g = $wpdb->prefix . 'paidleave_grants';
        $c = $wpdb->prefix . 'paidleave_consumptions';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.id, g.grant_date, g.expiry_date, g.granted_days, g.is_expired, g.remaining_days,
                    COALESCE( (SELECT SUM(x.consumed_days) FROM {$c} x WHERE x.grant_id = g.id), 0 ) AS consumed_so_far
             FROM {$g} g
             WHERE g.employee_code = %s
             ORDER BY g.grant_date ASC, g.id ASC",
            $code
        ), ARRAY_A );

        $grants = array();
        foreach ( (array) $rows as $r ) {
            $cap = (float) $r['granted_days'] - (float) $r['consumed_so_far'];
            if ( $cap < 0 ) $cap = 0.0;
            $grants[] = array(
                'id'           => (int) $r['id'],
                'grant_date'   => $r['grant_date'],
                'expiry_date'  => $r['expiry_date'],
                'granted_days' => (float) $r['granted_days'],
                'is_expired'   => (int) $r['is_expired'],
                'capacity'     => $cap,
            );
        }
        return $grants;
    }

    // =====================================================
    //  ヘルパー（共通）
    // =====================================================

    /** 既存の (employee_code|grant_date) */
    private static function get_existing_grant_keys() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_grants';
        $rows = $wpdb->get_col( "SELECT CONCAT(employee_code, '|', grant_date) FROM {$table}" );
        $map  = array();
        if ( $rows ) foreach ( $rows as $k ) $map[ $k ] = true;
        return $map;
    }

    /** 既存の (employee_code|consumed_date) */
    private static function get_existing_consumption_keys() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_consumptions';
        $rows = $wpdb->get_col( "SELECT CONCAT(employee_code, '|', consumed_date) FROM {$table}" );
        $map  = array();
        if ( $rows ) foreach ( $rows as $k ) $map[ $k ] = true;
        return $map;
    }

    /** 先頭のUTF-8 BOMを除去 */
    private static function strip_bom( $s ) {
        if ( substr( $s, 0, 3 ) === "\xEF\xBB\xBF" ) return substr( $s, 3 );
        return $s;
    }

    /** 日付を YYYY-MM-DD に正規化（YYYY-MM-DD / YYYY/M/D 受理）。不正なら null */
    private static function normalize_date( $raw ) {
        $raw = trim( $raw );
        if ( $raw === '' ) return null;
        $raw = str_replace( '/', '-', $raw );
        $parts = explode( '-', $raw );
        if ( count( $parts ) !== 3 ) return null;
        $y = (int) $parts[0]; $m = (int) $parts[1]; $d = (int) $parts[2];
        if ( $y < 1900 || $y > 2100 ) return null;
        if ( ! checkdate( $m, $d, $y ) ) return null;
        return sprintf( '%04d-%02d-%02d', $y, $m, $d );
    }

    /** 入社日と付与日から勤続月数（年×12＋月）。不明・逆転は0 */
    private static function calc_tenure_months( $hire_date, $grant_date ) {
        $hire_date = trim( (string) $hire_date );
        if ( $hire_date === '' ) return 0;
        $hire = date_create( $hire_date );
        $grant = date_create( $grant_date );
        if ( ! $hire || ! $grant ) return 0;
        if ( $grant < $hire ) return 0;
        $diff = $hire->diff( $grant );
        return $diff->y * 12 + $diff->m;
    }

    /** エラー/スキップ明細の1行 */
    private static function err( $line_no, $code, $reason ) {
        return array( 'line' => $line_no, 'code' => $code, 'reason' => $reason );
    }
}
