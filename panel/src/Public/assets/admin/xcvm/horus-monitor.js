(function () {
    'use strict';
    // The scheduler accepts an asynchronous refresh and never overlaps its own requests.
    function scheduler(refresh, allowed, clock = window, delay = 5000) {
        let timer, busy = false, paused = false, stopped = false;
        const plan = () => { if (!stopped) timer = clock.setTimeout(tick, delay); };
        async function tick() {
            if (stopped || busy) return;
            busy = true;
            try { if (!paused && allowed()) await refresh(); }
            catch (_) { /* The table's error handler presents a retryable status. */ }
            finally { busy = false; plan(); }
        }
        plan();
        return {pause(value) { paused = value; }, stop() {stopped = true; clock.clearTimeout(timer);}};
    }
    window.HorusMonitor = {scheduler};
    document.addEventListener('DOMContentLoaded', function () {
        const root = document.querySelector('.horus-monitor');
        if (!root || !window.jQuery) return;
        const $ = window.jQuery;
        const ar = document.documentElement.dir === 'rtl';
        const t = (a, e) => ar ? a : e;
        const status = root.querySelector('[data-monitor-status]');
        const updated = root.querySelector('[data-monitor-time]');
        const filtered = root.querySelector('[data-monitor-filtered]');
        const shown = root.querySelector('[data-monitor-shown]');
        const search = root.querySelector('[data-monitor-search]');
        const oldRefresh = document.getElementById('refreshTable');
        const tools = oldRefresh && oldRefresh.closest('.btn-group');
        if (tools) {
            const parent = tools.parentElement;
            oldRefresh.remove();
            root.querySelector('.horus-monitor-controls').append(tools);
            if (!parent.children.length) parent.remove();
        }
        const tables = Array.from(root.querySelectorAll('table')).filter(el => $.fn.dataTable.isDataTable(el));
        const el = tables[0];
        if (!el) {
            status.textContent = t('لا توجد بيانات للعرض', 'No data to display');
            filtered.textContent = shown.textContent = '0';
            updated.textContent = new Date().toLocaleTimeString();
            search.disabled = true;
            root.querySelector('[data-monitor-refresh]').addEventListener('click', () => window.location.reload());
            return;
        }
        const table = $(el).DataTable();
        let busy = false, failed = false, pending = false;
        const update = () => {
            if (table.settings()[0].ajax && !table.ajax.json()) return;
            const info = table.page.info();
            filtered.textContent = Number(info.recordsDisplay).toLocaleString();
            shown.textContent = Math.max(0, info.end - info.start).toLocaleString();
            if (!failed) status.textContent = t('جاهز', 'Ready');
        };
        const success = () => {
            busy = false; failed = false;
            updated.textContent = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
            update();
            if (pending) { pending = false; queueMicrotask(() => { void reload(); }); }
        };
        $(el).on('preXhr.dt.horus', function () { busy = true; status.textContent = t('جارٍ التحديث…', 'Updating…'); });
        $(el).on('xhr.dt.horus', function (_, settings, json) {
            if (!json) { busy = false; failed = true; pending = false; status.textContent = t('تعذر التحديث — أعد المحاولة', 'Update failed — retry'); return true; }
            success();
        });
        $(el).on('draw.dt.horus', update);
        if (!table.settings()[0].ajax || table.ajax.json()) success(); else update();
        // Replace the duplicate built-in search with the shared debounced field.
        root.querySelectorAll('.dt-search').forEach(node => { node.hidden = true; });
        const searchControl = window.HorusSubscriptions.bindSearch(search, value => table.search(value).draw());
        const reload = (manual = true) => new Promise(resolve => {
            const settings = table.settings()[0];
            if (busy || (settings.jqXHR && settings.jqXHR.readyState !== 4)) { if (manual) pending = true; resolve(); return; }
            if (!settings.ajax) { window.location.reload(); resolve(); return; }
            const settle = () => { $(el).off('xhr.dt.horusWait', settle); resolve(); };
            $(el).one('xhr.dt.horusWait', settle);
            table.ajax.reload(null, false);
        });
        root.querySelector('[data-monitor-refresh]').addEventListener('click', () => { void reload(); });
        const clear = document.getElementById('clearFilters');
        if (clear) clear.addEventListener('click', event => {
            event.stopPropagation();
            searchControl.reset();
            root.querySelectorAll('[id^="filter-"]').forEach(input => {
                if (input._flatpickr) input._flatpickr.clear(false);
                input.value = '';
                if (input.tagName === 'SELECT') $(input).trigger('change.select2');
            });
            table.search(''); table.page('first'); void reload();
        });
        const live = root.dataset.monitorPage === 'live_connections';
        const pause = root.querySelector('[data-monitor-pause]');
        pause.hidden = !live;
        if (live) {
            const runner = scheduler(() => reload(false), () => !document.hidden && !document.querySelector('.modal.show') && !root.querySelector('tbody .dropdown-menu.show'));
            pause.addEventListener('click', function () {
                const value = this.getAttribute('aria-pressed') !== 'true';
                this.setAttribute('aria-pressed', String(value)); runner.pause(value);
                this.textContent = value ? t('استئناف التحديث', 'Resume updates') : t('إيقاف التحديث', 'Pause updates');
            });
            window.addEventListener('pagehide', () => runner.stop(), {once:true});
            window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
        }
    });
})();
