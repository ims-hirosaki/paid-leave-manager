<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 社員管理プラグインの公開API関数をラップするブリッジクラス
 */
class PL_Employee_Bridge {

    /**
     * 在籍中の社員一覧を取得（有給管理用）
     */
    public static function get_active_employees( $args = array() ) {
        if ( function_exists( 'emp_get_active_employees' ) ) {
            return emp_get_active_employees( $args );
        }
        return array();
    }

    /**
     * 社員コードで1件取得
     */
    public static function get_by_code( $code ) {
        if ( function_exists( 'emp_get_employee_by_code' ) ) {
            return emp_get_employee_by_code( $code );
        }
        return null;
    }

    /**
     * IDで1件取得
     */
    public static function get_by_id( $id ) {
        if ( function_exists( 'emp_get_employee_by_id' ) ) {
            return emp_get_employee_by_id( $id );
        }
        return null;
    }

    /**
     * 所属マスタ一覧
     */
    public static function get_affiliations() {
        if ( function_exists( 'emp_get_affiliations' ) ) {
            return emp_get_affiliations();
        }
        return array();
    }

    /**
     * 部署マスタ一覧
     */
    public static function get_departments() {
        if ( function_exists( 'emp_get_departments' ) ) {
            return emp_get_departments();
        }
        return array();
    }

    /**
     * 社員管理プラグインが有効かどうか確認
     */
    public static function is_available() {
        return function_exists( 'emp_get_active_employees' );
    }
}
