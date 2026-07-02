<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Summary {

    public static function get_list( $date_from, $date_to, $mode = 'grant', $args = array() ) {
        global $wpdb;

        $employees = PL_Employee_Bridge::get_active_employees( $args );
        if ( empty( $employees ) ) return array();

        $result = array();
        foreach ( $employees as $emp ) {
            $code = $emp->employee_code;

            if ( $mode === 'grant' ) {
                $grants = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}paidleave_grants
                     WHERE employee_code = %s AND grant_date BETWEEN %s AND %s
                     ORDER BY grant_date ASC",
                    $code, $date_from, $date_to
                ) );
            } else {
                $grants = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DISTINCT g.* FROM {$wpdb->prefix}paidleave_grants g
                     INNER JOIN {$wpdb->prefix}paidleave_consumptions c ON g.id = c.grant_id
                     WHERE g.employee_code = %s AND c.consumed_date BETWEEN %s AND %s",
                    $code, $date_from, $date_to
                ) );
            }

            $consumed_in_period = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(consumed_days),0)
                 FROM {$wpdb->prefix}paidleave_consumptions
                 WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s",
                $code, $date_from, $date_to
            ) );

            // ★ 今年の消化（暦年 1/1〜12/31）— 従業員一覧の「今年の消化」列専用。
            //    一覧は date_from/date_to に 2000-01-01〜2099-12-31 を渡すため、
            //    $consumed_in_period（＝全期間累計）とは別に当年で集計する。
            $year_start         = date('Y') . '-01-01';
            $year_end           = date('Y') . '-12-31';
            $consumed_this_year = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(consumed_days),0)
                 FROM {$wpdb->prefix}paidleave_consumptions
                 WHERE employee_code = %s AND consumed_date BETWEEN %s AND %s",
                $code, $year_start, $year_end
            ) );

            $total_granted   = array_sum( array_column( (array) $grants, 'granted_days' ) );
            $total_remaining = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(remaining_days),0)
                 FROM {$wpdb->prefix}paidleave_grants
                 WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= %s",
                $code, date('Y-m-d')
            ) );

            $rate = $total_granted > 0 ?
                round( $consumed_in_period / $total_granted * 100, 1 ) : 0;

            $first_grant = $wpdb->get_var( $wpdb->prepare(
                "SELECT MIN(grant_date) FROM {$wpdb->prefix}paidleave_grants WHERE employee_code = %s",
                $code
            ) );

            // 失効予告：3ヶ月以内に失効する残日数
            $warn_date           = date( 'Y-m-d', strtotime( '+3 months' ) );
            $expiry_warning_days = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(remaining_days),0)
                 FROM {$wpdb->prefix}paidleave_grants
                 WHERE employee_code = %s
                   AND is_expired = 0
                   AND remaining_days > 0
                   AND expiry_date BETWEEN %s AND %s",
                $code, date('Y-m-d'), $warn_date
            ) );

            // ★ 社員ごとの pending 申請件数
            $pending_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_requests
                 WHERE employee_code = %s AND status = 'pending'",
                $code
            ) );

            $result[] = array(
                'employee_code'       => $code,
                'name'                => $emp->name,
                'hire_date'           => $emp->hire_date,
                'employment_type'     => $emp->employment_type  ?? '',
                'weekly_work_days'    => $emp->weekly_work_days ?? '',
                'first_grant_date'    => $first_grant,
                'total_granted'       => $total_granted,
                'consumed'            => $consumed_in_period,
                'consumed_this_year'  => $consumed_this_year,
                'remaining'           => $total_remaining,
                'rate'                => $rate,
                'expiry_warning_days' => $expiry_warning_days,
                'pending_count'       => $pending_count, // ★ 追加
            );
        }
        return $result;
    }

    // =====================================================
    //  AJAX
    // =====================================================

    public static function ajax_get() {
        check_ajax_referer( 'pl_summary_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die(-1);

        $date_from      = sanitize_text_field( $_POST['date_from'] ?? date('Y-01-01') );
        $date_to        = sanitize_text_field( $_POST['date_to']   ?? date('Y-12-31') );
        $mode           = sanitize_text_field( $_POST['mode']      ?? 'grant' );
        $affiliation_id = sanitize_text_field( $_POST['affiliation_id'] ?? '' );
        $department_id  = sanitize_text_field( $_POST['department_id']  ?? '' );

        $args = array();
        if ( $affiliation_id !== '' ) $args['affiliation_id'] = (int) $affiliation_id;
        if ( $department_id  !== '' ) $args['department_id']  = (int) $department_id;

        $data = self::get_list( $date_from, $date_to, $mode, $args );
        wp_send_json_success( $data );
    }
}
