<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

/** Private version snapshots. The browser receives redacted diffs, never a snapshot or PEM. */
final class ProxyHistory {
    public const RETAIN = 100;
    public function __construct(private string $directory) {}
    private function directory(int $id): string {
        if($id<1)throw new \InvalidArgumentException('INVALID_PROXY');
        return $this->directory.'/history/'.$id;
    }
    public static function publicProfile(array $profile): array {
        foreach(['upstream_cas','monitor_ca'] as $key)if(isset($profile[$key])) {
            $value=$profile[$key];
            $profile[$key]=is_array($value)?array_map(static fn($pem)=>$pem===''?'':substr(hash('sha256',(string)$pem),0,16),$value):($value===''?'':substr(hash('sha256',(string)$value),0,16));
        }
        return array_intersect_key($profile,array_flip(array_merge(array_keys(ProxyProfile::LIMITS),array_keys(ProxyProfile::MONITOR_LIMITS),[
            'name','server_ip','enabled','parent_ids','default_parent_id','http_ports','https_ports','hosts','allow_ip_access','reject_unknown_hosts','allow_admin','http2','prefer_https','trusted_proxies','outbound_bind_ip','upstream_cas','upstream_tls_names','monitor_enabled','monitor_ca'
        ])));
    }
    /** Called while ProxyProfiles holds save.lock; its caller removes this file if the save rolls back. */
    public function stage(int $id,array $document,array $previous,array $actor,string $action): string {
        $dir=$this->directory($id);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('HISTORY_STORAGE_FAILED');
        $old=self::publicProfile($previous);$new=self::publicProfile($document['profile']);$changes=[];
        foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $key)if(($old[$key]??null)!==($new[$key]??null))$changes[]=['field'=>$key,'before'=>$old[$key]??null,'after'=>$new[$key]??null];
        $entry=['id'=>bin2hex(random_bytes(16)),'at'=>time(),'actor'=>['id'=>max(0,(int)($actor['id']??0)),'name'=>mb_substr((string)($actor['name']??'System'),0,80)],'action'=>$action,
            'revision'=>$document['revision'],'changes'=>$changes,'profile'=>$document['profile']];
        $path=$dir.'/'.sprintf('%020d',(int)(microtime(true)*1000000)).'-'.$entry['id'].'.json';
        if(file_put_contents($path,json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new \RuntimeException('HISTORY_STORAGE_FAILED');
        chmod($path,0600);return $path;
    }
    public function prune(int $id): void {
        $files=glob($this->directory($id).'/*.json')?:[];rsort($files,SORT_STRING);
        foreach(array_slice($files,self::RETAIN) as $file)@unlink($file);
    }
    public function entries(int $id): array {
        $files=glob($this->directory($id).'/*.json')?:[];rsort($files,SORT_STRING);$entries=[];
        foreach(array_slice($files,0,self::RETAIN) as $file) {
            $item=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);unset($item['profile']);$entries[]=$item;
        }
        return $entries;
    }
    public function snapshot(int $id,string $entry): array {
        if(!preg_match('/^[a-f0-9]{32}\z/',$entry))throw new \InvalidArgumentException('INVALID_HISTORY_ENTRY');
        foreach(glob($this->directory($id).'/*-'.$entry.'.json')?:[] as $file)return json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        throw new \InvalidArgumentException('HISTORY_ENTRY_NOT_FOUND');
    }
}
