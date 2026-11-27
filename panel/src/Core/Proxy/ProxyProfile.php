<?php
declare(strict_types=1);

namespace XcVm\Core\Proxy;

/** The admin-editable proxy contract. No raw nginx directives or arbitrary upstream URLs. */
final class ProxyProfile {
    public const LIMITS = [
        'connection_limit'=>[1,100000,5000], 'per_ip_connections'=>[0,5000,0],
        'requests_per_second'=>[0,1000,0], 'request_burst'=>[1,10000,100],
        'worker_connections'=>[512,65536,16384], 'worker_processes'=>[0,64,0],
        'connect_timeout'=>[1,30,5], 'read_timeout'=>[15,3600,60],
        'send_timeout'=>[5,600,30], 'keepalive_timeout'=>[5,120,15],
        'header_timeout'=>[5,60,10], 'client_body_mb'=>[1,32,3],
    ];

    public static function ports(mixed $value): array {
        if (is_string($value)) $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($value) || count($value)>32) throw new \InvalidArgumentException('INVALID_PORTS');
        $ports=[];
        foreach ($value as $port) {
            if ((!is_int($port) && !is_string($port)) || !ctype_digit((string)$port) || (int)$port<1 || (int)$port>65535) throw new \InvalidArgumentException('INVALID_PORTS');
            $ports[(int)$port]=(int)$port;
        }
        return array_values($ports);
    }

    public static function host(mixed $value): string {
        if (!is_string($value) || strlen($value)>253) throw new \InvalidArgumentException('INVALID_HOST');
        $value=strtolower(rtrim(trim($value),'.'));
        if (filter_var($value,FILTER_VALIDATE_IP)) return $value;
        if (preg_match('/[^\x20-\x7e]/',$value) && function_exists('idn_to_ascii')) $value=idn_to_ascii($value,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46) ?: '';
        if ($value==='' || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D',$value)) throw new \InvalidArgumentException('INVALID_HOST');
        return $value;
    }

    private static function number(mixed $value, int $min, int $max): int {
        if ((!is_int($value)&&!is_string($value)) || !ctype_digit((string)$value) || (int)$value<$min || (int)$value>$max) throw new \InvalidArgumentException('INVALID_LIMIT');
        return (int)$value;
    }
    private static function boolean(array $input,string $key,bool $default=false): bool {
        $value=$input[$key]??$default;
        if(!is_bool($value))throw new \InvalidArgumentException('INVALID_BOOLEAN');return $value;
    }

    public static function normalize(array $input, array $parentIDs): array {
        $name=$input['name']??'';$ip=$input['server_ip']??'';
        if (!is_string($name)||trim($name)===''||strlen($name)>150||preg_match('/[\x00-\x1f]/',$name)) throw new \InvalidArgumentException('INVALID_NAME');
        if (!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP)) throw new \InvalidArgumentException('INVALID_IP');
        $selected=$input['parent_ids']??[];
        if (!is_array($selected)||count($selected)<1||count($selected)>32) throw new \InvalidArgumentException('INVALID_PARENTS');
        $parents=[];
        foreach ($selected as $id) {
            $id=self::number($id,1,PHP_INT_MAX);
            if (!in_array($id,$parentIDs,true)) throw new \InvalidArgumentException('INVALID_PARENTS');
            $parents[$id]=$id;
        }
        $default=self::number($input['default_parent_id']??0,1,PHP_INT_MAX);
        if (!isset($parents[$default])) throw new \InvalidArgumentException('INVALID_DEFAULT_PARENT');
        $http=self::ports($input['http_ports']??[]);$https=self::ports($input['https_ports']??[]);
        if ($http===[]&&$https===[]) throw new \InvalidArgumentException('PORT_REQUIRED');
        if (array_intersect($http,$https)) throw new \InvalidArgumentException('OVERLAPPING_PORTS');
        $hosts=$input['hosts']??[];
        if (!is_array($hosts)||count($hosts)>64) throw new \InvalidArgumentException('INVALID_HOSTS');
        $normalized=[];
        foreach ($hosts as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('INVALID_HOST');
            $host=self::host($row['host']??null);$parent=self::number($row['parent_id']??0,0,PHP_INT_MAX);
            if ($parent!==0&&!isset($parents[$parent])) throw new \InvalidArgumentException('INVALID_HOST_PARENT');
            if (isset($normalized[$host])) throw new \InvalidArgumentException('DUPLICATE_HOST');
            $certificate=$row['certificate']??'auto';
            if (!is_string($certificate)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D',$certificate)) throw new \InvalidArgumentException('INVALID_CERTIFICATE_ALIAS');
            $normalized[$host]=['host'=>$host,'parent_id'=>$parent,'certificate'=>$certificate];
        }
        $profile=['schema'=>1,'name'=>trim($name),'server_ip'=>$ip,'enabled'=>self::boolean($input,'enabled'),
            'parent_ids'=>array_values($parents),'default_parent_id'=>$default,'http_ports'=>$http,'https_ports'=>$https,
            'hosts'=>array_values($normalized),'allow_ip_access'=>self::boolean($input,'allow_ip_access',true),
            'reject_unknown_hosts'=>self::boolean($input,'reject_unknown_hosts',true), 'allow_admin'=>self::boolean($input,'allow_admin'), 'http2'=>self::boolean($input,'http2'), 'prefer_https'=>self::boolean($input,'prefer_https')];
        if($profile['prefer_https']&&$https===[])throw new \InvalidArgumentException('HTTPS_PORT_REQUIRED');
        if (!$profile['allow_ip_access']&&$profile['hosts']===[]) throw new \InvalidArgumentException('HOST_REQUIRED');
        foreach (self::LIMITS as $key=>[$min,$max,$default]) $profile[$key]=self::number($input[$key]??$default,$min,$max);
        $trusted=$input['trusted_proxies']??[];
        if (is_string($trusted)) $trusted=preg_split('/[\s,]+/',trim($trusted),-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($trusted)||count($trusted)>32) throw new \InvalidArgumentException('INVALID_TRUSTED_PROXIES');
        $profile['trusted_proxies']=[];
        foreach ($trusted as $network) {
            if (!is_string($network)) throw new \InvalidArgumentException('INVALID_TRUSTED_PROXIES');
            $parts=explode('/',$network);
            if (count($parts)>2||!filter_var($parts[0],FILTER_VALIDATE_IP)) throw new \InvalidArgumentException('INVALID_TRUSTED_PROXIES');
            $v6=str_contains($parts[0],':');
            try{$prefix=self::number($parts[1]??($v6?128:32),$v6?32:8,$v6?128:32);}catch(\InvalidArgumentException){throw new \InvalidArgumentException('INVALID_TRUSTED_PROXIES');}
            $profile['trusted_proxies'][]=$parts[0].'/'.$prefix;
        }
        $profile['trusted_proxies']=array_values(array_unique($profile['trusted_proxies']));
        $bind=$input['outbound_bind_ip']??'';
        if (!is_string($bind)||($bind!==''&&!filter_var($bind,FILTER_VALIDATE_IP))) throw new \InvalidArgumentException('INVALID_BIND_IP');
        $profile['outbound_bind_ip']=$bind;
        $profile['upstream_cas']=$profile['upstream_tls_names']=[];
        foreach (['upstream_cas','upstream_tls_names'] as $field) {
            $entries=$input[$field]??[];
            if (!is_array($entries)) throw new \InvalidArgumentException('INVALID_UPSTREAM_TLS');
            foreach ($entries as $id=>$value) {
                if (!isset($parents[(int)$id])||!is_string($value)) throw new \InvalidArgumentException('INVALID_UPSTREAM_TLS');
                if (trim($value)==='') continue;
                if ($field==='upstream_tls_names') $value=self::host($value);
                else {
                    if (strlen($value)>65536||str_contains($value,'PRIVATE KEY')) throw new \InvalidArgumentException('INVALID_UPSTREAM_CERTIFICATE');
                    preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/',$value,$certificates);
                    if (empty($certificates[0])||count($certificates[0])>8) throw new \InvalidArgumentException('INVALID_UPSTREAM_CERTIFICATE');
                    foreach ($certificates[0] as $certificate) if (@openssl_x509_parse($certificate)===false) throw new \InvalidArgumentException('INVALID_UPSTREAM_CERTIFICATE');
                    if (trim(str_replace($certificates[0],'',$value))!=='') throw new \InvalidArgumentException('INVALID_UPSTREAM_CERTIFICATE');
                    $value=implode("\n",$certificates[0])."\n";
                }
                $profile[$field][(int)$id]=$value;
            }
        }
        return $profile;
    }
}
