"""Install the independent GitHub update UI and root-owned timer on existing MAIN."""
import hashlib
import json
import os
import pathlib
import pwd
import shutil
import subprocess
import tempfile
import time

ROOT = pathlib.Path('/opt/xcvm-independent-lab')
STATE = pathlib.Path('/var/lib/xcvm-independent-updates')
SOURCE = pathlib.Path(__file__).resolve().parents[1]
FILES = ['panel/src/Public/Controllers/Admin/Ajax/ServerAjaxController.php', 'panel/src/Core/Updates/IndependentUpdates.php', 'panel/src/Public/Controllers/Admin/IndependentUpdatesController.php', 'panel/src/Public/Controllers/Admin/SettingsController.php', 'panel/src/Public/Views/admin/independent_updates.php', 'panel/src/Public/Views/admin/header.php', 'panel/src/Public/Views/admin/settings.php', 'panel/src/Public/routes/admin.php', 'panel/src/Core/Updates/IndependentNodeUpdates.php', 'panel/src/Cli/Commands/IndependentLbInstall.php', 'panel/src/Domain/Server/ServerService.php', 'panel/src/Public/Controllers/Admin/ServerInstallController.php', 'panel/src/Public/Views/admin/servers.php', 'panel/src/Public/Views/admin/server_install.php', 'panel/src/Core/Diagnostics/DiagnosticsService.php', 'panel/src/Core/Module/ModuleStore.php', 'panel/src/Core/Module/ModuleManager.php', 'panel/src/Public/Controllers/Admin/Ajax/MiscAjaxController.php', 'panel/src/Cli/CronJobs/ModuleLicensesCronJob.php', 'panel/src/Cli/Commands/BinariesCommand.php', 'panel/src/Public/Controllers/Admin/ModulesController.php', 'panel/src/Public/Views/admin/modules.php', 'panel/src/Public/Views/admin/dashboard.php', 'panel/src/Public/Controllers/Admin/Ajax/ModuleAjaxController.php', 'panel/src/Core/Updates/GitHubReleases.php']


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run as root')
    installed = json.loads((ROOT / 'installed.json').read_text())
    if installed.get('role') != 'main' or installed.get('repository') != 'mena50056/xcvm-independent' or not (ROOT / 'runtime/LocalCore.php').is_file():
        raise SystemExit('Only an existing independent MAIN is supported')
    account = pwd.getpwnam('xcvm_lab')
    os.umask(0o077)
    STATE.mkdir(mode=0o755, exist_ok=True)
    STATE.chmod(0o755)
    requests = STATE / 'requests'
    if requests.is_symlink():
        raise SystemExit('Invalid update request directory')
    requests.mkdir(mode=0o700, exist_ok=True)
    os.chown(requests, account.pw_uid, account.pw_gid)
    worker = pathlib.Path('/usr/local/lib/xcvm-independent-updates')
    worker.mkdir(parents=True, mode=0o755, exist_ok=True)
    shutil.copyfile(SOURCE / 'deployment/update_worker.py', worker / 'worker.py')
    (worker / 'worker.py').chmod(0o644)
    # The service's import uses a fixed root-owned copy, outside the web account.
    shutil.copyfile(SOURCE / 'deployment/update_worker.py', worker / 'update_worker.py')
    shutil.copyfile(SOURCE / 'deployment/node_update.py', worker / 'node_update.py')
    (worker / 'update_worker.py').chmod(0o644)
    (worker / 'node_update.py').chmod(0o644)
    from update_worker import panel_user, atomic, target_path, isolate_vendor
    isolate_vendor()
    for name in ['deployment/update_worker.py', 'deployment/node_update.py']:
        data = (SOURCE / name).read_bytes()
        with panel_user():
            atomic(target_path(name), data)
    manifest = json.loads((SOURCE / 'deployment/update-ui-manifest.json').read_text())
    if set(manifest) != set(FILES):
        raise ValueError('Invalid UI manifest')
    backup = STATE / 'backups' / ('enable-' + str(time.time_ns()))
    backup.mkdir(parents=True, mode=0o700)
    old = {}
    # Validate every source file before touching the running interface.
    for name in FILES:
        if hashlib.sha256((SOURCE / name).read_bytes()).hexdigest() != manifest[name]:
            raise ValueError('UI source checksum mismatch: ' + name)
        subprocess.run(['runuser', '-u', 'xcvm_lab', '--', str(ROOT / 'panel/src/bin/php/bin/php'), '-n', '-l'],
                       input=(SOURCE / name).read_bytes(), check=True, stdout=subprocess.DEVNULL, timeout=30)
        target = ROOT / name
        if target.is_symlink():
            raise ValueError('Unexpected UI symlink')
        old[name] = target.exists()
        if target.exists():
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, saved)
    from update_worker import panel_user, atomic, target_path, health, restart, stop_web
    try:
        stop_web()
        for name in FILES:
            data = (SOURCE / name).read_bytes()
            with panel_user():
                target = target_path(name)
                target.parent.mkdir(parents=True, exist_ok=True)
                atomic(target, data)
        restart()
        health()
    except Exception:
        for name, existed in old.items():
            data = (backup / name).read_bytes() if existed else None
            with panel_user():
                target = target_path(name)
                if existed:
                    atomic(target, data)
                else:
                    target.unlink(missing_ok=True)
        restart()
        raise
    if not (STATE / 'version.json').exists():
        atomic(STATE / 'version.json', json.dumps({'version': installed['version']}).encode())
    pathlib.Path('/etc/systemd/system/xcvm-independent-updates.service').write_text('''[Unit]
Description=Independent GitHub release check and requested MAIN update
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/xcvm-independent-updates/worker.py
TimeoutStartSec=900
UMask=0022
PrivateTmp=true
''')
    pathlib.Path('/etc/systemd/system/xcvm-independent-updates.timer').write_text('''[Unit]
Description=Process update requests every minute; check GitHub hourly
[Timer]
OnBootSec=30
OnUnitInactiveSec=60
[Install]
WantedBy=timers.target
''')
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'enable', '--now', 'xcvm-independent-updates.timer'], check=True)
    subprocess.run(['systemctl', 'start', 'xcvm-independent-updates.service'], check=True)
    print('GitHub update checks enabled. Open /lab/settings#panel-update as a full administrator.')


if __name__ == '__main__':
    main()
