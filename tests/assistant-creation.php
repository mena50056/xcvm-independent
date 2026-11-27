<?php
declare(strict_types=1);
namespace XcVm\Infrastructure\Database { trait DatabaseAware { private static function db(): object { return $GLOBALS['db']; } } }
namespace XcVm\Domain\Line {
    final class LineService {
        public static function updateLineSignal(int $id):void {}
        public static function process(array $form):array {
            $db=$GLOBALS['db'];$db->query('INSERT INTO lines(username,password,member_id,package_id) VALUES(?,?,?,?)',$form['username'],$form['password'],$form['member_id'],$form['package_id']??$form['package']);
            return ['status'=>1,'data'=>['insert_id'=>(int)$db->pdo->lastInsertId()]];
        }
    }
}
namespace XcVm\Domain\User {
    final class UserService {
        public static function process(array $form):array {
            $db=$GLOBALS['db'];$db->query('INSERT INTO users(username,password,owner_id,member_group_id,status,credits,override_packages) VALUES(?,?,?,?,1,0,?)',$form['username'],'hashed',$form['owner_id'],$form['member_group_id'],'{}');
            return ['status'=>1,'data'=>['insert_id'=>(int)$db->pdo->lastInsertId()]];
        }
    }
    final class ResellerAPI {
        public static function init(int $id):void {}
        public static function processLine(array $form):array {
            UserCredits::debit((int)$GLOBALS['rUserInfo']['id'],5);
            return \XcVm\Domain\Line\LineService::process($form);
        }
        public static function processUser(array $form,bool $returnCreatedCredentials=false):array {
            UserCredits::debit((int)$GLOBALS['rUserInfo']['id'],3);
            $result=UserService::process($form);
            if ($returnCreatedCredentials) { $result['created_credentials']=['username'=>$form['username'],'password'=>$form['password']]; }
            return $result;
        }
    }
}
namespace {
    require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
    require __DIR__.'/../panel/src/Domain/User/UserCredits.php';
    require __DIR__.'/../panel/src/Domain/Assistant/CreationTools.php';
    define('STATUS_SUCCESS',1);
    use XcVm\Domain\Assistant\CreationTools;
    use XcVm\Domain\User\UserCredits;
    function check(bool $ok,string $message):void { if (!$ok) { throw new \RuntimeException($message); } }
    function denied(callable $fn,string $expected):void { try { $fn(); } catch (\RuntimeException $e) { check($e->getMessage()===$expected,'Expected '.$expected.', got '.$e->getMessage());return; } throw new \RuntimeException('Expected '.$expected); }
    $db=new class {
        public \PDO $pdo;private array $rows=[];private int $count=0;public string $fail='';
        public function __construct() {
            $this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
            foreach ([
                'users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,password TEXT,owner_id INTEGER,member_group_id INTEGER,status INTEGER,credits NUMERIC,override_packages TEXT)',
                'users_groups(group_id INTEGER PRIMARY KEY,group_name TEXT,is_admin INTEGER,is_reseller INTEGER,subresellers TEXT,create_sub_resellers_price NUMERIC,allow_change_username INTEGER,minimum_username_length INTEGER,minimum_password_length INTEGER)',
                'lines(id INTEGER PRIMARY KEY,username TEXT UNIQUE,password TEXT,member_id INTEGER,package_id INTEGER)',
                'users_packages(id INTEGER PRIMARY KEY,package_name TEXT,is_line INTEGER,is_official INTEGER,groups TEXT,official_credits NUMERIC,official_duration INTEGER,official_duration_in TEXT,max_connections INTEGER,bouquets TEXT,output_formats TEXT)',
                'users_logs(id INTEGER PRIMARY KEY,owner INTEGER,type TEXT,action TEXT,log_id INTEGER,package_id INTEGER,cost NUMERIC,credits_after NUMERIC,date INTEGER,deleted_info TEXT)',
                'users_credits_logs(id INTEGER PRIMARY KEY,target_id INTEGER,admin_id INTEGER,amount NUMERIC,date INTEGER,reason TEXT)',
            ] as $schema) { $this->pdo->exec('CREATE TABLE '.$schema); }
            $this->pdo->exec("INSERT INTO users VALUES(1,'admin','secret',0,1,1,100,'{}'),(10,'parent','secret',1,3,1,100,'{}');
                INSERT INTO users_groups VALUES(1,'Admins',1,0,'[]',0,1,8,8),(3,'Parent',0,1,'[4]',3,1,8,8),(4,'Child',0,1,'[]',0,1,8,8),(5,'Privileged',1,1,'[]',0,1,8,8);
                INSERT INTO users_packages VALUES(1,'Day',1,1,'[3]',5,1,'days',1,'[]','[]'),(2,'Foreign',1,1,'[4]',7,1,'days',1,'[]','[]');");
        }
        public function query(string $sql,mixed ...$args):bool {
            if ($this->fail!=='' && str_contains($sql,$this->fail)) { return false; }
            $stmt=$this->pdo->prepare(str_replace(' FOR UPDATE','',$sql));$stmt->execute($args);$this->rows=$stmt->columnCount()?$stmt->fetchAll(\PDO::FETCH_ASSOC):[];$this->count=$stmt->columnCount()?count($this->rows):$stmt->rowCount();return true;
        }
        public function get_raw_rows():array { return $this->rows; }
        public function get_row():array { return $this->rows[0]??[]; }
        public function get_col():mixed { return array_values($this->rows[0]??[])[0]??null; }
        public function num_rows():int { return $this->count; }
        public function beginTransaction():bool { return $this->pdo->beginTransaction(); }
        public function isInTransaction():bool { return $this->pdo->inTransaction(); }
        public function commit():bool { return $this->pdo->commit(); }
        public function rollback():bool { return $this->pdo->inTransaction() && $this->pdo->rollBack(); }
    };
    $rUserInfo=['id'=>10,'member_group_id'=>3];$rPermissions=['is_admin'=>0,'is_reseller'=>1,'create_line'=>1,'create_sub_resellers'=>1];$tools=new CreationTools('reseller');
    $options=$tools->call('creation_options',[]);check(count($options['packages'])===1 && count($options['reseller_groups'])===1,'Options escaped scope');check(!str_contains(json_encode($options),'secret'),'Options leaked password');
    denied(fn()=>$tools->call('create_subscription',['username'=>'username1','package_id'=>2]),'FORBIDDEN');
    denied(fn()=>$tools->call('create_subscription',['username'=>'username1','package_id'=>1,'password'=>'unsafe']),'INVALID_ARGUMENTS');
    denied(fn()=>$tools->call('create_reseller',['username'=>'username1','group_id'=>5,'initial_credits'=>0]),'FORBIDDEN');
    denied(fn()=>$tools->call('create_reseller',['username'=>'username1','group_id'=>4,'initial_credits'=>98]),'INSUFFICIENT_CREDITS');
    denied(fn()=>$tools->call('create_reseller',['username'=>'username1','group_id'=>4,'initial_credits'=>-1]),'INVALID_ARGUMENTS');
    $line=$tools->call('create_subscription',['username'=>'line_created','package_id'=>1])['proposal'];
    check(UserCredits::balance(10)===100.0 && (int)$db->pdo->query('SELECT COUNT(*) FROM lines')->fetchColumn()===0,'Proposal wrote data');
    check(!str_contains(json_encode($line),'secret'),'Proposal leaked raw account row');
    $db->pdo->exec('UPDATE users_packages SET official_credits=6 WHERE id=1');denied(fn()=>$tools->execute($line),'STALE_PROPOSAL');$db->pdo->exec('UPDATE users_packages SET official_credits=5 WHERE id=1');
    $rPermissions['create_line']=0;denied(fn()=>$tools->execute($line),'FORBIDDEN');$rPermissions['create_line']=1;
    $db->fail='INSERT INTO `users_logs`';denied(fn()=>$tools->execute($line),'DATABASE_ERROR');$db->fail='';
    check(UserCredits::balance(10)===100.0 && (int)$db->pdo->query('SELECT COUNT(*) FROM lines')->fetchColumn()===0,'Failed audit left a purchase');
    $created=$tools->execute($line);check(UserCredits::balance(10)===95.0 && strlen($created['created']['credentials']['password'])>=24,'Line purchase/credentials wrong');
    denied(fn()=>$tools->execute($line),'USERNAME_EXISTS');
    $reseller=$tools->call('create_reseller',['username'=>'child_created','group_id'=>4,'initial_credits'=>10])['proposal'];
    check($reseller['debit']===13.0,'Fee and initial balance not combined');
    $db->pdo->exec("UPDATE users_groups SET subresellers='[]' WHERE group_id=3");denied(fn()=>$tools->execute($reseller),'FORBIDDEN');$db->pdo->exec("UPDATE users_groups SET subresellers='[4]' WHERE group_id=3");
    $db->fail='INSERT INTO `users_credits_logs`';denied(fn()=>$tools->execute($reseller),'DATABASE_ERROR');$db->fail='';
    check(UserCredits::balance(10)===95.0 && (int)$db->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===2,'Failed transfer audit left account/fee');
    $created=$tools->execute($reseller);$id=$created['created']['id'];check(UserCredits::balance(10)===82.0 && UserCredits::balance($id)===10.0,'Incorrect reseller fee/transfer');
    check(!str_contains((string)$db->pdo->query('SELECT GROUP_CONCAT(deleted_info) FROM users_logs')->fetchColumn(),$created['created']['credentials']['password']),'Assistant audit exposed credentials');
    $rUserInfo=['id'=>1,'member_group_id'=>1];$rPermissions=['is_admin'=>1,'advanced'=>[]];$admin=new CreationTools('admin');
    $p=$admin->call('create_reseller',['username'=>'admin_child','group_id'=>4,'initial_credits'=>1.25])['proposal'];check($p['debit']===0.0,'Admin charged reseller fee');
    $c=$admin->execute($p);check(UserCredits::balance(1)===100.0 && UserCredits::balance($c['created']['id'])===1.25,'Admin initial credit incorrect');
    $p=$admin->call('create_subscription',['username'=>'admin_line','package_id'=>2])['proposal'];$admin->execute($p);check(UserCredits::balance(1)===100.0,'Admin package unexpectedly charged');
    $rUserInfo['member_group_id']=7;$rPermissions['advanced']=['users','mng_regusers'];check($admin->definitions()===[],'Read-only admin can create');
    echo "PASS: creation scope, explicit proposals, prices, groups, balances, atomic rollback, native boundary, credentials, duplicate names and admin permissions\n";
}
