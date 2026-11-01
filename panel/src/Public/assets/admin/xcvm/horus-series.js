/* Horus series presentation; native permissions and action handlers remain authoritative. */
window.HorusSeriesImport={prepare:function(data,kind){if(kind==='folder'){data.delete('m3u_file');}else{data.delete('import_folder');data.delete('scan_recursive');}return data;}};
window.HorusEpisodeNumbers={fill:function(values){var used=new Set(values.filter(function(v){return /^\d+$/.test(String(v))&&Number(v)>0;}).map(Number)),next=1;return values.map(function(v){if(/^\d+$/.test(String(v))&&Number(v)>0)return Number(v);while(used.has(next))next++;used.add(next);return next++;});}};
document.addEventListener('DOMContentLoaded',function(){
 var table=document.querySelector('#series-table, #episodes-table');
 if(table){
  var header=table.closest('.card').querySelector('.card-header'),clear=document.getElementById('clearFilters');
  if(clear){var tools=clear.parentElement,old=tools.parentElement;tools.classList.add('xc-lines-actions');header.appendChild(tools);if(old!==header&&!old.children.length)old.remove();}
  var bulk=document.getElementById('bulk-bar');if(bulk){var zone=document.createElement('div');zone.className='xc-bulk-zone';zone.appendChild(bulk);header.after(zone);}
  table.addEventListener('error',function(event){if(event.target.matches('.horus-series-cover img'))event.target.hidden=true;},true);
 }
 var form=document.querySelector('#serie-form, #episode-form, #mass-form');if(!form)return;
 form.addEventListener('invalid',function(event){var pane=event.target.closest('.tab-pane');if(pane&&!pane.classList.contains('active')){var button=form.querySelector('[data-bs-target="#'+pane.id+'"]');if(button)button.click();}},true);
 var tabs=form.querySelector('[role=tablist]');
 if(tabs){Array.from(tabs.querySelectorAll(':scope > .nav-item > button')).forEach(function(button,index){var number=document.createElement('span');number.className='xc-step-number';number.textContent=String(index+1).padStart(2,'0');button.prepend(number);});var small=window.matchMedia('(max-width:767px)');function orientation(){tabs.setAttribute('aria-orientation',small.matches||form.id==='mass-form'?'horizontal':'vertical');}orientation();small.addEventListener('change',orientation);}
 var submit=form.querySelector('[type=submit]');if(submit)submit.parentElement.classList.add('xc-editor-savebar');
 var upload=document.getElementById('m3u_file');if(upload){var file=document.createElement('span');file.className='horus-series-file';file.setAttribute('role','status');file.hidden=true;upload.after(file);upload.addEventListener('change',function(){file.textContent=upload.files.length?upload.files[0].name:'';file.hidden=!upload.files.length;});}
});
