"""Separate MAIN timer: slow diagnostics must not delay cache maintenance or viewer sweeps."""
import pathlib


def write_units(root, unit_directory):
    root=pathlib.Path(root);directory=pathlib.Path(unit_directory);directory.mkdir(parents=True,exist_ok=True)
    service=directory/'xcvm-independent-proxy-monitor.service'
    service.write_text(f'''[Unit]
Description=Horus proxy port diagnostics
After=network-online.target mariadb.service redis-server.service
[Service]
Type=oneshot
User=xcvm_lab
Group=xcvm_lab
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHPRC={root}/php.ini
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/panel/src/console.php cron:proxy_monitor
TimeoutStartSec=180
NoNewPrivileges=true
PrivateTmp=true
UMask=0077
Nice=10
''')
    timer=directory/'xcvm-independent-proxy-monitor.timer'
    timer.write_text('''[Unit]
Description=Horus proxy monitoring schedule
[Timer]
OnBootSec=30
OnUnitInactiveSec=30
AccuracySec=2
Unit=xcvm-independent-proxy-monitor.service
[Install]
WantedBy=timers.target
''')
    for file in [service,timer]:file.chmod(0o644)
    return service,timer
