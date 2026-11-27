<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/panel/src/Core/Proxy/ProxyProfile.php';require $root.'/panel/src/Core/Proxy/ProxyProfiles.php';require $root.'/panel/src/Core/Proxy/ProxyHistory.php';require $root.'/panel/src/Core/Proxy/ProxySync.php';require $root.'/panel/src/Domain/Server/ProxyKey.php';require $root.'/panel/src/Domain/Server/ProxyRoute.php';require $root.'/runtime/ProxyPeer.php';
use XcVm\Core\Proxy\{ProxyProfile,ProxyProfiles,ProxySync};use XcVm\Domain\Server\ProxyKey;
function check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function refuses(callable $fn,string $reason): void {try{$fn();}catch(Throwable $e){check($e->getMessage()===$reason,'Wrong rejection: '.$e->getMessage());return;}throw new RuntimeException('Accepted '.$reason);}
$dir=sys_get_temp_dir().'/horus-proxy-'.bin2hex(random_bytes(6));mkdir($dir,0700);define('MAIN_HOME',$root.'/panel/src/');define('OPENSSL_EXTRA','test-only-routing-seed');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY AUTOINCREMENT,server_name TEXT,server_ip TEXT,server_type INTEGER DEFAULT 0,is_main INTEGER DEFAULT 0,enabled INTEGER,status INTEGER,proxy_key_gen INTEGER DEFAULT 0,proxy_signed INTEGER DEFAULT 0,parent_id TEXT,http_broadcast_port INTEGER,https_broadcast_port INTEGER,http_ports_add TEXT,https_ports_add TEXT,domain_name TEXT,total_clients INTEGER,enable_https INTEGER,last_check_ago INTEGER,watchdog_data TEXT);INSERT INTO servers (id,server_name,server_ip,is_main,enabled,https_broadcast_port) VALUES (1,"Main","192.0.2.1",1,1,443),(2,"LB","192.0.2.2",0,1,8443)');

require $root.'/panel/src/Core/Auth/Authorization.php';
require $root.'/panel/src/Core/Proxy/ProxyMonitor.php';
require $root.'/panel/src/Core/Proxy/ProxyDiagnostics.php';
require $root.'/panel/src/Domain/Assistant/ProxyTools.php';
define('XCVM_INDEPENDENT_LAB',true);
$rUserInfo=['id'=>7,'username'=>'Admin','member_group_id'=>3];$rPermissions=['is_admin'=>1,'advanced'=>[]];
$store=new ProxyProfiles($db,$dir);$p=$store->defaults();$p['name']='Test edge';$p['server_ip']='192.0.2.3';$p['enabled']=true;
try {
 $doc=$store->save(0,$p,'');$id=$doc['id'];$tool=new \XcVm\Domain\Assistant\ProxyTools('admin',$store);
 $status=$tool->call('proxy_status',['proxy_id'=>$id]);check($status['monitor_stale']&&$status['state']==='not_connected','Unknown monitor looked healthy');
 file_put_contents($dir.'/'.$id.'.status.json',json_encode(['last_sync'=>time(),'last_error'=>'SECRET_TOKEN','ready'=>false]));
 check(!str_contains(json_encode($tool->call('proxy_status',['proxy_id'=>$id])),'SECRET_TOKEN'),'Raw agent error leaked');
 check(count($tool->call('list_proxies',[])['proxies'])===1,'Proxy list');check(count($tool->call('proxy_parents',[])['parents'])===2,'Upstream list');
 refuses(fn()=>$tool->call('set_proxy_limit',['proxy_id'=>$id,'setting'=>'connection_limit','value'=>0]),'INVALID_ARGUMENTS');
 refuses(fn()=>$tool->call('set_proxy_limit',['proxy_id'=>$id,'setting'=>'shell','value'=>3]),'INVALID_ARGUMENTS');
 refuses(fn()=>$tool->call('set_proxy_enabled',['proxy_id'=>$id,'enabled'=>'false']),'INVALID_ARGUMENTS');
 refuses(fn()=>$tool->call('set_proxy_ports',['proxy_id'=>$id,'http_ports'=>[80],'https_ports'=>[80]]),'INVALID_PROXY_CONFIGURATION');
 refuses(fn()=>$tool->call('set_proxy_parents',['proxy_id'=>$id,'parent_ids'=>[999],'default_parent_id'=>999]),'INVALID_PROXY_CONFIGURATION');
 $args=['proxy_id'=>$id,'enabled'=>false];$proposal=$tool->call('set_proxy_enabled',$args)['proposal'];
 check($store->document($id)['revision']===$doc['revision'],'Proposal mutated configuration');
 check(str_contains(implode(' ',$proposal['public']['details']),'true → false'),'Missing before/after preview');
 $rPermissions['advanced']=['servers'];refuses(fn()=>$tool->execute($proposal),'FORBIDDEN');
 check(count($tool->definitions())===3,'Read-only admin received mutations');$rPermissions['advanced']=[];
 $saved=$tool->execute($proposal);check($saved['saved']&&$saved['proxy_pending'],'Saved config falsely treated as applied');
 check(!$store->document($id)['profile']['enabled'],'Disable not saved');
 $history=$store->history($id);check($history[0]['action']==='assistant'&&$history[0]['actor']['id']===7,'Missing native actor audit');
 refuses(fn()=>$tool->execute($proposal),'STALE_PROPOSAL');
 $proposal=$tool->call('set_proxy_limit',['proxy_id'=>$id,'setting'=>'connection_limit','value'=>2500])['proposal'];
 $doc=$store->document($id);$store->save($id,array_replace($doc['profile'],['read_timeout'=>120]),$doc['revision']);
 refuses(fn()=>$tool->execute($proposal),'STALE_PROPOSAL');
 $proposal=$tool->call('set_proxy_limit',['proxy_id'=>$id,'setting'=>'connection_limit','value'=>2500])['proposal'];$tool->execute($proposal);
 $p=$store->document($id)['profile'];check($p['connection_limit']===2500&&$p['read_timeout']===120,'Unrequested settings overwritten');
 $tool->execute($tool->call('set_proxy_ports',['proxy_id'=>$id,'http_ports'=>[8080],'https_ports'=>[8443]])['proposal']);
 $tool->execute($tool->call('set_proxy_parents',['proxy_id'=>$id,'parent_ids'=>[1,2],'default_parent_id'=>2])['proposal']);
 $p=$store->document($id)['profile'];check($p['http_ports']===[8080]&&$p['https_ports']===[8443]&&$p['default_parent_id']===2,'Ports/upstreams not saved');
 $reseller=new \XcVm\Domain\Assistant\ProxyTools('reseller',$store);check($reseller->definitions()===[],'Reseller definitions');refuses(fn()=>$reseller->execute($proposal),'FORBIDDEN');
 $rPermissions['advanced']=['users'];refuses(fn()=>$tool->call('proxy_status',['proxy_id'=>$id]),'FORBIDDEN');
 echo "PASS: native proxy saves/audits, explicit proposals, no secret leakage, strict bounds, permissions, replay and concurrent edits\n";
} finally {
 function cleanup(string $path):void{foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..'){is_dir($path.'/'.$name)?cleanup($path.'/'.$name):unlink($path.'/'.$name);}rmdir($path);}cleanup($dir);
}
