(() => {
    'use strict';
    const form = document.getElementById('settings-form');
    if (!form || form.dataset.horusOptionsBound) return;
    form.dataset.horusOptionsBound = '1';
    let pools;
    function hydrate(select) {
        if (!(select instanceof HTMLSelectElement) || select.dataset.optionsReady) return;
        const source = document.getElementById('horus-settings-options');
        if (!source) return;
        try { pools ??= JSON.parse(source.textContent); } catch (_) { return; }
        const rows = pools[select.dataset.horusOptions];
        if (!Array.isArray(rows)) return;
        const selected = new Map(Array.from(select.selectedOptions, option => [option.value, option.text]));
        const defaults = new Set(Array.from(select.options).filter(option => option.defaultSelected).map(option => option.value));
        const fragment = document.createDocumentFragment();
        for (const row of rows) {
            const value = String(row.id);
            fragment.append(new Option(String(row.text), value, defaults.has(value), selected.has(value)));
        }
        // Keep a saved reference even if its category or bouquet has since been removed.
        const available = new Set(rows.map(row => String(row.id)));
        for (const [value, text] of selected) if (!available.has(value)) fragment.append(new Option(text, value, defaults.has(value), true));
        select.replaceChildren(fragment);
        select.dataset.optionsReady = '1';
        if (window.jQuery && window.jQuery(select).data('select2')) window.jQuery(select).trigger('change.select2');
    }
    for (const name of ['focusin', 'pointerdown']) form.addEventListener(name, event => {
        const select = event.target.closest('select[data-horus-options]');
        if (select) hydrate(select);
    }, true);
    if (window.jQuery) window.jQuery(form).on('select2:opening.horusOptions', 'select[data-horus-options]', function () { hydrate(this); });
})();
