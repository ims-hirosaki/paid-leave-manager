<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wrap pl-wrap" id="pl-reset-page">
<h1 class="pl-page-title">
    <span class="dashicons dashicons-trash"></span> テストデータ削除
</h1>

<!-- 警告バナー -->
<div style="display:flex;align-items:flex-start;gap:.75rem;
            background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;
            padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <span class="dashicons dashicons-warning" style="color:#d97706;font-size:1.3rem;flex-shrink:0;margin-top:1px;"></span>
    <div>
        <strong style="display:block;margin-bottom:.25rem;">この操作はデータベースから物理削除します。元に戻すことはできません。</strong>
        <span style="font-size:.85rem;color:#92400e;">
            削除対象：付与ログ・消化ログ・申請ログ　／　削除されないもの：有給ルール設定・祝日データ
        </span>
    </div>
</div>

<!-- ============================================================
     セクション1: 社員別削除
     ============================================================ -->
<div class="pl-card">
    <div class="pl-card-title pl-card-title-orange">
        <span class="dashicons dashicons-admin-users"></span> 社員別データ削除
    </div>
    <p class="pl-hint" style="margin-bottom:1rem;">
        社員コードを入力してその社員の有給データのみ削除します。他の社員のデータには影響しません。
    </p>

    <div class="pl-field-row">
        <input type="text" id="plResetEmpCode" class="pl-input"
            placeholder="社員コードを入力" style="width:200px;">
        <button type="button" id="plResetCheckBtn" class="pl-btn pl-btn-primary">
            <span class="dashicons dashicons-search" style="font-size:14px;margin-top:1px;"></span> 件数確認
        </button>
    </div>

    <!-- 確認パネル -->
    <div id="plResetEmpPanel" style="display:none;margin-top:1.25rem;">
        <div style="background:#f8fafc;border:1px solid var(--pl-border);border-radius:8px;padding:1rem 1.25rem;">
            <div style="font-weight:700;margin-bottom:.9rem;">削除対象の確認</div>
            <div style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;max-width:320px;margin-bottom:1rem;font-size:.9rem;">
                <span style="color:var(--pl-muted);font-weight:600;">社員コード</span>
                <span id="plResetEmpCodeVal" style="font-weight:700;">—</span>
                <span style="color:var(--pl-muted);font-weight:600;">氏名</span>
                <span id="plResetEmpNameVal" style="font-weight:700;">—</span>
            </div>
            <div class="pl-reset-counts">
                <div class="pl-reset-count-item">
                    <span class="pl-reset-count-num" id="plResetCountGrants">0</span>
                    <span class="pl-reset-count-label">付与ログ</span>
                </div>
                <span class="pl-reset-count-sep">＋</span>
                <div class="pl-reset-count-item">
                    <span class="pl-reset-count-num" id="plResetCountConsumptions">0</span>
                    <span class="pl-reset-count-label">消化ログ</span>
                </div>
                <span class="pl-reset-count-sep">＋</span>
                <div class="pl-reset-count-item">
                    <span class="pl-reset-count-num" id="plResetCountRequests">0</span>
                    <span class="pl-reset-count-label">申請ログ</span>
                </div>
                <span class="pl-reset-count-sep">=</span>
                <div class="pl-reset-count-item">
                    <span class="pl-reset-count-num" style="color:var(--pl-danger);" id="plResetCountEmpTotal">0</span>
                    <span class="pl-reset-count-label">合計</span>
                </div>
            </div>
            <p id="plResetEmpNoData" style="display:none;color:var(--pl-muted);font-size:.875rem;margin-top:.5rem;margin-bottom:0;">
                この社員の削除対象データはありません。
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;margin-top:1rem;flex-wrap:wrap;">
            <button type="button" id="plResetEmpBtn"
                class="pl-btn" style="background:var(--pl-danger);color:#fff;" disabled>
                <span class="dashicons dashicons-trash" style="font-size:14px;margin-top:1px;"></span>
                この社員のデータを削除する
            </button>
            <span style="font-size:.8rem;color:var(--pl-muted);">※ 実行前に確認ダイアログが表示されます</span>
        </div>
    </div>
</div>

<!-- ============================================================
     セクション2: 全件削除
     ============================================================ -->
