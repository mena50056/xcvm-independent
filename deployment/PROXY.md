# Horus independent proxy

The independent proxy uses the distribution's nginx and a Python 3 standard-library
agent. It does not use the old vendor proxy archive, PHP runtime, database password,
MAIN signing private key, or remote shell commands from MAIN.

## Admin workflow

Open **Servers → Proxies → Add proxy**. Register the separate proxy's IP, select
registered upstream servers and a default upstream, then configure:

- Multiple HTTP and HTTPS ports, without overlap. The first port in each list is
  advertised in panel-generated URLs. All configured ports accept traffic.
- Multiple hostnames. `Default upstream` makes aliases; selecting a server per
  hostname implements host-based routing. Both can be used in one profile.
- Total and per-IP simultaneous request limits, optional per-IP request rate and
  burst, worker capacity, idle/read/connect/send/header timeouts and body size.
- IP access, rejection of unknown hosts, HTTP/2, optional access to admin pages,
  explicitly trusted forwarding networks and an optional local outbound IP.
- Optional upstream TLS names and additional **public** certificates / CAs.

Save the profile, then download the private installer as a full administrator.
Saving does not install nginx or prove that the new ports are listening. Advertised
ports, domains, upstream coverage and enabled state remain at the last confirmed
revision until the agent acknowledges a healthy matching configuration. A failed
apply therefore keeps newly generated playback URLs on the working endpoint.
The registered proxy IP is an immutable node identity; register another proxy
instead of changing the IP of an enrolled node. The UI
shows waiting, pending, applied, failed or offline. **Applied** requires an
authenticated acknowledgement after a new nginx worker answers the private Unix
health socket with the expected effective revision. Offline means no successful
authenticated sync for 30 seconds. A bad candidate keeps the previous working
configuration; the UI reports its error.

Only selected upstreams appear in the advanced TLS section. Inputs are structured
values, never arbitrary nginx directives, shell fragments or upstream URLs.
Optimistic revision checks reject an edit based on an older saved form.

## Install on a separate fresh server

Use Ubuntu 22.04 or 24.04, with SSH/root access and outbound HTTPS to MAIN. The
installer refuses a MAIN/streaming installation and a pre-existing unrelated nginx.
Do not run it on MAIN. The private package includes a key for this proxy alone.

```sh
umask 077
unzip horus-proxy-<id>.zip -d /root/horus-proxy-install
cd /root/horus-proxy-install
python3 install_proxy.py
systemctl status horus-proxy-agent horus-proxy-nginx
journalctl -u horus-proxy-agent -n 50
```

The installer temporarily suppresses distro service autostart while installing
packages, restores any existing policy, and installs its own services. It does not
change SSH ports, provider firewalls, UFW rules or DNS. Allow the configured public
ports in the applicable firewalls, point hostnames to the proxy IP, and ensure the
proxy can reach every selected upstream's configured HTTPS port. No MySQL or Redis
listener is needed on the proxy.

The installation root is `/opt/horus_proxy`. `enrollment.json`, `state.json`, TLS
private keys and downloaded packages are private runtime data, never Git files.
Re-running the package for the same proxy restarts its agent; a package for another
proxy / MAIN is refused. Revoke its installer key in the proxy list when needed:
old authentic agents receive a signed revocation and stop accepting new traffic.
Download and install a new package to re-enroll. Deleting a proxy also revokes it.

## TLS and forwarding

The control URL defaults to MAIN's IP and configured HTTPS port. A trusted operator
can override it with one HTTPS URL in `panel/src/config/proxy_main_url`, ending in
`/horus-proxy/v1/sync`. The public certificate in `tls/server.crt` is included as an
additional trust anchor; the agent verifies hostname/IP and the signed response.
Never put MAIN's private key in the package. For upstream streaming nginx verifies
TLS using the configured certificate name. A certificate whose DNS name differs
from the server IP needs that DNS name in **Certificate name**. MAIN's public
certificate is included automatically; private node CAs can be supplied per server.

HTTPS host aliases with certificate `auto` receive a 45-day leaf certificate from
a local proxy CA. The leaf is refreshed monthly. These certificates are useful for
isolated QA; they are **not publicly trusted**. For public HTTPS, provision a
trusted certificate and matching private key locally as
`/opt/horus_proxy/tls/<alias>.crt` and `<alias>.key`, with the key readable only by
root, then enter that alias for each covered host. nginx checks the key pair; the
agent checks hostname coverage and at least one day of remaining validity.
Use your certificate renewal process to replace those files; the agent notices
changed fingerprints and reloads. All certificates must cover their selected host.

Viewer IPs are forwarded only through registered proxy peers. The proxy overwrites
client-supplied forwarding headers and sends its registered IP. Trusted CDN ranges
are empty by default; add only actual networks you operate or trust. Do not add a
catch-all range. If using an alternate egress IP, register it consistently in MAIN;
the optional bind address must exist locally. The original escaped URI and query
are preserved when stripping the authenticated routing prefix. Known backend
redirects are rewritten to the incoming proxy authority.

## Application and failure behavior

The agent pulls signed configuration every five seconds. Each request is HMAC
authenticated, timestamp-bound and replay-protected. Responses are bound to the
request nonce. Old revoked keys can only receive a signed revocation, not current
configuration. A failed candidate backs off for 30 seconds; another revision can
be applied immediately. On a MAIN outage, the last authenticated working config
continues serving and local auto-certificate renewal can continue.

nginx configurations are tested before activation. Reloads are graceful: existing
streams continue on their current worker. An occupied port that passes `nginx -t`
is detected by the revision health check, then the previous config is restored.
Disabling a proxy prevents new requests after apply; use **Close connections** to
close its tracked viewer sessions. Long running streams can delay old-worker exit.
Nginx connection limits count simultaneous HTTP requests, including HTTP/2 streams;
they do not replace subscriber connection limits enforced by MAIN.

## Verification

```sh
php tests/proxy-profiles.php
node --check panel/src/Public/assets/admin/horus/proxy.js
sudo python3 -B deployment/test_proxy_runtime.py
```

The last command uses temporary directories and ephemeral ports. It tests real
TLS nginx upstreams, both host-routing modes, multiple ports, encoded credentials,
forwarded-header spoofing, admin-path rejection, redirects, bad TLS rejection,
occupied-port rollback and an open stream across a graceful reload. Isolated
panel QA also exercises signed HTTP sync/replay, CSRF, stale form revisions,
downloaded enrollment, visible failed/applied transitions and real TS/HLS playback.
No proxy is activated on production by these tests.
