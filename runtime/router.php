<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
if (!defined('MAIN_HOME')) define('MAIN_HOME',dirname(__DIR__).'/panel/src/');
require_once MAIN_HOME.'vendor/autoload.php';
\XcVm\Core\Config\ConstantsInitializer::init(MAIN_HOME);
$_SERVER['SERVER_ADDR']='127.0.0.1';
if(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https')$_SERVER['HTTPS']='on';
if(($_SERVER['REMOTE_ADDR']??'')==='127.0.0.1' && filter_var($_SERVER['HTTP_X_REAL_IP']??'',FILTER_VALIDATE_IP))$_SERVER['REMOTE_ADDR']=$_SERVER['HTTP_X_REAL_IP'];
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/cluster/v1/')){require __DIR__.'/cluster-http.php';return;}
if(preg_match('~^/live/([^/]+)/([^/]+)/(\d+)\.(ts|m3u8)$~',$path,$m)) {
    $_GET+=['type'=>'live','username'=>rawurldecode($m[1]),'password'=>rawurldecode($m[2]),'stream'=>$m[3],'extension'=>$m[4]];
    $_SERVER['XC_STREAM']='auth';require MAIN_HOME.'Public/stream/index.php';return;
}
if(preg_match('~^/auth/([^/]+)$~',$path,$m)) {
    $_GET['token']=rawurldecode($m[1]);$_SERVER['XC_STREAM']='live';require MAIN_HOME.'Public/stream/index.php';return;
}
if(preg_match('~^/(hls|key)/([^/]+)$~',$path,$m)) {
    $_GET['token']=rawurldecode($m[2]);$_SERVER['XC_STREAM']=$m[1]==='hls'?'segment':'key';require MAIN_HOME.'Public/stream/index.php';return;
}
// Keep existing page requests valid while the canonical panel prefix changes.
if($path === '/lab' || str_starts_with($path,'/lab/')) {
    $suffix=substr((string)$_SERVER['REQUEST_URI'],4);
    header('Location: /horus_xc'.($suffix === '' ? '/' : $suffix),true,307);return;
}
if($path === '/horus_xc'){header('Location: /horus_xc/',true,307);return;}
// Expose assets only, never the state, source tree, exports or arbitrary PHP.
if (preg_match('~^/horus_xc/assets/(.+)$~',$path,$m)) {
    $asset=realpath(MAIN_HOME.'Public/assets/admin/'.$m[1]);
    $base=realpath(MAIN_HOME.'Public/assets/admin');
    if($asset && $base && str_starts_with($asset,$base.'/') && is_file($asset) && !str_ends_with(strtolower($asset),'.php')) {
        $ext=strtolower(pathinfo($asset,PATHINFO_EXTENSION));$mime=['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf'];
        header('Content-Type: '.($mime[$ext]??'application/octet-stream'));readfile($asset);return;
    }
    http_response_code(404);return;
}
if(!str_starts_with($path,'/horus_xc/')){header('Location: /horus_xc/login');return;}
$_SERVER['XC_SCOPE']='admin';$_SERVER['XC_CODE']='horus_xc';
require MAIN_HOME.'Public/index.php';
