<?php
declare(strict_types=1);
// Lab HTTP transport. Upstream's FPM-pool readiness gate belongs to its
// service layout; this isolated single-process transport has no such pools.
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\{SettingsManager,SettingsRepository};
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Infrastructure\Database\DatabaseFactory;
$db=new LazyDatabaseHandler(true);DatabaseFactory::set($db);
$settings=SettingsRepository::getAll(true);SettingsManager::set($settings);
$GLOBALS['rSettings']=$settings;
$GLOBALS['rServers']=[];
$GLOBALS['xcvmLabNodeRoots']=[];
if(getenv('XCVM_LAB_NODE_ROOT') && ctype_digit((string)getenv('XCVM_LAB_NODE_ID')))
    $GLOBALS['xcvmLabNodeRoots'][(int)getenv('XCVM_LAB_NODE_ID')]=rtrim(getenv('XCVM_LAB_NODE_ROOT'),'/').'/';
if(!defined('SERVER_ID')) {
    $db->query('SELECT id FROM servers WHERE is_main=1 LIMIT 1');
    $mainId=(int)($db->get_row()['id']??0);
    if($mainId<1)throw new RuntimeException('Lab MAIN is missing');
    define('SERVER_ID',$mainId);
}
$main=[];
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(ClusterApi::readsMain($path)){$db->query('SELECT * FROM servers WHERE is_main=1 LIMIT 1');$main=$db->get_row()?:[];}
$res=ClusterApi::serve(ClusterCryptoFactory::create(),['method'=>$_SERVER['REQUEST_METHOD'],'path'=>$path,'query'=>$_SERVER['QUERY_STRING']??'','headers'=>getallheaders(),'body'=>file_get_contents('php://input',false,null,0,ClusterApi::MAX_BODY+1),'ip'=>$_SERVER['REMOTE_ADDR'],'https'=>($_SERVER['HTTPS']??'')==='on','port'=>(int)($_SERVER['SERVER_PORT']??0)],$settings,$main);
http_response_code($res['status']);foreach($res['headers'] as $k=>$v)header($k.': '.$v);echo $res['body'];
