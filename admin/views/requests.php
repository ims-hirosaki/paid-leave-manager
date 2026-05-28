<?php if ( ! defined('ABSPATH') ) exit; ?>

<div class="wrap pl-wrap" id="pl-requests-page">
<h1 class="pl-page-title">
    <span class="dashicons dashicons-bell"></span> 有給申請管理
</h1>

<!-- フィルター -->
<div class="pl-card">
    <div class="pl-card-title">絞り込み</div>
    <div class="pl-search-row" style="flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label class="pl-label" style="margin:0; white-space:nowrap;">状態</label>
            <select id="plReqStatus" class="pl-input" style="width:130px;">
                <option value="">すべて</option>
                <option value="pending"  selected>申請中のみ</option>
                <option value="approved">受理済み</option>
                <option value="rejected">却下済み</option>
            </select>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <label class="pl-label" style="margin:0; white-space:nowrap;">希望日</label>
            <input type="date" id="plReqFrom" class="pl-input" style="width:150px;">
            <span>〜</span>
            <input type="date" id="plReqTo" class="pl-input" style="width:150px;">
        </div>
        <button id="plReqSearch" class="pl-btn pl-btn-primary">
            <span class="dashicons dashicons-search" style="vertical-align:middle;"></span> 検索
        </button>
        <span id="plReqCount" class="pl-badge" style="display:none;"></span>
    </div>
</div>

<!-- 申請一覧テーブル -->
<div class="pl-card">
    <div class="pl-card-title">申請一覧</div>
    <div class="pl-table-wrap">
        <table class="pl-data-table" id="plReqTable">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>社員コード</th>
                    <th>氏名</th>
                    <th>希望日</th>
                    <th>申請日時</th>
                    <th>状態</th>
                    <th>申請備考</th>
                    <th>管理者コメント</th>
                    <th style="width:170px;">操作</th>
                </tr>
            </thead>
            <tbody id="plReqTbody">
                <tr><td colspan="9" class="pl-empty pl-loading">読み込み中...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 受理・却下モーダル -->
