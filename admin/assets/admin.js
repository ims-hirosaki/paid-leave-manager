/* global plData, jQuery */
(function ($) {
    'use strict';

    // =====================================================
    //  共通ユーティリティ
    // =====================================================

    function plAjax(action, data, callback) {
        $.post(plData.ajaxUrl, $.extend({ action: action }, data))
            .done(function (res) {
                if (typeof callback === 'function') callback(res);
            })
            .fail(function (xhr) {
                // サーバーエラー時（500等）にここに来る
                var msg = 'サーバーエラーが発生しました (HTTP ' + xhr.status + ')';
                if (typeof callback === 'function') {
                    callback({ success: false, data: { message: msg } });
                }
            });
    }

    let toastTimer;
    function showToast(msg, type) {
        let $t = $('#plToast');
        if (!$t.length) {
            $t = $('<div id="plToast"></div>').appendTo('body');
        }
        $t.text(msg).attr('class', type || '').addClass('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => $t.removeClass('show'), 4000);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatDays(val) {
        const n = parseFloat(val);
        return isNaN(n) ? '0' : n.toFixed(1).replace(/\.0$/, '');
    }

    // =====================================================
    //  従業員一覧ページ
    // =====================================================

    if ($('#pl-list-page').length) {

        let allEmployees = [];

        function loadAllEmployees() {
            plAjax('pl_summary_get', {
                nonce: plData.summaryNonce,
                date_from: '2000-01-01',
                date_to: '2099-12-31',
                mode: 'grant',
            }, function (res) {
                if (!res.success) return;
                allEmployees = res.data;
                renderList(allEmployees);
            });
        }

        function renderList(employees) {
            const $tbody = $('#plListTbody').empty();
            const search = $('#plSearch').val().toLowerCase();

            const filtered = employees.filter(function (e) {
                if (!search) return true;
                return (e.name || '').toLowerCase().includes(search)
                    || (e.employee_code || '').toLowerCase().includes(search);
            });

            $('#plFilterResult').text(filtered.length + ' 名');

            if (!filtered.length) {
                $tbody.append('<tr><td colspan="10" class="pl-empty">該当する社員がいません</td></tr>');
                return;
            }

            filtered.forEach(function (e) {
                const remaining = parseFloat(e.remaining || 0);
                const consumed = parseFloat(e.consumed || 0);

                // 失効予告列
                const expiryDays = parseFloat(e.expiry_warning_days || 0);
                let expiryCell;
                if (expiryDays > 0) {
                    expiryCell = `<span class="pl-rate-low" style="font-weight:600;">${formatDays(expiryDays)} 日が失効間近</span>`;
                } else {
                    expiryCell = '<span style="color:#aaa;">—</span>';
                }

                // 雇用区分・週勤務日数
                const employmentType = e.employment_type
                    ? escHtml(e.employment_type)
                    : '<span style="color:#aaa;">—</span>';
                const weeklyWorkDays = e.weekly_work_days
                    ? `週${e.weekly_work_days}勤務`
                    : '<span style="color:#aaa;">—</span>';

                // ★ 有給申請バッジ列
                //    pending_count > 0 なら赤バッジ（クリックで個人ページへ）
                //    0 なら「—」
                const pendingCount = parseInt(e.pending_count || 0, 10);
                const detailLink = `${plData.detailUrl}&code=${encodeURIComponent(e.employee_code)}`;
                let requestCell;
                if (pendingCount > 0) {
                    requestCell = `
                        <a href="${detailLink}" style="text-decoration:none;">
                            <span style="
                                display:inline-flex; align-items:center; gap:4px;
                                background:#d63638; color:#fff;
                                border-radius:50px; padding:3px 10px;
                                font-size:12px; font-weight:700;
                                white-space:nowrap; cursor:pointer;">
                                <span class="dashicons dashicons-bell" style="font-size:12px; width:12px; height:12px; line-height:1;"></span>
                                ${pendingCount} 件
                            </span>
                        </a>`;
                } else {
                    requestCell = '<span style="color:#aaa;">—</span>';
                }

                $tbody.append(`
                <tr>
                    <td><span class="pl-cell-code">${escHtml(e.employee_code)}</span></td>
                    <td><strong>${escHtml(e.name)}</strong></td>
                    <td>${escHtml(e.hire_date || '—')}</td>
                    <td>${employmentType}</td>
                    <td>${weeklyWorkDays}</td>
                    <td><strong>${formatDays(remaining)} 日</strong></td>
                    <td>${formatDays(consumed)} 日</td>
                    <td>${expiryCell}</td>
                    <td style="text-align:center;">${requestCell}</td>
                    <td>
                        <a href="${plData.grantUrl}&code=${encodeURIComponent(e.employee_code)}" class="pl-btn-sm">付与・消化</a>
                        <a href="${detailLink}" class="pl-btn-sm pl-btn-sm-secondary">詳細</a>
                    </td>
                </tr>` );
            });
        }

        let searchTimer;
        $('#plSearch, #plFilterStatus').on('input change', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => renderList(allEmployees), 300);
        });

        loadAllEmployees();
    }

    // =====================================================
    //  付与・消化登録ページ
    // =====================================================

    if ($('#pl-grant-page').length) {

        let currentEmp = null;
        let currentCheck = null;

        const urlCode = new URLSearchParams(window.location.search).get('code');
        if (urlCode) {
            $('#plEmpCode').val(urlCode);
            doSearch(urlCode);
        }

        $('#plSearchBtn').on('click', function () {
            doSearch($('#plEmpCode').val().trim());
        });
        $('#plEmpCode').on('keydown', function (e) {
            if (e.key === 'Enter') doSearch($(this).val().trim());
        });

        function doSearch(code) {
            if (!code) { showToast('社員コードを入力してください', 'error'); return; }

            plAjax('pl_grant_check', { nonce: plData.grantNonce, employee_code: code }, function (res) {
                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'エラーが発生しました';
                    showToast(msg, 'error');
                    $('#plEmpResult').hide();
                    return;
                }

                currentEmp = res.data.employee;
                currentCheck = res.data.check;
                const summary = res.data.summary;

                $('#riCode').text(currentEmp.employee_code);
                $('#riName').text(currentEmp.name);
                $('#riHire').text(currentEmp.hire_date || '—');
                $('#riEmpType').text(currentEmp.employment_type || '—');
                $('#riWeekly').text(currentEmp.weekly_work_days ? '週' + currentEmp.weekly_work_days + '勤務' : '—');

                renderSummary(currentEmp, summary);

                if (currentCheck.grantable) {
                    $('#plGrantInfo').html('<div class="pl-grant-badge">✓ ' + escHtml(currentCheck.message) + '</div>');
                    $('#plGrantDays').val(currentCheck.granted_days);
                    $('#plGrantSection').show();
                } else {
                    $('#plGrantSection').hide();
                }

                const totalRemaining = parseFloat(summary.total_remaining || 0);
                $('#plConsumeSection').toggle(totalRemaining > 0);

                $('#plEmpResult').show();
            });
        }

        function renderSummary(emp, summary) {
            const $tbody = $('#plGrantTbody').empty();
            const grants = summary.grants || [];

            if (!grants.length) {
                $tbody.append('<tr><td colspan="9" class="pl-empty">付与記録がありません</td></tr>');
                $('#plBarWrap').hide();
                return;
            }

            const totalGranted = parseFloat(summary.total_granted || 0);
            const totalConsumed = parseFloat(summary.total_consumed || 0);
            const totalRemaining = parseFloat(summary.total_remaining || 0);
            const rate = parseFloat(summary.consumption_rate || 0);
            const firstGrant = grants[grants.length - 1]?.grant_date || '—';

            $tbody.append(`
            <tr class="pl-summary-row">
                <td>${escHtml(emp.employee_code)}</td>
                <td>${escHtml(emp.name)}</td>
                <td>${escHtml(emp.hire_date || '—')}</td>
                <td>${escHtml(firstGrant)}</td>
                <td>${formatDays(totalGranted)} 日</td>
                <td>${formatDays(totalConsumed)} 日</td>
                <td><strong>${formatDays(totalRemaining)} 日</strong></td>
                <td>${rate}%</td>
                <td><a href="${plData.detailUrl}&code=${encodeURIComponent(emp.employee_code)}" class="pl-btn-sm pl-btn-sm-secondary">詳細</a></td>
            </tr>` );

            (summary.expiring_soon || []).forEach(function (g) {
                $tbody.append(`
                <tr class="pl-row-warn">
                    <td colspan="2"><span class="pl-badge pl-badge-orange">⚠ 失効予告</span></td>
                    <td colspan="2">付与ID #${g.id} / 失効日: ${escHtml(g.expiry_date)}</td>
                    <td colspan="5"><strong>${escHtml(g.remaining_days)} 日</strong>が失効します</td>
                </tr>` );
            });

            const fillWidth = Math.min(100, rate);
            $('#plBarConsumed').text(formatDays(totalConsumed));
            $('#plBarRemaining').text(formatDays(totalRemaining));
            $('#plProgressFill').css('width', fillWidth + '%');
            $('#plBarRate').text(rate + '% 消化済み');
            $('#plBarWrap').show();
        }

        // ---- 付与実行 ----
        $('#plGrantBtn').on('click', function () {
            if (!currentEmp || !currentCheck) return;
            const days = parseFloat($('#plGrantDays').val());
            if (isNaN(days) || days <= 0) { showToast('付与日数を正しく入力してください', 'error'); return; }
            if (!confirm(currentEmp.name + ' に ' + days + '日 を付与しますか？')) return;

            $('#plGrantBtn').prop('disabled', true).text('処理中...');
            plAjax('pl_grant_execute', {
                nonce: plData.grantNonce,
                employee_code: currentEmp.employee_code,
                tenure_months: currentCheck.tenure_months,
                grant_date: $('#plGrantDate').val(),
                granted_days: days,
                weekly_days: currentCheck.weekly_days || 5,
            }, function (res) {
                $('#plGrantBtn').prop('disabled', false).html('<span class="dashicons dashicons-plus"></span> 付与する');
                if (res.success) {
                    showToast('✓ ' + res.data.message, 'success');
                    doSearch(currentEmp.employee_code);
                } else {
                    showToast(res.data.message, 'error');
                }
            });
        });

        // ---- 消化登録（単日） ----
        $('#plConsumeBtn').on('click', function () {
            if (!currentEmp) return;
            const days = parseFloat($('#plConsumeDays').val());
            const date = $('#plConsumeDate').val();
            const note = $('#plConsumeNote').val();
            if (!date) { showToast('消化日を入力してください', 'error'); return; }
            if (!confirm(date + ' に ' + days + '日の消化を登録しますか？')) return;

            $('#plConsumeBtn').prop('disabled', true).text('処理中...');
            $.post(plData.ajaxUrl, {
                action: 'pl_consume_execute',
                nonce: plData.grantNonce,
                employee_code: currentEmp.employee_code,
                mode: 'single',
                consume_date: date,
                consume_days: days,
                unit_type: 'day',
                note: note,
            }).always(function (res) {
                $('#plConsumeBtn').prop('disabled', false).html('<span class="dashicons dashicons-minus"></span> 消化を登録する');
                try {
                    const data = (typeof res === 'string') ? JSON.parse(res) : res;
                    if (data && data.success) {
                        showToast('✓ ' + data.data.message, 'success');
                        doSearch(currentEmp.employee_code);
                    } else {
                        const msg = (data && data.data && data.data.message) ? data.data.message : 'エラーが発生しました';
                        showToast(msg, 'error');
                    }
                } catch (e) {
                    showToast('サーバーエラーが発生しました', 'error');
                }
            });
        });
    }

    // =====================================================
    //  個人管理ページ（ドロワー）
    // =====================================================

    if ($('#pl-detail-page').length) {
        // 付与ログ・消化ログ共通のドロワートグル処理
        $('.pl-drawer-toggle').on('click', function () {
            const id = $(this).attr('id').replace('Toggle', 'Drawer');
            const $drawer = $('#' + id);
            const $icon = $(this).find('.pl-drawer-icon');
            $drawer.slideToggle(200);
            $icon.text($drawer.is(':hidden') ? '▼' : '▲');
        });
    }

    // =====================================================
    //  集計表ページ
    // =====================================================

    if ($('#pl-summary-page').length) {

        $('#plSumSearch').on('click', function () {
            const from = $('#plSumFrom').val();
            const to = $('#plSumTo').val();
            const mode = $('[name="pl_mode"]:checked').val();

            if (!from || !to) { showToast('期間を入力してください', 'error'); return; }
            $('#plSumTbody').html('<tr><td colspan="8" class="pl-empty pl-loading">集計中...</td></tr>');

            plAjax('pl_summary_get', {
                nonce: plData.summaryNonce,
                date_from: from,
                date_to: to,
                mode: mode,
            }, function (res) {
                renderSummaryTable(res);
            });
        });

        function renderSummaryTable(res) {
            const $tbody = $('#plSumTbody').empty();
            if (!res.success || !res.data.length) {
                $tbody.append('<tr><td colspan="8" class="pl-empty">データがありません</td></tr>');
                return;
            }
            res.data.forEach(function (e) {
                const rate = parseFloat(e.rate || 0);
                const rateColor = rate < 50 ? 'pl-rate-low' : (rate >= 80 ? 'pl-rate-high' : '');
                $tbody.append(`
                <tr>
                    <td><span class="pl-cell-code">${escHtml(e.employee_code)}</span></td>
                    <td><a href="${plData.detailUrl}&code=${encodeURIComponent(e.employee_code)}">${escHtml(e.name)}</a></td>
                    <td>${escHtml(e.hire_date || '—')}</td>
                    <td>${escHtml(e.first_grant_date || '—')}</td>
                    <td>${formatDays(e.total_granted)} 日</td>
                    <td>${formatDays(e.consumed)} 日</td>
                    <td><strong>${formatDays(e.remaining)} 日</strong></td>
                    <td><span class="${rateColor}">${rate}%</span></td>
                </tr>` );
            });
        }
    }

    // =====================================================
    //  有給ルール設定ページ
    // =====================================================

    if ($('#pl-rules-page').length) {

        $('#plSaveRules').on('click', function () {
            const rules = {};
            $('.pl-rule-input').each(function () {
                const match = ($(this).attr('name') || '').match(/rules\[(\d+)\]\[(\d+)\]/);
                if (!match) return;
                if (!rules[match[1]]) rules[match[1]] = {};
                rules[match[1]][match[2]] = $(this).val();
            });

            const dows = [];
            $('.pl-dow-checkbox:checked').each(function () { dows.push(parseInt($(this).val())); });
            const units = [];
            $('.pl-unit-checkbox:checked').each(function () { units.push($(this).val()); });

            $('#plSaveRules').prop('disabled', true).text('保存中...');
            plAjax('pl_rules_save', {
                nonce: plData.rulesNonce,
                rules: rules,
                effective_date: $('#plEffectiveDate').val(),
                carryover_years: $('#plCarryoverYears').val(),
                expiration_years: $('#plExpirationYears').val(),
                min_annual_days: $('#plMinAnnualDays').val(),
                legal_holiday_dow: JSON.stringify(dows),
                use_national_holidays: $('#plUseNationalHolidays').is(':checked') ? '1' : '0',
                consumption_units: JSON.stringify(units),
            }, function (res) {
                $('#plSaveRules').prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> ルールを保存する');
                if (res.success) {
                    showToast('✓ ' + res.data.message, 'success');
                } else {
                    showToast(res.data.message, 'error');
                }
            });
        });

        $('#plFetchHolidays').on('click', function () {
            $(this).prop('disabled', true).text('取得中...');
            plAjax('pl_holiday_fetch', { nonce: plData.rulesNonce }, function (res) {
                $('#plFetchHolidays').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> 内閣府CSVから祝日を取得・更新');
                if (res.success) {
                    showToast('✓ ' + res.data.message, 'success');
                } else {
                    showToast(res.data.message, 'error');
                }
            });
        });
    }

    // =====================================================
    //  有給申請承認ページ（MAT連携）
    // =====================================================

    if ($('#pl-requests-page').length) {

        let allRequests = [];

        function loadRequests() {
            const status = $('#plReqFilterStatus').val();
            $('#plReqTbody').html('<tr><td colspan="6" class="pl-empty pl-loading">読み込み中...</td></tr>');

            plAjax('pl_requests_get', {
                nonce: plData.requestsNonce,
                status: status,
            }, function (res) {
                if (!res.success) {
                    showToast(res.data.message || 'エラーが発生しました', 'error');
                    return;
                }
                allRequests = res.data;
                renderRequests(allRequests);
            });
        }

        function renderRequests(requests) {
            const $tbody = $('#plReqTbody').empty();

            if (!requests.length) {
                $tbody.append('<tr><td colspan="6" class="pl-empty">申請がありません</td></tr>');
                $('#plReqCount').text('0 件');
                return;
            }

            $('#plReqCount').text(requests.length + ' 件');

            requests.forEach(function (r) {
                const statusBadge = getStatusBadge(r.status);
                const actions = r.status === 'pending'
                    ? `<button class="pl-btn-sm pl-req-approve" data-id="${r.id}">承認</button>
                       <button class="pl-btn-sm" style="background:#dc2626;" class="pl-req-reject" data-id="${r.id}">却下</button>`
                    : '—';

                $tbody.append(`
                <tr data-id="${r.id}">
                    <td><span class="pl-cell-code">${escHtml(r.employee_code)}</span></td>
                    <td>${escHtml(r.employee_name || '—')}</td>
                    <td>${escHtml(r.request_date)}</td>
                    <td>${statusBadge}</td>
                    <td>${escHtml(r.note || '—')}</td>
                    <td>
                        ${r.status === 'pending'
                        ? `<button class="pl-btn-sm pl-req-approve" data-id="${r.id}">承認</button>
                               <button class="pl-btn-sm pl-req-reject" style="background:#dc2626;" data-id="${r.id}">却下</button>`
                        : '—'
                    }
                    </td>
                </tr>` );
            });
        }

        function getStatusBadge(status) {
            const map = {
                pending: '<span class="pl-badge pl-badge-orange">申請中</span>',
                approved: '<span class="pl-badge pl-badge-green">承認済</span>',
                rejected: '<span class="pl-badge pl-badge-red">却下</span>',
            };
            return map[status] || escHtml(status);
        }

        // ---- 承認ボタン ----
        $('#plReqTbody').on('click', '.pl-req-approve', function () {
            const id = $(this).data('id');
            if (!confirm('この申請を承認しますか？')) return;
            updateRequest(id, 'approved');
        });

        // ---- 却下ボタン ----
        $('#plReqTbody').on('click', '.pl-req-reject', function () {
            const id = $(this).data('id');
            if (!confirm('この申請を却下しますか？')) return;
            updateRequest(id, 'rejected');
        });

        function updateRequest(id, status) {
            plAjax('pl_request_update', {
                nonce: plData.requestsNonce,
                id: id,
                status: status,
            }, function (res) {
                if (res.success) {
                    showToast('✓ ' + res.data.message, 'success');
                    loadRequests();
                } else {
                    showToast(res.data.message || 'エラーが発生しました', 'error');
                }
            });
        }

        // ---- フィルター変更 ----
        $('#plReqFilterStatus').on('change', function () {
            loadRequests();
        });

        // 初期読み込み
        loadRequests();
    }
    // =====================================================
    //  テストデータ削除ページ
    // =====================================================

    if ($('#pl-reset-page').length) {

        var _resetConfirmCallback = null;

        // ページ読み込み時に全件カウントを取得
        loadAllCounts();

        function loadAllCounts() {
            $('#plResetAllGrants, #plResetAllConsumptions, #plResetAllRequests, #plResetAllTotal')
                .text('読込中…');
            plAjax('pl_reset_get_counts', {
                nonce: plData.resetNonce,
                target: 'all',
            }, function (res) {
                if (!res || !res.success) {
                    $('#plResetAllGrants').text('—');
                    return;
                }
                var c = res.data.counts;
                $('#plResetAllGrants').text(c.grants);
                $('#plResetAllConsumptions').text(c.consumptions);
                $('#plResetAllRequests').text(c.requests);
                $('#plResetAllTotal').text(res.data.total);
            });
        }

        // ---- 社員別: 件数確認ボタン ----
        $('#plResetCheckBtn').on('click', function () {
            var code = $('#plResetEmpCode').val().trim();
            if (!code) { showToast('社員コードを入力してください', 'error'); return; }

            var $btn = $(this).prop('disabled', true)
                .html('<span class="dashicons dashicons-update" style="font-size:14px;margin-top:1px;"></span> 確認中…');

            plAjax('pl_reset_get_counts', {
                nonce: plData.resetNonce,
                target: 'employee',
                employee_code: code,
            }, function (res) {
                $btn.prop('disabled', false)
                    .html('<span class="dashicons dashicons-search" style="font-size:14px;margin-top:1px;"></span> 件数確認');

                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'エラーが発生しました';
                    showToast(msg, 'error');
                    $('#plResetEmpPanel').hide();
                    return;
                }

                var emp = res.data.employee;
                var c = res.data.counts;

                $('#plResetEmpCodeVal').text(emp.employee_code);
                $('#plResetEmpNameVal').text(emp.name);
                $('#plResetCountGrants').text(c.grants);
                $('#plResetCountConsumptions').text(c.consumptions);
                $('#plResetCountRequests').text(c.requests);
                $('#plResetCountEmpTotal').text(res.data.total);

                if (res.data.total === 0) {
                    $('#plResetEmpNoData').show();
                    $('#plResetEmpBtn').prop('disabled', true);
                } else {
                    $('#plResetEmpNoData').hide();
                    $('#plResetEmpBtn').prop('disabled', false);
                }

                $('#plResetEmpPanel').slideDown(200);
            });
        });

        // Enterキーでも確認を発火
        $('#plResetEmpCode').on('keydown', function (e) {
            if (e.key === 'Enter') $('#plResetCheckBtn').trigger('click');
        });

        // ---- 社員別: 削除ボタン ----
        $('#plResetEmpBtn').on('click', function () {
            var code = $('#plResetEmpCode').val().trim();
            var name = $('#plResetEmpNameVal').text();
            var total = parseInt($('#plResetCountEmpTotal').text(), 10);

            $('#plResetModalTitle').text('社員別データ削除の確認');
            $('#plResetModalMsg').html(
                '<strong>' + escHtml(name) + '（' + escHtml(code) + '）</strong> の<br>' +
                '付与・消化・申請ログ <strong>' + total + ' 件</strong> を削除します。'
            );
            openResetModal(function () { executeReset('employee', code); });
        });

        // ---- 全件削除ボタン ----
        $('#plResetAllBtn').on('click', function () {
            var total = $('#plResetAllTotal').text();

            $('#plResetModalTitle').text('全データ削除の確認');
            $('#plResetModalMsg').html(
                '<strong>全社員</strong> の付与・消化・申請ログ<br>' +
                '<strong style="color:var(--pl-danger);">' + escHtml(total) + ' 件すべて</strong> を削除します。'
            );
            openResetModal(function () { executeReset('all', ''); });
        });

        // ---- モーダル制御 ----
        function openResetModal(onConfirm) {
            _resetConfirmCallback = onConfirm;
            $('#plResetModal').fadeIn(150);
        }

        $('#plResetModalCancel').on('click', function () {
            $('#plResetModal').fadeOut(150);
            _resetConfirmCallback = null;
        });

        $('#plResetModal').on('click', function (e) {
            if ($(e.target).is('#plResetModal')) {
                $('#plResetModal').fadeOut(150);
                _resetConfirmCallback = null;
            }
        });

        $(document).on('keydown.resetModal', function (e) {
            if (e.key === 'Escape' && $('#plResetModal').is(':visible')) {
                $('#plResetModal').fadeOut(150);
                _resetConfirmCallback = null;
            }
        });

        $('#plResetModalConfirm').on('click', function () {
            $('#plResetModal').fadeOut(150);
            if (typeof _resetConfirmCallback === 'function') {
                _resetConfirmCallback();
                _resetConfirmCallback = null;
            }
        });

        // ---- 削除実行 ----
        function executeReset(target, code) {
            plAjax('pl_reset_execute', {
                nonce: plData.resetNonce,
                target: target,
                employee_code: code,
            }, function (res) {
                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : '削除に失敗しました';
                    showToast(msg, 'error');
                    return;
                }

                // 完了ログを表示
                $('#plResetLogMsg').text(res.data.message);
                var $detail = $('#plResetLogDetail').empty();
                $.each(res.data.detail || {}, function (label, val) {
                    $detail.append(
                        '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;' +
                        'padding:.5rem .9rem;font-size:.875rem;">' +
                        '<span style="font-weight:600;color:var(--pl-muted);">' + escHtml(label) + '</span> : ' +
                        '<strong>' + escHtml(val) + '</strong></div>'
                    );
                });
                $('#plResetLog').slideDown(200);
                showToast('✓ ' + res.data.message, 'success');

                // カウントをリフレッシュ
                loadAllCounts();

                // 社員別パネルをリセット
                if (target === 'employee') {
                    $('#plResetEmpPanel').slideUp(150);
                    $('#plResetEmpCode').val('');
                } else {
                    $('#plResetEmpPanel').slideUp(150);
                    $('#plResetEmpCode').val('');
                }
            });
        }
    }

})(jQuery);
