<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Grant {

    /**
     * 「今年」の付与起算サイクルを返す（全ページ共通の定義）
     *   基準日 = grant_date <= 今日 の最新 grant_date
     *   窓     = [基準日, 基準日 + 1年 − 1日]
     * @return array|null array('start'=>'Y-m-d','end'=>'Y-m-d')／付与が無ければ null
     */
    public static function get_current_cycle( $employee_code ) {
        global $wpdb;
        $start = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(grant_date)
             FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND grant_date <= %s",
            $employee_code, date('Y-m-d')
        ) );
        if ( ! $start ) return null;
        return array(
            'start' => $start,
            'end'   => date('Y-m-d', strtotime( $start . ' +1 year -1 day' )),
        );
    }

    // =====================================================
    //  サマリー取得（個人管理ページ用）
    // =====================================================

    public static function get_summary( $employee_code ) {
        global $wpdb;
        $today = date('Y-m-d');

        $grants = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s
             ORDER BY grant_date DESC",
            $employee_code
        ) );

        // ===== 全体（後方互換用） =====
        $total_granted = array_sum( array_column( (array) $grants, 'granted_days' ) );

        $total_remaining = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(remaining_days),0)
             FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= %s",
            $employee_code, $today
        ) );

        // 実消化（累計）は消化テーブルの実績から算出（失効分を含めない）
        $total_consumed = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(consumed_days),0)
             FROM {$wpdb->prefix}paidleave_consumptions
             WHERE employee_code = %s",
            $employee_code
        ) );

        // 失効日数 ＝ 付与合計 − 実消化 − 有効残（マイナスは0に丸め）※後方互換用に保持
        $total_expired = $total_granted - $total_consumed - $total_remaining;
        if ( $total_expired < 0 ) $total_expired = 0.0;

        $rate = $total_granted > 0
            ? round( $total_consumed / $total_granted * 100, 1 ) : 0;

        // =====================================================
        //  ① 現在有効期間内のサマリ
        //     対象グラント = is_expired = 0 AND expiry_date >= 今日
        // =====================================================
        $valid_granted = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(granted_days),0)
             FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= %s",
            $employee_code, $today
        ) );

        // 有効グラントに紐づく消化の実績合計（remaining_days ではなく消化テーブルを正とする）
        $valid_consumed = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(c.consumed_days),0)
             FROM {$wpdb->prefix}paidleave_consumptions c
             INNER JOIN {$wpdb->prefix}paidleave_grants g ON c.grant_id = g.id
             WHERE c.employee_code = %s AND g.is_expired = 0 AND g.expiry_date >= %s",
            $employee_code, $today
        ) );

        $valid_rate = $valid_granted > 0
            ? round( $valid_consumed / $valid_granted * 100, 1 ) : 0;

        // =====================================================
        //  ② 今年のサマリ（付与起算）
        //     基準日 = grant_date <= 今日 の最新 grant_date
        //     窓     = [基準日, 基準日 + 1年 − 1日]（例: 2026-05-08 → 2027-05-07）
        //     サイクル定義は get_current_cycle() に一元化
        // =====================================================
        // ★ ① の対象期間 = 現在有効な付与が張る期間（最古の付与日 〜 最新の有効期限）
        
        $valid_period = $wpdb->get_row( $wpdb->prepare(
            "SELECT MIN(grant_date) AS start, MAX(expiry_date) AS end
             FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= %s",
            $employee_code, $today
        ) );
        $valid_start = $valid_period ? $valid_period->start : null;
        $valid_end   = $valid_period ? $valid_period->end   : null;

        $cycle       = self::get_current_cycle( $employee_code );
        $cycle_start = $cycle ? $cycle['start'] : null;
        $cycle_end   = $cycle ? $cycle['end']   : null;

        $year_granted  = 0.0;
        $year_consumed = 0.0;
        $year_rate     = 0;
        $year_alert    = false;

        if ( $cycle_start ) {
            $year_granted = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(granted_days),0)
                 FROM {$wpdb->prefix}paidleave_grants
                 WHERE employee_code = %s AND grant_date BETWEEN %s AND %s",
                $employee_code, $cycle_start, $cycle_end
            ) );

            $year_consumed = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(consumed_days),0)
                 FROM {$wpdb->prefix}paidleave_consumptions
                 WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s",
                $employee_code, $cycle_start, $cycle_end
            ) );

            $year_rate = $year_granted > 0
                ? round( $year_consumed / $year_granted * 100, 1 ) : 0;

            // アラート条件: 付与 >= 10日 かつ 消化 < 5日 かつ 窓の残り3か月未満
            //              （＝今日 >= 基準日 + 9か月 かつ 今日 <= 窓の終端）
            $alert_threshold = date('Y-m-d', strtotime( $cycle_start . ' +9 months' ));
            if ( $year_granted >= 10
                 && $year_consumed < 5
                 && $today >= $alert_threshold
                 && $today <= $cycle_end ) {
                $year_alert = true;
            }
        }

        // 「今年の消化」も付与起算に統一（全ページ共通）。暦年クエリは廃止。
        $consumed_this_year = $year_consumed;

        // 失効予告（従来どおり）
        $warn_date = date('Y-m-d', strtotime('+3 months'));
        $expiring  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0
               AND expiry_date BETWEEN %s AND %s
             ORDER BY expiry_date ASC",
            $employee_code, $today, $warn_date
        ) );

        return array(
            'grants'             => $grants,
            // 後方互換キー
            'total_granted'      => $total_granted,
            'total_remaining'    => $total_remaining,
            'total_consumed'     => $total_consumed,
            'total_expired'      => $total_expired,
            'consumed_this_year' => $consumed_this_year,
            'consumption_rate'   => $rate,
            'expiring_soon'      => $expiring,
            // ① 現在有効期間内
            'valid_granted'      => $valid_granted,
            'valid_consumed'     => $valid_consumed,
            'valid_remaining'    => $total_remaining,
            'valid_rate'         => $valid_rate,
            'valid_start'        => $valid_start,   // ★ 追加
            'valid_end'          => $valid_end, 
            // ② 今年（付与起算）
            'cycle_start'        => $cycle_start,
            'cycle_end'          => $cycle_end,
            'year_granted'       => $year_granted,
            'year_consumed'      => $year_consumed,
            'year_rate'          => $year_rate,
            'year_alert'         => $year_alert,
        );
    }

    /**
     * 全付与ログを取得（個人管理ページ用）
     */
    public static function get_all_grants( $employee_code ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s
             ORDER BY grant_date DESC",
            $employee_code
        ) );
    }

    /**
     * 過去3件の付与ログを取得
     */
    public static function get_recent_grants( $employee_code, $limit = 3 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s
             ORDER BY grant_date DESC LIMIT %d",
            $employee_code, $limit
        ) );
    }

    // =====================================================
    //  失効処理（定期実行想定）
    // =====================================================

    public static function expire_old_grants() {
        global $wpdb;
        $today = date('Y-m-d');
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}paidleave_grants
             SET is_expired = 1, remaining_days = 0, updated_at = %s
             WHERE expiry_date < %s AND is_expired = 0 AND remaining_days > 0",
            current_time('mysql'), $today
        ) );
    }

    // =====================================================
    //  AJAX
    // =====================================================

    public static function ajax_check() {
        check_ajax_referer( 'pl_grant_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_die(-1);

        $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
        if ( ! $code ) wp_send_json_error( array( 'message' => '社員コードが必要です' ) );

        $emp = PL_Employee_Bridge::get_by_code( $code );
        if ( ! $emp ) wp_send_json_error( array( 'message' => '社員が見つかりません' ) );

        $check   = self::check_grantable( $emp );
        $summary = self::get_summary( $code );

        wp_send_json_success( array(
            'employee' => $emp,
            'check'    => $check,
            'summary'  => $summary,
        ) );
    }

    public static function ajax_execute() {
        check_ajax_referer( 'pl_grant_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_die(-1);

        $code           = sanitize_text_field( $_POST['employee_code'] ?? '' );
        $tenure_months  = (int) ( $_POST['tenure_months'] ?? 0 );
        $grant_date     = sanitize_text_field( $_POST['grant_date'] ?? date('Y-m-d') );
        $granted_days   = (float) ( $_POST['granted_days'] ?? 0 );
        $weekly_days    = (int) ( $_POST['weekly_days'] ?? 5 );

        if ( ! $code || $granted_days <= 0 ) {
            wp_send_json_error( array( 'message' => '必須パラメータが不足しています' ) );
        }

        $result = self::execute_grant( $code, $tenure_months, $grant_date, $granted_days, $weekly_days );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $summary = self::get_summary( $code );
        wp_send_json_success( array(
            'message' => $granted_days . '日を付与しました',
            'summary' => $summary,
        ) );
    }

    // ★ 個人管理ページ用サマリー再取得 AJAX
    public static function ajax_get_summary_for_employee() {
        check_ajax_referer( 'pl_grant_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_send_json_error( '権限がありません' );

        $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
        if ( ! $code ) wp_send_json_error( array( 'message' => '社員コードが必要です' ) );

        $summary = self::get_summary( $code );

        wp_send_json_success( array(
            // ① 現在有効期間内
            'valid_granted'   => (float) $summary['valid_granted'],
            'valid_consumed'  => (float) $summary['valid_consumed'],
            'valid_remaining' => (float) $summary['valid_remaining'],
            'valid_rate'      => (float) $summary['valid_rate'],
            // ② 今年（付与起算）
            'year_granted'    => (float) $summary['year_granted'],
            'year_consumed'   => (float) $summary['year_consumed'],
            'year_rate'       => (float) $summary['year_rate'],
            'year_alert'      => (bool)  $summary['year_alert'],
            'cycle_start'     => $summary['cycle_start'],
            'cycle_end'       => $summary['cycle_end'],
            // 後方互換
            'total_remaining'    => (float) $summary['total_remaining'],
            'total_consumed'     => (float) $summary['total_consumed'],
            'total_expired'      => (float) $summary['total_expired'],
            'consumed_this_year' => (float) $summary['consumed_this_year'],
            'consumption_rate'   => (float) $summary['consumption_rate'],
        ) );
    }

    // =====================================================
    //  内部ロジック
    // =====================================================

    public static function check_grantable( $emp ) {
        if ( ! $emp->hire_date ) {
            return array( 'grantable' => false, 'message' => '入社日が登録されていません' );
        }

        $hire_dt  = new DateTime( $emp->hire_date );
        $today_dt = new DateTime( date('Y-m-d') );
        $diff     = $hire_dt->diff( $today_dt );
        $tenure_months = $diff->y * 12 + $diff->m;

        $weekly_days = (int) ( $emp->weekly_work_days ?? 5 );
        $granted_days = PL_Rules::get_granted_days( $tenure_months, $weekly_days );

        if ( $granted_days === null ) {
            return array(
                'grantable'      => false,
                'tenure_months'  => $tenure_months,
                'weekly_days'    => $weekly_days,
                'message'        => '勤続 ' . $tenure_months . ' ヶ月・週 ' . $weekly_days . ' 日勤務に対応するルールが見つかりません',
            );
        }

        return array(
            'grantable'      => true,
            'tenure_months'  => $tenure_months,
            'weekly_days'    => $weekly_days,
            'granted_days'   => $granted_days,
            'message'        => '勤続 ' . $tenure_months . ' ヶ月・週 ' . $weekly_days . ' 日勤務 → ' . $granted_days . ' 日付与',
        );
    }

    public static function execute_grant( $employee_code, $tenure_months, $grant_date, $granted_days, $weekly_days = 5 ) {
        global $wpdb;

        $settings        = PL_Rules::get_settings();
        $expiration_years = (int) ( $settings['expiration_years'] ?? 2 );
        $expiry_date     = date( 'Y-m-d', strtotime( $grant_date . ' +' . $expiration_years . ' years -1 day' ) );

        $result = $wpdb->insert(
            $wpdb->prefix . 'paidleave_grants',
            array(
                'employee_code'            => $employee_code,
                'tenure_months'            => $tenure_months,
                'grant_date'               => $grant_date,
                'granted_days'             => $granted_days,
                'remaining_days'           => $granted_days,
                'expiry_date'              => $expiry_date,
                'is_expired'               => 0,
                'weekly_work_days_at_grant'=> $weekly_days,
                'created_at'               => current_time('mysql'),
                'updated_at'               => current_time('mysql'),
            ),
            array( '%s','%d','%s','%f','%f','%s','%d','%d','%s','%s' )
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'DB登録エラー: ' . $wpdb->last_error );
        }

        return $wpdb->insert_id;
    }
}
