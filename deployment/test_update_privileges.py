"""Linux CI integration: real PHP lint and file writes as the panel account.

Runs only in an ephemeral GitHub Actions runner, never on an installed panel.
"""
import hashlib
import io
import json
import os
import pathlib
import pwd
import shutil
import subprocess
import tarfile
import tempfile
from unittest.mock import patch
import update_worker as worker


def main():
    if os.environ.get('GITHUB_ACTIONS') != 'true' or os.geteuid() != 0:
        raise SystemExit('This integration test is restricted to root in ephemeral GitHub Actions')
    try:
        pwd.getpwnam('xcvm_lab')
        raise RuntimeError('Test account already exists')
    except KeyError:
        pass
    subprocess.run(['useradd', '--system', '--no-create-home', 'xcvm_lab'], check=True)
    account = pwd.getpwnam('xcvm_lab')
    try:
        with tempfile.TemporaryDirectory(prefix='xcvm-update-ci-') as temp:
            base = pathlib.Path(temp)
            base.chmod(0o755)
            root, state = base / 'panel', base / 'updates'
            root.mkdir(mode=0o755)
            state.mkdir(mode=0o755)
            name = 'panel/src/Core/update_probe.php'
            target = root / name
            target.parent.mkdir(parents=True)
            target.write_bytes(b'<?php echo "before";')
            binary = root / 'panel/src/bin/php/bin/php'
            binary.parent.mkdir(parents=True)
            binary.symlink_to(shutil.which('php'))
            for parent, dirs, files in os.walk(root):
                os.chown(parent, account.pw_uid, account.pw_gid)
                for file in files:
                    path = pathlib.Path(parent) / file
                    if not path.is_symlink():
                        os.chown(path, account.pw_uid, account.pw_gid)
            secret = root / 'php.ini'
            secret.write_bytes(b'private configuration')
            secret.chmod(0o600)
            content = b'<?php echo "after";'
            payload = base / 'payload.tar.gz'
            with tarfile.open(payload, 'w:gz') as archive:
                member = tarfile.TarInfo(name)
                member.size = len(content)
                archive.addfile(member, io.BytesIO(content))
            manifest = {'schema': 1, 'role': 'main', 'repository': worker.REPOSITORY, 'kind': 'code-only',
                        'version': 'v0.1.0-lab.5', 'from_versions': ['v0.1.0-lab.4'], 'database_migrations': [],
                        'sha256': hashlib.sha256(payload.read_bytes()).hexdigest(),
                        'files': {name: hashlib.sha256(content).hexdigest()}}
            candidate = {'version': manifest['version'], 'assets': {worker.MANIFEST: 'manifest', worker.PAYLOAD: 'payload'}}
            def get(url, *args):
                return json.dumps(manifest).encode() if url == 'manifest' else payload.read_bytes()
            with patch.object(worker, 'ROOT', root), patch.object(worker, 'STATE', state), \
                 patch.object(worker, 'get', side_effect=get), patch.object(worker, 'stop_web'), patch.object(worker, 'restart'), patch.object(worker, 'health'):
                worker.apply_update(candidate, 'v0.1.0-lab.4')
                assert target.read_bytes() == content
                assert target.stat().st_uid == account.pw_uid
                assert secret.read_bytes() == b'private configuration' and secret.stat().st_uid == 0
                assert os.geteuid() == 0
            print('PASS: real PHP lint, unprivileged installation writes and preserved private configuration')
    finally:
        subprocess.run(['userdel', 'xcvm_lab'], check=True)


if __name__ == '__main__':
    main()
