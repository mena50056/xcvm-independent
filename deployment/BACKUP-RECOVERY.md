# Horus encrypted off-site backups

The existing local schedule keeps its SQL backups and SHA-256 sidecars. S3/SFTP delivery now requires the recovery passphrase and sends an authenticated `.sql.horus` export plus an encrypted `.bundle` containing configuration. Dropbox retains its separate legacy workflow; it is not covered by this encrypted delivery path.

Configure a private S3 bucket, HTTPS endpoint, region, prefix, access key and secret in **Backups → Off-site targets**. Use a dedicated prefix for one MAIN. Keep the recovery passphrase in a separate password manager or offline copy. It is never sent to the backup target. Changing it affects new exports only; older copies still need their original passphrase. No S3 account or bucket is provisioned automatically.

Each delivery reads its remote objects back and checks their bytes and SHA-256 before pruning that target. The read-back consumes download bandwidth. A failed target keeps its older copies and the local SQL. The normal backup timer retries the latest failed export with a 15-minute initial cooldown, increasing up to six hours. A local success with off-site failure is shown as partial and returns failure from the cron. Target configuration still needs the administrator's database permission, POST, XHR and session CSRF token.

The independent recovery bundle contains the original master key, identity, encrypted DB/Redis configuration, signing state when present, proxy secret, desired/applied proxy profiles, bounded proxy configuration history, AI endpoint configuration, modules and custom translations. It excludes saved admin login credentials, sessions, pending commands and live proxy samples. It is an application recovery set, not a machine image: media files, executables, OS services and TLS provisioning must be restored separately. Verify proxy origin trust before directing viewers to a recovered MAIN.

Recovery works without booting the lost MAIN or contacting its database:

```sh
php deployment/open-backup.php backup_2026-11-28.sql.horus /private/new-database.sql < /private/recovery-passphrase.txt
php deployment/open-backup.php recovery_2026-11-28.bundle /private/new-configuration < /private/recovery-passphrase.txt
```

Both destinations must be new. Wrong keys, corruption, truncation and extra trailing data are rejected; a failed SQL decryption never publishes the plaintext destination. Review `independent/manifest.json`, import SQL using an account restricted to a new isolated schema, compare persistent table counts and test protected-state decryption before applying the files to a rebuilt MAIN. Do not import over the running production database as a restore drill. Review endpoints, domain/IP bindings and certificate trust before switching traffic.

Validation: `php tests/offsite-recovery.php`, `python3 tests/offsite-http.py` and the existing `tests/independent-backups.php` and `panel/tests/Support/horus-backups.php`. The HTTP fixture verifies real AWS Signature V4 requests, encrypted round trips and retention behavior after failed uploads or corrupt reads; it is not a test against a commercial S3 provider.
