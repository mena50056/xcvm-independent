# Developer connection isolation

Applied to the existing independent MAIN as a backed-up hotfix on v0.1.0-lab.7, without changing the distribution version or immutable release assets.

- Panel-log submission is a local no-op and contains no upstream reporting URL.
- Independent builds reject platform catalogues, module downloads/rollbacks, module-license renewal and activation-key submission before platform calls or module file changes.
- The store tab is disabled; the independent page supplies no developer store URL.
- Upstream-owned GitHub release clients and the legacy binary updater are refused. The latter cannot disable AppArmor in an independent build.
- The MAIN host has local /etc/hosts entries for xcvm.tech, www.xcvm.tech and api.xcvm.tech in both address families. This is additional hostname-level protection, not a blanket egress firewall and not a guarantee against arbitrary future domains or direct-IP requests.
- Own GitHub updates, content sources and cluster traffic remain permitted. Other optional integrations are not disabled by this policy.

Validation: PHP lint for every changed file; eight behavioral checks in tests/vendor-disconnect.php locally and on the Linux MAIN; own GitHub API HTTP200; unchanged web/fanout PIDs; current authenticated cluster heartbeats; disabled store confirmed in the authenticated UI.

Release v0.1.0-lab.8 includes complete MAIN/node bundles, MAIN code updates and the fixed LB isolation-policy update. Fresh installer-public-v9 uses .8. Existing MAINs need panel-updates-v5 helpers for the new node-policy catalog. Preserve upstream copyright and license notices: attribution is not background network traffic.

Policy LB assets use `xcvm-independent-node-policy-update.json` and `.tar.gz` (schema 2), so old helpers ignore them rather than incorrectly report successful isolation. The payload still contains exactly the two native ELF files: the trusted helper applies fixed local hostname entries itself. Failed application restores hosts and binaries and keeps the old version marker. Future policy changes must be reviewed in the helper, never supplied as remote commands.
