# 有給管理システム — WordPress プラグイン

社員情報管理システム（`employee-manager`）と連携して、有給休暇の付与・消化・集計を管理するプラグインです。労働基準法の標準ルールをデフォルト値として搭載し、内閣府の祝日データと自動連携します。

---

## 機能一覧

| 機能 | 説明 |
|---|---|
| 付与ルール管理 | 勤続年数 × 週勤務日数のマトリクスで付与日数を定義 |
| 付与処理 | 勤続月数を自動判定し、ルールに基づく付与日数を提示・登録 |
| 消化登録 | 単日での消化登録（先入れ先出しで複数付与にまたがって消化） |
| 個人管理 | 残日数・消化率・失効予告・消化ログを1画面で確認 |
| 集計表 | 期間・方式を指定して全社員の有給状況を一覧表示 |
| 従業員一覧 | 残日数・消化率を一覧で把握し、各種画面へクイックアクセス |
| 祝日連携 | 内閣府CSVから国民の祝日を取得・キャッシュ（WP-Cron自動更新） |
| 失効管理 | 付与から2年（設定変更可）で自動失効。3か月前から警告表示 |

---

## 動作要件

| 項目 | 要件 |
|---|---|
| WordPress | 5.8 以上 |
| PHP | 8.0 以上 |
| MySQL | 5.7 以上 / MariaDB 10.3 以上 |
| 依存プラグイン | `employee-manager`（社員情報管理システム） |
| 権限 | 管理者（`manage_options`）のみ使用可能 |

> **注意：** `employee-manager` が有効化されていない場合、社員情報の取得ができません。先に `employee-manager` を有効化してください。

---

## インストール

1. `paid-leave-manager.zip` を WordPress 管理画面 → **プラグイン → 新規追加 → ZIPをアップロード** でインストール
2. **有効化** をクリック
3. 有効化と同時に5つのテーブルが自動作成され、労働基準法の標準付与ルールが自動投入されます
4. **有給管理 → 有給ルール設定** で祝日データを取得してから運用を開始してください

---

## ファイル構成

```
paid-leave-manager/
│
├── paid-leave-manager.php              # メインファイル・WP-Cron設定
├── uninstall.php                       # プラグイン削除時にテーブルをDROP
│
├── includes/
│   ├── class-db-install.php            # 5テーブルの作成・デフォルトデータ投入
│   ├── class-employee-bridge.php       # employee-manager 公開APIのラッパー
│   ├── class-rules.php                 # 付与ルール・各種設定のCRUD
│   ├── class-holiday.php               # 内閣府CSV祝日取得・DBキャッシュ
│   ├── class-grant.php                 # 付与処理・サマリー・失効処理
│   ├── class-consumption.php           # 消化処理（先入れ先出し・重複チェック）
│   └── class-summary.php              # 集計表データ生成
│
└── admin/
    ├── class-admin-menu.php            # メニュー登録・アセット読込・AJAXフック
    │
    ├── views/
    │   ├── employee-list.php           # 従業員一覧画面
    │   ├── grant-register.php          # 付与・消化登録画面
    │   ├── employee-detail.php         # 個人管理画面（メニュー非表示）
    │   ├── summary.php                 # 集計表画面
    │   └── rules.php                   # 有給ルール設定画面
    │
    └── assets/
        ├── admin.css                   # 全画面共通スタイル
        └── admin.js                    # 全画面のAJAX・インタラクション
```

---

## 管理画面メニュー構成

```
有給管理（トップメニュー）
├── 従業員一覧
├── 付与・消化登録
├── 集計表
├── 有給ルール設定
└── 個人管理（メニュー非表示 / URLまたは「詳細」ボタンからアクセス）
```

---

## データベース設計

### テーブル一覧

| テーブル名 | 用途 |
|---|---|
| `{prefix}paidleave_rules` | 付与日数ルール（勤続月数 × 週勤務日数 → 付与日数） |
| `{prefix}paidleave_settings` | 各種設定（キーバリュー形式） |
| `{prefix}paidleave_grants` | 付与ログ（社員ごとの付与履歴） |
| `{prefix}paidleave_consumptions` | 消化ログ（日付・消化日数・充当した付与ID） |
| `{prefix}paidleave_holidays` | 祝日キャッシュ（内閣府CSV由来） |

### paidleave_rules

| カラム | 型 | 説明 |
|---|---|---|
| `id` | INT | 主キー |
| `tenure_months` | SMALLINT | 勤続月数（6/18/30/42/54/66/78） |
| `weekly_days` | TINYINT | 週勤務日数（1〜6） |
| `granted_days` | DECIMAL(4,1) | 付与日数 |
| `effective_date` | DATE | ルール適用開始日 |

### paidleave_grants

