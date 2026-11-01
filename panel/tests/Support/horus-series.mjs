import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../../src/Public/assets/admin/xcvm/horus-series.js',import.meta.url),'utf8');
const context={window:{},document:{addEventListener:()=>{}}};vm.runInNewContext(source,context);
for(const kind of ['folder','m3u']){const data=new FormData();data.set('m3u_file','stale.m3u');data.set('import_folder','s:1:/media/series');data.set('scan_recursive','1');data.set('server_tree_data','QA');context.window.HorusSeriesImport.prepare(data,kind);assert.equal(data.has('m3u_file'),kind==='m3u');assert.equal(data.has('import_folder'),kind==='folder');assert.equal(data.has('scan_recursive'),kind==='folder');assert.equal(data.get('server_tree_data'),'QA');}
const fill=context.window.HorusEpisodeNumbers.fill;
assert.deepEqual(Array.from(fill(['2',''])),[2,1]);assert.deepEqual(Array.from(fill(['','1',''])),[2,1,3]);assert.deepEqual(Array.from(fill(['01','2'])),[1,2]);assert.deepEqual(Array.from(fill(['',''])),[1,2]);
console.log('PASS: series import source isolation and collision-free episode numbering');
