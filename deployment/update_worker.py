"""Root-owned worker: GitHub release discovery and reversible MAIN code updates.

No database migrations, configuration changes, native binaries or remote hooks.
Only published update assets in the fixed public repository are accepted.
"""
import contextlib
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import tarfile
import tempfile
import time
import urllib.request

ROOT = pathlib.Path('/opt/xcvm-independent-lab')
STATE = pathlib.Path('/var/lib/xcvm-independent-updates')
REPOSITORY = 'mena50056/xcvm-independent'
API = 'https://api.github.com/repos/' + REPOSITORY
MANIFEST = 'xcvm-independent-update.json'
PAYLOAD = 'xcvm-independent-update.tar.gz'
MAX_PACKAGE = 100 * 1024 * 1024


def version(value):
    match = re.fullmatch(r'v(\d+)\.(\d+)\.(\d+)(?:-lab\.(\d+))?', value)
    if not match:
        raise ValueError('Unsupported independent release version')
    a, b, c, lab = match.groups()
    return (int(a), int(b), int(c), 1 if lab is None else 0, int(lab or 0))


def allowed(name):
    path = pathlib.PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts or '\\' in name or str(path) != name:
        return False
    prefixes = ('panel/src/Core/', 'panel/src/Domain/', 'panel/src/Infrastructure/',
                'panel/src/Streaming/', 'panel/src/Cli/', 'panel/src/Ministra/',
                'panel/src/Public/Controllers/', 'panel/src/Public/Views/',
                'panel/src/Public/routes/', 'panel/src/Public/assets/admin/')
    runtime = {'runtime/bootstrap.php', 'runtime/router.php', 'runtime/LocalCore.php',
               'runtime/NetworkExposure.php', 'runtime/cluster-http.php'}
    return (name in runtime or name.startswith(prefixes)) and path.suffix.lower() in {
        '.php', '.js', '.css', '.json', '.svg', '.png', '.jpg', '.webp', '.woff', '.woff2', '.ttf', '.ico', '.map'}


def atomic(path, data, mode=0o644):
    path = pathlib.Path(path)
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    temp = pathlib.Path(name)
    try:
        with os.fdopen(fd, 'wb') as file:
            file.write(data)
        temp.chmod(mode)
        temp.replace(path)
    finally:
        temp.unlink(missing_ok=True)


@contextlib.contextmanager
def panel_user():
    import pwd
    account = pwd.getpwnam('xcvm_lab')
    previous_groups = os.getgroups()
    os.setgroups(os.getgrouplist(account.pw_name, account.pw_gid))
    os.setegid(account.pw_gid)
    os.seteuid(account.pw_uid)
    try:
        yield
    finally:
        os.seteuid(0)
        os.setegid(0)
        os.setgroups(previous_groups)


def save(status):
    atomic(STATE / 'status.json', json.dumps(status, ensure_ascii=False).encode())


