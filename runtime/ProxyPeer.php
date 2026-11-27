<?php
declare(strict_types=1);
/** Forwarded viewer addresses are accepted only from a registered proxy TCP peer. */
final class HorusProxyPeer {
    public static function resolve(array $server,array $proxies): array {
        $peer=(string)($server['REMOTE_ADDR']??'');$server['XC_PEER_ADDR']=$peer;
        if(isset($proxies[$peer])&&(int)($proxies[$peer]['server_type']??0)===1&&!empty($proxies[$peer]['enabled'])) {
            $viewer=$server['HTTP_X_FORWARDED_FOR']??'';
            if(is_string($viewer)&&filter_var($viewer,FILTER_VALIDATE_IP))$server['REMOTE_ADDR']=$viewer;
        }
        return $server;
    }
}
