<?php
declare(strict_types=1);
namespace XcVm\Public\Controllers\Admin\Ajax;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Proxy\ProxyProfiles;

final class ProxyProfileAjaxController extends BaseAjaxController {
    public static function refresh(): void {
        ProxyProfiles::refreshRouting();
        \XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Server\ServerSavedEvent([]));
    }
    public function index(): never {
        if(!defined('XCVM_INDEPENDENT_LAB'))$this->fail(['error'=>'UNSUPPORTED_RUNTIME']);
        $this->gateAny([['adv','edit_server'],['adv','add_server']]);
        header('Cache-Control: no-store');
        try {
            $profiles=ProxyProfiles::app();$id=(int)($_GET['id']??$_POST['id']??0);$action=(string)($_GET['sub']??'status');
            if($action==='status'){$this->requireXhr();$this->ok(['status'=>$profiles->status($id)]);}
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \InvalidArgumentException('POST_REQUIRED');
            $data=$action==='save'?json_decode(file_get_contents('php://input',false,null,0,131073),true,64,JSON_THROW_ON_ERROR):$_POST;
            if(!is_array($data)||!is_string($data['csrf']??null)||!isset($_SESSION['horus_proxy_csrf'])||!hash_equals($_SESSION['horus_proxy_csrf'],$data['csrf'])){http_response_code(403);$this->fail(['error'=>'INVALID_CSRF']);}
            if($action==='save') {
                $this->requireXhr();$this->gate('adv',$id?'edit_server':'add_server');
                $doc=$profiles->save($id,$data['profile']??[],(string)($data['revision']??''));self::refresh();
                $this->ok(['id'=>$doc['id'],'revision'=>$doc['revision'],'status'=>$profiles->status($doc['id'])]);
            }
            if(in_array($action,['enable','disable','delete','kill','rotate_key'],true)) {
                $this->requireXhr();$this->gate('adv','edit_server');$row=$profiles->row($id);if(!$row)throw new \InvalidArgumentException('INVALID_PROXY');
                if($action==='delete')\XcVm\Domain\Server\ServerRepository::deleteById($id);
                elseif($action==='rotate_key') {
                    if(!Authorization::isFullAdmin())$this->fail(['error'=>'ADMIN_REQUIRED']);
                    $GLOBALS['db']->query('UPDATE servers SET proxy_key_gen=proxy_key_gen+1,proxy_signed=0,enabled=0,status=3 WHERE id=? AND server_type=1',$id);
                } elseif($action==='kill') {
                    if(\XcVm\Core\Config\SettingsManager::get('redis_handler')) {
                        foreach((array)json_decode((string)$row['parent_id'],true) as $parent)foreach(\XcVm\Domain\Stream\ConnectionTracker::getRedisConnections(null,$parent,null,true,false,false) as $connection)if((int)($connection['proxy_id']??0)===$id)\XcVm\Domain\Stream\ConnectionTracker::closeConnection($connection);
                    } else {
                        $GLOBALS['db']->query('SELECT * FROM lines_live WHERE proxy_id=?',$id);foreach($GLOBALS['db']->get_rows() as $connection)\XcVm\Domain\Stream\ConnectionTracker::closeConnection($connection);
                    }
                } else {
                    $doc=$profiles->document($id);if(!isset($doc['profile']))throw new \InvalidArgumentException('PROFILE_NOT_CONFIGURED');
                    $profile=$doc['profile'];$profile['enabled']=$action==='enable';$profiles->save($id,$profile,$doc['revision']);
                }
                self::refresh();$this->ok();
            }
            if($action==='enrollment') {
                if(!Authorization::isFullAdmin()){$this->fail(['error'=>'ADMIN_REQUIRED']);}
                $root=dirname(MAIN_HOME,2);$urlFile=CONFIG_PATH.'proxy_main_url';
                $url=is_file($urlFile)?trim((string)file_get_contents($urlFile)):'';
                if($url==='')foreach($profiles->parents() as $row)if((int)$row['is_main']===1){$ip=$row['server_ip'];$url='https://'.(str_contains($ip,':')?'['.$ip.']':$ip).':'.(int)$row['https_broadcast_port'].'/horus-proxy/v1/sync';break;}
                $enrollment=$profiles->enrollment($id,$url,(string)@file_get_contents($root.'/tls/server.crt'));
                $pipes=[];$process=proc_open(['/usr/bin/python3',$root.'/deployment/build_proxy_package.py'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
                if(!is_resource($process))throw new \RuntimeException('PACKAGE_FAILED');
                fwrite($pipes[0],json_encode($enrollment,JSON_THROW_ON_ERROR));fclose($pipes[0]);$zip=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
                if(proc_close($process)!==0||substr($zip,0,2)!=='PK')throw new \RuntimeException('PACKAGE_FAILED');
                $this->ok(['filename'=>'horus-proxy-'.$id.'.zip','package'=>base64_encode($zip)]);
            }
            throw new \InvalidArgumentException('INVALID_ACTION');
        }catch(\Throwable $e){$this->fail(['error'=>$e instanceof \InvalidArgumentException||$e->getMessage()==='PROFILE_CONFLICT'?$e->getMessage():'PROXY_OPERATION_FAILED']);}
    }
}
