"""The node installer verifies both transport bytes and the unpacked agent."""
import gzip
import hashlib
import importlib.util
import io
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('native_install_node', pathlib.Path(__file__).with_name('trusted-node.py'))
node = importlib.util.module_from_spec(spec)
spec.loader.exec_module(node)


class NativeAgentInstall(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.work = pathlib.Path(self.temp.name)
        self.old_work = node.WORK
        node.WORK = self.work
        self.binary = b'\x7fELF-test-agent'
        self.compressed = gzip.compress(self.binary)
        self.manifest = dict(repository='mena50056/xcvm-independent', tag='native-controls-v1', asset='xc_agent-linux-amd64.gz',
                             sha256=hashlib.sha256(self.compressed).hexdigest(), binary_sha256=hashlib.sha256(self.binary).hexdigest())
        self.target = self.work / 'payload/panel/src/bin/xc_agent/xc_agent'
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(b'old-agent')

    def tearDown(self):
        node.WORK = self.old_work
        self.temp.cleanup()

    def install(self, data=None):
        (self.work / 'native-agent.json').write_text(json.dumps(self.manifest))
        with patch.object(node.urllib.request, 'urlopen', return_value=io.BytesIO(self.compressed if data is None else data)) as fetch:
            node.install_native_agent(self.work / 'payload')
            return fetch

    def test_verified_agent_replaces_the_bundle_agent(self):
        fetch = self.install()
        self.assertEqual(self.binary, self.target.read_bytes())
        self.assertEqual('https://github.com/mena50056/xcvm-independent/releases/download/native-controls-v1/xc_agent-linux-amd64.gz', fetch.call_args.args[0])

    def test_corrupt_download_is_refused_before_replacement(self):
        with self.assertRaisesRegex(ValueError, 'download checksum'):
            self.install(self.compressed + b'tampered')
        self.assertEqual(b'old-agent', self.target.read_bytes())

    def test_wrong_unpacked_binary_is_refused_before_replacement(self):
        self.manifest['binary_sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'binary checksum'):
            self.install()
        self.assertEqual(b'old-agent', self.target.read_bytes())

    def test_other_repository_is_refused(self):
        self.manifest['repository'] = 'someone/else'
        with self.assertRaisesRegex(ValueError, 'source'):
            self.install()

    def test_release_path_injection_is_refused(self):
        self.manifest['tag'] = '../other'
        with self.assertRaisesRegex(ValueError, 'tag'):
            self.install()


if __name__ == '__main__':
    unittest.main()
