<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

/** Bounded private snapshots and incident lifecycle. Page refreshes never run network probes. */
final class ProxyMonitor {
    public function __construct(private ProxyProfiles $profiles) {}
    private function path(int $id): string {
        if($id<1)throw new \InvalidArgumentException('INVALID_PROXY');
        return $this->profiles->directory().'/'.$id.'.monitor.json';
    }
    private function read(int $id): array {
        $file=$this->path($id);return is_file($file)?json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR):[];
    }
    public function lastCheckedAt(int $id): int {return (int)($this->read($id)['last_checked_at']??0);}
    private function write(int $id,array $state): void {
        $file=$this->path($id);$stage=$file.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($stage,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('MONITOR_STORAGE_FAILED');
        chmod($stage,0600);if(!rename($stage,$file)){@unlink($stage);throw new \RuntimeException('MONITOR_STORAGE_FAILED');}
    }
    public static function policy(array $profile): array {
        $policy=['monitor_enabled'=>$profile['monitor_enabled']??true];
        foreach(ProxyProfile::MONITOR_LIMITS as $key=>$range)$policy[$key]=(int)($profile[$key]??$range[2]);
        return $policy;
    }
    /** Consecutive observations open/resolve incidents. Acknowledging never hides an ongoing fault. */
    public static function transition(array $state,array $conditions,array $policy,int $now): array {
        $state+=['alerts'=>[],'events'=>[],'counters'=>[],'samples'=>[]];
        $keys=array_unique(array_merge(array_keys($conditions),array_keys($state['counters'])));
        foreach($keys as $key) {
            $condition=$conditions[$key]??null;$counter=$state['counters'][$key]??['failures'=>0,'recoveries'=>0];
            $active=null;foreach($state['alerts'] as $i=>$alert)if($alert['key']===$key&&$alert['state']==='active'){$active=$i;break;}
            if($condition!==null) {
                $counter['failures']=min(100,$counter['failures']+1);$counter['recoveries']=0;
                if($active===null&&$counter['failures']>=$policy['monitor_failures']) {
                    $alert=['id'=>bin2hex(random_bytes(16)),'key'=>$key,'state'=>'active','opened_at'=>$now,'last_seen_at'=>$now,'resolved_at'=>null,'acknowledged_at'=>null,'acknowledged_by'=>null]+$condition;
                    $state['alerts'][]=$alert;$state['events'][]=['at'=>$now,'type'=>'opened','key'=>$key,'code'=>$condition['code']];
                } elseif($active!==null) {$state['alerts'][$active]=array_replace($state['alerts'][$active],$condition,['last_seen_at'=>$now]);}
            } else {
                $counter['failures']=0;$counter['recoveries']=min(100,$counter['recoveries']+1);
                if($active!==null&&$counter['recoveries']>=$policy['monitor_recoveries']) {
                    $state['alerts'][$active]['state']='resolved';$state['alerts'][$active]['resolved_at']=$now;
                    $state['events'][]=['at'=>$now,'type'=>'resolved','key'=>$key,'code'=>$state['alerts'][$active]['code']];
                }
            }
            if($active===null&&$condition===null)unset($state['counters'][$key]);else $state['counters'][$key]=$counter;
        }
        // Preserve active incidents; retain only a bounded tail of resolved incidents/events.
        $active=array_values(array_filter($state['alerts'],static fn($a)=>$a['state']==='active'));
        $resolved=array_values(array_filter($state['alerts'],static fn($a)=>$a['state']!=='active'));
        $state['alerts']=array_merge(array_slice($resolved,-100),$active);$state['events']=array_slice($state['events'],-200);
        return $state;
    }
    private function conditions(array $ports,array $status,array $metrics,array $policy,int $now,bool $enabled): array {
        $conditions=[];if(!$enabled)return $conditions;
        if(!$status['last_sync']||$now-$status['last_sync']>30)$conditions['agent']=['code'=>'AGENT_OFFLINE','severity'=>'critical','target'=>'agent'];
        if($status['state']==='failed')$conditions['configuration']=['code'=>'APPLY_FAILED','severity'=>'critical','target'=>'configuration'];
        foreach($ports as $port) {
            if(in_array($port['state'],['failed','unsupported'],true))$conditions['port:'.$port['id']]=['code'=>$port['code'],'severity'=>$port['state']==='unsupported'?'warning':'critical','target'=>$port['id']];
            if($port['certificate_expires_at']!==null&&$port['certificate_expires_at']-$now<=$policy['certificate_warning_hours']*3600)$conditions['certificate:'.$port['id']]=['code'=>$port['certificate_expires_at']<=$now?'CERTIFICATE_EXPIRED':'CERTIFICATE_EXPIRING','severity'=>$port['certificate_expires_at']<=$now?'critical':'warning','target'=>$port['id']];
        }
        if($status['last_sync']&&$now-$status['last_sync']<=30)foreach(['cpu','total_mem_used_percent'] as $metric)if(isset($metrics[$metric])&&$metrics[$metric]>=90)$conditions[$metric]=['code'=>$metric==='cpu'?'CPU_HIGH':'MEMORY_HIGH','severity'=>'warning','target'=>'resources'];
        return $conditions;
    }
    public function run(int $id,bool $manual=false,?callable $probe=null,?int $now=null): array {
        $now??=time();$row=$this->profiles->row($id);if(!$row)throw new \InvalidArgumentException('INVALID_PROXY');
        $lock=fopen($this->path($id).'.lock','c');if(!$lock)throw new \RuntimeException('MONITOR_STORAGE_FAILED');chmod($this->path($id).'.lock',0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \RuntimeException('PROBE_BUSY');}
        try {
            $state=$this->read($id);$doc=$this->profiles->document($id);$policy=self::policy($doc['profile']??[]);
            $interval=$manual?10:$policy['monitor_interval'];
            if($now-(int)($state['last_checked_at']??0)<$interval)return $this->snapshot($id,$now)+['reused_check'=>true];
            $wire=$this->profiles->applied($id);$status=$this->profiles->status($id);$metrics=json_decode((string)($row['watchdog_data']??''),true)?:[];
            $enabled=$policy['monitor_enabled']&&$wire!==null&&!empty($wire['profile']['enabled'])&&!empty($row['enabled']);$ports=[];
            if($enabled)$ports=$probe?($probe)($wire,$policy):ProxyDiagnostics::run($wire,$policy['monitor_timeout'],(string)($doc['profile']['monitor_ca']??''));
            $state=self::transition($state,$this->conditions($ports,$status,$metrics,$policy,$now,$enabled),$policy,$now);
            $state['last_checked_at']=$now;$state['ports']=$ports;$state['checked_revision']=$wire['revision']??null;
            $state['samples'][]=['at'=>$now,'healthy'=>count(array_filter($ports,static fn($p)=>$p['state']==='healthy')),'total'=>count($ports),
                'cpu'=>$metrics['cpu']??null,'out_mbps'=>isset($metrics['bytes_sent'])?round((float)$metrics['bytes_sent']*8/1000000,2):null];
            $state['samples']=array_slice($state['samples'],-120);$this->write($id,$state);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return $this->snapshot($id,$now);
    }
    public function acknowledge(int $id,string $alertId,array $actor): void {
        if(!$this->profiles->row($id)||!preg_match('/^[a-f0-9]{32}\z/',$alertId))throw new \InvalidArgumentException('INVALID_ALERT');
        $lock=fopen($this->path($id).'.lock','c');if(!$lock)throw new \RuntimeException('MONITOR_STORAGE_FAILED');flock($lock,LOCK_EX);
        try {
            $state=$this->read($id);$found=false;
            $state['alerts']??=[];foreach($state['alerts'] as &$alert)if($alert['id']===$alertId) {
                if($alert['acknowledged_at']===null){$alert['acknowledged_at']=time();$alert['acknowledged_by']=['id'=>(int)($actor['id']??0),'name'=>mb_substr((string)($actor['name']??''),0,80)];}
                $found=true;break;
            }unset($alert);
            if(!$found)throw new \InvalidArgumentException('INVALID_ALERT');$this->write($id,$state);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function snapshot(int $id,?int $now=null): array {
        $now??=time();$row=$this->profiles->row($id);if(!$row)throw new \InvalidArgumentException('INVALID_PROXY');
        $state=$this->read($id);$doc=$this->profiles->document($id);$policy=self::policy($doc['profile']??[]);$status=$this->profiles->status($id);$applied=$this->profiles->applied($id);
        $stale=!isset($state['last_checked_at'])||$now-$state['last_checked_at']>max(120,$policy['monitor_interval']*3);
        $ports=[];
        if($applied!==null)foreach(ProxyDiagnostics::targets($applied) as $target) {
            $observed=null;foreach($state['ports']??[] as $port)if($port['id']===$target['id']&&($state['checked_revision']??null)===$applied['revision'])$observed=$port;
            $port=$observed??array_intersect_key($target,array_flip(['id','scheme','port','host','origin_id']))+['state'=>'not_checked','code'=>'NOT_CHECKED','http_status'=>null,'tcp_ms'=>null,'tls_ms'=>null,'total_ms'=>null,'certificate_expires_at'=>null,'checked_at'=>null];
            if(!$policy['monitor_enabled']||empty($applied['profile']['enabled'])||empty($row['enabled']))$port['state']='disabled';elseif($stale&&$observed!==null)$port['state']='stale';$ports[]=$port;
        }
        $metrics=json_decode((string)($row['watchdog_data']??''),true)?:[];$fresh=$status['last_sync']&&$now-$status['last_sync']<=30;
        $safe=[];foreach(['cpu','total_mem_used_percent','bytes_sent','bytes_received','nginx_active','nginx_reading','nginx_writing','nginx_waiting','nginx_requests'] as $key)$safe[$key]=$fresh&&isset($metrics[$key])?(float)$metrics[$key]:null;
        $alerts=array_reverse($state['alerts']??[]);$enabled=$policy['monitor_enabled']&&!empty($row['enabled']);
        if($enabled&&$stale)$alerts[]=['id'=>'monitor-stale','key'=>'monitor','state'=>'active','severity'=>'warning','code'=>'MONITOR_STALE','target'=>'monitor','opened_at'=>$state['last_checked_at']??null,'acknowledged_at'=>null,'virtual'=>true];
        if($enabled&&!$fresh&&!array_filter($alerts,static fn($a)=>$a['key']==='agent'&&$a['state']==='active'))$alerts[]=['id'=>'agent-offline','key'=>'agent','state'=>'active','severity'=>'critical','code'=>'AGENT_OFFLINE','target'=>'agent','opened_at'=>$status['last_sync'],'acknowledged_at'=>null,'virtual'=>true];
        return ['id'=>$id,'name'=>$row['server_name'],'ip'=>$row['server_ip'],'enabled'=>(bool)$row['enabled'],'status'=>$status,'policy'=>$policy,'stale'=>$stale,'last_checked_at'=>$state['last_checked_at']??null,
            'ports'=>$ports,'metrics'=>$safe,'alerts'=>$alerts,'events'=>array_reverse($state['events']??[]),'samples'=>$state['samples']??[],
            'desired_ports'=>['http'=>$doc['profile']['http_ports']??[],'https'=>$doc['profile']['https_ports']??[]],'pending'=>$status['desired_revision']!==$status['applied_revision'],'server_time'=>$now];
    }
}
