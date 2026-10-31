# Independent MAIN GitHub updates

The MAIN checks the public `mena50056/xcvm-independent` releases every hour.
Full administrators use the existing **Update Server** control in Settings and can request an
immediate check or press **Update now**. Release detection never installs an
update automatically. No GitHub token or vendor activation key is involved.

## Existing MAIN: enable once

Run as root on the already installed independent MAIN:

```bash
python3 -c 'import urllib.request; exec(compile(urllib.request.urlopen("https://raw.githubusercontent.com/mena50056/xcvm-independent/panel-updates-v4/deployment/bootstrap-updates.py",timeout=60).read(),"bootstrap-updates.py","exec"))'
```

This installs the update page and timer. It follows the panel language and theme, without an extra global banner. It backs up the affected UI files and
restarts the web service. It does not reinstall the panel. Fresh installations
using `installer-public-v8` enable this service automatically.

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

The MAIN updater supports **MAIN application code and UI**. It does not
update proxy software, the updater itself, native MAIN agents/fanout/PHP,
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

## Independent LB updates

The MAIN service also checks published native LB update manifests hourly. The
original navbar update icon shows a count of eligible MAIN/LB updates. In Servers,
Update is enabled only when the compatible release is strictly newer than that
LB's verified independent distribution version. Native agent version numbers are
not substituted for the distribution version. Same or newer installed versions
stay disabled; unknown old nodes expose Check for updates first.

An existing LB's version is verified through the panel's normal pinned SSH flow,
using single-use root credentials. Update uses that same SSH form. The helper
rechecks the actual remote version and GitHub compatibility before writing any
files. It updates only xc_agent and xc_fanout, verifies the archive and both
SHA256 hashes, preserves identities/configuration/content, and rolls back binaries
on failed service/control-socket health. MAIN must receive a fresh authenticated
heartbeat before reporting success. Only changed binaries trigger service
restarts; changing fanout can interrupt viewers, so choose a suitable time.

Newly installed LBs record the version of their actual package automatically.
Older LBs need one SSH version check; their version is never guessed. No SSH
passwords are kept for future updates. Proxy and bulk updates remain outside this
independent path; the legacy bulk button is disabled.

Existing MAINs need the one-time service bootstrap v4 to enable the root-owned
LB release catalog and helpers, even if the MAIN code is already updated. Fresh
installer-public-v8 MAINs enable it automatically. The initial v0.1.0-lab.7 native
package uses the same tested binaries as the v0.1.0-lab.4 node bundle. It establishes
the managed distribution version and will not restart identical binaries.

To build a future LB update from a reviewed/tested native bundle:

```bash
python3 deployment/build_node_update.py --source-payload dist/node-bundle.tar.gz --output dist/update --version v0.1.0-lab.8 --from-version v0.1.0-lab.7
```

Publish both `xcvm-independent-node-update.json` and
`xcvm-independent-node-update.tar.gz` in that exact release. Never put PHP, shell
scripts, tokens, certificates or enrollment files in a node update. Fixture tests
cover node success, tampering, rollback and preserving identity; these do not
constitute a live LB update or HLS test.


## Vendor-isolation release .8

Use `panel-updates-v5/deployment/bootstrap-updates.py` once on older MAINs to install the new trusted helpers and isolation guards. Fresh `installer-public-v9` includes these helpers automatically and downloads complete .8 MAIN/node bundles. The LB button pins .8.

Schema-2 LB policy assets have separate names (`xcvm-independent-node-policy-update.json` / `.tar.gz`). Old helpers ignore them. New helpers verify both ELF hashes and apply only the hard-coded vendor-hostname isolation policy; no configuration, identity or content is replaced. The .8 binaries are identical to .4/.7 and do not require agent/fanout restarts. The hosts snapshot is restored on failed policy application. Own GitHub and cluster/source URLs are not blocked.

Build with `build_node_update.py --vendor-isolation` and `build_update.py --vendor-isolation`. The policy is part of fresh setup for either role. Never overwrite older release assets or tags.
