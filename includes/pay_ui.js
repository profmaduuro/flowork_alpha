/**
 * pay_ui.js — Shared AJAX utilities for the Flowork payment module
 * Drop this in /includes/ and it's auto-loaded by layout.php for pay_* pages
 */

window.PayUI = (function () {

    // ── Core fetch helper ─────────────────────────────────────────────────
    async function ajax(method, action, data) {
        const url = '/flowork/pay_ajax.php';
        let opts;
        if (method === 'GET') {
            const qs = new URLSearchParams({ action, ...data }).toString();
            opts = { method: 'GET' };
            return fetch(url + '?' + qs, opts).then(r => r.json());
        }
        const fd = new FormData();
        fd.append('action', action);
        for (const [k, v] of Object.entries(data)) {
            if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
            else fd.append(k, v);
        }
        return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
    }

    // ── Toast notification ────────────────────────────────────────────────
    let toastTimer;
    function toast(msg, type = 'success') {
        let el = document.getElementById('pay-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pay-toast';
            el.style.cssText = `
                position:fixed;bottom:24px;right:24px;z-index:9999;
                padding:12px 20px;border-radius:6px;font-size:13px;font-weight:500;
                max-width:360px;box-shadow:0 6px 20px rgba(0,0,0,.18);
                transform:translateY(120px);transition:transform .28s cubic-bezier(.4,0,.2,1);
                display:flex;align-items:center;gap:10px;
            `;
            document.body.appendChild(el);
        }
        const colors = {
            success: { bg: '#3d6b35', color: '#fff', icon: '✓' },
            error:   { bg: '#c03a2b', color: '#fff', icon: '✕' },
            warning: { bg: '#c8952a', color: '#fff', icon: '⚠' },
            info:    { bg: '#1a1208', color: '#e8b84b', icon: 'ℹ' },
        };
        const c = colors[type] || colors.info;
        el.style.background = c.bg;
        el.style.color = c.color;
        el.innerHTML = `<span style="font-size:1.1rem">${c.icon}</span><span>${msg}</span>`;
        clearTimeout(toastTimer);
        requestAnimationFrame(() => {
            el.style.transform = 'translateY(0)';
        });
        toastTimer = setTimeout(() => {
            el.style.transform = 'translateY(120px)';
        }, type === 'error' ? 5000 : 3500);
    }

    // ── Spinner helper (on any button) ────────────────────────────────────
    function spin(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn._orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:pay-spin .7s linear infinite;vertical-align:middle"></span>';
        } else {
            btn.disabled = false;
            btn.innerHTML = btn._orig || btn.innerHTML;
        }
    }

    // Inject spin keyframes once
    if (!document.getElementById('pay-spin-style')) {
        const s = document.createElement('style');
        s.id = 'pay-spin-style';
        s.textContent = '@keyframes pay-spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(s);
    }

    // ── Status badge renderer ─────────────────────────────────────────────
    const STATUS_BADGE = {
        pending:  '<span class="badge badge-gray" style="font-size:.6rem">Pending</span>',
        reviewed: '<span class="badge badge-gold" style="font-size:.6rem">Reviewed</span>',
        approved: '<span class="badge badge-blue" style="font-size:.6rem">Approved</span>',
        released: '<span class="badge badge-green" style="font-size:.6rem">Released</span>',
        locked:   '<span class="badge badge-red" style="font-size:.6rem">Locked</span>',
        paid:     '<span class="badge badge-green" style="font-size:.6rem">Paid</span>',
        on_hold:  '<span class="badge badge-red" style="font-size:.6rem">On Hold</span>',
    };

    // ── Individual payment confirmation ───────────────────────────────────
    function confirmPayment(tgid, growerNum, growerName, currency, amount, date) {
        // Reuse existing modal from payments.php
        const modal = document.getElementById('pay-modal');
        if (!modal) return;
        document.getElementById('modal-tgid').value       = tgid;
        document.getElementById('modal-date').value       = date;
        document.getElementById('modal-currency').value   = currency;
        document.getElementById('modal-pay-amount').value = amount;
        document.getElementById('modal-grower-label').textContent = growerNum + ' — ' + growerName;
        const cur = currency.toUpperCase();
        const fmt = (cur === 'USD' ? '$' : cur + ' ') +
            parseFloat(amount).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('modal-amount-display').textContent = fmt;
        const form = document.getElementById('pay-form');
        form.reset();
        document.getElementById('modal-tgid').value       = tgid;
        document.getElementById('modal-date').value       = date;
        document.getElementById('modal-currency').value   = currency;
        document.getElementById('modal-pay-amount').value = amount;
        modal.style.display = 'flex';
        setTimeout(() => document.getElementById('modal-method')?.focus(), 60);

        // Replace form submit with AJAX
        form.onsubmit = async function (e) {
            e.preventDefault();
            const chk1 = document.getElementById('chk1');
            const chk2 = document.getElementById('chk2');
            if (chk1 && !chk1.checked) { toast('Please confirm: amount verified', 'warning'); return; }
            if (chk2 && !chk2.checked) { toast('Please confirm: grower signed / receipt issued', 'warning'); return; }
            const btn = form.querySelector('[type=submit]');
            spin(btn, true);
            const fd = new FormData(form);
            const data = Object.fromEntries(fd.entries());
            try {
                const res = await ajax('POST', 'confirm_payment', data);
                spin(btn, false);
                if (res.success) {
                    modal.style.display = 'none';
                    toast(res.msg, 'success');
                    // Update row in register table without reload
                    updatePaymentRow(tgid, 'paid', data.pay_method, data.pay_ref);
                } else {
                    toast(res.msg, 'error');
                }
            } catch (err) {
                spin(btn, false);
                toast('Network error. Please try again.', 'error');
            }
        };
    }

    // Update a row in the payment register after individual payment
    function updatePaymentRow(tgid, status, method, ref) {
        const row = document.querySelector(`tr[data-tgid="${tgid}"]`);
        if (!row) return;
        const statusCell = row.querySelector('[data-cell="status"]');
        const actionCell = row.querySelector('[data-cell="action"]');
        const methodCell = row.querySelector('[data-cell="method"]');
        if (statusCell) statusCell.innerHTML = STATUS_BADGE[status] || status;
        if (actionCell) actionCell.innerHTML = '<span style="font-size:.68rem;color:var(--leaf)">✓ Paid</span>';
        if (methodCell) methodCell.innerHTML = `<div>${method}</div>${ref ? `<div class="td-mono" style="font-size:.68rem">${ref}</div>` : ''}`;
        row.style.transition = 'background .4s';
        row.style.background = 'rgba(61,107,53,.08)';
        setTimeout(() => row.style.background = '', 1800);
    }

    // ── Batch workflow button ─────────────────────────────────────────────
    async function batchAction(bid, transition, date, btn) {
        const labels = { review:'Reviewed', approve:'Approved', release:'Released & growers marked Paid', lock:'Locked' };
        if (!confirm(`${labels[transition] || transition} batch #${bid}?`)) return;
        spin(btn, true);
        try {
            const res = await ajax('POST', 'batch_action', { bid, transition, sell_date: date });
            spin(btn, false);
            if (res.success) {
                toast(res.msg, 'success');
                updateBatchStatus(bid, res.data.status);
                updateBatchWorkflowButtons(bid, res.data.status);
            } else {
                toast(res.msg, 'error');
            }
        } catch (err) {
            spin(btn, false);
            toast('Network error.', 'error');
        }
    }

    function updateBatchStatus(bid, status) {
        document.querySelectorAll(`[data-batch-status="${bid}"]`).forEach(el => {
            el.innerHTML = STATUS_BADGE[status] || status;
        });
    }

    // Redraw approval step tracker and action buttons
    function updateBatchWorkflowButtons(bid, newStatus) {
        const order = ['draft','reviewed','approved','released','locked'];
        const curIdx = order.indexOf(newStatus);
        // Update step tracker circles
        document.querySelectorAll(`[data-workflow-batch="${bid}"] [data-step]`).forEach(el => {
            const stepIdx = parseInt(el.dataset.step);
            el.style.background = stepIdx <= curIdx ? 'var(--leaf)' : 'var(--border)';
            el.style.color       = stepIdx <= curIdx ? '#fff'        : 'var(--muted)';
            el.textContent       = stepIdx <= curIdx ? '✓' : (stepIdx + 1);
        });
        // Hide/show action buttons based on new status
        const btns = {
            'review_btn' : newStatus === 'draft',
            'approve_btn': newStatus === 'reviewed',
            'release_btn': newStatus === 'approved',
            'lock_btn'   : newStatus === 'released',
        };
        for (const [id, show] of Object.entries(btns)) {
            const b = document.getElementById(`${id}_${bid}`);
            if (b) b.style.display = show ? '' : 'none';
        }
    }

    // ── Batch summary live counter ────────────────────────────────────────
    function updateBatchSummary(containerId) {
        const container = document.getElementById(containerId || 'batch-grower-list');
        if (!container) return;
        const checks = container.querySelectorAll('.batch-chk:checked');
        let total = 0;
        checks.forEach(c => total += parseFloat(c.dataset.net || 0));
        const el = document.getElementById('batch-sum');
        if (!el) return;
        if (checks.length === 0) {
            el.innerHTML = '<span style="color:var(--muted)">No growers selected</span>';
            const btn = document.getElementById('batch-submit-btn');
            if (btn) btn.style.display = 'none';
        } else {
            el.innerHTML = `<strong>${checks.length} grower(s)</strong> &nbsp;·&nbsp; <strong>$${total.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})} USD</strong>`;
            const btn = document.getElementById('batch-submit-btn');
            if (btn) btn.style.display = '';
        }
    }

    // ── Submit batch via AJAX ─────────────────────────────────────────────
    async function submitBatch(date, submitBtnId) {
        const method   = document.getElementById('batch-method')?.value;
        const ref      = document.getElementById('batch-ref')?.value   || '';
        const notes    = document.getElementById('batch-notes')?.value  || '';
        const currency = document.getElementById('batch-currency')?.value || 'usd';
        const checks   = document.querySelectorAll('.batch-chk:checked');

        if (!method) { toast('Select a payment method first', 'warning'); return; }
        if (!checks.length) { toast('Select at least one grower', 'warning'); return; }

        const tgids = Array.from(checks).map(c => c.dataset.tgid);
        const btn   = document.getElementById(submitBtnId || 'batch-submit-btn');

        if (!confirm(`Create batch for ${tgids.length} grower(s) via ${method}?`)) return;

        spin(btn, true);
        try {
            const res = await ajax('POST', 'create_batch', {
                sell_date: date, batch_method: method, batch_ref: ref,
                batch_notes: notes, currency, batch_tgids: tgids,
            });
            spin(btn, false);
            if (res.success) {
                toast(res.msg, 'success');
                // Refresh batch list
                refreshBatchList(date);
                // Reset selections
                checks.forEach(c => c.checked = false);
                updateBatchSummary();
                // Show new batch indicator
                if (res.data.batch_id) {
                    setTimeout(() => {
                        const el = document.querySelector(`[data-batch-id="${res.data.batch_id}"]`);
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 600);
                }
            } else {
                toast(res.msg, 'error');
            }
        } catch (err) {
            spin(btn, false);
            toast('Network error.', 'error');
        }
    }

    // ── Refresh batch list panel ──────────────────────────────────────────
    async function refreshBatchList(date) {
        try {
            const res = await ajax('GET', 'get_batches', { date });
            if (!res.success) return;
            const container = document.getElementById('batch-list-container');
            if (!container) return;
            if (!res.data.batches.length) {
                container.innerHTML = '<div class="card-body text-center text-muted" style="padding:2rem">No batches yet.</div>';
                return;
            }
            const status_badge = (s) => STATUS_BADGE[s] || s;
            container.innerHTML = res.data.batches.map(b => `
                <tr data-batch-id="${b.id}" style="cursor:pointer" onclick="location='pay_batches.php?date=${date}&bid=${b.id}'">
                  <td><span class="badge badge-blue" style="font-size:.62rem">#${b.id}</span></td>
                  <td style="font-size:.8rem">${escHtml(b.payment_method)}</td>
                  <td><span data-batch-status="${b.id}">${status_badge(b.status)}</span></td>
                  <td class="td-mono text-right">${b.cnt}</td>
                  <td class="td-mono text-right fw-500">$${parseFloat(b.total).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                  <td onclick="event.stopPropagation()"><a href="pay_batches.php?date=${date}&bid=${b.id}" class="btn btn-ghost btn-sm">View</a></td>
                </tr>
            `).join('');
        } catch(e) { /* silent */ }
    }

    // ── Refresh dashboard KPIs ────────────────────────────────────────────
    async function refreshDashboard(date) {
        try {
            const res = await ajax('GET', 'get_dashboard', { date });
            if (!res.success) return;
            const d = res.data;
            setText('kpi-payable-usd',   '$' + d.payable_usd.toLocaleString('en',{minimumFractionDigits:2}));
            setText('kpi-payable-growers', d.payable_growers + ' growers');
            setText('kpi-paid-usd',      '$' + d.paid_usd.toLocaleString('en',{minimumFractionDigits:2}));
            setText('kpi-paid-growers',  d.paid_growers + ' growers');
            setText('kpi-pending-usd',   '$' + d.pending_usd.toLocaleString('en',{minimumFractionDigits:2}));
        } catch(e) { /* silent */ }
    }

    function setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Auto-refresh dashboard every 30s if on dashboard page ────────────
    if (document.body.classList.contains('page-pay_dashboard')) {
        const d = new URLSearchParams(location.search).get('date') || new Date().toISOString().slice(0,10);
        setInterval(() => refreshDashboard(d), 30000);
    }

    return {
        toast, spin, ajax,
        confirmPayment, batchAction, submitBatch,
        updateBatchSummary, refreshBatchList, refreshDashboard,
        STATUS_BADGE, updatePaymentRow,
    };
})();
