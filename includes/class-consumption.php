<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Consumption {

    /**
     * 消化可能かチェック
     */
    public static function check_consumable( $employee_code, $consume_date, $consume_days ) {
        if ( PL_Holiday::is_holiday( $consume_date ) ) {
            return array( 'ok' => false, 'message' => '法定休日（または祝日）には有給休暇を取得できません' );
        }

        // 同じ日に既に消化登録がないか確認
        global $wpdb;
        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(consumed_days), 0)
             FROM {$wpdb->prefix}paidleave_consumptions
             WHERE employee_code = %s AND consumed_date = %s",
            $employee_code, $consume_date
        ) );
        if ( (float) $already > 0 ) {
            return array( 'ok' => false, 'message' => $consume_date . ' は既に ' . (float)$already . ' 日の消化が登録されています' );
        }

        $units = json_decode( PL_Rules::get_setting( 'consumption_units', '["1.0"]' ), true );
        if ( ! in_array( (float) $consume_days, array_map('floatval', $units), true ) ) {
            $units_str = implode( '・', $units ) . ' 日';
            return array( 'ok' => false, 'message' => "消化単位は {$units_str} のみ有効です" );
        }

        $available = self::get_available_days( $employee_code );
        if ( $available < $consume_days ) {
            return array( 'ok' => false, 'message' => "残日数（{$available}日）が不足しています" );
        }

        return array( 'ok' => true, 'available' => $available );
    }

    /**
     * 有効残日数を取得
     */
    public static function get_available_days( $employee_code ) {
        global $wpdb;
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(remaining_days),0)
             FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0 AND expiry_date >= %s",
            $employee_code, date('Y-m-d')
        ) );
        return (float) $total;
    }

    /**
     * 消化を実行（先入れ先出し）
     */
    public static function execute( $employee_code, $consume_date, $consume_days, $unit_type = 'day', $note = '' ) {
        global $wpdb;

        $grants = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_grants
             WHERE employee_code = %s AND is_expired = 0
               AND expiry_date >= %s AND remaining_days > 0
             ORDER BY expiry_date ASC",
            $employee_code, date('Y-m-d')
        ) );

        $remaining_to_consume = (float) $consume_days;
        $logs = array();

        foreach ( $grants as $grant ) {
            if ( $remaining_to_consume <= 0 ) break;

            $from_this = min( (float) $grant->remaining_days, $remaining_to_consume );

            $wpdb->insert(
                $wpdb->prefix . 'paidleave_consumptions',
                array(
                    'grant_id'      => $grant->id,
                    'employee_code' => $employee_code,
                    'consumed_date' => $consume_date,
                    'consumed_days' => $from_this,
                    'unit_type'     => $unit_type,
                    'note'          => sanitize_text_field( $note ),
                    'created_at'    => current_time('mysql'),
                    'updated_at'    => current_time('mysql'),
                )
            );
            $logs[] = $wpdb->insert_id;

            $new_remaining = (float) $grant->remaining_days - $from_this;
            $wpdb->update(
                $wpdb->prefix . 'paidleave_grants',
                array( 'remaining_days' => $new_remaining, 'updated_at' => current_time('mysql') ),
                array( 'id' => $grant->id )
            );

            $remaining_to_consume -= $from_this;
        }

        if ( $remaining_to_consume > 0 ) {
            return new WP_Error( 'insufficient', '残日数が不足しています' );
        }
        return $logs;
    }

    /**
     * 期間一括消化
     * 開始日〜終了日の間で法定休日を除いた平日に1日ずつ消化を登録する
     * @return array { registered: int, skipped: int, skipped_dates: array, errors: array }
     */
    public static function execute_range( $employee_code, $date_from, $date_to, $unit_type = 'day', $note = '' ) {
        $from = new DateTime( $date_from );
        $to   = new DateTime( $date_to );
        $to->modify( '+1 day' ); // DatePeriodの終端は含まれないため+1日

        $interval = new DateInterval('P1D');
        $period   = new DatePeriod( $from, $interval, $to );

        $registered    = 0;
        $skipped       = 0;
        $skipped_dates = array();
        $errors        = array();

        foreach ( $period as $day ) {
            $date_str = $day->format('Y-m-d');

            // 法定休日・祝日はスキップ
            if ( PL_Holiday::is_holiday( $date_str ) ) {
                $skipped++;
                $skipped_dates[] = $date_str;
                continue;
            }

            // 残日数チェック
            $available = self::get_available_days( $employee_code );
            if ( $available < 1.0 ) {
                $errors[] = $date_str . ' 以降：残日数が不足しています（残 ' . $available . ' 日）';
                break;
            }

            $result = self::execute( $employee_code, $date_str, 1.0, $unit_type, $note );
            if ( is_wp_error( $result ) ) {
                $errors[] = $date_str . '：' . $result->get_error_message();
            } else {
                $registered++;
            }
        }

        return compact( 'registered', 'skipped', 'skipped_dates', 'errors' );
    }

    /**
     * 消化ログ一覧取得
     */
    public static function get_logs( $employee_code ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, g.grant_date, g.expiry_date
             FROM {$wpdb->prefix}paidleave_consumptions c
             JOIN {$wpdb->prefix}paidleave_grants g ON c.grant_id = g.id
             WHERE c.employee_code = %s
             ORDER BY c.consumed_date DESC, c.id DESC",
            $employee_code
        ) );
    }

    // =====================================================
    //  AJAX
    // =====================================================

    public static function ajax_check() {
        check_ajax_referer( 'pl_grant_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die(-1);

        $code  = sanitize_text_field( $_POST['employee_code']  ?? '' );
        $date  = sanitize_text_field( $_POST['consume_date']   ?? date('Y-m-d') );
        $days  = (float) ( $_POST['consume_days'] ?? 1.0 );

        $result = self::check_consumable( $code, $date, $days );
        if ( $result['ok'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public static function ajax_execute() {
        check_ajax_referer( 'pl_grant_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die(-1);

        $code      = sanitize_text_field( $_POST['employee_code'] ?? '' );
        $mode      = sanitize_text_field( $_POST['mode']          ?? 'single' );
        $unit_type = sanitize_text_field( $_POST['unit_type']     ?? 'day' );
        $note      = sanitize_text_field( $_POST['note']          ?? '' );

        if ( $mode === 'range' ) {
            // 期間一括登録
            $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
            $date_to   = sanitize_text_field( $_POST['date_to']   ?? '' );

            if ( ! $date_from || ! $date_to ) {
                wp_send_json_error( array( 'message' => '開始日と終了日を入力してください' ) );
            }
            if ( $date_from > $date_to ) {
                wp_send_json_error( array( 'message' => '開始日は終了日より前にしてください' ) );
            }

            $result = self::execute_range( $code, $date_from, $date_to, $unit_type, $note );

            $msg = $result['registered'] . '日間を消化登録しました';
            if ( $result['skipped'] > 0 ) {
                $msg .= '（法定休日・祝日 ' . $result['skipped'] . '日 はスキップ）';
            }
            if ( ! empty( $result['errors'] ) ) {
                wp_send_json_error( array(
                    'message'    => $msg,
                    'errors'     => $result['errors'],
                    'registered' => $result['registered'],
                ) );
            }
            wp_send_json_success( array( 'message' => $msg, 'registered' => $result['registered'] ) );

        } else {
            // 単日登録
            $date = sanitize_text_field( $_POST['consume_date'] ?? date('Y-m-d') );
            $days = (float) ( $_POST['consume_days'] ?? 1.0 );

            $check = self::check_consumable( $code, $date, $days );
            if ( ! $check['ok'] ) {
                wp_send_json_error( array( 'message' => $check['message'] ) );
            }

            $result = self::execute( $code, $date, $days, $unit_type, $note );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            wp_send_json_success( array( 'message' => $days . '日を消化しました' ) );
        }
    }
}
