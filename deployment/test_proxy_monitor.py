"""Actual PHP cURL -> nginx (four ports) -> verified TLS origin. Generated fixtures only."""
import copy,json,pathlib,shutil,socket,subprocess,unittest
import test_proxy_runtime as proxy_fixture
from setup_proxy_monitor import write_units

class Diagnostics(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        proxy_fixture.Runtime.setUpClass();cls.fixture=proxy_fixture.Runtime
    @classmethod
    def tearDownClass(cls):proxy_fixture.Runtime.tearDownClass()
    def probe(self,wire,ca=None):
        root=pathlib.Path(__file__).resolve().parents[1]
        code="require "+json.dumps(str(root/'panel/src/Core/Proxy/ProxyProfile.php'))+";require "+json.dumps(str(root/'panel/src/Core/Proxy/ProxyDiagnostics.php'))+";$d=json_decode(stream_get_contents(STDIN),true);echo json_encode(XcVm\\Core\\Proxy\\ProxyDiagnostics::run($d['wire'],2,$d['ca']));"
        p=subprocess.run(['php','-r',code],input=json.dumps({'wire':wire,'ca':ca if ca is not None else (self.fixture.root/'edge/tls/local-ca.crt').read_text()}),capture_output=True,text=True,check=True)
        return json.loads(p.stdout)
    def test_four_ports_and_certificate_verification(self):
        rows=self.probe(self.fixture.wire);self.assertEqual(len(rows),4);self.assertTrue(all(r['state']=='healthy' and r['origin_id']==1 for r in rows),rows)
        for row in rows:
            self.assertIsNotNone(row['tcp_ms'])
            if row['scheme']=='https':self.assertIsNotNone(row['certificate_expires_at']);self.assertIsNotNone(row['tls_ms'])
        untrusted=self.probe(self.fixture.wire,ca=self.fixture.ca.read_text());self.assertTrue(all(r['code']=='TLS_FAILED' for r in untrusted if r['scheme']=='https'))
    def test_one_closed_port_is_not_hidden_by_healthy_ones(self):
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        wire=copy.deepcopy(self.fixture.wire);wire['profile']['http_ports'].append(port);rows=self.probe(wire)
        self.assertEqual(sum(r['state']=='healthy' for r in rows),4);self.assertEqual(next(r['code'] for r in rows if r['port']==port),'CONNECT_FAILED')
    def test_generation_header_cannot_be_spoofed(self):
        instance=proxy_fixture.Runtime();status,body,_=instance.request('/.well-known/horus-proxy-origin?nonce='+('a'*32),headers={'X-Horus-Proxy-Revision':'forged'})
        self.assertEqual(status,200);self.assertEqual(json.loads(body)['revision'],self.fixture.wire['revision'])
    def test_verified_certificate_fallback_and_rejected_identity(self):
        root=pathlib.Path(__file__).resolve().parents[1]
        code="require "+json.dumps(str(root/'panel/src/Core/Proxy/ProxyProfile.php'))+";require "+json.dumps(str(root/'panel/src/Core/Proxy/ProxyDiagnostics.php'))+";$d=json_decode(stream_get_contents(STDIN),true);echo json_encode(XcVm\\Core\\Proxy\\ProxyDiagnostics::certificateExpiry($d['target'],$d['ca']));"
        def expiry(host,ca):
            target={'ip':'127.0.0.1','host':host,'port':self.fixture.https[0]}
            return json.loads(subprocess.check_output(['php','-r',code],input=json.dumps({'target':target,'ca':str(ca)}),text=True))
        ca=self.fixture.root/'edge/tls/local-ca.crt'
        self.assertIsInstance(expiry('127.0.0.1',ca),int)
        self.assertIsNone(expiry('wrong.horus.test',ca))
        self.assertIsNone(expiry('127.0.0.1',self.fixture.ca))
    def test_monitor_schedule_is_separate_and_unprivileged(self):
        service,timer=write_units('/opt/horus_xc',self.fixture.root/'units');text=service.read_text()
        self.assertIn('User=xcvm_lab',text);self.assertIn('cron:proxy_monitor',text);self.assertIn('Type=oneshot',text);self.assertNotIn('Restart=',text);self.assertIn('OnUnitInactiveSec=30',timer.read_text())

if __name__=='__main__':unittest.main()
