"""Horus proxy agent: authenticated configuration, verified TLS and confirmed graceful reloads."""
import argparse
import datetime
import fcntl
import hashlib
import hmac
import http.client
import ipaddress
import json
import os
import pathlib
import secrets
import shutil
import socket
import ssl
import subprocess
import time
import urllib.error
import urllib.request

from proxy_config import certificate_paths, render, validate


def atomic(path, content, mode=0o600):
    path=pathlib.Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    temporary=path.with_name('.'+path.name+'.'+secrets.token_hex(6))
    with open(temporary,'x',encoding='utf-8') as output:
        os.chmod(temporary,mode);output.write(content);output.flush();os.fsync(output.fileno())
    os.replace(temporary,path)


class UnixHTTP(http.client.HTTPConnection):
    def __init__(self,path):super().__init__('localhost',timeout=2);self.path=str(path)
    def connect(self):
        self.sock=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);self.sock.settimeout(self.timeout);self.sock.connect(self.path)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None


class ProxyApplier:
    def __init__(self,root,nginx='/usr/sbin/nginx',systemd=True):
        self.root=pathlib.Path(root);self.nginx=str(nginx);self.systemd=systemd
        self.root.mkdir(parents=True,exist_ok=True)
        self.root.chmod(0o755)
        for name in ['run','logs','tls']:(self.root/name).mkdir(exist_ok=True)
        (self.root/'tls').chmod(0o700)
        for name in ['body','proxy','fastcgi','uwsgi','scgi']:(self.root/'run'/name).mkdir(exist_ok=True)

    def command(self,args):
        answer=subprocess.run(args,capture_output=True,text=True,timeout=20)
        if answer.returncode:
            raise RuntimeError((answer.stderr or answer.stdout or 'COMMAND_FAILED')[-1400:])
        return answer.stdout

    def state(self):
        try:return json.loads((self.root/'state.json').read_text())
        except FileNotFoundError:return {}

    def health(self):
        client=None
        try:
            client=UnixHTTP(self.root/'run/status.sock');client.request('GET','/health');response=client.getresponse();body=response.read(256).decode();client.close()
            return body if response.status==200 else ''
        except (OSError,http.client.HTTPException):return ''
        finally:
            if client:client.close()

    def prepare_certificates(self,wire,generation):
        p=wire['profile']
        if not p['https_ports']:return {}
        certdir=self.root/'tls';ca=certdir/'local-ca.crt';key=certdir/'local-ca.key'
        if not ca.exists():
            self.command(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-sha256','-days','3650','-subj','/CN=Horus Proxy Local CA',
                          '-addext','basicConstraints=critical,CA:TRUE','-keyout',str(key),'-out',str(ca)])
            key.chmod(0o600);ca.chmod(0o644)
        names={p['server_ip']}|{row['host'] for row in p['hosts'] if row['certificate']=='auto'}
        first=next(iter(sorted(names)));cert,private=certificate_paths(self.root,first,'auto',generation)
        if not cert.exists():
            request=cert.with_suffix('.csr');extensions=cert.with_suffix('.ext')
            san=[]
            for name in sorted(names):
                try:ipaddress.ip_address(name);san.append('IP:'+name)
                except ValueError:san.append('DNS:'+name)
            atomic(extensions,'subjectAltName='+','.join(san)+'\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
            self.command(['openssl','req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=Horus Proxy','-keyout',str(private),'-out',str(request)])
            self.command(['openssl','x509','-req','-in',str(request),'-CA',str(ca),'-CAkey',str(key),'-set_serial',str(secrets.randbits(120)+1),
                          '-days','45','-sha256','-extfile',str(extensions),'-out',str(cert)])
            private.chmod(0o600);cert.chmod(0o644);request.unlink();extensions.unlink()
        for name in names:
            target,target_key=certificate_paths(self.root,name,'auto',generation)
            if target!=cert:
                if not target.exists():shutil.copy2(cert,target)
                if not target_key.exists():shutil.copy2(private,target_key)
        fingerprints={}
        for row in p['hosts']:
            if row['certificate']=='auto':continue
            cert,private=certificate_paths(self.root,row['host'],row['certificate'],generation)
            if not cert.is_file() or not private.is_file():raise ValueError('CERTIFICATE_MISSING: '+row['certificate'])
            check='-verify_ip' if ':' in row['host'] or row['host'].replace('.','').isdigit() else '-verify_hostname'
            # x509 -checkhost prints a mismatch but still exits zero; verify fails reliably.
            self.command(['openssl','verify','-trusted',str(cert),'-partial_chain','-purpose','sslserver',check,row['host'],str(cert)])
            self.command(['openssl','x509','-in',str(cert),'-noout','-checkend','86400'])
            fingerprints[row['certificate']]=hashlib.sha256(cert.read_bytes()+private.read_bytes()).hexdigest()
        return fingerprints

    def activate(self,reload):
        if self.systemd:
            self.command(['systemctl','reload' if reload else 'start','horus-proxy-nginx.service'])
        else:
            args=[self.nginx,'-c',str(self.root/'nginx.conf')]
            if reload:args+=['-s','reload']
            self.command(args)

    def apply(self,wire):
        validate(wire)
        if wire['profile'].get('outbound_bind_ip'):
            address=wire['profile']['outbound_bind_ip']
            try:
                with socket.socket(socket.AF_INET6 if ':' in address else socket.AF_INET) as probe:probe.bind((address,0))
            except OSError as error:raise ValueError('OUTBOUND_BIND_IP_NOT_LOCAL') from error
        with open(self.root/'apply.lock','a') as lock:
            os.chmod(self.root/'apply.lock',0o600)
            fcntl.flock(lock,fcntl.LOCK_EX)
            state=self.state();generation=hashlib.sha256((wire['revision']+'|'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m')).encode()).hexdigest()
            fingerprints=self.prepare_certificates(wire,generation)
            effective=hashlib.sha256(json.dumps([wire['revision'],generation,fingerprints],sort_keys=True).encode()).hexdigest()
            if state.get('effective_revision')==effective and self.health()==effective:return False
            config=self.root/'nginx.conf';old=config.read_text() if config.exists() else None;running=bool(self.health())
            system_ca=pathlib.Path('/etc/ssl/certs/ca-certificates.crt').read_text()
            extra=wire.get('upstream_ca','')
            if not isinstance(extra,str) or len(extra)>1048576 or 'PRIVATE KEY' in extra:raise ValueError('INVALID_UPSTREAM_CA')
            atomic(self.root/'tls'/('upstreams-'+wire['revision']+'.pem'),system_ca+'\n'+extra)
            candidate=self.root/'nginx.candidate.conf'
            atomic(candidate,render(wire,self.root,effective_revision=effective,certificate_generation=generation))
            self.command([self.nginx,'-t','-c',str(candidate)])
            candidate.replace(config)
            try:
                self.activate(running)
                for _ in range(40):
                    if self.health()==effective:break
                    time.sleep(.2)
                else:raise RuntimeError('APPLY_NOT_CONFIRMED: old configuration retained or a listener is occupied')
            except Exception:
                if old is not None:
                    atomic(config,old)
                    self.activate(bool(self.health()))
                else:config.unlink(missing_ok=True)
                raise
            atomic(self.root/'state.json',json.dumps({'applied_revision':wire['revision'],'effective_revision':effective,'wire':wire,'applied_at':int(time.time()),'last_error':''}))
            return True


class Agent:
    def __init__(self,root,nginx='/usr/sbin/nginx',systemd=True):
        self.root=pathlib.Path(root);self.enrollment=json.loads((self.root/'enrollment.json').read_text());self.applier=ProxyApplier(root,nginx,systemd)
        if not self.enrollment['main_url'].startswith('https://'):raise ValueError('MAIN_REQUIRES_HTTPS')
        self.key=bytes.fromhex(self.enrollment['key']);self.error='';self.failed_revision='';self.attempted=0
        self.previous_metrics=None
        context=ssl.create_default_context();context.load_verify_locations(cadata=self.enrollment['main_ca'])
        self.http=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=context),NoRedirect())

    def fetch(self):
        state=self.applier.state();sid=self.enrollment['server_id'];nonce=secrets.token_hex(16);stamp=int(time.time()*1000)
        data={'server_id':sid,'generation':self.enrollment['generation'],'applied_revision':state.get('applied_revision',''),
              'last_error':self.error,'stats':self.metrics()}
        body=json.dumps(data,separators=(',',':')).encode()
        message=f'xcvm proxy req v1|{sid}|{stamp}|{nonce}|'+hashlib.sha256(body).hexdigest()
        signature=hmac.new(self.key,message.encode(),hashlib.sha256).hexdigest()
        request=urllib.request.Request(self.enrollment['main_url'],body,headers={'Content-Type':'application/json','X-XCVM-Proxy-Auth':f'{stamp}.{nonce}.{signature}'})
        with self.http.open(request,timeout=15) as response:
            raw=response.read(2*1024*1024+1)
            if len(raw)>2*1024*1024:raise ValueError('MAIN_RESPONSE_TOO_LARGE')
        envelope=json.loads(raw);payload=envelope['payload'];expected=hmac.new(self.key,(f'xcvm proxy resp v1|{sid}|{nonce}|'+payload).encode(),hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected,str(envelope.get('mac',''))):raise ValueError('INVALID_MAIN_SIGNATURE')
        return json.loads(payload)

    def metrics(self):
        cpu=list(map(int,pathlib.Path('/proc/stat').read_text().splitlines()[0].split()[1:9]));total=sum(cpu);idle=cpu[3]+cpu[4]
        memory={line.split(':')[0]:int(line.split()[1])*1024 for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if len(line.split())>=2}
        incoming=outgoing=0
        for interface in pathlib.Path('/sys/class/net').iterdir():
            if interface.name=='lo':continue
            incoming+=int((interface/'statistics/rx_bytes').read_text());outgoing+=int((interface/'statistics/tx_bytes').read_text())
        now=time.monotonic();used=100*(1-memory.get('MemAvailable',memory.get('MemFree',0))/max(1,memory['MemTotal']))
        result={'cpu':0,'total_mem_used_percent':round(used,2),'total_mem':memory['MemTotal'],
                'bytes_received':0,'bytes_sent':0,'bytes_received_total':incoming,'bytes_sent_total':outgoing,
                'uptime':str(datetime.timedelta(seconds=int(float(pathlib.Path('/proc/uptime').read_text().split()[0])))),
                'proxy_ready':bool(self.applier.health()),'sampled_at':int(time.time())}
        if self.previous_metrics:
            old=self.previous_metrics;elapsed=max(.01,now-old[4]);delta=total-old[0]
            result['cpu']=round(max(0,min(100,100*(1-(idle-old[1])/max(1,delta)))),2)
            result['bytes_received']=max(0,int((incoming-old[2])/elapsed));result['bytes_sent']=max(0,int((outgoing-old[3])/elapsed))
        self.previous_metrics=(total,idle,incoming,outgoing,now)
        return result

    def once(self):
        answer=self.fetch()
        if answer.get('revoked'):
            state=self.applier.state()
            if state.get('wire') and not state['wire'].get('_revoked'):
                wire=state['wire'];wire['profile']['enabled']=False;wire['_revoked']=True;wire['revision']=hashlib.sha256(('revoked|'+wire['revision']).encode()).hexdigest();self.applier.apply(wire)
            self.error='ENROLLMENT_REVOKED';return
        wire=answer['config']
        if wire['revision']==self.failed_revision and time.time()-self.attempted<30:return
        self.attempted=time.time()
        try:
            had_error=bool(self.error)
            changed=self.applier.apply(wire);self.error='';self.failed_revision=''
            if changed or had_error:self.fetch()  # acknowledge only after the worker's private health endpoint confirms it
        except Exception as error:
            self.error=str(error)[-1500:];self.failed_revision=wire['revision'];self.fetch()


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--root',default='/opt/horus_proxy');parser.add_argument('--once',action='store_true');args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run the proxy agent as root')
    agent=Agent(args.root)
    while True:
        try:agent.once()
        except Exception as error:
            agent.error=str(error)[-1500:]
            print('Proxy sync/apply failed: '+agent.error,flush=True)
            # Certificate renewal can use the last authenticated configuration while MAIN is unreachable.
            try:
                state=agent.applier.state()
                if state.get('wire'):agent.applier.apply(state['wire'])
            except Exception:pass
        if args.once:break
        time.sleep(5)


if __name__=='__main__':main()
