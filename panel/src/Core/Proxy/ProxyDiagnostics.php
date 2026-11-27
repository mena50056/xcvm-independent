<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

/** Credential-free probes. Targets come exclusively from a saved, confirmed proxy profile. */
final class ProxyDiagnostics {
    public const PATH='/.well-known/horus-proxy-origin';
    public static function targets(array $wire): array {
        $p=$wire['profile'];$host=$p['server_ip'];$parent=(int)$p['default_parent_id'];
        if(empty($p['allow_ip_access'])){$host=$p['hosts'][0]['host'];$parent=(int)($p['hosts'][0]['parent_id']?:$parent);}
        else foreach($p['hosts'] as $row)if($row['host']===$host)$parent=(int)($row['parent_id']?:$parent);
        $upstream=null;foreach($wire['parents'] as $row)if((int)$row['id']===$parent)$upstream=$row;
        if(!$upstream||!filter_var($p['server_ip'],FILTER_VALIDATE_IP)||!preg_match('/^[a-f0-9]{32}\z/',$upstream['route']))throw new \InvalidArgumentException('INVALID_PROBE_TARGET');
        $host=ProxyProfile::host($host);$targets=[];
        foreach(['http','https'] as $scheme)foreach(ProxyProfile::ports($p[$scheme.'_ports']) as $port)$targets[]=['id'=>$scheme.':'.$port,'scheme'=>$scheme,'port'=>$port,'host'=>$host,'ip'=>$p['server_ip'],'origin_id'=>$parent,'route'=>$upstream['route'],'revision'=>$wire['revision']];
        return $targets;
    }
    public static function evaluate(array $target,string $nonce,string $body,array $info,int $error,int $now): array {
        $code='OK';$http=(int)($info['http_code']??0);$data=json_decode($body,true);
        if($error!==0)$code=in_array($error,[35,51,58,60,64,66,77,80,82,83,90,91],true)?'TLS_FAILED':($error===28?'TIMEOUT':'CONNECT_FAILED');
        elseif($http!==200)$code=$http===404?'PROBE_UNAVAILABLE':($http>=500?'UPSTREAM_FAILED':'HTTP_REJECTED');
        elseif(!is_array($data)||($data['protocol']??'')!=='horus-origin-v1'||!is_string($data['nonce']??null)||!hash_equals($nonce,$data['nonce']))$code='INVALID_RESPONSE';
        elseif(!is_int($data['origin_id']??null)||$data['origin_id']!==$target['origin_id'])$code='ORIGIN_MISMATCH';
        elseif(!is_string($data['revision']??null)||!hash_equals($target['revision'],$data['revision']))$code='REVISION_MISMATCH';
        $expires=null;
        if($target['scheme']==='https'&&isset($info['certinfo'][0]['Cert'])) {
            $cert=openssl_x509_parse($info['certinfo'][0]['Cert']);if(is_array($cert))$expires=$cert['validTo_time_t']??null;
        }
        $tcp=(float)($info['connect_time']??0);$tls=(float)($info['appconnect_time']??0);
        return array_intersect_key($target,array_flip(['id','scheme','port','host','origin_id']))+[
            'state'=>$code==='OK'?'healthy':($code==='PROBE_UNAVAILABLE'?'unsupported':'failed'),'code'=>$code,'http_status'=>$http,
            'tcp_ms'=>$tcp>0?round($tcp*1000,1):null,'tls_ms'=>$tls>0?round(max(0,$tls-$tcp)*1000,1):null,
            'total_ms'=>round((float)($info['total_time']??0)*1000,1),'certificate_expires_at'=>$expires,'checked_at'=>$now];
    }
    public static function run(array $wire,int $timeout,string $ca=''): array {
        if(!function_exists('curl_multi_init'))throw new \RuntimeException('CURL_UNAVAILABLE');
        $targets=self::targets($wire);$results=[];$caFile=null;
        if($ca!==''){$caFile=tempnam(sys_get_temp_dir(),'horus-probe-ca-');if($caFile===false)throw new \RuntimeException('PROBE_CA_FAILED');chmod($caFile,0600);file_put_contents($caFile,$ca);}
        try {
            // At most eight bounded requests, no redirects, environment proxies, DNS lookups or body uploads.
            foreach(array_chunk($targets,8) as $batch) {
                $multi=curl_multi_init();$handles=[];
                try {
                    foreach($batch as $target) {
                        $nonce=bin2hex(random_bytes(16));$body='';$handle=curl_init();$host=$target['host'];$authority=str_contains($host,':')?'['.$host.']':$host;
                        $url=$target['scheme'].'://'.$authority.':'.$target['port'].'/'.$target['route'].self::PATH.'?nonce='.$nonce;
                        $pin=str_contains($target['ip'],':')?'['.$target['ip'].']':$target['ip'];
                        $entry=(object)['handle'=>$handle,'target'=>$target,'nonce'=>$nonce,'body'=>''];
                        curl_setopt_array($handle,[CURLOPT_URL=>$url,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                            CURLOPT_RESOLVE=>[$authority.':'.$target['port'].':'.$pin],CURLOPT_CONNECTTIMEOUT_MS=>min(2000,$timeout*1000),CURLOPT_TIMEOUT_MS=>$timeout*1000,
                            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CERTINFO=>true,CURLOPT_USERAGENT=>'Horus-Proxy-Diagnostics/1',CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache'],
                            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($entry):int{if(strlen($entry->body)+strlen($chunk)>4096)return 0;$entry->body.=$chunk;return strlen($chunk);}]);
                        if($caFile!==null)curl_setopt($handle,CURLOPT_CAINFO,$caFile);
                        curl_multi_add_handle($multi,$handle);$handles[]=$entry;
                    }
                    do{$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.2);}while($active&&$status===CURLM_OK);
                    $errors=[];while($message=curl_multi_info_read($multi))$errors[spl_object_id($message['handle'])]=$message['result'];
                    foreach($handles as $entry){$info=curl_getinfo($entry->handle);$info['appconnect_time']=curl_getinfo($entry->handle,CURLINFO_APPCONNECT_TIME);$info['certinfo']=curl_getinfo($entry->handle,CURLINFO_CERTINFO);$results[]=self::evaluate($entry->target,$entry->nonce,$entry->body,$info,$errors[spl_object_id($entry->handle)]??curl_errno($entry->handle),time());}
                } finally {foreach($handles as $entry){curl_multi_remove_handle($multi,$entry->handle);curl_close($entry->handle);}curl_multi_close($multi);}
            }
        } finally {if($caFile!==null)@unlink($caFile);}
        return $results;
    }
}
