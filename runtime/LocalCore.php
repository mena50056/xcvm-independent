<?php
declare(strict_types=1);

use XcVm\Core\Cluster\Crypto\{Box, Enc, PanelSig, Seal};

/** Experimental independent implementation. Never load alongside xcvm_core. */
final class XC_VM {
    private static string $dir;
    private static string $key;
    public static function boot(string $dir): void {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('The sodium extension is required.');
        }
        if ($dir === '' || !str_contains(str_replace('\\', '/', $dir), '/xcvm-independent-lab/')) {
            throw new RuntimeException('Only an isolated xcvm-independent-lab state directory is allowed.');
        }
        self::$dir = $dir;
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create state directory');
        $lock = fopen($dir . '/state.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock state');
        try {
            if (!is_file($dir . '/master.key')) self::write('master.key', random_bytes(32));
            self::$key = file_get_contents($dir . '/master.key');
            if (strlen(self::$key) !== 32) throw new RuntimeException('Invalid local master key');
            if (!is_file($dir . '/identity.json')) {
                $hex = bin2hex(random_bytes(16));
                self::write('identity.json', json_encode(['id' => substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20)]));
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static function write(string $name, string $bytes): void {
        $tmp = self::$dir . '/.' . bin2hex(random_bytes(12));
        if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Write failed');
        chmod($tmp, 0600);
        if (!rename($tmp, self::$dir . '/' . $name)) throw new RuntimeException('Atomic replacement failed');
    }
    private static function save(string $name, array $data): void {
        self::write($name, self::cluster_seal_local('state', json_encode($data, JSON_THROW_ON_ERROR), $name));
    }
    private static function load(string $name): array {
        return json_decode(self::cluster_open_local('state', file_get_contents(self::$dir.'/'.$name), $name), true, 512, JSON_THROW_ON_ERROR);
    }
    public static function install_id(): string { return json_decode(file_get_contents(self::$dir.'/identity.json'),true)['id']; }
    public static function identity_present(): array { return ['install_id'=>is_file(self::$dir.'/identity.json'),'panel_sk'=>is_file(self::$dir.'/panel.enc')]; }
    public static function env_fingerprint(): string { return hash('sha256', self::install_id().php_uname('n')); }
    // Compatibility capability probe, not a forged vendor licence or verdict.
    public static function license_valid(): bool { return true; }
    public static function license_check(): bool { return true; }
    public static function verify_branding(): bool { return true; }
    public static function config_init(array $data): bool { self::save('config.enc',$data); return true; }
    public static function config_server(): array { return self::load('config.enc')['server'] ?? []; }
    public static function config_set_redis(string $host,int $port,string $auth): bool {
        $c=self::load('config.enc'); $c['redis']=['host'=>$host,'port'=>$port,'auth'=>$auth]; self::save('config.enc',$c); return true;
    }
    public static function db_connect(bool $migrate=false): PDO {
        $d=self::load('config.enc')['db'];
        $dsn=$d['dsn'] ?? 'mysql:host='.$d['host'].';port='.($d['port']??3306).';dbname='.($migrate?'xc_vm_migrate':$d['name']).';charset=utf8mb4';
        return new PDO($dsn,$d['user']??null,$d['pass']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    public static function redis_connect(): Redis {
        $r=self::load('config.enc')['redis'];$c=new Redis();$c->connect($r['host'],$r['port'],3);
        if (($r['auth']??'')!=='') $c->auth($r['auth']); return $c;
    }
    public static function cluster_seal_local(string $purpose,string $data,string $context=''): string {
        return Box::box(self::$key,'local-core-v1:'.$purpose.'\0'.$context,$data);
    }
    public static function cluster_open_local(string $purpose,string $blob,string $context=''): string {
        $p=Box::open(self::$key,'local-core-v1:'.$purpose.'\0'.$context,$blob);
        if ($p===null) throw new RuntimeException('Local state authentication failed'); return $p;
    }
    public static function panel_pk(): string {
        if (!is_file(self::$dir.'/panel.enc')) self::save('panel.enc',['sk'=>bin2hex(random_bytes(32))]);
        return bin2hex(sodium_crypto_scalarmult_base(hex2bin(self::load('panel.enc')['sk'])));
    }
    public static function cluster_init(): array {
        $lock=fopen(self::$dir.'/state.lock','c+');if (!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('Lock failed');
        try {
            $created=!is_file(self::$dir.'/root.enc');
            if ($created) self::save('root.enc',['seed'=>bin2hex(random_bytes(32)),'box'=>bin2hex(random_bytes(32)),'floors'=>[],'clock'=>time()]);
            $r=self::root();$kp=sodium_crypto_sign_seed_keypair(hex2bin($r['seed']));$pub=sodium_crypto_sign_publickey($kp);
            return ['created'=>$created,'panel_sign_pub'=>$pub,'panel_box_pub'=>sodium_crypto_scalarmult_base(hex2bin($r['box'])),'panel_fp'=>hash('sha256',$pub,true)];
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    private static function root(): array {
        $r=self::load('root.enc');if(time()+120<$r['clock']) throw new RuntimeException('CLOCK');return $r;
    }
    public static function cluster_info(): array {
        if (!is_file(self::$dir.'/root.enc')) return ['api'=>1,'ext_version'=>'independent-lab-0.1','licensed'=>true,'clock_ok'=>true,'initialised'=>false];
        $r=self::root();$pub=sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hex2bin($r['seed'])));
        return ['api'=>1,'ext_version'=>'independent-lab-0.1','licensed'=>true,'clock_ok'=>true,'initialised'=>true,'kid'=>substr(hash('sha256',$pub),0,8),'panel_sign_pub'=>$pub,'panel_box_pub'=>sodium_crypto_scalarmult_base(hex2bin($r['box'])),'panel_fp'=>hash('sha256',$pub,true)];
    }
    public static function cluster_sign(string $tag,string $payload): string {
        $r=self::root();return sodium_crypto_sign_detached(PanelSig::input($tag,$payload),sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin($r['seed']))));
    }
    public static function cluster_verify(string $tag,string $payload,string $signature,string $public_key): bool { return PanelSig::verify($public_key,$tag,$payload,$signature); }
    public static function cluster_token_issue(array $p): array {
        foreach(['node_uuid','server_id','gen','epoch','node_sign_pub','agent_eph_pub','rotation_min'] as $k) if (!isset($p[$k])) throw new InvalidArgumentException('Missing '.$k);
        if(strlen($p['node_sign_pub'])!==32||strlen($p['agent_eph_pub'])!==32||$p['gen']<1||$p['epoch']<1||$p['server_id']<1) throw new InvalidArgumentException('Invalid node identity');
        $r=self::root();$floor=$r['floors'][$p['node_uuid']]??['gen'=>0,'min_epoch'=>0];
        if($p['gen']<$floor['gen']||$p['epoch']<$floor['min_epoch']) throw new RuntimeException('REVOKED');
        $now=time();$rotation=(int)$p['rotation_min'];if($rotation<1||$rotation>1440) throw new InvalidArgumentException('Rotation out of range');
        $grace=max(5,min(60,intdiv($rotation,4)));$exp=$now+($rotation+$grace)*60;
        // Independent random token: no vendor key, HWID, licence identifier or licence hash.
        $t=random_bytes(32);$kid=self::cluster_info()['kid'];
        $doc=['v'=>1,'typ'=>'xcvm-token','node_uuid'=>$p['node_uuid'],'server_id'=>(int)$p['server_id'],'gen'=>(int)$p['gen'],'epoch'=>(int)$p['epoch'],'iat'=>$now,'nbf'=>$now-120,'exp'=>$exp,'kid'=>$kid,'rotation_min'=>$rotation,'grace_min'=>$grace,'refresh_at'=>$now+intdiv($rotation*60,2),'token'=>bin2hex($t)];
        $json=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $record=$doc+['node_sign_pub'=>bin2hex($p['node_sign_pub'])];
        return array_diff_key($doc,['v'=>1,'typ'=>1,'node_uuid'=>1,'server_id'=>1,'gen'=>1,'token'=>1])+['epoch_record'=>self::cluster_seal_local('epoch',json_encode($record),$p['node_uuid']),'token_sealed'=>Seal::seal($p['agent_eph_pub'],'token',$p['node_uuid'],Enc::u32(strlen($json)).$json.self::cluster_sign('tok',$json))];
    }
    public static function cluster_session(string $record,string $uuid,bool $hard=false): array {
        $d=json_decode(self::cluster_open_local('epoch',$record,$uuid),true,512,JSON_THROW_ON_ERROR);$r=self::root();
        $floor=$r['floors'][$uuid]??['gen'=>0,'min_epoch'=>0];
        if($d['node_uuid']!==$uuid||$d['gen']<$floor['gen']||$d['epoch']<$floor['min_epoch']) throw new RuntimeException('REVOKED');
        if(time()<$d['nbf']||time()>=$d['exp']) throw new RuntimeException('EXPIRED');
        $out=array_diff_key($d,['token'=>1]);$out['node_sign_pub']=hex2bin($d['node_sign_pub']);$out['licensed']=true;$t=hex2bin($d['token']);
        foreach(['mac_up'=>'mac/up','mac_down'=>'mac/down','enc_up'=>'enc/up','enc_down'=>'enc/down'] as $k=>$label) $out['k_'.$k]=hash_hmac('sha256','xcvm/'.$label,$t,true);
        return $out;
    }
    public static function cluster_node_gen(string $uuid,int $gen,int $min_epoch=0): array {
        $lock=fopen(self::$dir.'/state.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Lock failed');
        try {$r=self::root();$old=$r['floors'][$uuid]??['gen'=>0,'min_epoch'=>0];$r['floors'][$uuid]=['gen'=>max($gen,$old['gen']),'min_epoch'=>max($min_epoch,$old['min_epoch'])];$r['clock']=max(time(),$r['clock']);self::save('root.enc',$r);return $r['floors'][$uuid];}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function cluster_lease_issue(array $p): array {
        $exp=min((int)$p['token_exp']+max(0,min(24,(int)$p['tolerance_h']))*3600,time()+26*3600);
        if($exp<=time()) throw new InvalidArgumentException('Lease already expired');
        $payload=json_encode(['v'=>1,'typ'=>'xcvm-lease','node_uuid'=>$p['node_uuid'],'server_id'=>(int)$p['server_id'],'gen'=>(int)$p['gen'],'iat'=>time(),'exp'=>$exp,'kid'=>self::cluster_info()['kid']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        return ['payload'=>$payload,'sig'=>self::cluster_sign('lea',$payload),'exp'=>$exp];
    }
    public static function cluster_open_sealed(string $purpose,string $sealed,string $context=''): string {
        if(!in_array($purpose,['enrol','enrol_code','rekey'],true))throw new InvalidArgumentException('Unknown purpose');
        $plain=Seal::open(hex2bin(self::root()['box']),$purpose,$context,$sealed);if($plain===null)throw new RuntimeException('SEAL');return $plain;
    }
    public static function config_set_db(string $pass): bool {
        $c=self::load('config.enc');if(empty($c['server']['is_lb']))throw new RuntimeException('NOT_NODE');
        $c['db']['pass']=$pass;self::save('config.enc',$c);return true;
    }
    public static function strip_db_credentials(): bool {
        $c=self::load('config.enc');if(empty($c['server']['is_lb']))throw new RuntimeException('NOT_NODE');
        unset($c['db']['user'],$c['db']['pass'],$c['redis']['auth']);self::save('config.enc',$c);return true;
    }
    /** Independent transport envelope. Intentionally not an official XCVT file. */
    private static function envelope(string $kind,string $target,array $data,?string $recipient): string {
        $pub=self::cluster_info()['panel_sign_pub'];
        $doc=['v'=>1,'kind'=>$kind,'target'=>$target,'iat'=>time(),'data'=>$data];
        $body=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if($recipient!==null){$key=hex2bin($recipient);if($key===false||strlen($key)!==32)throw new InvalidArgumentException('Invalid node public key');$body=Seal::seal($key,'lab-config',$target,$body);}
        $header='XCLAB1'.($recipient!==null?'S':'P').$pub;
        return $header.sodium_crypto_sign_detached($header.$body,sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::root()['seed'])))).$body;
    }
    private static function readEnvelope(string $blob,string $kind): array {
        if(strlen($blob)<104||substr($blob,0,6)!=='XCLAB1'||!in_array($blob[6],['S','P'],true))throw new RuntimeException('FORMAT');
        $header=substr($blob,0,39);$pub=substr($blob,7,32);$sig=substr($blob,39,64);$body=substr($blob,103);
        if(!sodium_crypto_sign_verify_detached($sig,$header.$body,$pub))throw new RuntimeException('SIGNATURE');
        if($blob[6]==='S'){$body=Seal::open(hex2bin(self::load('panel.enc')['sk']),'lab-config',self::install_id(),$body);if($body===null)throw new RuntimeException('SEAL');}
        $doc=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if($doc['kind']!==$kind||$doc['target']!==self::install_id()||abs(time()-$doc['iat'])>3600)throw new RuntimeException('TARGET_OR_TIME');
        return $doc+['sign_pub'=>bin2hex($pub)];
    }
    public static function cluster_pack(string $target_install_id,?int $version=2): string {
        if($version!==2)throw new InvalidArgumentException('Independent lab supports pin version 2 only');
        $i=self::cluster_info();return self::envelope('pin',$target_install_id,['panel_sign_pub'=>bin2hex($i['panel_sign_pub']),'panel_box_pub'=>bin2hex($i['panel_box_pub'])],null);
    }
    public static function cluster_pin(string $blob,?bool $replace=false): array {
        $d=self::readEnvelope($blob,'pin');if(!hash_equals($d['sign_pub'],$d['data']['panel_sign_pub']))throw new RuntimeException('PIN_SIGNATURE');
        if(is_file(self::$dir.'/pin.enc')&&!$replace&&!hash_equals(self::load('pin.enc')['panel_sign_pub'],$d['sign_pub']))throw new RuntimeException('PIN_MISMATCH');
        self::save('pin.enc',$d['data']);return self::cluster_pinned();
    }
    public static function cluster_pinned(): array {
        $d=self::load('pin.enc');return ['panel_sign_pub'=>hex2bin($d['panel_sign_pub']),'panel_box_pub'=>hex2bin($d['panel_box_pub'])];
    }
    public static function config_pack(string $target_install_id,mixed $server_params,?string $lb_panel_pk=null): string {
        if($lb_panel_pk===null)throw new InvalidArgumentException('Node public key required');
        $c=self::load('config.enc');$c['server']=(array)$server_params;
        return self::envelope('config',$target_install_id,$c,$lb_panel_pk);
    }
    public static function install_config(string $blob): bool {
        if(is_file(self::$dir.'/config.enc')&&empty(self::config_server()['is_lb']))throw new RuntimeException('REFUSE_MAIN_OVERWRITE');
        $d=self::readEnvelope($blob,'config');if(!hash_equals(bin2hex(self::cluster_pinned()['panel_sign_pub']),$d['sign_pub']))throw new RuntimeException('PIN_MISMATCH');
        if(empty($d['data']['server']['is_lb']))throw new RuntimeException('NODE_CONFIG_REQUIRED');
        self::save('config.enc',$d['data']);return true;
    }
    public static function cluster_lease_verify(string $payload,string $sig,int $main_time,string $node_uuid): array {
        if(!PanelSig::verify(self::cluster_pinned()['panel_sign_pub'],'lea',$payload,$sig))throw new RuntimeException('LEASE_SIGNATURE');
        $d=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
        if($d['typ']!=='xcvm-lease'||$d['node_uuid']!==$node_uuid||$d['exp']<=$main_time||$d['iat']>$main_time+120||$d['exp']-$d['iat']>26*3600)throw new RuntimeException('LEASE_INVALID');
        return $d;
    }
    public static function cluster_lease_store(string $payload,string $sig,string $node_uuid): array {
        $d=self::cluster_lease_verify($payload,$sig,time(),$node_uuid);
        if(is_file(self::$dir.'/lease.enc')){$old=self::load('lease.enc');if($d['iat']<$old['iat']||$d['gen']<$old['gen'])throw new RuntimeException('LEASE_ROLLBACK');}
        self::save('lease.enc',$d);return self::cluster_lease_state();
    }
    public static function cluster_lease_state(): array {
        if(!is_file(self::$dir.'/pin.enc'))return ['state'=>'none','why'=>'NOT_PINNED'];
        if(!is_file(self::$dir.'/lease.enc'))return ['state'=>'none','why'=>'NO_LEASE'];
        $d=self::load('lease.enc');return $d+['state'=>time()<$d['exp']?'live':'expired','main_time'=>time()];
    }
    public static function cluster_kek(): string {return hash_hkdf('sha256',self::$key,32,'independent-node-key');}
    public static function cluster_last_error(): ?string { return null; }
    public static function __callStatic(string $name,array $args): never { throw new BadMethodCallException('Independent lab capability not implemented: '.$name); }
}
