# Admin playback diagnosis

The admin assistant can use `diagnose_playback` for an exact subscription ID and an optional ordinary live-channel ID. It reports account expiry, activation and administrative state, allowed formats, configured restrictions, bounded connection snapshots, bouquet entitlement, stored stream/server state and recent allowlisted refusal codes.

The report is read-only and returned directly in Arabic or English. It never exposes passwords, source URLs, client IPs or raw request logs. Missing Redis data remains unknown; on-demand idle streams are not treated as failed. Panel data does not prove a client network problem or successful video delivery. Server inspection is limited to ten assignments and logs to ten recent records.

Access requires an admin and the users permission. Connection telemetry, request logs and channel/server details additionally require their existing permissions. Reseller access remains forbidden. Any follow-up supported repair uses the existing separate proposal and confirmation flow.

Examples: “Diagnose playback for subscription 123”; “Subscription 123 buffers on channel 45”. If no channel is specified, `stream_id` is zero. No playback probes or automated repairs are performed.

Validation: `php tests/assistant-playback.php`, assistant permission/management/creation tests, HTTP restrictions and inference protocol tests.
