"""Install the existing MAIN backup command's systemd schedule."""
import json
import os
import pathlib
import subprocess


def write_units(root, unit_directory):
    root = pathlib.Path(root)
    units = pathlib.Path(unit_directory)
    units.mkdir(parents=True, exist_ok=True)
    service = f"""[Unit]
Description=Horus scheduled database backups
After=mariadb.service
[Service]
Type=oneshot
User=xcvm_lab
Group=xcvm_lab
UMask=0077
Environment=XCVM_LAB_STATE={root}/.runtime-state/main
Environment=PHPRC={root}/php.ini
Environment=PHP_INI_SCAN_DIR=
ExecStart={root}/panel/src/bin/php/bin/php -c {root}/php.ini {root}/panel/src/console.php cron:backups
TimeoutStartSec=1800
NoNewPrivileges=true
"""
    timer = """[Unit]
Description=Check Horus configured backup schedule
[Timer]
OnBootSec=5min
OnCalendar=*:0/15
Persistent=true
[Install]
WantedBy=timers.target
"""
    for name, contents in [("service", service), ("timer", timer)]:
        path = units / ("xcvm-independent-backups." + name)
        path.write_text(contents)
        path.chmod(0o644)


if __name__ == "__main__":
    root = pathlib.Path("/opt/horus_xc")
    if os.geteuid() != 0 or json.loads((root / "installed.json").read_text())["role"] != "main":
        raise SystemExit("Run as root on MAIN")
    write_units(root, "/etc/systemd/system")
    subprocess.run(["systemctl", "daemon-reload"], check=True)
    subprocess.run(["systemctl", "enable", "--now", "xcvm-independent-backups.timer"], check=True)
    print("Horus backup timer enabled")
