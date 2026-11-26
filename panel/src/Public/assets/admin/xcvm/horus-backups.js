document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const root = document.querySelector('.horus-backups');
    if (!root) return;
    const text = (ar, en) => root.dataset.ar === '1' ? ar : en;
    const escape = value => { const el = document.createElement('span'); el.textContent = String(value ?? ''); return el.innerHTML.replace(/"/g, '&quot;'); };
    const errorText = text('تعذر تنفيذ الطلب. راجع حالة النسخ وأعد المحاولة.', 'Request failed. Check backup status and retry.');
    async function request(sub, filename = '') {
        const response = await fetch('./api?action=backup', {
            method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'},
            body:new URLSearchParams({sub, filename, csrf:root.dataset.csrf})
        });
        const data = await response.json();
        if (!response.ok || data.result !== true) throw new Error(errorText);
        return data;
    }
    const labels = {verified:text('البصمة سليمة','Checksum verified'), failed:text('فشل التحقق','Verification failed'), legacy:text('بدون بصمة مسجلة','No recorded checksum')};
    const table = jQuery('#backups-table').DataTable({
        serverSide:true, processing:true, responsive:false, paging:false, ordering:false, searching:false, info:false,
        ajax:{url:'./table', data:params => {params.id='backups';}},
        createdRow(row) {
            const labels=[text('التاريخ','Date'),text('اسم الملف','Filename'),text('الحجم','Size'),text('التحقق','Verification'),text('نسخة بعيدة','Remote'),text('الإجراءات','Actions')];
            Array.from(row.cells).forEach((cell,index)=>{cell.dataset.label=labels[index];});
        },
        columns:[
            {data:'date', render:escape},
            {data:'filename', render:value => '<span class="hb-filename" dir="ltr">' + escape(value) + '</span>'},
            {data:'size', render:escape},
            {data:null, render:(_, type, row) => escape(row.local ? labels[row.integrity || 'legacy'] : text('بعيدة فقط','Remote only'))},
            {data:'remote', render:value => escape(value === 'yes' ? text('موجودة','Available') : value === 'error' ? text('فشل الرفع','Upload failed') : value === 'uploading' ? text('جارٍ الرفع','Uploading') : '—')},
            {data:null, render:(_, type, row) => {
                const name=escape(row.filename);
                const download=row.local ? '<a class="btn btn-sm btn-label-primary" href="./api?action=backup&amp;sub=download&amp;filename='+encodeURIComponent(row.filename)+'">'+text('تنزيل','Download')+'</a> ' : '';
                return '<div class="hb-actions">'+download+'<button class="btn btn-sm btn-label-secondary" data-action="restore" data-name="'+name+'" '+(row.integrity==='failed'?'disabled':'')+'>'+text('استعادة','Restore')+'</button> <button class="btn btn-sm btn-label-danger" data-action="delete" data-name="'+name+'">'+text('حذف','Delete')+'</button></div>';
            }}
        ], layout:{topStart:null,topEnd:null,bottomStart:null,bottomEnd:null}
    });
    let timer, waitingUntil=0, awaitingRun=false, startedAt=0, previousStatus='', submittedStatus='';
    const create = document.getElementById('create-backup');
    async function status() {
        try {
            const {status:data} = await request('status');
            const states={running:text('جارٍ النسخ','Running'),success:text('نجح النسخ والتحقق','Backup verified'),failed:text('فشل النسخ','Backup failed'),restore_failed:text('تعذرت الاستعادة — راجع نسخة الأمان','Restore failed — check safety copy')};
            document.getElementById('hb-status').textContent=states[data.state] || text('لم يتم تشغيل النسخ بعد','No backup run recorded');
            document.getElementById('hb-time').textContent=data.time ? new Date(data.time*1000).toLocaleString() : '';
            const current=JSON.stringify(data);
            if (current!==previousStatus) {table.ajax.reload(null,false);previousStatus=current;}
            if (awaitingRun && data.time>=startedAt && current!==submittedStatus && data.state!=='running') awaitingRun=false;
            create.disabled=data.state==='running' || awaitingRun;
            if (data.state==='running' || awaitingRun) {
                if (Date.now()<waitingUntil) timer=setTimeout(status,3000);
                else {create.disabled=false;awaitingRun=false;document.getElementById('hb-status').textContent=text('التشغيل يستغرق وقتًا — حدّث الصفحة لمتابعة الحالة','Still waiting — reload to check status');}
            }
        } catch (_) {create.disabled=false;awaitingRun=false;document.getElementById('hb-status').textContent=errorText;}
    }
    waitingUntil=Date.now()+30*60*1000;
    void status();
    create.addEventListener('click', async () => {
        submittedStatus=previousStatus;create.disabled=true; startedAt=Math.floor(Date.now()/1000);awaitingRun=true;waitingUntil=Date.now()+30*60*1000;
        try {await request('backup');clearTimeout(timer);timer=setTimeout(status,1000);}
        catch (_) {create.disabled=false;awaitingRun=false;xcToast(errorText,'error');}
    });
    root.addEventListener('click', async event => {
        const button=event.target.closest('[data-action]');
        if (!button || button.disabled) return;
        button.disabled=true;
        try {
            const message=button.dataset.action==='restore' ? text('استعادة هذه النسخة ستستبدل قاعدة البيانات الحالية بعد أخذ نسخة أمان. هل تريد المتابعة؟','Restore replaces the current database after creating a safety copy. Continue?') : text('حذف هذه النسخة؟','Delete this backup?');
            if (!await xcConfirm(message)) return;
            await request(button.dataset.action,button.dataset.name);
            if (button.dataset.action==='restore') {window.location.href='./login';return;}
            table.ajax.reload(null,false);
        } catch (_) {xcToast(errorText,'error');}
        finally {button.disabled=false;}
    });
    document.getElementById('backup-settings').addEventListener('submit', async event => {
        event.preventDefault();const button=document.getElementById('save-settings');button.disabled=true;
        try {
            const response=await fetch('./post.php?action=backups',{method:'POST',body:new FormData(event.target),headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data=await response.json();if (!response.ok || data.result!==true) throw new Error();
            document.getElementById('hb-schedule').textContent=document.getElementById('automatic_backups').selectedOptions[0].textContent;
            xcToast(text('تم حفظ الجدولة','Schedule saved'),'success');
        } catch (_) {xcToast(errorText,'error');}
        finally {button.disabled=false;}
    });
    window.addEventListener('pagehide',()=>clearTimeout(timer),{once:true});
});
