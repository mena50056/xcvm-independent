<?php
declare(strict_types=1);
require dirname(__DIR__).'/runtime/bootstrap.php';
use XcVm\Core\Cluster\Crypto\{ClusterCryptoFactory,PanelSig,Seal};
$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function rejects(callable $f,string $label):void {try{$f();}catch(Throwable){check(true,$label);return;}check(false,$label);}
$main=getenv('XCVM_LAB_STATE');$node=$main.'-node';$outsider=$main.'-untrusted';
$factory=ClusterCryptoFactory::create();check($factory->info()['initialised'],'upstream cluster factory accepts independent core');
$before=$factory->info()['panel_sign_pub'];
XC_VM::boot($node);$nodeID=XC_VM::install_id();$nodePub=XC_VM::panel_pk();
XC_VM::boot($main);$pin=XC_VM::cluster_pack($nodeID,2);$config=XC_VM::config_pack($nodeID,['server_id'=>7,'is_lb'=>1],$nodePub);
$eph=random_bytes(32);$identity=sodium_crypto_sign_keypair();$uuid='19ed4dc4-c581-4d1c-b20b-4b39d6d7ed8d';
$issued=$factory->tokenIssue(['node_uuid'=>$uuid,'server_id'=>7,'gen'=>1,'epoch'=>1,'rotation_min'=>60,'node_sign_pub'=>sodium_crypto_sign_publickey($identity),'agent_eph_pub'=>sodium_crypto_scalarmult_base($eph)]);
$lease=$factory->leaseIssue(['node_uuid'=>$uuid,'server_id'=>7,'gen'=>1,'token_exp'=>$issued['exp'],'tolerance_h'=>2]);
XC_VM::boot($node);$pinned=XC_VM::cluster_pin($pin);
check(hash_equals($before,$pinned['panel_sign_pub']),'node pins MAIN signing identity');
check(XC_VM::install_config($config),'node imports signed sealed configuration');
check(XC_VM::config_server()===['server_id'=>7,'is_lb'=>1],'node receives own server role');
check(XC_VM::cluster_lease_store($lease['payload'],$lease['sig'],$uuid)['state']==='live','node accepts MAIN signed lease');
rejects(fn()=>XC_VM::cluster_lease_verify($lease['payload'],$lease['sig'],time(),'other-node'),'lease bound to correct node');
rejects(fn()=>XC_VM::cluster_lease_verify($lease['payload'].'x',$lease['sig'],time(),$uuid),'tampered lease rejected');
rejects(fn()=>XC_VM::cluster_lease_verify($lease['payload'],$lease['sig'],$lease['exp']+1,$uuid),'expired lease rejected');
$body=Seal::open($eph,'token',$uuid,$issued['token_sealed']);check(is_string($body),'node opens MAIN token');
$n=unpack('N',substr($body,0,4))[1];$json=substr($body,4,$n);
check(PanelSig::verify($pinned['panel_sign_pub'],'tok',$json,substr($body,4+$n)),'node verifies token against pinned key');
$sealed=XC_VM::cluster_seal_local('node-proof','node-secret','node');
XC_VM::boot($outsider);XC_VM::panel_pk();rejects(fn()=>XC_VM::install_config($config),'configuration cannot be opened by outsider');
rejects(fn()=>XC_VM::cluster_open_local('node-proof',$sealed,'node'),'outsider cannot read node secret');
XC_VM::cluster_init();$wrongPin=XC_VM::cluster_pack($nodeID,2);
XC_VM::boot($node);rejects(fn()=>XC_VM::cluster_pin($wrongPin),'pinned MAIN cannot be replaced silently');
XC_VM::boot($main);rejects(fn()=>XC_VM::install_config($config),'MAIN configuration protected against node overwrite');
check(is_object(XC_VM::redis_connect()),'real Redis connection works without activation');
$r=XC_VM::redis_connect();$key='xcvm-lab-check:'.bin2hex(random_bytes(8));$r->setex($key,30,'ok');check($r->get($key)==='ok','real Redis write/read');$r->del($key);
check(!extension_loaded('xcvm_core'),'original extension absent throughout test');
check(!is_file($main.'/activation_key')&&!is_file($main.'/licence/activation_key'),'MAIN has no vendor activation key');
echo 'RESULT '.$checks.' cluster checks passed'.PHP_EOL;
