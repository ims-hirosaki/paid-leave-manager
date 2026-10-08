# 06 機能一覧とAPI仕様

## 1. 機能一覧（機能ID）

| ID | 機能 | 主な実装 | 画面 |
|---|---|---|---|
| F01 | 付与可否判定（勤続月数→日数） | `PL_Grant::check_grantable` / `PL_Rules::get_granted_days` | S02 |
| F02 | 付与登録 | `PL_Grant::execute_grant` | S02 |
| F03 | 消化チェック | `PL_Consumption::check_consumable` | S02 |
| F04 | 消化登録（FIFO） | `PL_Consumption::execute` | S02 |
| F05 | 期間一括消化（UI無し） | `PL_Consumption::execute_range` | － |
| F06 | 失効処理 | `PL_Grant::expire_old_grants` | 全画面で自動 |
| F07 | サマリー計算 | `PL_Grant::get_summary` / `get_current_cycle` | S02, S03 |
| F08 | 集計表・一覧データ | `PL_Summary::get_list` | S01, S04 |
| F09 | ルール／設定の保存・取得 | `PL_Rules::*` | S05 |
| F10 | 祝日取得・休日判定 | `PL_Holiday::*` | S05, 消化・申請時 |
| F11 | 申請の作成（MAT） | `PL_Mat_Bridge` → `PL_Request::create` | － |
| F12 | 申請の受理（消化自動登録）／却下 | `PL_Request::approve` / `reject` | S03 |
| F13 | CSV付与インポート | `PL_Import` | S06 |
| F14 | CSV消化インポート | `PL_Import` | S06 |
| F15 | テストデータ削除 | `PL_Data_Reset` | S07 |
| F16 | DB作成・移行・初期データ | `PL_DB_Install` | 有効化時／バージョン不一致時 |

## 2. AJAX アクション一覧

共通：URL `admin-ajax.php`、メソッド POST、`nonce` パラメータ必須（`check_ajax_referer`）。権限不足時の挙動は `wp_die(-1)`（レスポンス `-1`）と `wp_send_json_error` が混在。
JS へは `plData` で nonce を渡す：`rulesNonce`（pl_rules_nonce）、`grantNonce`（pl_grant_nonce）、`summaryNonce`、`requestsNonce`（pl_request_nonce）、`resetNonce`。インポートの nonce（`pl_import_nonce`）はインポート画面内で直接生成。個人管理画面は申請用 nonce（`pl_request_nonce`）を画面内で生成。

| action | ハンドラ | nonce名 | 必要権限 | 主な入力 | 成功時の主な出力 |
|---|---|---|---|---|---|
| pl_rules_get | PL_Rules::ajax_get | pl_rules_nonce | manage_custom_plugin_settings | － | matrix, settings |
| pl_rules_save | PL_Rules::ajax_save | pl_rules_nonce | manage_custom_plugin_settings | rules[tenure][weekly], effective_date, 各設定値 | message「ルールを保存しました（◯件）」 |
| pl_holiday_fetch | PL_Holiday::ajax_fetch | pl_rules_nonce | manage_custom_plugin_settings | － | message（取得件数） |
| pl_grant_check | PL_Grant::ajax_check | pl_grant_nonce | access_custom_plugins | employee_code | employee, check, summary |
| pl_grant_execute | PL_Grant::ajax_execute | pl_grant_nonce | edit_custom_plugins | employee_code, tenure_months, grant_date, granted_days, weekly_days | message, summary |
| pl_grant_get_summary | PL_Grant::ajax_get_summary_for_employee | pl_grant_nonce | access_custom_plugins | employee_code | 有効期間内／今年の数値、年5日アラート、後方互換キー |
| pl_consume_check | PL_Consumption::ajax_check | pl_grant_nonce | access_custom_plugins | employee_code, consume_date, consume_days | ok, available（NG時は error） |
| pl_consume_execute | PL_Consumption::ajax_execute | pl_grant_nonce | edit_custom_plugins | mode(single/range), employee_code, consume_date/consume_days もしくは date_from/date_to, unit_type, note | message |
| pl_summary_get | PL_Summary::ajax_get | pl_summary_nonce | access_custom_plugins | date_from, date_to, mode(grant/consume), affiliation_id?, department_id? | 社員ごとの配列（下記） |
| pl_request_approve | PL_Request::ajax_approve | pl_request_nonce | edit_custom_plugins | request_id, admin_note | message, approved_by |
| pl_request_reject | PL_Request::ajax_reject | pl_request_nonce | edit_custom_plugins | request_id, admin_note | message, approved_by |
| pl_request_get_list | PL_Request::ajax_get_list | pl_request_nonce | access_custom_plugins | status, date_from, date_to | rows, total（**`PL_SHOW_REQUESTS_PAGE=true` のときのみ登録**） |
| pl_reset_get_counts | PL_Data_Reset::ajax_get_counts | pl_reset_nonce | manage_custom_plugin_settings | target(all/employee), employee_code | counts, total |
| pl_reset_execute | PL_Data_Reset::ajax_execute | pl_reset_nonce | manage_custom_plugin_settings | target, employee_code | message, detail |
| pl_import_preview | PL_Import::ajax_preview | pl_import_nonce | access_custom_plugins | csv_file（multipart） | summary, errors, dups, sample, can_import |
| pl_import_execute | PL_Import::ajax_execute | pl_import_nonce | edit_custom_plugins | － | inserted, valid_cnt, expired_cnt, skipped, failed, failed_count |
| pl_consume_import_preview | PL_Import::ajax_consume_preview | pl_import_nonce | access_custom_plugins | csv_file | summary, errors, dups, sample, can_import |
| pl_consume_import_execute | PL_Import::ajax_consume_execute | pl_import_nonce | edit_custom_plugins | － | inserted, to_valid, to_expired, skipped, failed, failed_count |

