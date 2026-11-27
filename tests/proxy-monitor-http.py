"""Real proxy AJAX controller and authorization over HTTP; isolated service doubles."""
import http.cookiejar,json,pathlib,shutil,socket,subprocess,tempfile,time,unittest,urllib.request,urllib.error,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1]
HARNESS=r'''<?php
namespace XcVm\Core\Proxy {
 final class ProxyProfiles {
  public static function app(){return new self;}
  public static function refreshRouting():void{}
  public function row($id){return ['id'=>$id];}
  public function proxies(){return [['id'=>2]];}
  public function status($id){return ['state'=>'applied'];}
  public function history($id){return [['id'=>str_repeat('b',32),'changes'=>[]]];}
  public function restore($id,$entry,$revision,$actor){if($revision!=='reviewed')throw new \RuntimeException('PROFILE_CONFLICT');file_put_contents(__DIR__.'/restore',json_encode([$id,$entry,$actor]));return ['revision'=>'new-version'];}
 }
 final class ProxyMonitor {
  public function __construct($profiles){}
  public function snapshot($id){return ['id'=>$id,'ports'=>[]];}
  public function run($id,$manual){file_put_contents(__DIR__.'/probe',json_encode([$id,$manual]));return $this->snapshot($id);}
  public function acknowledge($id,$alert,$actor){file_put_contents(__DIR__.'/ack',json_encode([$id,$alert,$actor]));}
 }
}
namespace XcVm\Domain\Stream {final class ConnectionTracker{public static function getLiveConnections($id,$proxy){return 3;}}}
namespace XcVm\Core\Events {final class EventDispatcher{public static function dispatch($event):void{}}}
namespace XcVm\Core\Events\Server {final class ServerSavedEvent{public function __construct($data){}}}
namespace XcVm\Core\Config {final class ConfigReader{public static function get($key){return 1;}}}
namespace {
session_start();$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/origin'){$proxyPeers=['192.0.2.2'=>['server_type'=>1,'enabled'=>1,'parent_id'=>[1]]];$_SERVER['XC_PEER_ADDR']=$_SERVER['HTTP_TEST_PEER']??'203.0.113.5';require ORIGIN;exit;}
if($path==='/signin'){$_SESSION['role']=$_GET['role']??'read';$_SESSION['horus_proxy_csrf']=bin2hex(random_bytes(16));header('Content-Type: application/json');echo json_encode(['csrf'=>$_SESSION['horus_proxy_csrf']]);exit;}
if(!isset($_SESSION['role'])){http_response_code(401);exit;}
define('XCVM_INDEPENDENT_LAB',true);$db=new stdClass;$role=$_SESSION['role'];$rUserInfo=['id'=>9,'username'=>'fixture-operator','member_group_id'=>2];$rPermissions=['is_admin'=>$role!=='reseller','advanced'=>$role==='edit'?['servers','edit_server']:($role==='read'?['servers']:['unrelated'])];
require AUTH;require BASE;require CONTROLLER;(new \XcVm\Public\Controllers\Admin\Ajax\ProxyProfileAjaxController)->index();
}
'''

class Http(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.path=pathlib.Path(cls.tmp.name)
  source=HARNESS
  for key,path in {'ORIGIN':'runtime/proxy-origin-health.php','AUTH':'panel/src/Core/Auth/Authorization.php','BASE':'panel/src/Public/Controllers/Admin/Ajax/BaseAjaxController.php','CONTROLLER':'panel/src/Public/Controllers/Admin/Ajax/ProxyProfileAjaxController.php'}.items():source=source.replace(key,json.dumps(str(ROOT/path)))
  (cls.path/'router.php').write_text(source)
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  cls.url=f'http://127.0.0.1:{port}';cls.process=subprocess.Popen([shutil.which('php'),'-S',f'127.0.0.1:{port}',str(cls.path/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(50):
   try:urllib.request.urlopen(cls.url+'/signin').read();break
   except OSError:time.sleep(.1)
 @classmethod
 def tearDownClass(cls):cls.process.terminate();cls.process.wait();cls.tmp.cleanup()
 def client(self,role=None):
  op=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));csrf=''
  if role:csrf=json.load(op.open(self.url+'/signin?role='+role))['csrf']
  return op,csrf
 def request(self,op,action='monitor',body=None,method=None):
  req=urllib.request.Request(self.url+'/api?sub='+action+'&id=2',None if body is None else urllib.parse.urlencode(body).encode(),headers={'X-Requested-With':'XMLHttpRequest'},method=method)
  try:r=op.open(req)
  except urllib.error.HTTPError as e:r=e
  with r:raw=r.read();return r.status,json.loads(raw) if raw else None
 def test_auth_and_read_permission(self):
  self.assertEqual(self.request(self.client()[0])[0],401)
  for role in ['reseller','none']:self.assertFalse(self.request(self.client(role)[0])[1]['result'])
  viewer,csrf=self.client('read');self.assertTrue(self.request(viewer)[1]['result']);self.assertTrue(self.request(viewer,'history')[1]['result'])
  for action in ['diagnose','acknowledge','restore']:self.assertFalse(self.request(viewer,action,{'csrf':csrf,'entry':'x','revision':'reviewed'})[1]['result'])
 def test_post_csrf_session_binding_and_fixed_targets(self):
  editor,csrf=self.client('edit');other,other_csrf=self.client('edit')
  self.assertEqual(self.request(editor,'diagnose')[1]['error'],'POST_REQUIRED')
  self.assertEqual(self.request(editor,'diagnose',{'csrf':'bad'})[0],403)
  self.assertEqual(self.request(other,'diagnose',{'csrf':csrf})[0],403)
  self.assertTrue(self.request(editor,'diagnose',{'csrf':csrf,'url':'http://unregistered.invalid'})[1]['result']);self.assertEqual(json.loads((self.path/'probe').read_text()),[2,True])
  self.assertTrue(self.request(editor,'acknowledge',{'csrf':csrf,'alert':'a'*32})[1]['result']);self.assertEqual(json.loads((self.path/'ack').read_text())[2]['id'],9)
  self.assertEqual(self.request(editor,'restore',{'csrf':csrf,'entry':'b'*32,'revision':'stale'})[1]['error'],'PROFILE_CONFLICT')
  self.assertTrue(self.request(editor,'restore',{'csrf':csrf,'entry':'b'*32,'revision':'reviewed'})[1]['result'])
 def test_origin_endpoint_accepts_registered_peer_only(self):
  op,_=self.client();query='?nonce='+('a'*32);headers={'X-Horus-Proxy-Revision':'b'*64}
  for h in [headers,{**headers,'X-IP':'192.0.2.2','X-Forwarded-For':'192.0.2.2'}]:
   with self.assertRaises(urllib.error.HTTPError) as error:op.open(urllib.request.Request(self.url+'/origin'+query,headers=h))
   self.assertEqual(error.exception.code,404)
  result=json.load(op.open(urllib.request.Request(self.url+'/origin'+query,headers={**headers,'Test-Peer':'192.0.2.2'})));self.assertEqual(result['origin_id'],1);self.assertEqual(result['nonce'],'a'*32)

if __name__=='__main__':unittest.main()
