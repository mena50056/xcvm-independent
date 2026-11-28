(function () {
    'use strict';
    var config = window.horusServerProxy;
    if (!config) return;
    var ar = document.documentElement.lang.indexOf('ar') === 0 || document.documentElement.dir === 'rtl';
    function t(a, e) { return ar ? a : e; }
    var box = document.getElementById('server-proxy-feedback'), busy = false;
    function notice(text, kind) {
        box.className = 'alert alert-' + (kind || 'info') + ' my-3';
        box.textContent = text;
    }
    function request(ids, sub, proxy) {
        var url = './api?action=multi&type=server&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids));
        var options = {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'};
        if (sub !== 'proxy_status') {
            var body = new URLSearchParams({csrf: config.csrf});
            if (proxy) body.set('proxy_id', proxy);
            options.method = 'POST'; options.body = body;
        }
        return fetch(url, options).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || d.result !== true) throw new Error(d && d.error || 'PROXY_OPERATION_FAILED');
            return d;
        });
    }
    function paint(data) {
        (data.servers || []).forEach(function (s) {
            var icon = document.querySelector('[data-proxy-state="' + s.id + '"]');
            if (!icon) return;
            icon.classList.remove('text-success', 'text-secondary', 'text-warning', 'text-danger');
            icon.classList.add('text-' + ({applied: 'success', disabled: 'secondary', pending: 'warning', unavailable: 'danger'}[s.state] || 'danger'));
            icon.title = {applied: t('البروكسي مطبّق', 'Proxy applied'), disabled: t('البروكسي متوقف', 'Proxy disabled'), pending: t('بانتظار تأكيد الوكيل', 'Waiting for agent acknowledgement'), unavailable: t('الربط غير مكتمل أو البروكسي غير متاح', 'Binding incomplete or proxy unavailable')}[s.state];
            icon.setAttribute('aria-label', icon.title);
        });
    }
    function errorText(error) {
        var code = error.message;
        if (code.indexOf('UPSTREAM_TLS_UNTRUSTED') === 0) return t('تعذّر توثيق HTTPS للسيرفر. أضف شهادته العامة إلى إعدادات السيرفر الخلفي في البروكسي، ثم أعد المحاولة. ', 'Cannot verify upstream HTTPS. Add its public certificate in the proxy upstream settings, then retry. ') + code;
        if (code.indexOf('UPSTREAM_REQUIRES_HTTPS') === 0) return t('السيرفر يجب أن يكون مفعّلًا وله بورت HTTPS. ', 'The server must be enabled with an HTTPS port. ') + code;
        if (code === 'NO_ENABLED_PROXY') return t('لا يوجد بروكسي مفعّل ومجهّز. جهّزه من صفحة البروكسي أولًا.', 'No enabled, configured proxy. Configure one on the Proxies page first.');
        if (code === 'PROFILE_CONFLICT') return t('تم تعديل إعدادات البروكسي من جلسة أخرى. حدّث الصفحة وحاول مجددًا.', 'Proxy settings changed in another session. Refresh and retry.');
        return t('تعذّر إكمال العملية: ', 'Operation failed: ') + code;
    }
    async function choose(data) {
        if (!window.Swal) throw new Error('SELECT_PROXY_ON_PROXIES_PAGE');
        var opts = {};
        data.candidates.forEach(function (p) { opts[p.id] = p.name; });
        var result = await window.Swal.fire({title: t('اختر البروكسي', 'Choose proxy'), input: 'select', inputOptions: opts, showCancelButton: true, confirmButtonText: t('تفعيل', 'Enable'), cancelButtonText: t('إلغاء', 'Cancel')});
        return result.isConfirmed ? Number(result.value) : null;
    }
    window.horusChangeServerProxy = async function (ids, sub) {
        if (busy) return;
        busy = true;
        notice(t('جارٍ التحقق من الاتصال الآمن وتجهيز الربط…', 'Verifying upstream TLS and preparing proxy binding…'));
        try {
            var data = await request(ids, sub);
            if (data.state === 'choose_proxy') {
                var proxy = await choose(data);
                if (!proxy) { box.className = 'd-none'; return; }
                data = await request(ids, sub, proxy);
            }
            paint(data);
            var deadline = Date.now() + 45000;
            while (data.state === 'pending' && Date.now() < deadline) {
                notice(t('تم حفظ الربط. بانتظار تأكيد تطبيقه من وكيل البروكسي…', 'Binding saved. Waiting for the proxy agent to confirm application…'), 'warning');
                await new Promise(function (resolve) { setTimeout(resolve, 2000); });
                data = await request(ids, 'proxy_status'); paint(data);
                var failed = (data.servers || []).flatMap(function (s) { return s.proxies; }).find(function (p) { return ['failed', 'offline', 'not_connected'].indexOf(p.status.state) >= 0; });
                if (failed) throw new Error('PROXY_AGENT_' + failed.status.state.toUpperCase());
            }
            if (data.state === 'pending') { notice(t('الطلب محفوظ ولم يؤكّد الوكيل تطبيقه بعد. راجع مركز مراقبة البروكسي؛ لا يُعد مفعّلًا حتى وصول التأكيد.', 'Request saved; the agent has not confirmed it yet. Check Proxy Monitoring. It is not active until acknowledged.'), 'warning'); return; }
            if (data.state !== 'applied') throw new Error('PROXY_NOT_AVAILABLE');
            if ((data.servers || []).some(function (s) { return s.state !== (sub === 'disable_proxy' ? 'disabled' : 'applied'); })) throw new Error('PROXY_REQUEST_CHANGED');
            notice(sub === 'disable_proxy' ? t('تم تعطيل تحويل الاتصالات الجديدة عبر البروكسي مع الحفاظ على المشاهدات الحالية.', 'Proxy redirects disabled for new connections; existing viewers are preserved.') : t('تم تطبيق الربط وتفعيل البروكسي على السيرفرات المحددة.', 'Proxy binding applied and enabled for the selected servers.'), 'success');
            setTimeout(function () { location.reload(); }, 1600);
        } catch (error) { notice(errorText(error), 'danger'); }
        finally { busy = false; }
    };
    var ids = Array.from(document.querySelectorAll('[data-proxy-state]')).map(function (el) { return Number(el.dataset.proxyState); });
    if (ids.length && config.canEdit) {
        function watch(batch, remaining) {
            request(batch, 'proxy_status').then(function (data) { paint(data); if (data.state === 'pending' && remaining > 0) setTimeout(function () { watch(batch, remaining - 1); }, 2000); }).catch(function () {});
        }
        for (var i = 0; i < ids.length; i += 100) watch(ids.slice(i, i + 100), 22);
    }
}());
