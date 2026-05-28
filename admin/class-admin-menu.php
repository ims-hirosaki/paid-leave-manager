<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 常時有効なAJAXアクション
        $ajax_actions = array(
            'pl_rules_get'         => array( 'PL_Rules',       'ajax_get' ),
            'pl_rules_save'        => array( 'PL_Rules',       'ajax_save' ),
            'pl_holiday_fetch'     => array( 'PL_Holiday',     'ajax_fetch' ),
            'pl_grant_check'       => array( 'PL_Grant',       'ajax_check' ),
            'pl_grant_execute'     => array( 'PL_Grant',       'ajax_execute' ),
            'pl_grant_get_summary' => array( 'PL_Grant',       'ajax_get_summary_for_employee' ),
            'pl_consume_check'     => array( 'PL_Consumption', 'ajax_check' ),
            'pl_consume_execute'   => array( 'PL_Consumption', 'ajax_execute' ),
            'pl_summary_get'       => array( 'PL_Summary',     'ajax_get' ),
            // 個人ページの受理・却下ボタン用（常時必要）
            'pl_request_approve'   => array( 'PL_Request',     'ajax_approve' ),
            'pl_request_reject'    => array( 'PL_Request',     'ajax_reject' ),
            // ★ テストデータ削除
            'pl_reset_get_counts'  => array( 'PL_Data_Reset',  'ajax_get_counts' ),
            'pl_reset_execute'     => array( 'PL_Data_Reset',  'ajax_execute' ),
        );

        // ★ 有給申請管理ページが有効な場合のみ一覧取得AJAXを追加
        if ( PL_SHOW_REQUESTS_PAGE ) {
            $ajax_actions['pl_request_get_list'] = array( 'PL_Request', 'ajax_get_list' );
        }

        foreach ( $ajax_actions as $action => $callback ) {
            add_action( 'wp_ajax_' . $action, $callback );
        }
    }

    public function register_menus() {
        add_menu_page(
            '有給管理システム', '有給管理', 'manage_options',
            'paid-leave-manager',
            array( $this, 'render_employee_list' ),
            'dashicons-calendar-alt', 31
        );
        add_submenu_page( 'paid-leave-manager', '従業員一覧',       '従業員一覧',       'manage_options', 'paid-leave-manager',  array( $this, 'render_employee_list' ) );
        add_submenu_page( 'paid-leave-manager', '付与・消化登録',   '付与・消化登録',   'manage_options', 'pl-grant-register',   array( $this, 'render_grant_register' ) );
        add_submenu_page( 'paid-leave-manager', '集計表',           '集計表',           'manage_options', 'pl-summary',          array( $this, 'render_summary' ) );
        add_submenu_page( 'paid-leave-manager', '有給ルール設定',   '有給ルール設定',   'manage_options', 'pl-rules',            array( $this, 'render_rules' ) );
        // ★ テストデータ削除
        add_submenu_page( 'paid-leave-manager', 'テストデータ削除', 'テストデータ削除', 'manage_options', 'pl-data-reset',       array( $this, 'render_data_reset' ) );

        // ★ PL_SHOW_REQUESTS_PAGE が true の時だけメニューに表示
        if ( PL_SHOW_REQUESTS_PAGE ) {
            add_submenu_page( 'paid-leave-manager', '有給申請管理', '有給申請管理', 'manage_options', 'pl-requests', array( $this, 'render_requests' ) );
        }

        // 個人管理はメニュー非表示（一覧の「詳細」ボタン経由のみアクセス）
        add_submenu_page( null, '個人管理', '個人管理', 'manage_options', 'pl-employee-detail', array( $this, 'render_employee_detail' ) );
    }

    public function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

        $pl_pages = array(
            'paid-leave-manager', 'pl-grant-register',
            'pl-employee-detail', 'pl-summary', 'pl-rules',
            'pl-data-reset', // ★ 追加
        );

        // ★ 有効な場合のみ pl-requests をロード対象に追加
        if ( PL_SHOW_REQUESTS_PAGE ) {
            $pl_pages[] = 'pl-requests';
        }

        if ( ! in_array( $page, $pl_pages, true ) ) return;

        wp_enqueue_style(  'paid-leave-manager-admin', PL_URL . 'admin/assets/admin.css', array(), PL_VERSION );
        wp_enqueue_script( 'paid-leave-manager-admin', PL_URL . 'admin/assets/admin.js',  array('jquery'), PL_VERSION, true );

        $pending_count = class_exists( 'PL_Request' ) ? PL_Request::get_pending_count() : 0;

        wp_localize_script( 'paid-leave-manager-admin', 'plData', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'rulesNonce'   => wp_create_nonce('pl_rules_nonce'),
            'grantNonce'   => wp_create_nonce('pl_grant_nonce'),
            'summaryNonce' => wp_create_nonce('pl_summary_nonce'),
            'requestsNonce'=> wp_create_nonce('pl_request_nonce'),
            'resetNonce'   => wp_create_nonce('pl_reset_nonce'), // ★ 追加
            'grantUrl'     => admin_url('admin.php?page=pl-grant-register'),
            'detailUrl'    => admin_url('admin.php?page=pl-employee-detail'),
            'requestsUrl'  => admin_url('admin.php?page=pl-requests'),
            'pendingCount' => $pending_count,
        ) );
    }

    public function render_employee_list()   { include PL_DIR . 'admin/views/employee-list.php'; }
    public function render_grant_register()  { include PL_DIR . 'admin/views/grant-register.php'; }
    public function render_employee_detail() { include PL_DIR . 'admin/views/employee-detail.php'; }
    public function render_summary()         { include PL_DIR . 'admin/views/summary.php'; }
    public function render_rules()           { include PL_DIR . 'admin/views/rules.php'; }
    public function render_data_reset()      { include PL_DIR . 'admin/views/data-reset.php'; } // ★ 追加

    // ★ requests.php はファイルとして残しておく（定数がfalseの間は呼ばれない）
    public function render_requests()        { include PL_DIR . 'admin/views/requests.php'; }
}
