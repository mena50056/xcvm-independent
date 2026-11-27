(()=>{'use strict';
 const root=document.getElementById('horus-proxy-monitor');if(!root)return;
 const data=JSON.parse(document.getElementById('hpm-data').textContent),t=(en,ar)=>data.ar?ar:en,$=id=>document.getElementById('hpm-'+id);
 const stateLabels={healthy:t('Healthy','سليم'),failed:t('Fault','عطل'),unsupported:t('Upgrade required','يحتاج تحديثًا'),stale:t('Outdated check','فحص قديم'),not_checked:t('Not checked','لم يُفحص'),disabled:t('Disabled','معطّل'),paused:t('Monitoring paused','المراقبة متوقفة'),offline:t('Agent offline','الوكيل غير متصل'),pending:t('Pending application','في انتظار التطبيق'),degraded:t('Needs attention','يحتاج متابعة')};
 const diagnoses={OK:t('Expected origin and revision confirmed','تم تأكيد السيرفر الخلفي ونسخة الإعدادات'),CONNECT_FAILED:t('Connection could not be established','تعذر إنشاء الاتصال'),TIMEOUT:t('Probe timed out','انتهت مهلة الفحص'),TLS_FAILED:t('TLS verification / handshake failed','فشل الاتصال الآمن أو التحقق من الشهادة'),UPSTREAM_FAILED:t('Origin / upstream returned an error','خطأ في الاتصال بالسيرفر الخلفي'),HTTP_REJECTED:t('Request was rejected','تم رفض طلب الفحص'),PROBE_UNAVAILABLE:t('Origin diagnostics endpoint unavailable','مسار التشخيص غير متاح؛ راجع تحديث المين والبروكسي'),INVALID_RESPONSE:t('Unexpected diagnostic response','استجابة الفحص غير مطابقة'),ORIGIN_MISMATCH:t('Request reached a different origin','وصل الطلب إلى سيرفر خلفي مختلف'),REVISION_MISMATCH:t('Worker revision differs from confirmed settings','نسخة عامل البروكسي تختلف عن الإعدادات المؤكدة'),NOT_CHECKED:t('Waiting for a scheduled or manual check','في انتظار الفحص التلقائي أو اليدوي'),AGENT_OFFLINE:t('No recent authenticated agent heartbeat','لا توجد مزامنة حديثة موثقة من الوكيل'),APPLY_FAILED:t('Configuration was not applied','تعذر تطبيق الإعدادات'),MONITOR_STALE:t('No recent diagnostics; check the monitoring timer','لا يوجد فحص حديث؛ راجع مؤقت المراقبة'),CERTIFICATE_EXPIRING:t('Certificate expires soon','الشهادة تقترب من الانتهاء'),CERTIFICATE_EXPIRED:t('Certificate expired','انتهت صلاحية الشهادة'),CPU_HIGH:t('CPU usage is at least 90%','استخدام المعالج وصل إلى 90% أو أكثر'),MEMORY_HIGH:t('Memory usage is at least 90%','استخدام الذاكرة وصل إلى 90% أو أكثر')};
 const errors={INVALID_CSRF:t('Session token expired. Reload the page.','انتهت صلاحية الجلسة؛ أعد تحميل الصفحة.'),SESSION_EXPIRED:t('Sign in again to continue.','سجّل الدخول مجددًا للمتابعة.'),PROFILE_CONFLICT:t('Settings changed in another session. Refresh before restoring.','تغيرت الإعدادات في جلسة أخرى؛ حدّث الصفحة قبل الاستعادة.'),PROBE_BUSY:t('Another check is still running.','يوجد فحص قيد التنفيذ بالفعل.'),CURL_UNAVAILABLE:t('The diagnostics service requires PHP cURL.','خدمة التشخيص تحتاج إضافة cURL في PHP.'),INVALID_ALERT:t('This incident is no longer available. Refresh.','التنبيه غير متاح؛ حدّث البيانات.')};
 let selected=Number(new URL(location.href).searchParams.get('monitor'))||data.nodes[0]?.id,tab='ports',snapshots=[],historyCache=new Map(),busy=false,loading=false,disconnected=false,expired=false,lastHistoryKey='';
 const node=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
 const date=value=>value?new Date(value*1000).toLocaleString(data.ar?'ar-EG':undefined,{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'—';
 const num=(v,suffix='',digits=1)=>v===null||v===undefined?'—':Number(v).toLocaleString(data.ar?'ar-EG':undefined,{maximumFractionDigits:digits})+suffix;
 const say=(text,kind='error')=>{const n=$('notice');n.textContent=text;n.dataset.kind=kind;n.hidden=false;};
 const badge=(state,label)=>{const b=node('span','hpm-badge',label||stateLabels[state]||state);b.dataset.state=state;return b;};
 const overall=s=>!s.enabled?'disabled':!s.policy.monitor_enabled?'paused':s.status.state==='offline'?'offline':disconnected||s.stale?'stale':s.ports.some(p=>['failed','unsupported'].includes(p.state))?'degraded':s.pending?'pending':s.ports.length&&s.ports.every(p=>p.state==='healthy')?'healthy':'not_checked';
 const current=()=>snapshots.find(s=>s.id===selected);
 async function api(sub,id,body){
  const options={headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'};
  if(body){options.method='POST';options.body=new URLSearchParams({csrf:data.csrf,...body});}
  const r=await fetch('./api?action=proxy_profile&sub='+encodeURIComponent(sub)+(id?'&id='+id:''),options);
  if(r.redirected||!r.headers.get('content-type')?.includes('application/json'))throw Error('SESSION_EXPIRED');
  const answer=await r.json();if(!answer.result)throw Error(answer.error||'REQUEST_FAILED');return answer;
 }
 function summary(){
  const ports=snapshots.flatMap(s=>s.ports),healthy=ports.filter(p=>p.state==='healthy').length,alerts=snapshots.flatMap(s=>s.alerts).filter(a=>a.state==='active').length;
  $('stat-proxies').textContent=data.nodes.length;$('stat-ports').textContent=snapshots.length?healthy+' / '+ports.length:'—';$('stat-alerts').textContent=snapshots.length?alerts:'—';
  $('stat-viewers').textContent=snapshots.length&&snapshots.every(s=>s.viewers!==null)?num(snapshots.reduce((n,s)=>n+s.viewers,0),'',0):'—';
  $('stat-note-proxies').textContent=t('Independent edge network','شبكة حورس المستقلة');$('stat-note-ports').textContent=t('Confirmed listeners','البورتات المطبّقة');$('stat-note-alerts').textContent=t('Acknowledged faults remain counted','تظل الأعطال المُقر بها ضمن العدد');$('stat-note-viewers').textContent=t('Tracked separately from Nginx','مستقل عن اتصالات Nginx');
 }
 function nodes(){
  const focused=document.activeElement?.closest('.hpm-node')?.dataset.id;
  const query=$('search').value.trim().toLowerCase(),fragment=document.createDocumentFragment();
  for(const item of data.nodes){if(![item.name,item.ip].some(v=>v.toLowerCase().includes(query)))continue;
   const s=snapshots.find(s=>s.id===item.id),button=node('button','hpm-node');button.type='button';button.dataset.id=item.id;button.setAttribute('aria-pressed',String(item.id===selected));
   const identity=node('div','hpm-node-identity');identity.append(node('strong','',item.name),node('code','',item.ip));button.append(identity,badge(s?overall(s):'not_checked'));fragment.append(button);
  }
  if(!fragment.childNodes.length)fragment.append(node('p','hpm-placeholder',t('No matching proxies','لا توجد نتائج مطابقة')));$('nodes').replaceChildren(fragment);if(focused)$('nodes').querySelector('[data-id="'+focused+'"]')?.focus({preventScroll:true});
 }
 function chart(samples){
  const series=samples.slice(-30),box=$('sparkline');if(series.filter(s=>s.out_mbps!==null).length<2){box.replaceChildren(node('small','',t('Chart appears after two network samples','يظهر الرسم بعد تسجيل عينتين للشبكة')));return;}
  const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 360 62');svg.setAttribute('preserveAspectRatio','none');svg.setAttribute('aria-hidden','true');
  const max=Math.max(1,...series.map(s=>s.out_mbps||0));let points=[];
  function segment(){if(points.length){const line=document.createElementNS(ns,'polyline');line.setAttribute('points',points.join(' '));svg.append(line);points=[];}}
  series.forEach((s,i)=>{if(s.out_mbps===null){segment();return;}points.push((i/(series.length-1)*356+2)+','+(56-s.out_mbps/max*48));});segment();box.replaceChildren(svg);box.title=date(series[0].at)+' — '+date(series.at(-1).at);
 }
 function ports(s){
  const labels=[t('Port','البورت'),t('State','الحالة'),'TCP / TLS',t('Response','الاستجابة'),t('Certificate','الشهادة'),t('Diagnosis','التشخيص')],fragment=document.createDocumentFragment();
  for(const p of s.ports){const row=node('tr');const cell=(i)=>{const td=node('td');td.dataset.label=labels[i];row.append(td);return td;};
   const identity=cell(0);identity.append(node('strong','hpm-port-number',p.port),node('small','hpm-protocol',p.scheme.toUpperCase()),node('code','hpm-host',p.host));
   cell(1).append(badge(disconnected&&p.state==='healthy'?'stale':p.state));cell(2).append(node('span','hpm-timing',num(p.tcp_ms,' ms')+' / '+(p.scheme==='https'?num(p.tls_ms,' ms'):'—')));cell(3).append(node('strong','hpm-timing',num(p.total_ms,' ms')),node('small','',p.http_status?'HTTP '+p.http_status:'—'));
   const cert=cell(4);if(p.scheme==='http')cert.textContent=t('HTTP','HTTP');else if(p.certificate_expires_at){const hours=Math.floor((p.certificate_expires_at-s.server_time)/3600);cert.append(badge(hours>s.policy.certificate_warning_hours?'healthy':hours>0?'pending':'failed',hours>0?num(hours,'',0)+' '+t('hours left','ساعة متبقية'):t('Expired','منتهية')),node('small','',date(p.certificate_expires_at)));}else cert.textContent='—';
   const detail=cell(5);detail.append(node('span','',p.state==='disabled'?t('Checks paused for this proxy','الفحص متوقف لهذا البروكسي'):diagnoses[p.code]||p.code),node('small','',t('Checked: ','آخر فحص: ')+date(p.checked_at)));fragment.append(row);
  }
  if(!s.ports.length){const row=node('tr'),td=node('td','hpm-placeholder',t('Waiting for a confirmed configuration before probing ports.','في انتظار إعدادات مؤكدة من الوكيل قبل فحص البورتات.'));td.colSpan=6;row.append(td);fragment.append(row);}$('ports').replaceChildren(fragment);$('port-coverage').textContent=s.ports.length+' '+t('ports','بورتات');$('count-ports').textContent=s.ports.length;
 }
 function alerts(s){
  const list=s.alerts.filter(a=>!$('open-only').checked||a.state==='active'),fragment=document.createDocumentFragment();$('count-alerts').textContent=s.alerts.filter(a=>a.state==='active').length;
  for(const a of list){const card=node('article','hpm-incident');card.dataset.severity=a.state==='active'?a.severity:'resolved';const heading=node('div','hpm-incident-heading');heading.append(node('strong','',diagnoses[a.code]||a.code),badge(a.state==='resolved'?'healthy':a.severity==='critical'?'failed':'pending',a.state==='resolved'?t('Recovered','تم التعافي'):t('Open','قائم')));card.append(heading,node('code','',a.target),node('p','hpm-description',t('Opened: ','بدأ: ')+date(a.opened_at)+(a.resolved_at?' · '+t('Recovered: ','تعافى: ')+date(a.resolved_at):'')));
   if(a.acknowledged_at)card.append(node('small','hpm-acknowledged',t('Reviewed by ','تمت المراجعة بواسطة ')+(a.acknowledged_by?.name||'—')+' · '+date(a.acknowledged_at)));
   else if(a.state==='active'&&!a.virtual&&data.canEdit){const b=node('button','btn btn-sm btn-outline-secondary',t('Acknowledge','تمت المراجعة'));b.type='button';b.dataset.ack=a.id;card.append(b);}fragment.append(card);
  }
  if(!list.length)fragment.append(node('div','hpm-empty-small',t('No incidents in this view.','لا توجد تنبيهات في هذا العرض.')));$('alerts').replaceChildren(fragment);
 }
 function render(){
  summary();nodes();const s=current();if(!s)return;
  $('name').textContent=s.name;$('ip').textContent=s.ip;$('configure').href='./proxy?id='+selected;
  const health=overall(s);$('state').replaceWith(Object.assign(badge(health),{id:'hpm-state'}));$('last-check').textContent=t('Last check: ','آخر فحص: ')+date(s.last_checked_at);$('last-sync').textContent=t('Agent: ','الوكيل: ')+date(s.status.last_sync);
  $('pending').hidden=!s.pending;$('pending').textContent=t('A configuration change is pending. Checks below use the last confirmed ports. Desired HTTP: ','يوجد تعديل في انتظار التطبيق. الفحوص أدناه للبورتات المؤكدة السابقة. HTTP المطلوب: ')+s.desired_ports.http.join(', ')+' · HTTPS: '+s.desired_ports.https.join(', ');
  $('metric-cpu').textContent=num(s.metrics.cpu,'%');$('metric-ram').textContent=num(s.metrics.total_mem_used_percent,'%');$('metric-out').textContent=num(s.metrics.bytes_sent===null?null:s.metrics.bytes_sent*8/1e6,' Mbps',2);$('metric-nginx').textContent=num(s.metrics.nginx_active,'',0);$('network-current').textContent=$('metric-out').textContent;
  $('diagnose').disabled=busy||!s.enabled||!s.policy.monitor_enabled;$('diagnose').textContent=busy?t('Checking…','جارٍ الفحص…'):t('Run diagnostics','فحص الآن');chart(s.samples);ports(s);alerts(s);if(tab==='history')loadHistory();
 }
 async function refresh(){if(loading||document.hidden||expired)return;loading=true;try{const answer=await api('monitor');snapshots=answer.proxies;disconnected=false;root.dataset.disconnected='false';render();}catch(e){disconnected=true;root.dataset.disconnected='true';if(e.message==='SESSION_EXPIRED')expired=true;say(errors[e.message]||t('Refresh failed. Displayed checks may be outdated.','تعذر التحديث؛ قد تكون الفحوص المعروضة قديمة.'));render();}finally{loading=false;}}
 function value(v){if(v===null||v===undefined)return '—';if(typeof v==='boolean')return v?t('Yes','نعم'):t('No','لا');return typeof v==='object'?JSON.stringify(v):String(v);}
 const fieldNames={name:t('Name','الاسم'),http_ports:t('HTTP ports','بورتات HTTP'),https_ports:t('HTTPS ports','بورتات HTTPS'),enabled:t('Traffic enabled','تفعيل المرور'),hosts:t('Hosts','الهوستات'),parent_ids:t('Origin servers','السيرفرات الخلفية'),monitor_ca:t('Probe CA fingerprint','بصمة شهادة فحص البروكسي'),upstream_cas:t('Origin CA fingerprints','بصمات شهادات السيرفرات الخلفية'),monitor_enabled:t('Monitoring','المراقبة'),monitor_interval:t('Check interval','فاصل الفحص'),monitor_timeout:t('Probe timeout','مهلة الفحص'),monitor_failures:t('Alert threshold','حد فتح التنبيه'),monitor_recoveries:t('Recovery threshold','حد التعافي'),certificate_warning_hours:t('Certificate warning','التنبيه لانتهاء الشهادة')};
 async function loadHistory(force=false){const s=current();if(!s)return;const id=selected,key=id+':'+s.status.saved_revision;if(historyCache.has(key)&&!force){if(lastHistoryKey!==key){drawHistory(historyCache.get(key));lastHistoryKey=key;}return;}$('history').replaceChildren(node('p','hpm-placeholder',t('Loading history…','تحميل السجل…')));try{const a=await api('history',id);historyCache.set(key,a.history);if(id===selected){drawHistory(a.history);lastHistoryKey=key;}}catch(e){if(id===selected)$('history').replaceChildren(node('p','hpm-placeholder',errors[e.message]||t('Could not load history.','تعذر تحميل السجل.')));}}
 function drawHistory(entries){const fragment=document.createDocumentFragment();$('count-history').textContent=entries.length;
  for(const entry of entries){const d=node('details','hpm-version'),summary=node('summary'),top=node('div','hpm-version-heading'),action=({save:t('Saved configuration','حفظ الإعدادات'),restore:t('Restored version','استعادة نسخة'),enable:t('Enabled traffic','تفعيل المرور'),disable:t('Disabled traffic','تعطيل المرور'),baseline:t('Previous configuration','الإعدادات السابقة')})[entry.action]||entry.action;
   top.append(node('strong','',action),node('time','',date(entry.at)));summary.append(top,node('small','',entry.actor.name+' · '+entry.revision.slice(0,12)));d.append(summary);
   const changes=node('div','hpm-changes');for(const change of entry.changes){const row=node('div','hpm-change');row.append(node('strong','',fieldNames[change.field]||change.field),node('span','hpm-before',value(change.before)),node('span','hpm-after',value(change.after)));changes.append(row);}d.append(changes);
   if(data.canEdit&&entry.revision!==current()?.status.saved_revision){const b=node('button','btn btn-sm btn-outline-primary',t('Restore this version','استعادة هذه النسخة'));b.type='button';b.dataset.restore=entry.id;d.append(b);}fragment.append(d);
  }
  if(!entries.length)fragment.append(node('div','hpm-empty-small',t('New configuration changes will be recorded here.','ستظهر هنا تغييرات الإعدادات الجديدة.')));$('history').replaceChildren(fragment);
 }
 function selectTab(name,focus=false){tab=name;root.querySelectorAll('[data-tab]').forEach(b=>{const active=b.dataset.tab===name;b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;$('panel-'+b.dataset.tab).hidden=!active;if(active&&focus)b.focus();});if(name==='history')loadHistory();}
 root.addEventListener('click',async event=>{const button=event.target.closest('button');if(!button)return;
  if(button.dataset.id){selected=Number(button.dataset.id);const url=new URL(location.href);url.searchParams.set('monitor',String(selected));history.replaceState(null,'',url);render();return;}
  if(button.dataset.tab){selectTab(button.dataset.tab);return;}
  if(button.id==='hpm-history-refresh'){loadHistory(true);return;}
  if(button.id==='hpm-diagnose'){busy=true;render();try{const answer=await api('diagnose',selected,{});await refresh();say(answer.proxy.reused_check?t('A recent check is already available.','يوجد فحص حديث بالفعل؛ تم عرض نتيجته.'):t('Port diagnostics completed.','اكتمل فحص البورتات.'),'success');}catch(e){say(errors[e.message]||t('Diagnostics could not finish.','تعذر إكمال الفحص.'));}finally{busy=false;render();}return;}
  if(button.dataset.ack){button.disabled=true;try{await api('acknowledge',selected,{alert:button.dataset.ack});await refresh();}catch(e){say(errors[e.message]||e.message);button.disabled=false;}return;}
  if(button.dataset.restore){if(!confirm(t('Save this version as a new desired configuration? It can change ports and routing after the agent applies it.','حفظ هذه النسخة كإعدادات جديدة؟ قد تتغير البورتات والتوجيه بعد تطبيق الوكيل.')))return;button.disabled=true;try{await api('restore',selected,{entry:button.dataset.restore,revision:current().status.saved_revision});historyCache.clear();await refresh();say(current()?.pending?t('Restore saved. Waiting for agent confirmation.','تم حفظ الاستعادة، في انتظار تأكيد الوكيل.'):t('Version saved; these settings are already applied.','تم حفظ الاستعادة؛ هذه الإعدادات مطبّقة بالفعل.'),'success');}catch(e){say(errors[e.message]||e.message);button.disabled=false;}return;}
  if(button.dataset.hpmAction){if(!confirm(t('Apply this action? Disable, delete or revoke can interrupt playback.','تنفيذ الإجراء؟ التعطيل أو الحذف أو إلغاء المفتاح قد يقطع المشاهدة.')))return;button.disabled=true;try{await api(button.dataset.hpmAction,selected,{});location.reload();}catch(e){say(errors[e.message]||e.message);button.disabled=false;}}
 });
 root.querySelector('[role=tablist]')?.addEventListener('keydown',event=>{const tabs=['ports','alerts','history'],i=tabs.indexOf(tab);let next;if(event.key==='ArrowRight')next=(i+(data.ar?2:1))%3;else if(event.key==='ArrowLeft')next=(i+(data.ar?1:2))%3;else if(event.key==='Home')next=0;else if(event.key==='End')next=2;else return;event.preventDefault();selectTab(tabs[next],true);});
 if(!data.nodes.some(n=>n.id===selected))selected=data.nodes[0]?.id;
 summary();if(!data.nodes.length)return;$('search').addEventListener('input',nodes);$('open-only').addEventListener('change',()=>{if(current())alerts(current());});nodes();refresh();const timer=setInterval(refresh,10000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
