<?php
declare(strict_types=1);
namespace XcVm\Domain\Assistant;

use XcVm\Cli\Commands\{IndependentLbInstall,SshSession};
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Proxy\{ProxyProfiles,ProxyDiagnostics};
use XcVm\Domain\Server\InstallCredentials;

/** MAIN CLI worker; fixed installers only, no model-supplied commands. */
final class InstallationRunner {
    public function __construct(private InstallationStore $store,private \PDO $pdo) {}
    private function phase(array &$job,string $phase): void {$job['phase']=$phase;$this->store->write($job);}
    private function actor(int $id): void {
        global $rUserInfo,$rPermissions;
        $q=$this->pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$rUserInfo=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$rUserInfo || !(int)$rUserInfo['status'])throw new \RuntimeException('permission_revoked');
        $rPermissions=AuthRepository::getPermissions((int)$rUserInfo['member_group_id']);
        if(is_string($rPermissions['advanced']??null)) {
            $decoded=trim($rPermissions['advanced'])===''?[]:json_decode($rPermissions['advanced'],true);
            if(!is_array($decoded))throw new \RuntimeException('permission_revoked');
            $rPermissions['advanced']=$decoded;
        }
        if(!InstallationWizard::allowed())throw new \RuntimeException('permission_revoked');
    }
    public function tick(): void {
        $worker=$this->store->lock('worker');
        try {
            $wizard=new InstallationWizard($this->store,$this->pdo);
            foreach($this->store->all() as $candidate) {
                $lock=$this->store->lock('wizard');
                try {
                    $job=$wizard->expire($this->store->read($candidate['id']));
                    if($job['state']!=='queued')continue;
                    $job['state']='running';$job['started_at']=time();$job['phase']='preflight';
                    $sealed=$job['secret']??'';unset($job['secret']);$this->store->write($job);
                } finally {InstallationStore::unlock($lock);}
                $password='';
                try {
                    $this->actor($job['actor']);$password=$this->store->open($sealed);unset($sealed);
                    $this->perform($job,$password);
                } catch(\Throwable $e) {
                    $codes=['permission_revoked','existing_server','ssh_connection','ssh_fingerprint','ssh_authentication','fresh_server_required','remote_timeout','transfer_failed','node_install_failed','proxy_sync_failed','public_tls_failed','no_parent','installer_failed'];
                    $job['state']='failed';$job['result']=in_array($e->getMessage(),$codes,true)?$e->getMessage():'installer_failed';
                    // Do not expose remote output, secrets, paths or exception messages.
                } finally {
                    if($password!=='')sodium_memzero($password);unset($sealed);
                    $job['finished_at']=time();$job['phase']='finished';$this->store->write($job);
                    error_log('XC assistant installation '.json_encode(['actor'=>$job['actor'],'job'=>$job['id'],'server_id'=>$job['server_id']??null,'state'=>$job['state'],'result'=>$job['result']??'']));
                }
                break; // One background installation at a time, without holding an HTTP request.
            }
        } finally {InstallationStore::unlock($worker);}
    }
    private function perform(array &$job,string $password): void {
        $d=$job['data'];$q=$this->pdo->prepare('SELECT id FROM servers WHERE server_ip=? OR private_ip=?');$q->execute([$d['ip'],$d['ip']]);if($q->fetchColumn())throw new \RuntimeException('existing_server');
        $conn=SshSession::open($d['ip'],$d['ssh_port'],$error);if(!$conn)throw new \RuntimeException('ssh_connection');
        try {
            $key=(string)ssh2_fingerprint($conn,SSH2_FINGERPRINT_SHA1|SSH2_FINGERPRINT_HEX);
            if(InstallCredentials::checkHostKey($key,$d['fingerprint'],null)!==null)throw new \RuntimeException('ssh_fingerprint');
            if(!@ssh2_auth_password($conn,'root',$password))throw new \RuntimeException('ssh_authentication');
            $os=$d['kind']==='proxy'?'test "$VERSION_ID" = 22.04 || test "$VERSION_ID" = 24.04':'test "$VERSION_ID" = 22.04';
            try {$this->run($conn,'test "$(id -u)" = 0; . /etc/os-release; test "$ID" = ubuntu; ('.$os.'); test "$(uname -m)" = x86_64; command -v python3 >/dev/null; for p in /opt/horus_proxy /opt/horus_xc /opt/xcvm-independent-lab /home/horus_xc /home/xc_vm /home/xtreamcodes /home/xui /var/lib/mysql/xc_vm /var/lib/mysql/xcvm_lab_main; do test ! -e "$p" && test ! -L "$p"; done; ! id xcvm_lab >/dev/null 2>&1; ! pgrep -x nginx >/dev/null; test -z "$(ss -H -lnt \'( sport = :80 or sport = :443 or sport = :8080 or sport = :18080 )\')"',45);}
            catch(\Throwable){throw new \RuntimeException('fresh_server_required');}
            $this->actor($job['actor']); // Revocation while SSH preflight was running must stop before mutation.
            if($d['kind']==='proxy')$this->proxy($conn,$job);
            else $this->node($job,$password);
        } finally {if(function_exists('ssh2_disconnect'))@ssh2_disconnect($conn);}
    }
    private function node(array &$job,string $password): void {
        $d=$job['data'];$this->phase($job,'registering');
        $row=QueryHelper::verifyPostTable('servers',['server_name'=>$d['name'],'server_ip'=>$d['ip'],'server_type'=>0,'is_main'=>0,'enabled'=>0,'status'=>3,'serve_only'=>1,'total_clients'=>$d['capacity'],'network_interface'=>'auto','ssh_hostkey_sha1'=>$d['fingerprint']]);unset($row['id']);$prepared=QueryHelper::prepareArray($row);
        $this->pdo->prepare('INSERT INTO servers ('.$prepared['columns'].') VALUES ('.$prepared['placeholder'].')')->execute($prepared['data']);
        $job['server_id']=(int)$this->pdo->lastInsertId();$this->phase($job,'installing');
        $cred=InstallCredentials::write($job['server_id'],'root',$password);
        // Native installer already verifies package checksums, MAIN TLS and fresh authenticated heartbeat.
        ob_start(static function(string $output): string {return '';},4096);
        try {$code=IndependentLbInstall::execute(['2',(string)$job['server_id'],(string)$d['ssh_port'],'-','-','--cred-file='.$cred,'--expect-hostkey='.$d['fingerprint']]);}
        finally {ob_end_clean();@unlink($cred);}
        if($code!==0)throw new \RuntimeException('node_install_failed');
        $q=$this->pdo->prepare('SELECT status,enabled,last_check_ago FROM servers WHERE id=? AND is_main=0');$q->execute([$job['server_id']]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row||(int)$row['status']!==1||!(int)$row['enabled']||(int)$row['last_check_ago']<time()-30)throw new \RuntimeException('node_install_failed');
        $job['state']='succeeded';$job['result']='node_heartbeat_verified';
    }
    private function proxy($conn,array &$job): void {
        $d=$job['data'];$profiles=ProxyProfiles::app();$parent=null;$main=null;
        foreach($profiles->parents() as $row){if((int)$row['id']===$d['parent_id']&&(int)$row['enabled']&&(int)$row['https_broadcast_port']>0)$parent=$row;if((int)$row['is_main']===1)$main=$row;}
        if(!$parent||!$main)throw new \RuntimeException('no_parent');
        $this->phase($job,'registering');$p=$profiles->defaults();
        $p=array_replace($p,['name'=>$d['name'],'server_ip'=>$d['ip'],'enabled'=>true,'parent_ids'=>[$d['parent_id']],'default_parent_id'=>$d['parent_id'],'connection_limit'=>$d['capacity'],'http_ports'=>[80],'https_ports'=>[443],'prefer_https'=>true]);
        $doc=$profiles->save(0,$p,'',['id'=>$job['actor'],'name'=>'Assistant installation'],'assistant_install');$ownedRevision=$doc['revision'];$job['server_id']=$doc['id'];$this->phase($job,'installing');
        $this->pdo->prepare('UPDATE servers SET ssh_hostkey_sha1=? WHERE id=?')->execute([$d['fingerprint'],$job['server_id']]);
        $root=dirname(rtrim(MAIN_HOME,'/'),2);$urlFile=CONFIG_PATH.'proxy_main_url';$url=is_file($urlFile)?trim((string)file_get_contents($urlFile)):'';
        if($url==='')$url='https://'.$main['server_ip'].':'.(int)$main['https_broadcast_port'].'/horus-proxy/v1/sync';
        $enrollment=$profiles->enrollment($job['server_id'],$url,(string)file_get_contents($root.'/tls/server.crt'));
        $remote=trim($this->run($conn,'umask 077; mktemp -d /root/horus-ai-install.XXXXXXXX',20));
        if(!preg_match('~^/root/horus-ai-install\.[A-Za-z0-9]{8}$~D',$remote))throw new \RuntimeException('installer_failed');
        $temp=tempnam($this->store->directory,'.enrollment-');chmod($temp,0600);
        try {
            foreach(['proxy_agent.py','proxy_config.py','install_proxy.py','proxy_ip_tls.py'] as $file)$this->send($conn,$root.'/deployment/'.$file,$remote.'/'.$file);
            file_put_contents($temp,json_encode($enrollment,JSON_THROW_ON_ERROR));$this->send($conn,$temp,$remote.'/enrollment.json');unlink($temp);
            $this->run($conn,'DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a python3 '.escapeshellarg($remote.'/install_proxy.py'),1800);
            $this->phase($job,'verifying');$this->awaitProxy($profiles,$job['server_id']);
            if($d['tls_mode']==='public_ip') {
                $this->phase($job,'tls');
                try {$this->run($conn,'python3 /opt/horus_proxy/proxy_ip_tls.py setup',1200);}catch(\Throwable){throw new \RuntimeException('public_tls_failed');}
                $latest=$profiles->document($job['server_id']);
                if($latest['revision']!==$doc['revision'])throw new \RuntimeException('installer_failed');
                $p=$latest['profile'];$p['hosts']=[['host'=>$d['ip'],'parent_id'=>0,'certificate'=>'public-ip']];
                $changed=$profiles->save($job['server_id'],$p,$latest['revision'],['id'=>$job['actor'],'name'=>'Assistant installation'],'assistant_tls');$ownedRevision=$changed['revision'];
                $this->awaitProxy($profiles,$job['server_id']);
            }
            $this->phase($job,'verifying');$checks=ProxyDiagnostics::run($profiles->wire($job['server_id']),5);
            $healthy=count($checks)===2 && count(array_filter($checks,static fn($r)=>$r['state']==='healthy'))===2;
            $job['state']=$healthy?'succeeded':'attention';$job['result']=$healthy?'proxy_ports_verified':'proxy_installed_check_tls_firewall';
            if(!$healthy)$this->disableNewProxy($profiles,$job,$ownedRevision);
        } catch (\Throwable $e) {
            try {$this->disableNewProxy($profiles,$job,$ownedRevision);}catch(\Throwable){}
            throw $e;
        } finally {
            if(is_file($temp))unlink($temp);
            try {$this->run($conn,'rm -rf -- '.escapeshellarg($remote),20);}catch(\Throwable){}
        }
    }
    private function disableNewProxy(ProxyProfiles $profiles,array $job,string $revision): void {
        $current=$profiles->document($job['server_id']);
        // Never overwrite a subsequent administrator edit while an install is running.
        if(($current['revision']??'')!==$revision)return;
        $p=$current['profile'];$p['enabled']=false;
        $profiles->save($job['server_id'],$p,$revision,['id'=>$job['actor'],'name'=>'Assistant installation'],'assistant_install_attention');
        $this->awaitProxy($profiles,$job['server_id']);
    }
    private function awaitProxy(ProxyProfiles $profiles,int $id): void {
        for($i=0;$i<45;$i++){if($profiles->status($id)['state']==='applied')return;sleep(2);}throw new \RuntimeException('proxy_sync_failed');
    }
    private function send($conn,string $local,string $remote): void {
        if(!is_file($local)||!@ssh2_scp_send($conn,$local,$remote,0600))throw new \RuntimeException('transfer_failed');
        $hash=hash_file('sha256',$local);$actual=trim($this->run($conn,'sha256sum '.escapeshellarg($remote).' | cut -d " " -f1',30));if(!hash_equals($hash,$actual))throw new \RuntimeException('transfer_failed');
    }
    private function run($conn,string $command,int $timeout): string {
        $marker='__AI_INSTALL_'.bin2hex(random_bytes(12)).'__';
        $wrapped='bash -lc '.escapeshellarg("set -euo pipefail\n".$command).'; rc=$?; printf '.escapeshellarg("\n".$marker.'%d'."\n").' "$rc"';
        $out=ssh2_exec($conn,$wrapped);if(!$out)throw new \RuntimeException('installer_failed');$err=ssh2_fetch_stream($out,SSH2_STREAM_STDERR);
        stream_set_blocking($out,false);stream_set_blocking($err,false);$body='';$until=microtime(true)+$timeout;
        try {while(!feof($out)||!feof($err)){ $chunk=stream_get_contents($out);if(is_string($chunk))$body=substr($body.$chunk,-32768);stream_get_contents($err);if(microtime(true)>$until)throw new \RuntimeException('remote_timeout');usleep(20000);}}
        finally {stream_set_blocking($out,true);stream_set_blocking($err,true);fclose($err);fclose($out);}
        if(!preg_match('/'.preg_quote($marker,'/').'(\d+)\s*$/',$body,$m)||(int)$m[1]!==0)throw new \RuntimeException('installer_failed');
        return preg_replace('/\n?'.preg_quote($marker,'/').'\d+\s*$/','',$body);
    }
}
