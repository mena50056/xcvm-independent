import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const root=new URL('../../src/',import.meta.url);
const partial=fs.readFileSync(new URL('Public/Views/layouts/horus_head.php',root),'utf8');
const script=partial.match(/<script>([\s\S]*?)<\/script>/)[1];
function boot(){
 const classes=new Set(),events={},frames=[];let fallback;
 const ctx={document:{documentElement:{classList:{add:v=>classes.add(v),remove:v=>classes.delete(v)}},addEventListener:(n,fn)=>events[n]=fn},window:{addEventListener:(n,fn)=>events[n]=fn},setTimeout:fn=>(fallback=fn,1),clearTimeout:()=>{},requestAnimationFrame:fn=>frames.push(fn)};
 vm.runInNewContext(script,ctx);
 return {classes,events,frames,timeout:()=>fallback()};
}
let page=boot();assert(page.classes.has('horus-booting'),'shell must be gated before content is parsed');
page.events.DOMContentLoaded();assert(page.classes.has('horus-booting'));
page.frames.shift()();assert(page.classes.has('horus-booting'),'first frame must not expose intermediate layout');
page.frames.shift()();assert(!page.classes.has('horus-booting'),'ready layout must be revealed');
page=boot();page.timeout();assert(!page.classes.has('horus-booting'),'failed scripts must not lock users out');
page=boot();page.events.pageshow({persisted:true});assert(!page.classes.has('horus-booting'),'browser restore must reveal the page');
for(const path of ['admin/header.php','layouts/reseller/header.php','admin/login.php','reseller/login.php']){
 const source=fs.readFileSync(new URL('Public/Views/'+path,root),'utf8');
 assert(source.includes('horus_head.php'),path+' missing shared first-paint boundary');
 assert(source.indexOf('<meta charset=')<source.indexOf('horus_head.php'),path+' must declare encoding before branded inline content');
 assert(!/https:\/\/fonts\.(googleapis|gstatic)\.com/.test(source),path+' still loads external fonts');
 assert(source.indexOf('horus_head.php')<source.indexOf('rel="stylesheet"'),path+' gates too late');
}
console.log('PASS: early gate, two-frame readiness, timeout, browser restore, four templates and local fonts');
