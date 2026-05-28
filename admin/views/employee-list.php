<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wrap pl-wrap" id="pl-list-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-groups"></span> 従業員一覧</h1>

<!-- 検索・フィルター -->
<div class="pl-filter-bar">
    <input type="text" id="plSearch" class="pl-input" placeholder="氏名・社員コードで検索" style="width:220px;">
    <select id="plFilterStatus" class="pl-select">
        <option value="1">在籍中</option>
        <option value="">全員</option>
        <option value="0">退職者</option>
    </select>
    <span class="pl-filter-result" id="plFilterResult"></span>
</div>

<div class="pl-card" style="padding:0;">
<div class="pl-table-wrap">
<table class="pl-data-table" id="plListTable">
    <thead>
        <tr>
            <th>社員コード</th>
            <th>氏名</th>
            <th>入社日</th>
            <th>雇用区分</th>
            <th>週勤務日数</th>
            <th>残有給日数</th>
            <th>今年の消化</th>
            <th>失効予告</th>
            <th>有給申請</th><!-- ★ 追加 -->
            <th>操作</th>
        </tr>
    </thead>
    <tbody id="plListTbody">
        <tr><td colspan="10" class="pl-empty pl-loading">読み込み中...</td></tr>
    </tbody>
</table>
</div>
</div>

</div>
