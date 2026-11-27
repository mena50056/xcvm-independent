<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

use XcVm\Domain\Server\{ProxyKey,ProxyRoute};

/** Private desired configuration and authenticated acknowledgements, independent of vendor archives. */
final class ProxyProfiles {
    public function __construct(private \PDO $db, private string $directory) {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('PROFILE_STORAGE_FAILED');
    }
    public static function app(): self {
        $db=\XcVm\Infrastructure\Database\DatabaseFactory::get();$db->query('SELECT 1');
        return new self($db->dbh,CONFIG_PATH.'horus_proxy');
    }
    private function query(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function path(int $id,string $suffix=''): string {if($id<1)throw new \InvalidArgumentException('INVALID_PROXY');return $this->directory.'/'.$id.$suffix.'.json';}
    private function read(string $path): array {if(!is_file($path))return [];return json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);}
    private function write(string $path,array $data): void {
        $temporary=$path.'.'.bin2hex(random_bytes(6));
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)){@unlink($temporary);throw new \RuntimeException('PROFILE_STORAGE_FAILED');}
        chmod($temporary,0600);if(!rename($temporary,$path)){@unlink($temporary);throw new \RuntimeException('PROFILE_STORAGE_FAILED');}
    }
    public function parents(): array {return $this->query('SELECT * FROM servers WHERE server_type <> 1 ORDER BY is_main DESC,id')->fetchAll(\PDO::FETCH_ASSOC);}
    public function row(int $id): array {return $this->query('SELECT * FROM servers WHERE id=? AND server_type=1',[$id])->fetch(\PDO::FETCH_ASSOC)?:[];}
    public function document(int $id): array {return $this->read($this->path($id));}
    public function defaults(?array $row=null): array {
        $parents=$this->parents();$ids=$row?json_decode((string)$row['parent_id'],true):[(int)($parents[0]['id']??0)];
        if(!is_array($ids))$ids=[(int)$ids];$ids=array_map('intval',$ids);
        $p=['name'=>$row['server_name']??'','server_ip'=>$row['server_ip']??'','enabled'=>(bool)($row['enabled']??false),'parent_ids'=>$ids,'default_parent_id'=>$ids[0]??0,
            'http_ports'=>$row?array_values(array_filter(array_merge([(int)$row['http_broadcast_port']],ProxyProfile::ports((string)($row['http_ports_add']??''))))):[80],
            'https_ports'=>$row?array_values(array_filter(array_merge([(int)$row['https_broadcast_port']],ProxyProfile::ports((string)($row['https_ports_add']??''))))):[443],
            'hosts'=>[],'allow_ip_access'=>true,'reject_unknown_hosts'=>true,'allow_admin'=>false,'http2'=>true,'trusted_proxies'=>[],
            'outbound_bind_ip'=>'','upstream_cas'=>[],'upstream_tls_names'=>[]];
        foreach(explode(',',(string)($row['domain_name']??'')) as $host)if(trim($host)!=='')$p['hosts'][]=['host'=>$host,'parent_id'=>0,'certificate'=>'auto'];
        foreach(ProxyProfile::LIMITS as $key=>$range)$p[$key]=$range[2];return $p;
    }
    public function save(int $id,array $input,string $baseRevision): array {
        $lock=fopen($this->directory.'/save.lock','c');if(!$lock)throw new \RuntimeException('PROFILE_STORAGE_FAILED');chmod($this->directory.'/save.lock',0600);flock($lock,LOCK_EX);
        $old=null;$path=null;
        try {
            $row=$id?$this->row($id):[];if($id&&!$row)throw new \InvalidArgumentException('INVALID_PROXY');
            $document=$id?$this->document($id):[];
            if(!hash_equals((string)($document['revision']??''),$baseRevision))throw new \RuntimeException('PROFILE_CONFLICT');
            $p=ProxyProfile::normalize($input,array_map(static fn($r)=>(int)$r['id'],$this->parents()));
            if($this->query('SELECT id FROM servers WHERE server_ip=? AND id<>?',[$p['server_ip'],$id])->fetchColumn())throw new \InvalidArgumentException('DUPLICATE_IP');
            $this->db->beginTransaction();
            if(!$id){$this->query('INSERT INTO servers (server_name,server_ip,server_type,is_main,enabled,status,proxy_key_gen) VALUES (?,?,1,0,0,3,1)',[$p['name'],$p['server_ip']]);$id=(int)$this->db->lastInsertId();}
            $path=$this->path($id);$old=is_file($path)?file_get_contents($path):null;
            $this->query('UPDATE servers SET server_name=?,server_ip=?,enabled=?,parent_id=?,http_broadcast_port=?,https_broadcast_port=?,http_ports_add=?,https_ports_add=?,domain_name=?,total_clients=?,enable_https=?,proxy_key_gen=CASE WHEN proxy_key_gen<1 THEN 1 ELSE proxy_key_gen END WHERE id=? AND server_type=1',
                [$p['name'],$p['server_ip'],(int)$p['enabled'],json_encode($p['parent_ids']),$p['http_ports'][0]??0,$p['https_ports'][0]??0,implode(',',array_slice($p['http_ports'],1)),implode(',',array_slice($p['https_ports'],1)),implode(',',array_column($p['hosts'],'host')),$p['connection_limit'],(int)($p['http_ports']===[]),$id]);
            $doc=['revision'=>bin2hex(random_bytes(32)),'saved_at'=>time(),'profile'=>$p];$this->write($path,$doc);$this->db->commit();return ['id'=>$id]+$doc;
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($path!==null){if($old===null)@unlink($path);else file_put_contents($path,$old,LOCK_EX);}throw $e;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function wire(int $id): array {
        $lock=fopen($this->directory.'/save.lock','c');if(!$lock)throw new \RuntimeException('PROFILE_STORAGE_FAILED');flock($lock,LOCK_SH);
        try{return $this->buildWire($id);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function buildWire(int $id): array {
        $row=$this->row($id);$doc=$this->document($id);if(!$row||!isset($doc['profile']))throw new \InvalidArgumentException('PROFILE_NOT_CONFIGURED');
        $p=$doc['profile'];$p['enabled']=(bool)$row['enabled'];$nodes=[];$ca='';
        foreach($this->parents() as $parent) {
            $pid=(int)$parent['id'];if(!in_array($pid,$p['parent_ids'],true))continue;
            if(!(int)$parent['enabled']||(int)$parent['https_broadcast_port']<1)throw new \RuntimeException('UPSTREAM_REQUIRES_HTTPS: '.$pid);
            $nodes[]=['id'=>$pid,'ip'=>$parent['server_ip'],'port'=>(int)$parent['https_broadcast_port'],'tls_name'=>$p['upstream_tls_names'][$pid]??$parent['server_ip'],'route'=>ProxyRoute::current($id,$pid)];
            $ca.=$p['upstream_cas'][$pid]??'';
            if((int)$parent['is_main']===1&&is_file(dirname(MAIN_HOME,2).'/tls/server.crt'))$ca.=file_get_contents(dirname(MAIN_HOME,2).'/tls/server.crt');
        }
        if(count($nodes)!==count($p['parent_ids']))throw new \RuntimeException('UPSTREAM_MISSING');
        $wire=['schema'=>1,'profile'=>$p,'parents'=>$nodes,'upstream_ca'=>$ca];$wire['revision']=hash('sha256',json_encode($wire,JSON_THROW_ON_ERROR));return $wire;
    }
    public function status(int $id): array {
        $doc=$this->document($id);$s=$this->read($this->path($id,'.status'));$desired='';$error='';
        try{$desired=$this->wire($id)['revision'];}catch(\Throwable $e){$error=$e->getMessage();}
        $state=!$doc?'unconfigured':(!$s?'not_connected':(time()-($s['last_sync']??0)>30?'offline':(!empty($s['last_error'])?'failed':(($s['applied_revision']??'')===$desired&&!empty($s['ready'])?'applied':'pending'))));
        return ['state'=>$state,'desired_revision'=>$desired,'saved_revision'=>$doc['revision']??'','saved_at'=>$doc['saved_at']??null,'last_sync'=>$s['last_sync']??null,'applied_revision'=>$s['applied_revision']??'','last_error'=>$error?:($s['last_error']??'')];
    }
    public function acknowledge(int $id,array $data): void {
        $revision=is_string($data['applied_revision']??null)&&preg_match('/^[a-f0-9]{64}$/D',$data['applied_revision'])?$data['applied_revision']:'';
        $error=is_string($data['last_error']??null)?substr($data['last_error'],0,1500):'';
        $stats=$data['stats']??[];$safe=[];
        foreach(['cpu','total_mem_used_percent','total_mem','bytes_received','bytes_sent','bytes_received_total','bytes_sent_total'] as $key)if(isset($stats[$key])&&is_numeric($stats[$key])&&$stats[$key]>=0)$safe[$key]=min((float)$stats[$key],PHP_INT_MAX);
        $safe['uptime']=is_string($stats['uptime']??null)?substr($stats['uptime'],0,80):'';
        $ready=($stats['proxy_ready']??false)===true;
        $this->write($this->path($id,'.status'),['last_sync'=>time(),'applied_revision'=>$revision,'last_error'=>$error,'ready'=>$ready]);
        $this->query('UPDATE servers SET proxy_signed=1,last_check_ago=?,status=?,watchdog_data=? WHERE id=? AND server_type=1',[time(),$ready?1:3,json_encode($safe),$id]);
    }
    public function enrollment(int $id,string $url,string $ca): array {
        $row=$this->row($id);$this->wire($id);
        $parts=parse_url($url);if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||!isset($parts['host'])||!str_contains($ca,'BEGIN CERTIFICATE'))throw new \InvalidArgumentException('MAIN_TLS_NOT_CONFIGURED');
        $key=ProxyKey::forServer($id,(int)$row['proxy_key_gen']);if($key===null)throw new \RuntimeException('PROXY_KEY_UNAVAILABLE');
        return ['schema'=>1,'server_id'=>$id,'generation'=>(int)$row['proxy_key_gen'],'key'=>bin2hex($key),'main_url'=>$url,'main_ca'=>$ca];
    }
}
