"""Schedule the existing connection sweep on MAIN (idle HLS, expiry and ended sessions)."""
import pathlib


def write_units(root, unit_directory):
    root = pathlib.Path(root)
    directory = pathlib.Path(unit_directory)
    directory.mkdir(parents=True, exist_ok=True)
    service = directory / 'xcvm-independent-connections.service'
    service.write_text(f'''[Unit]
Description=Horus connection lifecycle sweep
After=mariadb.service redis-server.service xcvm-independent-fanout.service
[Service]
Type=oneshot
User=xcvm_lab
Group=xcvm_lab
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHPRC={root}/php.ini
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/panel/src/console.php cron:users
TimeoutStartSec=120
NoNewPrivileges=true
''')
    timer = directory / 'xcvm-independent-connections.timer'
    timer.write_text('''[Unit]
Description=Horus connection lifecycle schedule
[Timer]
OnBootSec=20
OnUnitInactiveSec=10
AccuracySec=1
Unit=xcvm-independent-connections.service
[Install]
WantedBy=timers.target
''')
    for file in [service, timer]:
        file.chmod(0o644)
    return service, timer