> `pl_import_*` と `pl_consume_import_*` は `PL_Import::init()`（管理画面のみ）で登録。それ以外は `PL_Admin_Menu::__construct`。

### `pl_summary_get` の1要素
`employee_code, name, hire_date, employment_type, weekly_work_days, first_grant_date, total_granted, consumed, consumed_this_year, remaining, rate, expiry_warning_days, pending_count`

### `pl_grant_check` の `check`
- 付与可：`{grantable:true, tenure_months, weekly_days, granted_days, message}`
- 付与不可：`{grantable:false, message}`（入社日未登録）、`{grantable:false, tenure_months, weekly_days, message}`（ルールなし／6か月未満）

### `pl_grant_get_summary` / `get_summary()` の主なキー
`grants, total_granted, total_remaining, total_consumed, total_expired, consumed_this_year, consumption_rate, expiring_soon, valid_granted, valid_consumed, valid_remaining, valid_rate, valid_start, valid_end, cycle_start, cycle_end, year_granted, year_consumed, year_rate, year_alert`
（`total_expired` ＝付与合計−消化合計−有効残。0未満は0。後方互換のため保持）

## 3. 主要クラスとメソッド

### PL_Rules（`includes/class-rules.php`）
`get_settings()` 全設定の連想配列 / `get_setting($key,$default)` / `update_setting($key,$value)`（upsert）/ `get_rules()` / `get_rule($tenure,$weekly,$as_of)` 適用日≦基準日で最新1件 / `get_granted_days($tenure_months,$weekly_days,$as_of)` / `save_rules($data,$effective_date)`（セルごとに upsert、負値はスキップ。保存セル数を返す）/ `get_rules_matrix($effective_date)` 7×6配列（無ければ0.0）

### PL_Grant（`class-grant.php`）
`get_current_cycle($code)` / `get_summary($code)` / `get_all_grants` / `get_recent_grants($code,$limit=3)` / `expire_old_grants()` / `check_grantable($emp)` / `execute_grant($code,$tenure_months,$grant_date,$days,$weekly_days=5)`（成功時 insert_id、失敗時 WP_Error）

### PL_Consumption（`class-consumption.php`）
`check_consumable` / `get_available_days` / `execute($code,$date,$days,$unit_type='day',$note='')`（成功時 消化ログID配列、不足時 WP_Error `insufficient`）/ `execute_range` / `get_logs($code)`（付与の日付をJOIN、日付降順）

### PL_Summary / PL_Holiday / PL_Request / PL_Import / PL_Data_Reset / PL_Employee_Bridge
- `PL_Summary::get_list($from,$to,$mode,$args)`
- `PL_Holiday::fetch_and_cache()`（成功時 件数、失敗時 false）/ `get_holidays_for_year($year)`（連想配列 日付→名称）/ `is_holiday($date)`
- `PL_Request`：`create($code_or_array,$date,$note)`（成功時 ID、重複/休日/不正は WP_Error）/ `approve($id,$note)` / `reject($id,$note)` / `get_by_id` / `get_by_employee_date` / `get_by_employee($code,$status)` / `get_list($args)`（emp_master を LEFT JOIN）/ `get_pending_count()` / `get_status_label($status)` / `get_status($code,$date)`（※下記グローバル関数経由で参照）
  - WP_Error コード：`invalid_params`, `holiday`, `duplicate`, `db_error`, `not_found`, `already_approved`, `already_rejected`, `consume_failed`
