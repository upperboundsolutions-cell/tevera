# Installation and operations

This installs Phase 1. It does not deploy the future map, reports or live relay. All commands below run inside `platform/` unless specified. The existing Traccar server remains separate.

## Requirements

- PHP 8.3+ with ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, PDO, pdo_mysql, session, tokenizer and xml; zip for Composer; pdo_sqlite for isolated tests.
- Composer 2, Node.js 22.12+ and npm.
- A supported MySQL 8 or MariaDB release.
- A reachable Traccar server and dedicated service account; SMTP credentials for password recovery.
- HTTPS for production.

Laravel 12 is used with an explicit PHP 8.3 minimum. Commit both dependency lockfiles. Run `composer audit` and `npm audit` during releases. Review framework support dates before production rollout.

## Ubuntu 24.04 example

Install OS packages (requires a privileged shell):

```bash
sudo apt update
sudo apt install php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-sqlite3 unzip git mariadb-server nginx
```

Install Composer using its [official verified installer](https://getcomposer.org/download/) and Node.js 22.12+ using the [official distribution](https://nodejs.org/en/download). Verify `php -v`, `composer --version`, `node -v` and `npm -v`. Do not use a PHP 8.2 executable.

Create a separate database from an administrator's MariaDB/MySQL session. Choose a unique strong password:

```sql
CREATE DATABASE fleet_atlas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleet_atlas'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON fleet_atlas.* TO 'fleet_atlas'@'127.0.0.1';
```

That account is limited to the application database. For hardened deployments, use a separate migration account with DDL rights and grant the runtime account only the necessary data operations.

Place the project at `/var/www/fleet-atlas` (the contents of `platform/`, not the Traccar repository root):

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Edit `.env` before migrations:

```dotenv
APP_NAME=FleetAtlas
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tracking.example.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fleet_atlas
DB_USERNAME=fleet_atlas
DB_PASSWORD="your database password"
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
TRACCAR_URL=http://127.0.0.1:8082
TRACCAR_USERNAME="your dedicated service account"
TRACCAR_PASSWORD="your service password"
TRACCAR_TIMEOUT=15
TRACCAR_CONNECT_TIMEOUT=5
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME="your SMTP username"
MAIL_PASSWORD="your SMTP password"
MAIL_FROM_ADDRESS=tracking@example.com
MAIL_FROM_NAME=FleetAtlas
```

For SMTP port 465 use `MAIL_SCHEME=smtps`. Verify provider requirements and an actual reset email. Never set real secrets as `VITE_*` variables, commit `.env`, or expose log files. Set `APP_URL` to the canonical HTTPS origin; restrict virtual hosts to that hostname.

```bash
php artisan migrate --force
php artisan platform:create-user
php artisan traccar:check
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The user command prompts for name, email and a hidden password. It creates a super admin by default. There are no default passwords. No seed command is required.

Set ownership to your deployment user and web-server group:

```bash
sudo chown -R deploy:www-data /var/www/fleet-atlas
sudo chmod -R u=rwX,g=rX,o= /var/www/fleet-atlas
sudo chmod -R ug=rwX /var/www/fleet-atlas/storage /var/www/fleet-atlas/bootstrap/cache
sudo chmod 640 /var/www/fleet-atlas/.env
```

Substitute the real deployment user. The web-server user needs write access only to `storage/` and `bootstrap/cache/`. Do not use 777. Configure log rotation and protected database backups; test restores. Use the provided Nginx or Apache example, substitute the hostname/certificate paths, then test configuration before reload.

## Windows / XAMPP development

The inspected machine has XAMPP PHP 8.2.12, which does not satisfy the project's declared requirement. Install PHP 8.3+ separately from the [official Windows downloads](https://www.php.net/downloads.php?os=windows), or upgrade XAMPP to a distribution with a compatible PHP runtime. Do not mix extensions from different PHP builds.

For CLI development, use an isolated NTS x64 PHP directory, for example `C:\\php83`, with the matching Visual C++ runtime. Copy `php.ini-development` to `php.ini`, set `extension_dir`, and enable curl, fileinfo, mbstring, openssl, pdo_mysql, pdo_sqlite and zip. Ensure Composer uses this same PHP. Apache module hosting requires a compatible thread-safe build; the CLI server avoids changing the installed XAMPP Apache.

Start MySQL through XAMPP and create the separate database/user using the SQL above. Install Composer 2 and Node.js 22.12+.

```powershell
cd C:\xampp\htdocs\traccar\platform
$env:Path = 'C:\php83;' + $env:Path
php -v
composer install
Copy-Item .env.example .env
php artisan key:generate
npm.cmd ci
npm.cmd run build
```

Configure MySQL, Traccar and SMTP in `.env`. For local HTTP only, set:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
```

Then:

```powershell
php artisan migrate
php artisan platform:create-user
php artisan traccar:check
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/login`. Use compiled assets (`npm.cmd run build`, or `npm.cmd run build -- --watch`) during Phase 1; the strict CSP deliberately permits only same-origin assets, so the Vite development server is not configured. Do not access the project as `http://localhost/traccar/platform`: Laravel's document root must be `platform/public`.

This working session initially installed dependencies and ran tests using PHP 8.2 before enforcing the PHP 8.3 requirement. An isolated runtime under the repository's ignored `.tools/` directory, if present, is a local test tool and is not part of the distributable application. Use your own supported runtime for deployment.

## Account provisioning

```bash
php artisan platform:create-customer
php artisan platform:create-user --role=admin --customer=1
php artisan platform:create-user --role=fleet_manager --customer=1
php artisan platform:create-user --role=operator --customer=1
php artisan platform:create-user --role=customer --customer=1
php artisan platform:user-status person@example.com inactive
php artisan platform:user-status person@example.com active
```

Use the customer ID printed by the first command. Non-super-admin roles require an active customer. Operator/customer vehicle assignments will be managed in Phase 2/7; until assigned, these accounts have no vehicle visibility. Account creation and status changes are audited. CLI commands require trusted server access. Deactivation clears remembered authentication and database sessions; middleware also checks account/customer activation on every request. The last active super admin cannot be deactivated through the command.

## Traccar connection

Run Traccar separately, for example at `http://127.0.0.1:8082`. Use a dedicated service account with access only to the fleet resources managed by this platform; device creation/update needs appropriate Traccar permissions. Read-only credentials can verify connectivity, but cannot perform later CRUD operations. Never modify Traccar tables from Laravel.

For a remote Traccar host, use an HTTPS private endpoint with a valid trusted certificate. Unencrypted non-loopback URLs are rejected. Keep port 8082 private with firewall rules. If Laravel is in a container, loopback means that container; use a protected TLS endpoint for a separate Traccar container/server.

Run `php artisan traccar:check`, or sign in as super admin and select **Test connection**. The check makes one authenticated request and reports no vehicle data. A configuration failure means missing credentials or an invalid/insecure URL; authentication failure means Traccar rejected the service account; a connection failure means network/TLS/timeout trouble. Fix configuration, then rerun `php artisan config:cache`.

No Traccar live connection is opened in Phase 1. Phase 3 requires a private relay with authorized customer channels. Do not reverse-proxy an administrator WebSocket directly to the browser.

## Queue and scheduler

Password recovery sends SMTP mail synchronously in Phase 1; no worker or scheduler is required for the delivered features. Job/cache/session tables are available for later phases. An optional `deploy/fleet-worker.service` is supplied for when background jobs are added. At that point enable the worker and add the scheduler:

```cron
* * * * * cd /var/www/fleet-atlas && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

On Windows, the later-phase equivalents are `php artisan queue:work --tries=3 --timeout=90` and `php artisan schedule:work` in separate terminals. Production workers should be supervised and restarted on deployments.

## Release checks

```bash
composer install
php artisan test
vendor/bin/pint --test
npm ci
npm run build
composer audit
npm audit
```

Run tests on a dedicated MySQL/MariaDB test database as described in TESTING.md. Never run `migrate:fresh` or test database-refresh operations against a production database. Deploy the lockfiles and compiled assets; install production dependencies with `--no-dev`, migrate with `--force`, refresh caches and restart any workers.

Behind a reverse proxy, configure Laravel trusted proxies for the exact proxy addresses and ensure HTTPS is forwarded correctly. Do not blindly trust every incoming proxy header. Test HTTPS cookies, SMTP delivery and tenant separation against the deployed host before onboarding customers.
