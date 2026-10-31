import pathlib,tempfile,unittest,contextlib,json,hashlib,tarfile,io
from unittest.mock import patch
import update_worker as common
import node_update as node
from test_node_updates import NodeUpdateTests
class VendorIsolationTests(unittest.TestCase):
 def test_hosts_preserved_idempotent_and_symlink_refused(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);hosts=root/'hosts';hosts.write_text('127.0.0.1 localhost\n10.0.0.2 own-node\n');before=hosts.read_bytes()
   with patch.object(common,'VENDOR_HOSTS',hosts),patch.object(common,'STATE',root/'state'):
    self.assertTrue(common.isolate_vendor());after=hosts.read_bytes();self.assertIn(before,after);self.assertIn(b'api.xcvm.tech',after);self.assertFalse(common.isolate_vendor());self.assertEqual(after,hosts.read_bytes());self.assertEqual(before,(root/'state/hosts.before-vendor-isolation').read_bytes())
    real=root/'real';hosts.rename(real)
    try:hosts.symlink_to(real)
    except OSError:
     if __import__('sys').platform=='win32':return
     raise
    with self.assertRaises(ValueError):common.isolate_vendor()
 def test_missing_or_false_policy_rejected(self):
  for value in [False,None,'true']:
   data=NodeUpdateTests().manifest({n:b'\x7fELF' for n in node.FILES});data.update(schema=2,kind='native-with-vendor-isolation',vendor_isolation=value)
   with self.assertRaises(ValueError):node.validate(data)
 def exercise_policy(self,fail_marker=False):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);install=root/'install';state=root/'state';state.mkdir();hosts=root/'hosts';hosts.write_text('127.0.0.1 localhost\n');before=hosts.read_bytes();content=b'\x7fELFunchanged'
   for n in node.FILES:
    p=install/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
   identity=install/'panel/src/config/cluster/agent.json';identity.parent.mkdir(parents=True);identity.write_bytes(b'identity stays')
   mem=io.BytesIO()
   with tarfile.open(fileobj=mem,mode='w:gz') as t:
    for n in node.FILES:
     m=tarfile.TarInfo(n);m.size=len(content);t.addfile(m,io.BytesIO(content))
   payload=mem.getvalue();data=NodeUpdateTests().manifest({n:content for n in node.FILES});data.update(schema=2,kind='native-with-vendor-isolation',vendor_isolation=True,version='v0.1.0-lab.8',from_versions=['v0.1.0-lab.7'],sha256=hashlib.sha256(payload).hexdigest());item={'version':data['version'],'assets':{node.POLICY_MANIFEST:'manifest',node.POLICY_PAYLOAD:'payload'}}
   original=common.atomic
   def atomic(path,contents,mode=0o644):
    if fail_marker and pathlib.Path(path).name=='node-version.json':raise RuntimeError('marker failed')
    return original(path,contents,mode)
   with patch.object(common,'ROOT',install),patch.object(common,'STATE',state),patch.object(common,'VENDOR_HOSTS',hosts),patch.object(common,'get',side_effect=lambda u,*a,**k:json.dumps(data).encode() if u=='manifest' else payload),patch.object(common,'panel_user',contextlib.nullcontext),patch.object(common,'atomic',side_effect=atomic),patch.object(node.subprocess,'run') as command,patch.object(node,'health'):
    if fail_marker:
     with self.assertRaisesRegex(RuntimeError,'marker failed'):node.apply(item,'v0.1.0-lab.7')
     self.assertEqual(before,hosts.read_bytes());self.assertFalse((state/'node-version.json').exists())
    else:
     self.assertEqual('v0.1.0-lab.8',node.apply(item,'v0.1.0-lab.7'));self.assertIn(b'www.xcvm.tech',hosts.read_bytes())
    command.assert_not_called();self.assertEqual(b'identity stays',identity.read_bytes())
 def test_policy_update_keeps_binaries_identity_and_processes(self):self.exercise_policy()
 def test_policy_failure_rolls_back_hosts_without_service_restart(self):self.exercise_policy(True)
if __name__=='__main__':unittest.main()
