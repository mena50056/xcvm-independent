<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
use XcVm\Core\Util\SystemInfo;
use XcVm\Streaming\Fanout\FanoutClient;
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
$pdo=XC_VM::db_connect();$cpu=cpuSample();$network=networkSample();$historyAt=0;
while(true) {
    sleep(2);$current=cpuSample();$next=networkSample();$elapsed=max(.01,$next[2]-$network[2]);
    $s=SystemInfo::getStats();$total=$current[0]-$cpu[0];$idle=$current[1]-$cpu[1];
    $s['cpu']=$total>0?round(max(0,min(100,100*(1-$idle/$total))),2):0;
    $s['bytes_received']=(int)max(0,($next[0]-$network[0])/$elapsed);$s['bytes_sent']=(int)max(0,($next[1]-$network[1])/$elapsed);
    $s['bytes_received_total']=$next[0];$s['bytes_sent_total']=$next[1];
    $s['sampled_at']=time();$s['fanout']=FanoutClient::status();
    $counts=$pdo->query('SELECT COUNT(*) AS connections,COUNT(DISTINCT user_id) AS users FROM lines_live WHERE server_id=1 AND hls_end=0')->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('UPDATE servers SET watchdog_data=?,last_check_ago=?,connections=?,users=? WHERE id=1');$q->execute([json_encode($s,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),time(),$counts['connections'],$counts['users']]);
    if(time()-$historyAt>=10) {
        $row=['server_id'=>1,'connections'=>(int)$counts['connections'],'users'=>(int)$counts['users'],'time'=>time()];
        foreach(['cpu','cpu_cores','cpu_avg','total_mem','total_mem_free','total_mem_used','total_mem_used_percent','total_disk_space','uptime','bytes_sent','bytes_received','bytes_sent_total','bytes_received_total','cpu_load_average'] as $k)$row[$k]=$s[$k];
        $row['iostat_info']=json_encode($s['iostat_info']);$row['gpu_info']=json_encode($s['gpu_info']);
        $sql='INSERT INTO servers_stats (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
        $pdo->prepare($sql)->execute(array_values($row));$historyAt=time();
    }
    $cpu=$current;$network=$next;
}
