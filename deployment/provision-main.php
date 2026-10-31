<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('XCVM_LAB_STATE='.$root.'/.runtime-state/main');
require $root.'/runtime/bootstrap.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$options=json_decode(file_get_contents($root.'/.install-options.json'),true,512,JSON_THROW_ON_ERROR);
$panelHost=!empty($options['hostname'])?$options['hostname']:$options['server_ip'];
$pdo=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['xcvm_lab_main','xcvm_lab_migrate'] as $database){
    if($pdo->query('SHOW DATABASES LIKE '.$pdo->quote($database))->fetchColumn())throw new RuntimeException('Existing database refused: '.$database);
}
$dbPassword=bin2hex(random_bytes(32));
$pdo->exec("CREATE USER 'xcvm_lab'@'localhost' IDENTIFIED BY ".$pdo->quote($dbPassword));
foreach(['xcvm_lab_main','xcvm_lab_migrate'] as $database){
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
    $pdo->exec('GRANT ALL PRIVILEGES ON `'.$database."`.* TO 'xcvm_lab'@'localhost'");
}
XC_VM::config_init(['server'=>['server_id'=>1,'is_lb'=>0],
    'db'=>['dsn'=>'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=xcvm_lab_main;charset=utf8mb4','host'=>'localhost','port'=>3306,'name'=>'xcvm_lab_main','user'=>'xcvm_lab','pass'=>$dbPassword],
    'redis'=>['host'=>'127.0.0.1','port'=>6379,'auth'=>'']]);
$proc=proc_open(['/usr/bin/mariadb','--socket=/run/mysqld/mysqld.sock','-u','root','xcvm_lab_main'],[0=>['file',$root.'/panel/src/bin/install/database.sql','r'],1=>['file',$root.'/schema-import.log','a'],2=>['file',$root.'/schema-import.log','a']],$pipes);
if(!is_resource($proc)||proc_close($proc)!==0)throw new RuntimeException('Schema import failed');
$d=XC_VM::db_connect();
$d->prepare("UPDATE servers SET server_ip=?,private_ip=NULL,server_name='Independent MAIN',is_main=1,http_broadcast_port=18080,https_broadcast_port=443,enable_https=1 WHERE id=1")->execute([$options['server_ip']]);
$d->prepare('UPDATE servers SET domain_name=? WHERE id=1')->execute([$options['hostname']]);
$d->exec("DELETE FROM users");
$d->exec("DELETE FROM access_codes");
$d->exec("INSERT INTO access_codes (code,type,enabled,groups) VALUES ('lab',0,1,'[1]')");
$password=bin2hex(random_bytes(16));
$salt='$6$rounds=20000$'.bin2hex(random_bytes(8)).'$';
$d->prepare('INSERT INTO users (username,password,member_group_id,status,date_registered) VALUES (?,?,1,1,?)')->execute([$options['admin_user'],crypt($password,$salt),time()]);
$d->prepare("UPDATE settings SET auto_update_lbs=0,license='',api_pass=?,live_streaming_pass=? WHERE id=1")->execute([bin2hex(random_bytes(24)),bin2hex(random_bytes(32))]);
XC_VM::cluster_init();
file_put_contents($root.'/panel/src/tmp/crontab','independent-installer-managed');
require $root.'/panel/src/bootstrap.php';XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
$failed=\XcVm\Core\Database\MigrationRunner::run($db);
\XcVm\Cli\Commands\StatusCommand::markSchema($db,$failed===[]);
if($failed)throw new RuntimeException('Migrations failed: '.implode('; ',$failed));
$columns=$d->query('SHOW COLUMNS FROM settings')->fetchAll(PDO::FETCH_COLUMN);
$settings=['fanout_enabled'=>1,'fanout_supervise'=>1,'dts_legacy_ffmpeg'=>0,'keep_protocol'=>1,'cluster_api_enabled'=>1,'lb_new_node_mode'=>'api','cluster_transport'=>'https_required','cluster_main_host'=>$options['hostname']??''];
foreach($settings as $key=>$value)if(in_array($key,$columns,true))$d->prepare('UPDATE settings SET `'.$key.'`=? WHERE id=1')->execute([$value]);
$file=$root.'/.runtime-state/main/admin-login.json';
file_put_contents($file,json_encode(['url'=>'https://'.$panelHost.'/lab/login','username'=>$options['admin_user'],'password'=>$password],JSON_PRETTY_PRINT));chmod($file,0600);
echo "Fresh independent identity, database and admin created.\n";
