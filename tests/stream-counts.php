<?php
declare(strict_types=1);
// Run with php -n: the fixture exposes Redis protocol replies without a live service.
namespace XcVm\Infrastructure\Redis {
    final class RedisManager { public static ?\Redis $redis=null; public static function instance(): ?\Redis {return self::$redis;} }
}
namespace {
    if (extension_loaded('redis')) { fwrite(STDERR,"Run this protocol fixture with php -n.\n");exit(1); }
    final class Redis {
        public array $sets=[], $records=[], $commands=[]; public bool $failed=false; private array $pending=[];
        public function multi(): self {$this->pending=[];return $this;}
        public function zCard($key): self {$this->commands[]='ZCARD';$this->pending[]=count($this->sets[$key]??[]);return $this;}
        public function zRevRangeByScore($key,$max,$min): self {$this->commands[]='ZRANGEBYSCORE';$this->pending[]=array_reverse($this->sets[$key]??[]);return $this;}
        public function exec(): array|false {return $this->failed?false:$this->pending;}
        public function mGet(array $keys): array {return array_map(fn($key)=>$this->records[$key]??false,$keys);}
    }
    function igbinary_unserialize(string $bytes): mixed {return unserialize($bytes,['allowed_classes'=>false]);}
    require dirname(__DIR__).'/panel/src/Infrastructure/Database/DatabaseAware.php';
    require dirname(__DIR__).'/panel/src/Domain/Stream/ConnectionTracker.php';
    use XcVm\Domain\Stream\ConnectionTracker;
    use XcVm\Infrastructure\Redis\RedisManager;
    function checkCounts(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
    $redis=new Redis();RedisManager::$redis=$redis;
    $redis->sets=['STREAM#11'=>['a','b'],'STREAM#12'=>[]];
    foreach(['a'=>1,'b'=>2] as $uuid=>$server)$redis->records[$uuid]=serialize(['uuid'=>$uuid,'stream_id'=>11,'server_id'=>$server]);
    checkCounts(ConnectionTracker::getStreamConnections([8=>11,20=>12],true,true)===[11=>2,12=>0],'Counts include empty streams and normalize sparse input keys');
    checkCounts($redis->commands===['ZCARD','ZCARD'],'A count must not download viewer identifiers');
    $rows=ConnectionTracker::getStreamConnections([11],false,false);
    checkCounts($rows[11][1][0]['uuid']==='a'&&$rows[11][2][0]['uuid']==='b','Detailed grouped records stay available');
    $redis->failed=true;checkCounts(ConnectionTracker::getStreamConnections([11],true,true)===[],'A failed Redis transaction is not a partial counter');
    RedisManager::$redis=null;checkCounts(ConnectionTracker::getStreamConnections([11],true,true)===[],'Unavailable store');
    checkCounts(ConnectionTracker::getStreamConnections([],true,true)===[],'Empty input needs no connection');
    echo "Stream counts use constant-size replies; detailed rows, empty streams, sparse IDs and unavailable stores passed\n";
}
