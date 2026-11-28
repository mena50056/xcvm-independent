<?php
declare(strict_types=1);
$root=dirname(__DIR__);foreach(['ProxyProfile','ProxyProfiles','ProxyHistory','ServerProxyControl'] as $class)require $root.'/panel/src/Core/Proxy/'.$class.'.php';require $root.'/panel/src/Domain/Server/ProxyRoute.php';
use XcVm\Core\Proxy\{ProxyProfiles,ServerProxyControl};
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function refuses(callable $fn,string $code):void{try{$fn();}catch(Throwable $e){check($e->getMessage()===$code,$e->getMessage());return;}throw new RuntimeException('Accepted '.$code);}
define('MAIN_HOME',$root.'/panel/src/');define('OPENSSL_EXTRA','test-only-routing-seed');
$directory=sys_get_temp_dir().'/horus-server-proxy-'.bin2hex(random_bytes(6));mkdir($directory,0700);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE servers(id INTEGER PRIMARY KEY AUTOINCREMENT,server_name TEXT,server_ip TEXT,server_type INTEGER DEFAULT 0,is_main INTEGER DEFAULT 0,enabled INTEGER,status INTEGER,enable_proxy INTEGER DEFAULT 0,proxy_key_gen INTEGER DEFAULT 0,proxy_signed INTEGER DEFAULT 0,parent_id TEXT,http_broadcast_port INTEGER,https_broadcast_port INTEGER,http_ports_add TEXT,https_ports_add TEXT,domain_name TEXT,total_clients INTEGER,enable_https INTEGER,last_check_ago INTEGER,watchdog_data TEXT);INSERT INTO servers(id,server_name,server_ip,is_main,enabled,https_broadcast_port) VALUES(1,"Main","192.0.2.1",1,1,443),(2,"LB","192.0.2.2",0,1,443),(3,"LB2","192.0.2.4",0,1,443)');
$profiles=new ProxyProfiles($db,$directory);$p=$profiles->defaults();$p['name']='Horus edge';$p['server_ip']='192.0.2.3';$p['enabled']=true;$p['http_ports']=[80,8080];$p['https_ports']=[443,8443];$p['hosts']=[['host'=>'vod.example.test','parent_id'=>1,'certificate'=>'auto']];
$doc=$profiles->save(0,$p,'');$pid=$doc['id'];unset($doc['id']);$original=$doc['profile'];
$control=new ServerProxyControl($profiles,fn($parent,$profile)=>$profile);$actor=['id'=>9,'name'=>'Operator'];
$flag=fn($id)=>(int)$db->query('SELECT enable_proxy FROM servers WHERE id='.(int)$id)->fetchColumn();
$ack=function(?string $revision=null,bool $ready=true,string $error='')use($profiles,$pid):void{$profiles->acknowledge($pid,['applied_revision'=>$revision??$profiles->wire($pid)['revision'],'last_error'=>$error,'stats'=>['proxy_ready'=>$ready]]);};
try {
    refuses(fn()=>$control->change([$pid],true,null,$actor),'INVALID_SERVERS');
    $failing=new ServerProxyControl($profiles,function($parent,$profile){if((int)$parent['id']===3)throw new RuntimeException('UPSTREAM_TLS_UNTRUSTED: 3');return $profile;});
    refuses(fn()=>$failing->change([2,3],true,null,$actor),'UPSTREAM_TLS_UNTRUSTED: 3');check($profiles->document($pid)===$doc&&!$flag(2),'TLS preflight failure partially changed profile');
    $state=$control->change([2,3],true,null,$actor);check($state['state']==='pending'&&!$flag(2)&&!$flag(3),'Enabled before agent acknowledgement');
    $saved=$profiles->document($pid);check($saved['profile']['parent_ids']===[1,2,3],'Upstreams not added');
    foreach(['default_parent_id','hosts','http_ports','https_ports','connection_limit','monitor_enabled'] as $key)check($saved['profile'][$key]===$original[$key],'Existing configuration changed: '.$key);
    $ack(str_repeat('a',64));check(!$flag(2),'Stale acknowledgement enabled routing');$ack(null,false);check(!$flag(2),'Unready acknowledgement enabled routing');$ack(null,true,'BIND_FAILED');check(!$flag(2),'Failed acknowledgement enabled routing');
    // No browser polling is required: the next authenticated acknowledgement completes it.
    $ack();check($flag(2)===1&&$flag(3)===1&&$control->status([2,3])['state']==='applied','Matching healthy acknowledgement did not finish');
    $before=$profiles->wire($pid);$control->change([2],false,null,$actor);check(!$flag(2)&&$flag(3)===1,'Disable affected another server');check($profiles->wire($pid)===$before,'Disable removed routes used by existing viewers');
    $control->change([2],true,null,$actor);$control->change([2],false,null,$actor);$ack();check(!$flag(2),'Delayed enable acknowledgement undid Disable');
    $control->change([2],true,null,$actor);$edited=$profiles->document($pid);$edited['profile']['monitor_latency_ms']=1500;$profiles->save($pid,$edited['profile'],$edited['revision']);$ack();check($flag(2)===1,'Unrelated profile edit lost durable routing intent');
    $p2=$p;$p2['name']='Second edge';$p2['server_ip']='192.0.2.5';$profiles->save(0,$p2,'');
    $profiles->save($pid,$p,$profiles->document($pid)['revision']);
    $count=count($profiles->history($pid));$choice=$control->change([2],true,null,$actor);check($choice['state']==='choose_proxy'&&count($choice['candidates'])===2&&count($profiles->history($pid))===$count,'Ambiguous proxies silently selected');
    $control->change([2],true,$pid,$actor);check(in_array(2,$profiles->document($pid)['profile']['parent_ids'],true),'Explicit selection ignored');
    $disabled=$profiles->document($pid);$disabled['profile']['enabled']=false;$profiles->save($pid,$disabled['profile'],$disabled['revision']);check($profiles->document($pid)['enable_parents']===[],'Disabled proxy retained enable intent');
    echo "Server proxy binding, bulk preflight, acknowledgement, durable intent, cancellation, selection and session preservation checks passed\n";
} finally {
    $remove=function($path)use(&$remove){foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..'){is_dir($path.'/'.$name)?$remove($path.'/'.$name):unlink($path.'/'.$name);}rmdir($path);};$remove($directory);
}
