"""Exercise the real assistant controller over HTTP with isolated model/tool doubles."""
import http.cookiejar,json,pathlib,socket,subprocess,tempfile,time,unittest,urllib.request,urllib.error,shutil

ROOT=pathlib.Path(__file__).resolve().parents[1]
HARNESS=r'''<?php
namespace XcVm\Domain\Assistant {
 final class AssistantClient {
  public static function enabled(): bool {return true;}
  public function chat(array $messages, PanelTools $tools): array {
   file_put_contents(__DIR__.'/seen_messages',json_encode($messages));
   if (end($messages)['content']==='bulk') { return ['message'=>'','proposal'=>['kind'=>'creation','tool'=>'create_subscriptions_bulk','arguments'=>['count'=>2,'package_id'=>1],'revision'=>'internal-only','public'=>['title'=>'create_subscriptions_bulk','target'=>'2 subscriptions','details'=>['Total: 10','Balance: 100 → 90']]]]; }
   if (end($messages)['content']==='create') { return ['message'=>'','proposal'=>['kind'=>'creation','revision'=>'internal-only','public'=>['title'=>'create_reseller','target'=>'new_user','details'=>['Fee: 3','Initial credits: 10','Balance: 100 → 87']]]]; }
   return ['message'=>'','proposal'=>['line_id'=>1,'username'=>'fixture','member_id'=>1,'before'=>1,'enabled'=>false]];
  }
 }
 final class PanelTools {
  public function __construct(string $scope) {}
  public function execute(array $proposal): array {if (($proposal['tool']??'')==='create_subscriptions_bulk') { if (($proposal['arguments']['count']??null)!==2) {throw new \RuntimeException('INVALID_ARGUMENTS');} file_put_contents(__DIR__.'/executed',"1\n",FILE_APPEND); return ['saved'=>true,'signal_pending'=>false,'created_batch'=>[['kind'=>'subscription','id'=>11,'credentials'=>['username'=>'bulk1','password'=>'BULK_SECRET_1']],['kind'=>'subscription','id'=>12,'credentials'=>['username'=>'bulk2','password'=>'BULK_SECRET_2']]],'total_debit'=>10,'credits_after'=>90]; }file_put_contents(__DIR__.'/executed',"1\n",FILE_APPEND);return ['saved'=>true,'signal_pending'=>false] + (($proposal['kind']??'')==='creation' ? ['created'=>['kind'=>'reseller','id'=>2,'credentials'=>['username'=>'new_user','password'=>'NEW_SECRET_PASSWORD']],'credits_after'=>87] : []);}
 }
}
namespace {
session_start();
if ($_SERVER['REQUEST_URI']==='/signin') {$_SESSION['hash']=1;echo 'ok';exit;}
if ($_SERVER['REQUEST_URI']==='/reseller-signin') {$_SESSION['reseller']=1;$_SESSION['hash']=1;$_SESSION['role']='reseller';$_SESSION['xc_assistant_reseller']=['pending'=>['token'=>'legacy-token']];echo 'ok';exit;}
if ($_SERVER['REQUEST_URI']==='/legacy-state') {echo json_encode(isset($_SESSION['xc_assistant_reseller']));exit;}
$rUserInfo=['id'=>1,'member_group_id'=>1];$rPermissions=['is_admin'=>($_SESSION['role']??'admin')==='admin'?1:0,'is_reseller'=>1];
array_walk_recursive($_SESSION, static function (&$value) { $value = (string) $value; });
session_write_close(); // Scope bootstraps may release the lock before dispatch.
require CONTROLLER;
require RESELLER_CONTROLLER;
if ($_SERVER['REQUEST_URI']==='/reseller-api') {(new \XcVm\Public\Controllers\Reseller\AssistantController())->index();}
(new \XcVm\Public\Controllers\Admin\AssistantController())->index();
}
'''

