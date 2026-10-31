"""Explicit Horus path migration. Keeps live process paths valid without restarts."""
import argparse,ctypes,errno,hashlib,json,os,pathlib,pwd,shutil,subprocess,time
OLD=pathlib.Path('/opt/xcvm-independent-lab')
NEW=pathlib.Path('/opt/horus_xc')

def exchange_root(old,new):
    if old.is_symlink() or not old.is_dir() or os.path.lexists(new):
        raise ValueError('Unexpected source or occupied Horus directory')
    if old.parent.stat().st_dev != new.parent.stat().st_dev:
        raise ValueError('Atomic migration requires one filesystem')
    # After RENAME_EXCHANGE this link is at OLD and points to NEW's real tree.
    new.symlink_to(new)
    libc=ctypes.CDLL(None,use_errno=True)
    try:
        fn=libc.renameat2
        fn.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
        fn.restype=ctypes.c_int
        if fn(-100,os.fsencode(old),-100,os.fsencode(new),2)!=0:
            raise OSError(ctypes.get_errno(),os.strerror(ctypes.get_errno()))
    except Exception:
        if new.is_symlink() and os.readlink(new)==str(new):new.unlink()
        raise

def place(path,data,mode=None):
    st=path.stat() if path.exists() else None
    temp=path.with_name('.'+path.name+'.horus-stage')
    temp.write_bytes(data)
    if st:os.chown(temp,st.st_uid,st.st_gid)
    temp.chmod(mode if mode is not None else (st.st_mode&0o777 if st else 0o644))
    temp.replace(path)

def main():
    p=argparse.ArgumentParser();p.add_argument('--source',type=pathlib.Path,required=True);p.add_argument('--manifest',type=pathlib.Path,required=True);a=p.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run as root')
    migrated=NEW.is_dir() and not NEW.is_symlink() and OLD.is_symlink() and OLD.resolve()==NEW
    if not migrated and (OLD.is_symlink() or not OLD.is_dir() or os.path.lexists(NEW)):
        raise ValueError('Expected exactly the existing independent installation')
    root=NEW if migrated else OLD
    installed=json.loads((root/'installed.json').read_text());role=installed['role']
    if installed.get('repository')!='mena50056/xcvm-independent' or role not in ['main','node']:raise ValueError('Wrong installation identity')
    manifest=json.loads(a.manifest.read_text())
    for name,digest in manifest.items():
        rel=pathlib.PurePosixPath(name)
        if rel.is_absolute() or '..' in rel.parts or not name.startswith(('deployment/','runtime/','panel/src/Cli/Commands/')):raise ValueError('Unexpected migration source')
        source=a.source/name
        if source.is_symlink() or hashlib.sha256(source.read_bytes()).hexdigest()!=digest:raise ValueError('Source checksum mismatch')
    units=['xcvm-independent-fanout','xcvm-independent-agent'] if role=='node' else ['xcvm-independent-lab','xcvm-independent-fanout','xcvm-independent-monitor','xcvm-independent-signals','xcvm-independent-fanout-sync']
    def processes():return subprocess.check_output(['systemctl','show',*units,'-p','Id','-p','MainPID','-p','ActiveState'],text=True)
    before=processes();backup=pathlib.Path('/root/horus-path-migration-'+str(time.time_ns()));backup.mkdir(mode=0o700)
    identity=[root/'panel/src/config/cluster/agent.json'] if role=='node' else [root/'.runtime-state/main/master.key',root/'.runtime-state/main/identity.json']
    hashes={str(x.relative_to(root)):hashlib.sha256(x.read_bytes()).hexdigest() for x in identity}
    php=root/'panel/src/bin/php/bin/php'
    for name in manifest:
        if name.endswith('.php') and role=='main':subprocess.run(['runuser','-u','xcvm_lab','--',str(php),'-n','-l'],input=(a.source/name).read_bytes(),check=True,stdout=subprocess.DEVNULL)
    if role=='main':
        code="""<?php
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage());exit(1);});
require '/opt/xcvm-independent-lab/runtime/bootstrap.php';
$p=XC_VM::db_connect();$q=$p->query("SELECT * FROM access_codes WHERE code='horus_xc'");$new=$q->fetch(PDO::FETCH_ASSOC);
if(!$new){$old=$p->query("SELECT * FROM access_codes WHERE code='lab'")->fetch(PDO::FETCH_ASSOC);if(!$old)throw new RuntimeException('Legacy access code missing');unset($old['id']);$old['code']='horus_xc';$cols=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($old));$p->prepare('INSERT INTO access_codes ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($old),'?')).')')->execute(array_values($old));}
else {if((int)$new['type']!==0 || !$new['enabled'])throw new RuntimeException('Conflicting Horus access code');}
echo 'Horus admin access code ready';
"""
        env=dict(os.environ,XCVM_LAB_STATE=str(root/'.runtime-state/main'),PHP_INI_SCAN_DIR='')
        subprocess.run(['runuser','-u','xcvm_lab','--','env','XCVM_LAB_STATE='+str(root/'.runtime-state/main'),'PHP_INI_SCAN_DIR=',str(php),'-c',str(root/'php.ini')],input=code.encode(),check=True,stdout=subprocess.DEVNULL,env=env)
    for name in manifest:
        if role=='node' and not name.startswith('deployment/'):continue
        path=root/name
        if path.exists():
            b=backup/name;b.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(path,b)
        path.parent.mkdir(parents=True,exist_ok=True)
        place(path,(a.source/name).read_bytes())
        account=pwd.getpwnam('xcvm_lab');os.chown(path,account.pw_uid,account.pw_gid)
    configs=list(pathlib.Path('/etc/systemd/system').glob('xcvm-independent-*.service'))
    configs += [root/'php.ini',root/'deployment/maintenance.sh',pathlib.Path('/etc/nginx/sites-available/xcvm-independent')]
    for idx,path in enumerate(configs):
        if not path.is_file():continue
        text=path.read_text()
        if str(OLD) not in text:continue
        shutil.copy2(path,backup/('config-'+str(idx)));place(path,text.replace(str(OLD),str(NEW)).encode())
    if role=='main':
        login=root/'.runtime-state/main/admin-login.json'
        if login.is_file():
            data=json.loads(login.read_text());data['url']=str(data['url']).replace('/lab/','/horus_xc/');place(login,json.dumps(data,indent=2).encode(),0o600)
        worker=pathlib.Path('/usr/local/lib/xcvm-independent-updates')
        for name,source in [('worker.py','update_worker.py'),('update_worker.py','update_worker.py'),('node_update.py','node_update.py')]:
            target=worker/name;shutil.copy2(target,backup/('root-'+name));place(target,(a.source/'deployment'/source).read_bytes(),0o644);os.chown(target,0,0)
    if not migrated:exchange_root(OLD,NEW)
    from ensure_horus_home import ensure_home
    ensure_home()
    subprocess.run(['systemctl','daemon-reload'],check=True)
    after=processes();assert before==after,'A running service changed during migration'
    for name,digest in hashes.items():assert hashlib.sha256((NEW/name).read_bytes()).hexdigest()==digest,'Identity changed'
    result={'role':role,'root':str(NEW),'legacy_root_is_alias':OLD.is_symlink(),'services_unchanged':True,'identity_unchanged':True,'backup':str(backup),'services':after}
    print(json.dumps(result))
if __name__=='__main__':main()
