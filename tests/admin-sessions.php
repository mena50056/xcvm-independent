<?php
declare(strict_types=1);
namespace XcVm\Core\Util {
    final class NetworkUtils {public static function getUserIP(): string {return $_SERVER['REMOTE_ADDR']??'';}}
}
namespace XcVm\Domain\User {
    final class UserRepository {
        public static function getAuthUserByCredentials(string $name,string $password): ?array {
            $user=$GLOBALS['db']->user;
            return $user['username']===$name&&\XcVm\Core\Auth\Authenticator::checkPassword($password,$user['password'])?$user:null;
        }
    }
}
namespace XcVm\Core\Auth {
    final class AuthRepository {
        public static function getCurrentCode(bool $row=false): mixed {return $row?['id'=>1,'groups'=>'[1]']:'panel';}
        public static function getPermissions(int $id): array {return ['is_admin'=>1,'is_reseller'=>1];}
    }
    final class TwoFactor {public static function hold(...$args): bool {return false;}}
}
namespace {
    use XcVm\Core\Auth\{Authenticator,SessionManager};
    $root=dirname(__DIR__);require $root.'/panel/src/Core/Bootstrap/BootStageInterface.php';require $root.'/panel/src/Core/Bootstrap/Stage/SessionStage.php';require $root.'/panel/src/Core/Auth/SessionManager.php';require $root.'/panel/src/Core/Auth/Authenticator.php';
    foreach(['STATUS_FAILURE'=>0,'STATUS_SUCCESS'=>1,'STATUS_DISABLED'=>5,'STATUS_NOT_ADMIN'=>6,'STATUS_INVALID_CAPTCHA'=>12,'STATUS_INVALID_CODE'=>13,'STATUS_NOT_RESELLER'=>35] as $key=>$value)define($key,$value);
    function verify(bool $ok,string $why): void {if(!$ok)throw new \RuntimeException($why);}
    $GLOBALS['db']=new class {
        public array $user=[];private array $row=[];
        public function query(string $sql,...$args): bool {
            $this->row=str_contains($sql,'COUNT(*)')?['count'=>1]:[];
            // Model the old password-writing query too: the regression must fail before the fix.
            if(str_contains($sql,'UPDATE `users` SET `password` = ?'))$this->user['password']=$args[0];
            return true;
        }
        public function get_row(): array {return $this->row;}
    };
    $GLOBALS['rSettings']=['recaptcha_enable'=>0,'save_login_logs'=>0];$_SERVER['REMOTE_ADDR']='192.0.2.10';$settings=['ip_logout'=>1,'ip_subnet_match'=>0];
    $dir=sys_get_temp_dir().'/horus-sessions-'.bin2hex(random_bytes(8));mkdir($dir,0700);ini_set('session.save_path',$dir);ini_set('session.gc_probability','0');
    $password=Authenticator::hashPassword('test-only-password');$GLOBALS['db']->user=['id'=>9,'username'=>'fixture','password'=>$password,'member_group_id'=>1,'status'=>1];
    foreach(['admin','reseller'] as $scope) {
        $auth=$scope==='admin'?'hash':'reseller';$proof=$scope==='admin'?'verify':'rverify';$snapshots=[];
        foreach([1,2] as $browser) {
            session_id('');session_start();$old=session_id();
            $result=$scope==='admin'?Authenticator::login(['username'=>'fixture','password'=>'test-only-password'],true):Authenticator::resellerLogin(['username'=>'fixture','password'=>'test-only-password']);
            verify($result['status']===STATUS_SUCCESS,'Sign-in failed');verify(session_id()!==$old,'Session fixation protection was lost');$snapshots[]=$_SESSION;session_write_close();
        }
        verify($GLOBALS['db']->user['password']===$password,'A second sign-in rehashed the credential');
        foreach($snapshots as $snapshot)verify(($snapshot[$auth]??0)===9&&($snapshot[$proof]??'')===md5('fixture||'.$password),'A browser credential proof was invalidated');
        $_SESSION=$snapshots[0];if($scope==='admin')verify(SessionManager::adminSessionValid($GLOBALS['db']->user,['is_admin'=>1],$settings),'The first browser was signed out');
    }
    $_SESSION=$snapshots[0];$_SESSION['verify']=md5('fixture||'.$password);$_SESSION['ip']='192.0.2.10';
    verify(!SessionManager::adminSessionValid(['status'=>0]+array_diff_key($GLOBALS['db']->user,['status'=>true]),['is_admin'=>1],$settings),'Disabled account retained access');
    $_SERVER['REMOTE_ADDR']='192.0.2.11';verify(!SessionManager::adminSessionValid($GLOBALS['db']->user,['is_admin'=>1],$settings),'IP binding was weakened');$_SERVER['REMOTE_ADDR']='192.0.2.10';
    $GLOBALS['db']->user['password']=Authenticator::hashPassword('new-test-only-password');verify(!SessionManager::adminSessionValid($GLOBALS['db']->user,['is_admin'=>1],$settings),'A real password change did not revoke the session');
    $_SESSION=[];verify(Authenticator::login(['username'=>'fixture','password'=>'wrong'],true)['status']===STATUS_FAILURE,'Wrong password accepted');verify(!isset($_SESSION['hash']),'Wrong password created an authenticated session');
    $recent=$dir.'/sess_retainthirtyminutes';$expired=$dir.'/sess_expiredsixtyminutes';file_put_contents($recent,'hash|i:9;');file_put_contents($expired,'hash|i:9;');touch($recent,time()-1800);touch($expired,time()-3700);
    ini_set('session.gc_maxlifetime','1440');ini_set('session.gc_probability','1');ini_set('session.gc_divisor','1');session_id('');\XcVm\Core\Bootstrap\Stage\SessionStage::startSession();
    clearstatcache();verify(is_file($recent),'Garbage collection removed a session before the panel idle limit');verify(!is_file($expired),'Expired sessions stopped being collected');verify((int)ini_get('session.gc_maxlifetime')>=3600,'Retention shorter than the idle guard');
    verify(session_get_cookie_params()['httponly']&&session_get_cookie_params()['samesite']==='Strict','Cookie protections were weakened');session_destroy();
    foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);
    echo "Concurrent admin/reseller sessions, credential revocation, session fixation, IP binding and garbage collection checks passed\n";
}
