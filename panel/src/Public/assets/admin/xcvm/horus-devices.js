(function () {
    'use strict';
    window.HorusDevices = {
        async matchingCodes(request, criteria, pageSize = 500) {
            const ids = new Set(); let start = 0; let total = null;
            do {
                let page = await request(Object.assign({}, criteria, {id:'active_codes', start, length:pageSize}));
                if (typeof page === 'string') page = JSON.parse(page);
                if (!page || !Array.isArray(page.data)) throw new Error('Invalid code list');
                const count = Number(page.recordsFiltered);
                if (!Number.isSafeInteger(count) || count < 0 || (total !== null && total !== count)) throw new Error('Code list changed; refresh and retry');
                total = count;
                if (!page.data.length && start < total) throw new Error('Incomplete code list');
                for (const row of page.data) {
                    const id = Number(row.id);
                    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid code identifier');
                    ids.add(id);
                }
                start += page.data.length;
            } while (start < total);
            if (ids.size !== total) throw new Error('Code list changed; refresh and retry');
            return Array.from(ids);
        }
    };
    document.addEventListener('DOMContentLoaded', function () {
        const ar = document.documentElement.dir === 'rtl' || document.documentElement.lang.startsWith('ar');
        const text = (a, e) => ar ? a : e;
        const make = (tag, cls, value) => {const el=document.createElement(tag);el.className=cls;if(value)el.textContent=value;return el;};
        document.querySelectorAll('.horus-device-workspace').forEach(card => {
            const header=card.querySelector('.card-header'), title=header.querySelector('h5');
            const mag=!!card.querySelector('#mags-table'), enigma=!!card.querySelector('#e2-table');
            const intro=make('div','xc-workspace-intro');
            intro.append(make('span','xc-workspace-eyebrow',mag?'HORUS · MAG':enigma?'HORUS · ENIGMA2':text('حورس · الأكواد النشطة','HORUS · ACTIVATION CODES')));
            intro.append(title);intro.append(make('p','xc-workspace-caption',mag||enigma?text('إدارة الأجهزة والاشتراكات والربط وحدود الوصول.','Manage devices, subscriptions, pairing and access limits.'):text('المخزون والتفعيل والدفعات وإدارة الوصول في مكان واحد.','Stock, activation, batches and access controls in one workspace.')));header.prepend(intro);
            const bulk=card.querySelector('#bulk-bar');if(bulk){const zone=make('div','horus-device-bulk');header.after(zone);zone.append(bulk);}
        });
        const form=document.querySelector('.horus-device-editor');
        if(form){
            const summary=make('div','horus-device-summary');const state=make('span','horus-device-pair-state');state.setAttribute('role','status');
            summary.append(make('strong','',form.id==='mag-form'?'HORUS · MAG':'HORUS · ENIGMA2'),state);form.prepend(summary);
            const pair=form.querySelector('#pair_id');
            const sync=()=>{state.textContent=pair?.value?text('مرتبط باشتراك — تُستخدم إعدادات الحساب المرتبط','Paired subscription — access follows the linked account'):text('جهاز مستقل — راجع الصلاحية والباقات قبل الحفظ','Standalone device — review expiry and bouquets before saving');};
            if(pair){pair.addEventListener('change',sync);if(window.jQuery)jQuery(pair).on('change.horusDevice',sync);}sync();
            const mac=form.querySelector('#mac');if(mac){mac.dir='ltr';mac.autocomplete='off';mac.spellcheck=false;}
        }
        const generator=document.getElementById('admin-active-code-form');
        if(generator){
            const summary=make('div','horus-generation-summary');summary.setAttribute('role','status');generator.prepend(summary);
            const sync=()=>{const qty=generator.querySelector('#num_codes');const pkg=generator.querySelector('#package_id');summary.textContent=text('الكمية: ','Quantity: ')+(qty?.value||'0')+' · '+(pkg?.selectedOptions[0]?.textContent||text('اختر الباقة','Choose a package'));};
            generator.addEventListener('input',sync);generator.addEventListener('change',sync);generator.addEventListener('click',()=>queueMicrotask(sync));generator.addEventListener('reset',()=>setTimeout(sync,0));if(window.jQuery)jQuery('#package_id').on('change.horusSummary',sync);sync();
        }
    });
})();
