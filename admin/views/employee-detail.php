<?php if ( ! defined('ABSPATH') ) exit;
if ( ! function_exists( 'pl_format_tenure' ) ) {
    /** 勤続月数(int) を「〇年〇ヶ月」表記に変換 */
    function pl_format_tenure( $months ) {
        $months = (int) $months;
        if ( $months <= 0 ) return '—';
        $y = intdiv( $months, 12 );
        $m = $months % 12;
        if ( $y > 0 && $m > 0 ) return $y . '年' . $m . 'ヶ月';
        if ( $y > 0 )           return $y . '年';
        return $m . 'ヶ月';
    }
}

$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
$emp  = $code ? PL_Employee_Bridge::get_by_code( $code ) : null;

if ( $emp ) {
    $summary      = PL_Grant::get_summary( $code );
    $all_grants   = PL_Grant::get_all_grants( $code );
    $recent       = PL_Grant::get_recent_grants( $code, 3 );
    $consume_logs = PL_Consumption::get_logs( $code );

    $hire_dt      = new DateTime( $emp->hire_date ?? date('Y-m-d') );
    $today_dt     = new DateTime( date('Y-m-d') );
    $diff         = $hire_dt->diff( $today_dt );
    $tenure_label = $diff->y . '年' . $diff->m . 'ヶ月';

    $leave_requests = class_exists('PL_Request')
        ? PL_Request::get_by_employee( $code )
        : array();
    $pending_count = count( array_filter( $leave_requests, fn($r) => $r->status === 'pending' ) );
}
?>
<div class="wrap pl-wrap" id="pl-detail-page">
<h1 class="pl-page-title">
    <span class="dashicons dashicons-id"></span> 個人管理ページ
    <a href="<?php echo esc_url(admin_url('admin.php?page=paid-leave-manager')); ?>" class="pl-back-link">← 従業員一覧に戻る</a>
</h1>

<?php if ( ! $emp ) : ?>
<div class="pl-notice pl-notice-warning">社員が見つかりません。</div>
<?php else : ?>

<!-- 社員基本情報 -->
<div class="pl-card">
    <div class="pl-card-title">社員情報</div>
    <div class="pl-info-grid">
        <span class="pl-info-key">社員コード</span><span class="pl-info-val"><?php echo esc_html($emp->employee_code); ?></span>
        <span class="pl-info-key">氏名</span><span class="pl-info-val"><?php echo esc_html($emp->name); ?></span>
        <span class="pl-info-key">入社日</span><span class="pl-info-val"><?php echo esc_html($emp->hire_date ?? '未登録'); ?></span>
        <span class="pl-info-key">勤続期間</span><span class="pl-info-val"><?php echo esc_html($tenure_label ?? '—'); ?></span>
        <span class="pl-info-key">雇用区分</span><span class="pl-info-val"><?php echo esc_html($emp->employment_type ?? '未登録'); ?></span>
        <span class="pl-info-key">週勤務日数</span><span class="pl-info-val"><?php echo $emp->weekly_work_days ? '週'.(int)$emp->weekly_work_days.'勤務' : '未登録'; ?></span>
    </div>
    <div style="margin-top:1rem;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=pl-grant-register&code='.urlencode($code))); ?>"
            class="pl-btn pl-btn-primary">付与・消化登録へ</a>
    </div>
</div>

<!-- ★ 消化サマリー① 現在有効期間内 -->
<div class="pl-card" id="pl-summary-card-valid">
    <div class="pl-card-title">有給サマリー（現在有効期間内）</div>
    <div class="pl-summary-nums">
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-valid-granted"><?php echo esc_html($summary['valid_granted']); ?></div>
            <div class="pl-num-label">付与日数</div>
        </div>
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-valid-consumed"><?php echo esc_html($summary['valid_consumed']); ?></div>
            <div class="pl-num-label">消化日数（累計）</div>
        </div>
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-valid-remaining"><?php echo esc_html($summary['valid_remaining']); ?></div>
            <div class="pl-num-label">残日数（有効）</div>
        </div>
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-valid-rate"><?php echo esc_html($summary['valid_rate']); ?>%</div>
            <div class="pl-num-label">消化率（累計）</div>
        </div>
    </div>
    <?php if ( $summary['valid_granted'] > 0 ) : ?>
    <div class="pl-bar-wrap" style="margin-top:1rem;">
        <div class="pl-progress-bar pl-progress-bar-lg">
            <?php $vrate = $summary['valid_rate']; ?>
            <div id="sum-progress-fill" class="pl-progress-fill <?php echo $vrate >= 100 ? 'pl-progress-done' : ($vrate >= 80 ? '' : 'pl-progress-warn'); ?>"
                 style="width:<?php echo min(100, $vrate); ?>%"></div>
        </div>
        <div class="pl-bar-rate"><span id="sum-rate-bar"><?php echo esc_html($vrate); ?></span>% 消化済み</div>
    </div>
    <?php endif; ?>
