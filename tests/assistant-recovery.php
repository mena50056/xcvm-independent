<?php
declare(strict_types=1);
namespace XcVm\Core\Backup {
 class BackupIntegrity { public static function status(){return ['state'=>'success','time'=>100,'secret'=>'HIDDEN'];} }
 class BackupService { const PERIODS=['daily'=>86400]; }
}
namespace XcVm\Core\Config { class SettingsManager { public static function get($key){return 'daily';} } }
namespace XcVm\Core\Process { class ProcessRunner { public static array $calls=[]; public static function start($args){self::$calls[]=$args;return true;} } }
namespace XcVm\Domain\Assistant {
 class PlaybackTools {
  public static array $diagnosis=[];public static bool $fail=false;
  public function __construct($scope){}
  public function call($name,$args){if(self::$fail)throw new \RuntimeException('FAILED');return self::$diagnosis;}
 }
 class PanelTools {
  public static array $executed=[];public static bool $failCheck=false;
  public function __construct($scope){}
  public function call($name,$args){return ['proposal'=>['kind'=>'management','name'=>$name,'args'=>$args]];}
  public function execute($proposal){self::$executed[]=$proposal;if(self::$failCheck)PlaybackTools::$fail=true;return ['saved'=>true];}
 }
}
namespace {
 require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
 require __DIR__.'/../panel/src/Domain/Assistant/RecoveryTools.php';
 use XcVm\Domain\Assistant\RecoveryTools;
 use XcVm\Domain\Assistant\PlaybackTools;
 use XcVm\Domain\Assistant\PanelTools;
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function refused($fn,$code){try{$fn();}catch(\RuntimeException $e){check($e->getMessage()===$code,'Wrong refusal: '.$e->getMessage());return;}throw new \RuntimeException('Missing refusal');}
 $root=sys_get_temp_dir().'/recovery-'.bin2hex(random_bytes(6));mkdir($root,0700);
 define('MAIN_HOME',$root.'/');define('WATCH_TMP_PATH',$root.'/watch/');define('PHP_BIN','/fixed/php');
 $db=new \stdClass();
 $rUserInfo=['id'=>1,'member_group_id'=>3];$rPermissions=['is_admin'=>1,'advanced'=>[]];
 $tool=new RecoveryTools('admin');
 try {
  check((new RecoveryTools('reseller'))->definitions()===[],'Reseller exposure');
  refused(fn()=>(new RecoveryTools('reseller'))->call('diagnose_panel',[]),'FORBIDDEN');
  $args=['line_id'=>1,'stream_id'=>9,'symptom'=>'no_playback'];
  $base=['line_id'=>1,'stream_id'=>9,'instances'=>[],'report_ar'=>'لم يتم تغيير أي إعداد. أي إصلاح يحتاج طلبًا وتأكيدًا منفصلين.','report_en'=>'No settings were changed. Any fix needs a separate request and confirmation.'];
  PlaybackTools::$diagnosis=$base+['findings'=>[['code'=>'disabled','kind'=>'confirmed_blocker']]];
  $p=$tool->call('repair_playback',$args)['proposal'];
  check($p['action']['name']==='set_line_enabled' && !PanelTools::$executed,'Proposal executed early');
  PlaybackTools::$diagnosis['findings'][]=['code'=>'expired','kind'=>'confirmed_blocker'];
  check(!isset($tool->call('repair_playback',$args)['proposal']),'Expired line enabled');
  refused(fn()=>$tool->execute($p),'STALE_PROPOSAL');check(!PanelTools::$executed,'Stale repair executed');
  array_pop(PlaybackTools::$diagnosis['findings']);$answer=$tool->execute($p);
  check(count(PanelTools::$executed)===1 && isset($answer['recovery_report']),'Missing postcheck');
  check(!str_contains($answer['recovery_report']['en'],'No settings were changed'),'False postcheck claim');
  PlaybackTools::$diagnosis=$base+['findings'=>[['code'=>'account_ready','kind'=>'observation'],['code'=>'channel_allowed','kind'=>'observation']]];
  PlaybackTools::$diagnosis['instances']=[['server_id'=>1,'state'=>'reported_down','server_online'=>true]];
  check($tool->call('repair_playback',$args)['proposal']['action']['name']==='control_stream','Down stream not proposed');
  PlaybackTools::$diagnosis['instances'][]=['server_id'=>2,'state'=>'running','server_online'=>true];
  check(!isset($tool->call('repair_playback',$args)['proposal']),'Selected ambiguous server');
  array_pop(PlaybackTools::$diagnosis['instances']);PlaybackTools::$diagnosis['instances'][0]['state']='on_demand_idle';
  check(!isset($tool->call('repair_playback',$args)['proposal']),'Restarted idle stream');
  PlaybackTools::$diagnosis['instances'][0]['state']='reported_down';PlaybackTools::$diagnosis['instances'][0]['server_online']=false;
  check(!isset($tool->call('repair_playback',$args)['proposal']),'Restarted offline server');
  PlaybackTools::$diagnosis['instances'][0]['server_online']=true;$p=$tool->call('repair_playback',$args)['proposal'];PanelTools::$failCheck=true;
  check(str_contains($tool->execute($p)['recovery_report']['en'],'verification failed'),'Postcheck failure misreported');
  $rUserInfo['member_group_id']=4;$rPermissions['advanced']=['users'];
  refused(fn()=>$tool->call('diagnose_panel',[]),'FORBIDDEN');
  $rUserInfo['member_group_id']=3;$rPermissions['advanced']=[];
  $status=$tool->call('diagnose_panel',[]);check($status['queue']==='missing'&&!str_contains(json_encode($status),'HIDDEN'),'Panel status leaked secret');
  refused(fn()=>$tool->call('repair_import_queue',['path'=>'/tmp/untrusted']),'INVALID_ARGUMENTS');
  $p=$tool->call('repair_import_queue',[])['proposal'];check(!is_dir(WATCH_TMP_PATH),'Created before confirmation');
  $result=$tool->execute($p);check(is_dir(WATCH_TMP_PATH)&&isset($result['recovery_report']),'Queue repair failed');
  refused(fn()=>$tool->execute($p),'STALE_PROPOSAL');rmdir(WATCH_TMP_PATH);
  file_put_contents(rtrim(WATCH_TMP_PATH,'/'),'sentinel');
  check(!isset($tool->call('repair_import_queue',[])['proposal']),'Overwrote file');unlink(rtrim(WATCH_TMP_PATH,'/'));
  $p=$tool->call('create_panel_backup',[])['proposal'];check(!\XcVm\Core\Process\ProcessRunner::$calls,'Backup ran before confirmation');
  $answer=$tool->execute($p);
  check(\XcVm\Core\Process\ProcessRunner::$calls===[['/fixed/php',$root.'/console.php','cron:backups','1']],'Unsafe backup command');
  check(str_contains($answer['recovery_report']['en'],'not confirmed'),'Backup falsely complete');
  echo "Recovery proposals, stale state, permissions, postchecks and fixed maintenance actions passed.\n";
 } finally {if(is_dir(WATCH_TMP_PATH))rmdir(WATCH_TMP_PATH);rmdir($root);}
}
