<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * テストデータ削除クラス
 *
 * 付与ログ（paidleave_grants）・消化ログ（paidleave_consumptions）・
 * 申請ログ（paidleave_requests）を社員別または全件で物理削除する。
 *
 * ルール・設定・祝日データは削除しない。
 */
class PL_Data_Reset {

    // =====================================================
    //  件数取得（削除前の確認用）
    // =====================================================

    /**
     * 指定社員のデータ件数を返す
     */
    public static function get_counts_by_employee( $employee_code ) {
        global $wpdb;

        $grants = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_grants WHERE employee_code = %s",
            $employee_code
        ) );

        $consumptions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_consumptions WHERE employee_code = %s",
            $employee_code
        ) );

        // paidleave_requests テーブルは任意（存在しない環境もある）
        $requests = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}paidleave_requests'" ) ) {
            $requests = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_requests WHERE employee_code = %s",
                $employee_code
            ) );
        }

        return compact( 'grants', 'consumptions', 'requests' );
    }

    /**
     * 全社員のデータ件数を返す
     */
    public static function get_counts_all() {
        global $wpdb;

        $grants       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_grants" );
        $consumptions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_consumptions" );
        $requests     = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}paidleave_requests'" ) ) {
            $requests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_requests" );
        }

        return compact( 'grants', 'consumptions', 'requests' );
    }

    // =====================================================
    //  削除処理
    // =====================================================

    /**
     * 指定社員の付与・消化・申請データを全削除
     */
    public static function delete_by_employee( $employee_code ) {
        global $wpdb;

        // 消化ログは付与ログより先に削除（外部キー制約がある環境への配慮）
        $deleted_consumptions = (int) $wpdb->delete(
            $wpdb->prefix . 'paidleave_consumptions',
            array( 'employee_code' => $employee_code ),
            array( '%s' )
        );

        $deleted_grants = (int) $wpdb->delete(
            $wpdb->prefix . 'paidleave_grants',
            array( 'employee_code' => $employee_code ),
            array( '%s' )
        );

        $deleted_requests = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}paidleave_requests'" ) ) {
            $deleted_requests = (int) $wpdb->delete(
                $wpdb->prefix . 'paidleave_requests',
                array( 'employee_code' => $employee_code ),
                array( '%s' )
            );
        }

        return compact( 'deleted_grants', 'deleted_consumptions', 'deleted_requests' );
    }

    /**
     * 全社員の付与・消化・申請データを一括削除（TRUNCATE）
     */
    public static function delete_all() {
        global $wpdb;

        $before = self::get_counts_all();

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}paidleave_consumptions" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}paidleave_grants" );

        $deleted_requests = 0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}paidleave_requests'" ) ) {
            $deleted_requests = $before['requests'];
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}paidleave_requests" );
        }

        return array(
            'deleted_grants'       => $before['grants'],
            'deleted_consumptions' => $before['consumptions'],
            'deleted_requests'     => $deleted_requests,
        );
    }

    // =====================================================
    //  AJAX ハンドラー
    // =====================================================

    /**
     * action: pl_reset_get_counts
     * 削除前の確認件数を返す
     */
    public static function ajax_get_counts() {
        check_ajax_referer( 'pl_reset_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $target = sanitize_text_field( $_POST['target'] ?? 'employee' );

        if ( $target === 'all' ) {
            $counts = self::get_counts_all();
            wp_send_json_success( array(
                'target' => 'all',
                'counts' => $counts,
                'total'  => array_sum( $counts ),
            ) );
        } else {
            $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
            if ( ! $code ) {
                wp_send_json_error( array( 'message' => '社員コードを入力してください' ) );
            }

            $emp = PL_Employee_Bridge::get_by_code( $code );
            if ( ! $emp ) {
                wp_send_json_error( array( 'message' => '社員が見つかりません（コード: ' . esc_html( $code ) . '）' ) );
            }

            $counts = self::get_counts_by_employee( $code );
            wp_send_json_success( array(
                'target'   => 'employee',
                'employee' => array(
                    'employee_code' => $emp->employee_code,
                    'name'          => $emp->name,
                ),
                'counts' => $counts,
                'total'  => array_sum( $counts ),
            ) );
        }
    }

    /**
     * action: pl_reset_execute
     * 削除を実行する
     */
    public static function ajax_execute() {
        check_ajax_referer( 'pl_reset_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $target = sanitize_text_field( $_POST['target'] ?? 'employee' );

        if ( $target === 'all' ) {
            $result = self::delete_all();
            $total  = array_sum( $result );
            wp_send_json_success( array(
                'message' => '全社員のデータを削除しました（合計 ' . $total . ' 件）',
                'detail'  => array(
                    '付与ログ' => $result['deleted_grants'] . ' 件',
                    '消化ログ' => $result['deleted_consumptions'] . ' 件',
                    '申請ログ' => $result['deleted_requests'] . ' 件',
                ),
            ) );
        } else {
            $code = sanitize_text_field( $_POST['employee_code'] ?? '' );
            if ( ! $code ) {
                wp_send_json_error( array( 'message' => '社員コードが指定されていません' ) );
            }

            $emp = PL_Employee_Bridge::get_by_code( $code );
            if ( ! $emp ) {
                wp_send_json_error( array( 'message' => '社員が見つかりません' ) );
            }

            $result = self::delete_by_employee( $code );
            $total  = array_sum( $result );
            wp_send_json_success( array(
                'message' => $emp->name . '（' . esc_html( $emp->employee_code ) . '）のデータを削除しました（合計 ' . $total . ' 件）',
                'detail'  => array(
                    '付与ログ' => $result['deleted_grants'] . ' 件',
                    '消化ログ' => $result['deleted_consumptions'] . ' 件',
                    '申請ログ' => $result['deleted_requests'] . ' 件',
                ),
            ) );
        }
    }
}
