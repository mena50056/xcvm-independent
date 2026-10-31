<?php
require dirname(__DIR__).'/panel/src/vendor/autoload.php';
define('XCVM_INDEPENDENT_LAB', true);
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Module\{ModuleStore,ModuleManager};
use XcVm\Core\Updates\GitHubReleases;
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
check(DiagnosticsService::submitPanelLogs() === false,'log submission needs no database and sends nothing');
$called=false;
$r=ModuleStore::catalogue('unused',[],true,function()use(&$called){$called=true;throw new RuntimeException('network/core callback reached');});
check(!$called && ($r['reason']??'')==='vendor_disconnected','store refuses before calling platform or cache');
$m=(new ReflectionClass(ModuleManager::class))->newInstanceWithoutConstructor();
foreach ([['downloadFromPlatform',['unused']],['rollbackFromPlatform',['unused']],['pullFilesFromPlatform',['unused','',null]]] as [$method,$args]) {
 try {(new ReflectionMethod($m,$method))->invokeArgs($m,$args);throw new LogicException('unexpected acceptance');}
 catch (RuntimeException $e) {check($e->getMessage()==='Developer services are disconnected.',$method.' refuses before touching module files');}
}
check($m->renewModuleLicense('unused')===false,'license renewal does not inspect modules or call upstream');
try {new GitHubReleases('vAtErOn-MeDiA','XC_VM');throw new LogicException('upstream accepted');}
catch(RuntimeException $e){check($e->getMessage()==='Developer services are disconnected.','upstream GitHub release client denied case-insensitively');}
check((new GitHubReleases('mena50056','xcvm-independent')) instanceof GitHubReleases,'own GitHub release client remains available');
