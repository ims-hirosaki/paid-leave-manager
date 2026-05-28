<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_Mat_Bridge {

    public function __construct() {
        // ajax-handlers.php は do_action に配列を1つ渡す
        add_action( 'my_attendance_paid_leave_saved', array( $this, 'on_paid_leave_saved' ), 10, 1 );
    }

    /**
     * MATから有給希望が保存されたときに呼ばれる
     *
     * @param array $payload {
     *   @type int    $emp_master_id
     *   @type string $employee_code
     *   @type string $employee_name
     *   @type string $paid_leave_date  'Y-m-d'
     *   @type int    $log_id
     *   @type string $action           'insert' | 'update'
     * }
     */
    public function on_paid_leave_saved( $payload ) {
        if ( empty( $payload['employee_code'] ) || empty( $payload['paid_leave_date'] ) ) {
            return;
        }
        PL_Request::create(
            $payload['employee_code'],
            $payload['paid_leave_date'],
            ''
        );
    }
}
