# Phase 1 verification

Verified on 2026-09-06 using PHP 8.3.33 and Laravel 12.69.1.

| Check | Result |
| --- | --- |
| SQLite isolated suite | 28 tests passed, 142 assertions |
| MariaDB 10.4.32 dedicated test database suite | 28 tests passed, 142 assertions |
| Business migration rollback and reapply | Passed on SQLite and MariaDB |
| Composer manifest/lock validation | Passed with `composer validate --strict` |
| Composer dependency security check | No advisories reported during lock refresh |
| npm dependency audit | No vulnerabilities reported during installation |
| Tailwind/JavaScript production build | Passed |
| Laravel Pint | Passed |
| Blade view compilation and route caching | Passed |
| HTTP login page and compiled CSS | 200 |
| HTTP login POST without CSRF token | 419 |
| Login security headers | CSP, frame restriction and content type protection present |
| Traccar check with missing credentials | Safe configuration error, nonzero exit |
| Visual browser inspection | Not performed: browser runtime reported no available browser |
| Real Traccar and SMTP delivery | Not performed: connection credentials were not supplied |

The MariaDB version is the existing local XAMPP installation, not a production version recommendation. Its temporary test database was created only for this run and removed afterward. The application's local SQLite database contains no seeded/default accounts.

## Automated coverage

- Login, logout, remember token and audit records.
- Login throttling after repeated failures, including a subsequent correct password.
- Disabled account/customer rejection and existing-session account deactivation.
- Password recovery response for unknown addresses; valid, invalid, expired and reused reset tokens.
- Password hashing and session invalidation after reset.
- CLI customer/user provisioning, deactivation and last-super-admin protection.
- Cross-customer denial for every tenant role, including deliberately incorrect assignments.
- Explicit assignment requirements for customer/operator accounts.
- Privilege fields excluded from mass assignment.
- Authorization before upstream tracking requests and filtering of excess upstream positions.
- HTML escaping of user-supplied account names.
- Traccar server-side Basic authentication and device query parameters.
- Sanitized authentication, validation, not-found, connection, redirect and server errors.
- Remote plaintext URL rejection, malformed JSON rejection and no mutation retry.
- Super-admin-only connection check without device/credential disclosure.

Laravel disables CSRF verification inside its standard testing environment, so the 419 check was separately performed against the actual local HTTP server.

## Repeat SQLite tests

```bash
composer install
php artisan test
vendor/bin/pint --test
npm ci
npm run build
```

The committed phpunit.xml selects an in-memory SQLite database, an array mail transport and isolated session/cache drivers. Traccar HTTP calls are faked; tests do not need or access a live tracking server.

## Repeat MySQL/MariaDB tests

Create a disposable database and a user limited to that database. Do not use the application database: RefreshDatabase drops/recreates tables. Export these variables before running the suite (replace placeholders):

```bash
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_DATABASE=fleet_atlas_test
export DB_USERNAME=fleet_atlas_test
export DB_PASSWORD='your-test-database-password'
php artisan config:clear
php artisan test
```

PowerShell uses `$env:DB_CONNECTION='mysql'` and equivalent assignments for the other values. Use a separate terminal to avoid leaving test environment overrides in an application server session. PHPUnit's configured defaults do not overwrite existing process environment values.

## Required checks before production onboarding

Configure a real Traccar service account, run `php artisan traccar:check`, and verify both authorized access and rejected access using known test vehicles. Verify a delivered SMTP reset link uses the correct HTTPS origin. Inspect desktop/mobile layouts in a browser and verify cookie flags on the production HTTPS host. Phase 1 does not implement maps, live relay, device CRUD, reports, geofence synchronization or production load testing; those remain in the staged roadmap.
