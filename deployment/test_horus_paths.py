import pathlib,tempfile,unittest,sys
from migrate_horus import exchange_root
@unittest.skipUnless(sys.platform.startswith('linux'),'Linux atomic directory exchange')
class HorusPaths(unittest.TestCase):
 def test_atomic_swap_keeps_open_file_and_legacy_path(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);old=root/'old';new=root/'horus_xc';old.mkdir();(old/'identity').write_text('unchanged')
   with (old/'identity').open() as live:
    exchange_root(old,new);self.assertTrue(old.is_symlink());self.assertFalse(new.is_symlink());self.assertEqual(old.resolve(),new);self.assertEqual('unchanged',live.read());self.assertEqual('unchanged',(old/'identity').read_text())
 def test_occupied_target_refused_before_changes(self):
  with tempfile.TemporaryDirectory() as d:
   old=pathlib.Path(d)/'old';new=pathlib.Path(d)/'new';old.mkdir();new.mkdir()
   with self.assertRaises(ValueError):exchange_root(old,new)
   self.assertTrue(old.is_dir());self.assertFalse(old.is_symlink())
if __name__=='__main__':unittest.main()
