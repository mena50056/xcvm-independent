"""Real individual/bulk controllers: permissions, POST, CSRF and shared dispatch."""
import http.cookiejar, json, pathlib, socket, subprocess, tempfile, time, unittest, urllib.request, urllib.parse, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
HARNESS=r'''<?php
namespace XcVm\Core\Proxy {
 final class ProxyProfiles {public static function app(){return new self;}public static function refreshRouting():void{}}
 final class ServerProxyControl {
  public function __construct($profiles){}
  public function status($ids){if(!$ids||in_array(0,$ids,true))throw new \InvalidArgumentException('INVALID_SERVERS');return ['state'=>'pending','servers'=>$ids];}
  public function change($ids,$enable,$selected,$actor){file_put_contents(__DIR__.'/mutation',json_encode([$ids,$enable,$selected,$actor]));return ['state'=>'pending','servers'=>$ids];}
 }
}
namespace {
session_start();if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/signin'){$_SESSION['role']=$_GET['role']??'read';$_SESSION['horus_proxy_csrf']='fixture-csrf';echo json_encode(['csrf'=>'fixture-csrf']);exit;}
if(!isset($_SESSION['role'])){http_response_code(401);exit;}
define('XCVM_INDEPENDENT_LAB',true);$db=new \stdClass;$rUserInfo=['id'=>9,'username'=>'Operator'];$rPermissions=['is_admin'=>true,'advanced'=>$_SESSION['role']==='edit'?['edit_server']:['servers']];
require AUTH;require REQUEST;require BASE;require SERVER;require MULTI;
\XcVm\Core\Http\RequestManager::set(array_merge($_GET,$_POST));
if(($_GET['action']??'server')==='multi')(new \XcVm\Public\Controllers\Admin\Ajax\MultiAjaxController)->multi();
(new \XcVm\Public\Controllers\Admin\Ajax\ServerAjaxController)->server();
}
'''
class Http(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.path=pathlib.Path(cls.tmp.name);source=HARNESS
  for name,path in {'AUTH':'panel/src/Core/Auth/Authorization.php','REQUEST':'panel/src/Core/Http/RequestManager.php','BASE':'panel/src/Public/Controllers/Admin/Ajax/BaseAjaxController.php','SERVER':'panel/src/Public/Controllers/Admin/Ajax/ServerAjaxController.php','MULTI':'panel/src/Public/Controllers/Admin/Ajax/MultiAjaxController.php'}.items():source=source.replace('require '+name+';', 'require '+json.dumps(str(ROOT/path))+';')
  (cls.path/'router.php').write_text(source)
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
  cls.url=f'http://127.0.0.1:{port}';cls.process=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(cls.path/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(50):
   try:urllib.request.urlopen(cls.url+'/signin').read();break
   except OSError:time.sleep(.1)
 @classmethod
 def tearDownClass(cls):cls.process.terminate();cls.process.wait();cls.tmp.cleanup()
 def client(self,role=None):
  client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  if role:client.open(self.url+'/signin?role='+role).close()
  return client
 def request(self,client,action='server',sub='enable_proxy',body=None,ids='[2,3]'):
  url=self.url+'/api?'+urllib.parse.urlencode({'action':action,'sub':sub,'server_id':2,'type':'server','ids':ids})
  request=urllib.request.Request(url,None if body is None else urllib.parse.urlencode(body).encode(),headers={'X-Requested-With':'XMLHttpRequest'})
  try:response=client.open(request)
  except urllib.error.HTTPError as error:response=error
  with response:raw=response.read();return response.status,json.loads(raw) if raw else None
 def test_anonymous_and_readonly(self):
  self.assertEqual(self.request(self.client())[0],401)
  for action in ['server','multi']:self.assertFalse(self.request(self.client('read'),action,body={'csrf':'fixture-csrf'})[1]['result'])
 def test_csrf_post_and_poll(self):
  client=self.client('edit')
  for action in ['server','multi']:
   self.assertEqual(self.request(client,action)[1]['error'],'POST_REQUIRED')
   self.assertEqual(self.request(client,action,body={'csrf':'bad'})[0],403)
   self.assertEqual(self.request(client,action,'proxy_status')[1]['state'],'pending')
 def test_single_and_bulk(self):
  client=self.client('edit')
  for action,ids in [('server',[2]),('multi',[2,3])]:
   for sub,enable in [('enable_proxy',True),('disable_proxy',False)]:
    self.assertTrue(self.request(client,action,sub,{'csrf':'fixture-csrf','proxy_id':7})[1]['result'])
    saved=json.loads((self.path/'mutation').read_text());self.assertEqual(saved[:3],[ids,enable,7]);self.assertEqual(saved[3]['id'],9)
  self.assertEqual(self.request(client,'multi','proxy_status',ids='[[1]]')[1]['error'],'INVALID_SERVERS')
if __name__=='__main__':unittest.main()
