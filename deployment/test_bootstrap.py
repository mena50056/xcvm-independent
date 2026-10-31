"""Execute the real bootstrap's preflight with isolated paths and command stubs."""
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import unittest


@unittest.skipUnless(sys.platform.startswith('linux'), 'Linux shell preflight')
class BootstrapPreflight(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='xcvm-bootstrap-check-')
        self.root = pathlib.Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        code = pathlib.Path(__file__).with_name('bootstrap-main-ip.sh').read_text()
        markers = sorted(set(re.findall(r'/(?:home|opt|var/lib/mysql|etc/systemd/system|etc/init.d)/[A-Za-z0-9_./-]+', code)), key=len, reverse=True)
        self.paths = {name: self.root / 'markers' / str(index) for index, name in enumerate(markers)}
        for name, target in self.paths.items():
            code = code.replace(name, str(target))
        release = self.root / 'os-release'
        release.write_text('ID=ubuntu\nVERSION_ID="22.04"\n')
        code = code.replace('/etc/os-release', str(release))
        self.script = self.root / 'bootstrap.sh'
        self.script.write_text(code)
        self.env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'], FIXTURE_IP='8.8.4.4', FIXTURE_BUSY='')
        self.stub('id', '#!/bin/sh\nif [ "$1" = -u ]; then echo 0; exit 0; fi\nexit 1\n')
        self.stub('uname', '#!/bin/sh\necho x86_64\n')
        self.stub('ss', '#!/bin/sh\n[ -z "$FIXTURE_BUSY" ] || echo "LISTEN 0 128 0.0.0.0:443"\nexit 0\n')
        self.stub('cloud-init', '#!/bin/sh\nexit 0\n')
        self.stub('ip', '''#!/usr/bin/env python3
import os,sys,json
ip=os.environ['FIXTURE_IP']
if '-j' in sys.argv:
 print(json.dumps([{'prefsrc':ip}] if 'route' in sys.argv else [{'addr_info':[{'family':'inet','scope':'global','local':ip}]}]))
else:print('2: eth0 inet '+ip+'/24 scope global eth0')
''')
        for name in ['apt-get', 'git']:
            self.stub(name, '#!/bin/sh\ntouch "' + str(self.root / 'mutation') + '"\nexit 99\n')

    def stub(self, name, code):
        path = self.bin / name
        path.write_text(code)
        path.chmod(0o755)

    def tearDown(self):
        self.temp.cleanup()

    def run_bootstrap(self, *args):
        result = subprocess.run(['bash', str(self.script), '--check-only', *args], env=self.env, capture_output=True, text=True, timeout=15)
        self.assertFalse((self.root / 'mutation').exists(), 'preflight reached a mutating command')
        return result.returncode, result.stdout + result.stderr

    def test_new_host_detects_assigned_public_ip_without_prompting(self):
        code, output = self.run_bootstrap()
        self.assertEqual(0, code, output)
        self.assertIn('Selected MAIN IPv4: 8.8.4.4', output)

    def test_existing_panel_paths_databases_and_services_are_refused(self):
        for name in ['/home/xc_vm', '/opt/horus_xc', '/opt/xcvm-independent-lab', '/home/xui', '/var/lib/mysql/xcvm_lab_main', '/etc/systemd/system/xcvm-independent-agent.service']:
            with self.subTest(name=name):
                marker = self.paths[name]
                marker.parent.mkdir(parents=True, exist_ok=True)
                marker.touch()
                code, output = self.run_bootstrap()
                self.assertEqual(1, code, output)
                self.assertIn('Existing panel', output)
                marker.unlink()

    def test_broken_panel_symlink_is_refused(self):
        marker = self.paths['/home/xc_vm']
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.symlink_to(self.root / 'missing-target')
        self.assertIn('Existing panel', self.run_bootstrap()[1])

    def test_busy_web_port_is_refused(self):
        self.env['FIXTURE_BUSY'] = 'yes'
        code, output = self.run_bootstrap()
        self.assertEqual(1, code, output)
        self.assertIn('port is occupied', output)

    def test_auto_detection_refuses_private_only_host(self):
        self.env['FIXTURE_IP'] = '10.0.0.5'
        code, output = self.run_bootstrap()
        self.assertEqual(1, code, output)
        self.assertIn('Could not determine', output)

    def test_explicit_ip_must_be_assigned(self):
        code, output = self.run_bootstrap('--server-ip=8.8.8.8')
        self.assertEqual(1, code, output)
        self.assertIn('not assigned', output)

    def test_former_host_auto_detects_and_still_refuses_existing_panel(self):
        self.env['FIXTURE_IP'] = '45.32.223.254'
        code, output = self.run_bootstrap()
        self.assertEqual(0, code, output)
        self.assertIn('Selected MAIN IPv4: 45.32.223.254', output)
        marker = self.paths['/home/xc_vm']
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.touch()
        code, output = self.run_bootstrap()
        self.assertEqual(1, code, output)
        self.assertIn('Existing panel', output)


if __name__ == '__main__':
    unittest.main()