</div>

<!-- ★ 消化サマリー② 今年（付与起算） -->
<div class="pl-card" id="pl-summary-card-year">
    <div class="pl-card-title">
        今年のサマリー（付与起算）
        <?php if ( ! empty($summary['cycle_start']) ) : ?>
        <span style="font-size:12px; font-weight:normal; color:#666; margin-left:8px;">
            対象期間：<?php echo esc_html($summary['cycle_start']); ?> 〜 <?php echo esc_html($summary['cycle_end']); ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ( ! empty($summary['year_alert']) ) : ?>
    <div class="pl-notice pl-notice-warning" id="sum-year-alert" style="margin-bottom:1rem;">
        ⚠️ 年5日の取得義務に対し、今年の消化が <strong><?php echo esc_html($summary['year_consumed']); ?></strong> 日です。期限（<?php echo esc_html($summary['cycle_end']); ?>）まで3か月を切っています。計画的な取得をご案内ください。
    </div>
    <?php else : ?>
    <div class="pl-notice pl-notice-warning" id="sum-year-alert" style="margin-bottom:1rem; display:none;"></div>
    <?php endif; ?>

    <?php if ( empty($summary['cycle_start']) ) : ?>
    <p class="pl-hint" style="margin:0;">付与記録がないため、今年のサマリーは表示できません。</p>
    <?php else : ?>
    <div class="pl-summary-nums">
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-year-granted"><?php echo esc_html($summary['year_granted']); ?></div>
            <div class="pl-num-label">今年の付与</div>
        </div>
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-year-consumed"><?php echo esc_html($summary['year_consumed']); ?></div>
            <div class="pl-num-label">今年の消化</div>
        </div>
        <div class="pl-summary-num">
            <div class="pl-num-val" id="sum-year-rate"><?php echo esc_html($summary['year_rate']); ?>%</div>
            <div class="pl-num-label">今年の消化率</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ★ 有給申請カード -->
<div class="pl-card" id="pl-detail-requests-card">
    <div class="pl-card-title" style="display:flex; align-items:center; gap:10px;">
        <span class="dashicons dashicons-calendar-alt"></span>
        有給申請
        <?php if ( $pending_count > 0 ) : ?>
        <span id="pl-detail-pending-badge" style="
            display:inline-flex; align-items:center; justify-content:center;
            background:#d63638; color:#fff; border-radius:50px;
            font-size:12px; font-weight:700; padding:2px 9px; line-height:1.5;">
            未処理 <?php echo $pending_count; ?> 件
        </span>
        <?php endif; ?>
    </div>

    <?php if ( empty($leave_requests) ) : ?>
    <p class="pl-hint" style="margin:0;">申請はありません。</p>
    <?php else : ?>
    <div class="pl-table-wrap" style="margin-top:8px;">
    <table class="pl-data-table" id="plDetailReqTable">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>希望日</th>
                <th>申請日時</th>
                <th>状態</th>
                <th>申請備考</th>
                <th>管理者コメント</th>
                <th style="width:210px;">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $leave_requests as $req ) :
            $status_map = array(
                'pending'  => array('label'=>'申請中',   'class'=>'pl-badge-orange'),
                'approved' => array('label'=>'受理済み', 'class'=>'pl-badge-green'),
                'rejected' => array('label'=>'却下',      'class'=>'pl-badge-red'),
            );
            $st = $status_map[$req->status] ?? array('label'=>$req->status,'class'=>'');
        ?>
        <tr id="pl-req-row-<?php echo (int)$req->id; ?>">
            <td><?php echo (int)$req->id; ?></td>
            <td><strong><?php echo esc_html($req->request_date); ?></strong></td>
            <td style="font-size:12px; color:#666;"><?php echo esc_html(substr($req->created_at??'',0,16)); ?></td>
            <td id="pl-req-status-<?php echo (int)$req->id; ?>">
                <span class="pl-badge <?php echo esc_attr($st['class']); ?>"><?php echo esc_html($st['label']); ?></span>
            </td>
            <td style="font-size:12px;"><?php echo esc_html($req->note ?? '—'); ?></td>
            <td style="font-size:12px;" id="pl-req-admin-note-<?php echo (int)$req->id; ?>">
                <?php echo esc_html($req->admin_note ?? '—'); ?>
            </td>
            <td>
            <?php if ( $req->status === 'pending' ) : ?>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <button class="pl-btn-sm pl-req-approve"
                        data-id="<?php echo (int)$req->id; ?>"
                        data-date="<?php echo esc_attr($req->request_date); ?>"
                        style="background:#16a34a; color:#fff; border-color:#16a34a;">
                        ✓ 受理
                    </button>
                    <button class="pl-btn-sm pl-req-reject"
                        data-id="<?php echo (int)$req->id; ?>"
                        data-date="<?php echo esc_attr($req->request_date); ?>"
                        style="background:#d63638; color:#fff; border-color:#d63638;">
                        ✕ 却下
                    </button>
                </div>
            <?php else : ?>
                <span style="font-size:12px; color:#888;">
                    <?php echo esc_html($req->approved_by ?? ''); ?>
                    <?php if($req->approved_at) echo ' ('.esc_html(substr($req->approved_at,0,10)).')'; ?>
                </span>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- 受理・却下モーダル -->
