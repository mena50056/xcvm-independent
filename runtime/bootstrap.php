<?php
declare(strict_types=1);
define('XCVM_INDEPENDENT_LAB',true);
$repository=json_decode(file_get_contents(dirname(__DIR__).'/deployment/repository.json'),true)['repository']??'';
if(!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$repository))throw new RuntimeException('Independent repository configuration is required');
[$gitOwner,$gitRepo]=explode('/',$repository,2);
define('GIT_OWNER',$gitOwner);
foreach(['GIT_REPO_MAIN','GIT_REPO_DEV','GIT_REPO_UPDATE','GIT_REPO_BIN','GIT_REPO_FANOUT','GIT_REPO_PROXY','GIT_REPO_FFMPEG'] as $constant)define($constant,$gitRepo);
if(PHP_OS_FAMILY==='Linux') {
    defined('FFMPEG_BIN_40') || define('FFMPEG_BIN_40','/usr/bin/ffmpeg');
    defined('FFPROBE_BIN_40') || define('FFPROBE_BIN_40','/usr/bin/ffprobe');
}
if (extension_loaded('xcvm_core') || class_exists('XC_VM', false)) throw new RuntimeException('Original core must not be loaded in the lab');
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'XcVm\\')) {
        $path=dirname(__DIR__).'/panel/src/'.str_replace('\\','/',substr($class,5)).'.php';
        if(is_file($path)) require $path;
    }
});
require __DIR__.'/LocalCore.php';
require_once __DIR__.'/NetworkExposure.php';
$state=getenv('XCVM_LAB_STATE');
if (!$state) throw new RuntimeException('Set XCVM_LAB_STATE to an isolated lab state directory');
XC_VM::boot($state);
