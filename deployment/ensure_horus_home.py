"""Create the canonical Horus home alias; preserve legacy callers without restarts."""
import pathlib,os,json
ROOT=pathlib.Path('/opt/horus_xc')
HOME=pathlib.Path('/home/horus_xc')
LEGACY=pathlib.Path('/home/xc_vm')

def ensure_home(root=ROOT,home=HOME,legacy=LEGACY):
    target=root/'panel/src'
    if not target.is_dir():raise ValueError('Horus panel directory is missing')
    for path in [home,legacy]:
        if os.path.lexists(path) and (not path.is_symlink() or path.resolve()!=target.resolve()):
            raise ValueError('Refusing an occupied home path: '+str(path))
    if not os.path.lexists(home):home.symlink_to(target)
    if not os.path.lexists(legacy) or os.readlink(legacy)!=str(home):
        temp=legacy.with_name('.xc_vm.horus-home-'+str(os.getpid()))
        temp.symlink_to(home);temp.replace(legacy)
    assert home.resolve()==target.resolve() and legacy.resolve()==target.resolve()
    return {'home':str(home),'target':str(target),'legacy_is_compatibility_alias':True}

if __name__=='__main__':
    if os.geteuid()!=0:raise SystemExit('Run as root')
    print(json.dumps(ensure_home()))
