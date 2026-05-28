<?php
/**
 * Plugin Name: 有給管理システム
 * Description: 社員情報管理システムと連携した有給休暇の付与・消化・集計を管理するプラグイン
 * Version:     1.2.0
 * Author:      IMS
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PL_VERSION', '1.2.0' );
define( 'PL_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PL_URL',     plugin_dir_url( __FILE__ ) );
define( 'PL_FILE',    __FILE__ );

// =====================================================
//  ★ 有給申請管理ページの表示制御
//
//  false = メニュー非表示（現在の運用：個人ページから直接受理）
//  true  = メニュー表示（将来、一覧型の申請管理が必要になった場合に変更）
// =====================================================
define( 'PL_SHOW_REQUESTS_PAGE', false );

require_once PL_DIR . 'includes/class-db-install.php';
require_once PL_DIR . 'includes/class-employee-bridge.php';
require_once PL_DIR . 'includes/class-rules.php';
require_once PL_DIR . 'includes/class-holiday.php';
require_once PL_DIR . 'includes/class-grant.php';
require_once PL_DIR . 'includes/class-consumption.php';
require_once PL_DIR . 'includes/class-summary.php';
require_once PL_DIR . 'includes/class-request.php';
require_once PL_DIR . 'includes/class-mat-bridge.php';
require_once PL_DIR . 'includes/class-data-reset.php'; // ★ テストデータ削除
require_once PL_DIR . 'admin/class-admin-menu.php';

register_activation_hook( __FILE__, array( 'PL_DB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PL_DB_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'pl_init' );
function pl_init() {
    if ( get_option( 'pl_db_version' ) !== PL_VERSION ) {
        PL_DB_Install::activate();
    }
}

if ( is_admin() ) {
    new PL_Admin_Menu();
}

new PL_Mat_Bridge();

add_action( 'pl_annual_holiday_fetch', array( 'PL_Holiday', 'fetch_and_cache' ) );
if ( ! wp_next_scheduled( 'pl_annual_holiday_fetch' ) ) {
    $next = mktime( 0, 0, 0, 4, 1, (int) date('Y') );
    if ( $next < time() ) $next = mktime( 0, 0, 0, 4, 1, (int) date('Y') + 1 );
    wp_schedule_event( $next, 'yearly', 'pl_annual_holiday_fetch' );
}

function pl_get_request_status( $employee_code, $date ) {
    return PL_Request::get_status( $employee_code, $date );
}
