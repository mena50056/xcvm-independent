"""Generate configuration for a clean host; no existing test credentials are copied."""
import json
import os
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path('/opt/horus_xc')
SRC = ROOT / 'panel/src'


def write(path, text, mode=0o644):
    p = pathlib.Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text)
    p.chmod(mode)


options = json.loads((ROOT / '.install-options.json').read_text())
role = options['role']
stage = sys.argv[1]
if stage != 'services':
    write(ROOT / 'installed.json', json.dumps({'role': role, 'repository': options['repository'], 'version': options['tag']}))
    config = {'prebuffer_max_sec': 60, 'hls_target_sec': 6, 'hls_window': 6, 'grace_sec': 10,
              'source_insecure': False, 'source_backend': 'auto', 'supervise': True}
    write(SRC / 'bin/xc_fanout/config.json', json.dumps(config))
    tls = ROOT / 'tls'
    tls.mkdir(mode=0o700, exist_ok=True)
    hostname = options.get('hostname') or options['server_ip']
    san = ('DNS:' if options.get('hostname') else 'IP:') + hostname
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-days', '30',
                    '-subj', '/CN=' + hostname, '-addext', 'subjectAltName=' + san,
                    '-keyout', str(tls / 'server.key'), '-out', str(tls / 'server.crt')], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    (tls / 'server.key').chmod(0o600)
    if options.get('tls_cert'):
        shutil.copyfile(options['tls_cert'], tls / 'server.crt')
        shutil.copyfile(options['tls_key'], tls / 'server.key')
        (tls / 'server.key').chmod(0o600)
    if role == 'node':
        if options.get('main_ca_file'):
            certificate = pathlib.Path(options['main_ca_file']).read_bytes()
            system_certs = pathlib.Path('/etc/ssl/certs/ca-certificates.crt').read_bytes()
            ca_file = SRC / 'config/cluster/main-ca-bundle.crt'
            ca_file.parent.mkdir(parents=True, exist_ok=True)
            ca_file.write_bytes(certificate + b'\n' + system_certs)
            ca_file.chmod(0o600)
            write(SRC / 'config/cluster/main-ca.sha256', __import__('hashlib').sha256(certificate).hexdigest() + '\n', 0o600)
        data = json.loads(pathlib.Path(options['enrollment_file']).read_text())
        if not data.get('server_id') or not data.get('token_sealed') or not data.get('main_urls'):
            raise ValueError('Invalid node enrollment')
        if any(not url.startswith('https://') for url in data['main_urls']):
            raise ValueError('Remote cluster enrollment requires trusted HTTPS')
        # Keygen must have been done on THIS node before MAIN issued the sealed token.
        identity = pathlib.Path(options['enrollment_file']).with_name('agent.json')
        if not identity.is_file():
            raise ValueError('Place this node\'s keygen agent.json beside enrollment.json')
        write(SRC / 'config/cluster/agent.json', identity.read_text(), 0o600)
        write(ROOT / '.node-enrollment.json', json.dumps(data), 0o600)
        write(SRC / 'http.conf', 'listen ' + str(int(options.get('http_port', 8080))) + ';\n')
        write(SRC / 'https.conf', 'listen ' + str(int(options.get('https_port', 443))) + ' ssl;\n')
        write(ROOT / 'node-id', str(int(data['server_id'])))
    else:
        extensions = SRC / 'bin/php/lib/php/extensions/no-debug-non-zts-20230831'
        ini = f'''[PHP]
extension_dir={extensions}
memory_limit=512M
date.timezone=Africa/Cairo
display_errors=Off
log_errors=On
error_log={ROOT}/php-errors.log
session.save_path={SRC}/tmp/player
extension=igbinary.so
extension=redis.so
extension=ssh2.so
extension=maxminddb.so
'''
        write(ROOT / 'php.ini', ini)
    raise SystemExit(0)


def unit(name, command, after='', extra=''):
    write('/etc/systemd/system/' + name + '.service', f'''[Unit]
Description=XCVM independent {name}
After=network-online.target {after}
[Service]
User=xcvm_lab
Group=xcvm_lab
Environment=XCVM_LAB_STATE={ROOT}/.runtime-state/main
Environment=PHPRC={ROOT}/php.ini
Environment=PHP_INI_SCAN_DIR=
Environment=PHP_CLI_SERVER_WORKERS=8
ExecStart={command}
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
{extra}
[Install]
WantedBy=multi-user.target
''')


