<?php
declare(strict_types=1);
namespace XcVm\Infrastructure\Database {
    trait DatabaseAware { private static function db(): object { return $GLOBALS['db']; } }
}
namespace XcVm\Domain\Line {
    final class ActiveCodeService { public static function lineAwaitsRedemption(array $line): bool { return !empty($line['is_activecode']); } }
    final class LineService { public static array $signals=[]; public static function updateLineSignal(int $id):void {self::$signals[]=$id;} }
}
namespace XcVm\Core\Http {
    final class ApiClient { public static bool $accept=true;public static array $calls=[];public static function request(array $args):string {self::$calls[]=$args;return json_encode(['result'=>self::$accept]);} }
}
namespace XcVm\Domain\User {
    final class ResellerAPI {
        public static array $forms=[];
        public static function init(int $id):void {}
        public static function processLine(array $form):array {
            self::$forms[]=$form;
            $GLOBALS['db']->query('UPDATE `lines` SET `package_id`=?,`max_connections`=2,`exp_date`=`exp_date`+86400 WHERE `id`=?',$form['package'],$form['edit']);
            UserCredits::debit((int)$GLOBALS['rUserInfo']['id'],5);
            return ['status'=>1];
        }
    }
}
namespace {
    $root=dirname(__DIR__);
    require $root.'/panel/src/Core/Auth/Authorization.php';
    require $root.'/panel/src/Domain/User/UserCredits.php';
    require $root.'/panel/src/Domain/Assistant/ManagementTools.php';
    require $root.'/panel/src/Domain/Assistant/SubscriptionTools.php';
    define('STATUS_SUCCESS',1);
    use XcVm\Domain\Assistant\{ManagementTools,SubscriptionTools};
    function check(bool $ok,string $message):void {if(!$ok)throw new \RuntimeException($message);}
    function denied(callable $fn,string $code):void {try{$fn();}catch(\RuntimeException $e){check($e->getMessage()===$code,'Unexpected error: '.$e->getMessage());return;}throw new \RuntimeException('Expected '.$code);}
    $db=new class {
        public \PDO $pdo;private array $rows=[];private int $count=0;public string $fail='';
        public function __construct(){
            $this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
            $this->pdo->sqliteCreateFunction('LOCATE',static fn($a,$b)=>strpos($b,$a)===false?0:strpos($b,$a)+1);
            $this->pdo->sqliteCreateFunction('JSON_CONTAINS',static fn($a,$b,$path)=>in_array((int)$b,json_decode($a,true)?:[],true)?1:0);
            $schema=[
                'users_groups(group_id INTEGER PRIMARY KEY,is_admin INTEGER,is_reseller INTEGER)',
                'users(id INTEGER PRIMARY KEY,username TEXT,owner_id INTEGER,member_group_id INTEGER,status INTEGER,credits NUMERIC,override_packages TEXT)',
                'lines(id INTEGER PRIMARY KEY,username TEXT,password TEXT,member_id INTEGER,enabled INTEGER,admin_enabled INTEGER,exp_date INTEGER,max_connections INTEGER,package_id INTEGER,is_mag INTEGER DEFAULT 0,is_e2 INTEGER DEFAULT 0,is_activecode INTEGER DEFAULT 0,pair_id INTEGER,contact TEXT,reseller_notes TEXT,allowed_ips TEXT,allowed_ua TEXT,bouquet TEXT)',
                'users_logs(id INTEGER PRIMARY KEY,owner INTEGER,type TEXT,action TEXT,log_id INTEGER,package_id INTEGER,cost NUMERIC,credits_after NUMERIC,date INTEGER,deleted_info TEXT)',
                'users_credits_logs(id INTEGER PRIMARY KEY,target_id INTEGER,admin_id INTEGER,amount NUMERIC,date INTEGER,reason TEXT)',
                'streams(id INTEGER PRIMARY KEY,stream_display_name TEXT,type INTEGER,stream_source TEXT)',
                'streams_types(type_id INTEGER PRIMARY KEY,live INTEGER,type_key TEXT)',
                'streams_servers(stream_id INTEGER,server_id INTEGER,parent_id INTEGER,stream_status INTEGER,pid INTEGER,stream_started INTEGER,on_demand INTEGER)',
                'servers(id INTEGER,server_name TEXT,server_type INTEGER,enabled INTEGER,status INTEGER,last_check_ago INTEGER,total_clients INTEGER,connections INTEGER)',
                'users_packages(id INTEGER PRIMARY KEY,package_name TEXT,is_line INTEGER,is_official INTEGER,groups TEXT,official_credits NUMERIC,official_duration INTEGER,official_duration_in TEXT,max_connections INTEGER,bouquets TEXT)',
            ];
            foreach($schema as $sql)$this->pdo->exec('CREATE TABLE '.$sql);
            $this->pdo->exec("INSERT INTO users_groups VALUES(1,1,0),(3,0,1),(4,1,1)");
            $this->pdo->exec("INSERT INTO users VALUES(1,'admin',0,1,1,100,'{}'),(10,'reseller',1,3,1,100,'{}'),(11,'child',10,3,1,20,'{}'),(12,'foreign',20,3,1,30,'{}'),(13,'privileged',10,4,1,40,'{}')");
            $this->pdo->exec("INSERT INTO lines(id,username,password,member_id,enabled,admin_enabled,exp_date,max_connections,package_id,bouquet) VALUES(1,'owned','private',10,1,1,".(time()+86400).",1,1,'[1]'),(2,'foreign','private',20,1,1,".(time()+86400).",1,1,'[1]')");
            $this->pdo->exec("INSERT INTO streams VALUES(1,'Channel',1,'SECRET_URL');INSERT INTO streams_types VALUES(1,1,'live');INSERT INTO streams_servers VALUES(1,5,0,0,0,0,0)");
            $this->pdo->exec("INSERT INTO users_packages VALUES(1,'Day',1,1,'[3]',5,1,'days',2,'[1]')");
        }
        public function query(string $sql,mixed ...$args):bool {
            if($this->fail!==''&&str_contains($sql,$this->fail))return false;
            $s=$this->pdo->prepare(str_replace(' FOR UPDATE','',$sql));$s->execute($args);$this->rows=$s->columnCount()?$s->fetchAll(\PDO::FETCH_ASSOC):[];$this->count=$s->columnCount()?count($this->rows):$s->rowCount();return true;
        }
        public function get_rows():array{return $this->rows;}public function get_raw_rows():array{return $this->rows;}
        public function get_row():array{return $this->rows[0]??[];}public function get_col():mixed{return array_values($this->rows[0]??[])[0]??null;}
        public function num_rows():int{return $this->count;}public function beginTransaction():bool{return $this->pdo->beginTransaction();}
        public function isInTransaction():bool{return $this->pdo->inTransaction();}public function commit():bool{return $this->pdo->commit();}
        public function rollback():bool{return $this->pdo->inTransaction()&&$this->pdo->rollBack();}
    };
    $rUserInfo=['id'=>10,'member_group_id'=>3,'credits'=>100];$rPermissions=['is_admin'=>0,'is_reseller'=>1,'create_sub_resellers'=>1,'create_line'=>1,'all_reports'=>[11],'advanced'=>[]];
    $reseller=new ManagementTools('reseller');
    check(count($reseller->call('find_resellers',['query'=>'child'])['resellers'])===1,'Child not found');
    check($reseller->call('find_resellers',['query'=>'foreign'])['resellers']===[],'Foreign reseller leaked');
    check($reseller->call('find_resellers',['query'=>'privileged'])['resellers']===[],'Admin account leaked');
    denied(fn()=>$reseller->call('adjust_reseller_credits',['user_id'=>12,'amount'=>10,'reason'=>'test']),'NOT_FOUND_OR_FORBIDDEN');
    denied(fn()=>$reseller->call('adjust_reseller_credits',['user_id'=>10,'amount'=>10,'reason'=>'self']),'FORBIDDEN');
    denied(fn()=>$reseller->call('adjust_reseller_credits',['user_id'=>11,'amount'=>1.5,'reason'=>'fraction']),'INVALID_ARGUMENTS');
    denied(fn()=>$reseller->call('adjust_reseller_credits',['user_id'=>11,'amount'=>101,'reason'=>'short']),'INSUFFICIENT_CREDITS');
    denied(fn()=>$reseller->call('control_stream',['stream_id'=>1,'server_id'=>5,'operation'=>'stop']),'FORBIDDEN');
    $proposal=$reseller->call('adjust_reseller_credits',['user_id'=>11,'amount'=>10,'reason'=>'allocated'])['proposal'];
    check(\XcVm\Domain\User\UserCredits::balance(10)===100.0,'Proposal charged');
    $db->fail='INSERT INTO `users_credits_logs`';denied(fn()=>$reseller->execute($proposal),'DATABASE_ERROR');$db->fail='';
    check(\XcVm\Domain\User\UserCredits::balance(10)===100.0&&\XcVm\Domain\User\UserCredits::balance(11)===20.0,'Transfer audit not atomic');
    $reseller->execute($proposal);check(\XcVm\Domain\User\UserCredits::balance(10)===90.0&&\XcVm\Domain\User\UserCredits::balance(11)===30.0,'Transfer not balanced');
    denied(fn()=>$reseller->execute($proposal),'STALE_PROPOSAL');
    $proposal=$reseller->call('set_reseller_enabled',['user_id'=>11,'enabled'=>false])['proposal'];
    $rPermissions['create_sub_resellers']=0;denied(fn()=>$reseller->execute($proposal),'FORBIDDEN');$rPermissions['create_sub_resellers']=1;
    $db->pdo->exec('UPDATE users SET owner_id=20 WHERE id=11');denied(fn()=>$reseller->execute($proposal),'NOT_FOUND_OR_FORBIDDEN');$db->pdo->exec('UPDATE users SET owner_id=10 WHERE id=11');
    $reseller->execute($proposal);check((int)$db->pdo->query('SELECT status FROM users WHERE id=11')->fetchColumn()===0,'Reseller state not saved');
    check(count($reseller->call('credit_report',['days'=>7])['recent'])===1,'Credit report lost scoped transfer');
    $subs=new SubscriptionTools('reseller');
    check($subs->call('list_packages',[])['packages'][0]['credits']===5.0,'Package price wrong');
    $renew=$subs->call('renew_line_package',['line_id'=>1,'package_id'=>1])['proposal'];
    check(!str_contains(json_encode($renew),'private'),'Password leaked into proposal');
    $db->pdo->exec('UPDATE users_packages SET official_credits=6 WHERE id=1');denied(fn()=>$subs->execute($renew),'STALE_PROPOSAL');$db->pdo->exec('UPDATE users_packages SET official_credits=5 WHERE id=1');
    $db->pdo->exec("UPDATE users_packages SET groups='[4]' WHERE id=1");denied(fn()=>$subs->execute($renew),'FORBIDDEN');$db->pdo->exec("UPDATE users_packages SET groups='[3]' WHERE id=1");
    $subs->execute($renew);check(\XcVm\Domain\User\UserCredits::balance(10)===85.0,'Native renewal not charged once');
    check(\XcVm\Domain\User\ResellerAPI::$forms[0]['password']==='','Existing password unnecessarily supplied');
    denied(fn()=>$subs->execute($renew),'STALE_PROPOSAL');
    $rUserInfo=['id'=>1,'member_group_id'=>1,'credits'=>100];$rPermissions=['is_admin'=>1,'advanced'=>[],'all_reports'=>[]];$admin=new ManagementTools('admin');
    $p=$admin->call('extend_line_days',['line_id'=>1,'days'=>30])['proposal'];$old=(int)$db->pdo->query('SELECT exp_date FROM lines WHERE id=1')->fetchColumn();$admin->execute($p);
    check((int)$db->pdo->query('SELECT exp_date FROM lines WHERE id=1')->fetchColumn()===$old+30*86400,'Expiry extension wrong');
    $p=$admin->call('set_line_connections',['line_id'=>1,'connections'=>3])['proposal'];$admin->execute($p);check((int)$db->pdo->query('SELECT max_connections FROM lines WHERE id=1')->fetchColumn()===3,'Connection limit wrong');
    denied(fn()=>$admin->call('set_line_connections',['line_id'=>1,'connections'=>0]),'INVALID_ARGUMENTS');
    $p=$admin->call('adjust_reseller_credits',['user_id'=>11,'amount'=>0.25,'reason'=>'admin adjustment'])['proposal'];$admin->execute($p);check(\XcVm\Domain\User\UserCredits::balance(11)===30.25,'Decimal admin credits lost');
    check(!str_contains(json_encode($admin->call('find_streams',['query'=>'Channel'])),'SECRET_URL'),'Source URL leaked');
    denied(fn()=>$admin->call('control_stream',['stream_id'=>1,'server_id'=>99,'operation'=>'stop']),'NOT_FOUND_OR_FORBIDDEN');
    $p=$admin->call('control_stream',['stream_id'=>1,'server_id'=>5,'operation'=>'restart'])['proposal'];
    $db->pdo->exec('UPDATE streams_servers SET parent_id=9');denied(fn()=>$admin->execute($p),'STALE_PROPOSAL');$db->pdo->exec('UPDATE streams_servers SET parent_id=0');
    \XcVm\Core\Http\ApiClient::$accept=false;denied(fn()=>$admin->execute($p),'CONTROL_REFUSED');
    \XcVm\Core\Http\ApiClient::$accept=true;check($admin->execute($p)['queued']===true,'Backend acceptance misreported');
    check(\XcVm\Core\Http\ApiClient::$calls[1]['sub']==='start','Restart not mapped to native backend');
    $db->pdo->exec("INSERT INTO servers VALUES(5,'Main',0,1,1,0,500,12)");
    $health=$admin->call('server_health',[])['snapshot'][0];check((int)$health['active_connections']===12&&(int)$health['connection_capacity']===500,'Capacity mistaken for actual connections');
    $db->pdo->exec("INSERT INTO streams_types VALUES(4,1,'radio_streams');INSERT INTO streams VALUES(4,'Radio',4,'SECRET');INSERT INTO streams_servers VALUES(4,5,0,0,0,0,0)");
    check($admin->call('find_streams',['query'=>'Radio'])['streams']===[],'Radio needs its own permission');
    denied(fn()=>$admin->call('control_stream',['stream_id'=>4,'server_id'=>5,'operation'=>'start']),'NOT_FOUND_OR_FORBIDDEN');
    $rUserInfo['member_group_id']=2;$rPermissions['advanced']=['users'];check(!$admin->permitted('adjust_reseller_credits')&&!$admin->permitted('control_stream'),'Limited admin escalated');
    echo "PASS: scoped management, real credit transfer primitive, atomic audits, decimal/insufficient funds, revoked permissions, package repricing, native renewal boundary, expiry/limits, stream assignments and backend refusal\n";
}
