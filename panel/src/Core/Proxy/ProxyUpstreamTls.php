<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

/** Public certificates obtained over the installer's authenticated, host-key checked SSH. */
final class ProxyUpstreamTls {
    public static function remember(string $directory,int $id,string $certificate): void {
        if($id<1||strlen($certificate)>65536||str_contains($certificate,'PRIVATE KEY')||!@openssl_x509_parse($certificate))throw new \InvalidArgumentException('INVALID_UPSTREAM_CERTIFICATE');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('PROFILE_STORAGE_FAILED');
        $path=$directory.'/'.$id.'.crt';$temp=$path.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($temp,$certificate,LOCK_EX)!==strlen($certificate))throw new \RuntimeException('PROFILE_STORAGE_FAILED');
        chmod($temp,0600);if(!rename($temp,$path)){@unlink($temp);throw new \RuntimeException('PROFILE_STORAGE_FAILED');}
    }
    public static function prepare(array $parent,array $profile,string $directory): array {
        $id=(int)$parent['id'];$ip=(string)$parent['server_ip'];$port=(int)$parent['https_broadcast_port'];
        if(!(int)$parent['enabled']||$port<1||!filter_var($ip,FILTER_VALIDATE_IP))throw new \RuntimeException('UPSTREAM_REQUIRES_HTTPS: '.$id);
        $ca=(string)($profile['upstream_cas'][$id]??'');
        if($ca===''&&is_file($directory.'/'.$id.'.crt'))$ca=(string)file_get_contents($directory.'/'.$id.'.crt');
        if($ca===''&&(int)$parent['is_main']===1&&is_file(dirname(MAIN_HOME,2).'/tls/server.crt'))$ca=(string)file_get_contents(dirname(MAIN_HOME,2).'/tls/server.crt');
        $name=(string)($profile['upstream_tls_names'][$id]??$ip);$host=str_contains($name,':')?'['.$name.']':$name;
        $temp=null;$curl=curl_init('https://'.$host.':'.$port.'/');
        try {
            $options=[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
            if($name!==$ip)$options[CURLOPT_RESOLVE]=[$name.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip)];
            if($ca!==''){$temp=tempnam(sys_get_temp_dir(),'horus-ca-');if($temp===false)throw new \RuntimeException('PROFILE_STORAGE_FAILED');chmod($temp,0600);file_put_contents($temp,$ca);$options[CURLOPT_CAINFO]=$temp;}
            curl_setopt_array($curl,$options);$ok=curl_exec($curl);
            if($ok===false)throw new \RuntimeException('UPSTREAM_TLS_UNTRUSTED: '.$id);
        } finally {curl_close($curl);if($temp!==null)@unlink($temp);}
        if($ca!=='')$profile['upstream_cas'][$id]=$ca;
        return $profile;
    }
}
