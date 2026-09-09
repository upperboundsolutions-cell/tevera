# TEVERA single-VPS deployment

This Linux Docker Compose package runs the PHP application, worker, scheduler, Caddy HTTPS gateway, Traccar compiled from this repository, and two separate MariaDB databases. It does not use the Windows CMD files. Docker Engine with Compose v2, OpenSSL and util-linux (`flock`) must already be installed. Use the official Docker instructions for your Linux distribution: https://docs.docker.com/engine/install/.

## First installation

1. Upload a reviewed copy of this repository to `/opt/tevera`. Do not upload `.tools`, `platform/.env`, local SQLite files, logs or backups into a public web directory. Keep source files including `platform/composer.lock`, `platform/package-lock.json`, `gradle/`, `src/`, `schema/` and `templates/`.
2. Point a domain A record at the VPS. If you add an AAAA record, IPv6 must also reach the server. Allow TCP 80/443 for HTTPS provisioning and web access. Allow only the GPS ports used by your trackers in the VPS/provider firewall. Default mappings are GT06 5023 TCP/UDP, Teltonika 5027 TCP/UDP and OsmAnd 5055 TCP. Other protocols remain available in the source; add explicit mappings in `compose.yaml` as needed. Do not publish port 8082 or database ports. Docker-published ports require appropriate Docker/provider firewall rules, not just assumptions about UFW.
3. Run:

   ```bash
   cd /opt/tevera
   chmod +x tevera
   ./tevera setup
   ./tevera start
   ./tevera admin
   ./tevera link-traccar
   ./tevera status
   ```

   Setup prompts for domain/email, generates private passwords and APP_KEY, and optionally accepts Paynow credentials without echoing the key. First source builds may take several minutes. `admin` securely prompts for your TEVERA administrator. `link-traccar` first checks existing credentials; only confirm new-account creation for a fresh Traccar database. Wait for Traccar to finish database initialization before linking. Health heartbeats take up to two minutes to appear.
4. Open `https://YOUR_DOMAIN/login`. Platform administrators can open System health, manage companies, set plans and onboard customers.
5. Configure SMTP in `deploy/.env` before using password recovery. Default mail delivery is the log driver, not real email. Paynow stays disabled until you confirm the integration currency, plan prices and public callback reachability and enable `PAYNOW_ENABLED=true`. Run `./tevera start` after environment changes. See `platform/docs/MULTI-TENANT-BILLING.md`.

There is one private configuration file: `deploy/.env`. Compose reads it without executing it as shell code. Restrict access to the deployment folder and Docker daemon; Docker administrators can inspect container environment variables. Never print `docker compose config` into support messages because it expands credentials; use `./tevera config-check` instead. Existing database passwords must not be changed only in the environment file: rotate the corresponding database account first using a controlled procedure.

## Management

| Command | Effect |
| --- | --- |
| `./tevera start` | Build current source, start databases, apply migrations, start services |
| `./tevera stop` | Stop services while retaining all named volumes |
| `./tevera status` | Container status plus authenticated Traccar/database checks and worker/scheduler heartbeats |
| `./tevera logs traccar` | Follow the selected service's recent logs |
| `./tevera backup` | Pause writers, dump both databases, archive files and private configuration, then resume |
| `./tevera update` | Back up, rebuild the source currently on disk, stop writers, migrate and restart |
| `./tevera restore PATH --confirm-replace-data` | Replace databases and stored files from a trusted verified backup; leave writers stopped |

Services use `restart: unless-stopped` and Docker must start at boot (`sudo systemctl enable --now docker`). An explicitly stopped stack remains stopped until `./tevera start`. Restart policies restart exited processes, not merely unhealthy ones: monitor failures externally and inspect `./tevera status`. Logs are rotated at 10 MB × 3 files per container. Unused images and backups are not automatically deleted.

## Backups and recovery

Backups under `deploy/backups/` contain plaintext secrets, personal data and GPS records. Files/directories are restricted by umask 077. Copy completed backups to encrypted off-server storage and monitor available disk space. Each complete backup has SHA256SUMS. Incomplete backups without a valid manifest must not be restored. SHA256 is an integrity check, not authentication; only restore trusted backups. Database dumps intentionally contain database recreation statements. Do not import them into shared unrelated databases.

Backups briefly interrupt the web app and GPS receivers to keep TEVERA's IDs and Traccar's records consistent. Some trackers buffer during outages, but delivery during downtime is device-dependent. Large installations need a database-native backup/replication strategy to reduce downtime.

To schedule nightly backups when installed at `/opt/tevera`:

```bash
sudo cp deploy/tevera-backup.service deploy/tevera-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now tevera-backup.timer
systemctl list-timers tevera-backup.timer
```

For a different installation directory, edit both paths in the service first. Monitor `journalctl -u tevera-backup.service` and arrange off-server copies and failure notifications. The timer is provided but not installed by this coding session.

Recovery drill on an isolated replacement VPS:

1. Install Docker and the exact matching source release. Preserve source archives/image digests separately with each release; database backups alone do not contain application code.
2. Copy the trusted backup's `deploy.env` to `deploy/.env` (mode 600). Keep APP_KEY unchanged or encrypted data cannot be read. Do not point real DNS or trackers at the recovery VPS yet.
3. Build images with `docker compose --env-file deploy/.env -f deploy/compose.yaml build`. Then run `./tevera restore /ABSOLUTE/BACKUP/PATH --confirm-replace-data`. Configuration must match the backup exactly. This is destructive to the target TEVERA volumes.
4. With the matching source in place, run `./tevera start`. Confirm login, company isolation, record counts, device IDs and Traccar authentication. Run a controlled GPS-report test. Cut over DNS and trackers only after validation. Caddy obtains new certificates; its certificate data is not included in the application backup.

An update failure after writers stop leaves them stopped so you can inspect logs and avoid serving a half-migrated application. Do not use `docker compose down -v`: it deletes persistent data. Roll back using the matching previous source plus both databases and stored files, not one database in isolation.

## Existing local data

This package starts NEW databases. It does not automatically import the current Windows SQLite/H2 installation. A migration must convert both TEVERA and Traccar databases together while retaining device IDs, preserve Laravel APP_KEY, copy stored files, and verify counts/relationships before switching devices. Do not create a fresh Traccar database and attach an old frontend database expecting IDs to match.

## Network and validation boundary

Caddy terminates HTTPS and proxies to an unpublished Apache container. `TRUST_DOCKER_PROXY` is enabled only for this topology; do not expose Apache directly with this setting. TEVERA allows HTTP only to the explicitly configured private `traccar:8082` service, while remote API connections still require HTTPS. Databases have only an internal network. Application and Traccar outbound networks allow payment, mail and geocoding access.

The app Docker image installs locked Composer/npm dependencies; the Java image builds the checked-out source with its Gradle wrapper. Base image tags track their maintained release lines, so test updates on staging and pin image digests for reproducible releases. This is a deployment package, not evidence of a completed production installation. Docker is unavailable in the development machine used to prepare it; container builds, HTTPS issuance, GPS ingress and full backup/restore still require an end-to-end test on a Docker host.

References: https://docs.docker.com/compose/how-tos/startup-order/ ; https://caddyserver.com/docs/automatic-https ; https://www.traccar.org/configuration-file/ ; https://www.traccar.org/mysql/.
