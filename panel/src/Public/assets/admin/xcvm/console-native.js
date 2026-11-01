/* Relocate existing alert nodes only; polling hooks, controls and events remain intact. */
document.addEventListener('DOMContentLoaded',function(){
  var slot=document.getElementById('xc-console-alert-slot');
  var alerts=document.getElementById('latest-alerts');
  if(slot&&alerts&&!alerts.classList.contains('d-none')){slot.appendChild(alerts);slot.hidden=false;}
  var summary=document.getElementById('xc-console-summary');
  var updated=document.getElementById('stats_updated');
  var statistics=updated&&updated.closest('.card');
  if(summary&&statistics){
    var groups={'active-streams':'streams','offline-streams':'streams','online-users':'subscriptions','active-connections':'connections','input-flow':'connections','output-flow':'connections'};
    Object.keys(groups).forEach(function(cls){
      var metric=statistics.querySelector('.'+cls);
      var item=metric&&metric.closest('.dashboard-stat-item');
      var destination=summary.querySelector('[data-xc-console-group="'+groups[cls]+'"]');
      if(item&&destination)destination.appendChild(item);
    });
    statistics.before(summary);
    summary.hidden=false;
    // Preserve the native update timestamp and any optional controls.
    var remaining=statistics.querySelector('.card-body');
    if(remaining&&!remaining.querySelector('.dashboard-stat-item'))remaining.hidden=true;
  }
});
document.addEventListener('DOMContentLoaded',function(){
  var summary=document.getElementById('xc-console-summary');
  if(!summary)return;
  var stat=document.getElementById('stats_updated');
  var statistics=stat&&stat.closest('.card');
  var serverCard=document.querySelector('.dashboard-card-hover');
  var serverRow=serverCard&&serverCard.parentElement.parentElement;
  var picker=document.querySelector('.dashboard-server-select');
  var pageHeading=picker&&picker.parentElement;
  if(serverRow&&statistics){
    var section=document.createElement('section');section.className='xc-console-server-section';
    var toolbar=document.createElement('div');toolbar.className='xc-console-server-toolbar';
    var title=document.createElement('h5');
    var serverTitle=summary.querySelector('.xc-console-summary-servers h5');
    title.textContent=(serverTitle?serverTitle.textContent.trim():'')+' · '+statistics.querySelector('h5').textContent;
    toolbar.appendChild(title);if(picker)toolbar.appendChild(picker);
    section.appendChild(toolbar);section.appendChild(serverRow);summary.after(section);
    if(pageHeading)pageHeading.hidden=true;
    var stamp=statistics.querySelector('.card-header small');
    if(stamp){stamp.classList.add('xc-console-update');toolbar.appendChild(stamp);}
    statistics.hidden=true;
  }
  var online=summary.querySelector('.active-streams .entry');
  var offline=summary.querySelector('.offline-streams .entry');
  var streamCard=summary.querySelector('.xc-console-summary-streams');
  var headerCount=document.createElement('span');headerCount.className='xc-console-header-count';
  if(streamCard)streamCard.querySelector('.card-header').prepend(headerCount);
  var meters=[];
  [online,offline].forEach(function(entry){if(!entry)return;var meter=document.createElement('div');meter.className='xc-console-meter';meter.setAttribute('aria-hidden','true');var bar=document.createElement('span');meter.appendChild(bar);entry.closest('.dashboard-stat-item').appendChild(meter);meters.push(bar);});
  function refresh(){var on=Number((online?online.textContent:'0').replace(/[^0-9.]/g,''))||0;var off=Number((offline?offline.textContent:'0').replace(/[^0-9.]/g,''))||0;headerCount.textContent=(on+off).toLocaleString();meters.forEach(function(bar,i){bar.style.width=((on+off)>0?((i?off:on)/(on+off)*100):0)+'%';});}
  var observer=new MutationObserver(refresh);[online,offline].forEach(function(node){if(node)observer.observe(node,{childList:true,characterData:true,subtree:true});});refresh();
});
/* Subscription workspace: retain original controls, names, IDs and handlers. */
document.addEventListener('DOMContentLoaded',function(){
  var rtl=document.documentElement.dir==='rtl'||document.documentElement.lang.indexOf('ar')===0;
  function words(ar,en){return rtl?ar:en;}
  function node(tag,cls,text){var e=document.createElement(tag);e.className=cls;if(text)e.textContent=text;return e;}
  var table=document.getElementById('lines-table');
  if(table){
    document.body.classList.add('xc-lines-page');
    var card=table.closest('.card');card.classList.add('xc-lines-workspace');
    var header=card.querySelector('.card-header');
    var heading=header.querySelector('h5');
    var intro=node('div','xc-workspace-intro');
    intro.appendChild(node('span','xc-workspace-eyebrow',words('إدارة العملاء','CUSTOMER MANAGEMENT')));
    if(heading)intro.appendChild(heading);
    intro.appendChild(node('p','xc-workspace-caption',words('ابحث عن الاشتراكات، راجع حالتها، وأدِر الوصول من مكان واحد.','Find subscriptions, review their status and manage access in one place.')));
    header.prepend(intro);
    var clear=document.getElementById('clearFilters');
    var toolbar=clear&&clear.parentElement;
    var oldToolbar=toolbar&&toolbar.parentElement;
    if(toolbar){toolbar.classList.add('xc-lines-actions');header.appendChild(toolbar);if(oldToolbar&&!oldToolbar.children.length)oldToolbar.remove();}
    var filters=document.getElementById('filter-search');
    var filterBody=filters&&filters.closest('.card-body');
    if(filterBody)filterBody.classList.add('xc-lines-filters');
    var status=document.getElementById('filter-status');
    if(status){
      var tabs=node('div','xc-status-tabs');tabs.setAttribute('role','group');tabs.setAttribute('aria-label',words('تصفية حسب الحالة','Filter by status'));
      Array.from(status.options).forEach(function(option){
        var button=node('button','xc-status-chip',option.textContent);button.type='button';button.dataset.status=option.value;
        button.addEventListener('click',function(){status.value=option.value;if(window.jQuery)window.jQuery(status).trigger('change');else status.dispatchEvent(new Event('change',{bubbles:true}));sync();});tabs.appendChild(button);
      });
      function sync(){Array.from(tabs.children).forEach(function(button){var selected=button.dataset.status===status.value;button.classList.toggle('active',selected);button.setAttribute('aria-pressed',String(selected));});}
      status.addEventListener('change',sync);if(window.jQuery)window.jQuery(status).on('change.xcWorkspace',sync);
      if(clear)clear.addEventListener('click',function(){setTimeout(sync,0);});
      filterBody.after(tabs);sync();
    }
    var bulk=document.getElementById('bulk-bar');if(bulk){var bulkWrap=bulk.parentElement;bulkWrap.classList.add('xc-bulk-zone');header.after(bulkWrap);}
  }
  var form=document.getElementById('line-form');
  if(form){
    document.body.classList.add('xc-line-editor');
    var shell=form.querySelector('.card');if(shell)shell.classList.add('xc-editor-shell');
    var tablist=form.querySelector('[role=tablist]');
    if(tablist){tablist.setAttribute('aria-orientation','vertical');Array.from(tablist.querySelectorAll('[role=tab]')).forEach(function(button,index){button.prepend(node('span','xc-step-number',String(index+1).padStart(2,'0')));});}
    var details=document.getElementById('tab-details');
    if(details){
      var children=Array.from(details.children);
      var groups=[{title:words('بيانات الحساب','Account credentials'),caption:words('اسم المستخدم وكلمة المرور والمالك.','Username, password and account owner.'),items:children.slice(0,2),icon:'tabler-user-circle'},
      {title:words('مدة الاشتراك والوصول','Subscription & access'),caption:words('تاريخ الانتهاء وعدد الاتصالات المسموح بها.','Expiration date and allowed simultaneous connections.'),items:children.slice(2,4),icon:'tabler-calendar-time'},
      {title:words('التواصل والملاحظات','Contact & notes'),caption:words('طرق التواصل والملاحظات الخاصة بالحساب.','Contact details and notes for this account.'),items:children.slice(4),icon:'tabler-message-circle'}];
      groups.forEach(function(group){var section=node('section','xc-editor-section');var title=node('h5','xc-editor-section-title');var icon=node('i','icon-base ti '+group.icon);icon.setAttribute('aria-hidden','true');title.appendChild(icon);title.appendChild(document.createTextNode(group.title));section.appendChild(title);section.appendChild(node('p','xc-editor-section-caption',group.caption));group.items.forEach(function(child){section.appendChild(child);});details.appendChild(section);});
    }
    var submit=document.getElementById('line-submit');if(submit){var footer=submit.parentElement;footer.classList.add('xc-editor-savebar');var note=node('span','xc-editor-save-note',words('راجع البيانات قبل الحفظ','Review account details before saving'));footer.prepend(note);}
  }
});
document.addEventListener('DOMContentLoaded',function(){var tabs=document.querySelector('#line-form [role=tablist]');if(!tabs)return;var mobile=window.matchMedia('(max-width:767px)');function orientation(){tabs.setAttribute('aria-orientation',mobile.matches?'horizontal':'vertical');}orientation();mobile.addEventListener('change',orientation);});
/* Copyright: Horus interface design and customizations. Upstream notices remain visible. */
document.addEventListener('DOMContentLoaded',function(){
  var footer=document.querySelector('.content-footer .footer-container');
  if(!footer||footer.querySelector('.xc-horus-rights'))return;
  var rights=document.createElement('div');rights.className='xc-horus-rights';rights.dir='rtl';
  var brand=document.createElement('strong');brand.className='xc-horus-brand';brand.textContent='حورس | HORUS';
  var text=document.createElement('span');text.textContent='© '+new Date().getFullYear()+' حورس — حقوق تصميم وتخصيص الواجهة محفوظة.';
  rights.appendChild(brand);rights.appendChild(text);
  Array.from(footer.children).forEach(function(child){child.classList.add('xc-upstream-notice');});
  footer.prepend(rights);
});
document.addEventListener('DOMContentLoaded',function(){
 var menu=document.getElementById('layout-menu');if(!menu)return;
 var list=menu.querySelector('.menu-inner');if(!list)return;
 var ar=document.documentElement.dir==='rtl'||document.documentElement.lang.indexOf('ar')===0;
 var box=document.createElement('div');box.className='xc-menu-search';
 var input=document.createElement('input');input.type='search';input.placeholder=ar?'ابحث في القائمة…':'Find a page…';input.setAttribute('aria-label',ar?'البحث في القائمة الجانبية':'Search sidebar navigation');input.autocomplete='off';
 var clear=document.createElement('button');clear.type='button';clear.textContent='×';clear.setAttribute('aria-label',ar?'مسح البحث':'Clear navigation search');clear.hidden=true;
 box.appendChild(input);box.appendChild(clear);list.before(box);
 var empty=document.createElement('div');empty.className='xc-menu-empty';empty.textContent=ar?'لا توجد صفحات مطابقة':'No matching pages';empty.hidden=true;list.after(empty);empty.setAttribute('role','status');
 var snapshot=null;var items=Array.from(list.querySelectorAll('.menu-item'));
 function normalize(text){return text.toLocaleLowerCase().normalize('NFKD').replace(/[\u064B-\u065F\u0670]/g,'').trim();}
 function filter(){var query=normalize(input.value);clear.hidden=!query;if(query&&!snapshot)snapshot=items.map(function(item){return item.classList.contains('open');});
  items.forEach(function(item){item.hidden=false;});
  if(query){items.slice().reverse().forEach(function(item){var link=item.querySelector(':scope > .menu-link');var self=link&&normalize(link.textContent).includes(query);var sub=item.querySelector(':scope > .menu-sub');var children=sub?Array.from(sub.children).filter(function(e){return e.classList.contains('menu-item');}):[];if(self&&sub){sub.querySelectorAll('.menu-item').forEach(function(child){child.hidden=false;});}var match=self||children.some(function(child){return !child.hidden;});item.hidden=!match;if(sub)item.classList.toggle('open',!!match);});}
  else if(snapshot){items.forEach(function(item,i){item.classList.toggle('open',snapshot[i]);});snapshot=null;}
  list.querySelectorAll('.menu-header').forEach(function(header){header.hidden=!!query;});empty.hidden=!query||items.some(function(item){return !item.hidden;});
 }
 input.addEventListener('input',filter);clear.addEventListener('click',function(){input.value='';filter();input.focus();});input.addEventListener('keydown',function(e){if(e.key==='Escape'){input.value='';filter();}});
 menu.querySelectorAll('.layout-menu-toggle').forEach(function(toggle){toggle.setAttribute('aria-label',ar?'طي أو فتح القائمة':'Toggle sidebar');});
 function accessibility(){list.querySelectorAll('.menu-toggle').forEach(function(link){link.setAttribute('aria-expanded',String(link.parentElement.classList.contains('open')));});list.querySelectorAll('a.menu-link:not(.menu-toggle)').forEach(function(link){if(link.parentElement.classList.contains('active'))link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});}
 accessibility();new MutationObserver(accessibility).observe(list,{subtree:true,attributes:true,attributeFilter:['class']});
});
/* Horus streaming and infrastructure workspaces: reuse original data and actions. */
document.addEventListener('DOMContentLoaded',function(){
 var ar=document.documentElement.dir==='rtl'||document.documentElement.lang.indexOf('ar')===0;
 function text(a,e){return ar?a:e;}function node(tag,cls,value){var el=document.createElement(tag);el.className=cls;if(value)el.textContent=value;return el;}
 var streams=document.getElementById('streams-table'),servers=document.getElementById('servers-table');
 [streams,servers].forEach(function(table){if(!table)return;var isStreams=table===streams;var card=table.closest('.card');card.classList.add('xc-lines-workspace','xc-operations-workspace');document.body.classList.add(isStreams?'xc-streams-page':'xc-servers-page');var header=card.querySelector('.card-header');var title=header.querySelector('h5');var intro=node('div','xc-workspace-intro');intro.appendChild(node('span','xc-workspace-eyebrow',isStreams?text('حورس · إدارة البث','HORUS · STREAMING'):text('حورس · البنية التحتية','HORUS · INFRASTRUCTURE')));if(title)intro.appendChild(title);intro.appendChild(node('p','xc-workspace-caption',isStreams?text('المصادر والحالات والتصنيفات وأدوات التحكم في مكان واحد.','Sources, status, categories and stream controls in one workspace.'):text('راقب موارد السيرفرات واتصالاتها، وافتح تفاصيل كل سيرفر.','Monitor server resources and connections, then open each node for details.')));header.prepend(intro);
 var bulk=document.getElementById('bulk-bar');if(bulk){var zone=node('div','xc-bulk-zone');zone.appendChild(bulk);header.after(zone);}
 if(isStreams){var clear=document.getElementById('clearFilters');if(clear){var tools=clear.parentElement,old=tools.parentElement;tools.classList.add('xc-lines-actions');header.appendChild(tools);if(old!==header&&!old.children.length)old.remove();}var filter=document.getElementById('filter-category');var filters=filter&&filter.closest('.card-body');if(filters)filters.classList.add('xc-lines-filters');var status=document.getElementById('filter-status');if(status&&filters){var tabs=node('div','xc-status-tabs');tabs.setAttribute('role','group');tabs.setAttribute('aria-label',text('تصفية حالة البث','Filter stream status'));Array.from(status.options).forEach(function(option){var button=node('button','xc-status-chip',option.textContent);button.type='button';button.dataset.status=option.value;button.addEventListener('click',function(){status.value=option.value;if(window.jQuery)window.jQuery(status).trigger('change');else status.dispatchEvent(new Event('change',{bubbles:true}));sync();});tabs.appendChild(button);});function sync(){Array.from(tabs.children).forEach(function(button){var active=button.dataset.status===status.value;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});}if(window.jQuery)window.jQuery(status).on('change.xcStreams',sync);status.addEventListener('change',sync);if(clear)clear.addEventListener('click',function(){setTimeout(sync,0);});filters.after(tabs);sync();}}
 });
 var streamForm=document.getElementById('stream-form'),serverForm=document.getElementById('server-form'),installForm=document.getElementById('install-form');
 [streamForm,serverForm,installForm].forEach(function(form){if(!form)return;form.classList.add('xc-operation-form');var tabs=form.querySelector('[role=tablist]');if(!tabs)return;var buttons=Array.from(tabs.querySelectorAll(':scope > .nav-item > button'));buttons.forEach(function(button,index){button.prepend(node('span','xc-step-number',String(index+1).padStart(2,'0')));button.setAttribute('role','tab');});var screen=window.matchMedia('(max-width:767px)');function orient(){tabs.setAttribute('aria-orientation',screen.matches?'horizontal':'vertical');}orient();screen.addEventListener('change',orient);
 if(form===streamForm){var shell=tabs.closest('.card');shell.classList.add('xc-editor-shell','xc-operation-shell');}
 else{form.classList.add('xc-server-editor');var nav=node('div','xc-server-editor-nav');tabs.before(nav);nav.appendChild(tabs);var content=form.querySelector('.tab-content');if(content)content.classList.add('xc-server-editor-content');}
 var submit=form.querySelector('button[type=submit]');if(submit){var footer=submit.parentElement;footer.classList.add('xc-editor-savebar');footer.prepend(node('span','xc-editor-save-note',text('راجع الإعدادات قبل الحفظ','Review configuration before saving')));}
 form.querySelectorAll('.tab-pane').forEach(function(pane){if(pane.parentElement!==form.querySelector('.tab-content'))return;var label=buttons.find(function(button){return button.getAttribute('data-bs-target')==='#'+pane.id;});if(label){var heading=node('h5','xc-operation-section-title',label.textContent.replace(/^\d+\s*/,''));pane.prepend(heading);}});
 });
 if(document.getElementById('watchdog_cpu')){document.body.classList.add('xc-server-monitor');var content=document.querySelector('.container-p-y');if(content)content.classList.add('xc-monitor-workspace');}
});
document.addEventListener('DOMContentLoaded',function(){var form=document.getElementById('stream-form');if(form&&!form.querySelector('input[name="edit"]')){document.querySelectorAll('a[href="stream_view?id="]').forEach(function(link){link.hidden=true;link.classList.add('d-none');});}if(/\/stream_view\/?$/.test(location.pathname))document.body.classList.add('xc-server-monitor');});
