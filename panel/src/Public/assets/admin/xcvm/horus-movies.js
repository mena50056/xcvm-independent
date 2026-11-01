/* Movie-specific presentation. Existing forms, permissions and action handlers remain authoritative. */
window.HorusMovieImport={prepare:function(data,kind){if(kind==='folder'){data.delete('m3u_file');}else{data.delete('import_folder');data.delete('scan_recursive');}return data;}};
document.addEventListener('DOMContentLoaded',function(){
 var table=document.getElementById('movies-table');
 if(table){
  var header=table.closest('.card').querySelector('.card-header');
  var clear=document.getElementById('clearFilters');
  if(clear){var tools=clear.parentElement,old=tools.parentElement;tools.classList.add('xc-lines-actions');header.appendChild(tools);if(old!==header&&!old.children.length)old.remove();}
  var bulk=document.getElementById('bulk-bar');if(bulk){var zone=document.createElement('div');zone.className='xc-bulk-zone';zone.appendChild(bulk);header.after(zone);}
  table.addEventListener('error',function(event){if(event.target.matches('.horus-movie-cover img'))event.target.hidden=true;},true);
 }
 var form=document.getElementById('movie-form');if(!form)return;
 if(!form.querySelector('input[name="edit"]'))document.querySelectorAll('a[href="stream_view?id="]').forEach(function(link){link.hidden=true;link.classList.add('d-none');});
 form.addEventListener('invalid',function(event){var pane=event.target.closest('.tab-pane');if(pane&&!pane.classList.contains('active')){var tab=form.querySelector('[data-bs-target="#'+pane.id+'"]');if(tab)tab.click();}},true);
 var tabs=form.querySelector('[role=tablist]');
 if(tabs){Array.from(tabs.querySelectorAll(':scope > .nav-item > button')).forEach(function(button,index){var number=document.createElement('span');number.className='xc-step-number';number.textContent=String(index+1).padStart(2,'0');button.prepend(number);});var small=window.matchMedia('(max-width:767px)');function orientation(){tabs.setAttribute('aria-orientation',small.matches?'horizontal':'vertical');}orientation();small.addEventListener('change',orientation);}
 var submit=document.getElementById('movie-submit');if(submit)submit.parentElement.classList.add('xc-editor-savebar');
 var upload=document.getElementById('m3u_file'),summary=document.getElementById('horus-import-file');
 if(upload&&summary)upload.addEventListener('change',function(){summary.textContent=upload.files.length?upload.files[0].name:'';summary.hidden=!upload.files.length;});
});
