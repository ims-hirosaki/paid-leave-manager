<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Rules {

    // =====================================================
    //  設定の読み書き
    // =====================================================

    public static function get_settings() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM {$wpdb->prefix}paidleave_settings"
        );
        $settings = array();
        foreach ( $rows as $r ) {
            $settings[ $r->setting_key ] = $r->setting_value;
        }
        return $settings;
    }

    public static function get_setting( $key, $default = '' ) {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}paidleave_settings WHERE setting_key = %s",
            $key
        ) );
        return $val !== null ? $val : $default;
    }

    public static function update_setting( $key, $value ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}paidleave_settings (setting_key, setting_value)
             VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            $key, $value
        ) );
    }

    // =====================================================
    //  付与日数ルールの読み書き
    // =====================================================

    /**
     * 全ルールを取得（最新の適用日のみ）
     */
    public static function get_rules() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}paidleave_rules
             ORDER BY tenure_months ASC, weekly_days ASC, effective_date DESC"
        );
    }

    /**
     * 特定の勤続月数・週勤務日数・適用日に対応するルールを取得
     */
    public static function get_rule( $tenure_months, $weekly_days, $as_of_date = null ) {
        global $wpdb;
        if ( ! $as_of_date ) $as_of_date = date('Y-m-d');
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_rules
             WHERE tenure_months = %d AND weekly_days = %d AND effective_date <= %s
             ORDER BY effective_date DESC LIMIT 1",
            $tenure_months, $weekly_days, $as_of_date
        ) );
    }

    /**
     * 勤続月数・週勤務日数に対応する付与日数を返す
     *
     * 実際の勤続月数から「到達済みの最大マイルストーン」を特定し、
     * そのマイルストーンに対応する付与日数を返す。
     *
     * マイルストーン: 6 / 18 / 30 / 42 / 54 / 66 / 78 ヶ月
     *
     * @param int    $tenure_months  実際の勤続月数（例: 20）
     * @param int    $weekly_days    週勤務日数（1〜6）
     * @param string $as_of_date     適用日（省略時は今日）
     * @return float|null            付与日数。未到達またはルール未設定の場合 null
     */
    public static function get_granted_days( $tenure_months, $weekly_days, $as_of_date = null ) {
        // 到達済みの最大マイルストーンを降順に検索
        $milestones = array( 78, 66, 54, 42, 30, 18, 6 );
        $target     = null;
        foreach ( $milestones as $m ) {
            if ( $tenure_months >= $m ) {
                $target = $m;
                break;
            }
        }

        // 6ヶ月未満（入社半年未満）はまだ付与対象外
        if ( $target === null ) {
            return null;
        }

        $rule = self::get_rule( $target, $weekly_days, $as_of_date );
        return $rule ? (float) $rule->granted_days : null;
    }

    /**
     * ルールを一括保存（画面から42件分）
     */
    public static function save_rules( $rules_data, $effective_date ) {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_rules';
        $saved = 0;

        foreach ( $rules_data as $tenure => $days_by_week ) {
            foreach ( $days_by_week as $weekly => $days ) {
                $days = (float) $days;
                if ( $days < 0 ) continue;

                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE tenure_months=%d AND weekly_days=%d AND effective_date=%s",
                    (int)$tenure, (int)$weekly, $effective_date
                ) );

                if ( $exists ) {
                    $wpdb->update( $table,
                        array( 'granted_days' => $days ),
                        array( 'id' => $exists )
                    );
                } else {
                    $wpdb->insert( $table, array(
                        'tenure_months'  => (int) $tenure,
                        'weekly_days'    => (int) $weekly,
                        'granted_days'   => $days,
                        'effective_date' => $effective_date,
                    ) );
                }
                $saved++;
            }
        }
        return $saved;
    }

    /**
     * ルールをマトリクス形式で取得（画面表示用）
     * 返値: [ tenure_months => [ weekly_days => granted_days ] ]
     */
    public static function get_rules_matrix( $effective_date = null ) {
        global $wpdb;
        if ( ! $effective_date ) $effective_date = date('Y-m-d');

        $tenures = array( 6, 18, 30, 42, 54, 66, 78 );
        $weeks   = array( 1, 2, 3, 4, 5, 6 );
        $matrix  = array();

        foreach ( $tenures as $t ) {
            $matrix[ $t ] = array();
            foreach ( $weeks as $w ) {
                $rule = self::get_rule( $t, $w, $effective_date );
                $matrix[ $t ][ $w ] = $rule ? (float) $rule->granted_days : 0.0;
            }
        }
        return $matrix;
    }

    // =====================================================
    //  AJAX
    // =====================================================

    public static function ajax_get() {
        check_ajax_referer( 'pl_rules_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die(-1);

        wp_send_json_success( array(
            'matrix'   => self::get_rules_matrix(),
            'settings' => self::get_settings(),
        ) );
    }

    public static function ajax_save() {
        check_ajax_referer( 'pl_rules_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die(-1);

        $effective_date = sanitize_text_field( $_POST['effective_date'] ?? date('Y-m-d') );
        $rules_data     = $_POST['rules'] ?? array();

        // 設定を保存
        $settings_map = array(
            'carryover_years', 'expiration_years', 'min_annual_days',
            'legal_holiday_dow', 'use_national_holidays',
            'consumption_units', 'default_consumption_unit',
        );
        foreach ( $settings_map as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $val = is_array( $_POST[ $key ] )
                    ? json_encode( array_map( 'intval', $_POST[ $key ] ) )
                    : sanitize_text_field( $_POST[ $key ] );
                self::update_setting( $key, $val );
            }
        }

        $saved = self::save_rules( $rules_data, $effective_date );
        wp_send_json_success( array( 'message' => 'ルールを保存しました（' . $saved . '件）' ) );
    }
}
