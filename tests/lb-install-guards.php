<?php
declare(strict_types=1);
$base=dirname(__DIR__);$fixture=$base.'/.test-state/lb-button-'.bin2hex(random_bytes(6));
mkdir($fixture.'/panel/src/bin/install',0700,true);mkdir($fixture.'/tls',0700,true);
putenv('XCVM_LAB_STATE='.$fixture.'/state');require $base.'/runtime/bootstrap.php';
define('MAIN_HOME',$fixture.'/panel/src/');define('BIN_PATH',MAIN_HOME.'bin/');define('CACHE_TMP_PATH',$fixture.'/cache/');
use XcVm\Cli\Commands\IndependentLbInstall;
use XcVm\Domain\Server\InstallCredentials;
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;$checks++;}
$config=$fixture.'/openssl.cnf';file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\nCN=192.0.2.10\n[extensions]\nsubjectAltName=IP:192.0.2.10\n");
$key=openssl_pkey_new(['private_key_bits'=>2048,'config'=>$config]);
$csr=openssl_csr_new(['commonName'=>'192.0.2.10'],$key,['config'=>$config]);
$cert=openssl_csr_sign($csr,null,$key,1,['config'=>$config,'x509_extensions'=>'extensions']);
if(!$cert)throw new RuntimeException('Cannot generate test certificate');
openssl_x509_export($cert,$pem);$path=$fixture.'/tls/server.crt';file_put_contents($path,$pem);
IndependentLbInstall::validateMainCertificate($path,'192.0.2.10');check(true,'MAIN certificate accepts the correct IP SAN');
try{IndependentLbInstall::validateMainCertificate($path,'192.0.2.20');throw new LogicException('Wrong IP accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'subjectAltName'),'wrong certificate IP is refused');}
XC_VM::config_init(['db'=>['dsn'=>'sqlite:'.$fixture.'/guards.sqlite','user'=>null,'pass'=>null]]);
$pdo=XC_VM::db_connect();$pdo->exec('CREATE TABLE servers(id INTEGER PRIMARY KEY,is_main INTEGER,server_ip TEXT,status INTEGER)');
$pdo->exec("INSERT INTO servers VALUES(1,1,'192.0.2.10',1),(2,0,'192.0.2.10',3)");
$secret='test-ssh-secret-never-log';$cred=InstallCredentials::write(2,'root',$secret);
ob_start();$result=IndependentLbInstall::execute(['2','2','22','-','-','--cred-file='.$cred]);$log=ob_get_clean();
check($result===1 && str_contains($log,'MAIN itself'),'node installation on MAIN is refused');
check(!is_file($cred),'SSH credential file is consumed and removed');
check(!str_contains($log,$secret),'SSH password is never printed');
check((int)$pdo->query('SELECT status FROM servers WHERE id=1')->fetchColumn()===1,'MAIN row is never marked failed by a refused node installation');
echo 'RESULT '.$checks.' guard checks passed'.PHP_EOL;
