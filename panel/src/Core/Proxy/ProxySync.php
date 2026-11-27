<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;
use XcVm\Domain\Server\ProxyKey;

final class ProxySync {
    /** An old, authentic key gets a signed revocation; forged requests never do. */
    public static function serve(ProxyProfiles $profiles,string $body,string $header,int $nowMs,callable $claim): array {
        if(strlen($body)>65536)return [413,''];
        try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return [400,''];}
        if(!is_array($data)||!is_int($data['server_id']??null)||!is_int($data['generation']??null)||$data['server_id']<1||$data['generation']<1||$data['generation']>2147483647)return [400,''];
        $id=$data['server_id'];$key=ProxyKey::forServer($id,$data['generation']);
        $verified=$key===null?null:ProxyKey::verifyRequest($key,$id,$header,$body,$nowMs);
        if(!$verified||!$claim('proxy-'.$id,$verified['nonce'],$verified['ts']))return [403,''];
        $row=$profiles->row($id);
        if(!$row||(int)$row['proxy_key_gen']!==$data['generation'])return [200,ProxyKey::answer($key,$id,$verified['nonce'],['revoked'=>true])];
        $profiles->acknowledge($id,$data);
        return [200,ProxyKey::answer($key,$id,$verified['nonce'],['config'=>$profiles->wire($id)])];
    }
}
