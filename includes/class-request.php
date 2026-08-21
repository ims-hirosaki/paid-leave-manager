<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Request {

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    /**
     * 申請を登録する（重複チェックあり）
     */
    public static function create( $employee_code_or_data, $paid_leave_date = '', $note = '' ) {
        global $wpdb;

        if ( is_array( $employee_code_or_data ) ) {
            $data = $employee_code_or_data;
            $code = sanitize_text_field( $data['employee_code'] ?? '' );
            $date = sanitize_text_field( $data['request_date']  ?? $data['paid_leave_date'] ?? '' );
            $note = sanitize_textarea_field( $data['note'] ?? '' );
        } else {
            $code = sanitize_text_field( $employee_code_or_data );
            $date = sanitize_text_field( $paid_leave_date );
            $note = sanitize_textarea_field( $note );
            $data = array();
        }

        if ( ! $code || ! $date ) {
            return new WP_Error( 'invalid_params', '社員コードまたは申請日が不正です。' );
        }

        if ( class_exists( 'PL_Holiday' ) && PL_Holiday::is_holiday( $date ) ) {
            return new WP_Error( 'holiday', '祝日・法定休日には申請できません。' );
        }

        $existing = self::get_by_employee_date( $code, $date );
        if ( $existing ) {
            if ( $existing->status === self::STATUS_REJECTED ) {
                $wpdb->update(
                    $wpdb->prefix . 'paidleave_requests',
                    array(
                        'status'      => self::STATUS_PENDING,
                        'note'        => $note,
                        'admin_note'  => null,
                        'approved_by' => null,
                        'approved_at' => null,
                        'updated_at'  => current_time( 'mysql' ),
                    ),
                    array( 'id' => $existing->id ),
                    array( '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                return $existing->id;
            }
            return new WP_Error( 'duplicate', 'この日付の申請はすでに存在します（状態：' . self::get_status_label( $existing->status ) . '）。' );
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'paidleave_requests',
            array(
                'employee_code' => $code,
                'request_date'  => $date,
                'status'        => self::STATUS_PENDING,
                'note'          => $note,
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'DB登録エラー: ' . $wpdb->last_error );
        }

        return $wpdb->insert_id;
    }

    /**
     * 申請を受理する（常に消化登録も実行）
     *
     * @param int    $request_id
     * @param string $admin_note
     * @return true|WP_Error
     */
    public static function approve( $request_id, $admin_note = '' ) {
        global $wpdb;

        $req = self::get_by_id( $request_id );
        if ( ! $req ) {
            return new WP_Error( 'not_found', '申請が見つかりません。' );
        }
        if ( $req->status === self::STATUS_APPROVED ) {
            return new WP_Error( 'already_approved', 'すでに受理済みです。' );
        }

        $current_user = wp_get_current_user();

        // ステータスを受理に更新
        $wpdb->update(
            $wpdb->prefix . 'paidleave_requests',
            array(
                'status'      => self::STATUS_APPROVED,
                'admin_note'  => sanitize_textarea_field( $admin_note ),
                'approved_by' => $current_user->display_name ?: $current_user->user_login,
                'approved_at' => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $request_id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        // ★ 消化登録を実行（引数を個別で渡す形式に修正）
        $consume_result = PL_Consumption::execute(
            $req->employee_code,                          // $employee_code
            $req->request_date,                           // $consume_date
            1.0,                                          // $consume_days
            'day',                                        // $unit_type
            '有給申請受理（申請ID: ' . $request_id . '）' // $note
        );

        if ( is_wp_error( $consume_result ) ) {
            // 受理は完了しているが消化登録に失敗した場合
            return new WP_Error(
                'consume_failed',
                '受理しましたが消化登録に失敗しました: ' . $consume_result->get_error_message()
                . '　付与・消化登録ページから手動で登録してください。',
                array( 'request_id' => $request_id )
            );
        }

        return true;
    }

    /**
     * 申請を却下する
     */
    public static function reject( $request_id, $admin_note = '' ) {
        global $wpdb;

        $req = self::get_by_id( $request_id );
        if ( ! $req ) {
            return new WP_Error( 'not_found', '申請が見つかりません。' );
        }
        if ( $req->status === self::STATUS_REJECTED ) {
            return new WP_Error( 'already_rejected', 'すでに却下済みです。' );
        }
        if ( $req->status === self::STATUS_APPROVED ) {
            return new WP_Error( 'already_approved', '受理済みの申請は却下できません。消化ログを削除してから却下してください。' );
        }

        $current_user = wp_get_current_user();
        $wpdb->update(
            $wpdb->prefix . 'paidleave_requests',
            array(
                'status'      => self::STATUS_REJECTED,
                'admin_note'  => sanitize_textarea_field( $admin_note ),
                'approved_by' => $current_user->display_name ?: $current_user->user_login,
                'approved_at' => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $request_id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return true;
    }

    /**
     * IDで1件取得
     */
    public static function get_by_id( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_requests WHERE id = %d",
            $id
        ) );
    }

    /**
     * 社員コード＋日付で1件取得
     */
    public static function get_by_employee_date( $employee_code, $request_date ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_requests
             WHERE employee_code = %s AND request_date = %s
             ORDER BY id DESC LIMIT 1",
            $employee_code, $request_date
        ) );
    }

    /**
     * 社員コードで申請一覧を取得
     */
    public static function get_by_employee( $employee_code, $status = null ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paidleave_requests WHERE employee_code = %s",
            $employee_code
        );
        if ( $status ) {
            $sql .= $wpdb->prepare( " AND status = %s", $status );
        }
        $sql .= " ORDER BY request_date DESC";
        return $wpdb->get_results( $sql );
    }

    /**
     * 全申請一覧（管理画面用）
     */
    public static function get_list( $args = array() ) {
        global $wpdb;

        $status    = sanitize_text_field( $args['status']    ?? '' );
        $date_from = sanitize_text_field( $args['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $args['date_to']   ?? '' );
        $per_page  = max( 1, (int) ( $args['per_page'] ?? 50 ) );
        $page      = max( 1, (int) ( $args['page']     ?? 1  ) );
        $offset    = ( $page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $params = array();

        if ( $status ) {
            $where[]  = "r.status = %s";
            $params[] = $status;
        }
        if ( $date_from ) {
            $where[]  = "r.request_date >= %s";
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[]  = "r.request_date <= %s";
            $params[] = $date_to;
        }

        $where_sql = implode( ' AND ', $where );
        $base_sql  = "FROM {$wpdb->prefix}paidleave_requests r
                      LEFT JOIN {$wpdb->prefix}emp_master m ON r.employee_code = m.employee_code";

        $count_sql = "SELECT COUNT(*) {$base_sql} WHERE {$where_sql}";
        $total     = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql )
        );

        $data_sql = "SELECT r.*, m.name AS employee_name
                     {$base_sql}
                     WHERE {$where_sql}
                     ORDER BY r.request_date DESC, r.id DESC
                     LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $params ) );

        return array( 'rows' => $rows ?: array(), 'total' => $total );
    }

    /**
     * 未処理（pending）の件数を取得
     */
    public static function get_pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paidleave_requests WHERE status = 'pending'"
        );
    }

    /**
     * ステータスの日本語ラベルを返す
     */
    public static function get_status_label( $status ) {
        $map = array(
            self::STATUS_PENDING  => '申請中',
            self::STATUS_APPROVED => '受理済み',
            self::STATUS_REJECTED => '却下',
        );
        return $map[ $status ] ?? $status;
    }

    // =====================================================
    //  AJAX handlers
    // =====================================================

    /**
     * AJAX: 申請一覧取得
     */
    public static function ajax_get_list() {
        check_ajax_referer( 'pl_request_nonce', 'nonce' );
        if ( ! current_user_can( 'access_custom_plugins' ) ) wp_send_json_error( '権限がありません' );

        $args   = array(
            'status'    => sanitize_text_field( $_POST['status']    ?? '' ),
            'date_from' => sanitize_text_field( $_POST['date_from'] ?? '' ),
            'date_to'   => sanitize_text_field( $_POST['date_to']   ?? '' ),
            'per_page'  => 100,
        );
        $result = self::get_list( $args );

        $rows = array();
        foreach ( $result['rows'] as $r ) {
            $rows[] = array(
                'id'            => (int) $r->id,
                'employee_code' => $r->employee_code,
                'employee_name' => $r->employee_name,
                'request_date'  => $r->request_date,
                'status'        => $r->status,
                'status_label'  => self::get_status_label( $r->status ),
                'note'          => $r->note,
                'admin_note'    => $r->admin_note,
                'approved_by'   => $r->approved_by,
                'approved_at'   => $r->approved_at,
                'created_at'    => $r->created_at,
            );
        }

        wp_send_json_success( array( 'rows' => $rows, 'total' => $result['total'] ) );
    }

    /**
     * ★ AJAX: 受理（常に消化登録も実行）
     */
    public static function ajax_approve() {
        check_ajax_referer( 'pl_request_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません' );

        $id         = (int) ( $_POST['request_id'] ?? 0 );
        $admin_note = sanitize_textarea_field( $_POST['admin_note'] ?? '' );

        $result = self::approve( $id, $admin_note );

        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            // 消化登録失敗でも受理自体は成功している場合は success で返す（警告メッセージ付き）
            if ( $code === 'consume_failed' ) {
                wp_send_json_success( array( 'message' => $result->get_error_message() ) );
            }
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'     => '受理し、消化登録も完了しました。',
            'approved_by' => wp_get_current_user()->display_name ?: wp_get_current_user()->user_login,
        ) );
    }

    /**
     * ★ AJAX: 却下
     */
    public static function ajax_reject() {
        check_ajax_referer( 'pl_request_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_custom_plugins' ) ) wp_send_json_error( '権限がありません' );

        $id         = (int) ( $_POST['request_id'] ?? 0 );
        $admin_note = sanitize_textarea_field( $_POST['admin_note'] ?? '' );

        $result = self::reject( $id, $admin_note );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'     => '却下しました。',
            'approved_by' => wp_get_current_user()->display_name ?: wp_get_current_user()->user_login,
        ) );
    }
}
