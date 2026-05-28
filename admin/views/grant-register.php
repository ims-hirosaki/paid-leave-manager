<?php if ( ! defined('ABSPATH') ) exit;
$init_code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
$settings  = PL_Rules::get_settings();
$units     = json_decode( $settings['consumption_units'] ?? '["1.0"]', true ) ?: array('1.0');
?>
<div class="wrap pl-wrap" id="pl-grant-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-calendar-alt"></span> 付与・消化登録</h1>

<div class="pl-card">
    <div class="pl-card-title">社員検索</div>
    <div class="pl-search-row">
        <input type="text" id="plEmpCode" class="pl-input" placeholder="社員コードを入力"
            value="<?php echo esc_attr($init_code); ?>" style="width:200px;">
        <button type="button" id="plSearchBtn" class="pl-btn pl-btn-primary">検索</button>
    </div>
</div>

<div id="plEmpResult" style="display:none;">

    <!-- 社員基本情報 -->
    <div class="pl-card">
        <div class="pl-card-title">社員情報</div>
        <div class="pl-info-grid">
            <span class="pl-info-key">社員コード</span><span class="pl-info-val" id="riCode"></span>
            <span class="pl-info-key">氏名</span><span class="pl-info-val" id="riName"></span>
            <span class="pl-info-key">入社日</span><span class="pl-info-val" id="riHire"></span>
            <span class="pl-info-key">雇用区分</span><span class="pl-info-val" id="riEmpType"></span>
            <span class="pl-info-key">週勤務日数</span><span class="pl-info-val" id="riWeekly"></span>
        </div>
    </div>

    <!-- 有給状況テーブル -->
    <div class="pl-card">
        <div class="pl-card-title">有給状況</div>
        <div class="pl-table-wrap">
        <table class="pl-data-table">
            <thead>
                <tr>
                    <th>社員コード</th><th>氏名</th><th>入社日</th><th>有給発生日</th>
                    <th>付与日数</th><th>消化日数</th><th>残日数</th><th>消化率</th><th>詳細</th>
                </tr>
            </thead>
            <tbody id="plGrantTbody">
                <tr><td colspan="9" class="pl-empty">検索してください</td></tr>
            </tbody>
        </table>
        </div>

        <div class="pl-bar-wrap" id="plBarWrap" style="display:none;">
            <div class="pl-bar-label">
                <span>消化済み <strong id="plBarConsumed">0</strong> 日</span>
                <span>残り <strong id="plBarRemaining">0</strong> 日</span>
            </div>
            <div class="pl-progress-bar">
                <div class="pl-progress-fill" id="plProgressFill"></div>
            </div>
            <div class="pl-bar-rate" id="plBarRate"></div>
        </div>
    </div>

    <!-- 付与・消化 横並びレイアウト -->
    <div class="pl-two-col" id="plActionRow">
        <!-- 付与セクション -->
        <div id="plGrantSection" style="display:none;">
            <div class="pl-card pl-card-grant pl-card-full">
                <div class="pl-card-title pl-card-title-green">付与処理</div>
                <div id="plGrantInfo" class="pl-grant-info"></div>
                <div class="pl-field-row" style="margin-top:1rem;">
                    <label class="pl-label">付与日</label>
                    <input type="date" id="plGrantDate" class="pl-input" value="<?php echo date('Y-m-d'); ?>" style="width:100%;">
                </div>
                <div class="pl-field-row">
                    <label class="pl-label">付与日数</label>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <input type="number" id="plGrantDays" class="pl-input" min="0" max="40" step="0.5" style="width:90px;">
                        <span class="pl-unit">日</span>
                    </div>
                    <span class="pl-hint">ルールに基づく日数。変更可</span>
                </div>
                <button type="button" id="plGrantBtn" class="pl-btn pl-btn-success pl-btn-block" style="margin-top:1rem;">
                    <span class="dashicons dashicons-plus"></span> 付与する
                </button>
            </div>
        </div>

        <!-- 消化登録セクション -->
        <div id="plConsumeSection" style="display:none;">
            <div class="pl-card pl-card-consume pl-card-full">
                <div class="pl-card-title pl-card-title-orange">消化登録</div>
                <div class="pl-field-row" style="margin-top:.5rem;">
                    <label class="pl-label">消化日</label>
                    <input type="date" id="plConsumeDate" class="pl-input" value="<?php echo date('Y-m-d'); ?>" style="width:100%;">
                </div>
                <div class="pl-field-row">
                    <label class="pl-label">消化日数</label>
                    <select id="plConsumeDays" class="pl-select" style="width:140px;">
                        <?php foreach ( $units as $u ) : ?>
                            <option value="<?php echo esc_attr($u); ?>"><?php echo esc_html($u); ?> 日</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pl-field-row">
                    <label class="pl-label">備考</label>
                    <input type="text" id="plConsumeNote" class="pl-input" placeholder="任意" style="width:100%;">
                </div>
                <button type="button" id="plConsumeBtn" class="pl-btn pl-btn-warning pl-btn-block" style="margin-top:.5rem;">
                    <span class="dashicons dashicons-minus"></span> 消化を登録する
                </button>
            </div>
        </div>
    </div>

</div><!-- #plEmpResult -->
</div>
