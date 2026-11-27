"""Authenticated admin calls this with enrollment JSON on stdin. Never write enrollment to source."""
import io,json,pathlib,sys,zipfile

def build(enrollment):
    if enrollment.get('schema')!=1 or len(bytes.fromhex(enrollment['key']))!=32:raise ValueError('INVALID_ENROLLMENT')
    output=io.BytesIO();directory=pathlib.Path(__file__).resolve().parent
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name in ['proxy_agent.py','proxy_config.py','install_proxy.py']:
            archive.writestr(name,(directory/name).read_bytes())
        archive.writestr('enrollment.json',json.dumps(enrollment))
        archive.writestr('README.txt','HORUS PRIVATE PROXY INSTALLER\nKeep this archive private.\nOn a separate Ubuntu 22.04/24.04 server:\n  umask 077\n  unzip horus-proxy-<id>.zip -d /root/horus-proxy-install\n  cd /root/horus-proxy-install\n  python3 install_proxy.py\nAllow only your configured ports in the provider firewall. Point DNS to this proxy.\nAuto TLS uses a local CA, not a publicly trusted certificate. See the admin form and docs/proxy.md.\n')
    return output.getvalue()

if __name__=='__main__':sys.stdout.buffer.write(build(json.load(sys.stdin)))
