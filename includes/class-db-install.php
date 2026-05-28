<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PL_DB_Install {

    public static function activate() {
        self::create_tables();
        self::migrate_existing_tables(); // ★ 既存DBへのカラム追加
        self::insert_default_settings();
        self::insert_default_rules();
        update_option( 'pl_db_version', PL_VERSION );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'pl_annual_holiday_fetch' );
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 付与日数ルール
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_rules (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            tenure_months   SMALLINT        NOT NULL COMMENT '勤続月数（6/18/30/42/54/66/78）',
            weekly_days     TINYINT         NOT NULL COMMENT '週勤務日数（1〜6）',
            granted_days    DECIMAL(4,1)    NOT NULL DEFAULT 0 COMMENT '付与日数',
            effective_date  DATE            NOT NULL COMMENT 'ルール適用開始日',
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rule (tenure_months, weekly_days, effective_date)
        ) $charset;" );

        // 設定
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_settings (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            setting_key     VARCHAR(50)     NOT NULL,
            setting_value   VARCHAR(500)    NOT NULL DEFAULT '',
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_key (setting_key)
        ) $charset;" );

        // 付与ログ
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_grants (
            id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            employee_code               VARCHAR(20)     NOT NULL COMMENT '社員コード',
            tenure_months               SMALLINT        NOT NULL COMMENT '付与時の勤続月数',
            weekly_work_days_at_grant   TINYINT         NOT NULL DEFAULT 5 COMMENT '付与時の週勤務日数',
            grant_date                  DATE            NOT NULL COMMENT '有給発生日（付与日）',
            expiry_date                 DATE            NOT NULL COMMENT '有効期限',
            granted_days                DECIMAL(4,1)    NOT NULL COMMENT '付与日数',
            remaining_days              DECIMAL(4,1)    NOT NULL COMMENT '残日数',
            is_expired                  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '失効フラグ',
            created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee (employee_code),
            KEY idx_expiry   (expiry_date)
        ) $charset;" );

        // 消化ログ
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_consumptions (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            grant_id        INT UNSIGNED    NOT NULL COMMENT 'paidleave_grants.id',
            employee_code   VARCHAR(20)     NOT NULL,
            consumed_date   DATE            NOT NULL COMMENT '消化日',
            consumed_days   DECIMAL(5,2)    NOT NULL COMMENT '消化日数',
            unit_type       VARCHAR(10)     NOT NULL DEFAULT 'day' COMMENT 'day / half_day / hour',
            note            VARCHAR(255)        NULL DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee  (employee_code),
            KEY idx_grant     (grant_id)
        ) $charset;" );

        // 祝日キャッシュ
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_holidays (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            holiday_date    DATE            NOT NULL,
            holiday_name    VARCHAR(100)    NOT NULL,
            year            SMALLINT        NOT NULL,
            fetched_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_date (holiday_date),
            KEY idx_year (year)
        ) $charset;" );

        // ★ 修正：MAT連携：有給申請ステータス管理
        //   admin_note  を追加
        //   approved_by を BIGINT → VARCHAR(100) に変更（氏名を格納するため）
        dbDelta( "CREATE TABLE {$wpdb->prefix}paidleave_requests (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            employee_code   VARCHAR(20)     NOT NULL COMMENT '社員コード',
            request_date    DATE            NOT NULL COMMENT '有給希望日',
            status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending / approved / rejected',
            note            VARCHAR(255)        NULL DEFAULT NULL COMMENT '申請備考',
            admin_note      VARCHAR(255)        NULL DEFAULT NULL COMMENT '管理者コメント',
            approved_by     VARCHAR(100)        NULL DEFAULT NULL COMMENT '受理・却下した管理者の表示名',
            approved_at     DATETIME            NULL DEFAULT NULL COMMENT '受理・却下日時',
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee (employee_code),
            KEY idx_date     (request_date),
            KEY idx_status   (status)
        ) $charset;" );
    }

    /**
     * ★ 追加：既存テーブルへのカラム追加マイグレーション
     *
     * dbDelta は新規カラムの追加には対応しているが、
     * カラムの「型変更」には対応していないため ALTER TABLE で補完する。
     * 既にカラムが存在する場合は何もしない。
     */
    public static function migrate_existing_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_requests';

        // ① admin_note カラムが存在しない場合は追加
        $has_admin_note = $wpdb->get_results( $wpdb->prepare(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
               AND TABLE_NAME   = %s
               AND COLUMN_NAME  = 'admin_note'",
            DB_NAME, $table
        ) );
        if ( empty( $has_admin_note ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}`
                 ADD COLUMN `admin_note` VARCHAR(255) NULL DEFAULT NULL
                 COMMENT '管理者コメント'
                 AFTER `note`"
            );
        }

        // ② approved_by が BIGINT 型のままの場合は VARCHAR(100) に変更
        $col_info = $wpdb->get_row( $wpdb->prepare(
            "SELECT DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
               AND TABLE_NAME   = %s
               AND COLUMN_NAME  = 'approved_by'",
            DB_NAME, $table
        ) );
        if ( $col_info && in_array( strtolower( $col_info->DATA_TYPE ), array( 'bigint', 'int', 'tinyint' ), true ) ) {
            $wpdb->query(
                "ALTER TABLE `{$table}`
                 MODIFY COLUMN `approved_by` VARCHAR(100) NULL DEFAULT NULL
                 COMMENT '受理・却下した管理者の表示名'"
            );
        }
    }

    public static function insert_default_settings() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_settings';

        $defaults = array(
            'carryover_years'          => '2',
            'expiration_years'         => '2',
            'min_annual_days'          => '5',
            'consumption_units'        => '["1.0"]',
            'default_consumption_unit' => '1.0',
            'legal_holiday_dow'        => '[0]',
            'use_national_holidays'    => '1',
            'rules_effective_date'     => date('Y-m-d'),
        );

        foreach ( $defaults as $key => $val ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE setting_key = %s", $key
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $table, array(
                    'setting_key'   => $key,
                    'setting_value' => $val,
                ) );
            }
        }
    }

    public static function insert_default_rules() {
        global $wpdb;
        $table = $wpdb->prefix . 'paidleave_rules';

        // 既にルールが存在する場合はスキップ
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) return;

        $effective_date = date('Y-m-d');

        // 労働基準法に基づく標準付与日数テーブル
        $rules = array(
            // 週5日（フルタイム）
            array( 6,  6, 10.0 ), array( 18, 6, 11.0 ), array( 30, 6, 12.0 ),
            array( 42, 6, 14.0 ), array( 54, 6, 16.0 ), array( 66, 6, 18.0 ),
            array( 78, 6, 20.0 ),
            // 週4日
            array( 6,  4, 7.0 ),  array( 18, 4, 8.0 ),  array( 30, 4, 9.0 ),
            array( 42, 4, 10.0 ), array( 54, 4, 12.0 ), array( 66, 4, 13.0 ),
            array( 78, 4, 15.0 ),
            // 週3日
            array( 6,  3, 5.0 ),  array( 18, 3, 6.0 ),  array( 30, 3, 6.0 ),
            array( 42, 3, 8.0 ),  array( 54, 3, 9.0 ),  array( 66, 3, 10.0 ),
            array( 78, 3, 11.0 ),
            // 週2日
            array( 6,  2, 3.0 ),  array( 18, 2, 4.0 ),  array( 30, 2, 4.0 ),
            array( 42, 2, 5.0 ),  array( 54, 2, 6.0 ),  array( 66, 2, 6.0 ),
            array( 78, 2, 7.0 ),
            // 週1日
            array( 6,  1, 1.0 ),  array( 18, 1, 2.0 ),  array( 30, 1, 2.0 ),
            array( 42, 1, 2.0 ),  array( 54, 1, 3.0 ),  array( 66, 1, 3.0 ),
            array( 78, 1, 3.0 ),
        );

        foreach ( $rules as $r ) {
            $wpdb->insert( $table, array(
                'tenure_months'  => $r[0],
                'weekly_days'    => $r[1],
                'granted_days'   => $r[2],
                'effective_date' => $effective_date,
            ) );
        }
    }
}
