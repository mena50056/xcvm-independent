<?php
declare(strict_types=1);
require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
require __DIR__.'/../panel/src/Domain/Server/InstallCredentials.php';
require __DIR__.'/../panel/src/Domain/Assistant/InstallationStore.php';
require __DIR__.'/../panel/src/Domain/Assistant/InstallationWizard.php';
use XcVm\Domain\Assistant\{InstallationStore,InstallationWizard};
define('XCVM_INDEPENDENT_LAB',true);
function check($condition,$reason){if(!$condition)throw new RuntimeException($reason);}
function refused($fn,$code){try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$code,'Wrong refusal '.$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
$root=sys_get_temp_dir().'/assistant-install-'.bin2hex(random_bytes(8));
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE servers(id INTEGER PRIMARY KEY,server_name TEXT,server_ip TEXT,private_ip TEXT,server_type INTEGER,enabled INTEGER,https_broadcast_port INTEGER)');
$pdo->exec("INSERT INTO servers VALUES(1,'MAIN','8.8.8.8','10.1.0.1',0,1,443),(2,'Offline','8.8.4.4','',0,0,443)");
$db=(object)['dbh'=>$pdo];$rUserInfo=['id'=>1,'member_group_id'=>1];$rPermissions=['is_admin'=>1,'advanced'=>[]];
$store=new InstallationStore($root);$wizard=new InstallationWizard($store,$pdo);
function step(array $job,string $value): array {global $wizard;return $wizard->handle(['operation'=>'install_step','job_id'=>$job['id'],'token'=>$job['token'],'value'=>$value])['installation'];}
function finish(array $job,string $kind='proxy'):array {
 $values=['kind'=>$kind,'name'=>'QA node','ip'=>'9.9.9.9','ssh_port'=>'22','fingerprint'=>str_repeat('a',40),'parent_id'=>'1','tls_mode'=>'later','capacity'=>'100','fresh'=>'yes','password'=>'TEST_SECRET_DO_NOT_EXPOSE'];
 while($job['state']==='draft')$job=step($job,$values[$job['field']]);return $job;
}
try {
 check(InstallationWizard::requested('عاوز أنصب سيرفر بروكسي جديد'),'Arabic start shortcut');
 check(InstallationWizard::requested('Install a load balancer'),'English start shortcut');
 foreach(['لا تنصب بروكسي','Do not install a server','اشرح تنصيب بروكسي','restart proxy'] as $text)check(!InstallationWizard::requested($text),'Incorrect installation shortcut');
 $job=$wizard->handle(['operation'=>'install_begin'])['installation'];$id=$job['id'];
 refused(fn()=>$wizard->handle(['operation'=>'install_confirm','job_id'=>$id,'token'=>$job['token']]),'STALE_PROPOSAL');
 $job=step($job,'proxy');$old=$job;$job=step($job,'QA');
 refused(fn()=>step($old,'replay'),'STALE_PROPOSAL');
 foreach(['127.0.0.1','10.0.0.1','::1','localhost','8.8.8.8','9.9.9.9;id'] as $ip)refused(fn()=>step($job,$ip),$ip==='8.8.8.8'?'INSTALL_EXISTING_SERVER':'INVALID_ARGUMENTS');
 $job=step($job,'9.9.9.9');refused(fn()=>step($job,'0'),'INVALID_ARGUMENTS');$job=step($job,'22');
 refused(fn()=>step($job,'skip'),'INVALID_ARGUMENTS');$job=step($job,str_repeat('a',40));
 refused(fn()=>step($job,'2'),'INVALID_ARGUMENTS');$job=step($job,'1');$job=finish($job);
 check($job['state']==='review','Plan not reviewed');check(!str_contains(json_encode($job),'TEST_SECRET'),'Secret in public projection');
 $private=$store->read($id);check(!str_contains(json_encode($private),'TEST_SECRET'),'Password stored in plaintext');check($store->open($private['secret'])==='TEST_SECRET_DO_NOT_EXPOSE','Cannot decrypt worker credentials');
 check((int)$pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn()===2,'Wizard modified servers before confirmation');
 $rUserInfo['id']=2;refused(fn()=>$wizard->handle(['operation'=>'install_status','job_id'=>$id]),'FORBIDDEN');$rUserInfo['id']=1;
 $rPermissions['is_admin']=0;refused(fn()=>$wizard->handle(['operation'=>'install_confirm','job_id'=>$id,'token'=>$job['token']]),'FORBIDDEN');$rPermissions['is_admin']=1;
 $rUserInfo['member_group_id']=2;$rPermissions['advanced']=['users','add_server'];refused(fn()=>$wizard->handle(['operation'=>'install_status','job_id'=>$id]),'FORBIDDEN');$rUserInfo['member_group_id']=1;$rPermissions['advanced']=[];
 $queued=$wizard->handle(['operation'=>'install_confirm','job_id'=>$id,'token'=>$job['token']])['installation'];check($queued['state']==='queued','Not queued');
 refused(fn()=>$wizard->handle(['operation'=>'install_confirm','job_id'=>$id,'token'=>$job['token']]),'STALE_PROPOSAL');
 $wizard->handle(['operation'=>'install_cancel','job_id'=>$id,'token'=>$queued['token']]);check(!isset($store->read($id)['secret']),'Cancelled credentials retained');
 $job=finish($wizard->handle(['operation'=>'install_begin'])['installation'],'stream');
 check(!isset($job['plan']['parent_id']) && $job['plan']['kind']==='stream','Node steps incorrect');
 $private=$store->read($job['id']);$private['expires']=time()-1;$store->write($private);
 check($wizard->handle(['operation'=>'install_status','job_id'=>$job['id']])['installation']['state']==='expired','Review expiry');check(!isset($store->read($job['id'])['secret']),'Expired credentials retained');
 $job=finish($wizard->handle(['operation'=>'install_begin'])['installation'],'lb');
 $pdo->exec("INSERT INTO servers VALUES(3,'race','9.9.9.9','',0,1,443)");
 refused(fn()=>$wizard->handle(['operation'=>'install_confirm','job_id'=>$job['id'],'token'=>$job['token']]),'INSTALL_EXISTING_SERVER');$pdo->exec('DELETE FROM servers WHERE id=3');
 $queued=$wizard->handle(['operation'=>'install_confirm','job_id'=>$job['id'],'token'=>$job['token']])['installation'];
 $private=$store->read($job['id']);$private['state']='running';$private['started_at']=time()-2800;unset($private['secret']);$store->write($private);
 $unknown=$wizard->handle(['operation'=>'install_status','job_id'=>$job['id']])['installation'];check($unknown['state']==='unknown','Interrupted execution retried or falsely successful');
 $next=$wizard->handle(['operation'=>'install_begin'])['installation'];$next=step($next,'lb');$next=step($next,'next');refused(fn()=>step($next,'9.9.9.9'),'INSTALL_TARGET_BUSY');
 echo "PASS: admin-only staged installs, protected credentials, target validation, expiry, ownership, revocation, immutable confirmation, replay and uncertain execution\n";
} finally {foreach(glob($root.'/{*,.*}',GLOB_BRACE)?:[] as $file)if(is_file($file))unlink($file);rmdir($root);}
