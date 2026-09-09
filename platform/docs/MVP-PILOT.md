# Before hosting: local pilot operations

Implemented locally:

- Getting started page: scoped setup progress and links to vehicle, tracker,
  notification and billing setup. Physical route checks stay explicitly manual.
- Per-account notification preferences for geofence entry/exit, recorded
  overspeed, offline and tracker alarm events. Only accessible active vehicles
  and eligible accounts are processed. Events are deduplicated per recipient.
- Notification delivery history distinguishes local preview, transport acceptance,
  and uncertain outcomes. SMTP must be configured for real delivery. No test
  email is sent to another person by this implementation.
- Scheduler polls the previous ten minutes. Events before the latest preference
  change are skipped. Longer outages are not backfilled automatically. This is
  suitable for a small pilot; polling is per subscribed user/device and needs
  batching before large fleets. No SMS/push delivery is implemented.
- Local XAMPP backup helper and isolated restore rehearsal for both databases.
- Health checks every five minutes log failures locally. External email/on-call
  monitoring is not configured.

Run `Start-TEVERA.cmd` to start the web app, scheduler and queue worker. These
local background processes stop at reboot; run the launcher again. XAMPP MySQL
and the Traccar process must also be running. Docker uses its existing services.

Local backups run at 02:00 in the app timezone while the scheduler is running:

```powershell
& .tools/php83/php.exe tools/backup-local.php
& .tools/php83/php.exe tools/backup-local.php --verify-restore
```

Backups are private under `platform/storage/app/private/backups`. The restore
check uses XAMPP's local root account with an empty password to create randomly
named disposable databases; it never overwrites live databases. If root is
secured, adapt that local verification connection before running it. Normal
dumps use each application's restricted database account, with temporary option
files removed afterward. This helper is for the existing local XAMPP setup.

Each database uses a consistent transactional dump. The two dumps are separate
snapshots, not an atomic cross-database snapshot. The manifest records hashes and
restored row counts. Protect copies of the private app environment, tracker
configuration and uploads separately; SQL dumps alone are not a full deployment
backup. Copy backups to another machine, monitor free space, and set retention
before production. The Docker deployment has its separate full backup workflow.

Still requires external configuration or manual verification:

- Confirm plan pricing/currency and Paynow settings before live checkout.
- Configure SMTP and verify an authorized recipient receives mail.
- Connect a physical tracker and verify movement and geofence transitions.
- Browser/mobile visual and interaction checks require a connected browser.
- VPS deployment, TLS, automatic restart after boot, external monitoring and
  off-machine backup storage remain deployment work.

No claim of production readiness or complete Traccar feature parity is made.