fan = SRC / 'bin/xc_fanout'
cmd = f'{fan}/xc_fanout -sock {fan}/sockets/http.sock -ctl {fan}/sockets/control.sock -config {fan}/config.json -ffmpeg /usr/bin/ffmpeg -fileroots {SRC}/content'
if role == 'node':
    cmd += f' -front enabled -front-ports-http {SRC}/http.conf -front-ports-https {SRC}/https.conf -front-cert {ROOT}/tls/server.crt -front-key {ROOT}/tls/server.key -front-nginx http://127.0.0.1:1 -front-streams {SRC}/content/streams -gw {fan}/sockets/gw.sock -gw-policy {SRC}/tmp/gateway/policy.json -front-agent-sock {SRC}/config/cluster/agent.sock'
unit('xcvm-independent-fanout', cmd, extra=('AmbientCapabilities=CAP_NET_BIND_SERVICE\nCapabilityBoundingSet=CAP_NET_BIND_SERVICE' if role=='node' else ''))
php = f'{SRC}/bin/php/bin/php -c {ROOT}/php.ini'
if role == 'main':
    unit('xcvm-independent-lab', f'{php} -S 127.0.0.1:18080 -t {SRC}/Public {ROOT}/runtime/router.php', 'mariadb.service redis-server.service')
    unit('xcvm-independent-monitor', f'{php} {ROOT}/runtime/monitor.php')
    unit('xcvm-independent-signals', f'{php} {SRC}/console.php signals')
    unit('xcvm-independent-fanout-sync', f'{php} {SRC}/console.php fanout_sync')
    write(ROOT / 'deployment/maintenance.sh', f'''#!/bin/sh
set -eu
export XCVM_LAB_STATE={ROOT}/.runtime-state/main PHPRC={ROOT}/php.ini PHP_INI_SCAN_DIR=
{php} {ROOT}/deployment/maintenance.php
{php} {SRC}/console.php cron:cache_engine
date +%s > {SRC}/config/signals.last
''', 0o755)
    unit('xcvm-independent-maintenance', f'/bin/sh {ROOT}/deployment/maintenance.sh', extra='Type=oneshot')
    # A oneshot timer must not use automatic restart.
    path = pathlib.Path('/etc/systemd/system/xcvm-independent-maintenance.service')
    path.write_text(path.read_text().replace('Restart=on-failure', 'Restart=no'))
    write('/etc/systemd/system/xcvm-independent-maintenance.timer', '''[Unit]
Description=Independent cache maintenance
[Timer]
OnBootSec=15
OnUnitActiveSec=60
[Install]
WantedBy=timers.target
''')
    write('/etc/nginx/sites-available/xcvm-independent', f'''server {{
listen 80;
server_name {options.get('hostname') or options['server_ip']};
return 308 https://$host$request_uri;
}}
server {{
listen 443 ssl;
server_name {options.get('hostname') or options['server_ip']};
ssl_certificate {ROOT}/tls/server.crt;
ssl_certificate_key {ROOT}/tls/server.key;
location /xc_fanout/ {{ internal; proxy_pass http://unix:{fan}/sockets/http.sock:/live/; proxy_buffering off; proxy_read_timeout 60s; }}
location ^~ /xc_fanout_hls/ {{ internal; rewrite ^/xc_fanout_hls/(\\d+)_(\\d+)$ /hls/$1/$2.ts break; proxy_pass http://unix:{fan}/sockets/http.sock; proxy_http_version 1.1; proxy_set_header Connection ""; }}
location / {{ proxy_pass http://127.0.0.1:18080; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Real-IP $remote_addr; proxy_read_timeout 75s; }}
}}
''')
    link = pathlib.Path('/etc/nginx/sites-enabled/xcvm-independent')
    if not link.exists():
        link.symlink_to('/etc/nginx/sites-available/xcvm-independent')
    subprocess.run(['usermod', '-aG', 'xcvm_lab', 'www-data'], check=True)
    # Restrict databases to loopback on this fresh installation.
    write('/etc/mysql/mariadb.conf.d/90-xcvm-independent.cnf', '[mysqld]\nbind-address=127.0.0.1\n')
    subprocess.run(['systemctl', 'restart', 'mariadb'], check=True)
else:
    sid = int((ROOT / 'node-id').read_text())
    ca = SRC / 'config/cluster/main-ca-bundle.crt'
    unit('xcvm-independent-agent', f'{SRC}/bin/xc_agent/xc_agent run -serve-only -seed-fanout -state {SRC}/config/cluster/agent.json -server-id {sid} -fanout-ctl {fan}/sockets/control.sock -fanout-binary {fan}/xc_fanout -ffmpeg /usr/bin/ffmpeg -ffprobe /usr/bin/ffprobe -selfheal-repo= -ffmpeg-repo= -streams {SRC}/content/streams/ -tmp {SRC}/tmp/ -signals {SRC}/signals/ -playlist {SRC}/content/playlists/ -logs {SRC}/tmp/logs/ -gateway-policy {SRC}/tmp/gateway/policy.json -flood-dir {SRC}/tmp/flood/', extra=(f'Environment=SSL_CERT_FILE={ca}' if ca.exists() else ''))
