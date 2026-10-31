# Horus installation paths

Fresh .9 MAIN/node bundles use /opt/horus_xc and the MAIN UI prefix /horus_xc/. installer-public-v10 and panel-updates-v6 are the matching source tags.

Existing installations need an explicit migration, not a fresh install. deployment/migrate_horus.py takes a reviewed source tree and a SHA256 manifest. It clones the existing admin access-code settings, validates PHP as the panel account, keeps identity files, atomically exchanges the installation directory and a compatibility symlink with Linux renameat2, rewrites service/configuration paths and reloads systemd definitions without restarting services. The legacy /opt/xcvm-independent-lab and /home/xc_vm paths remain compatibility aliases. /lab/ redirects with method-preserving HTTP307. Viewer and cluster endpoints do not change.

The .9 release deliberately has no ordinary in-place update asset: this path/access-code migration must not be delivered as an application-only update. Later update helpers detect the canonical root, with a fallback for unmigrated instances. Existing version markers are unchanged by the namespace migration itself. No credentials or cluster identities are regenerated.
