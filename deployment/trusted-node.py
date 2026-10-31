"""Two-phase node installer invoked only over MAIN's authenticated SSH session."""
import hashlib
import gzip
import io
import re
import ipaddress
import json
import os
import pathlib
import shutil
import ssl
import subprocess
import sys
import tarfile
import urllib.request
import uuid
from fetch_release import fetch_release
from install import validate_archive

ROOT = pathlib.Path('/opt/horus_xc')
WORK = pathlib.Path(__file__).resolve().parent


def fresh_host():
    if os.geteuid() != 0:
        raise ValueError('Root is required')
    release = pathlib.Path('/etc/os-release').read_text()
    if 'ID=ubuntu' not in release or 'VERSION_ID="22.04"' not in release:
        raise ValueError('Ubuntu 22.04 is required')
    for path in [ROOT, pathlib.Path('/opt/xcvm-independent-lab'), pathlib.Path('/home/xc_vm'), pathlib.Path('/home/xtreamcodes'), pathlib.Path('/home/xui')]:
        if path.exists() or path.is_symlink():
            raise ValueError('Existing panel refused: ' + str(path))
    listeners = subprocess.check_output(['ss', '-H', '-lnt', '( sport = :80 or sport = :443 or sport = :8080 or sport = :18080 )'], text=True)
    if listeners.strip():
        raise ValueError('Required node ports are already occupied')


def verify_main(plan, certificate):
    ipaddress.IPv4Address(plan['main_ip'])
    port = int(plan['main_port'])
    if not 1 <= port <= 65535:
        raise ValueError('Invalid MAIN HTTPS port')
    context = ssl.create_default_context(cafile=str(certificate))
    # check_hostname=True and CERT_REQUIRED are deliberately retained.
    with urllib.request.urlopen('https://' + plan['main_ip'] + ':' + str(port) + '/horus_xc/login', context=context, timeout=20) as response:
        if response.status != 200:
            raise ValueError('MAIN login endpoint is not healthy')


def extract(archive, destination):
    validate_archive(archive)
    for member in archive.getmembers():
        target = destination / member.name
        target.parent.mkdir(parents=True, exist_ok=True)
        with archive.extractfile(member) as source, target.open('xb') as output:
            shutil.copyfileobj(source, output)
        target.chmod(member.mode & 0o755)


def install_native_agent(payload):
    manifest = json.loads((WORK / 'native-agent.json').read_text())
    if manifest['repository'] != 'mena50056/xcvm-independent' or manifest['asset'] != 'xc_agent-linux-amd64.gz':
        raise ValueError('Unexpected native agent source')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+', manifest['tag']):
        raise ValueError('Invalid native agent release tag')
    url = 'https://github.com/' + manifest['repository'] + '/releases/download/' + manifest['tag'] + '/' + manifest['asset']
    with urllib.request.urlopen(url, timeout=60) as response:
        data = response.read(16 * 1024 * 1024 + 1)
    if len(data) > 16 * 1024 * 1024 or hashlib.sha256(data).hexdigest() != manifest['sha256']:
        raise ValueError('Native agent download checksum mismatch')
    with gzip.GzipFile(fileobj=io.BytesIO(data)) as archive:
        binary = archive.read(32 * 1024 * 1024 + 1)
    if len(binary) > 32 * 1024 * 1024 or hashlib.sha256(binary).hexdigest() != manifest['binary_sha256']:
        raise ValueError('Native agent binary checksum mismatch')
    target = payload / 'panel/src/bin/xc_agent/xc_agent'
    target.write_bytes(binary)
    target.chmod(0o755)


def main():
    os.umask(0o077)
    plan = json.loads((WORK / 'plan.json').read_text())
    ipaddress.IPv4Address(plan['server_ip'])
    if plan['server_ip'] == plan['main_ip']:
        raise ValueError('Cannot install a load balancer on MAIN')
    fresh_host()
    if sys.argv[1] == 'prepare':
        verify_main(plan, WORK / 'main.crt')
        package = fetch_release('mena50056/xcvm-independent', plan['version'], 'node', None, WORK)
        payload = WORK / 'payload'
        payload.mkdir(mode=0o700)
        with tarfile.open(package) as archive:
            metadata = json.load(archive.extractfile('release.json'))
            if metadata['role'] != 'node' or metadata['version'] != plan['version'] or metadata['repository'] != 'mena50056/xcvm-independent':
                raise ValueError('Unexpected node package metadata')
            extract(archive, payload)
        install_native_agent(payload)
        result = subprocess.run([str(payload / 'panel/src/bin/xc_agent/xc_agent'), 'keygen', '-uuid', str(uuid.uuid4()), '-state', str(WORK / 'agent.json')], check=True, capture_output=True, text=True)
        public = json.loads(result.stdout)
        # Do not expose the node's private identity or any enrollment token.
        print(json.dumps({key: public[key] for key in ['node_uuid', 'sign_pub', 'box_pub', 'eph_pub']}), flush=True)
        return
    if sys.argv[1] != 'finish':
        raise ValueError('Unknown installation phase')
    verify_main(plan, WORK / 'main.crt')
    enrollment = json.loads((WORK / 'enrollment.json').read_text())
    if enrollment['main_urls'] != [plan['main_url']]:
        raise ValueError('Enrollment MAIN endpoint mismatch')
    # Files were already checksum-verified and safely extracted during prepare.
    ROOT.mkdir(mode=0o755)
    shutil.copytree(WORK / 'payload', ROOT, dirs_exist_ok=True)
    shutil.copyfile(WORK / 'configure.py', ROOT / 'deployment/configure.py')
    options = {
        'role': 'node', 'repository': 'mena50056/xcvm-independent', 'tag': plan['version'],
        'server_ip': plan['server_ip'], 'hostname': None, 'tls_cert': None, 'tls_key': None,
        'enrollment_file': str(WORK / 'enrollment.json'), 'main_ca_file': str(WORK / 'main.crt'),
        'http_port': plan['http_port'], 'https_port': plan['https_port'],
    }
    option_file = ROOT / '.install-options.json'
    option_file.write_text(json.dumps(options));option_file.chmod(0o600)
    env = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_MODE='a', APT_LISTCHANGES_FRONTEND='none')
    # Preserve host package configuration and never prompt for a merge.
    apt_config = WORK / 'apt.conf'
    apt_config.write_text('DPkg::Lock::Timeout "180";\nDPkg::Options { "--force-confdef"; "--force-confold"; };\n')
    env['APT_CONFIG'] = str(apt_config)
    try:
        subprocess.run(['bash', str(ROOT / 'deployment/setup.sh'), 'node'], env=env, check=True)
        subprocess.run(['systemctl', 'is-active', '--quiet', 'xcvm-independent-agent', 'xcvm-independent-fanout'], check=True)
        if shutil.which('ufw') and 'Status: active' in subprocess.check_output(['ufw', 'status'], text=True):
            subprocess.run(['ufw', 'allow', str(int(plan['https_port'])) + '/tcp'], check=True)
        print('Node services started; checking MAIN heartbeat next.', flush=True)
    finally:
        option_file.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never dump enrollment contents or private key data.
        print('Node installation failed: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
