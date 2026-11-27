"""Install only the independent edge proxy, never the MAIN panel or a database."""
import json,os,pathlib,shutil,subprocess

def run(*args):subprocess.run(args,check=True)

def main():
    if os.geteuid()!=0:raise SystemExit('Run as root')
    release=dict(line.split('=',1) for line in pathlib.Path('/etc/os-release').read_text().splitlines() if '=' in line)
    if release.get('ID','').strip('"')!='ubuntu' or release.get('VERSION_ID','').strip('"') not in ['22.04','24.04']:raise SystemExit('Ubuntu 22.04 or 24.04 required')
    if pathlib.Path('/opt/horus_xc').exists() or pathlib.Path('/home/xc_vm').exists():raise SystemExit('Refusing to install on a MAIN / streaming server')
    source=pathlib.Path(__file__).resolve().parent;root=pathlib.Path('/opt/horus_proxy')
    enrollment=json.loads((source/'enrollment.json').read_text())
    if enrollment.get('schema')!=1 or not enrollment['main_url'].startswith('https://') or len(bytes.fromhex(enrollment['key']))!=32:raise SystemExit('Invalid private enrollment')
    if root.exists():
        current=json.loads((root/'enrollment.json').read_text())
        if current['server_id']!=enrollment['server_id'] or current['main_url']!=enrollment['main_url']:raise SystemExit('Existing proxy belongs to another enrollment')
    elif subprocess.run(['pgrep','-x','nginx'],stdout=subprocess.DEVNULL).returncode==0:raise SystemExit('Existing nginx detected: use a separate fresh server')
    # Suppress the distro service autostart during package installation only.
    policy=pathlib.Path('/usr/sbin/policy-rc.d');previous=policy.read_bytes() if policy.exists() else None;mode=policy.stat().st_mode&0o777 if policy.exists() else 0o755
    try:
        policy.write_text('#!/bin/sh\nexit 101\n');policy.chmod(0o755)
        run('apt-get','update');run('apt-get','install','-y','nginx','openssl','ca-certificates','python3')
    finally:
        if previous is None:policy.unlink(missing_ok=True)
        else:policy.write_bytes(previous);policy.chmod(mode)
    run('systemctl','disable','--now','nginx.service')
    root.mkdir(mode=0o755,exist_ok=True)
    for name in ['proxy_agent.py','proxy_config.py']:
        shutil.copy2(source/name,root/name);(root/name).chmod(0o644)
    temporary=root/'enrollment.next';temporary.write_text(json.dumps(enrollment));temporary.chmod(0o600);temporary.replace(root/'enrollment.json')
    units={'horus-proxy-nginx.service':'''[Unit]
Description=Horus streaming edge nginx
After=network-online.target
Wants=network-online.target
[Service]
Type=forking
PIDFile=/opt/horus_proxy/run/nginx.pid
ExecStartPre=/usr/sbin/nginx -t -c /opt/horus_proxy/nginx.conf
ExecStart=/usr/sbin/nginx -c /opt/horus_proxy/nginx.conf
ExecReload=/usr/sbin/nginx -t -c /opt/horus_proxy/nginx.conf
ExecReload=/bin/kill -HUP $MAINPID
ExecStop=/bin/kill -QUIT $MAINPID
Restart=on-failure
TimeoutStopSec=30
LimitNOFILE=262144
PrivateTmp=true
ProtectHome=true
NoNewPrivileges=true
[Install]
WantedBy=multi-user.target
''','horus-proxy-agent.service':'''[Unit]
Description=Horus authenticated proxy configuration agent
After=network-online.target
Wants=network-online.target
[Service]
ExecStart=/usr/bin/python3 /opt/horus_proxy/proxy_agent.py --root /opt/horus_proxy
Restart=always
RestartSec=5
UMask=0077
PrivateTmp=true
ProtectHome=true
NoNewPrivileges=true
[Install]
WantedBy=multi-user.target
'''}
    for name,content in units.items():pathlib.Path('/etc/systemd/system',name).write_text(content)
    run('systemctl','daemon-reload');run('systemctl','enable','horus-proxy-nginx.service');run('systemctl','enable','horus-proxy-agent.service');run('systemctl','restart','horus-proxy-agent.service')
    print('Horus proxy agent installed. Check the admin Applied status and journalctl -u horus-proxy-agent. Configure DNS, provider firewall and trusted public TLS before public use.')

if __name__=='__main__':main()
