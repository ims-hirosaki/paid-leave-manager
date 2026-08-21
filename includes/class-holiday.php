<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Holiday {

    /**
     * 内閣府CSVを取得してDBにキャッシュ
     */
    public static function fetch_and_cache() {
        $url      = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';
        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[PL_Holiday] fetch failed: ' . $response->get_error_message() );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        // Shift-JIS → UTF-8
        if ( function_exists( 'mb_convert_encoding' ) ) {
            $body = mb_convert_encoding( $body, 'UTF-8', 'Shift-JIS' );
        }

        $lines = preg_split( '/\r?\n/', trim( $body ) );
        if ( count( $lines ) < 2 ) return false;

        global $wpdb;
        $table   = $wpdb->prefix . 'paidleave_holidays';
        $fetched = current_time('mysql');
        $count   = 0;

        // ヘッダー行をスキップ
        array_shift( $lines );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;
            $parts = explode( ',', $line );
            if ( count( $parts ) < 2 ) continue;

            $date_str = trim( $parts[0] );
            $name     = trim( $parts[1] );

            // YYYY/M/D → YYYY-MM-DD
            $d = DateTime::createFromFormat( 'Y/n/j', $date_str );
            if ( ! $d ) continue;
            $date = $d->format('Y-m-d');
            $year = (int) $d->format('Y');

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (holiday_date, holiday_name, year, fetched_at)
                 VALUES (%s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE holiday_name=VALUES(holiday_name), fetched_at=VALUES(fetched_at)",
                $date, $name, $year, $fetched
            ) );
            $count++;
        }
        return $count;
    }

    /**
     * 指定年の祝日を取得（DBに無ければ自動フェッチ）
     */
    public static function get_holidays_for_year( $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_holidays';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT holiday_date, holiday_name FROM {$table} WHERE year = %d ORDER BY holiday_date ASC",
            $year
        ) );

        // DBに無ければ取得を試みる
        if ( empty( $rows ) ) {
            self::fetch_and_cache();
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT holiday_date, holiday_name FROM {$table} WHERE year = %d ORDER BY holiday_date ASC",
                $year
            ) );
        }

        $map = array();
        foreach ( $rows as $r ) {
            $map[ $r->holiday_date ] = $r->holiday_name;
        }
        return $map;
    }

    /**
     * 指定日が法定休日（曜日 or 祝日）かどうかチェック
     */
    public static function is_holiday( $date_str ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $date_str );
        if ( ! $d ) return false;

        // 法定休日曜日チェック
        $dow_setting = PL_Rules::get_setting( 'legal_holiday_dow', '[0]' );
        $legal_dows  = json_decode( $dow_setting, true ) ?: array(0);
        $dow         = (int) $d->format('w'); // 0=日〜6=土
        if ( in_array( $dow, $legal_dows, true ) ) return true;

        // 祝日チェック
        $use_national = PL_Rules::get_setting( 'use_national_holidays', '1' );
        if ( $use_national === '1' ) {
            $year     = (int) $d->format('Y');
            $holidays = self::get_holidays_for_year( $year );
            if ( isset( $holidays[ $date_str ] ) ) return true;
        }

        return false;
    }

    // =====================================================
    //  AJAX
    // =====================================================

    public static function ajax_fetch() {
        check_ajax_referer( 'pl_rules_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_custom_plugin_settings' ) ) wp_die(-1);

        $count = self::fetch_and_cache();
        if ( $count !== false ) {
            wp_send_json_success( array( 'message' => "祝日データを {$count} 件取得・更新しました" ) );
        } else {
            wp_send_json_error( array( 'message' => '祝日データの取得に失敗しました' ) );
        }
    }
}
