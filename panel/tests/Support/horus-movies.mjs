import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../../src/Public/assets/admin/xcvm/horus-movies.js',import.meta.url),'utf8');
const context={window:{},document:{addEventListener:()=>{}}};
vm.runInNewContext(source,context);
const prepare=context.window.HorusMovieImport.prepare;
function fixture(){const data=new FormData();data.set('m3u_file','previous.m3u');data.set('import_folder','s:1:/media/movies');data.set('scan_recursive','1');data.set('server_tree_data','[{"id":"1","parent":"source"}]');return data;}
let data=fixture();prepare(data,'folder');assert(!data.has('m3u_file'),'folder import must not upload a previously selected M3U');assert.equal(data.get('import_folder'),'s:1:/media/movies');assert.equal(data.get('scan_recursive'),'1');
data=fixture();prepare(data,'m3u');assert(data.has('m3u_file'));assert(!data.has('import_folder'),'M3U import must not submit a stale folder');assert(!data.has('scan_recursive'));assert.equal(data.get('server_tree_data'),'[{"id":"1","parent":"source"}]');
console.log('PASS: selected import source, stale-source exclusion and server assignment preservation');