<div id="plDetailReqModal" class="pl-modal-overlay" style="display:none;">
    <div class="pl-modal-box" style="max-width:480px;">
        <h3 id="plDetailReqModalTitle" class="pl-modal-title"></h3>
        <div class="pl-modal-body">
            <p id="plDetailReqModalDesc" style="margin:0 0 16px; font-size:14px;"></p>
            <div class="pl-field">
                <label class="pl-label">管理者コメント（任意）</label>
                <textarea id="plDetailReqAdminNote" class="pl-input" rows="3"
                    placeholder="受理/却下の理由など"></textarea>
            </div>
        </div>
        <div class="pl-modal-footer">
            <button id="plDetailReqModalConfirm" class="pl-btn pl-btn-primary">受理し消化登録をする</button>
            <button id="plDetailReqModalCancel" class="pl-btn pl-btn-secondary">キャンセル</button>
        </div>
    </div>
</div>

<!-- 付与情報（直近3件） -->
<div class="pl-card">
    <div class="pl-card-title">直近の付与</div>
    <?php if ( empty($recent) ) : ?>
    <p class="pl-hint">付与記録がありません。</p>
    <?php else : ?>
    <div class="pl-table-wrap">
    <table class="pl-data-table">
        <thead><tr><th>付与日</th><th>勤続年数</th><th>週勤務</th><th>付与日数</th><th>残日数</th><th>有効期限</th><th>状態</th></tr></thead>
        <tbody>
        <?php foreach ( $recent as $g ) : ?>
        <tr>
            <td><?php echo esc_html($g->grant_date); ?></td>
            <td><?php echo esc_html( pl_format_tenure( $g->tenure_months ) ); ?></td>
            <td>週<?php echo (int)($g->weekly_work_days_at_grant ?? 5); ?>勤務</td>
            <td><?php echo esc_html($g->granted_days); ?> 日</td>
            <td><strong><?php echo esc_html($g->remaining_days); ?> 日</strong></td>
            <td><?php echo esc_html($g->expiry_date); ?></td>
            <td><?php echo $g->is_expired ? '<span class="pl-badge pl-badge-red">失効</span>' : '<span class="pl-badge pl-badge-green">有効</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ( count($all_grants) > 3 ) : ?>
    <button class="pl-drawer-toggle" id="allGrantsToggle" style="margin-top:8px;">
        全付与ログ（<?php echo count($all_grants); ?>件）を見る <span class="pl-drawer-icon">▼</span>
    </button>
    <div id="allGrantsDrawer" style="display:none; margin-top:8px;">
    <div class="pl-table-wrap">
    <table class="pl-data-table">
        <thead><tr><th>付与日</th><th>勤続月数</th><th>週勤務</th><th>付与日数</th><th>残日数</th><th>有効期限</th><th>状態</th></tr></thead>
        <tbody>
        <?php foreach ( $all_grants as $g ) : ?>
        <tr>
            <td><?php echo esc_html($g->grant_date); ?></td>
            <td><?php echo esc_html( pl_format_tenure( $g->tenure_months ) ); ?></td>
            <td>週<?php echo (int)($g->weekly_work_days_at_grant ?? 5); ?>勤務</td>
            <td><?php echo esc_html($g->granted_days); ?> 日</td>
            <td><strong><?php echo esc_html($g->remaining_days); ?> 日</strong></td>
            <td><?php echo esc_html($g->expiry_date); ?></td>
            <td><?php echo $g->is_expired ? '<span class="pl-badge pl-badge-red">失効</span>' : '<span class="pl-badge pl-badge-green">有効</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php endif; ?>
