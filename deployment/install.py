"""Fresh-server installer. It refuses existing deployments and the original host."""
import argparse
import ipaddress
import json
import os
import pathlib
import re
import subprocess
import tarfile
import tempfile
from fetch_release import fetch_release

ROOT = pathlib.Path('/opt/xcvm-independent-lab')
BLOCKED = '45.32.223.254'


def validate_archive(archive):
    names = set()
    for member in archive.getmembers():
        p = pathlib.PurePosixPath(member.name)
        if p.is_absolute() or '..' in p.parts or '\\' in member.name:
            raise ValueError('Unsafe archive path')
        if not member.isfile() or member.name in names:
            raise ValueError('Only unique regular files are accepted')
        if p.parts[0] not in ('runtime', 'deployment', 'panel', 'LICENSE', 'NOTICE.md', 'release.json'):
            raise ValueError('Unexpected archive member')
        if 'xcvm_core.so' in member.name or 'ioncube' in member.name.lower():
            raise ValueError('Original extension is forbidden')
        names.add(member.name)


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--role', choices=['main', 'node'], required=True)
    p.add_argument('--repository', default='mena50056/xcvm-independent')
    p.add_argument('--tag', required=True)
    p.add_argument('--token-file', required=True)
    p.add_argument('--server-ip', required=True)
    p.add_argument('--admin-user', default='admin')
    p.add_argument('--hostname')
    p.add_argument('--enrollment-file')
    p.add_argument('--tls-cert')
    p.add_argument('--tls-key')
    p.add_argument('--plan', action='store_true')
    a = p.parse_args()
    if bool(a.tls_cert) != bool(a.tls_key):
        raise SystemExit('Supply both --tls-cert and --tls-key')
    address = ipaddress.ip_address(a.server_ip)
    if str(address) == BLOCKED:
        raise SystemExit('The original production server is explicitly excluded')
    if address.version != 4:
        raise SystemExit('This experimental installer currently supports IPv4')
    if a.hostname and (not re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', a.hostname) or a.hostname==str(address)):
        raise SystemExit('Invalid DNS hostname')
    if not a.plan:
        if os.geteuid() != 0:
            raise SystemExit('Run as root on a fresh server')
        local = subprocess.check_output(['ip', '-j', 'address'], text=True)
        if BLOCKED in local:
            raise SystemExit('Refusing the original production server')
    if ROOT.exists() or pathlib.Path('/home/xc_vm').exists() or pathlib.Path('/home/xc_vm').is_symlink():
        raise SystemExit('Existing panel detected; reinstall/upgrade is deliberately refused')
    if a.role == 'node' and not a.enrollment_file:
        raise SystemExit('A private node enrollment JSON from MAIN is required')
    print(json.dumps({'repository': a.repository, 'tag': a.tag, 'role': a.role,
                      'root': str(ROOT), 'server_ip': str(address), 'existing_server': 'refused'}))
    if a.plan:
        return
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix='xcvm-release-') as temp:
        package = fetch_release(a.repository, a.tag, a.role, a.token_file, temp)
        with tarfile.open(package) as archive:
            validate_archive(archive)
            metadata = json.load(archive.extractfile('release.json'))
            if metadata['role'] != a.role or metadata['version'] != a.tag or metadata['repository'] != a.repository:
                raise ValueError('Release metadata mismatch')
            ROOT.mkdir(mode=0o755)
            # Members were all validated as regular files; never extract links/devices.
            for member in archive.getmembers():
                target = ROOT / member.name
                target.parent.mkdir(parents=True, exist_ok=True)
                with archive.extractfile(member) as src, target.open('xb') as dst:
                    while chunk := src.read(1024 * 1024):
                        dst.write(chunk)
                target.chmod(member.mode & 0o755)
    # Only the validated bundle's setup is run. Credentials stay in a private file.
    options = ROOT / '.install-options.json'
    options.write_text(json.dumps(vars(a)))
    options.chmod(0o600)
    try:
        subprocess.run(['bash', str(ROOT / 'deployment/setup.sh'), a.role], check=True)
    finally:
        options.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