class AssistantHTTP(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.path=pathlib.Path(cls.tmp.name)
  source=HARNESS.replace('RESELLER_CONTROLLER',json.dumps(str(ROOT/'panel/src/Public/Controllers/Reseller/AssistantController.php').replace('\\','/'))).replace('CONTROLLER',json.dumps(str(ROOT/'panel/src/Public/Controllers/Admin/AssistantController.php').replace('\\','/')))
  (cls.path/'router.php').write_text(source,encoding='utf-8')
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  cls.url=f'http://127.0.0.1:{port}'
  cls.process=subprocess.Popen([shutil.which('php'),'-S',f'127.0.0.1:{port}',str(cls.path/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(50):
   try:urllib.request.urlopen(cls.url+'/signin',timeout=1);break
   except OSError:time.sleep(.1)
 @classmethod
 def tearDownClass(cls):cls.process.terminate();cls.process.wait();cls.tmp.cleanup()
 def client(self,login=True):
  op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  if login:op.open(self.url+'/signin').read()
  return op
 def request(self,op,data=None,csrf='',path='/api'):
  req=urllib.request.Request(self.url+path,data=json.dumps(data).encode() if data else None,headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
  try:
   with op.open(req) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as error:
   with error:return error.code,json.load(error)
 def test_auth_csrf_confirmation_replay_and_session_binding(self):
  self.assertEqual(self.request(self.client(False))[0],401)
  a,b=self.client(),self.client();csrf=self.request(a)[1]['csrf'];bcsrf=self.request(b)[1]['csrf']
  self.assertEqual(self.request(a,{'operation':'chat','message':'disable'},'bad')[0],403)
  status,result=self.request(a,{'operation':'chat','message':'disable'},csrf);self.assertEqual(status,200)
  self.assertFalse((self.path/'executed').exists())
  self.assertEqual(self.request(b,{'operation':'confirm','token':result['token']},bcsrf)[0],409)
  self.assertEqual(self.request(a,{'operation':'confirm','token':result['token']},csrf)[0],200)
  self.assertEqual(self.request(a,{'operation':'confirm','token':result['token']},csrf)[0],409)
  self.assertEqual((self.path/'executed').read_text().splitlines(),['1'])
 def test_cancel_reset_and_rate_limit(self):
  a=self.client();csrf=self.request(a)[1]['csrf']
  result=self.request(a,{'operation':'chat','message':'disable'},csrf)[1]
  self.assertEqual(self.request(a,{'operation':'cancel','token':result['token']},csrf)[0],200)
  self.assertEqual(self.request(a,{'operation':'confirm','token':result['token']},csrf)[0],409)
  for _ in range(5):self.assertEqual(self.request(a,{'operation':'chat','message':'disable'},csrf)[0],200)
  self.assertEqual(self.request(a,{'operation':'reset'},csrf)[0],200)
  self.assertEqual(self.request(a,{'operation':'chat','message':'disable'},csrf)[0],429)
 def test_creation_confirmation_keeps_credentials_out_of_history(self):
  a=self.client();csrf=self.request(a)[1]['csrf']
  status,proposal=self.request(a,{'operation':'chat','message':'create'},csrf)
  self.assertEqual(status,200);self.assertEqual(proposal['proposal']['title'],'create_reseller')
  self.assertNotIn('internal-only',json.dumps(proposal));self.assertNotIn('NEW_SECRET_PASSWORD',json.dumps(proposal))
  status,result=self.request(a,{'operation':'confirm','token':proposal['token']},csrf)
  self.assertEqual(status,200);self.assertEqual(result['execution']['created']['credentials']['password'],'NEW_SECRET_PASSWORD')
  self.assertEqual(self.request(a,{'operation':'confirm','token':proposal['token']},csrf)[0],409)
  self.assertEqual(self.request(a,{'operation':'chat','message':'inspect history'},csrf)[0],200)
  self.assertNotIn('NEW_SECRET_PASSWORD',(self.path/'seen_messages').read_text())
 def test_bulk_confirmation_is_single_use_and_credentials_are_not_history(self):
  a=self.client();csrf=self.request(a)[1]['csrf']
  before=len((self.path/'executed').read_text().splitlines()) if (self.path/'executed').exists() else 0
  status,proposal=self.request(a,{'operation':'chat','message':'bulk'},csrf)
  self.assertEqual(status,200);self.assertEqual(proposal['proposal']['title'],'create_subscriptions_bulk')
  self.assertNotIn('internal-only',json.dumps(proposal));self.assertNotIn('BULK_SECRET',json.dumps(proposal))
  status,result=self.request(a,{'operation':'confirm','token':proposal['token'],'count':999},csrf)
  self.assertEqual(status,200);self.assertEqual(len(result['execution']['created_batch']),2)
  self.assertEqual(self.request(a,{'operation':'confirm','token':proposal['token']},csrf)[0],409)
  self.assertEqual(len((self.path/'executed').read_text().splitlines()),before+1)
  self.request(a,{'operation':'chat','message':'inspect history'},csrf)
  self.assertNotIn('BULK_SECRET',(self.path/'seen_messages').read_text())
 def test_reseller_endpoints_and_old_confirmations_are_rejected(self):
  self.assertEqual(self.request(self.client(),path='/reseller-api')[0],403)
  a=self.client();a.open(self.url+'/reseller-signin').read()
  before=(self.path/'executed').read_text() if (self.path/'executed').exists() else ''
  self.assertEqual(self.request(a,path='/reseller-api')[0],403)
  self.assertFalse(json.load(a.open(self.url+'/legacy-state')))
  self.assertEqual(self.request(a)[0],401)
  for operation in ['chat','confirm','cancel','reset']:
   self.assertEqual(self.request(a,{'operation':operation,'message':'create','token':'legacy-token'},'old-csrf',path='/reseller-api')[0],403)
   self.assertEqual(self.request(a,{'operation':operation,'message':'create','token':'legacy-token'},'old-csrf')[0],401)
  self.assertEqual((self.path/'executed').read_text() if (self.path/'executed').exists() else '',before)
 def test_widget_only_renders_for_admin_scope_and_role(self):
  source=r'''<?php
namespace XcVm\Domain\Assistant {final class AssistantClient {public static function enabled():bool{return true;}}}
namespace {
define('MAIN_HOME',ROOT);
$result=[];
foreach ([['admin',1],['reseller',0],['reseller',1],['admin',0]] as [$scope,$admin]) {
 $rUserInfo=['id'=>1];$rPermissions=['is_admin'=>$admin];ob_start();require VIEW;$result[]=str_contains(ob_get_clean(),'id="xc-assistant"');
}
echo json_encode($result);
}'''.replace('ROOT',json.dumps((ROOT/'panel/src').as_posix()+'/')).replace('VIEW',json.dumps((ROOT/'panel/src/Public/Views/layouts/assistant.php').as_posix()))
  file=self.path/'view-test.php';file.write_text(source,encoding='utf-8')
  self.assertEqual(json.loads(subprocess.check_output([shutil.which('php'),str(file)])),[True,False,False,False])
if __name__=='__main__':unittest.main()
