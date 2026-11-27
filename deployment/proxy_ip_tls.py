"""Optional public IP TLS for an installed Horus proxy; no domain or MAIN keys needed."""
import argparse,ipaddress,json,os,pathlib,re,shlex,subprocess
from proxy_agent import atomic

CERTBOT='certbot==5.10.0'

class PublicIPTLS:
    def __init__(self,root):
        self.root=pathlib.Path(root)
        if not self.root.is_absolute() or '..' in self.root.parts or not re.fullmatch(r'/[A-Za-z0-9_./-]+',str(self.root)):raise ValueError('INVALID_ROOT')
        state=json.loads((self.root/'state.json').read_text());profile=state['wire']['profile']
        self.ip=str(ipaddress.ip_address(profile['server_ip']))
        if not ipaddress.ip_address(self.ip).is_global:raise ValueError('PUBLIC_IP_REQUIRED')
        if 80 not in profile['http_ports']:raise ValueError('HTTP_PORT_80_REQUIRED_FOR_RENEWAL')
        self.acme=self.root/'acme';self.webroot=self.acme/'www';self.client=self.acme/'venv/bin/certbot'

    def run(self,args):subprocess.run([str(v) for v in args],check=True,timeout=900)

    def arguments(self,staging=False):
        name='staging' if staging else 'production'
        return ['--config-dir',str(self.acme/name/'config'),'--work-dir',str(self.acme/name/'work'),'--logs-dir',str(self.acme/name/'logs')]

    def deploy(self):
        live=self.acme/'production/config/live/horus-proxy-ip'
        self.run(['openssl','verify','-CApath','/etc/ssl/certs','-untrusted',live/'chain.pem','-purpose','sslserver','-verify_ip',self.ip,live/'cert.pem'])
        self.run(['openssl','x509','-in',live/'cert.pem','-noout','-checkend','86400'])
        atomic(self.root/'tls/public-ip.crt',(live/'fullchain.pem').read_text(),0o644)
        atomic(self.root/'tls/public-ip.key',(live/'privkey.pem').read_text(),0o600)
        print('Public IP certificate installed; the agent detects its fingerprint and validates a graceful reload.',flush=True)

    def setup(self):
        self.webroot.mkdir(parents=True,exist_ok=True)
        self.acme.chmod(0o755);self.webroot.chmod(0o755)
        self.run(['apt-get','update']);self.run(['apt-get','install','-y','python3-venv'])
        if not self.client.exists():
            self.run(['/usr/bin/python3','-m','venv',self.acme/'venv'])
            self.run([self.acme/'venv/bin/python','-m','pip','install',CERTBOT])
        hook=shlex.join(['/usr/bin/python3',str(self.root/'proxy_ip_tls.py'),'deploy','--root',str(self.root)])
        for staging in [True,False]:
            args=[self.client,'certonly',*self.arguments(staging),'--non-interactive','--agree-tos','--register-unsafely-without-email','--required-profile','shortlived','--webroot','--webroot-path',self.webroot,'--ip-address',self.ip,'--cert-name','horus-proxy-ip']
            if staging:args+=['--staging']
            else:args+=['--deploy-hook',hook]
            self.run(args)
        self.deploy()
        command=shlex.join([str(self.client),'renew',*self.arguments(),'--quiet'])
        service='[Unit]\nDescription=Renew Horus proxy public IP certificate\nAfter=network-online.target horus-proxy-nginx.service\n[Service]\nType=oneshot\nExecStart='+command+'\nUMask=0077\nPrivateTmp=true\nProtectHome=true\nNoNewPrivileges=true\nTimeoutStartSec=900\n'
        timer='[Unit]\nDescription=Check Horus proxy IP certificate renewal every four hours\n[Timer]\nOnCalendar=*-*-* 00,04,08,12,16,20:00:00 UTC\nRandomizedDelaySec=10m\nPersistent=true\n[Install]\nWantedBy=timers.target\n'
        atomic('/etc/systemd/system/horus-proxy-ip-tls.service',service,0o644);atomic('/etc/systemd/system/horus-proxy-ip-tls.timer',timer,0o644)
        self.run(['systemctl','daemon-reload']);self.run(['systemctl','enable','--now','horus-proxy-ip-tls.timer'])
        print('Public IP TLS ready. Select certificate alias public-ip on the proxy IP host. Keep HTTP port 80 reachable for automatic renewal.',flush=True)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['setup','deploy']);parser.add_argument('--root',default='/opt/horus_proxy');args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run as root')
    operation=PublicIPTLS(args.root);getattr(operation,args.action)()

if __name__=='__main__':main()
