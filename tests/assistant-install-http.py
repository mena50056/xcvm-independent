"""Real controller and installation state over HTTP; no remote server is contacted."""
import importlib.util,pathlib,json,subprocess,tempfile,shutil,socket,time,urllib.request,unittest,os
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('assistant_http_base',ROOT/'tests/assistant-http.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
class InstallationHTTP(unittest.TestCase):
 client=base.AssistantHTTP.client
 request=base.AssistantHTTP.request
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.path=pathlib.Path(cls.tmp.name)
  deps='\n'.join('require '+json.dumps((ROOT/'panel/src'/name).as_posix())+';' for name in ['Core/Auth/Authorization.php','Domain/Server/InstallCredentials.php','Domain/Assistant/InstallationStore.php','Domain/Assistant/InstallationWizard.php'])
  setup=deps+r'''
 define('XCVM_INDEPENDENT_LAB',true);define('CONFIG_PATH',__DIR__.'/');
 $pdo=new PDO('sqlite:'.__DIR__.'/database.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $pdo->exec('CREATE TABLE IF NOT EXISTS servers(id INTEGER PRIMARY KEY,server_name TEXT,server_ip TEXT,private_ip TEXT,server_type INTEGER,enabled INTEGER,https_broadcast_port INTEGER)');
 $pdo->exec("INSERT OR IGNORE INTO servers VALUES(1,'MAIN','8.8.8.8','10.0.0.1',0,1,443)");$db=(object)['dbh'=>$pdo];
 '''
  source=base.HARNESS.replace('session_start();',setup+'\nsession_start();',1)
  source=source.replace("file_put_contents(__DIR__.'/seen_messages',json_encode($messages));", "file_put_contents(__DIR__.'/seen_messages',json_encode($messages)); if(end($messages)['content']==='install')return ['message'=>'','proposal'=>null,'begin_installation'=>true];")
  source=source.replace('RESELLER_CONTROLLER',json.dumps((ROOT/'panel/src/Public/Controllers/Reseller/AssistantController.php').as_posix())).replace('CONTROLLER',json.dumps((ROOT/'panel/src/Public/Controllers/Admin/AssistantController.php').as_posix()))
  (cls.path/'router.php').write_text(source,encoding='utf-8')
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  cls.url=f'http://127.0.0.1:{port}'
  cmd=[shutil.which('php')]+(['-d','extension=sodium'] if os.name=='nt' else [])+['-S',f'127.0.0.1:{port}',str(cls.path/'router.php')]
  cls.process=subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(50):
   try:urllib.request.urlopen(cls.url+'/signin',timeout=1);break
   except OSError:time.sleep(.1)
 @classmethod
 def tearDownClass(cls):cls.process.terminate();cls.process.wait();cls.tmp.cleanup()
 def test_secret_isolation_confirmation_and_resume(self):
  a=self.client();metadata=self.request(a)[1];self.assertTrue(metadata['installation_enabled']);csrf=metadata['csrf']
  self.assertEqual(self.request(a,{'operation':'install_begin'},'invalid')[0],403)
  status,result=self.request(a,{'operation':'chat','message':'install'},csrf);self.assertEqual(status,200)
  job=result['installation'];self.assertEqual(job['field'],'kind')
  values={'kind':'proxy','name':'Wizard test','ip':'9.9.9.9','ssh_port':'22','fingerprint':'a'*40,'parent_id':'1','tls_mode':'later','capacity':'50','fresh':'yes','password':'NEVER_SEND_TO_MODEL'}
  while job['state']=='draft':
   if job['field']=='password':
    status,ignored=self.request(a,{'operation':'chat','message':'NEVER_SEND_TO_MODEL'},csrf);self.assertEqual(status,200);self.assertEqual(ignored['installation']['field'],'password')
   status,result=self.request(a,{'operation':'install_step','job_id':job['id'],'token':job['token'],'value':values[job['field']]},csrf)
   self.assertEqual(status,200,result);job=result['installation'];self.assertNotIn('NEVER_SEND_TO_MODEL',json.dumps(result))
  self.assertEqual(job['state'],'review');self.assertNotIn('NEVER_SEND_TO_MODEL',(self.path/'seen_messages').read_text())
  resume=self.request(a)[1]['installation'];self.assertEqual(resume['id'],job['id']);self.assertEqual(resume['state'],'review')
  confirmed=self.request(a,{'operation':'install_confirm','job_id':job['id'],'token':job['token']},csrf)
  self.assertEqual(confirmed[0],200);self.assertEqual(confirmed[1]['installation']['state'],'queued')
  self.assertEqual(self.request(a,{'operation':'install_confirm','job_id':job['id'],'token':job['token']},csrf)[0],409)
  queued=confirmed[1]['installation'];self.assertEqual(self.request(a,{'operation':'install_cancel','job_id':job['id'],'token':queued['token']},csrf)[0],200)
  saved=json.loads((self.path/'assistant-install'/f"{job['id']}.json").read_text());self.assertNotIn('secret',saved)
 def test_reseller_denied(self):
  op=self.client();op.open(self.url+'/reseller-signin').read();self.assertEqual(self.request(op)[0],401)
if __name__=='__main__':unittest.main()