<div id="plReqModal" class="pl-modal-overlay" style="display:none;">
    <div class="pl-modal-box">
        <h3 id="plReqModalTitle" class="pl-modal-title"></h3>
        <div class="pl-modal-body">
            <div class="pl-info-grid" id="plReqModalInfo"></div>
            <div class="pl-field" style="margin-top:16px;">
                <label class="pl-label">管理者コメント（任意）</label>
                <textarea id="plReqAdminNote" class="pl-input" rows="3"
                    placeholder="受理/却下の理由など"></textarea>
            </div>
            <div id="plReqAutoConsumeRow" class="pl-field" style="margin-top:12px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" id="plReqAutoConsume" checked>
                    <span>受理と同時に消化登録する（1日）</span>
                </label>
                <p class="pl-hint" style="margin-left:24px; margin-top:4px;">
                    残日数が不足の場合は消化登録がスキップされます。付与・消化登録ページから手動登録してください。
                </p>
            </div>
        </div>
        <div class="pl-modal-footer">
            <button id="plReqModalConfirm" class="pl-btn pl-btn-primary">確定</button>
            <button id="plReqModalCancel" class="pl-btn pl-btn-secondary">キャンセル</button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce      = '<?php echo wp_create_nonce("pl_request_nonce"); ?>';
    var ajaxurl    = ajaxurl || '<?php echo admin_url("admin-ajax.php"); ?>';
    var currentAction = '';   // 'approve' or 'reject'
    var currentId     = 0;

    // =========================================================
    //  初期ロード・検索
    // =========================================================
    loadRequests();

    $('#plReqSearch').on('click', function() { loadRequests(); });

    function loadRequests() {
        $('#plReqTbody').html('<tr><td colspan="9" class="pl-empty pl-loading">読み込み中...</td></tr>');
        $('#plReqCount').hide();

        $.post(ajaxurl, {
            action   : 'pl_request_get_list',
            nonce    : nonce,
            status   : $('#plReqStatus').val(),
            date_from: $('#plReqFrom').val(),
            date_to  : $('#plReqTo').val(),
        }, function(res) {
            if (!res.success) {
                $('#plReqTbody').html('<tr><td colspan="9" class="pl-empty">取得に失敗しました。</td></tr>');
                return;
            }
            renderRows(res.data.rows);
            var total = res.data.total || 0;
            if (total > 0) {
                $('#plReqCount').text(total + ' 件').show();
            }
        });
    }

    // =========================================================
    //  テーブル描画
    // =========================================================
    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            $('#plReqTbody').html('<tr><td colspan="9" class="pl-empty">申請はありません。</td></tr>');
            return;
        }

        var statusLabel = {
            'pending' : '<span class="pl-badge pl-badge-warning">申請中</span>',
            'approved': '<span class="pl-badge pl-badge-success">受理済み</span>',
            'rejected': '<span class="pl-badge pl-badge-danger">却下済み</span>',
        };

        var html = '';
        $.each(rows, function(_, r) {
            var isPending  = (r.status === 'pending');
            var isApproved = (r.status === 'approved');

            html += '<tr>';
            html += '<td>' + esc(r.id) + '</td>';
            html += '<td>' + esc(r.employee_code) + '</td>';
            html += '<td>' + esc(r.employee_name) + '</td>';
            html += '<td>' + esc(r.request_date) + '</td>';
            html += '<td style="font-size:.85em;color:#64748b;">' + esc(r.created_at) + '</td>';
            html += '<td>' + (statusLabel[r.status] || esc(r.status_label)) + '</td>';
            html += '<td style="font-size:.85em;">' + esc(r.note) + '</td>';
            html += '<td style="font-size:.85em;">' + esc(r.admin_note) + '</td>';
            html += '<td>';
            if (isPending) {
                html += '<button class="pl-btn pl-btn-success pl-btn-sm pl-req-approve"'
                      + ' data-id="' + r.id + '"'
                      + ' data-code="' + esc(r.employee_code) + '"'
                      + ' data-name="' + esc(r.employee_name) + '"'
                      + ' data-date="' + esc(r.request_date) + '"'
                      + ' style="margin-right:6px;">受理</button>';
                html += '<button class="pl-btn pl-btn-danger pl-btn-sm pl-req-reject"'
                      + ' data-id="' + r.id + '"'
                      + ' data-code="' + esc(r.employee_code) + '"'
                      + ' data-name="' + esc(r.employee_name) + '"'
                      + ' data-date="' + esc(r.request_date) + '"'
                      + '>却下</button>';
            } else {
                html += '<span style="color:#94a3b8;font-size:.85em;">処理済み</span>';
            }
            html += '</td>';
            html += '</tr>';
        });

        $('#plReqTbody').html(html);
    }

    // =========================================================
    //  受理ボタン
    // =========================================================
    $(document).on('click', '.pl-req-approve', function() {
        currentAction = 'approve';
        currentId     = $(this).data('id');
        openModal(
            '受理の確認',
            $(this).data('code'),
            $(this).data('name'),
            $(this).data('date'),
            true   // autoConsume行を表示
        );
    });

    // =========================================================
    //  却下ボタン
    // =========================================================
    $(document).on('click', '.pl-req-reject', function() {
        currentAction = 'reject';
        currentId     = $(this).data('id');
        openModal(
            '却下の確認',
            $(this).data('code'),
            $(this).data('name'),
            $(this).data('date'),
            false  // autoConsume行を非表示
        );
    });

    // =========================================================
    //  モーダル
    // =========================================================
    function openModal(title, code, name, date, showAutoConsume) {
        $('#plReqModalTitle').text(title);
        $('#plReqModalInfo').html(
            '<div><span class="pl-info-label">社員コード</span><span>' + esc(code) + '</span></div>'
            + '<div><span class="pl-info-label">氏名</span><span>' + esc(name) + '</span></div>'
            + '<div><span class="pl-info-label">希望日</span><span>' + esc(date) + '</span></div>'
        );
        $('#plReqAdminNote').val('');
        $('#plReqAutoConsumeRow').toggle(showAutoConsume);
        $('#plReqAutoConsume').prop('checked', true);
        $('#plReqModalConfirm')
            .removeClass('pl-btn-primary pl-btn-danger')
            .addClass(currentAction === 'approve' ? 'pl-btn-primary' : 'pl-btn-danger')
            .prop('disabled', false)
            .text('確定');
        $('#plReqModal').fadeIn(150);
    }

    $('#plReqModalCancel').on('click', function() {
        $('#plReqModal').fadeOut(150);
    });

    $('#plReqModal').on('click', function(e) {
        if ($(e.target).is('#plReqModal')) $(this).fadeOut(150);
    });

    // =========================================================
    //  確定ボタン
    // =========================================================
    $('#plReqModalConfirm').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('処理中...');

        var action    = (currentAction === 'approve') ? 'pl_request_approve' : 'pl_request_reject';
        var adminNote = $('#plReqAdminNote').val();
        var postData  = {
            action     : action,
            nonce      : nonce,
            request_id : currentId,
            admin_note : adminNote,
        };

        if (currentAction === 'approve') {
            postData.auto_consume = $('#plReqAutoConsume').is(':checked') ? '1' : '0';
        }

        $.post(ajaxurl, postData, function(res) {
            $btn.prop('disabled', false).text('確定');
            if (res.success) {
                var msg = res.data.message || (currentAction === 'approve' ? '受理しました。' : '却下しました。');
                alert(msg);
                $('#plReqModal').fadeOut(150);
                loadRequests();
            } else {
                var errMsg = (res.data && res.data.message) ? res.data.message : '処理に失敗しました。';
                alert('エラー: ' + errMsg);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('確定');
            alert('通信エラーが発生しました。');
        });
    });

    // =========================================================
    //  ユーティリティ
    // =========================================================
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
});
</script>
