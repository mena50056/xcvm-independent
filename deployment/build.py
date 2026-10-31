"""Build an allowlisted source checkout and separate MAIN/node release assets."""
import argparse
import hashlib
import io
import json
import pathlib
import re
import shutil
import tarfile

PROJECT = pathlib.Path(__file__).resolve().parents[1]
FORBIDDEN_PARTS = {'private', '.runtime-state', '.test-state', '.git', 'node_modules', '__pycache__', '.cache'}
FORBIDDEN_SUFFIXES = {'.log', '.enc', '.key', '.pem', '.sqlite', '.pyc'}


def add(archive, name, data, executable=False):
    item = tarfile.TarInfo(name)
    item.size = len(data)
    item.mode = 0o755 if executable else 0o644
    item.mtime = 0
    archive.addfile(item, io.BytesIO(data))


def copy_source(destination):
    if destination.exists():
        raise ValueError('Build destination already exists; choose a new directory')
    destination.mkdir(parents=True)
    for top in ['runtime', 'deployment', 'panel/src', 'panel/docs']:
        for path in sorted((PROJECT / top).rglob('*')):
            rel = path.relative_to(PROJECT)
            parts = rel.parts
            if not path.is_file() or path.is_symlink() or set(parts) & FORBIDDEN_PARTS:
                continue
            if path.suffix.lower() in FORBIDDEN_SUFFIXES:
                continue
            if top == 'runtime' and path.name in ('test-source.py', 'hls-producer.py'):
                continue
            if top == 'panel/src':
                child = parts[2:]
                if child[0] in ('config', 'tmp', 'signals', 'content', 'storage', 'backups'):
                    continue
                if child[0] == 'bin' and child != ('bin', 'install', 'database.sql'):
                    continue
                if path.name in ('config.ini', 'php.ini', 'RELEASE_ID'):
                    continue
            target = destination / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(path, target)
    for filename in ['README.md', 'NOTICE.md', '.gitignore']:
        shutil.copyfile(PROJECT / filename, destination / filename)
    shutil.copyfile(PROJECT / 'panel/LICENSE', destination / 'LICENSE')
    workflow = destination / '.github/workflows/verify.yml'
    workflow.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(PROJECT / '.github/workflows/verify.yml', workflow)
    # Only standalone tests with generated local fixtures are exported.
    for filename in ['core.php', 'cluster-nodes.php', 'deployment-policy.php', 'lb-install-guards.php', 'run.sh']:
        target = destination / 'tests' / filename
        target.parent.mkdir(exist_ok=True)
        shutil.copyfile(PROJECT / 'tests' / filename, target)
    return destination


def runtime_files(php_archive, tools_archive):
    files = {}
    for source, prefix in [(php_archive, 'bin/php/'), (tools_archive, 'bin/xc_')]:
        with tarfile.open(source) as archive:
            for member in archive:
                name = member.name.removeprefix('./')
                if not member.isfile() or not name.startswith(prefix):
                    continue
                if prefix == 'bin/php/':
                    extension = pathlib.PurePosixPath(name).name
                    allowed_extension = '/lib/php/extensions/' in name and extension in {
                        'igbinary.so', 'redis.so', 'ssh2.so', 'maxminddb.so', 'opcache.so'}
                    if name != 'bin/php/bin/php' and not allowed_extension:
                        continue
                if prefix == 'bin/xc_' and name not in ('bin/xc_agent/xc_agent', 'bin/xc_fanout/xc_fanout'):
                    continue
                if any(s in name.lower() for s in ('xcvm_core', 'ioncube', 'php.ini', 'php-fpm.conf')):
                    continue
                if '..' in pathlib.PurePosixPath(name).parts or name.startswith('/'):
                    raise ValueError('Unsafe runtime input')
                files['panel/src/' + name] = archive.extractfile(member).read()
    for name in ['panel/src/bin/php/bin/php', 'panel/src/bin/xc_agent/xc_agent', 'panel/src/bin/xc_fanout/xc_fanout']:
        if name not in files:
            raise ValueError('Missing runtime input: ' + name)
    return files


def build(source, output, php_archive, tools_archive, version):
    repository = json.loads((source / 'deployment/repository.json').read_text())['repository']
    output.mkdir(parents=True, exist_ok=True)
    runtime = runtime_files(php_archive, tools_archive)
    sums = []
    for role in ['main', 'node']:
        filename = f'xcvm-independent-{role}-linux-amd64.tar.gz'
        target = output / filename
        with tarfile.open(target, 'w:gz', compresslevel=3) as archive:
            add(archive, 'release.json', json.dumps({'role': role, 'version': version, 'repository': repository, 'platform': 'ubuntu-22.04-amd64', 'experimental': True}).encode())
            for file in sorted(source.rglob('*')):
                if not file.is_file():
                    continue
                rel = file.relative_to(source).as_posix()
                if not rel.startswith(('runtime/', 'deployment/', 'panel/src/')) and rel not in ('LICENSE', 'NOTICE.md'):
                    continue
                if role == 'node' and rel.startswith(('panel/', 'runtime/')):
                    continue
                add(archive, rel, file.read_bytes(), file.suffix == '.sh')
            for name, data in runtime.items():
                if role == 'node' and '/bin/php/' in name:
                    continue
                add(archive, name, data, '/bin/' in name or name.endswith(('.so', '.sh')))
        h=hashlib.sha256()
        with target.open('rb') as stream:
            while chunk := stream.read(1024*1024):
                h.update(chunk)
        digest=h.hexdigest()
        sums.append(digest + '  ' + filename)
    (output / 'SHA256SUMS').write_text('\n'.join(sums) + '\n')
    # Bootstrap utilities are usable without fetching any vendor installer.
    for name in ['install.py', 'fetch_release.py']:
        shutil.copyfile(source / 'deployment' / name, output / name)


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--source-output', type=pathlib.Path, required=True)
    p.add_argument('--release-output', type=pathlib.Path, required=True)
    p.add_argument('--php-runtime', type=pathlib.Path, required=True)
    p.add_argument('--go-runtime', type=pathlib.Path, required=True)
    p.add_argument('--version', default='v0.1.0-lab.3')
    a = p.parse_args()
    if not re.fullmatch(r'v[0-9][A-Za-z0-9_.-]*', a.version):
        raise SystemExit('Invalid version')
    source = copy_source(a.source_output.resolve())
    build(source, a.release_output.resolve(), a.php_runtime, a.go_runtime, a.version)
    print('Sanitized checkout and release assets built')


if __name__ == '__main__':
    main()
