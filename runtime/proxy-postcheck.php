<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Invoked by the dedicated MAIN timer as the panel service account.
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
set_exception_handler(static function($error){fwrite(STDERR,"Proxy postcheck worker failed\n");exit(1);});
$profiles=\XcVm\Core\Proxy\ProxyProfiles::app();$worker=new \XcVm\Core\Proxy\ProxyPostcheck($profiles);$start=microtime(true);
foreach($profiles->proxies() as $row){if(microtime(true)-$start>80)break;try{$worker->run((int)$row['id']);}catch(\Throwable){error_log('Proxy postcheck unavailable: '.(int)$row['id']);}}
