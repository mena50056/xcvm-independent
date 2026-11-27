<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
set_exception_handler(static function($e){fwrite(STDERR,"Assistant installation worker failed\n");exit(1);});
$store=new \XcVm\Domain\Assistant\InstallationStore(CONFIG_PATH.'assistant-install');
(new \XcVm\Domain\Assistant\InstallationRunner($store,$GLOBALS['db']->dbh))->tick();
