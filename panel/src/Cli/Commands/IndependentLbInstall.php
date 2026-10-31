<?php
declare(strict_types=1);
namespace XcVm\Cli\Commands;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\{EnrolmentService,LeaseService};
use XcVm\Domain\Server\InstallCredentials;

/** Button adapter for independent, credential-free serve-only nodes. */
final class IndependentLbInstall {
    public static function execute(array $args): int {
        [$args,$options]=InstallCredentials::splitOptions($args);
        $pdo=\XC_VM::db_connect();$sid=(int)($args[1]??0);$conn=false;$remote=null;$lock=null;$temp=[];
        try {
            if(isset($options['cred-file'])) {
                $credentials=InstallCredentials::consume($options['cred-file']);
                if($credentials===null)throw new \RuntimeException('SSH credential file is missing or unreadable');
                $args[3]=$credentials['username'];$args[4]=$credentials['password'];
            }
            if((int)($args[0]??0)!==2)throw new \RuntimeException('Independent installation currently supports load balancers only; proxy installation is not enabled');
            $q=$pdo->prepare('SELECT * FROM servers WHERE id=?');$q->execute([$sid]);$node=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$node || !empty($node['is_main']) || !filter_var($node['server_ip'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))throw new \RuntimeException('Invalid load-balancer server row');
            $main=$pdo->query('SELECT * FROM servers WHERE is_main=1 LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
            if(!$main || $main['server_ip']===$node['server_ip'])throw new \RuntimeException('Refusing to install a node on MAIN itself');
            if(($args[3]??'')!=='root')throw new \RuntimeException('Independent fresh-node installation requires root SSH access');
            $port=(int)($args[2]??22);if($port<1 || $port>65535)throw new \RuntimeException('Invalid SSH port');
            $lock=fopen(InstallCredentials::dir().$sid.'.lock','c');
            if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Installation for this node is already running');
            set_time_limit(0);@cli_set_process_title('XCVM Independent Install['.$sid.']');
            $root=dirname(rtrim(MAIN_HOME,'/'),2);
            $certificate=$root.'/tls/server.crt';self::validateMainCertificate($certificate,$main['server_ip']);
            $mainPort=(int)($main['https_broadcast_port']??443);
            $mainUrl='https://'.$main['server_ip'].':'.$mainPort.'/cluster/v1/';
            echo "Connecting to the fresh load-balancer over SSH...\n";
            $conn=SshSession::open($node['server_ip'],$port,$error);
            if(!$conn)throw new \RuntimeException('SSH connection failed: '.$error);
            $presented=(string)ssh2_fingerprint($conn,SSH2_FINGERPRINT_SHA1|SSH2_FINGERPRINT_HEX);
            $stored=!empty($options['forget-hostkey'])?null:($node['ssh_hostkey_sha1']??null);
            $expected=InstallCredentials::normalizeHostKey($options['expect-hostkey']??'');
            $refusal=InstallCredentials::checkHostKey($presented,$expected,$stored);
            if($refusal!==null)throw new \RuntimeException($refusal);
            if(!ssh2_auth_password($conn,$args[3],$args[4]))throw new \RuntimeException('SSH authentication failed');
            unset($args[4],$credentials);
            $pdo->prepare('UPDATE servers SET ssh_hostkey_sha1=?,serve_only=1,status=3 WHERE id=?')->execute([InstallCredentials::normalizeHostKey($presented),$sid]);
            self::run($conn,'test "$(id -u)" = 0; . /etc/os-release; test "$ID:$VERSION_ID:$(uname -m)" = ubuntu:22.04:x86_64; command -v python3 >/dev/null');
            // Refuse before sending an enrollment or modifying packages/services.
            self::run($conn,'for p in /home/xc_vm /opt/xcvm-independent-lab /home/xtreamcodes /home/xui /var/lib/mysql/xc_vm /var/lib/mysql/xcvm_lab_main; do test ! -e "$p" && test ! -L "$p" || { echo "Existing panel found: $p"; exit 1; }; done; ! id xcvm_lab >/dev/null 2>&1; test -z "$(ss -H -lnt \'( sport = :80 or sport = :443 or sport = :8080 or sport = :18080 )\')"');
            $remote=trim(self::run($conn,'umask 077; mktemp -d /root/xcvm-node-install.XXXXXXXX')['output']);
            if(!preg_match('~^/root/xcvm-node-install\.[A-Za-z0-9]{8}$~D',$remote))throw new \RuntimeException('Invalid private remote staging directory');
            foreach(['trusted-node.py','fetch_release.py','install.py','configure.py'] as $file)self::send($conn,$root.'/deployment/'.$file,$remote.'/'.$file);
            self::send($conn,$certificate,$remote.'/main.crt');
            $plan=['server_ip'=>$node['server_ip'],'main_ip'=>$main['server_ip'],'main_port'=>$mainPort,'main_url'=>$mainUrl,'http_port'=>80,'https_port'=>443,'version'=>'v0.1.0-lab.3'];
            $planFile=self::privateFile($plan,$temp);self::send($conn,$planFile,$remote.'/plan.json');
            echo "Checking MAIN TLS and downloading the node package from mena50056/xcvm-independent without a token...\n";
            $result=self::run($conn,'python3 '.escapeshellarg($remote.'/trusted-node.py').' prepare',false);
            $keys=json_decode(trim($result['output']),true,512,JSON_THROW_ON_ERROR);
            foreach(['sign_pub','box_pub','eph_pub'] as $key)if(!is_string($keys[$key]??null) || !preg_match('/^[0-9a-f]{64}$/D',$keys[$key]))throw new \RuntimeException('Malformed node public keys');
            if(!is_string($keys['node_uuid']??null) || !preg_match('/^[0-9a-f-]{36}$/D',$keys['node_uuid']))throw new \RuntimeException('Malformed node UUID');
            $enrollment=self::issueEnrollment($sid,$keys,$main,$mainUrl);
            $enrollmentFile=self::privateFile($enrollment,$temp);self::send($conn,$enrollmentFile,$remote.'/enrollment.json');
            echo "Installing the node and starting certificate-verified cluster enrollment...\n";
            self::run($conn,'python3 '.escapeshellarg($remote.'/trusted-node.py').' finish',true,1800);
            $online=false;
            for($i=0;$i<45;$i++) {
                $q=$pdo->prepare('SELECT n.state,n.last_seen_at,s.last_check_ago,s.watchdog_data FROM cluster_nodes n JOIN servers s ON s.id=n.server_id WHERE n.server_id=? AND n.node_uuid=?');$q->execute([$sid,$keys['node_uuid']]);$state=$q->fetch(\PDO::FETCH_ASSOC);
                if($state && $state['state']==='active' && (int)$state['last_seen_at']>=(int)(microtime(true)*1000)-15000 && (int)$state['last_check_ago']>=time()-15 && !empty($state['watchdog_data'])){$online=true;break;}
                sleep(2);
            }
            if(!$online)throw new \RuntimeException('Node services started but MAIN has not received a fresh authenticated heartbeat; inspect the node agent log');
            $pdo->prepare('UPDATE servers SET status=1,enabled=1,serve_only=1,http_broadcast_port=80,https_broadcast_port=443,enable_https=1 WHERE id=?')->execute([$sid]);
            @unlink(CACHE_TMP_PATH.'servers');
            echo "SUCCESS: node active; MAIN received an authenticated heartbeat. TLS verification remains enabled.\n";
            return 0;
        } catch(\Throwable $e) {
            if($sid>0)$pdo->prepare('UPDATE servers SET status=4 WHERE id=? AND is_main=0')->execute([$sid]);
            echo 'FAILED: '.$e->getMessage()."\n";
            return 1;
        } finally {
            foreach($temp as $file)@unlink($file);
            if($conn && $remote!==null && preg_match('~^/root/xcvm-node-install\.[A-Za-z0-9]{8}$~D',$remote)){
                try{self::run($conn,'rm -rf -- '.escapeshellarg($remote),false,30);}catch(\Throwable){}
            }
            if($conn && function_exists('ssh2_disconnect'))@ssh2_disconnect($conn);
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        }
    }

    public static function validateMainCertificate(string $path,string $ip): void {
        $certificate=openssl_x509_parse((string)file_get_contents($path));
        if(!$certificate || ($certificate['validTo_time_t']??0)<=time() || ($certificate['validFrom_time_t']??PHP_INT_MAX)>time())throw new \RuntimeException('MAIN HTTPS certificate is expired or not yet valid');
        $names=array_map('trim',explode(',',(string)($certificate['extensions']['subjectAltName']??'')));
        if(!in_array('IP Address:'.$ip,$names,true))throw new \RuntimeException('MAIN certificate must contain its public IP in subjectAltName');
    }

    public static function issueEnrollment(int $sid,array $keys,array $main,string $mainUrl): array {
        $crypto=ClusterCryptoFactory::create();$settings=SettingsManager::getAll();$settings['lb_new_node_mode']='api';
        $first=EnrolmentService::issueFirst($crypto,$sid,$keys['node_uuid'],hex2bin($keys['sign_pub']),hex2bin($keys['box_pub']),hex2bin($keys['eph_pub']),$settings,$main);
        $info=$crypto->info();
        return ['server_id'=>$sid,'panel_sign_pub'=>base64_encode($info['panel_sign_pub']),'panel_box_pub'=>base64_encode($info['panel_box_pub']),'main_urls'=>[$mainUrl],'policy_ver'=>1,'epoch'=>1,'token_sealed'=>base64_encode($first['token_sealed'])]+LeaseService::wire($first['lease']);
    }

    private static function privateFile(array $data,array &$files): string {
        $file=tempnam(InstallCredentials::dir(),'independent-');if($file===false)throw new \RuntimeException('Cannot stage installation metadata');
        chmod($file,0600);$files[]=$file;
        if(file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR))===false)throw new \RuntimeException('Cannot write installation metadata');
        return $file;
    }

    private static function send($conn,string $source,string $destination): void {
        if(!is_file($source) || !ssh2_scp_send($conn,$source,$destination,0600))throw new \RuntimeException('Could not transfer a required installation file');
        $sum=trim(explode(' ',self::run($conn,'sha256sum '.escapeshellarg($destination),false)['output'])[0]);
        if(!hash_equals(hash_file('sha256',$source),$sum))throw new \RuntimeException('SSH transferred-file checksum mismatch');
    }

    private static function run($conn,string $command,bool $stream=true,int $timeout=180): array {
        $marker='__XCVM_RC_'.bin2hex(random_bytes(8)).'__';
        $wrapped='bash -lc '.escapeshellarg("set -euo pipefail\n".$command).'; rc=$?; printf '.escapeshellarg("\n".$marker.'%d'."\n").' "$rc"';
        $out=ssh2_exec($conn,$wrapped);if(!$out)throw new \RuntimeException('Cannot open SSH command channel');
        $err=ssh2_fetch_stream($out,SSH2_STREAM_STDERR);stream_set_blocking($out,false);stream_set_blocking($err,false);
        $output='';$errors='';$display=['',''];$until=microtime(true)+$timeout;
        while(!feof($out) || !feof($err)) {
            foreach([[$out,false],[$err,true]] as [$channel,$isError]){
                $chunk=stream_get_contents($channel);
                if($chunk!==false && $chunk!==''){
                    if($isError)$errors.=$chunk;else$output.=$chunk;
                    if($stream){
                        $index=$isError?1:0;$display[$index].=$chunk;
                        while(($end=strpos($display[$index],"\n"))!==false){
                            $line=substr($display[$index],0,$end+1);$display[$index]=substr($display[$index],$end+1);
                            if(!str_contains($line,$marker))echo $line;
                        }
                        flush();
                    }
                }
            }
            if(microtime(true)>$until)throw new \RuntimeException('SSH installation stage timed out');
            usleep(20000);
        }
        fclose($err);fclose($out);
        if($stream)foreach($display as $line)if($line!=='' && !str_contains($line,$marker))echo $line;
        if(!preg_match('/'.preg_quote($marker,'/').'(\d+)\s*$/',$output,$match))throw new \RuntimeException('SSH stage ended without a completion status');
        $output=preg_replace('/\n?'.preg_quote($marker,'/').'\d+\s*$/','',$output);
        if((int)$match[1]!==0)throw new \RuntimeException('Remote stage failed: '.trim(substr($errors."\n".$output,-1600)));
        return ['output'=>$output,'error'=>$errors];
    }
}
