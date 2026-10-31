import pathlib,tempfile,unittest,sys
from ensure_horus_home import ensure_home
@unittest.skipUnless(sys.platform.startswith('linux'),'Linux home aliases')
class HorusHomeTests(unittest.TestCase):
 def fixture(self,d):
  root=pathlib.Path(d)/'opt/horus_xc';target=root/'panel/src';target.mkdir(parents=True);(target/'identity').write_bytes(b'identity');home=pathlib.Path(d)/'home/horus_xc';home.parent.mkdir();legacy=home.with_name('xc_vm');return root,target,home,legacy
 def test_existing_legacy_callers_and_identity_preserved(self):
  with tempfile.TemporaryDirectory() as d:
   root,target,home,legacy=self.fixture(d);legacy.symlink_to(target);ensure_home(root,home,legacy);self.assertEqual(str(home),__import__('os').readlink(legacy));self.assertEqual(target,home.resolve());self.assertEqual(b'identity',(legacy/'identity').read_bytes());ensure_home(root,home,legacy);self.assertEqual(b'identity',(home/'identity').read_bytes())
 def test_existing_home_via_legacy_is_canonicalized_without_a_loop(self):
  with tempfile.TemporaryDirectory() as d:
   root,target,home,legacy=self.fixture(d);legacy.symlink_to(target);home.symlink_to(legacy);ensure_home(root,home,legacy);self.assertEqual(str(target),__import__('os').readlink(home));self.assertEqual(str(home),__import__('os').readlink(legacy));self.assertEqual(b'identity',(legacy/'identity').read_bytes())
 def test_occupied_canonical_home_refused_without_legacy_change(self):
  with tempfile.TemporaryDirectory() as d:
   root,target,home,legacy=self.fixture(d);legacy.symlink_to(target);home.mkdir();(home/'keep').write_bytes(b'keep')
   with self.assertRaises(ValueError):ensure_home(root,home,legacy)
   self.assertEqual(b'keep',(home/'keep').read_bytes());self.assertEqual(str(target),__import__('os').readlink(legacy))
if __name__=='__main__':unittest.main()
