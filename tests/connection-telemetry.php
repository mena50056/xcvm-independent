<?php
declare(strict_types=1);
require dirname(__DIR__).'/panel/src/Core/Cluster/StoredConnections.php';
require dirname(__DIR__).'/runtime/ConnectionTelemetry.php';
if (!function_exists('igbinary_unserialize')) { function igbinary_unserialize(string $data): mixed { return unserialize($data, ['allowed_classes'=>false]); } }
function encoded(array $row): string { return function_exists('igbinary_serialize') ? igbinary_serialize($row) : serialize($row); }
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE servers(id INTEGER); INSERT INTO servers VALUES(1),(2),(3); CREATE TABLE lines_live(server_id INTEGER,proxy_id INTEGER,user_id INTEGER,hmac_id INTEGER,hmac_identifier TEXT,hls_end INTEGER)');
$records=[];
foreach ([['a',1,7,0,'',3],['b',1,7,0,'',0],['c',2,7,0,'',0],['d',2,8,0,'',0],['e',1,0,9,'alpha',0],['f',1,0,9,'beta',0],['ended',1,99,0,'',0],['orphan',99,88,0,'',0]] as [$uuid,$sid,$uid,$hid,$name,$proxy]) {
    $record=['uuid'=>$uuid,'server_id'=>$sid,'user_id'=>$uid,'hmac_id'=>$hid,'hmac_identifier'=>$name,'proxy_id'=>$proxy,'hls_end'=>$uuid==='ended'?1:0];
    $records[$uuid]=encoded($record);
    $db->prepare('INSERT INTO lines_live VALUES(?,?,?,?,?,?)')->execute([$sid,$proxy,$uid,$hid,$name,$record['hls_end']]);
}
$records['deleted']=false;$records['mismatch']=encoded(['uuid'=>'wrong','server_id'=>1,'user_id'=>99]);
$redis=new class($records) {
    public int $maxBatch=0;public bool $failed=false;public bool $readFailed=false;
    public function __construct(public array $records){}
    public function zRange($key,$start,$end){if($this->failed)return false;return array_slice(array_keys($this->records),$start,$end-$start+1);}
    public function mGet($keys){if($this->readFailed)return false;$this->maxBatch=max($this->maxBatch,count($keys));return array_map(fn($key)=>$this->records[$key],$keys);}
};
$expected=['servers'=>[1=>['connections'=>4,'users'=>3],2=>['connections'=>2,'users'=>2],3=>['connections'=>1,'users'=>1]],'total_users'=>4];
verify(IndependentConnectionTelemetry::sample($db,true,$redis)===$expected,'Redis open records, unique owners, proxy, ended and orphan filters');
verify(IndependentConnectionTelemetry::sample($db,false,null)===$expected,'MySQL and Redis counters agree');
$redis->failed=true;
verify(IndependentConnectionTelemetry::sample($db,true,$redis)===null,'An unavailable Redis must not become zero or stale MySQL data');
verify(IndependentConnectionTelemetry::sample($db,true,null)===null,'No Redis connector is unavailable');
$redis->failed=false;$redis->readFailed=true;
verify(IndependentConnectionTelemetry::sample($db,true,$redis)===null,'A failed batch read never becomes a partial counter');
$redis->readFailed=false;$redis->records=[];
for($i=0;$i<2505;$i++)$redis->records['uuid-'.$i]=encoded(['uuid'=>'uuid-'.$i,'server_id'=>1,'user_id'=>7,'hls_end'=>0]);
$large=IndependentConnectionTelemetry::sample($db,true,$redis);
verify($large['servers'][1]===['connections'=>2505,'users'=>1]&&$redis->maxBatch<=1000,'Large connection snapshots use bounded batches');
$redis->records=[];
verify(IndependentConnectionTelemetry::sample($db,true,$redis)['total_users']===0,'A healthy empty store is zero');
echo "PASS: connection telemetry (stores, unique users/HMAC, proxies, ended/stale records, failures, 2505 viewers)\n";
