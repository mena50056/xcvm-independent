"""Regression: replay zero-argument Ollama tool calls as {}, not a JSON array."""
import http.server,json,pathlib,subprocess,tempfile,threading,unittest,shutil
ROOT=pathlib.Path(__file__).resolve().parents[1]
class ClientProtocol(unittest.TestCase):
 def test_tool_round_trip_and_transport_failure(self):
  requests=[];fail=False;empty=False;diagnose=False
  class Handler(http.server.BaseHTTPRequestHandler):
   def log_message(self,*args):pass
   def do_POST(self):
    body=json.loads(self.rfile.read(int(self.headers['Content-Length'])));requests.append(body)
    if empty:self.send_response(200);response={'done':True,'message':{'role':'assistant','content':''}}
    elif fail:self.send_response(503);response={'error':'unavailable'}
    else:
     self.send_response(200)
     response={'done':True,'message':{'role':'assistant','content':'' if len(requests)==1 else 'READY'}}
     if diagnose:response['message']['tool_calls']=[{'function':{'name':'diagnose_playback','arguments':{}}}]
     elif len(requests)==1:response['message']['tool_calls']=[{'function':{'name':'lookup','arguments':{}}}]
    self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps(response).encode())
  server=http.server.HTTPServer(('127.0.0.1',11435),Handler)
  worker=threading.Thread(target=server.serve_forever,daemon=True);worker.start()
  try:
   with tempfile.TemporaryDirectory() as directory:
    path=pathlib.Path(directory);(path/'config').mkdir();(path/'config/assistant.json').write_text(json.dumps({'enabled':True,'url':'http://127.0.0.1:11435/api/chat'}))
    source='''<?php
namespace XcVm\\Domain\\Assistant {
final class PanelTools {
public function definitions():array{return [['type'=>'function','function'=>['name'=>'lookup','parameters'=>['type'=>'object','properties'=>new \\stdClass()]]]];}
public function call(string $name,array $args):array{return $name==='diagnose_playback'?['report_ar'=>'AR','report_en'=>'EXACT_DIAGNOSIS']:['value'=>42];}
}
}
namespace {
define('MAIN_HOME',CONFIG);
require SOURCE;
try {echo json_encode((new \\XcVm\\Domain\\Assistant\\AssistantClient())->chat([['role'=>'user','content'=>'Look up the value']],new \\XcVm\\Domain\\Assistant\\PanelTools()));}
catch(\\RuntimeException $e){echo json_encode(['error'=>$e->getMessage()]);}
}
'''.replace('CONFIG',json.dumps(path.as_posix()+'/')).replace('SOURCE',json.dumps((ROOT/'panel/src/Domain/Assistant/AssistantClient.php').as_posix()))
    (path/'test.php').write_text(source)
    def run():return json.loads(subprocess.check_output([shutil.which('php'),str(path/'test.php')],stderr=subprocess.DEVNULL))
    self.assertEqual(run()['message'],'READY');self.assertEqual(len(requests),2)
    replay=requests[1]['messages'][-2]['tool_calls'][0]['function']['arguments']
    self.assertIsInstance(replay,dict);self.assertEqual(requests[1]['messages'][-1]['role'],'tool')
    self.assertFalse(requests[0]['think']);self.assertFalse(requests[0]['stream'])
    diagnose=True;before=len(requests);self.assertEqual(run()['message'],'EXACT_DIAGNOSIS');self.assertEqual(len(requests)-before,1);diagnose=False
    fail=True;self.assertEqual(run()['error'],'AI_UNAVAILABLE')
    fail=False;empty=True;before=len(requests);self.assertEqual(run()['error'],'AI_UNAVAILABLE');self.assertEqual(len(requests)-before,3)
    empty=False
    media_source=source.replace("'name'=>'lookup'", "'name'=>'media_options'").replace("return [['type'=>'function'", "return [['function'=>['name'=>'unrelated']],['type'=>'function'").replace('Look up the value','Add a movie')
    (path/'test.php').write_text(media_source,encoding='utf-8');self.assertEqual(run()['message'],'READY')
    self.assertEqual([x['function']['name'] for x in requests[-1]['tools']],['media_options'])
    self.assertIn('admin media assistant',requests[-1]['messages'][0]['content'])
    (path/'test.php').write_text(media_source.replace('Add a movie','Show subscriptions'),encoding='utf-8');self.assertEqual(run()['message'],'READY')
    self.assertEqual(len(requests[-1]['tools']),2)
  finally:server.shutdown();server.server_close();worker.join()
if __name__=='__main__':unittest.main()
