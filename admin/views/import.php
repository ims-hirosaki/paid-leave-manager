<?php
/**
 * 有給付与履歴 CSVインポート画面
 *
 * 2段階:
 *   1. CSVを選択 →「内容を確認（プレビュー）」… 取込予定/エラー/重複を表示
 *   2.「この内容で取込む」… DBへ登録
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$pl_import_nonce = wp_create_nonce( 'pl_import_nonce' );
$pl_ajax_url     = admin_url( 'admin-ajax.php' );
$pl_exp_years    = (int) PL_Rules::get_setting( 'expiration_years', 2 );
if ( $pl_exp_years <= 0 ) $pl_exp_years = 2;
?>
<div class="wrap pl-wrap" id="pl-import-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-upload"></span> 有給付与履歴 CSVインポート</h1>

<!-- 説明 -->
<div class="pl-card">
    <div class="pl-card-title">取込仕様</div>
    <ul style="margin:0; padding-left:20px; font-size:13px; line-height:1.9;">
        <li>CSVの列順は <strong>社員番号 / 有給発生日 / 付与日数</strong>（末尾に余分な空カラムや空行があっても無視します）</li>
        <li>文字コードは <strong>UTF-8（BOM付き可）</strong></li>
        <li>失効日は CSV からは取らず <strong>「付与日 ＋ <?php echo esc_html( $pl_exp_years ); ?>年」で自動計算</strong>します</li>
        <li>失効日が今日を過ぎている付与は <strong>失効（残日数0）</strong>として、有効な付与は <strong>残日数＝付与日数（満額）</strong>で取込みます</li>
        <li>社員マスタに存在しない社員番号の行は <strong>取込まずエラー</strong>として一覧表示します</li>
        <li>同じ「社員番号＋付与日」が既に登録済みの行は <strong>重複としてスキップ</strong>します（再取込しても二重登録されません）</li>
        <li>消化（取得済み）データは今回の取込対象外です</li>
    </ul>
</div>

<!-- STEP1: ファイル選択 -->
<div class="pl-card">
    <div class="pl-card-title">STEP 1 ＿ CSVファイルを選択</div>
    <div class="pl-field" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <input type="file" id="plImportFile" accept=".csv,.txt" class="pl-input" style="max-width:420px;">
        <button id="plImportPreviewBtn" class="pl-btn pl-btn-primary">内容を確認（プレビュー）</button>
        <span id="plImportLoading" style="display:none; color:#555;">解析中…</span>
    </div>
    <p class="pl-hint" id="plImportFileHint" style="margin-top:8px;">※ この時点ではデータベースには登録されません。</p>
</div>

<!-- 解析エラー（致命的） -->
<div id="plImportFatal" class="pl-notice pl-notice-warning" style="display:none;"></div>

<!-- STEP2: プレビュー結果 -->
<div id="plImportPreviewWrap" style="display:none;">
    <div class="pl-card">
        <div class="pl-card-title">STEP 2 ＿ 取込内容の確認</div>

        <!-- 集計サマリー -->
        <div id="plImportSummary" class="pl-info-grid" style="grid-template-columns:auto 1fr; max-width:520px; margin-bottom:8px;"></div>

        <!-- 取込対象プレビュー（先頭20件） -->
        <details id="plImportSampleBox" style="margin:8px 0;">
            <summary style="cursor:pointer; font-weight:600;">取込対象データ（先頭20件）を確認</summary>
            <div class="pl-table-wrap" style="margin-top:8px;">
                <table class="pl-data-table">
                    <thead><tr>
                        <th>社員番号</th><th>氏名</th><th>付与日</th><th>付与日数</th>
                        <th>失効日</th><th>状態</th><th>残日数</th>
                    </tr></thead>
                    <tbody id="plImportSampleBody"></tbody>
                </table>
            </div>
        </details>

        <!-- エラー明細 -->
        <div id="plImportErrorBox" style="display:none; margin-top:12px;">
            <div style="font-weight:600; color:#b32d2e; margin-bottom:6px;">⚠ 取込まれないエラー行 <span id="plImportErrorCount"></span></div>
            <div class="pl-table-wrap" style="max-height:240px;">
                <table class="pl-data-table">
                    <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                    <tbody id="plImportErrorBody"></tbody>
                </table>
            </div>
        </div>

        <!-- 重複明細 -->
        <div id="plImportDupBox" style="display:none; margin-top:12px;">
            <div style="font-weight:600; color:#996800; margin-bottom:6px;">既に登録済み（スキップ）<span id="plImportDupCount"></span></div>
            <div class="pl-table-wrap" style="max-height:200px;">
                <table class="pl-data-table">
                    <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                    <tbody id="plImportDupBody"></tbody>
                </table>
            </div>
        </div>

        <!-- 実行ボタン -->
        <div style="margin-top:16px; display:flex; align-items:center; gap:12px;">
            <button id="plImportExecuteBtn" class="pl-btn pl-btn-primary">この内容で取込む</button>
            <button id="plImportCancelBtn" class="pl-btn pl-btn-secondary">やり直す</button>
            <span id="plImportExecLoading" style="display:none; color:#555;">登録中…</span>
        </div>
    </div>
</div>

<!-- 完了結果 -->
<div id="plImportResult" class="pl-notice pl-notice-success" style="display:none;"></div>

</div>

<script>
(function($){
    var PL_IMPORT = {
        ajaxUrl: <?php echo wp_json_encode( $pl_ajax_url ); ?>,
        nonce:   <?php echo wp_json_encode( $pl_import_nonce ); ?>
    };

    function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

    function resetPreview(){
        $('#plImportPreviewWrap').hide();
        $('#plImportFatal').hide().text('');
        $('#plImportResult').hide().text('');
    }

    // --- STEP1: プレビュー ---
    $('#plImportPreviewBtn').on('click', function(){
        var fileInput = $('#plImportFile')[0];
        if ( ! fileInput.files || ! fileInput.files.length ) {
            alert('CSVファイルを選択してください。');
            return;
        }
        resetPreview();
        $('#plImportLoading').show();
        $('#plImportPreviewBtn').prop('disabled', true);

        var fd = new FormData();
        fd.append('action', 'pl_import_preview');
        fd.append('nonce', PL_IMPORT.nonce);
        fd.append('csv_file', fileInput.files[0]);

        $.ajax({
            url: PL_IMPORT.ajaxUrl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false
        }).done(function(res){
            if ( ! res || ! res.success ) {
                $('#plImportFatal').show().text( (res && res.data && res.data.message) ? res.data.message : '解析に失敗しました。' );
                return;
            }
            renderPreview(res.data);
        }).fail(function(){
            $('#plImportFatal').show().text('通信エラーが発生しました。ファイルサイズやサーバー設定をご確認ください。');
        }).always(function(){
            $('#plImportLoading').hide();
            $('#plImportPreviewBtn').prop('disabled', false);
        });
    });

    function renderPreview(d){
        var s = d.summary;

        // サマリー
        var rows = [
            ['データ行（社員番号あり）', s.data_rows + ' 行'],
            ['空行スキップ', s.blank_skipped + ' 行'],
            ['取込対象', '<strong>' + s.valid + ' 件</strong>（有効 ' + s.valid_active + ' ／ 失効 ' + s.valid_expired + '）'],
            ['エラー（取込不可）', (s.error > 0 ? '<span style="color:#b32d2e;font-weight:600;">' + s.error + ' 件</span>' : '0 件')],
            ['重複（スキップ）', (s.dup > 0 ? '<span style="color:#996800;">' + s.dup + ' 件</span>' : '0 件')]
        ];
        var html = '';
        rows.forEach(function(r){
            html += '<span class="pl-info-key">' + r[0] + '</span><span class="pl-info-val">' + r[1] + '</span>';
        });
        $('#plImportSummary').html(html);

        // サンプル
        var sb = '';
        (d.sample || []).forEach(function(r){
            sb += '<tr>'
               + '<td>' + esc(r.employee_code) + '</td>'
               + '<td>' + esc(r.name) + '</td>'
               + '<td>' + esc(r.grant_date) + '</td>'
               + '<td>' + esc(r.granted_days) + ' 日</td>'
               + '<td>' + esc(r.expiry_date) + '</td>'
               + '<td>' + ( r.is_expired
                          ? '<span class="pl-badge pl-badge-red">失効</span>'
                          : '<span class="pl-badge pl-badge-green">有効</span>' ) + '</td>'
               + '<td>' + esc(r.remaining_days) + ' 日</td>'
               + '</tr>';
        });
        $('#plImportSampleBody').html(sb || '<tr><td colspan="7" class="pl-empty">なし</td></tr>');

        // エラー
        if ( s.error > 0 ) {
            var eb = '';
            (d.errors || []).forEach(function(e){
                eb += '<tr><td>' + esc(e.line) + '</td><td>' + esc(e.code) + '</td><td>' + esc(e.reason) + '</td></tr>';
            });
            var moreE = s.error > (d.errors || []).length ? '（先頭 ' + (d.errors||[]).length + ' 件を表示）' : '';
            $('#plImportErrorCount').text('（' + s.error + ' 件）' + moreE);
            $('#plImportErrorBody').html(eb);
            $('#plImportErrorBox').show();
        } else {
            $('#plImportErrorBox').hide();
        }

        // 重複
        if ( s.dup > 0 ) {
            var db = '';
            (d.dups || []).forEach(function(e){
                db += '<tr><td>' + esc(e.line) + '</td><td>' + esc(e.code) + '</td><td>' + esc(e.reason) + '</td></tr>';
            });
            $('#plImportDupCount').text('（' + s.dup + ' 件）');
            $('#plImportDupBody').html(db);
            $('#plImportDupBox').show();
        } else {
            $('#plImportDupBox').hide();
        }

        // 実行ボタンの可否
        $('#plImportExecuteBtn').prop('disabled', ! d.can_import);
        $('#plImportPreviewWrap').show();
    }

    // --- やり直す ---
    $('#plImportCancelBtn').on('click', function(){
        resetPreview();
        $('#plImportFile').val('');
    });

    // --- STEP2: 本実行 ---
    $('#plImportExecuteBtn').on('click', function(){
        var s = $('#plImportSummary').data('summary');
        if ( ! confirm('プレビューに表示された取込対象を登録します。よろしいですか？') ) return;

        $('#plImportExecLoading').show();
        $('#plImportExecuteBtn').prop('disabled', true);
        $('#plImportCancelBtn').prop('disabled', true);

        $.post(PL_IMPORT.ajaxUrl, {
            action: 'pl_import_execute',
            nonce:  PL_IMPORT.nonce
        }).done(function(res){
            if ( ! res || ! res.success ) {
                $('#plImportFatal').show().text( (res && res.data && res.data.message) ? res.data.message : '登録に失敗しました。' );
                return;
            }
            var d = res.data;
            var msg = '✅ ' + d.message
                    + '（有効 ' + d.valid_cnt + ' 件 ／ 失効 ' + d.expired_cnt + ' 件）';
            if ( d.failed_count > 0 ) {
                msg += '　※ 登録失敗 ' + d.failed_count + ' 件';
            }
            $('#plImportResult').show().html(msg);
            $('#plImportPreviewWrap').hide();
            $('#plImportFile').val('');
        }).fail(function(){
            $('#plImportFatal').show().text('通信エラーが発生しました。');
        }).always(function(){
            $('#plImportExecLoading').hide();
            $('#plImportExecuteBtn').prop('disabled', false);
            $('#plImportCancelBtn').prop('disabled', false);
        });
    });

})(jQuery);
</script>
