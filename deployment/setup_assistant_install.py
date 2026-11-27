"""Dedicated MAIN installer worker; never run remote installation in a web request."""
import pathlib

def write_units(root,directory):
    root=pathlib.Path(root);directory=pathlib.Path(directory);directory.mkdir(parents=True,exist_ok=True)
    service=directory/'xcvm-assistant-install.service';timer=directory/'xcvm-assistant-install.timer'
    service.write_text(f'''[Unit]
Description=Process explicitly confirmed assistant server installations
After=network-online.target
[Service]
Type=oneshot
User=xcvm_lab
Group=xcvm_lab
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/runtime/assistant-install.php
TimeoutStartSec=2700
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
''')
    timer.write_text('''[Unit]
Description=Check confirmed server installations and expire unused credentials
[Timer]
OnBootSec=15
OnUnitInactiveSec=10
AccuracySec=1
[Install]
WantedBy=timers.target
''')
    for file in [service,timer]:file.chmod(0o644)
    return service,timer

if __name__ == '__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('root');parser.add_argument('--units-dir',default='/etc/systemd/system');args=parser.parse_args()
    write_units(args.root,args.units_dir)
