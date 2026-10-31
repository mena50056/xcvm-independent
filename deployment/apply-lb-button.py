"""Apply the independent LB-button adapter to an existing independent MAIN only."""
import argparse
import hashlib
import json
import os
import pathlib
import pwd
import shutil
import subprocess
import tempfile
import time
import urllib.request

ROOT=pathlib.Path('/opt/xcvm-independent-lab')
FILES=[
    'deployment/trusted-node.py','deployment/configure.py','deployment/fetch_release.py','deployment/install.py',
    'panel/src/Cli/Commands/IndependentLbInstall.php','panel/src/Domain/Server/ServerService.php',
    'deployment/setup.sh','deployment/repository.json','deployment/bootstrap-main-ip.sh',
    'deployment/native-agent.json','runtime/LocalCore.php',
    'panel/src/Core/Database/QueryHelper.php','panel/src/Domain/Stream/StreamProcess.php',
    'panel/src/Domain/Cluster/ClusterRoute.php',
    'panel/src/Cli/Commands/ServerInstallCommand.php',
]


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--source',type=pathlib.Path,help='Use an already downloaded, pinned source checkout')
    args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run as root')
    installed=json.loads((ROOT/'installed.json').read_text())
    if installed.get('role')!='main' or installed.get('repository')!='mena50056/xcvm-independent' or not (ROOT/'runtime/LocalCore.php').is_file():
        raise SystemExit('Only an existing independent MAIN may be updated')
    os.umask(0o077)
    account=pwd.getpwnam('xcvm_lab')
    backup=pathlib.Path(tempfile.mkdtemp(prefix='xcvm-lb-button-backup-',dir='/root'))
    base='https://raw.githubusercontent.com/mena50056/xcvm-independent/independent-lb-button-v4/'
    def read(name):
        if args.source:return (args.source/name).read_bytes()
        with urllib.request.urlopen(base+name,timeout=30) as response:return response.read()
    manifest=json.loads(read('deployment/lb-button-manifest.json'))
    if set(manifest)!=set(FILES):raise ValueError('Unexpected patch file manifest')
    staged={};old={}
    try:
        # Stage and validate every file before activating any entry point.
        for name in FILES:
            data=read(name)
            if hashlib.sha256(data).hexdigest()!=manifest[name]:raise ValueError('Patch checksum mismatch: '+name)
            target=ROOT/name
            stage=target.with_name(target.name+'.lb-button-new')
            stage.write_bytes(data);stage.chmod(0o600)
            staged[name]=stage
            if name.endswith('.php'):
                subprocess.run([str(ROOT/'panel/src/bin/php/bin/php'),'-c',str(ROOT/'php.ini'),'-l',str(stage)],check=True)
            if target.exists():
                saved=backup/name.replace('/','__');shutil.copy2(target,saved);old[name]=saved
            else:old[name]=None
        for name in FILES:
            stage=staged[name];os.chown(stage,account.pw_uid,account.pw_gid);stage.chmod(0o644)
            stage.replace(ROOT/name)
        # MAIN must consume cache signals from the node's authenticated events.
        monitor=pathlib.Path('/etc/systemd/system/xcvm-independent-monitor.service').read_text()
        if 'User=xcvm_lab' not in monitor:raise ValueError('Unexpected MAIN monitor service')
        signals=monitor.replace('xcvm-independent-monitor','xcvm-independent-signals').replace(str(ROOT/'runtime/monitor.php'),str(ROOT/'panel/src/console.php')+' signals')
        if 'console.php signals' not in signals:raise ValueError('Cannot derive MAIN signal service')
        unit=pathlib.Path('/etc/systemd/system/xcvm-independent-signals.service')
        if unit.exists() and unit.read_text()!=signals:raise ValueError('Existing MAIN signal service differs')
        unit.write_text(signals)
        subprocess.run(['systemctl','daemon-reload'],check=True)
        subprocess.run(['systemctl','enable','--now','xcvm-independent-signals'],check=True)
        subprocess.run(['systemctl','is-active','--quiet','xcvm-independent-signals'],check=True)
        with urllib.request.urlopen('http://127.0.0.1:18080/lab/login',timeout=15) as response:
            if response.status!=200:raise RuntimeError('MAIN login health check failed')
        (ROOT/'lb-button.json').write_text(json.dumps({'revision':'independent-lb-button-v4','installed_at':int(time.time()),'backup':str(backup)},indent=2))
        print('Independent LB button enabled; MAIN login HTTP 200. Backup: '+str(backup))
    except Exception:
        for name,saved in old.items():
            target=ROOT/name
            if saved is None:target.unlink(missing_ok=True)
            else:shutil.copy2(saved,target)
        raise
    finally:
        for stage in staged.values():stage.unlink(missing_ok=True)


if __name__=='__main__':main()
