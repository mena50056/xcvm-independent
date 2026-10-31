"""Verified native-only LB updates, invoked through the panel's pinned SSH flow."""
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tarfile
import tempfile
import time
import update_worker as common

MANIFEST = 'xcvm-independent-node-update.json'
PAYLOAD = 'xcvm-independent-node-update.tar.gz'
FILES = {'panel/src/bin/xc_agent/xc_agent': 'xcvm-independent-agent',
         'panel/src/bin/xc_fanout/xc_fanout': 'xcvm-independent-fanout'}


def validate(data):
    if data.get('schema') != 1 or data.get('role') != 'node' or data.get('repository') != common.REPOSITORY:
        raise ValueError('Wrong node update identity')
    common.version(data['version'])
    if not isinstance(data.get('from_versions'), list) or not data['from_versions']:
        raise ValueError('Missing supported node versions')
    for value in data['from_versions']:
        common.version(value)
    if data.get('kind') != 'native-only' or data.get('database_migrations') != [] or set(data.get('files', {})) != set(FILES):
        raise ValueError('Only the two native LB binaries may be updated')
    import re
    if any(not re.fullmatch('[a-f0-9]{64}', digest) for digest in [data.get('sha256', '')] + list(data['files'].values())):
        raise ValueError('Invalid node update checksums')
    return data


def catalog():
    releases = json.loads(common.get(common.API + '/releases?per_page=100'))
    result = []
    for release in releases:
        assets = {asset['name']: asset['url'] for asset in release.get('assets', [])}
        if release.get('draft') or MANIFEST not in assets or PAYLOAD not in assets:
            continue
        data = validate(json.loads(common.get(assets[MANIFEST], binary=True)))
        if data['version'] != release['tag_name']:
            raise ValueError('Node release tag mismatch')
        result.append({'version': data['version'], 'from_versions': data['from_versions'],
                       'assets': assets, 'url': 'https://github.com/' + common.REPOSITORY + '/releases/tag/' + data['version']})
    return sorted(result, key=lambda item: common.version(item['version']), reverse=True)


def candidate(current):
    return next((item for item in catalog() if current in item['from_versions'] and common.version(item['version']) > common.version(current)), None)


def inspect():
    data = json.loads((common.ROOT / 'installed.json').read_text())
    if data.get('role') != 'node' or data.get('repository') != common.REPOSITORY:
        raise ValueError('Only an existing independent LB may be updated')
    if pathlib.Path('/etc/systemd/system/xcvm-independent-lab.service').exists():
        raise ValueError('Refusing a MAIN host')
    if not (common.ROOT / 'panel/src/config/cluster/agent.json').is_file():
        raise ValueError('Node enrollment identity is missing')
    marker = common.STATE / 'node-version.json'
    current = json.loads(marker.read_text())['version'] if marker.exists() else data['version']
    common.version(current)
    return current


def health():
    subprocess.run(['systemctl', 'is-active', '--quiet', *FILES.values()], check=True, timeout=30)
    # Also require the fanout control socket, not just a systemd process.
    import socket
    path = common.ROOT / 'panel/src/bin/xc_fanout/sockets/control.sock'
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(5)
        connection.connect(str(path))


def apply(item, current):
    if common.version(item['version']) <= common.version(current):
        raise ValueError('Node update must be newer than installed')
    data = validate(json.loads(common.get(item['assets'][MANIFEST], binary=True)))
    if data['version'] != item['version'] or current not in data['from_versions']:
        raise ValueError('Incompatible node update')
    backup = common.STATE / 'backups' / ('node-' + str(time.time_ns()))
    backup.mkdir(parents=True, mode=0o700)
    with tempfile.TemporaryDirectory(prefix='xcvm-node-update-', dir=common.STATE) as temp:
        stage = pathlib.Path(temp)
        payload = stage / 'payload.tar.gz'
        payload.write_bytes(common.get(item['assets'][PAYLOAD], 64 * 1024 * 1024, True))
        if hashlib.sha256(payload.read_bytes()).hexdigest() != data['sha256']:
            raise ValueError('Node payload checksum mismatch')
        seen = set()
        expanded = 0
        with tarfile.open(payload) as archive:
            for member in archive:
                if not member.isfile() or member.name not in FILES or member.name in seen:
                    raise ValueError('Unexpected node archive entry')
                expanded += member.size
                if expanded > 128 * 1024 * 1024:
                    raise ValueError('Expanded node package exceeds limit')
                content = archive.extractfile(member).read()
                if hashlib.sha256(content).hexdigest() != data['files'][member.name] or content[:4] != b'\x7fELF':
                    raise ValueError('Invalid native node binary')
                (stage / pathlib.Path(member.name).name).write_bytes(content)
                seen.add(member.name)
        if seen != set(FILES):
            raise ValueError('Incomplete node update')
        changed = []
        for name, service in FILES.items():
            target = common.target_path(name)
            if not target.is_file():
                raise ValueError('Installed native binary is missing')
            if hashlib.sha256(target.read_bytes()).hexdigest() != data['files'][name]:
                (backup / target.name).write_bytes(target.read_bytes())
                changed.append((name, service))
        try:
            for name, _ in changed:
                content = (stage / pathlib.Path(name).name).read_bytes()
                with common.panel_user():
                    common.atomic(common.target_path(name), content, 0o755)
            for _, service in changed:
                subprocess.run(['systemctl', 'restart', service], check=True, timeout=45)
            for attempt in range(20):
                try:
                    health()
                    break
                except Exception:
                    if attempt == 19:
                        raise
                    time.sleep(1)
            common.atomic(common.STATE / 'node-version.json', json.dumps({'version': item['version']}).encode())
        except Exception:
            for name, _ in changed:
                content = (backup / pathlib.Path(name).name).read_bytes()
                with common.panel_user():
                    common.atomic(common.target_path(name), content, 0o755)
            for _, service in changed:
                subprocess.run(['systemctl', 'restart', service], check=True, timeout=45)
            raise
    return item['version']


def main():
    if os.geteuid() != 0:
        raise SystemExit('Root SSH access is required')
    current = inspect()
    command = sys.argv[1] if len(sys.argv) > 1 else 'inspect'
    if command == 'inspect':
        print(json.dumps({'version': current}))
        return
    if command != 'apply' or len(sys.argv) != 3:
        raise SystemExit('Expected inspect or apply VERSION')
    common.STATE.mkdir(mode=0o755, exist_ok=True)
    import fcntl
    with (common.STATE / 'node-update.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        item = candidate(current)
        if not item or item['version'] != sys.argv[2]:
            raise ValueError('No newer compatible node release is available')
        print(json.dumps({'version': apply(item, current)}))


if __name__ == '__main__':
    main()
