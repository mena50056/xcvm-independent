/* Horus reseller and access-control screens. All changes remain in the original forms. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    const ar = document.documentElement.dir === 'rtl' || document.documentElement.lang.startsWith('ar');
    const words = (arabic, english) => ar ? arabic : english;
    const make = (tag, cls, text) => { const el = document.createElement(tag); el.className = cls; if (text) el.textContent = text; return el; };
    document.querySelectorAll('.horus-resellers-workspace').forEach(card => {
        const groups = !!card.querySelector('#groups-table');
        card.querySelector('.xc-workspace-eyebrow').textContent = groups ? words('حورس · المجموعات والصلاحيات', 'HORUS · ACCESS CONTROL') : words('حورس · إدارة الموزعين', 'HORUS · RESELLERS');
        card.querySelector('.xc-workspace-caption').textContent = groups ? words('راجع صلاحيات كل مجموعة والباقات المتاحة لها من مكان واحد.', 'Review each group’s capabilities and package access in one workspace.') : words('الحسابات والأرصدة والملكية وحالة الوصول في مكان واحد.', 'Accounts, balances, ownership and access status in one workspace.');
    });
    const form = document.querySelector('.horus-reseller-editor');
    if (!form) return;
    const group = form.id === 'group-form';
    const summary = make('section', 'horus-access-summary');
    summary.setAttribute('aria-label', words('ملخص الإعدادات', 'Configuration summary'));
    const name = make('strong', 'horus-access-summary-name');
    const detail = make('span', 'horus-access-summary-detail'); detail.setAttribute('role', 'status');
    summary.append(make('span', 'horus-access-emblem', group ? '◇' : '◎'), name, detail); form.prepend(summary);
    const warning = make('div', 'alert alert-warning horus-full-access-note', words('مجموعة مسؤول بلا صلاحيات محددة تعني وصولًا كاملًا حسب قواعد البانل. اختر الصلاحيات المطلوبة لحصر الوصول.', 'An administrator group with no selected permissions has full access under the panel’s rules. Select specific permissions to limit access.'));
    warning.hidden = true;
    if (group) document.querySelector('#tab-admin')?.prepend(warning);
    const refresh = () => {
        const field = form.querySelector(group ? '#group_name' : '#username');
        name.textContent = field.value || words('حساب جديد', 'New account');
        if (group) {
            const admin = !!form.querySelector('#is_admin')?.checked;
            const reseller = !!form.querySelector('#is_reseller')?.checked;
            const permissions = form.querySelectorAll('.group-permission-cb:checked').length;
            detail.textContent = [admin ? words('مسؤول', 'Administrator') : '', reseller ? words('موزع', 'Reseller') : '', words('الصلاحيات المحددة: ', 'Selected permissions: ') + permissions].filter(Boolean).join(' · ');
            warning.hidden = !admin || permissions > 0;
        } else {
            const select = form.querySelector('#member_group_id');
            detail.textContent = select?.selectedOptions[0]?.textContent || '';
        }
    };
    form.addEventListener('input', refresh); form.addEventListener('change', refresh);
    form.addEventListener('click', event => { if (event.target.closest('button')) queueMicrotask(refresh); }); refresh();
    // These controls operate only on visible, enabled rows, preserving off-screen choices.
    form.querySelectorAll('.horus-selection-tools').forEach(toolbar => {
        const table = toolbar.nextElementSibling?.querySelector('table'); if (!table) return;
        const controls = make('div', 'horus-visible-actions');
        for (const [value, label] of [[true, words('تحديد الظاهر', 'Select visible')], [false, words('إلغاء الظاهر', 'Clear visible')]]) {
            const button = make('button', 'btn btn-sm btn-label-secondary', label); button.type = 'button';
            button.addEventListener('click', () => {
                table.querySelectorAll('tbody tr:not([hidden]) input[type=checkbox]:not(:disabled)').forEach(input => { input.checked = value; });
                form.dispatchEvent(new Event('change', {bubbles:true}));
            }); controls.append(button);
        }
        toolbar.append(controls);
    });
    // Restricting to an empty set means no bouquet access; never fill a selection automatically.
    const limit = form.querySelector('#bouquet_limit_enabled');
    const allowed = form.querySelector('#allowed_bouquets');
    if (limit && allowed) {
        const status = make('p', 'horus-bouquet-limit-status'); status.setAttribute('role', 'status'); allowed.after(status);
        const update = () => {
            const restricted = limit.value === '1';
            allowed.disabled = !restricted;
            status.textContent = restricted ? words('الباقات المسموحة: ', 'Allowed bouquets: ') + allowed.selectedOptions.length : words('الوصول إلى باقات الحزم المخصصة للمجموعة.', 'Access follows the packages assigned to the group.');
        };
        limit.addEventListener('change', update); allowed.addEventListener('change', update); update();
        if (window.jQuery?.fn.select2) window.jQuery(allowed).select2({width:'100%', closeOnSelect:false}).on('change.horusLimit', update);
    }
});
