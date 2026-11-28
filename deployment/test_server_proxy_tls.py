"""Use a real loopback TLS origin; unknown and wrong-name certificates fail closed."""
import http.server, json, pathlib, ssl, subprocess, tempfile, threading, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class TLS(unittest.TestCase):
 def test_upstream_trust(self):
  with tempfile.TemporaryDirectory() as temp:
   directory=pathlib.Path(temp);cert=directory/'origin.crt';key=directory/'origin.key'
   subprocess.run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   class Handler(http.server.BaseHTTPRequestHandler):
    def do_HEAD(self):self.send_response(200);self.end_headers()
    def log_message(self,*args):pass
   server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);server.socket=context.wrap_socket(server.socket,server_side=True);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
   try:
    source=r'''<?php
require CLASS_PATH;
use XcVm\Core\Proxy\ProxyUpstreamTls;
$parent=['id'=>8,'server_ip'=>'127.0.0.1','enabled'=>1,'https_broadcast_port'=>PORT,'is_main'=>0];$directory=TRUST_DIR;
try{ProxyUpstreamTls::prepare($parent,[],$directory);throw new RuntimeException('Unknown certificate accepted');}catch(RuntimeException $e){if($e->getMessage()!=='UPSTREAM_TLS_UNTRUSTED: 8')throw $e;}
ProxyUpstreamTls::remember($directory,8,file_get_contents(CERT_PATH));$profile=ProxyUpstreamTls::prepare($parent,[],$directory);if(empty($profile['upstream_cas'][8]))throw new RuntimeException('Trusted certificate not retained');
try{ProxyUpstreamTls::prepare($parent,['upstream_tls_names'=>[8=>'wrong.example.test']],$directory);throw new RuntimeException('Wrong name accepted');}catch(RuntimeException $e){if($e->getMessage()!=='UPSTREAM_TLS_UNTRUSTED: 8')throw $e;}
echo "Verified TLS accepts pinned origin and rejects unknown CA and wrong hostname\n";
'''
    for name,value in {'CLASS_PATH':str(ROOT/'panel/src/Core/Proxy/ProxyUpstreamTls.php'),'PORT':server.server_port,'TRUST_DIR':str(directory/'trust'),'CERT_PATH':str(cert)}.items():source=source.replace(name,json.dumps(value))
    script=directory/'check.php';script.write_text(source);subprocess.run(['php',str(script)],check=True)
   finally:server.shutdown();server.server_close();thread.join(timeout=5)
if __name__=='__main__':unittest.main()
