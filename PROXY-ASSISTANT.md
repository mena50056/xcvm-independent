# Admin proxy assistant

The assistant can list registered proxies and available upstream servers, read the saved proxy configuration and bounded latest monitor snapshot, and propose changes to enabled state, numeric limits/timeouts, HTTP/HTTPS port lists, or selected upstream/default server IDs.

Every write requires the existing separate confirmation button, admin servers + edit_server permissions, and an unchanged profile revision. Values use the native ProxyProfile validation. Unrequested fields are preserved. ProxyProfiles.save records the actor in native proxy history and serializes concurrent saves. Session confirmation tokens are single-use.

A successful confirmation means the desired configuration was saved, not that the proxy has applied it. The UI explicitly reports pending acknowledgement. Read status again to verify application; missing/stale monitoring is not proof of a healthy proxy. Viewing status does not trigger a network probe. Monitor snapshots depend on the existing monitoring service and may be unavailable.

Only explicit fields reach the model: no enrollment keys, certificates, raw agent errors, SSH credentials, or generated nginx commands. Proxy installation, deletion, key rotation, session termination and shell commands are not assistant capabilities.

Requires the matching ProxyProfiles, ProxyProfile, ProxyHistory, ProxyMonitor and ProxyDiagnostics libraries. Deployment upgraded those libraries from the repository on the existing panel; it did not change an operational proxy profile.

Checks: tests/assistant-proxy.php exercises the native SQLite-backed profile service, audit history, before/after proposals, limit/port/upstream validation, revoked permissions, replay and concurrent edits. Existing assistant HTTP tests cover admin-only access and CSRF. A disposable disabled proxy verifies the live Arabic chat confirmation without changing an operational proxy.

## Diagnosis and confirmed restart

`diagnose_proxy` runs the bounded native probes and returns a deterministic Arabic/English report. It distinguishes TLS, connection/timeout, upstream, routing, pending configuration and missing/stale diagnostics. A probe observation is not a proven root cause. `restart_proxy` presents the diagnosis, explicit maintenance or recovery reason, the exact nginx service action and viewer interruption warning before confirmation. Recovery requires a relevant observed failure. Maintenance is only for an explicit user restart request.

Confirmation rechecks permissions, recent diagnosis, saved revision, enabled state, matching applied revision and a fresh capable agent. It queues a signed, per-node operation expiring after 120 seconds; it does not claim execution succeeded. Check `proxy_status` for queued, succeeded, failed, unknown, rejected or expired results. Expiration without acknowledgement does not prove the service was never restarted. No automatic retry occurs after an ambiguous execution.

The agent checks command type, generation, revision and expiry; durably records consumption before executing only `systemctl restart horus-proxy-nginx.service`, syntax-checks nginx first, then verifies its private health endpoint. A process interruption produces an unknown result rather than another restart. Commands and outcomes are retained in private per-proxy operation records with actor and diagnosis evidence. Old agents remain usable for configuration but cannot accept restart commands until updated.

Validation includes signed sync tests, permission/confirmation/concurrency checks, agent failure/replay tests and an isolated real-nginx restart/PID/health test. Production acceptance tests diagnose and preview/cancel a restart; they do not restart the live proxy service.
