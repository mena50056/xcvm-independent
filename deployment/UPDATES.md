# Independent MAIN GitHub updates

The MAIN checks the public `mena50056/xcvm-independent` releases every hour.
Full administrators use the existing **Update Server** control in Settings and can request an
immediate check or press **Update now**. Release detection never installs an
update automatically. No GitHub token or vendor activation key is involved.

## Existing MAIN: enable once

Run as root on the already installed independent MAIN:

```bash
python3 -c 'import urllib.request; exec(compile(urllib.request.urlopen("https://raw.githubusercontent.com/mena50056/xcvm-independent/panel-updates-v3/deployment/bootstrap-updates.py",timeout=60).read(),"bootstrap-updates.py","exec"))'
```

This installs the update page and timer. It follows the panel language and theme, without an extra global banner. It backs up the affected UI files and
restarts the web service. It does not reinstall the panel. Fresh installations
using `installer-public-v7` enable this service automatically.

Open `https://SERVER_IP/lab/settings#panel-update`. Requests run within a minute.
The root-owned status is `/var/lib/xcvm-independent-updates/status.json`;
backups are in the same directory's `backups/`. Worker logs are available with
`journalctl -u xcvm-independent-updates.service`. The timer is
`xcvm-independent-updates.timer`.

## Publishing future versions

Only published releases containing **both** `xcvm-independent-update.json`
and `xcvm-independent-update.tar.gz` are advertised. Source commits and native
binary releases are not panel updates. Tags use `vX.Y.Z` or `vX.Y.Z-lab.N`;
matching prereleases are included because this installation is an independent lab.

Build from a clean, reviewed checkout:

```bash
python3 deployment/build_update.py --source . --output dist/update --version v0.1.0-lab.6 --from-version v0.1.0-lab.5
```

Attach the resulting JSON and tarball to that exact version's GitHub Release,
then publish it. Set `--from-version` for each tested compatible installed
version. Keep published tags and assets immutable. The package builder includes
only tracked, allowlisted application code and admin assets.

The worker downloads anonymously over verified HTTPS and checks the repository,
role, version, compatibility, payload SHA256 and each file's SHA256. Web users
cannot supply URLs, commands or paths. Only full administrators can queue an
update, protected by a session CSRF token. The root worker is installed outside
the panel; code writes and PHP lint run as `xcvm_lab`.

This first updater supports **MAIN application code and UI only**. It does not
update nodes, proxy software, the updater itself, native agents/fanout/PHP,
vendor dependencies or the database. Configuration, identities, certificates,
content and private credentials are excluded. Releases requiring database
migrations are refused. Packages are overlays: removal of obsolete files needs
a separate migration mechanism. A release that requires an intermediate version
must list its compatibility accurately; install the supported intermediate first.

Before replacement it stages and lints files and saves originals. It restarts
only `xcvm-independent-lab` and checks the login endpoint. A failed application
or health check restores the files and restarts the previous web code. This is
a code rollback, not a database or full-server restore. The web interface can
briefly disconnect during the restart; no fanout or node restart is requested.

Automated checks cover release filtering, versions, forbidden paths, tampering,
archives, compatibility, successful updates, failed-health rollback, administrator
permissions, persistent CSRF and HTML escaping. Linux CI also exercises real PHP
lint and file replacement using an unprivileged panel account. A real installed
MAIN upgrade still needs deployment verification; fixture tests are not evidence
of a live-server upgrade.
