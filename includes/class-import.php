<?php
/**
 * 有給付与履歴 CSVインポート
 *
 * 過去の有給付与台帳（Excel由来CSV）を wp_paidleave_grants に一括取込する。
 *
 * - CSV列構成: 社員番号, 有給発生日, 付与日数 （末尾の余分な空カラムは無視）
 * - 失効日(expiry_date)はCSVからは取らず「付与日 + expiration_years年」で自動計算
 * - 失効日 < 今日 の付与は is_expired=1 / remaining_days=0 で取込
 * - 失効日 >= 今日 の付与は is_expired=0 / remaining_days=付与日数（満額）で取込
 * - 消化(consumptions)は今回対象外（後日、消化日基準で別途インポート）
 *
 * 2段階方式:
 *   ① pl_import_preview … アップロード→解析→取込予定/スキップ/エラーを集計して返す
 *                          （正規化済みの取込対象行は transient に一時保存）
 *   ② pl_import_execute … transient から読み出して INSERT
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Import {

    /** 表示するエラー/スキップ明細の上限（画面が重くならないように） */
    const MAX_DETAIL_ROWS = 200;

    /** transient のキー接頭辞（ユーザー単位で保持） */
    const TRANSIENT_PREFIX = 'pl_import_rows_';

    /** transient の保持時間 */
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    /** アップロード許容サイズ（バイト）。空行を含む大きめのCSVを想定して 30MB */
    const MAX_UPLOAD_BYTES = 31457280;

    // =====================================================
    //  初期化
    // =====================================================
    public static function init() {
        add_action( 'wp_ajax_pl_import_preview', array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_pl_import_execute', array( __CLASS__, 'ajax_execute' ) );
    }

    // =====================================================
    //  AJAX: ① プレビュー（解析のみ・DB登録はしない）
    // =====================================================
    public static function ajax_preview() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        // --- アップロード検証 ---
        if ( empty( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => 'ファイルが選択されていません。' ) );
        }
        $file = $_FILES['csv_file'];

        if ( ! empty( $file['error'] ) ) {
            wp_send_json_error( array( 'message' => 'アップロードに失敗しました（エラーコード: ' . (int) $file['error'] . '）。' ) );
        }
        if ( $file['size'] > self::MAX_UPLOAD_BYTES ) {
            wp_send_json_error( array( 'message' => 'ファイルサイズが大きすぎます（上限 ' . round( self::MAX_UPLOAD_BYTES / 1048576 ) . 'MB）。' ) );
        }
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' && $ext !== 'txt' ) {
            wp_send_json_error( array( 'message' => 'CSVファイル（.csv）を選択してください。' ) );
        }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => '不正なアップロードです。' ) );
        }

        // --- 解析 ---
        $result = self::parse_file( $file['tmp_name'] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // 取込対象行を transient に保存（本実行で再アップロード不要にする）
        $uid = get_current_user_id();
        set_transient( self::TRANSIENT_PREFIX . $uid, $result['valid_rows'], self::TRANSIENT_TTL );

        wp_send_json_success( array(
            'summary' => $result['summary'],
            'errors'  => array_slice( $result['errors'], 0, self::MAX_DETAIL_ROWS ),
            'dups'    => array_slice( $result['dups'],   0, self::MAX_DETAIL_ROWS ),
            'sample'  => array_slice( $result['valid_rows'], 0, 20 ), // 先頭20件のプレビュー
            'can_import' => count( $result['valid_rows'] ) > 0,
        ) );
    }

    // =====================================================
    //  AJAX: ② 本実行（transient から INSERT）
    // =====================================================
    public static function ajax_execute() {
        check_ajax_referer( 'pl_import_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $uid  = get_current_user_id();
        $rows = get_transient( self::TRANSIENT_PREFIX . $uid );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            wp_send_json_error( array( 'message' => 'プレビュー結果が見つかりません（有効期限切れの可能性があります）。お手数ですが、もう一度ファイルを選択してプレビューしてください。' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_grants';
        $now   = current_time( 'mysql' );

        // 取込直前に既存(employee_code, grant_date)を再取得して二重登録を防止
        $existing = self::get_existing_grant_keys();

        $inserted = 0;
        $skipped  = 0;
        $valid_cnt   = 0;
        $expired_cnt = 0;
        $failed   = array();

        foreach ( $rows as $r ) {
            $key = $r['employee_code'] . '|' . $r['grant_date'];
            if ( isset( $existing[ $key ] ) ) {
                $skipped++;
                continue;
            }

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

            if ( $ok === false ) {
                $failed[] = $r['employee_code'] . ' / ' . $r['grant_date'];
                continue;
            }

            $existing[ $key ] = true; // ファイル内の重複も弾く
            $inserted++;
            if ( $r['is_expired'] ) {
                $expired_cnt++;
            } else {
                $valid_cnt++;
            }
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
    //  CSV解析（プレビュー・本実行共通の正規化ロジック）
    // =====================================================
    private static function parse_file( $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return new WP_Error( 'open_failed', 'ファイルを開けませんでした。' );
        }

        // 失効年数（既定2年）
        $expiration_years = (int) PL_Rules::get_setting( 'expiration_years', 2 );
        if ( $expiration_years <= 0 ) $expiration_years = 2;

        $today = date( 'Y-m-d' );

        $existing  = self::get_existing_grant_keys();
        $emp_cache = array(); // employee_code => emp object|null

        $valid_rows = array();
        $errors     = array();
        $dups       = array();

        $line_no       = 0;
        $data_rows      = 0; // 社員番号が入っている行数
        $blank_skipped  = 0; // 空行（社員番号なし）
        $seen_in_file   = array(); // ファイル内重複検出用

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $line_no++;

            // 1行目はヘッダー（BOM除去のうえスキップ）
            if ( $line_no === 1 ) {
                continue;
            }

            // fgetcsv は空行に対して array(null) を返す
            $code = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
            $code = self::strip_bom( $code );

            // 社員番号が空 → 空行・空カラムとしてスキップ（明細には出さない）
            if ( $code === '' ) {
                $blank_skipped++;
                continue;
            }

            $data_rows++;

            $grant_raw = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
            $days_raw  = isset( $row[2] ) ? trim( (string) $row[2] ) : '';

            // --- 付与日の検証・正規化 ---
            $grant_date = self::normalize_date( $grant_raw );
            if ( $grant_date === null ) {
                $errors[] = self::err( $line_no, $code, '有給発生日が不正です（' . $grant_raw . '）' );
                continue;
            }

            // --- 付与日数の検証 ---
            if ( $days_raw === '' || ! is_numeric( $days_raw ) || (float) $days_raw <= 0 ) {
                $errors[] = self::err( $line_no, $code, '付与日数が不正です（' . $days_raw . '）' );
                continue;
            }
            $granted_days = (float) $days_raw;

            // --- 社員の存在確認（emp_master） ---
            if ( ! array_key_exists( $code, $emp_cache ) ) {
                $emp_cache[ $code ] = PL_Employee_Bridge::get_by_code( $code );
            }
            $emp = $emp_cache[ $code ];
            if ( ! $emp ) {
                $errors[] = self::err( $line_no, $code, '社員マスタに存在しない社員番号です' );
                continue;
            }

            // --- 重複チェック（DB既存 + ファイル内） ---
            $key = $code . '|' . $grant_date;
            if ( isset( $existing[ $key ] ) || isset( $seen_in_file[ $key ] ) ) {
                $dups[] = self::err( $line_no, $code, '既に登録済み（付与日 ' . $grant_date . '）' );
                continue;
            }
            $seen_in_file[ $key ] = true;

            // --- 失効日・失効判定・残日数 ---
            $expiry_date = date( 'Y-m-d', strtotime( $grant_date . ' +' . $expiration_years . ' years' ) );
            $is_expired  = ( $expiry_date < $today ) ? 1 : 0;
            $remaining   = $is_expired ? 0.0 : $granted_days;

            // --- 勤続月数（入社日と付与日から）・週勤務日数 ---
            $tenure_months = self::calc_tenure_months( $emp->hire_date ?? '', $grant_date );
            $weekly_days   = (int) ( $emp->weekly_work_days ?? 5 );
            if ( $weekly_days <= 0 ) $weekly_days = 5;

            $valid_rows[] = array(
                'employee_code' => $code,
                'name'          => isset( $emp->name ) ? $emp->name : '',
                'grant_date'    => $grant_date,
                'granted_days'  => $granted_days,
                'expiry_date'   => $expiry_date,
                'is_expired'    => $is_expired,
                'remaining_days'=> $remaining,
                'tenure_months' => $tenure_months,
                'weekly_days'   => $weekly_days,
            );
        }
        fclose( $fh );

        $expired_in_valid = 0;
        foreach ( $valid_rows as $vr ) {
            if ( $vr['is_expired'] ) $expired_in_valid++;
        }

        $summary = array(
            'data_rows'     => $data_rows,             // 社員番号入りの行
            'blank_skipped' => $blank_skipped,         // 空行
            'valid'         => count( $valid_rows ),   // 取込対象
            'valid_active'  => count( $valid_rows ) - $expired_in_valid, // うち有効
            'valid_expired' => $expired_in_valid,      // うち失効
            'error'         => count( $errors ),       // エラー
            'dup'           => count( $dups ),         // 重複スキップ
        );

        return array(
            'summary'    => $summary,
            'valid_rows' => $valid_rows,
            'errors'     => $errors,
            'dups'       => $dups,
        );
    }

    // =====================================================
    //  ヘルパー
    // =====================================================

    /** 既存の (employee_code|grant_date) を連想配列で取得 */
    private static function get_existing_grant_keys() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_grants';
        $rows = $wpdb->get_col( "SELECT CONCAT(employee_code, '|', grant_date) FROM {$table}" );
        $map  = array();
        if ( $rows ) {
            foreach ( $rows as $k ) {
                $map[ $k ] = true;
            }
        }
        return $map;
    }

    /** 先頭のUTF-8 BOMを除去 */
    private static function strip_bom( $s ) {
        if ( substr( $s, 0, 3 ) === "\xEF\xBB\xBF" ) {
            return substr( $s, 3 );
        }
        return $s;
    }

    /**
     * 日付を YYYY-MM-DD に正規化。
     * 受理: YYYY-MM-DD / YYYY/M/D / YYYY/MM/DD
     * 不正な場合は null を返す。
     */
    private static function normalize_date( $raw ) {
        $raw = trim( $raw );
        if ( $raw === '' ) return null;

        $raw = str_replace( '/', '-', $raw );
        $parts = explode( '-', $raw );
        if ( count( $parts ) !== 3 ) return null;

        $y = (int) $parts[0];
        $m = (int) $parts[1];
        $d = (int) $parts[2];

        if ( $y < 1900 || $y > 2100 ) return null;
        if ( ! checkdate( $m, $d, $y ) ) return null;

        return sprintf( '%04d-%02d-%02d', $y, $m, $d );
    }

    /**
     * 入社日と付与日から勤続月数を算出（年×12＋月）。
     * 入社日が不明、または付与日が入社日より前の場合は 0。
     */
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
        return array(
            'line'   => $line_no,
            'code'   => $code,
            'reason' => $reason,
        );
    }
}
