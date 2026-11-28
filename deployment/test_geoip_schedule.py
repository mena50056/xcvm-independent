import pathlib
import tempfile
import unittest
from setup_geoip import write_units


class GeoipScheduleTest(unittest.TestCase):
    def test_limited_worker_and_weekly_timer(self):
        with tempfile.TemporaryDirectory() as directory:
            service, timer = write_units('/opt/horus_xc', directory)
            unit = service.read_text()
            self.assertIn('User=xcvm_lab', unit)
            self.assertIn('/runtime/geoip-update.php', unit)
            self.assertNotIn('cron:maxmind', unit)
            self.assertNotIn('License', unit)
            self.assertIn('Nice=10', unit)
            self.assertIn('OnCalendar=Tue ', timer.read_text())
            self.assertIn('Persistent=true', timer.read_text())

    def test_unexpected_path_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                write_units('/opt/horus\nExecStart=/other', directory)


if __name__ == '__main__':
    unittest.main()
