import contextlib
import hashlib
import io
import json
import pathlib
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import update_worker as worker


class UpdateTests(unittest.TestCase):
    def manifest(self, content=b'<?php echo "new";'):
        return {'schema': 1, 'kind': 'code-only', 'role': 'main', 'repository': worker.REPOSITORY,
                'version': 'v0.1.0-lab.5', 'from_versions': ['v0.1.0-lab.4'], 'database_migrations': [],
                'sha256': '0' * 64, 'files': {'panel/src/Core/example.php': hashlib.sha256(content).hexdigest()}}

    def archive(self, directory, entries):
        file = directory / 'test.tar.gz'
        with tarfile.open(file, 'w:gz') as archive:
            for name, content, kind in entries:
                member = tarfile.TarInfo(name)
                member.type = kind
                member.size = len(content)
                archive.addfile(member, io.BytesIO(content))
        return file

    def test_versions_ignore_other_release_tags_and_sort_lab_numerically(self):
        self.assertGreater(worker.version('v0.1.0-lab.10'), worker.version('v0.1.0-lab.9'))
        self.assertGreater(worker.version('v0.1.0'), worker.version('v0.1.0-lab.99'))
        with self.assertRaises(ValueError):
            worker.version('native-controls-v1')

    def test_discovery_only_advertises_complete_update_releases(self):
        releases = [{'tag_name': 'v0.1.0-lab.9', 'draft': False, 'assets': []},
                    {'tag_name': 'v0.1.0-lab.5', 'draft': False, 'assets': [
                        {'name': name, 'url': worker.API + '/releases/assets/' + str(i)}
                        for i, name in enumerate([worker.MANIFEST, worker.PAYLOAD])]}]
        def download(url, *args, **kwargs):
            if '/releases?' not in url:
                self.assertTrue(kwargs.get('binary'), 'GitHub asset JSON requires octet-stream Accept')
            return json.dumps(releases if '/releases?' in url else self.manifest()).encode()
        with patch.object(worker, 'get', side_effect=download):
            self.assertEqual('v0.1.0-lab.5', worker.discover('v0.1.0-lab.4')['version'])
            self.assertIsNone(worker.discover('v0.1.0-lab.5'))

    def test_discovery_offers_compatible_intermediate_before_newest(self):
        releases = []
        for n in [5, 6]:
            releases.append({'tag_name': 'v0.1.0-lab.' + str(n), 'assets': [
                {'name': name, 'url': worker.API + '/releases/assets/' + str(n) + '/' + name}
                for name in [worker.MANIFEST, worker.PAYLOAD]]})
        def download(url, *args, **kwargs):
            if '/releases?' in url:
                return json.dumps(releases).encode()
            self.assertTrue(kwargs.get('binary'), 'Download the manifest contents, not asset metadata')
            manifest = self.manifest()
            if '/6/' in url:
                manifest.update(version='v0.1.0-lab.6', from_versions=['v0.1.0-lab.5'])
            return json.dumps(manifest).encode()
        with patch.object(worker, 'get', side_effect=download):
            self.assertEqual('v0.1.0-lab.5', worker.discover('v0.1.0-lab.4')['version'])

    def test_secrets_binaries_migrations_and_traversal_are_forbidden(self):
        for name in ['runtime/../php.ini', '/etc/passwd', 'panel/src/config/cluster/agent.json',
                     'panel/src/bin/php/bin/php', 'panel/src/content/test.php', 'panel/src/vendor/a.php',
                     'panel/src/Core/../../config/a.php', 'panel/src/migrations/a.php',
                     'deployment/setup.sh', 'installed.json', 'runtime/master.key']:
            self.assertFalse(worker.allowed(name), name)

    def test_manifest_requires_compatible_main_without_migrations(self):
        worker.validate_manifest(self.manifest(), 'v0.1.0-lab.5', 'v0.1.0-lab.4')
        for field, value in [('role', 'node'), ('repository', 'other/repo'), ('database_migrations', ['SQL']),
                             ('from_versions', ['v0.1.0-lab.3'])]:
            manifest = self.manifest()
            manifest[field] = value
            with self.assertRaises(ValueError):
                worker.validate_manifest(manifest, 'v0.1.0-lab.5', 'v0.1.0-lab.4')

    def test_archive_tampering_extra_files_and_symlinks_fail(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            name = 'panel/src/Core/example.php'
            for entries in [[(name, b'wrong', tarfile.REGTYPE)],
                            [(name, b'<?php echo "new";', tarfile.REGTYPE), ('runtime/secret.php', b'x', tarfile.REGTYPE)],
                            [(name, b'', tarfile.SYMTYPE)], []]:
                payload = self.archive(root, entries)
                manifest = self.manifest()
                manifest['sha256'] = hashlib.sha256(payload.read_bytes()).hexdigest()
                with self.assertRaises(ValueError):
                    worker.extract_checked(payload, manifest, root / 'stage')

    def test_failed_health_rolls_back_and_preserves_version_and_config(self):
        self.exercise_apply(fail=True)

    def test_success_updates_code_and_version_but_preserves_config(self):
        self.exercise_apply(fail=False)

    def exercise_apply(self, fail):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            install, state = root / 'install', root / 'state'
            state.mkdir()
            target = install / 'panel/src/Core/example.php'
            target.parent.mkdir(parents=True)
            target.write_bytes(b'old')
            (install / 'php.ini').write_bytes(b'private configuration')
            payload = self.archive(root, [(str(target.relative_to(install)).replace('\\', '/'), b'<?php echo "new";', tarfile.REGTYPE)])
            manifest = self.manifest()
            manifest['sha256'] = hashlib.sha256(payload.read_bytes()).hexdigest()
            candidate = {'version': manifest['version'], 'assets': {worker.MANIFEST: 'manifest', worker.PAYLOAD: 'payload'}}
            def download(url, *args, **kwargs):
                return json.dumps(manifest).encode() if url == 'manifest' else payload.read_bytes()
            with patch.object(worker, 'ROOT', install), patch.object(worker, 'STATE', state), \
                 patch.object(worker, 'get', side_effect=download), patch.object(worker, 'panel_user', contextlib.nullcontext), \
                 patch.object(worker.subprocess, 'run'), patch.object(worker, 'restart') as restart, \
                 patch.object(worker, 'health', side_effect=[RuntimeError('unhealthy'), None] if fail else [None]):
                if fail:
                    with self.assertRaisesRegex(RuntimeError, 'unhealthy'):
                        worker.apply_update(candidate, 'v0.1.0-lab.4')
                    self.assertEqual(b'old', target.read_bytes())
                    self.assertFalse((state / 'version.json').exists())
                    self.assertEqual(2, restart.call_count)
                else:
                    worker.apply_update(candidate, 'v0.1.0-lab.4')
                    self.assertEqual(b'<?php echo "new";', target.read_bytes())
                    self.assertEqual('v0.1.0-lab.5', json.loads((state / 'version.json').read_text())['version'])
                self.assertEqual(b'private configuration', (install / 'php.ini').read_bytes())

    def test_symlink_install_target_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            try:
                (root / 'runtime').symlink_to(root / 'elsewhere', target_is_directory=True)
            except OSError:
                self.skipTest('Symlink privilege unavailable')
            with patch.object(worker, 'ROOT', root), self.assertRaises(ValueError):
                worker.target_path('runtime/LocalCore.php')


if __name__ == '__main__':
    unittest.main()
