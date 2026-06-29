<?php
/**
 * 有給 CSVインポート画面（付与 / 消化 タブ）
 *
 * 各タブとも 2段階:
 *   1. CSVを選択 →「内容を確認（プレビュー）」
 *   2.「この内容で取込む」→ DB登録
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$pl_import_nonce = wp_create_nonce( 'pl_import_nonce' );
$pl_ajax_url     = admin_url( 'admin-ajax.php' );
$pl_exp_years    = (int) PL_Rules::get_setting( 'expiration_years', 2 );
if ( $pl_exp_years <= 0 ) $pl_exp_years = 2;
?>
<div class="wrap pl-wrap" id="pl-import-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-upload"></span> 有給 CSVインポート</h1>

<!-- タブ -->
<div class="pl-import-tabs" style="display:flex; gap:8px; margin:12px 0 16px; border-bottom:2px solid #dcdcde;">
    <button class="pl-tab-btn is-active" data-tab="grant"
        style="border:none; background:none; padding:10px 18px; font-size:14px; font-weight:600; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px;">
        付与インポート
    </button>
    <button class="pl-tab-btn" data-tab="consume"
        style="border:none; background:none; padding:10px 18px; font-size:14px; font-weight:600; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px;">
        消化インポート
    </button>
</div>

<!-- ============================ 付与タブ ============================ -->
<div class="pl-tab-panel" data-panel="grant">

    <div class="pl-card">
        <div class="pl-card-title">取込仕様（付与）</div>
        <ul style="margin:0; padding-left:20px; font-size:13px; line-height:1.9;">
            <li>CSVの列順は <strong>社員番号 / 有給発生日 / 付与日数</strong>（末尾の空カラム・空行は無視）</li>
            <li>文字コードは <strong>UTF-8（BOM付き可）</strong>、日付は <strong>YYYY-MM-DD</strong></li>
            <li>失効日は <strong>「付与日 ＋ <?php echo esc_html( $pl_exp_years ); ?>年」で自動計算</strong></li>
            <li>失効日が今日を過ぎた付与は <strong>失効（残日数0）</strong>、有効な付与は <strong>残日数＝付与日数（満額）</strong>で取込</li>
            <li>社員マスタに無い社員番号はエラー、同じ「社員番号＋付与日」が既存なら重複スキップ</li>
        </ul>
    </div>

    <div class="pl-card">
        <div class="pl-card-title">STEP 1 ＿ 付与CSVを選択</div>
        <div class="pl-field" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <input type="file" id="grantFile" accept=".csv,.txt" class="pl-input" style="max-width:420px;">
            <button id="grantPreviewBtn" class="pl-btn pl-btn-primary">内容を確認（プレビュー）</button>
            <span id="grantLoading" style="display:none; color:#555;">解析中…</span>
        </div>
        <p class="pl-hint" style="margin-top:8px;">※ この時点ではデータベースには登録されません。</p>
    </div>

    <div id="grantFatal" class="pl-notice pl-notice-warning" style="display:none;"></div>

    <div id="grantPreviewWrap" style="display:none;">
        <div class="pl-card">
            <div class="pl-card-title">STEP 2 ＿ 取込内容の確認</div>
            <div id="grantSummary" class="pl-info-grid" style="grid-template-columns:auto 1fr; max-width:520px; margin-bottom:8px;"></div>

            <details style="margin:8px 0;">
                <summary style="cursor:pointer; font-weight:600;">取込対象データ（先頭20件）を確認</summary>
                <div class="pl-table-wrap" style="margin-top:8px;">
                    <table class="pl-data-table">
                        <thead><tr><th>社員番号</th><th>氏名</th><th>付与日</th><th>付与日数</th><th>失効日</th><th>状態</th><th>残日数</th></tr></thead>
                        <tbody id="grantSampleBody"></tbody>
                    </table>
                </div>
            </details>

            <div id="grantErrorBox" style="display:none; margin-top:12px;">
                <div style="font-weight:600; color:#b32d2e; margin-bottom:6px;">⚠ 取込まれないエラー行 <span id="grantErrorCount"></span></div>
                <div class="pl-table-wrap" style="max-height:240px;">
                    <table class="pl-data-table">
                        <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                        <tbody id="grantErrorBody"></tbody>
                    </table>
                </div>
            </div>

            <div id="grantDupBox" style="display:none; margin-top:12px;">
                <div style="font-weight:600; color:#996800; margin-bottom:6px;">既に登録済み（スキップ）<span id="grantDupCount"></span></div>
                <div class="pl-table-wrap" style="max-height:200px;">
                    <table class="pl-data-table">
                        <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                        <tbody id="grantDupBody"></tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px; display:flex; align-items:center; gap:12px;">
                <button id="grantExecuteBtn" class="pl-btn pl-btn-primary">この内容で取込む</button>
                <button id="grantCancelBtn" class="pl-btn pl-btn-secondary">やり直す</button>
                <span id="grantExecLoading" style="display:none; color:#555;">登録中…</span>
            </div>
        </div>
    </div>

    <div id="grantResult" class="pl-notice pl-notice-success" style="display:none;"></div>
</div>

<!-- ============================ 消化タブ ============================ -->
<div class="pl-tab-panel" data-panel="consume" style="display:none;">

    <div class="pl-card">
        <div class="pl-card-title">取込仕様（消化）</div>
        <ul style="margin:0; padding-left:20px; font-size:13px; line-height:1.9;">
            <li>CSVの列順は <strong>社員番号 / 消化日</strong>（全休=1日固定。末尾の空カラム・空行は無視）</li>
            <li>文字コードは <strong>UTF-8（BOM付き可）</strong>、日付は <strong>YYYY-MM-DD</strong></li>
            <li>充当は <strong>消化日基準FIFO</strong>（消化日に有効だった付与を、付与日の古い順に充当）</li>
            <li>失効済みの付与にも当時の消化として記録します（残日数は据え置き）。有効な付与は残日数を減算</li>
            <li>充当先が無い／残不足の行はエラー、同じ「社員番号＋消化日」が既存なら重複スキップ</li>
            <li><strong>付与インポートを先に完了</strong>させてから実行してください</li>
        </ul>
    </div>

    <div class="pl-card">
        <div class="pl-card-title">STEP 1 ＿ 消化CSVを選択</div>
        <div class="pl-field" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <input type="file" id="consumeFile" accept=".csv,.txt" class="pl-input" style="max-width:420px;">
            <button id="consumePreviewBtn" class="pl-btn pl-btn-primary">内容を確認（プレビュー）</button>
            <span id="consumeLoading" style="display:none; color:#555;">解析中…</span>
        </div>
        <p class="pl-hint" style="margin-top:8px;">※ この時点ではデータベースには登録されません。</p>
    </div>

    <div id="consumeFatal" class="pl-notice pl-notice-warning" style="display:none;"></div>

    <div id="consumePreviewWrap" style="display:none;">
        <div class="pl-card">
            <div class="pl-card-title">STEP 2 ＿ 取込内容の確認</div>
            <div id="consumeSummary" class="pl-info-grid" style="grid-template-columns:auto 1fr; max-width:520px; margin-bottom:8px;"></div>

            <details style="margin:8px 0;">
                <summary style="cursor:pointer; font-weight:600;">取込対象データ（先頭20件）を確認</summary>
                <div class="pl-table-wrap" style="margin-top:8px;">
                    <table class="pl-data-table">
                        <thead><tr><th>社員番号</th><th>氏名</th><th>消化日</th><th>日数</th><th>充当先（付与日）</th><th>状態</th></tr></thead>
                        <tbody id="consumeSampleBody"></tbody>
                    </table>
                </div>
            </details>

            <div id="consumeErrorBox" style="display:none; margin-top:12px;">
                <div style="font-weight:600; color:#b32d2e; margin-bottom:6px;">⚠ 取込まれないエラー行 <span id="consumeErrorCount"></span></div>
                <div class="pl-table-wrap" style="max-height:240px;">
                    <table class="pl-data-table">
                        <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                        <tbody id="consumeErrorBody"></tbody>
                    </table>
                </div>
            </div>

            <div id="consumeDupBox" style="display:none; margin-top:12px;">
                <div style="font-weight:600; color:#996800; margin-bottom:6px;">既に登録済み（スキップ）<span id="consumeDupCount"></span></div>
                <div class="pl-table-wrap" style="max-height:200px;">
                    <table class="pl-data-table">
                        <thead><tr><th style="width:80px;">行番号</th><th style="width:120px;">社員番号</th><th>理由</th></tr></thead>
                        <tbody id="consumeDupBody"></tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px; display:flex; align-items:center; gap:12px;">
                <button id="consumeExecuteBtn" class="pl-btn pl-btn-primary">この内容で取込む</button>
                <button id="consumeCancelBtn" class="pl-btn pl-btn-secondary">やり直す</button>
                <span id="consumeExecLoading" style="display:none; color:#555;">登録中…</span>
            </div>
        </div>
    </div>

    <div id="consumeResult" class="pl-notice pl-notice-success" style="display:none;"></div>
</div>

</div><!-- /.wrap -->

<script>
(function($){
    var PL_IMPORT = {
        ajaxUrl: <?php echo wp_json_encode( $pl_ajax_url ); ?>,
        nonce:   <?php echo wp_json_encode( $pl_import_nonce ); ?>
    };

    function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }

    // ---- タブ切替 ----
    $('.pl-tab-btn').on('click', function(){
        var tab = $(this).data('tab');
        $('.pl-tab-btn').removeClass('is-active').css({'border-bottom-color':'transparent','color':''});
        $(this).addClass('is-active').css({'border-bottom-color':'#2271b1','color':'#2271b1'});
        $('.pl-tab-panel').hide();
        $('.pl-tab-panel[data-panel="' + tab + '"]').show();
    });
    // 初期アクティブ表示
    $('.pl-tab-btn.is-active').css({'border-bottom-color':'#2271b1','color':'#2271b1'});

    // =====================================================
    //  汎用エンジン（付与・消化で共通のアップロード→プレビュー→実行）
    // =====================================================
    function setupImporter(cfg){
        function reset(){
            $(cfg.previewWrap).hide();
            $(cfg.fatal).hide().text('');
            $(cfg.result).hide().html('');
        }

        // プレビュー
        $(cfg.previewBtn).on('click', function(){
            var fileInput = $(cfg.file)[0];
            if (!fileInput.files || !fileInput.files.length) { alert('CSVファイルを選択してください。'); return; }
            reset();
            $(cfg.loading).show();
            $(cfg.previewBtn).prop('disabled', true);

            var fd = new FormData();
            fd.append('action', cfg.previewAction);
            fd.append('nonce', PL_IMPORT.nonce);
            fd.append('csv_file', fileInput.files[0]);

            $.ajax({ url: PL_IMPORT.ajaxUrl, type:'POST', data:fd, processData:false, contentType:false })
            .done(function(res){
                if (!res || !res.success) {
                    $(cfg.fatal).show().text((res && res.data && res.data.message) ? res.data.message : '解析に失敗しました。');
                    return;
                }
                cfg.render(res.data);
            })
            .fail(function(){ $(cfg.fatal).show().text('通信エラーが発生しました。ファイルサイズやサーバー設定をご確認ください。'); })
            .always(function(){ $(cfg.loading).hide(); $(cfg.previewBtn).prop('disabled', false); });
        });

        // やり直す
        $(cfg.cancelBtn).on('click', function(){ reset(); $(cfg.file).val(''); });

        // 本実行
        $(cfg.executeBtn).on('click', function(){
            if (!confirm('プレビューに表示された取込対象を登録します。よろしいですか？')) return;
            $(cfg.execLoading).show();
            $(cfg.executeBtn).prop('disabled', true);
            $(cfg.cancelBtn).prop('disabled', true);

            $.post(PL_IMPORT.ajaxUrl, { action: cfg.executeAction, nonce: PL_IMPORT.nonce })
            .done(function(res){
                if (!res || !res.success) {
                    $(cfg.fatal).show().text((res && res.data && res.data.message) ? res.data.message : '登録に失敗しました。');
                    return;
                }
                $(cfg.result).show().html(cfg.resultHtml(res.data));
                $(cfg.previewWrap).hide();
                $(cfg.file).val('');
            })
            .fail(function(){ $(cfg.fatal).show().text('通信エラーが発生しました。'); })
            .always(function(){
                $(cfg.execLoading).hide();
                $(cfg.executeBtn).prop('disabled', false);
                $(cfg.cancelBtn).prop('disabled', false);
            });
        });
    }

    // 共通: エラー/重複明細の描画
    function renderDetail(list, count, totalShownLabel, $countEl, $body, $box){
        if (count > 0) {
            var html = '';
            (list || []).forEach(function(e){
                html += '<tr><td>' + esc(e.line) + '</td><td>' + esc(e.code) + '</td><td>' + esc(e.reason) + '</td></tr>';
            });
            var more = count > (list || []).length ? '（先頭 ' + (list||[]).length + ' 件を表示）' : '';
            $countEl.text('（' + count + ' 件）' + more);
            $body.html(html);
            $box.show();
        } else { $box.hide(); }
    }

    // =====================================================
    //  付与インポーター
    // =====================================================
    setupImporter({
        file:'#grantFile', previewBtn:'#grantPreviewBtn', loading:'#grantLoading',
        fatal:'#grantFatal', previewWrap:'#grantPreviewWrap',
        executeBtn:'#grantExecuteBtn', cancelBtn:'#grantCancelBtn', execLoading:'#grantExecLoading',
        result:'#grantResult',
        previewAction:'pl_import_preview', executeAction:'pl_import_execute',
        render: function(d){
            var s = d.summary;
            var rows = [
                ['データ行（社員番号あり）', s.data_rows + ' 行'],
                ['空行スキップ', s.blank_skipped + ' 行'],
                ['取込対象', '<strong>' + s.valid + ' 件</strong>（有効 ' + s.valid_active + ' ／ 失効 ' + s.valid_expired + '）'],
                ['エラー（取込不可）', (s.error > 0 ? '<span style="color:#b32d2e;font-weight:600;">' + s.error + ' 件</span>' : '0 件')],
                ['重複（スキップ）', (s.dup > 0 ? '<span style="color:#996800;">' + s.dup + ' 件</span>' : '0 件')]
            ];
            var html = '';
            rows.forEach(function(r){ html += '<span class="pl-info-key">' + r[0] + '</span><span class="pl-info-val">' + r[1] + '</span>'; });
            $('#grantSummary').html(html);

            var sb = '';
            (d.sample || []).forEach(function(r){
                sb += '<tr><td>' + esc(r.employee_code) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.grant_date) + '</td>'
                   + '<td>' + esc(r.granted_days) + ' 日</td><td>' + esc(r.expiry_date) + '</td>'
                   + '<td>' + (r.is_expired ? '<span class="pl-badge pl-badge-red">失効</span>' : '<span class="pl-badge pl-badge-green">有効</span>') + '</td>'
                   + '<td>' + esc(r.remaining_days) + ' 日</td></tr>';
            });
            $('#grantSampleBody').html(sb || '<tr><td colspan="7" class="pl-empty">なし</td></tr>');

            renderDetail(d.errors, s.error, null, $('#grantErrorCount'), $('#grantErrorBody'), $('#grantErrorBox'));
            renderDetail(d.dups,  s.dup,   null, $('#grantDupCount'),  $('#grantDupBody'),  $('#grantDupBox'));

            $('#grantExecuteBtn').prop('disabled', !d.can_import);
            $('#grantPreviewWrap').show();
        },
        resultHtml: function(d){
            var msg = '✅ ' + d.message + '（有効 ' + d.valid_cnt + ' 件 ／ 失効 ' + d.expired_cnt + ' 件）';
            if (d.failed_count > 0) msg += '　※ 登録失敗 ' + d.failed_count + ' 件';
            return msg;
        }
    });

    // =====================================================
    //  消化インポーター
    // =====================================================
    setupImporter({
        file:'#consumeFile', previewBtn:'#consumePreviewBtn', loading:'#consumeLoading',
        fatal:'#consumeFatal', previewWrap:'#consumePreviewWrap',
        executeBtn:'#consumeExecuteBtn', cancelBtn:'#consumeCancelBtn', execLoading:'#consumeExecLoading',
        result:'#consumeResult',
        previewAction:'pl_consume_import_preview', executeAction:'pl_consume_import_execute',
        render: function(d){
            var s = d.summary;
            var rows = [
                ['データ行（社員番号あり）', s.data_rows + ' 行'],
                ['空行スキップ', s.blank_skipped + ' 行'],
                ['取込対象', '<strong>' + s.valid + ' 件</strong>'],
                ['エラー（取込不可）', (s.error > 0 ? '<span style="color:#b32d2e;font-weight:600;">' + s.error + ' 件</span>' : '0 件')],
                ['重複（スキップ）', (s.dup > 0 ? '<span style="color:#996800;">' + s.dup + ' 件</span>' : '0 件')]
            ];
            var html = '';
            rows.forEach(function(r){ html += '<span class="pl-info-key">' + r[0] + '</span><span class="pl-info-val">' + r[1] + '</span>'; });
            $('#consumeSummary').html(html);

            var sb = '';
            (d.sample || []).forEach(function(r){
                sb += '<tr><td>' + esc(r.employee_code) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.consumed_date) + '</td>'
                   + '<td>' + esc(r.consumed_days) + ' 日</td><td>' + esc(r.target_grant) + '</td>'
                   + '<td>' + (r.target_expired ? '<span class="pl-badge pl-badge-red">失効分</span>' : '<span class="pl-badge pl-badge-green">有効分</span>') + '</td></tr>';
            });
            $('#consumeSampleBody').html(sb || '<tr><td colspan="6" class="pl-empty">なし</td></tr>');

            renderDetail(d.errors, s.error, null, $('#consumeErrorCount'), $('#consumeErrorBody'), $('#consumeErrorBox'));
            renderDetail(d.dups,  s.dup,   null, $('#consumeDupCount'),  $('#consumeDupBody'),  $('#consumeDupBox'));

            $('#consumeExecuteBtn').prop('disabled', !d.can_import);
            $('#consumePreviewWrap').show();
        },
        resultHtml: function(d){
            var msg = '✅ ' + d.message + '（有効付与へ ' + d.to_valid + ' 日 ／ 失効付与へ ' + d.to_expired + ' 日）';
            if (d.failed_count > 0) msg += '　※ 充当失敗 ' + d.failed_count + ' 件';
            return msg;
        }
    });

})(jQuery);
</script>