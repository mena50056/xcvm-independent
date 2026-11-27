<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach(['ProxyProfile','ProxyHistory','ProxyProfiles','ProxyDiagnostics','ProxyMonitor'] as $class)require $root.'/panel/src/Core/Proxy/'.$class.'.php';
require $root.'/panel/src/Domain/Server/ProxyKey.php';require $root.'/panel/src/Domain/Server/ProxyRoute.php';
use XcVm\Core\Proxy\{ProxyProfile,ProxyProfiles,ProxyHistory,ProxyDiagnostics,ProxyMonitor};
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,string $reason): void {try{$fn();}catch(Throwable $e){check($e->getMessage()===$reason,$e->getMessage());return;}throw new RuntimeException('Accepted '.$reason);}
$dir=sys_get_temp_dir().'/horus-monitor-'.bin2hex(random_bytes(5));mkdir($dir,0700);define('MAIN_HOME',$root.'/panel/src/');define('OPENSSL_EXTRA','unit-test-only');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON;CREATE TABLE servers(id INTEGER PRIMARY KEY AUTOINCREMENT,server_name TEXT,server_ip TEXT,server_type INTEGER DEFAULT 0,is_main INTEGER DEFAULT 0,enabled INTEGER,status INTEGER,proxy_key_gen INTEGER DEFAULT 0,proxy_signed INTEGER DEFAULT 0,parent_id TEXT,http_broadcast_port INTEGER,https_broadcast_port INTEGER,http_ports_add TEXT,https_ports_add TEXT,domain_name TEXT,total_clients INTEGER,enable_https INTEGER,last_check_ago INTEGER,watchdog_data TEXT);INSERT INTO servers(id,server_name,server_ip,is_main,enabled,https_broadcast_port)VALUES(1,"Main","192.0.2.1",1,1,443)');
$profiles=new ProxyProfiles($db,$dir);$p=$profiles->defaults();$p=array_replace($p,['name'=>'Horus edge','server_ip'=>'192.0.2.2','enabled'=>true,'parent_ids'=>[1],'default_parent_id'=>1,'http_ports'=>[80,8080],'https_ports'=>[443,8443]]);
check($p['monitor_enabled']&&$p['monitor_failures']===2&&$p['monitor_recoveries']===2,'Conservative monitoring defaults');
rejects(fn()=>ProxyProfile::normalize(array_replace($p,['monitor_timeout'=>99]),[1]),'INVALID_LIMIT');rejects(fn()=>ProxyProfile::normalize(array_replace($p,['monitor_ca'=>'-----BEGIN PRIVATE KEY-----']),[1]),'INVALID_MONITOR_CA');
$doc=$profiles->save(0,$p,'',['id'=>7,'name'=>'Operator']);$id=$doc['id'];$monitor=new ProxyMonitor($profiles);
check($profiles->applied($id)===null&&$monitor->snapshot($id)['ports']===[],'Unconfirmed listeners were probed/advertised');
$wire=$profiles->wire($id);$profiles->acknowledge($id,['applied_revision'=>$wire['revision'],'stats'=>['proxy_ready'=>true,'cpu'=>4,'nginx_active'=>17,'nginx_waiting'=>8]]);
check($profiles->applied($id)['revision']===$wire['revision'],'Confirmed snapshot not captured');
$targets=ProxyDiagnostics::targets($wire);check(array_column($targets,'id')===['http:80','http:8080','https:443','https:8443'],'All configured ports covered');
$nonce=str_repeat('a',32);$response=json_encode(['protocol'=>'horus-origin-v1','origin_id'=>1,'nonce'=>$nonce,'revision'=>$wire['revision']]);
$result=ProxyDiagnostics::evaluate($targets[0],$nonce,$response,['http_code'=>200,'connect_time'=>.001,'total_time'=>.05],0,time());check($result['state']==='healthy'&&$result['tcp_ms']===1.0,'Valid round trip rejected');
foreach([['{}',200,0,'INVALID_RESPONSE'],[$response,502,0,'UPSTREAM_FAILED'],[$response,404,0,'PROBE_UNAVAILABLE'],[$response,0,60,'TLS_FAILED'],[$response,0,28,'TIMEOUT']] as [$body,$http,$err,$code])check(ProxyDiagnostics::evaluate($targets[0],$nonce,$body,['http_code'=>$http],$err,time())['code']===$code,'Probe failure misclassified');
check(ProxyDiagnostics::evaluate($targets[0],str_repeat('b',32),$response,['http_code'=>200],0,time())['state']==='failed','A replayed/unrelated nonce looked healthy');
$malformed=json_encode(['protocol'=>'horus-origin-v1','origin_id'=>1,'nonce'=>[],'revision'=>$wire['revision']]);check(ProxyDiagnostics::evaluate($targets[0],$nonce,$malformed,['http_code'=>200],0,time())['code']==='INVALID_RESPONSE','Non-string nonce accepted');
$wrong=json_encode(['protocol'=>'horus-origin-v1','origin_id'=>9,'nonce'=>$nonce,'revision'=>$wire['revision']]);check(ProxyDiagnostics::evaluate($targets[0],$nonce,$wrong,['http_code'=>200],0,time())['code']==='ORIGIN_MISMATCH','Wrong origin accepted');
$wrong=json_encode(['protocol'=>'horus-origin-v1','origin_id'=>1,'nonce'=>$nonce,'revision'=>str_repeat('0',64)]);check(ProxyDiagnostics::evaluate($targets[0],$nonce,$wrong,['http_code'=>200],0,time())['code']==='REVISION_MISMATCH','Wrong worker revision accepted');
$calls=0;$now=time();$bad=function($w)use(&$calls){$calls++;return array_map(static fn($target)=>array_intersect_key($target,array_flip(['id','scheme','port','host','origin_id']))+['state'=>'failed','code'=>'CONNECT_FAILED','http_status'=>0,'tcp_ms'=>null,'tls_ms'=>null,'total_ms'=>0,'certificate_expires_at'=>null,'checked_at'=>time()],ProxyDiagnostics::targets($w));};
$good=static function($w)use($bad){return array_map(static fn($r)=>array_replace($r,['state'=>'healthy','code'=>'OK','http_status'=>200]),$bad($w));};
$a=$monitor->run($id,true,$bad,$now);check(count(array_filter($a['alerts'],fn($a)=>str_starts_with($a['key'],'port:')))===0,'Transient failure opened incidents');
$a=$monitor->run($id,true,$bad,$now+1);check($calls===1&&!empty($a['reused_check']),'Manual probe cooldown bypassed');
$a=$monitor->run($id,true,$bad,$now+11);$faults=array_values(array_filter($a['alerts'],fn($a)=>str_starts_with($a['key'],'port:')&&$a['state']==='active'));check(count($faults)===4,'Consecutive failures missing incidents');
$alertId=$faults[0]['id'];$monitor->acknowledge($id,$alertId,['id'=>7,'name'=>'Operator']);$a=$monitor->snapshot($id);$ack=array_values(array_filter($a['alerts'],fn($a)=>$a['id']===$alertId))[0];check($ack['acknowledged_at']!==null&&$ack['state']==='active','Acknowledgment hid or failed to persist an active incident');
for($i=0;$i<5;$i++)$monitor->snapshot($id);check($calls===2,'Reading dashboard ran network probes');
$a=$monitor->run($id,true,$good,$now+22);check(count(array_filter($a['alerts'],fn($a)=>str_starts_with($a['key'],'port:')&&$a['state']==='active'))===4,'One success resolved faults prematurely');
$a=$monitor->run($id,true,$good,$now+33);check(count(array_filter($a['alerts'],fn($a)=>str_starts_with($a['key'],'port:')&&$a['state']==='active'))===0,'Recovery not recorded');
$stale=$monitor->snapshot($id,$now+1000);check($stale['stale']&&$stale['ports'][0]['state']==='stale'&&$stale['metrics']['cpu']===null,'Stale data presented as healthy/fresh');
$changed=array_replace($p,['http_ports'=>[9090]]);$second=$profiles->save($id,$changed,$doc['revision'],['id'=>8,'name'=>'Second operator']);
check($monitor->snapshot($id)['pending']&&$monitor->snapshot($id)['ports'][0]['port']===80,'Pending settings replaced confirmed probe targets');
$history=$profiles->history($id);check(count($history)===2&&$history[0]['actor']['id']===8&&$history[0]['changes'][0]['field']==='http_ports','Change audit missing actor/diff');
rejects(fn()=>$profiles->save($id,$changed,$doc['revision']),'PROFILE_CONFLICT');check(count($profiles->history($id))===2,'Stale save left audit entries');
$restored=$profiles->restore($id,$history[1]['id'],$second['revision'],['id'=>7,'name'=>'Operator']);check($restored['profile']['http_ports']===[80,8080]&&$restored['revision']!==$doc['revision']&&$profiles->history($id)[0]['action']==='restore','Restore did not create a new audited desired revision');
rejects(fn()=>$profiles->restore($id,'../../secret',$restored['revision'],[]),'INVALID_HISTORY_ENTRY');
$key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'unit-test-ca'],$key);$cert=openssl_csr_sign($csr,null,$key,2);openssl_x509_export($cert,$pem);
$withCA=array_replace($p,['monitor_ca'=>$pem,'upstream_cas'=>[1=>$pem]]);$caDoc=$profiles->save($id,$withCA,$restored['revision'],['id'=>7,'name'=>'Operator']);$export=json_encode($profiles->history($id));check(!str_contains($export,'BEGIN CERTIFICATE')&&!str_contains($export,'PRIVATE KEY')&&str_contains($export,substr(hash('sha256',$pem),0,16)),'History API exposed raw certificate material');
// Commit failure after both the document and journal were staged must restore both.
$db->exec('CREATE TABLE audit_guard(id INTEGER PRIMARY KEY,server_id INTEGER REFERENCES servers(id) DEFERRABLE INITIALLY DEFERRED);CREATE TRIGGER fail_commit AFTER UPDATE OF server_name ON servers WHEN NEW.server_name="rollback-test" BEGIN INSERT INTO audit_guard(id,server_id) VALUES(1,99999);END');
$before=$profiles->document($id);$auditCount=count($profiles->history($id));try{$profiles->save($id,array_replace($withCA,['name'=>'rollback-test']),$caDoc['revision']);throw new RuntimeException('Commit failure accepted');}catch(PDOException){}
check($profiles->document($id)===$before&&count($profiles->history($id))===$auditCount,'Failed commit changed config or left phantom history');
function cleanup(string $path):void{foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..'){is_dir($path.'/'.$name)?cleanup($path.'/'.$name):unlink($path.'/'.$name);}rmdir($path);}cleanup($dir);
echo "Proxy diagnostics, nonce/origin/revision checks, cooldown, incident lifecycle, staleness, history, restore and rollback passed\n";
