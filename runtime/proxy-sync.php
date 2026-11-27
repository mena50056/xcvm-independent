<?php
declare(strict_types=1);
use XcVm\Core\Proxy\{ProxyProfiles,ProxySync};
use XcVm\Infrastructure\Database\DatabaseFactory;
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);return;}
if(($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);return;}
// Only an authenticated admin's enrollment operation creates MAIN's proxy secret.
if(!is_file(CONFIG_PATH.'proxy_secret')){http_response_code(403);return;}
try {
    $db=new \XcVm\Core\Database\LazyDatabaseHandler(true);DatabaseFactory::set($db);
    $settings=\XcVm\Core\Config\SettingsRepository::getAll(true);\XcVm\Core\Config\SettingsManager::set($settings);$GLOBALS['rSettings']=$settings;
    [$code,$answer]=ProxySync::serve(ProxyProfiles::app(),(string)file_get_contents('php://input',false,null,0,65537),(string)($_SERVER['HTTP_X_XCVM_PROXY_AUTH']??''),(int)round(microtime(true)*1000),static fn($node,$nonce,$ts)=>\XcVm\Domain\Cluster\NonceStore::claim($node,hex2bin($nonce),$ts),[ProxyProfiles::class,'refreshRouting']);
    http_response_code($code);echo $answer;
}catch(\Throwable){http_response_code(503);echo '{"error":"PROXY_SYNC_UNAVAILABLE"}';}