| カラム | 型 | 説明 |
|---|---|---|
| `id` | INT | 主キー |
| `employee_code` | VARCHAR(20) | 社員コード |
| `tenure_months` | SMALLINT | 付与時の勤続月数 |
| `weekly_work_days_at_grant` | TINYINT | 付与時の週勤務日数 |
| `grant_date` | DATE | 付与日（有給発生日） |
| `expiry_date` | DATE | 有効期限 |
| `granted_days` | DECIMAL(4,1) | 付与日数 |
| `remaining_days` | DECIMAL(4,1) | 残日数 |
| `is_expired` | TINYINT(1) | 失効フラグ |

### paidleave_consumptions

| カラム | 型 | 説明 |
|---|---|---|
| `id` | INT | 主キー |
| `grant_id` | INT | 充当した付与ID（paidleave_grants.id） |
| `employee_code` | VARCHAR(20) | 社員コード |
| `consumed_date` | DATE | 消化日 |
| `consumed_days` | DECIMAL(5,2) | 消化日数 |
| `unit_type` | VARCHAR(10) | 消化単位（day / half_day / hour） |
| `note` | VARCHAR(255) | 備考 |

### paidleave_settings（設定キー一覧）

| キー | デフォルト値 | 説明 |
|---|---|---|
| `carryover_years` | `2` | 繰り越し可能年数 |
| `expiration_years` | `2` | 有効期限（付与から何年） |
| `min_annual_days` | `5` | 年間最低消化義務日数 |
| `consumption_units` | `["1.0"]` | 有効な消化単位（JSON配列） |
| `legal_holiday_dow` | `[0]` | 法定休日曜日（0=日〜6=土） |
| `use_national_holidays` | `1` | 国民の祝日を法定休日として扱う |
| `rules_effective_date` | インストール日 | ルール適用開始日 |

---

## 使い方

### 初期設定

**1. 祝日データの取得**

```
有給管理 → 有給ルール設定 → 「内閣府CSVから祝日を取得・更新」ボタン
```

内閣府のWebサイトからCSVをダウンロードし、`paidleave_holidays` テーブルにキャッシュします。
毎年4月1日にWP-Cronで自動更新されます。

**2. 付与ルールの確認・調整**

```
有給管理 → 有給ルール設定
```

インストール時に労働基準法の標準値が自動投入されます。自社ルールに合わせて変更してください。

| 勤続期間 | 週5以上勤務 | 週4勤務 | 週3勤務 | 週2勤務 | 週1勤務 |
|---|---|---|---|---|---|
| 6ヶ月 | 10日 | 7日 | 5日 | 3日 | 1日 |
| 1年6ヶ月 | 11日 | 8日 | 6日 | 4日 | 2日 |
| 2年6ヶ月 | 12日 | 9日 | 6日 | 4日 | 2日 |
| 3年6ヶ月 | 14日 | 10日 | 8日 | 5日 | 2日 |
| 4年6ヶ月 | 16日 | 12日 | 9日 | 6日 | 3日 |
| 5年6ヶ月 | 18日 | 13日 | 10日 | 6日 | 3日 |
| 6年6ヶ月以上 | 20日 | 15日 | 11日 | 7日 | 3日 |

---

### 付与処理

```
有給管理 → 付与・消化登録 → 社員コードで検索
```

1. 社員コードを入力して検索
2. 付与可能な場合は「付与処理」カードに勤続月数と付与日数が自動表示されます
3. 付与日・付与日数を確認（変更可能）して「付与する」をクリック

**付与タイミングの判定ロジック：**
- 入社日からの経過月数を計算し、6/18/30/42/54/66/78ヶ月の各マイルストーンに到達しているか確認
- 該当マイルストーンで未付与のものが付与対象
- 社員の `weekly_work_days`（週勤務日数）を参照してルールから付与日数を自動取得

---

### 消化登録

```
有給管理 → 付与・消化登録 → 社員コードで検索
```

1. 社員コードを入力して検索
2. 残日数がある場合は「消化登録」カードが表示されます
3. 消化日・消化日数・備考を入力して「消化を登録する」をクリック

**消化のバリデーション：**
- 法定休日（設定した曜日）および国民の祝日には登録不可
- 同一日の重複登録不可（既に登録がある日はエラー）
- 残日数不足の場合はエラー

**消化の充当方式（先入れ先出し）：**
有効期限が近い付与から順に消化します。複数の付与にまたがる場合も自動で按分します。

---

### 個人管理

```
従業員一覧 → 「詳細」ボタン
付与・消化登録 → 有給状況テーブルの「詳細」リンク
```

1画面で以下をすべて確認できます。

- 社員基本情報・勤続期間
- 残日数・消化日数・今年の消化・消化率（数値＋プログレスバー）
- 失効予告（3ヶ月以内に失効する付与がある場合に警告表示）
- 付与情報（直近3件）
- 全付与ログ（ドロワーで展開）
- 消化ログ一覧（ドロワーで展開）

---

### 集計表

```
有給管理 → 集計表
```

| 設定項目 | 説明 |
|---|---|
| 集計期間 | 開始日〜終了日を指定 |
| 集計方式 | **付与ベース**：期間内に付与されたレコードを対象 / **消化ベース**：期間内に消化された日を対象 |

