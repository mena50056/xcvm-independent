"""Install the dedicated post-restart verification timer on MAIN."""
import pathlib

def write_units(root,directory):
    root=pathlib.Path(root);directory=pathlib.Path(directory);directory.mkdir(parents=True,exist_ok=True)
    service=directory/'xcvm-proxy-postcheck.service';timer=directory/'xcvm-proxy-postcheck.timer'
    service.write_text(f'''[Unit]
Description=Verify proxy health after a confirmed service operation
After=network-online.target
[Service]
Type=oneshot
User=xcvm_lab
Group=xcvm_lab
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/runtime/proxy-postcheck.php
TimeoutStartSec=120
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
''')
    timer.write_text('''[Unit]
Description=Check pending proxy restart verification
[Timer]
OnBootSec=10
OnUnitInactiveSec=10
AccuracySec=1
[Install]
WantedBy=timers.target
''')
    for p in [service,timer]:p.chmod(0o644)
    return service,timer