def get(url, limit=2 * 1024 * 1024, binary=False):
    # Asset URLs originate in this repository's API response, never the web queue.
    if not url.startswith(API + '/'):
        raise ValueError('Unexpected release API URL')
    req = urllib.request.Request(url, headers={
        'User-Agent': 'XCVM-Independent-Updates',
        'Accept': 'application/octet-stream' if binary else 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28'})
    with urllib.request.urlopen(req, timeout=90) as response:
        if not response.geturl().startswith('https://'):
            raise ValueError('Only HTTPS downloads are accepted')
        data = response.read(limit + 1)
    if len(data) > limit:
        raise ValueError('Oversized update download')
    return data


def discover(current):
    releases = json.loads(get(API + '/releases?per_page=100'))
    choices = []
    for release in releases:
        try:
            tag = release['tag_name']
            if release.get('draft') or version(tag) <= version(current):
                continue
            assets = {item['name']: item['url'] for item in release.get('assets', [])}
            if MANIFEST not in assets or PAYLOAD not in assets:
                continue
            choices.append((version(tag), release, assets))
        except (ValueError, KeyError, TypeError):
            continue
    if not choices:
        return None
    for _, release, assets in sorted(choices, key=lambda item: item[0], reverse=True):
        try:
            validate_manifest(json.loads(get(assets[MANIFEST])), release['tag_name'], current)
        except (ValueError, TypeError, KeyError):
            continue
        return {'version': release['tag_name'], 'assets': assets,
                'url': 'https://github.com/' + REPOSITORY + '/releases/tag/' + release['tag_name'],
                'notes': str(release.get('body') or '')[:12000]}
    return None


def validate_manifest(data, tag, current):
    if data.get('schema') != 1 or data.get('repository') != REPOSITORY or data.get('role') != 'main' or data.get('version') != tag:
        raise ValueError('Update identity mismatch')
    if data.get('kind') != 'code-only' or data.get('database_migrations') != []:
        raise ValueError('This updater only supports MAIN code updates without database migrations')
    if current not in data.get('from_versions', []):
        raise ValueError('This release does not support the installed version')
    files = data.get('files')
    if not isinstance(files, dict) or not files or len(files) > 15000:
        raise ValueError('Invalid update file list')
    for name, digest in files.items():
        if not allowed(name) or not re.fullmatch('[a-f0-9]{64}', digest):
            raise ValueError('Forbidden update file: ' + name)
    if not re.fullmatch('[a-f0-9]{64}', data.get('sha256', '')):
        raise ValueError('Missing payload SHA256')
    return files


def extract_checked(payload, manifest, destination):
    if hashlib.sha256(payload.read_bytes()).hexdigest() != manifest['sha256']:
        raise ValueError('Update payload checksum mismatch')
    seen = set()
    size = 0
    with tarfile.open(payload) as archive:
        for member in archive:
            name = member.name
            if not member.isfile() or name not in manifest['files'] or name in seen or not allowed(name):
                raise ValueError('Unsafe or unexpected archive entry')
            size += member.size
            if size > 300 * 1024 * 1024:
                raise ValueError('Expanded update exceeds limit')
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            content = archive.extractfile(member).read()
            if hashlib.sha256(content).hexdigest() != manifest['files'][name]:
                raise ValueError('Update file checksum mismatch')
            target.write_bytes(content)
            seen.add(name)
    if seen != set(manifest['files']):
        raise ValueError('Incomplete update payload')


def target_path(name):
    target = ROOT / name
    if target.is_symlink() or any(p.is_symlink() for p in target.parents if p != ROOT.parent):
        raise ValueError('Refusing update through a symlink')
    if not target.resolve().is_relative_to(ROOT.resolve()):
        raise ValueError('Update target escapes installation')
    return target


def health():
    for attempt in range(20):
        try:
            with urllib.request.urlopen('http://127.0.0.1:18080/lab/login', timeout=3) as response:
                if response.status == 200:
                    return
        except Exception:
            pass
        time.sleep(1)
    raise RuntimeError('MAIN login health check failed')


def restart():
    subprocess.run(['systemctl', 'restart', 'xcvm-independent-lab'], check=True, timeout=45)


def stop_web():
    subprocess.run(['systemctl', 'stop', 'xcvm-independent-lab'], check=True, timeout=45)


def apply_update(candidate, current):
    manifest = json.loads(get(candidate['assets'][MANIFEST]))
    files = validate_manifest(manifest, candidate['version'], current)
    backup = STATE / 'backups' / (str(time.time_ns()) + '-' + current)
    backup.mkdir(parents=True, mode=0o700)
    old = {}
    with tempfile.TemporaryDirectory(prefix='xcvm-update-', dir=STATE) as temp:
        stage = pathlib.Path(temp)
        payload = stage / 'payload.tar.gz'
        payload.write_bytes(get(candidate['assets'][PAYLOAD], MAX_PACKAGE, True))
        extract_checked(payload, manifest, stage / 'files')
        for name in files:
            target = target_path(name)
            if name.endswith('.php'):
                # PHP is installation-owned: never execute it with root privileges.
                stage.chmod(0o755)
                subprocess.run(['runuser', '-u', 'xcvm_lab', '--', str(ROOT / 'panel/src/bin/php/bin/php'), '-n', '-l', str(stage / 'files' / name)],
                               check=True, stdout=subprocess.DEVNULL, timeout=30)
            old[name] = target.exists()
            if target.exists():
                saved = backup / name
                saved.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(target, saved)
        (backup / 'restore.json').write_text(json.dumps({'version': current, 'files': old}))
        try:
            # Avoid serving a mixture of old and new application files.
            stop_web()
            for name in files:
                content = (stage / 'files' / name).read_bytes()
                with panel_user():
                    target = target_path(name)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    atomic(target, content)
            restart()
            health()
            atomic(STATE / 'version.json', json.dumps({'version': candidate['version']}).encode())
        except Exception:
            for name, existed in old.items():
                content = (backup / name).read_bytes() if existed else None
                mode = (backup / name).stat().st_mode & 0o777 if existed else 0o644
                with panel_user():
                    target = target_path(name)
                    if existed:
                        atomic(target, content, mode)
                    else:
                        target.unlink(missing_ok=True)
            restart()
            health()
            raise
    return str(backup)


def tick():
    installed = json.loads((ROOT / 'installed.json').read_text())
    if installed.get('repository') != REPOSITORY or installed.get('role') != 'main':
        raise ValueError('Only an independent MAIN installation is supported')
    current = json.loads((STATE / 'version.json').read_text())['version'] if (STATE / 'version.json').exists() else installed['version']
    version(current)
    status = json.loads((STATE / 'status.json').read_text()) if (STATE / 'status.json').exists() else {}
    request = STATE / 'requests/request.json'
    action = None
    if request.exists() and not request.is_symlink():
        try:
            if request.stat().st_size > 1024:
                raise ValueError('Oversized update request')
            action = json.loads(request.read_text())
        finally:
            request.unlink(missing_ok=True)
    now = int(time.time())
    if not action and now - status.get('checked_at', 0) < 3600:
        return
    status.update(current=current, state='checking', error='', checked_at=now)
    save(status)
    try:
        candidate = discover(current)
        if candidate:
            manifest = json.loads(get(candidate['assets'][MANIFEST]))
            validate_manifest(manifest, candidate['version'], current)
        status.update(latest=candidate['version'] if candidate else None,
                      url=candidate['url'] if candidate else None, notes=candidate['notes'] if candidate else '',
                      state='available' if candidate else 'current')
        if action and action.get('action') == 'apply':
            if not candidate or action.get('version') != candidate['version']:
                raise ValueError('Release changed; review the latest version before updating')
            status['state'] = 'updating'
            save(status)
            status['backup'] = apply_update(candidate, current)
            status.update(current=candidate['version'], latest=None, state='updated', updated_at=int(time.time()))
    except Exception as error:
        status.update(state='error', error=str(error)[:800])
    save(status)


def main():
    import fcntl
    if os.geteuid() != 0:
        raise SystemExit('Run the installed worker as root')
    with (STATE / 'worker.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        tick()


if __name__ == '__main__':
    main()
