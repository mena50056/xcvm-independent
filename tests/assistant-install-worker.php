<?php
declare(strict_types=1);
namespace XcVm\Core\Auth {class AuthRepository {public static function getPermissions($id){return ['is_admin'=>1,'advanced'=>[]];}}}
namespace XcVm\Core\Database {class QueryHelper {
 public static function verifyPostTable($table,$row){return $row;}
 public static function prepareArray($row){return ['columns'=>implode(',',array_keys($row)),'placeholder'=>implode(',',array_fill(0,count($row),'?')),'data'=>array_values($row)];}
}}
namespace XcVm\Core\Proxy {
 class ProxyProfiles {
  public static array $docs=[];
  public static function app(){return new self();}
  public function parents(){return [['id'=>7,'enabled'=>1,'https_broadcast_port'=>443,'is_main'=>1,'server_ip'=>'8.8.8.8']];}
  public function defaults(){return [];}
  public function save($id,$profile,$revision,$actor,$action){if(!$id){$GLOBALS['pdo']->prepare('INSERT INTO servers(server_ip,is_main) VALUES(?,0)')->execute([$profile['server_ip']]);$id=(int)$GLOBALS['pdo']->lastInsertId();} $doc=['id'=>$id,'revision'=>bin2hex(random_bytes(32)),'profile'=>$profile];self::$docs[$id]=$doc;return $doc;}
  public function document($id){return self::$docs[$id];}
  public function enrollment($id,$url,$ca){return ['schema'=>1,'key'=>str_repeat('b',64),'main_url'=>$url];}
  public function status($id){return ['state'=>'applied'];}
  public function wire($id){return self::$docs[$id];}
 }
 class ProxyDiagnostics {public static function run($wire,$timeout){return [['state'=>'healthy'],['state'=>$GLOBALS['tls_healthy']?'healthy':'failed']];}}
}
namespace XcVm\Cli\Commands {
 class SshSession {public static function open($ip,$port,&$error){$GLOBALS['ssh_calls']++;$job=$GLOBALS['store']->read($GLOBALS['active_job']);\check(!isset($job['secret']),'Credentials retained when remote work started');return new \stdClass();}}
 class IndependentLbInstall {public static int $calls=0;public static function execute($args){self::$calls++;[$pos,$opts]=\XcVm\Domain\Server\InstallCredentials::splitOptions($args);$cred=\XcVm\Domain\Server\InstallCredentials::consume($opts['cred-file']);\check($cred['password']==='WORKER_SECRET','Credentials did not reach fixed installer');\check(!str_contains(json_encode($args),'WORKER_SECRET'),'Secret in argv');$GLOBALS['pdo']->prepare('UPDATE servers SET status=1,enabled=1,last_check_ago=? WHERE id=?')->execute([time(),(int)$pos[1]]);return 0;}}
}
namespace {
 require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
 require __DIR__.'/../panel/src/Domain/Server/InstallCredentials.php';
 foreach(['InstallationStore','InstallationWizard','InstallationRunner'] as $file)require __DIR__.'/../panel/src/Domain/Assistant/'.$file.'.php';
 use XcVm\Domain\Assistant\{InstallationStore,InstallationRunner};
 define('XCVM_INDEPENDENT_LAB',true);define('SSH2_FINGERPRINT_SHA1',1);define('SSH2_FINGERPRINT_HEX',2);define('SSH2_STREAM_STDERR',1);
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function ssh2_fingerprint(...$args){return str_repeat('a',40);}
 function ssh2_auth_password($conn,$user,$password){check($user==='root'&&$password==='WORKER_SECRET','Wrong SSH credentials');return true;}
 function ssh2_disconnect($conn){}
 function ssh2_scp_send($conn,$local,$remote,$mode){check($mode===0600,'Remote secret permissions');$GLOBALS['uploads'][$remote]=hash_file('sha256',$local);return true;}
 function ssh2_exec($conn,$command){
  preg_match('/__AI_INSTALL_[a-f0-9]+__/',$command,$m);$output='';
  if(str_contains($command,'mktemp -d'))$output="/root/horus-ai-install.abcdefgh\n";
  if(str_contains($command,'sha256sum'))foreach($GLOBALS['uploads'] as $path=>$hash)if(str_contains($command,$path))$output=$hash."\n";
  $f=fopen('php://temp','w+');fwrite($f,$output."\n".$m[0].($GLOBALS['preflight_fails']?'1':'0')."\n");rewind($f);return $f;
 }
 function ssh2_fetch_stream(...$args){return fopen('php://temp','w+');}
 $root=sys_get_temp_dir().'/install-worker-'.bin2hex(random_bytes(6));mkdir($root,0700);define('BIN_PATH',$root.'/');define('MAIN_HOME',$root.'/panel/src/');define('CONFIG_PATH',$root.'/config/');mkdir($root.'/deployment');mkdir($root.'/tls');file_put_contents($root.'/tls/server.crt','TEST_CERT');foreach(['proxy_agent.py','proxy_config.py','install_proxy.py','proxy_ip_tls.py'] as $file)file_put_contents($root.'/deployment/'.$file,'TEST_PACKAGE');$uploads=[];$tls_healthy=true;
 $store=new InstallationStore($root.'/jobs');$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db=(object)['dbh'=>$pdo];
 $pdo->exec('CREATE TABLE users(id INTEGER,status INTEGER,member_group_id INTEGER);INSERT INTO users VALUES(1,1,1)');
 $pdo->exec('CREATE TABLE servers(id INTEGER PRIMARY KEY AUTOINCREMENT,server_name TEXT,server_ip TEXT,private_ip TEXT,server_type INTEGER,is_main INTEGER,enabled INTEGER,status INTEGER,serve_only INTEGER,total_clients INTEGER,network_interface TEXT,ssh_hostkey_sha1 TEXT,last_check_ago INTEGER)');
 $runner=new InstallationRunner($store,$pdo);$ssh_calls=0;$preflight_fails=false;
 function queueJob():array {global $store,$active_job;$active_job=bin2hex(random_bytes(16));$job=['id'=>$active_job,'actor'=>1,'state'=>'queued','phase'=>'queued','expires'=>time()+60,'secret'=>$store->seal('WORKER_SECRET'),'data'=>['kind'=>'lb','name'=>'test','ip'=>'9.9.9.9','ssh_port'=>22,'fingerprint'=>str_repeat('a',40),'capacity'=>100]];$store->write($job);return $job;}
 try {
  $job=queueJob();$runner->tick();$done=$store->read($job['id']);check($done['state']==='succeeded'&&$done['result']==='node_heartbeat_verified','Worker did not verify heartbeat');check(!isset($done['secret']),'Secret retained');check(!glob($root.'/install/*.cred'),'Credential handoff not consumed');
  $runner->tick();check(\XcVm\Cli\Commands\IndependentLbInstall::$calls===1&&$ssh_calls===1,'Worker replayed completed installation');
  $job=queueJob();$runner->tick();check($store->read($job['id'])['result']==='existing_server'&&$ssh_calls===1,'Existing server reached SSH');
  $pdo->exec('DELETE FROM servers');$preflight_fails=true;$job=queueJob();$runner->tick();check($store->read($job['id'])['result']==='fresh_server_required','Failed preflight ignored');check((int)$pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn()===0,'Created row after refused preflight');
  $pdo->exec('UPDATE users SET status=0');$job=queueJob();$before=$ssh_calls;$runner->tick();check($store->read($job['id'])['result']==='permission_revoked'&&$ssh_calls===$before,'Revoked actor executed');
  $pdo->exec('UPDATE users SET status=1');$preflight_fails=false;
  $job=queueJob();$job['data']['kind']='proxy';$job['data']['parent_id']=7;$job['data']['tls_mode']='public_ip';$store->write($job);$runner->tick();
  $done=$store->read($job['id']);check($done['state']==='succeeded'&&$done['result']==='proxy_ports_verified','Proxy verification failed');
  check(\XcVm\Core\Proxy\ProxyProfiles::$docs[$done['server_id']]['profile']['hosts'][0]['certificate']==='public-ip','Public TLS alias was not applied');
  check(!glob($root.'/jobs/.enrollment-*'),'Temporary enrollment retained');
  $pdo->exec('DELETE FROM servers');$tls_healthy=false;
  $job=queueJob();$job['data']['kind']='proxy';$job['data']['parent_id']=7;$job['data']['tls_mode']='later';$store->write($job);$runner->tick();check($store->read($job['id'])['state']==='attention','Failed TLS probe was treated as healthy');
  check(!str_contains(json_encode($store->all()),'WORKER_SECRET'),'Worker secret leakage');
  echo "PASS: durable worker, native installer boundary, fresh heartbeat, consumed credentials, no replay, preflight and revoked actor\n";
 } finally {foreach(['jobs','install','deployment','tls'] as $dir){foreach(glob($root.'/'.$dir.'/{*,.*}',GLOB_BRACE)?:[] as $file)if(is_file($file))unlink($file);if(is_dir($root.'/'.$dir))rmdir($root.'/'.$dir);}rmdir($root);}
}