</div>

<!-- 消化ログ -->
<div class="pl-card">
    <div class="pl-card-title">消化ログ</div>
    <?php if ( empty($consume_logs) ) : ?>
    <p class="pl-hint">消化記録がありません。</p>
    <?php else : ?>
    <button class="pl-drawer-toggle" id="consumeLogsToggle">
        消化ログ（<?php echo count($consume_logs); ?>件）を見る <span class="pl-drawer-icon">▼</span>
    </button>
    <div id="consumeLogsDrawer" style="display:none; margin-top:8px;">
    <div class="pl-table-wrap">
    <table class="pl-data-table">
        <thead><tr><th>消化日</th><th>消化日数</th><th>備考</th></tr></thead>
        <tbody>
        <?php foreach ( $consume_logs as $cl ) : ?>
        <tr>
            <td><?php echo esc_html($cl->consumed_date); ?></td>
            <td><?php echo esc_html($cl->consumed_days); ?> 日</td>
            <td><?php echo esc_html($cl->note ?? '—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce      = '<?php echo wp_create_nonce("pl_request_nonce"); ?>';
    var grantNonce = '<?php echo wp_create_nonce("pl_grant_nonce"); ?>';
    var empCode    = '<?php echo esc_js($code); ?>';
    var ajaxurl    = '<?php echo admin_url("admin-ajax.php"); ?>';
    var pendingCount = <?php echo (int)$pending_count; ?>;
    var currentAction = '';
    var currentId     = 0;
    var currentDate   = '';

    // =====================================================
    //  ★ サマリーをAJAXで再取得してDOMを更新する関数
    // =====================================================
    function refreshSummary() {
        $.post(ajaxurl, {
            action:        'pl_grant_get_summary',
            nonce:         grantNonce,
            employee_code: empCode,
        }, function(res) {
            if (!res.success) return;
            var s = res.data;

            // 数値を更新（小数点以下の .0 は省略）
            function fmt(val) {
                var n = parseFloat(val);
                return isNaN(n) ? '0' : n.toFixed(1).replace(/\.0$/, '');
            }

            // ① 現在有効期間内
            $('#sum-valid-granted').text(fmt(s.valid_granted));
            $('#sum-valid-consumed').text(fmt(s.valid_consumed));
            $('#sum-valid-remaining').text(fmt(s.valid_remaining));
            $('#sum-valid-rate').text(fmt(s.valid_rate) + '%');
            $('#sum-rate-bar').text(fmt(s.valid_rate));

            // プログレスバーの幅・色を更新（①消化率・累計）
            var r = parseFloat(s.valid_rate);
            var w = Math.min(100, isNaN(r) ? 0 : r);
            $('#sum-progress-fill')
                .css('width', w + '%')
                .removeClass('pl-progress-warn pl-progress-done')
                .addClass(r >= 100 ? 'pl-progress-done' : (r >= 80 ? '' : 'pl-progress-warn'));

            // ② 今年（付与起算）
            $('#sum-year-granted').text(fmt(s.year_granted));
            $('#sum-year-consumed').text(fmt(s.year_consumed));
            $('#sum-year-rate').text(fmt(s.year_rate) + '%');

            // 年5日アラートの再描画
            var $alert = $('#sum-year-alert');
            if ($alert.length) {
                if (s.year_alert) {
                    $alert.html(
                        '⚠️ 年5日の取得義務に対し、今年の消化が <strong>' + fmt(s.year_consumed) +
                        '</strong> 日です。期限（' + (s.cycle_end || '') +
                        '）まで3か月を切っています。計画的な取得をご案内ください。'
                    ).show();
                } else {
                    $alert.hide().empty();
                }
            }
        });
    }

    // =====================================================
    //  受理ボタン → モーダルを「受理」用に開く
    // =====================================================
    $(document).on('click', '.pl-req-approve', function() {
        currentAction = 'approve';
        currentId     = $(this).data('id');
        currentDate   = $(this).data('date');
        $('#plDetailReqModalTitle').text('受理の確認');
        $('#plDetailReqModalDesc').html(
            '<strong>' + currentDate + '</strong> の有給申請を受理し、消化登録（1日）を行います。'
        );
        $('#plDetailReqModalConfirm')
            .removeClass('pl-btn-danger')
            .addClass('pl-btn-primary')
            .text('受理し消化登録をする');
        $('#plDetailReqAdminNote').val('');
        $('#plDetailReqModal').show();
    });

    // =====================================================
    //  却下ボタン → モーダルを「却下」用に開く
    // =====================================================
    $(document).on('click', '.pl-req-reject', function() {
        currentAction = 'reject';
        currentId     = $(this).data('id');
        currentDate   = $(this).data('date');
        $('#plDetailReqModalTitle').text('却下の確認');
        $('#plDetailReqModalDesc').html(
            '<strong>' + currentDate + '</strong> の有給申請を却下します。'
        );
        $('#plDetailReqModalConfirm')
            .removeClass('pl-btn-primary')
            .addClass('pl-btn-danger')
            .text('却下する');
        $('#plDetailReqAdminNote').val('');
        $('#plDetailReqModal').show();
    });

    // =====================================================
    //  モーダル：確定ボタン
    // =====================================================
    $('#plDetailReqModalConfirm').on('click', function() {
        var $btn      = $(this).prop('disabled', true).text('処理中...');
        var adminNote = $('#plDetailReqAdminNote').val();
        // ★ 正しい AJAX アクション名で送信
        var action    = currentAction === 'approve' ? 'pl_request_approve' : 'pl_request_reject';

        $.post(ajaxurl, {
            action:     action,
            nonce:      nonce,
            request_id: currentId,
            admin_note: adminNote,
        }, function(res) {
            $btn.prop('disabled', false);
            $('#plDetailReqModal').hide();

            if (res.success) {
                // ---- ① 申請行のUIを更新 ----
                var $row = $('#pl-req-row-' + currentId);

                if (currentAction === 'approve') {
                    $btn.text('受理し消化登録をする');
                    $('#pl-req-status-' + currentId).html(
                        '<span class="pl-badge pl-badge-green">受理済み</span>'
                    );
                } else {
                    $btn.text('却下する');
                    $('#pl-req-status-' + currentId).html(
                        '<span class="pl-badge pl-badge-red">却下</span>'
                    );
                }
                // 操作ボタン列 → 処理者表示に切り替え
                $row.find('td:last').html(
                    '<span style="font-size:12px;color:#888;">' +
                    (res.data.approved_by || '') + '</span>'
                );
                // 管理者コメント列を更新
                $('#pl-req-admin-note-' + currentId).text(adminNote || '—');

                // ---- ② pending件数バッジを更新 ----
                pendingCount--;
                if (pendingCount <= 0) {
                    $('#pl-detail-pending-badge').remove();
                } else {
                    $('#pl-detail-pending-badge').text('未処理 ' + pendingCount + ' 件');
                }

                // ---- ③ ★ 受理した場合のみサマリーをリアルタイム更新 ----
                if (currentAction === 'approve') {
                    refreshSummary();
                }

                alert(res.data.message);

            } else {
                $btn.text(currentAction === 'approve' ? '受理し消化登録をする' : '却下する');
                alert('エラー: ' + (res.data.message || '処理に失敗しました'));
            }
        }).fail(function() {
            $btn.prop('disabled', false)
                .text(currentAction === 'approve' ? '受理し消化登録をする' : '却下する');
            alert('通信エラーが発生しました。');
        });
    });
});
</script>

<?php endif; ?>
</div>