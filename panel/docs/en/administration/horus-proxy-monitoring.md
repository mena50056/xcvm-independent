# Horus proxy monitoring

Open **Servers → Proxies**. The operations center separates authenticated agent
heartbeats, active viewers in the connection store, Nginx connections (including
idle connections), and network diagnostics. A connected agent alone does not
prove a public port works.

Each check originates on MAIN and contacts every port in the last confirmed
profile. It verifies TCP, HTTPS certificate/name, the expected origin, a fresh
nonce and the nginx configuration revision. Requests use a fixed, credential-free
origin endpoint. They never start a stream or log in as a subscriber. These are
route checks, not video decoding tests. An origin without the diagnostics endpoint
is reported as unavailable, never as successfully tested. Native serve-only nodes
need equivalent origin endpoint support before their routes can pass this check.

The probe uses the proxy IP (or the first configured hostname when IP access is
disabled), pins that hostname to the registered proxy IP and follows no redirects.
Additional host aliases are not exhaustively checked by this version. TLS checking
always remains enabled; an optional public CA PEM supports private lab certificates.
Ports or URLs supplied in a diagnostic API request are ignored: only saved,
confirmed targets can be contacted.

## Scheduling and incidents

`cron:proxy_monitor` runs as `xcvm_lab`, separately from cache maintenance and the
viewer reaper. The installation generates and enables
`xcvm-independent-proxy-monitor.timer`, which wakes every 30 seconds. Each proxy
has its own interval (60 seconds by default), bounded per-port timeout and
consecutive-failure/recovery thresholds (two by default). At most eight probes
run concurrently per proxy; manual checks reuse results less than ten seconds old.
Page refreshes read snapshots without probing or changing incident counters.
The scheduler visits the oldest checks first and stops starting new work after
90 seconds, leaving room for the last bounded batch inside its 180-second service
timeout. A large set of unavailable proxies therefore rotates across runs.

Incidents cover port/route/TLS failures, missing heartbeats, failed configuration
application, approaching certificate expiry and sustained CPU/memory use of 90%
or more. Acknowledging records the operator and time without resolving the fault.
Recovery is retained in the timeline. Old observations are marked stale; a missing
monitor timer cannot leave an indefinitely green display. Alerts stay inside the
panel; no external email, chat or messaging integration is enabled.

The private store retains the latest 120 check samples, 100 resolved incidents
(plus all active incidents), and 200 incident transitions per proxy. Snapshots and
locks live beside profiles under `config/horus_proxy`, outside public assets.

## Configuration history

Successful saves record the actor, timestamp, a redacted field diff, and a private
restorable profile. The previous configuration is captured on the first edit of
an older installation. History starts with this feature; it does not invent past
changes. The last 100 versions are retained. Public CA contents are represented
by fingerprints in the browser; private keys and enrollment secrets are absent.

Restoring requires the current saved revision and the normal edit permission and
session CSRF token. It creates a new desired revision. Existing routing and probes
continue using the last healthy, acknowledged configuration until the agent
confirms the replacement. Concurrent edits fail with `PROFILE_CONFLICT`.

Read-only server admins can view monitoring/history. Manual diagnostics,
acknowledgments and restores require `edit_server`; they use POST and session-bound
CSRF validation. Diagnostic requests release the PHP session lock before I/O.

## Upgrade checklist

Deploy the reviewed MAIN source and the proxy agent/renderer together. Agent
renderer generation 2 reloads nginx to install the upstream revision header.
Installing only the UI does not supply that header or the origin endpoint.
Keep the existing proxy enrollment, certificates, data and firewall configuration.
No business database schema migration is required.

For an existing MAIN installation, generate the separate schedule with
`deployment.setup_proxy_monitor.write_units(root, '/etc/systemd/system')`, reload
systemd and enable the timer after the application code is ready. Verify a manual
check and a timer-driven check on all ports before declaring rollout complete.
No extra public management listener is introduced. A real TS/HLS playback test
and a check of proxy-only admission remain separate rollout checks.

Regression checks:

```sh
php tests/proxy-profiles.php
php tests/proxy-monitor.php
python3 tests/proxy-monitor-http.py
sudo python3 deployment/test_proxy_monitor.py
node --check panel/src/Public/assets/admin/horus/proxy-monitor.js
```
