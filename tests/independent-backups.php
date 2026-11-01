<?php
require dirname(__DIR__).'/panel/src/vendor/autoload.php';
use XcVm\Core\Backup\IndependentDatabaseDump;
function check($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
$method=new ReflectionMethod(IndependentDatabaseDump::class,'connection');
[$db,$options]=$method->invoke(null,['dsn'=>'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=xcvm_lab_main;charset=utf8mb4','name'=>'xcvm_lab_main','user'=>'xcvm_lab','pass'=>"one\"\\two\n[client]\nuser=other"]);
check($db==='xcvm_lab_main'&&str_contains($options,'protocol="socket"'),'local socket and exact database retained');
check(!str_contains($options,"\nuser=other")&&str_contains($options,'\\n[client]\\nuser=other'),'credential newlines cannot inject client options');
foreach([['dsn'=>'mysql:host=external.example;dbname=db'],['dsn'=>'mysql:host=localhost;dbname=bad-name'],['dsn'=>'sqlite:/tmp/db']] as $config){try{$method->invoke(null,$config);throw new LogicException('accepted unsafe config');}catch(InvalidArgumentException $e){echo "PASS unsafe database config refused\n";}}
$root=sys_get_temp_dir().'/horus-dump-'.bin2hex(random_bytes(6));mkdir($root);mkdir($root.'/backups');file_put_contents($root.'/keep.sql','old backup');
$dest=new ReflectionMethod(IndependentDatabaseDump::class,'destination');
foreach([$root.'/outside.sql',$root.'/keep.sql'] as $name){try{$dest->invoke(null,$name,$root.'/backups');throw new LogicException('accepted unsafe path');}catch(InvalidArgumentException $e){echo "PASS outside backup path refused\n";}}
file_put_contents($root.'/backups/existing.sql','keep');try{$dest->invoke(null,$root.'/backups/existing.sql',$root.'/backups');throw new LogicException('accepted existing backup');}catch(InvalidArgumentException $e){}
check(file_get_contents($root.'/backups/existing.sql')==='keep','existing completed backup preserved');
check(\XcVm\Core\Backup\BackupService::create($root.'/backups/existing.sql')===false && file_get_contents($root.'/backups/existing.sql')==='keep','public backup service never replaces or deletes an existing backup');
unlink($root.'/backups/existing.sql');unlink($root.'/keep.sql');rmdir($root.'/backups');rmdir($root);
