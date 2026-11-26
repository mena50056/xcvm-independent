<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
use XcVm\Core\Util\SystemInfo;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Infrastructure\Redis\RedisManager;
require_once __DIR__.'/ConnectionTelemetry.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
function cpuSample():array {
    $line=file('/proc/stat')[0];$a=array_map('intval',preg_split('/\s+/',trim(substr($line,3))));
    return [array_sum(array_slice($a,0,8)),($a[3]??0)+($a[4]??0)];
}
function networkSample():array {
    $rx=$tx=0;foreach(glob('/sys/class/net/*') as $p){if(basename($p)==='lo')continue;$rx+=(int)@file_get_contents($p.'/statistics/rx_bytes');$tx+=(int)@file_get_contents($p.'/statistics/tx_bytes');}
    return [$rx,$tx,microtime(true)];
}
$pdo=XC_VM::db_connect();$cpu=cpuSample();$network=networkSample();$historyAt=$settingsAt=$countsAt=0;
$q=$pdo->prepare('SELECT watchdog_data,connections,users FROM servers WHERE id=?');$q->execute([SERVER_ID]);$initial=$q->fetch(PDO::FETCH_ASSOC);$previous=json_decode((string)$initial['watchdog_data'],true)['connection_counts']??[];
$countsAt=(int)($previous['sampled_at']??0);$totalUsers=(int)($previous['total_users']??SettingsManager::get('total_users'));
$counts=['connections'=>(int)$initial['connections'],'users'=>(int)$initial['users']];$published=[];$publishedTotal=null;
$writeCounts=$pdo->prepare('UPDATE servers SET connections=?,users=? WHERE id=?');$writeTotal=$pdo->prepare('UPDATE settings SET total_users=?');
while(true) {
    sleep(2);$current=cpuSample();$next=networkSample();$elapsed=max(.01,$next[2]-$network[2]);
    $s=SystemInfo::getStats();$total=$current[0]-$cpu[0];$idle=$current[1]-$cpu[1];
    $s['cpu']=$total>0?round(max(0,min(100,100*(1-$idle/$total))),2):0;
    $s['bytes_received']=(int)max(0,($next[0]-$network[0])/$elapsed);$s['bytes_sent']=(int)max(0,($next[1]-$network[1])/$elapsed);
    $s['bytes_received_total']=$next[0];$s['bytes_sent_total']=$next[1];
    $s['sampled_at']=time();$s['fanout']=FanoutClient::status();
    if(time()-$settingsAt>=10){SettingsManager::set(SettingsRepository::getAll(true));$settingsAt=time();}
    $redisEnabled=(bool) SettingsManager::get('redis_handler');
    $snapshot=IndependentConnectionTelemetry::sample($pdo,$redisEnabled,$redisEnabled?RedisManager::instance():null);
    // A failed read can leave a stale socket that ping's 30-second debounce has
    // not noticed. Discard it; instance() reconnects with its existing retry backoff.
    if($redisEnabled&&$snapshot===null)RedisManager::closeInstance();
    if($snapshot!==null){
        foreach($snapshot['servers'] as $id=>$count){
            if(($published[$id]??null)!==$count){$writeCounts->execute([$count['connections'],$count['users'],$id]);$published[$id]=$count;}
        }
        $totalUsers=$snapshot['total_users'];$countsAt=time();
        if($publishedTotal!==$totalUsers){$writeTotal->execute([$totalUsers]);$publishedTotal=$totalUsers;}
        $counts=$snapshot['servers'][SERVER_ID]??$counts;
    }
    $s['connection_counts']=['available'=>$snapshot!==null,'source'=>$redisEnabled?'redis':'mysql','sampled_at'=>$countsAt,'total_users'=>$totalUsers];
    $q=$pdo->prepare('UPDATE servers SET watchdog_data=?,last_check_ago=? WHERE id=?');$q->execute([json_encode($s,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),time(),SERVER_ID]);
    if(time()-$historyAt>=10) {
        $row=['server_id'=>SERVER_ID,'connections'=>(int)$counts['connections'],'users'=>(int)$counts['users'],'total_users'=>$totalUsers,'time'=>time()];
        $q=$pdo->prepare('SELECT COUNT(*) FROM streams_servers JOIN streams ON streams.id=streams_servers.stream_id WHERE server_id=? AND pid>0 AND type=1');$q->execute([SERVER_ID]);$row['streams']=(int)$q->fetchColumn();
        foreach(['cpu','cpu_cores','cpu_avg','total_mem','total_mem_free','total_mem_used','total_mem_used_percent','total_disk_space','uptime','bytes_sent','bytes_received','bytes_sent_total','bytes_received_total','cpu_load_average'] as $k)$row[$k]=$s[$k];
        $row['iostat_info']=json_encode($s['iostat_info']);$row['gpu_info']=json_encode($s['gpu_info']);
        $sql='INSERT INTO servers_stats (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
        $pdo->prepare($sql)->execute(array_values($row));$historyAt=time();
    }
    $cpu=$current;$network=$next;
}
