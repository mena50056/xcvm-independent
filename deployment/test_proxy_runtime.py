"""Root-only, isolated nginx integration. No panel database or real listeners are touched."""
import copy,hashlib,http.client,http.server,json,os,pathlib,shutil,socket,ssl,subprocess,tempfile,threading,time,unittest
from proxy_agent import ProxyApplier
from proxy_config import render,validate

class Runtime(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid()!=0:raise unittest.SkipTest('real nginx integration requires root')
        cls.nginx=os.environ.get('HORUS_TEST_NGINX') or shutil.which('nginx')
        if not cls.nginx:raise unittest.SkipTest('nginx is not installed')
        cls.root=pathlib.Path(tempfile.mkdtemp(prefix='horus-proxy-'));cls.root.chmod(0o755)
        cls.ca=cls.root/'upstream.crt';key=cls.root/'upstream.key'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=upstream.horus.test','-addext','subjectAltName=DNS:upstream.horus.test','-addext','basicConstraints=critical,CA:TRUE','-keyout',str(key),'-out',str(cls.ca)],check=True,capture_output=True)
        cls.servers=[];cls.threads=[]
        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version='HTTP/1.1'
            def log_message(self,*args):pass
            def do_GET(self):
                if self.path.startswith('/.well-known/horus-proxy-origin?'):
                    import urllib.parse
                    body=json.dumps({'protocol':'horus-origin-v1','origin_id':self.server.origin_id,'nonce':urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get('nonce',[''])[0],'revision':self.headers.get('X-Horus-Proxy-Revision','')}).encode()
                    self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body);return
                if self.path=='/slow':
                    self.send_response(200);self.send_header('Content-Length','30');self.end_headers()
                    for _ in range(30):self.wfile.write(b'x');self.wfile.flush();time.sleep(.1)
                    return
                if self.path.startswith('/redirect'):
                    self.send_response(302);self.send_header('Location','https://127.0.0.1:'+str(self.server.server_port)+'/auth/token');self.send_header('Content-Length','0');self.end_headers();return
                body=json.dumps({'path':self.path,'parent':self.server.server_port,'headers':dict(self.headers)}).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        for i in range(2):
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cls.ca,key);server.socket=context.wrap_socket(server.socket,server_side=True);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();cls.servers.append(server);cls.threads.append(thread)
            server.origin_id=i+1
        def port():
            with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
        cls.http=[port(),port()];cls.https=[port(),port()]
        limits={'connection_limit':100,'per_ip_connections':0,'requests_per_second':0,'request_burst':100,'worker_connections':512,'worker_processes':2,'connect_timeout':2,'read_timeout':60,'send_timeout':30,'keepalive_timeout':10,'header_timeout':10,'client_body_mb':3}
        cls.wire={'schema':1,'profile':dict(limits,server_ip='127.0.0.1',http_ports=cls.http,https_ports=cls.https,enabled=True,allow_ip_access=True,reject_unknown_hosts=True,allow_admin=False,http2=True,default_parent_id=1,hosts=[{'host':'alias.horus.test','parent_id':0,'certificate':'auto'},{'host':'vod.horus.test','parent_id':2,'certificate':'auto'}],trusted_proxies=[],outbound_bind_ip=''),
            'parents':[{'id':i+1,'ip':'127.0.0.1','port':s.server_port,'tls_name':'upstream.horus.test','route':str(i+1)*32} for i,s in enumerate(cls.servers)],'upstream_ca':cls.ca.read_text()}
        cls.revision(cls.wire);cls.applier=ProxyApplier(cls.root/'edge',cls.nginx,False);cls.applier.apply(cls.wire)
    @staticmethod
    def revision(wire):wire['revision']=hashlib.sha256(json.dumps({k:v for k,v in wire.items() if k!='revision'},sort_keys=True).encode()).hexdigest()
    def request(self,path='/',host='alias.horus.test',port=None,tls=False,headers=None):
        if tls:c=http.client.HTTPSConnection('127.0.0.1',port or self.https[0],context=ssl.create_default_context(cafile=str(self.root/'edge/tls/local-ca.crt')),timeout=10)
        else:c=http.client.HTTPConnection('127.0.0.1',port or self.http[0],timeout=10)
        c.request('GET',path,headers={'Host':host,**(headers or {})});r=c.getresponse();data=r.read();h=dict(r.getheaders());c.close();return r.status,data,h
    def test_ports_hosts_uri_headers_redirects(self):
        for port in self.http:
            status,body,_=self.request('/a%2Fb?x=%2F%26',port=port,headers={'X-Forwarded-For':'8.8.8.8','X-Real-IP':'9.9.9.9','X-IP':'1.1.1.1'});data=json.loads(body);self.assertEqual(status,200);self.assertEqual(data['path'],'/a%2Fb?x=%2F%26');self.assertEqual(data['parent'],self.servers[0].server_port);self.assertEqual(data['headers']['X-Forwarded-For'],'127.0.0.1');self.assertEqual(data['headers']['X-IP'],'127.0.0.1')
        for port in self.https:self.assertEqual(self.request(tls=True,port=port)[0],200)
        self.assertEqual(json.loads(self.request(host='vod.horus.test')[1])['parent'],self.servers[1].server_port)
        for i in [0,1]:
            data=json.loads(self.request('/'+str(i+1)*32+'/live/u%2Fname/p%26word/12.ts?a=%2F')[1]);self.assertEqual(data['parent'],self.servers[i].server_port);self.assertEqual(data['path'],'/live/u%2Fname/p%26word/12.ts?a=%2F')
        self.assertEqual(self.request('/redirect',host='alias.horus.test:8080')[2]['Location'],'http://alias.horus.test:8080/auth/token')
        for path in ['/horus_xc/login','/'+('1'*32)+'/horus_xc/login','/%68orus_xc/login','/horus-proxy/v1/sync']:
            self.assertEqual(self.request(path)[0],404)
        with self.assertRaises((http.client.RemoteDisconnected,ConnectionResetError)):self.request(host='unknown.test')
    def test_rollback_and_graceful_reload(self):
        old=self.applier.state();c=http.client.HTTPConnection('127.0.0.1',self.http[0],timeout=10);c.request('GET','/slow',headers={'Host':'alias.horus.test'});stream=c.getresponse();self.assertEqual(stream.read(1),b'x')
        changed=copy.deepcopy(self.wire);changed['profile']['read_timeout']=75;self.revision(changed);self.applier.apply(changed);self.assertEqual(stream.read(),b'x'*29);c.close()
        good=self.applier.state();occupied=socket.socket();occupied.bind(('0.0.0.0',0));occupied.listen();bad=copy.deepcopy(changed);bad['profile']['http_ports'].append(occupied.getsockname()[1]);self.revision(bad)
        with self.assertRaises(RuntimeError):self.applier.apply(bad)
        occupied.close();self.assertEqual(self.applier.state()['applied_revision'],good['applied_revision']);self.assertEqual(self.applier.health(),good['effective_revision']);self.assertEqual(self.request()[0],200)
        invalid=copy.deepcopy(changed);invalid['profile']['hosts'][0]['host']='bad; return 200';self.revision(invalid)
        with self.assertRaises(ValueError):self.applier.apply(invalid)
        invalid=copy.deepcopy(changed);invalid['profile']['outbound_bind_ip']='192.0.2.254';self.revision(invalid)
        with self.assertRaisesRegex(ValueError,'OUTBOUND_BIND_IP_NOT_LOCAL'):self.applier.apply(invalid)
        self.assertEqual(self.request()[0],200);self.applier.apply(self.wire)
    def test_tls_failure_is_closed(self):
        wire=copy.deepcopy(self.wire);wire['parents'][0]['tls_name']='wrong.horus.test';self.revision(wire);self.applier.apply(wire)
        self.assertEqual(self.request()[0],502);self.applier.apply(self.wire);self.assertEqual(self.request()[0],200)
    def test_custom_certificate_hostname_is_verified(self):
        shutil.copy2(self.ca,self.root/'edge/tls/custom.crt');shutil.copy2(self.root/'upstream.key',self.root/'edge/tls/custom.key')
        wire=copy.deepcopy(self.wire);wire['profile']['hosts'][0]['certificate']='custom';self.revision(wire)
        with self.assertRaisesRegex(RuntimeError,'hostname mismatch'):self.applier.apply(wire)
        wire['profile']['hosts'][0]['host']='upstream.horus.test';self.revision(wire);self.applier.apply(wire)
        context=ssl.create_default_context(cafile=str(self.ca))
        with socket.create_connection(('127.0.0.1',self.https[0]),timeout=5) as raw:
            with context.wrap_socket(raw,server_hostname='upstream.horus.test') as connection:
                connection.sendall(b'GET / HTTP/1.1\r\nHost: upstream.horus.test\r\nConnection: close\r\n\r\n');self.assertIn(b'200 OK',connection.recv(4096))
        self.applier.apply(self.wire)
    def test_ip_certificate_without_sni_and_local_acme(self):
        cert=self.root/'edge/tls/public-ip.crt';key=self.root/'edge/tls/public-ip.key'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1','-keyout',str(key),'-out',str(cert)],check=True,capture_output=True)
        wire=copy.deepcopy(self.wire);wire['profile']['hosts'].append({'host':'127.0.0.1','parent_id':0,'certificate':'public-ip'});self.revision(wire);self.applier.apply(wire)
        connection=http.client.HTTPSConnection('127.0.0.1',self.https[0],context=ssl.create_default_context(cafile=str(cert)),timeout=5);connection.request('GET','/');response=connection.getresponse();self.assertEqual(response.status,200);response.read();connection.close()
        directory=self.root/'edge/acme/www/.well-known/acme-challenge';directory.mkdir(parents=True);(directory/'probe').write_text('local-challenge-proof')
        self.assertEqual(self.request('/.well-known/acme-challenge/probe',host='127.0.0.1')[1],b'local-challenge-proof')
        self.assertEqual(self.request('/.well-known/acme-challenge/missing',host='127.0.0.1')[0],404)
        self.applier.apply(self.wire)
    @classmethod
    def tearDownClass(cls):
        subprocess.run([cls.nginx,'-c',str(cls.root/'edge/nginx.conf'),'-s','quit'],capture_output=True)
        for server in cls.servers:server.shutdown();server.server_close()
        time.sleep(.3);shutil.rmtree(cls.root)

if __name__=='__main__':unittest.main()
