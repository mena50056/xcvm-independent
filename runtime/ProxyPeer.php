<?php
declare(strict_types=1);
/** Forwarded viewer addresses are accepted only from a registered proxy TCP peer. */
final class HorusProxyPeer {
    public static function isStreamingPath(string $path): bool {
        return preg_match('~^/(?:live/[^/]+/[^/]+/\d+\.(?:ts|m3u8)|(?:auth|hls|key)/[^/]+)$~',$path)===1;
    }

    /** Guard every playback entry, including already-issued tokens and HLS keys. */
    public static function streamingStatus(string $path,array $request,?array $settings,?array $servers,array $proxies,int $serverId): int {
        if(!self::isStreamingPath($path))return 0;
        if($settings===null || !isset($servers[$serverId]))return 503;
        // When the optional restreamer exception is enabled, subscriber admission
        // remains responsible for deciding which lines qualify for that exception.
        if(empty($servers[$serverId]['enable_proxy']) || !empty($settings['restreamer_bypass_proxy']))return 0;
        $peer=(string)($request['XC_PEER_ADDR']??$request['REMOTE_ADDR']??'');
        $proxy=$proxies[$peer]??[];
        $parents=$proxy['parent_id']??[];
        if(is_string($parents))$parents=json_decode($parents,true);
        return (int)($proxy['server_type']??0)===1 && !empty($proxy['enabled'])
            && is_array($parents) && in_array($serverId,array_map('intval',$parents),true) ? 0 : 403;
    }

    public static function resolve(array $server,array $proxies): array {
        $peer=(string)($server['REMOTE_ADDR']??'');$server['XC_PEER_ADDR']=$peer;
        if(isset($proxies[$peer])&&(int)($proxies[$peer]['server_type']??0)===1&&!empty($proxies[$peer]['enabled'])) {
            $viewer=$server['HTTP_X_FORWARDED_FOR']??'';
            if(is_string($viewer)&&filter_var($viewer,FILTER_VALIDATE_IP))$server['REMOTE_ADDR']=$viewer;
        }
        return $server;
    }
}