- `PL_Import`（定数：表示明細上限200件、transient TTL 1時間、アップロード上限30MB）
- `PL_Data_Reset`：`get_counts_by_employee` / `get_counts_all` / `delete_by_employee`（消化→付与→申請の順に DELETE）/ `delete_all`（TRUNCATE）
- `PL_Employee_Bridge`：`get_active_employees($args)` / `get_by_code` / `get_by_id` / `get_affiliations` / `get_departments` / `is_available()`。employee-manager が無効なら空配列／null を返すだけ（エラーにしない）。

## 4. グローバル関数・定数・フック

| 名前 | 種別 | 内容 |
|---|---|---|
| `PL_VERSION` / `PL_DIR` / `PL_URL` / `PL_FILE` | 定数 | バージョン、パス、URL |
| `PL_SHOW_REQUESTS_PAGE` | 定数 | 申請管理ページの表示（現在 false） |
| `pl_get_request_status($code,$date)` | 関数 | 他プラグイン（MAT 等）から申請状態を問い合わせる公開関数。内部で `PL_Request::get_status` を呼ぶが、**`PL_Request` に `get_status` メソッドは定義されていない**（09 参照） |
| `my_attendance_paid_leave_saved` | アクション（受信） | MAT が有給希望保存時に発火。ペイロード配列（下記）を `PL_Mat_Bridge::on_paid_leave_saved` が受け、`PL_Request::create(employee_code, paid_leave_date, '')` |
| `pl_annual_holiday_fetch` | アクション（自作） | `PL_Holiday::fetch_and_cache` を実行する Cron イベント |
| `pl_init`（plugins_loaded） | 関数 | DBバージョン不一致時に `PL_DB_Install::activate()` |

### MAT 連携ペイロード
`emp_master_id`(int)、`employee_code`、`employee_name`、`paid_leave_date`('Y-m-d')、`log_id`(int)、`action`('insert'|'update')。**使用するのは `employee_code` と `paid_leave_date` のみ**。`action` の区別はしない（update でも新規作成を試み、重複ならエラーで無視される。作成結果の WP_Error は握りつぶされる）。

## 5. エラーメッセージ一覧（主なもの）

| メッセージ | 発生箇所 |
|---|---|
| 社員コードが必要です / 社員が見つかりません | 付与検索 |
| 入社日が登録されていません | 付与可否判定 |
| 勤続 ◯ ヶ月・週 ◯ 日勤務に対応するルールが見つかりません | 付与可否判定 |
| 必須パラメータが不足しています | 付与実行（社員コード空 または 付与日数≦0） |
| DB登録エラー: … | 付与・申請の insert 失敗 |
| 法定休日（または祝日）には有給休暇を取得できません | 消化チェック |
| ◯ は既に ◯ 日の消化が登録されています | 消化チェック |
| 消化単位は ◯ 日のみ有効です | 消化チェック |
| 残日数（◯日）が不足しています / 残日数が不足しています | 消化チェック／FIFO実行 |
| 開始日と終了日を入力してください / 開始日は終了日より前にしてください | 期間消化 |
| 祝日・法定休日には申請できません。 | 申請作成 |
| この日付の申請はすでに存在します（状態：◯）。 | 申請作成 |
| すでに受理済みです。 / すでに却下済みです。 / 受理済みの申請は却下できません。… / 申請が見つかりません。 | 受理・却下 |
| 受理しましたが消化登録に失敗しました: …　付与・消化登録ページから手動で登録してください。 | 受理（消化失敗） |
| 祝日データの取得に失敗しました | 祝日取得 |
| ファイルが選択されていません／サイズが大きすぎます／CSVファイル（.csv）を選択してください／不正なアップロードです | CSV検証 |
| プレビュー結果が見つかりません（有効期限切れの可能性があります）… | CSV本実行 |
| 有給発生日が不正です／付与日数が不正です／消化日が不正です／社員マスタに存在しない社員番号です／既に登録済み／消化日に有効な付与の残が不足しています／消化日に有効だった付与が見つかりません | CSV行エラー |

## 6. セキュリティ実装の概要
- 全 AJAX で nonce 検証＋ケイパビリティ確認。入力は `sanitize_text_field` 等で整形、SQL は `$wpdb->prepare`。
- 一部（`SHOW TABLES LIKE`、`TRUNCATE`、`COUNT(*)` 等）は固定文字列のSQLで、ユーザー入力を含まない。
- ファイルアップロード：拡張子（csv/txt）・サイズ・`is_uploaded_file` を検証。ファイル内容は `fgetcsv` で読み、保存せず transient に行データのみ保持。
- 付与登録（`pl_grant_execute`）は**サーバー側で付与日数の妥当性（ルールとの一致）を検証しない**。クライアントから渡された値をそのまま登録する（権限者の手動修正を許容する設計）。
