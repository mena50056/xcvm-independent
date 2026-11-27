<?php
declare(strict_types=1);
namespace XcVm\Core\Config { class SettingsManager { public static bool $redis=false; public static function get($key) { return self::$redis; } } }
namespace XcVm\Infrastructure\Redis { class RedisManager { public static $store=null; public static function instance() { return self::$store; } } }
namespace XcVm\Domain\Line { class ActiveCodeService { public static function lineAwaitsRedemption($line) { return (bool)$line['is_activecode']; } } }
namespace XcVm\Domain\Server { class ServerRepository { public static function getAll() { return [1=>['enabled'=>1,'server_online'=>1,'password'=>'SERVER_SECRET']]; } } }
namespace {
require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
require __DIR__.'/../panel/src/Domain/Assistant/PlaybackTools.php';
function check($ok,$why) { if (!$ok) { throw new \RuntimeException($why); } }
function denied($fn) { try { $fn(); } catch (\RuntimeException $e) { check($e->getMessage()==='FORBIDDEN','Wrong denial');return; } throw new \RuntimeException('Missing denial'); }
$db=new class {
 public PDO $pdo;private $stmt;
 function __construct() {
  $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $this->pdo->exec('CREATE TABLE lines (id INTEGER, username TEXT, enabled INTEGER, admin_enabled INTEGER, exp_date INTEGER, is_activecode INTEGER, max_connections INTEGER, bouquet TEXT, allowed_outputs TEXT, allowed_ips TEXT, allowed_ua TEXT, is_isplock INTEGER, forced_country TEXT, force_server_id INTEGER, is_mag INTEGER, is_e2 INTEGER)');
  $this->pdo->exec("INSERT INTO lines VALUES (1,'customer',1,1,NULL,0,1,'[1]','[1,2]','[\"IP_SECRET\"]','[]',0,'',0,0,0)");
  $this->pdo->exec('CREATE TABLE lines_live (user_id INTEGER,hls_end INTEGER); INSERT INTO lines_live VALUES(1,0),(1,1),(2,0)');
  $this->pdo->exec('CREATE TABLE streams (id INTEGER,stream_display_name TEXT,direct_source INTEGER,type INTEGER); INSERT INTO streams VALUES(9,\'Channel\',0,1)');
  $this->pdo->exec("CREATE TABLE bouquets(id INTEGER,bouquet_channels TEXT); INSERT INTO bouquets VALUES(1,'[9]')");
  $this->pdo->exec('CREATE TABLE streams_servers(stream_id INTEGER,server_id INTEGER,pid INTEGER,stream_status INTEGER,on_demand INTEGER,to_analyze INTEGER); INSERT INTO streams_servers VALUES(9,1,0,0,1,0)');
  $this->pdo->exec('CREATE TABLE lines_logs(id INTEGER,user_id INTEGER,client_status TEXT,date INTEGER)');
 }
 function query($sql,...$args) { check(str_starts_with($sql,'SELECT'),'Diagnosis mutated state');$this->stmt=$this->pdo->prepare($sql);return $this->stmt->execute($args); }
 function get_raw_rows() { return $this->stmt->fetchAll(PDO::FETCH_ASSOC); }
};
$rUserInfo=['id'=>1,'member_group_id'=>3];$rPermissions=['is_admin'=>1,'advanced'=>[]];
$tool=new \XcVm\Domain\Assistant\PlaybackTools('admin');
$args=['line_id'=>1,'stream_id'=>9,'symptom'=>'buffering'];
$run=fn()=>$tool->call('diagnose_playback',$args);
$has=fn($r,$code)=>in_array($code,array_column($r['findings'],'code'),true);
$r=$run();check($has($r,'account_ready') && $has($r,'channel_allowed') && $has($r,'at_connection_limit'),'Account checks');
check($has($r,'stream_on_demand_idle')&&!$has($r,'restart_candidate'),'Idle stream must not be restarted');
check($r['connections']['count']===1,'Ended/other connections counted');
check(!str_contains(json_encode($r),'SECRET')&&!isset($r['proposal']),'Secrets or mutation leaked');
$db->pdo->exec('UPDATE lines SET exp_date=1,enabled=0,admin_enabled=0,is_activecode=1');
$r=$run();foreach(['expired','disabled','admin_banned','activation_pending'] as $code) { check($has($r,$code),$code); }
check(!$has($r,'account_ready'),'Blocked account marked ready');
$db->pdo->exec("UPDATE bouquets SET bouquet_channels='[]'; UPDATE streams_servers SET stream_status=1");
$r=$run();check($has($r,'channel_not_allowed')&&$has($r,'restart_candidate'),'Entitlement/down diagnosis');
$db->pdo->exec('UPDATE streams SET direct_source=1');$r=$run();check($has($r,'direct_source')&&!$has($r,'restart_candidate'),'Direct stream incorrectly restarted');
\XcVm\Core\Config\SettingsManager::$redis=true;
$r=$run();check(!$r['connections']['available']&&!$has($r,'at_connection_limit'),'Unavailable Redis fell back to SQL');
if (!function_exists('igbinary_unserialize')) { function igbinary_unserialize($raw) { return unserialize($raw,['allowed_classes'=>false]); } }
if (!function_exists('igbinary_serialize')) { function igbinary_serialize($record) { return serialize($record); } }
\XcVm\Infrastructure\Redis\RedisManager::$store=new class {
 function zCard($key) { return 4; }
 function zRange($key,$from,$to) { return ['live','ended','foreign','stale']; }
 function mGet($keys) { return [igbinary_serialize(['uuid'=>'live','user_id'=>1,'hls_end'=>0]),igbinary_serialize(['uuid'=>'ended','user_id'=>1,'hls_end'=>1]),igbinary_serialize(['uuid'=>'foreign','user_id'=>2,'hls_end'=>0]),false]; }
};
$r=$run();check($r['connections']['count']===1,'Redis counted ended, foreign or stale sessions');
$db->pdo->exec("INSERT INTO lines_logs VALUES(1,1,'USER_EXPIRED',".time()."),(2,1,'SECRET_RAW_STATUS',".time().")");
$r=$run();check(count($r['recent_refusals'])===1&&!str_contains(json_encode($r),'SECRET'),'Refusal whitelist failed');
$rPermissions['advanced']=['users'];$r=$tool->call('diagnose_playback',array_replace($args,['stream_id'=>0]));check(!$r['connections']['available'],'Permission leak');
denied($run);denied(fn()=>(new \XcVm\Domain\Assistant\PlaybackTools('reseller'))->call('diagnose_playback',$args));
$rPermissions['advanced']=['settings'];check($tool->definitions()===[],'Unauthorized definition');denied($run);
echo "PASS: read-only playback checks, permissions, expiry, activation, entitlement, on-demand, direct sources, Redis failure and secret redaction\n";
}
