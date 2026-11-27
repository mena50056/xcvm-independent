<?php
namespace XcVm\Core\Proxy;

/** One short-lived, signed-channel service operation per proxy; no command strings. */
final class ProxyOperations {
    public function __construct(private ProxyProfiles $profiles) {}
    private function path(int $id): string {if($id<1)throw new \RuntimeException('INVALID_PROXY');return $this->profiles->directory().'/'.$id.'.operation.json';}
    private function read(int $id): array {$p=$this->path($id);return is_file($p)?json_decode(file_get_contents($p),true,32,JSON_THROW_ON_ERROR):[];}
    private function write(int $id,array $data): void {
        $path=$this->path($id);$temp=$path.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('OPERATION_STORAGE_FAILED');
        chmod($temp,0600);if(!rename($temp,$path)){@unlink($temp);throw new \RuntimeException('OPERATION_STORAGE_FAILED');}
    }
    private function locked(int $id,callable $fn): mixed {
        $lock=fopen($this->path($id).'.lock','c');if(!$lock)throw new \RuntimeException('OPERATION_STORAGE_FAILED');chmod($this->path($id).'.lock',0600);flock($lock,LOCK_EX);
        try{return $fn();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function state(int $id): array {
        $s=$this->read($id);$op=$s['operation']??null;
        if($op&&$op['state']==='queued'&&$op['expires_at']<time())$op['state']='expired';
        return ['supported'=>($s['version']??0)===1&&($s['seen_at']??0)>=time()-30,'operation'=>$op?array_intersect_key($op,array_flip(['id','state','created_at','expires_at','finished_at','code','verification'])):null];
    }
    public function verificationCandidate(int $id): ?array {return $this->read($id)['operation']??null;}
    public function recordVerification(int $id,string $operationId,array $result): void {
        $this->locked($id,function()use($id,$operationId,$result){$s=$this->read($id);if(($s['operation']['id']??'')!==$operationId)return;$s['operation']['verification']=$result;$this->write($id,$s);});
    }
    public function queue(int $id,string $revision,array $actor,array $evidence): array {
        return $this->locked($id,function()use($id,$revision,$actor,$evidence){
            $s=$this->read($id);$doc=$this->profiles->document($id);$row=$this->profiles->row($id);$status=$this->profiles->status($id);
            if(($s['version']??0)!==1||($s['seen_at']??0)<time()-30)throw new \RuntimeException('PROXY_RESTART_UNAVAILABLE');
            if(!hash_equals($doc['revision']??'',$revision))throw new \RuntimeException('STALE_PROPOSAL');
            if(empty($row['enabled'])||empty($doc['profile']['enabled'])||!$status['last_sync']||$status['last_sync']<time()-30||$status['desired_revision']!==$status['applied_revision'])throw new \RuntimeException('PROXY_RESTART_UNAVAILABLE');
            if(($s['operation']['state']??'')==='queued'&&$s['operation']['expires_at']>=time())throw new \RuntimeException('PROXY_OPERATION_PENDING');
            $op=['id'=>bin2hex(random_bytes(16)),'type'=>'restart','revision'=>$status['desired_revision'],'generation'=>(int)$row['proxy_key_gen'],'created_at'=>time(),'expires_at'=>time()+120,'state'=>'queued','actor'=>['id'=>(int)$actor['id'],'name'=>mb_substr((string)$actor['name'],0,80)],'evidence'=>$evidence];
            // Archive the previous record before replacing it; fail closed if audit storage fails.
            if(isset($s['operation'])){$dir=$this->profiles->directory().'/operations';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new \RuntimeException('OPERATION_STORAGE_FAILED');$path=$dir.'/'.$id.'-'.$s['operation']['id'].'.json';if(file_put_contents($path,json_encode($s['operation'],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('OPERATION_STORAGE_FAILED');chmod($path,0600);}
            $s['operation']=$op;$this->write($id,$s);return $this->state($id)['operation'];
        });
    }
    /** Only invoked after ProxySync verifies the per-node HMAC and nonce. */
    public function sync(int $id,array $data): ?array {
        return $this->locked($id,function()use($id,$data){
            $s=$this->read($id);$s['seen_at']=time();$s['version']=($data['operations_version']??0)===1?1:0;
            $result=$data['operation_result']??[];$op=$s['operation']??null;
            if($op&&($op['state']==='queued')&&($result['id']??'')===$op['id']&&in_array($result['state']??'', ['succeeded','failed','unknown','rejected'],true)) {
                $s['operation']['state']=$result['state'];$s['operation']['finished_at']=time();
                $s['operation']['code']=in_array($result['code']??'', ['RESTART_CONFIRMED','RESTART_FAILED','INTERRUPTED','EXPIRED','STALE_CONFIGURATION','UNSUPPORTED'],true)?$result['code']:'RESTART_FAILED';
            }
            $this->write($id,$s);$op=$s['operation']??null;
            if(!$op||$op['state']!=='queued'||$op['expires_at']<time()||$s['version']!==1)return null;
            return array_intersect_key($op,array_flip(['id','type','revision','generation','expires_at']));
        });
    }
}
