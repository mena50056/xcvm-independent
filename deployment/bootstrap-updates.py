"""One-time public installer for the update service; no fresh panel installation."""
import hashlib
import json
import os
import pathlib
import subprocess
import tempfile
import urllib.request

BASE = 'https://raw.githubusercontent.com/mena50056/xcvm-independent/panel-updates-v4/'
FILES = {
    'panel/src/Public/Controllers/Admin/Ajax/ServerAjaxController.php',
    'deployment/update_worker.py', 'deployment/enable_updates.py', 'deployment/update-ui-manifest.json',
    'panel/src/Core/Updates/IndependentUpdates.php',
    'panel/src/Public/Controllers/Admin/IndependentUpdatesController.php',
    'panel/src/Public/Controllers/Admin/SettingsController.php',
    'panel/src/Public/Views/admin/independent_updates.php',
    'panel/src/Public/Views/admin/header.php', 'panel/src/Public/Views/admin/settings.php',
    'panel/src/Public/routes/admin.php',
    'deployment/node_update.py',
    'panel/src/Core/Updates/IndependentNodeUpdates.php',
    'panel/src/Cli/Commands/IndependentLbInstall.php',
    'panel/src/Domain/Server/ServerService.php',
    'panel/src/Public/Controllers/Admin/ServerInstallController.php',
    'panel/src/Public/Views/admin/servers.php',
    'panel/src/Public/Views/admin/server_install.php',
}


def download(name):
    with urllib.request.urlopen(BASE + name, timeout=60) as response:
        data = response.read(4 * 1024 * 1024 + 1)
    if len(data) > 4 * 1024 * 1024:
        raise ValueError('Oversized updater source')
    return data


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run as root on the installed independent MAIN')
    root = pathlib.Path('/opt/xcvm-independent-lab')
    installed = json.loads((root / 'installed.json').read_text())
    if installed.get('role') != 'main' or installed.get('repository') != 'mena50056/xcvm-independent':
        raise SystemExit('Only an existing independent MAIN is supported')
    manifest = json.loads(download('deployment/update-service-manifest.json'))
    if set(manifest) != FILES:
        raise ValueError('Invalid updater source manifest')
    with tempfile.TemporaryDirectory(prefix='xcvm-enable-updates-') as directory:
        source = pathlib.Path(directory)
        for name in sorted(FILES):
            data = download(name)
            if hashlib.sha256(data).hexdigest() != manifest[name]:
                raise ValueError('Updater source checksum mismatch: ' + name)
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        subprocess.run(['/usr/bin/python3', str(source / 'deployment/enable_updates.py')], check=True)


if __name__ == '__main__':
    main()
