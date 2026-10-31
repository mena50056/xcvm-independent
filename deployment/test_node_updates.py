import contextlib
import hashlib
import io
import json
import pathlib
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import node_update as node
import update_worker as common


class NodeUpdateTests(unittest.TestCase):
    def manifest(self, files):
        return {'schema': 1, 'role': 'node', 'repository': common.REPOSITORY, 'version': 'v0.1.0-lab.7',
                'from_versions': ['v0.1.0-lab.4'], 'kind': 'native-only', 'database_migrations': [],
                'files': {name: hashlib.sha256(content).hexdigest() for name, content in files.items()}, 'sha256': '0' * 64}

    def test_manifest_excludes_identity_config_php_and_wrong_roles(self):
        files = {name: b'\x7fELFtest' for name in node.FILES}
        for field, value in [('role', 'main'), ('repository', 'other/repo'), ('database_migrations', ['SQL']),
                             ('files', {'panel/src/config/cluster/agent.json': '0' * 64})]:
            manifest = self.manifest(files)
            manifest[field] = value
            with self.assertRaises(ValueError):
                node.validate(manifest)

    def test_same_or_older_node_release_is_refused_before_download(self):
        for version in ['v0.1.0-lab.4', 'v0.1.0-lab.3']:
            with patch.object(common, 'get', side_effect=AssertionError('Must not download')):
                with self.assertRaisesRegex(ValueError, 'newer'):
                    node.apply({'version': version}, 'v0.1.0-lab.4')

    def exercise(self, failure=False, tamper=False, unchanged=False):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            install, state = root / 'install', root / 'state'
            state.mkdir()
            before = b'\x7fELFold'
            files = {name: before if unchanged else b'\x7fELFnew' for name in node.FILES}
            for name in files:
                target = install / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(before)
            identity = install / 'panel/src/config/cluster/agent.json'
            identity.parent.mkdir(parents=True)
            identity.write_bytes(b'private sealed identity')
            payload = root / 'payload.tar.gz'
            with tarfile.open(payload, 'w:gz') as archive:
                for name, content in files.items():
                    member = tarfile.TarInfo(name)
                    member.size = len(content)
                    archive.addfile(member, io.BytesIO(content))
            manifest = self.manifest(files)
            manifest['sha256'] = hashlib.sha256(payload.read_bytes()).hexdigest() if not tamper else '0' * 64
            item = {'version': manifest['version'], 'assets': {node.MANIFEST: 'manifest', node.PAYLOAD: 'payload'}}
            def get(url, *args, **kwargs):
                if url == 'manifest':
                    self.assertTrue(kwargs.get('binary'))
                    return json.dumps(manifest).encode()
                return payload.read_bytes()
            with patch.object(common, 'ROOT', install), patch.object(common, 'STATE', state), \
                 patch.object(common, 'get', side_effect=get), patch.object(common, 'panel_user', contextlib.nullcontext), \
                 patch.object(node.subprocess, 'run') as command, patch.object(node.time, 'sleep'), \
                 patch.object(node, 'health', side_effect=RuntimeError('failed node health') if failure else None):
                if failure or tamper:
                    with self.assertRaises((ValueError, RuntimeError)):
                        node.apply(item, 'v0.1.0-lab.4')
                    self.assertFalse((state / 'node-version.json').exists())
                    for name in files:
                        self.assertEqual(before, (install / name).read_bytes())
                else:
                    self.assertEqual('v0.1.0-lab.7', node.apply(item, 'v0.1.0-lab.4'))
                    self.assertEqual('v0.1.0-lab.7', json.loads((state / 'node-version.json').read_text())['version'])
                    for name, content in files.items():
                        self.assertEqual(content, (install / name).read_bytes())
                    self.assertEqual(0 if unchanged else 2, command.call_count)
                self.assertEqual(b'private sealed identity', identity.read_bytes())

    def test_success_keeps_identity_and_advances_version_after_health(self):
        self.exercise()

    def test_failed_health_restores_both_binaries_and_keeps_old_version(self):
        self.exercise(failure=True)

    def test_tampered_payload_never_replaces_binaries(self):
        self.exercise(tamper=True)

    def test_identical_binaries_do_not_restart_streaming_services(self):
        self.exercise(unchanged=True)


if __name__ == '__main__':
    unittest.main()