<div class="pl-card pl-card-danger">
    <div class="pl-card-title pl-card-title-red">
        <span class="dashicons dashicons-warning"></span> 全データ削除
    </div>
    <p class="pl-hint" style="margin-bottom:1rem;">
        全社員の付与・消化・申請データをまとめて削除します。運用開始前のテストデータを一括リセットする際に使用してください。
    </p>

    <!-- 全件カウント表示 -->
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="font-weight:700;margin-bottom:.9rem;color:var(--pl-danger);">現在のデータ件数</div>
        <div class="pl-reset-counts">
            <div class="pl-reset-count-item">
                <span class="pl-reset-count-num" id="plResetAllGrants">—</span>
                <span class="pl-reset-count-label">付与ログ</span>
            </div>
            <span class="pl-reset-count-sep" style="color:#fca5a5;">＋</span>
            <div class="pl-reset-count-item">
                <span class="pl-reset-count-num" id="plResetAllConsumptions">—</span>
                <span class="pl-reset-count-label">消化ログ</span>
            </div>
            <span class="pl-reset-count-sep" style="color:#fca5a5;">＋</span>
            <div class="pl-reset-count-item">
                <span class="pl-reset-count-num" id="plResetAllRequests">—</span>
                <span class="pl-reset-count-label">申請ログ</span>
            </div>
            <span class="pl-reset-count-sep" style="color:#fca5a5;">=</span>
            <div class="pl-reset-count-item">
                <span class="pl-reset-count-num" style="color:var(--pl-danger);" id="plResetAllTotal">—</span>
                <span class="pl-reset-count-label">合計</span>
            </div>
        </div>
    </div>

    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <button type="button" id="plResetAllBtn"
            class="pl-btn" style="background:var(--pl-danger);color:#fff;">
            <span class="dashicons dashicons-warning" style="font-size:14px;margin-top:1px;"></span>
            全データを削除する
        </button>
        <span style="font-size:.8rem;color:var(--pl-muted);">※ 全社員分の付与・消化・申請ログがすべて削除されます</span>
    </div>
</div>

<!-- ============================================================
     削除完了ログ（実行後に表示）
     ============================================================ -->
<div id="plResetLog" style="display:none;">
    <div class="pl-card" style="border-left:4px solid var(--pl-success);">
        <div class="pl-card-title pl-card-title-green">
            <span class="dashicons dashicons-yes-alt"></span> 削除完了
        </div>
        <p id="plResetLogMsg" style="font-weight:700;margin-bottom:.9rem;font-size:.95rem;"></p>
        <div id="plResetLogDetail" style="display:flex;gap:.75rem;flex-wrap:wrap;"></div>
    </div>
</div>

<!-- ============================================================
     確認モーダル
     ============================================================ -->
<div id="plResetModal" class="pl-modal-overlay" style="display:none;">
    <div class="pl-modal-box" style="max-width:440px;">
        <h3 class="pl-modal-title" style="color:var(--pl-danger);margin-top:0;">
            <span class="dashicons dashicons-warning"></span>
            <span id="plResetModalTitle">削除の確認</span>
        </h3>
        <div class="pl-modal-body" style="padding:0 0 1.25rem;">
            <p id="plResetModalMsg" style="font-size:.95rem;line-height:1.8;margin:0 0 1rem;"></p>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;
                        padding:.75rem 1rem;font-size:.875rem;color:#b91c1c;font-weight:600;">
                この操作は取り消せません。本当に削除しますか？
            </div>
        </div>
        <div class="pl-modal-footer" style="display:flex;gap:.75rem;justify-content:flex-end;padding-top:1rem;border-top:1px solid var(--pl-border);">
            <button id="plResetModalConfirm" class="pl-btn" style="background:var(--pl-danger);color:#fff;">
                削除する
            </button>
            <button id="plResetModalCancel" class="pl-btn pl-btn-secondary">キャンセル</button>
        </div>
    </div>
</div>

<!-- ページ専用スタイル -->
<style>
.pl-reset-counts {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.pl-reset-count-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .2rem;
    min-width: 64px;
}
.pl-reset-count-num {
    font-size: 1.8rem;
    font-weight: 800;
    line-height: 1;
    color: var(--pl-text);
}
.pl-reset-count-label {
    font-size: .72rem;
    font-weight: 600;
    color: var(--pl-muted);
    white-space: nowrap;
}
.pl-reset-count-sep {
    font-size: 1.1rem;
    color: var(--pl-border);
    font-weight: 700;
    align-self: flex-end;
    padding-bottom: .4rem;
}
</style>
