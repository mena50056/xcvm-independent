<?php
declare(strict_types=1);
require dirname(__DIR__).'/panel/src/vendor/autoload.php';
use XcVm\Domain\Backup\{BackupArchive,RecoveryBundle,IndependentRecovery,OffsiteBackup};
function checkRecovery(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function deniedRecovery(callable $call,string $destination):void {
    try{$call();throw new LogicException('Unsafe operation accepted');}catch(LogicException $e){throw $e;}catch(Throwable $e){}
    checkRecovery(!file_exists($destination),'Failed authentication published plaintext');
}
$dir=sys_get_temp_dir().'/horus-recovery-'.bin2hex(random_bytes(5));mkdir($dir,0700);$pass='test-only-recovery-passphrase-'.bin2hex(random_bytes(16));
try {
    $sql=$dir.'/backup_fixture.sql';$f=fopen($sql,'wb');for($i=0;$i<128;$i++)fwrite($f,random_bytes(65536));fclose($f);
    $meta=BackupArchive::encrypt($sql,$sql.'.horus',$pass);
    checkRecovery($meta['bytes']===8388608,'Streaming file size');
    BackupArchive::decrypt($sql.'.horus',$dir.'/restored.sql',$pass);
    checkRecovery(hash_file('sha256',$sql)===hash_file('sha256',$dir.'/restored.sql'),'SQL round trip');
    deniedRecovery(fn()=>BackupArchive::decrypt($sql.'.horus',$dir.'/wrong.sql','wrong-recovery-passphrase-000000'),$dir.'/wrong.sql');
    $cipher=file_get_contents($sql.'.horus');$changed=$cipher;$changed[100]=chr(ord($changed[100])^1);file_put_contents($dir.'/changed.horus',$changed);
    deniedRecovery(fn()=>BackupArchive::decrypt($dir.'/changed.horus',$dir.'/tampered.sql',$pass),$dir.'/tampered.sql');
    foreach(['truncated'=>substr($cipher,0,-20),'appended'=>$cipher.'x','no-final'=>substr($cipher,0,-200)] as $name=>$bytes){file_put_contents($dir.'/'.$name.'.horus',$bytes);deniedRecovery(fn()=>BackupArchive::decrypt($dir.'/'.$name.'.horus',$dir.'/'.$name.'.sql',$pass),$dir.'/'.$name.'.sql');}
    try{BackupArchive::decrypt($sql.'.horus',$dir.'/restored.sql',$pass);throw new LogicException('Existing destination replaced');}catch(RuntimeException $e){}
    checkRecovery(hash_file('sha256',$sql)===hash_file('sha256',$dir.'/restored.sql'),'Existing plaintext protected');
    file_put_contents($dir.'/empty.sql','');BackupArchive::encrypt($dir.'/empty.sql',$dir.'/empty.horus',$pass);BackupArchive::decrypt($dir.'/empty.horus',$dir.'/empty-open.sql',$pass);checkRecovery(filesize($dir.'/empty-open.sql')===0,'Empty authenticated stream');
    mkdir($dir.'/state');mkdir($dir.'/config');mkdir($dir.'/config/horus_proxy');
    foreach(['master.key'=>random_bytes(32),'identity.json'=>'{"id":"test"}','config.enc'=>'config-state','root.enc'=>'signing-state','admin-login.json'=>'never-back-up-this'] as $name=>$data)file_put_contents($dir.'/state/'.$name,$data);
    file_put_contents($dir.'/config/proxy_secret','test-proxy-key');file_put_contents($dir.'/config/assistant.json','{"token":"test-key"}');file_put_contents($dir.'/config/horus_proxy/2.json','{"profile":{}}');file_put_contents($dir.'/config/horus_proxy/2.operation.json','{"state":"queued"}');
    $files=IndependentRecovery::files($dir.'/state',$dir.'/config');
    checkRecovery(isset($files['independent/state/master.key'],$files['independent/state/config.enc'],$files['independent/state/root.enc'],$files['independent/config/proxy_secret'],$files['independent/config/assistant.json']),'Independent identity, DB, signing, proxy and AI configuration');
    checkRecovery(!isset($files['independent/state/admin-login.json'],$files['independent/config/horus_proxy/2.operation.json']),'Login credentials and queued commands excluded');
    $bundle=RecoveryBundle::seal($files,$pass,time());checkRecovery(!str_contains($bundle,'test-proxy-key'),'Bundle secrets encrypted');$opened=RecoveryBundle::open($bundle,$pass);checkRecovery($opened!==null,'Bundle authenticates');RecoveryBundle::extract($opened,$dir.'/unpacked');
    checkRecovery(file_get_contents($dir.'/unpacked/independent/state/master.key')===file_get_contents($dir.'/state/master.key'),'Recovery identity restored');
    deniedRecovery(fn()=>RecoveryBundle::extract(['files'=>['../escape'=>'x']],$dir.'/bad-extract'),$dir.'/bad-extract');
    $targets=[['id'=>1],['id'=>2]];$pruned=[];$uploaded=[];
    $rows=OffsiteBackup::deliver(['backup_1.sql.horus'=>$sql.'.horus','recovery_1.bundle'=>$dir.'/empty.horus'],$targets,
        static function($t,$f,$n)use(&$uploaded){$uploaded[]=[$t['id'],$n];return null;},static fn($t,$f,$n)=>$t['id']===1?'corrupt':null,static function($t)use(&$pruned){$pruned[]=$t['id'];});
    checkRecovery($rows[0]['state']==='failed'&&$rows[1]['state']==='verified'&&$pruned===[2],'Only fully verified targets are pruned');
    checkRecovery(count($uploaded)===3,'Independent targets continue after a failed read back');
    echo "PASS: encrypted SQL, wrong key, tampering, truncation, final tag, exclusive output, independent state, safe extraction and retention after verification\n";
} finally {
    $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($walk as $p){$p->isDir()&&!$p->isLink()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($dir);
}
