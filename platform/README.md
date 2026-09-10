# TEVERA â€” Vehicle Tracking & Fleet Management

A Laravel 12 / PHP 8.3+ foundation for a custom GPS tracking platform powered by a separate Traccar server.

Latest update: vehicle/GPS registration, editing, deactivation, customer/user/driver provisioning and a protocol reference are available. See [Adding vehicles and GPS devices](docs/FLEET-MANAGEMENT.md). The Phase 1 notes below describe the original foundation.

GPS devices â†’ Traccar â†’ privileged server-side Laravel service â†’ authenticated Blade workspace.

Implemented: login/logout, remember me, forgot/reset password, activation checks, five roles, tenant-scoped vehicle policies, business migrations, audited account provisioning, reusable Traccar REST transport, a super-admin connection check, responsive Tailwind/Blade screens and regression tests. No default credentials are created. Traccar owns GPS processing and position history.

Fleet operations now include fuel logs, maintenance, customer tracking links, driver reports, WhatsApp templates and separate USD/ZiG payment accounts. See [Fleet operations setup and limitations](docs/FLEET-OPERATIONS.md).

Start with these documents:

- [Architecture and complete folder structure](docs/ARCHITECTURE.md)
- [Ubuntu and Windows/XAMPP installation](docs/INSTALLATION.md)
- [Tests and verification results](docs/TESTING.md)
- [Complete first-party source with file paths](docs/PHASE1-SOURCE.md)

Quick setup, using PHP 8.3+:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Configure MySQL/MariaDB, SMTP and Traccar in `.env`. For local HTTP, set `SESSION_SECURE_COOKIE=false`. Then:

```bash
php artisan migrate
php artisan platform:create-user
php artisan traccar:check
php artisan serve --host=127.0.0.1 --port=8000
```

Open `/login`. The provisioning command prompts for a hidden password. Follow the full installation guide for HTTPS, server configuration and production permissions.

Verification: 28 tests / 142 assertions pass on PHP 8.3.33 with both SQLite and MariaDB. The frontend build, migration rollback/reapply, formatting, route/view caching and HTTP CSRF rejection passed. Real Traccar authentication, SMTP delivery and visual browser inspection remain unverified.

Scope: this delivers Phase 1 only. Vehicle management, live maps/WebSockets, playback, geofences and reports follow in Phases 2â€“8. See the architecture document for the complete roadmap.
