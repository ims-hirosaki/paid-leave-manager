<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wrap pl-wrap" id="pl-summary-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-chart-bar"></span> 集計表</h1>

<div class="pl-card">
    <div class="pl-card-title">集計条件</div>
    <div class="pl-filter-row">
        <div class="pl-field-row">
            <label class="pl-label">集計期間</label>
            <input type="date" id="plSumFrom" class="pl-input" value="<?php echo date('Y-01-01'); ?>">
            <span class="pl-unit">〜</span>
            <input type="date" id="plSumTo" class="pl-input" value="<?php echo date('Y-12-31'); ?>">
        </div>
        <div class="pl-field-row">
            <label class="pl-label">集計方式</label>
            <div class="pl-radio-group">
                <label class="pl-radio-label">
                    <input type="radio" name="pl_mode" value="grant" checked> 付与ベース
                </label>
                <label class="pl-radio-label">
                    <input type="radio" name="pl_mode" value="consume"> 消化ベース
                </label>
            </div>
        </div>
        <button type="button" id="plSumSearch" class="pl-btn pl-btn-primary">
            <span class="dashicons dashicons-search"></span> 集計する
        </button>
    </div>
</div>

<!-- 結果テーブル -->
<div class="pl-card" style="padding:0;">
<div class="pl-table-wrap">
<table class="pl-data-table" id="plSumTable">
    <thead>
        <tr>
            <th>社員コード</th>
            <th>氏名</th>
            <th>入社日</th>
            <th>有給発生日</th>
            <th>付与日数</th>
            <th>消化日数</th>
            <th>残日数</th>
            <th>消化率</th>
        </tr>
    </thead>
    <tbody id="plSumTbody">
        <tr><td colspan="8" class="pl-empty">集計条件を入力して「集計する」を押してください</td></tr>
    </tbody>
</table>
</div>
</div>

</div>
