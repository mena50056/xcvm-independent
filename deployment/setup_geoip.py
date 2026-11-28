"""MAIN-only periodic updates using the administrator's configured MaxMind account."""
import pathlib
import re


def write_units(root, directory):
    root = pathlib.Path(root)
    if not root.is_absolute() or not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(root)):
        raise ValueError('An absolute installation path without whitespace is required')
    directory = pathlib.Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    service = directory / 'xcvm-independent-geoip.service'
    timer = directory / 'xcvm-independent-geoip.timer'
    service.write_text(f'''[Unit]
Description=Update configured official MaxMind databases
After=network-online.target mariadb.service
[Service]
Type=oneshot
User=xcvm_lab
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/runtime/geoip-update.php
TimeoutStartSec=900
Nice=10
IOWeight=10
NoNewPrivileges=true
PrivateTmp=true
UMask=0027
''', encoding='utf-8')
    timer.write_text('''[Unit]
Description=Weekly official GeoIP refresh
[Timer]
OnCalendar=Tue *-*-* 04:20:00 UTC
RandomizedDelaySec=20m
Persistent=true
[Install]
WantedBy=timers.target
''', encoding='utf-8')
    for path in (service, timer):
        path.chmod(0o644)
    return service, timer


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('root')
    parser.add_argument('--units-dir', default='/etc/systemd/system')
    args = parser.parse_args()
    write_units(args.root, args.units_dir)
