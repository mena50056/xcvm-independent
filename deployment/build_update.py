"""Build a code-only, checksummed update release; never include config or state."""
import argparse
import hashlib
import io
import json
import pathlib
import subprocess
import tarfile
from update_worker import allowed, version, REPOSITORY, MANIFEST, PAYLOAD


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--from-version', action='append', required=True)
    args = parser.parse_args()
    version(args.version)
    for current in args.from_version:
        if version(current) >= version(args.version):
            raise ValueError('Updates must advance the installed version')
    # Only tracked files from the reviewable source checkout enter the package.
    tracked = subprocess.check_output(['git', '-C', str(args.source), 'ls-files', '-z']).decode().split('\0')
    files = sorted(name for name in tracked if allowed(name))
    args.output.mkdir(parents=True, exist_ok=True)
    hashes = {}
    payload = args.output / PAYLOAD
    with tarfile.open(payload, 'w:gz') as archive:
        for name in files:
            data = (args.source / name).read_bytes()
            hashes[name] = hashlib.sha256(data).hexdigest()
            member = tarfile.TarInfo(name)
            member.size = len(data)
            member.mode = 0o644
            archive.addfile(member, io.BytesIO(data))
    manifest = {'schema': 1, 'kind': 'code-only', 'repository': REPOSITORY, 'role': 'main',
                'version': args.version, 'from_versions': args.from_version, 'database_migrations': [],
                'sha256': hashlib.sha256(payload.read_bytes()).hexdigest(), 'files': hashes}
    (args.output / MANIFEST).write_text(json.dumps(manifest, indent=2) + '\n')
    print('Built', args.version, len(files), 'files;', payload.stat().st_size, 'bytes')


if __name__ == '__main__':
    main()
