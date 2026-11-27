"""Restart receipt tests; no services or listeners are touched."""
import json,pathlib,tempfile,time,unittest,sys,types
if sys.platform=='win32':
    sys.modules['fcntl']=types.SimpleNamespace(flock=lambda *a:None,LOCK_EX=2,LOCK_UN=8)
from proxy_agent import ProxyApplier,atomic
class Restart(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.applier=ProxyApplier(self.tmp.name);self.calls=[]
        self.applier.command=lambda args:self.calls.append(args)
        self.applier.health=lambda:'effective'
        atomic(self.applier.root/'state.json',json.dumps(dict(applied_revision='a'*64,effective_revision='effective',wire={'profile':{'enabled':True}})))
        self.op=dict(id='b'*32,type='restart',revision='a'*64,generation=1,expires_at=int(time.time())+120)
    def tearDown(self):self.tmp.cleanup()
    def test_success_and_replay(self):
        result=self.applier.restart(self.op,1);self.assertEqual(result['state'],'succeeded');self.assertEqual(self.calls[-1],['systemctl','restart','horus-proxy-nginx.service'])
        self.assertEqual(self.applier.restart(self.op,1),result);self.assertEqual(len(self.calls),2)
    def test_expired_and_wrong_revision(self):
        self.op['expires_at']=int(time.time())-1;self.assertEqual(self.applier.restart(self.op,1)['code'],'EXPIRED');self.assertFalse(self.calls)
        self.op.update(id='c'*32,expires_at=int(time.time())+120,revision='wrong');self.assertEqual(self.applier.restart(self.op,1)['code'],'STALE_CONFIGURATION');self.assertFalse(self.calls)
    def test_claim_survives_crash(self):
        atomic(self.applier.root/('operation-'+self.op['id']+'.json'),json.dumps(dict(id=self.op['id'],state='claimed')))
        self.assertEqual(self.applier.restart(self.op,1)['state'],'unknown');self.assertFalse(self.calls)
    def test_failed_syntax_never_restarts(self):
        def fail(args):self.calls.append(args);raise RuntimeError('secret command error')
        self.applier.command=fail;result=self.applier.restart(self.op,1);self.assertEqual(result['code'],'RESTART_FAILED');self.assertEqual(len(self.calls),1);self.assertNotIn('secret',json.dumps(result))
        self.applier.restart(self.op,1);self.assertEqual(len(self.calls),1)
    def test_no_arbitrary_operations(self):
        self.op['type']='shell'
        with self.assertRaises(ValueError):self.applier.restart(self.op,1)
        self.assertFalse(self.calls)
if __name__=='__main__':unittest.main()