出力項目：社員コード・氏名・入社日・有給発生日・付与日数・消化日数・残日数・消化率

---

## 主要ロジック仕様

### 失効処理

- `expiry_date < 今日` かつ `is_expired = 0` のレコードを `is_expired = 1`、`remaining_days = 0` に更新
- 付与から `expiration_years`（デフォルト2年）経過で失効
- 失効3ヶ月前から個人管理ページに警告表示

### 先入れ先出し（FIFO）の詳細

消化登録時、以下の優先順で付与レコードから充当します。

1. `is_expired = 0`（有効）
2. `expiry_date >= 今日`（期限切れでない）
3. `remaining_days > 0`（残日数あり）
4. `expiry_date ASC`（**有効期限が近いものから**）

1回の消化で複数の付与にまたがる場合、自動的に按分して `remaining_days` を更新します。

### 勤続月数の計算

```
経過月数 = (今日 - 入社日) の年数 × 12 + 月数
```

PHP の `DateTime::diff()` を使用。日数の端数は切り捨てです。

### 祝日データの更新

- 取得元：内閣府 `https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv`
- 文字コード：Shift-JIS → UTF-8 に変換して保存
- WP-Cron により毎年4月1日 0:00 に自動更新
- 手動更新：有給ルール設定ページの「祝日を取得・更新」ボタン

---

## AJAXアクション一覧

すべて Nonce 検証と `manage_options` 権限チェックを実施しています。

| アクション名 | クラス・メソッド | Nonce |
|---|---|---|
| `pl_rules_get` | `PL_Rules::ajax_get` | `pl_rules_nonce` |
| `pl_rules_save` | `PL_Rules::ajax_save` | `pl_rules_nonce` |
| `pl_holiday_fetch` | `PL_Holiday::ajax_fetch` | `pl_rules_nonce` |
| `pl_grant_check` | `PL_Grant::ajax_check` | `pl_grant_nonce` |
| `pl_grant_execute` | `PL_Grant::ajax_execute` | `pl_grant_nonce` |
| `pl_consume_check` | `PL_Consumption::ajax_check` | `pl_grant_nonce` |
| `pl_consume_execute` | `PL_Consumption::ajax_execute` | `pl_grant_nonce` |
| `pl_summary_get` | `PL_Summary::ajax_get` | `pl_summary_nonce` |

---

## employee-manager との連携

社員情報は `employee-manager` の公開API関数を経由して取得します。直接DBクエリは行いません。

```php
// class-employee-bridge.php が以下の関数をラップ
emp_get_active_employees()      // 在籍中の社員一覧
emp_get_employee_by_code($code) // 社員コードで1件取得
emp_get_employee_by_id($id)     // IDで1件取得
emp_get_affiliations()          // 所属マスタ一覧
emp_get_departments()           // 部署マスタ一覧
```

有給管理システムが参照する `emp_master` のカラム：

| カラム名 | 用途 |
|---|---|
| `employee_code` | 社員コード（有給レコードのキー） |
| `name` | 氏名（画面表示用） |
| `hire_date` | 入社日（勤続月数の計算に使用） |
| `employment_type` | 雇用区分（画面表示のみ） |
| `weekly_work_days` | 週勤務日数（付与ルールの参照に使用） |

---

## アンインストール

WordPress 管理画面でプラグインを**削除**すると、以下の5テーブルがすべて DROP されます。

- `wp_paidleave_rules`
- `wp_paidleave_settings`
- `wp_paidleave_grants`
- `wp_paidleave_consumptions`
- `wp_paidleave_holidays`

**無効化だけではテーブルは削除されません**（データは保持されます）。

---

## 今後の拡張ポイント

| 機能 | 概要 |
|---|---|
| 期間一括消化 | 開始〜終了日を指定して平日に一括登録（`execute_range()` メソッドは実装済み） |
| 半日・時間単位の消化 | `consumption_units` 設定と `unit_type` カラムで対応可能な設計 |
| 消化ログの削除・修正 | 誤登録時の取り消し機能 |
| CSVエクスポート | 集計表・消化ログのCSVダウンロード |
| 年5日消化義務のアラート | `min_annual_days` 設定と照合した警告通知 |
| 一斉付与バッチ | 全在籍社員に対して一括で付与可能な社員を処理 |

---

## 開発メモ

- PHP 8.0 の新構文（`match` 式・名前付き引数等）は意図的に不使用（WordPress 推奨の後方互換を重視）
- `dbDelta()` を使用しているため、カラム追加は `class-db-install.php` の CREATE TABLE 文を修正して再実行するだけでマイグレーション可能
- JavaScript は jQuery（WordPress 同梱）のみを使用し、外部ライブラリへの依存なし
- CSS は `paid-leave-manager-admin` というハンドル名でエンキューし、他プラグインとの競合を防止
- ページ判定は `$_GET['page']` で行う（`$hook` は親スラッグの文字によって不安定なため）

---

## ライセンス

GPL-2.0+
