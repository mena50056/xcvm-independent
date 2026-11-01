/* Horus subscription workspaces. Original form names and save handlers remain authoritative. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        const ar = document.documentElement.dir === 'rtl' || document.documentElement.lang.startsWith('ar');
        const text = (arabic, english) => ar ? arabic : english;
        const make = (tag, cls, value) => {
            const el = document.createElement(tag);
            el.className = cls;
            if (value) el.textContent = value;
            return el;
        };
        document.querySelectorAll('.horus-access-workspace').forEach(card => {
            if (!ar) return;
            const packages = !!card.querySelector('#packages-table');
            card.querySelector('.xc-workspace-eyebrow').textContent = packages ? 'حورس · باقات الاشتراك' : 'حورس · باقات المحتوى';
            card.querySelector('.xc-workspace-caption').textContent = packages ? 'حدّد المدد والتكلفة والصلاحيات ومجموعات الموزعين.' : 'اجمع البث والأفلام والمسلسلات والإذاعات في باقات وصول واضحة.';
        });
        const form = document.querySelector('.horus-subscription-editor');
        if (!form) return;
        const nav = form.querySelector('[role=tablist]');
        if (nav) {
            const mobile = window.matchMedia('(max-width:767px)');
            const orientation = () => nav.setAttribute('aria-orientation', mobile.matches ? 'horizontal' : 'vertical');
            orientation();
            mobile.addEventListener('change', orientation);
            nav.querySelectorAll('[role=tab]').forEach((tab, index) => {
                if (!tab.querySelector('.xc-step-number')) tab.prepend(make('span', 'xc-step-number', String(index + 1).padStart(2, '0')));
            });
        }
        // Native required validation must bring the invalid field's tab into view.
        form.addEventListener('invalid', event => {
            const pane = event.target.closest('.tab-pane');
            const tab = pane && nav && nav.querySelector('[data-bs-target="#' + pane.id + '"]');
            if (tab && !tab.classList.contains('active') && window.bootstrap) {
                tab.addEventListener('shown.bs.tab', () => event.target.focus(), {once:true});
                bootstrap.Tab.getOrCreateInstance(tab).show();
            }
        }, true);
        const savebar = form.querySelector('.xc-editor-savebar');
        if (savebar) {
            if (!savebar.querySelector('.xc-editor-save-note')) savebar.prepend(make('span', 'xc-editor-save-note', text('راجع الإعدادات ثم احفظ التغييرات', 'Review settings, then save changes')));
            const destination = form.id === 'line-form' ? 'lines' : form.id === 'package-form' ? 'packages' : 'bouquets';
            const back = make('a', 'btn btn-label-secondary', text('العودة للقائمة', 'Back to list'));
            back.href = destination;
            if (window.parent === window) savebar.insertBefore(back, savebar.querySelector('[type=submit]'));
        }
        const password = form.querySelector('#password');
        if (password) {
            const box = make('div', 'input-group');
            password.before(box); box.append(password);
            const reveal = make('button', 'btn btn-label-secondary', text('إظهار', 'Show'));
            reveal.type = 'button'; reveal.setAttribute('aria-controls', password.id); reveal.setAttribute('aria-pressed', 'false');
            reveal.addEventListener('click', () => {
                const visible = password.type === 'password';
                password.type = visible ? 'text' : 'password';
                reveal.textContent = visible ? text('إخفاء', 'Hide') : text('إظهار', 'Show');
                reveal.setAttribute('aria-pressed', String(visible));
            });
            box.append(reveal);
        }
        // Filtering never detaches rows: checked ids and saved bouquet order survive a search.
        for (const cls of ['line-bouquet-cb', 'package-bouquet-cb', 'package-group-cb']) {
            const first = form.querySelector('.' + cls);
            if (!first) continue;
            const table = first.closest('table');
            const rows = Array.from(table.tBodies[0].rows);
            const toolbar = make('div', 'horus-selection-tools');
            const input = make('input', 'form-control'); input.type = 'search';
            input.placeholder = text('ابحث بالاسم أو الرقم…', 'Find by name or ID…'); input.setAttribute('aria-label', input.placeholder);
            const only = make('label', 'horus-selected-only');
            const checkbox = make('input', 'form-check-input'); checkbox.type = 'checkbox';
            only.append(checkbox, document.createTextNode(text('المحدد فقط', 'Selected only')));
            const counter = make('span', 'horus-selection-count'); counter.setAttribute('role', 'status');
            toolbar.append(input, only, counter); table.closest('.table-responsive').before(toolbar);
            const empty = make('p', 'horus-selection-empty', text('لا توجد نتائج مطابقة. غيّر البحث أو ألغِ عرض المحدد فقط.', 'No matches. Change the search or turn off Selected only.'));
            empty.hidden = true; table.closest('.table-responsive').after(empty);
            const sync = () => {
                const query = input.value.trim().toLocaleLowerCase(); let visible = 0; let selected = 0;
                for (const row of rows) {
                    const checked = row.querySelector('.' + cls).checked;
                    if (checked) selected++;
                    row.hidden = !row.textContent.toLocaleLowerCase().includes(query) || (checkbox.checked && !checked);
                    if (!row.hidden) visible++;
                }
                counter.textContent = text('المحدد: ', 'Selected: ') + selected + ' / ' + rows.length;
                empty.hidden = visible > 0;
            };
            input.addEventListener('input', sync); checkbox.addEventListener('change', sync);
            form.addEventListener('change', sync);
            form.addEventListener('click', event => { if (event.target.closest('button')) queueMicrotask(sync); });
            sync();
        }
    });
})();
