# FleetAtlas architecture

## Target architecture

```text
GPS devices ──device protocols──> Traccar server
                                  │
                                  ├── Traccar database (positions, history, events, reports)
                                  │
                                  ├── REST API <── Laravel TraccarService (server credentials)
                                  │                    │
                                  │              FleetTrackingService
                                  │              policies + customer scope
                                  │                    │
                                  └── WebSocket ──> private relay [Phase 3]
                                                       │
                                      authorized customer channels [Phase 3]
                                                       │
Browser <── HTTPS/session/CSRF ── Laravel controllers + Blade
                                      │
                                      └── MySQL/MariaDB (business data only)
```

The existing Java server and its database are independent of this application. Do not point Laravel at Traccar's database. Only Laravel's `public/` directory may be served by Apache/Nginx.

## Phase 1 folder structure

```text
platform/
├── app/
│   ├── Console/Commands/
│   │   ├── CheckTraccar.php
│   │   ├── CreateCustomer.php
│   │   ├── CreateUser.php
│   │   └── SetUserStatus.php
│   ├── Enums/Role.php
│   ├── Exceptions/TraccarException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── DashboardController.php
│   │   │   └── TraccarConnectionController.php
│   │   └── Middleware/
│   │       ├── EnsureAccountIsActive.php
│   │       └── SecurityHeaders.php
│   ├── Models/{Customer,User,Vehicle}.php
│   ├── Policies/VehiclePolicy.php
│   ├── Providers/AppServiceProvider.php
│   └── Services/{AuditLogger,FleetTrackingService,TraccarService}.php
├── bootstrap/app.php
├── config/                         # Laravel config + traccar.php
├── database/
│   ├── factories/UserFactory.php
│   ├── migrations/                 # Auth, queues, cache, business schema
│   └── seeders/DatabaseSeeder.php  # Intentionally creates no accounts
├── deploy/                        # Apache, Nginx and worker examples
├── docs/                          # Architecture, installation, testing
├── public/                        # ONLY web document root
├── resources/
│   ├── css/app.css                 # Tailwind + visual system
│   ├── js/app.js                   # Mobile navigation
│   └── views/
│       ├── auth/                  # Login, forgot/reset password
│       ├── layouts/app.blade.php
│       ├── partials/messages.blade.php
│       └── dashboard.blade.php
├── routes/{web,console}.php
├── storage/                       # Private logs, sessions, cache
├── tests/Feature/                 # Authentication, isolation, API failures
├── .env.example
├── composer.json / composer.lock
└── package.json / package-lock.json
```

API token routes, jobs, WebSocket relay and map assets will be introduced when those phases require them. Phase 1 uses Laravel's web session middleware; no unauthenticated API proxy exists.

## Authorization contract

| Role | Visibility | Vehicle modifications | Platform connection check |
| --- | --- | --- | --- |
| Super Admin | All customers | Policy permits | Yes |
| Admin | Own customer | Policy permits | No |
| Fleet Manager | Own customer | Policy permits | No |
| Operator | Own customer AND explicit assignment | No | No |
| Customer/User | Own customer AND explicit assignment | No | No |

These are fixed, version-controlled permissions for Phase 1. Role/permission editing UI comes in Phase 7. All authenticated routes use `auth`, `active` and `auth.session`. Each policy verifies activation and customer visibility. An unassigned non-super-admin has no access. Even an erroneous cross-customer pivot assignment cannot grant access.

Never return `TraccarService::getDevices()` directly to a tenant. It is privileged transport. Browser-facing tracking operations must use a service that first authorizes a local Vehicle, derives its Traccar ID from the database, and filters returned objects. `FleetTrackingService::latestPositions()` implements and tests this pattern. It is not exposed as a polling endpoint.

Laravel migrations establish customer ownership and indexes, but relational foreign keys alone do not prove that a driver, user and vehicle belong to the same customer. Future assignment controllers must authorize and validate all participants inside a transaction. UI CRUD and assignment workflows are intentionally deferred to their phases.

## Data ownership

Laravel stores customers, users, vehicle metadata, drivers, vehicle assignments and driver history; Traccar resource IDs link the systems. Alerts will reference Traccar event IDs, with per-user read receipts. Settings store non-secret business options; credentials belong in environment configuration. Audit records contain action, actor, subject, customer and time, never passwords or raw API bodies.

Laravel does not copy GPS positions. Traccar remains authoritative for positions, protocols, geofences, event generation, trips and historical reports. Schema for future features exists; event ingestion and management UI do not run yet.

## Traccar transport

Credentials are read from `config/traccar.php`, backed by `.env`, and sent using HTTP Basic authentication only from PHP. HTTPS is required except for loopback. TLS verification remains enabled; redirects are disabled. Timeouts are bounded. No automatic mutation retries are used, avoiding duplicate device creation after ambiguous timeouts. Error messages omit upstream response bodies and raw connection exceptions.

The connection check uses authenticated `GET /api/devices`, discards the returned inventory and displays only success/failure. `GET /api/server` alone would not prove authentication. Device lookup uses `GET /api/devices?id=...` as defined in the repository's `openapi.yaml`.

For larger fleets, Phase 3 must add a private process that authenticates to Traccar's supported live interface and distributes only authorized payloads. Never expose an administrator session cookie, credentials, or an unfiltered Traccar WebSocket to customers. Reauthorize subscriptions on assignment and activation changes. No relay or WebSocket endpoint is claimed as implemented in Phase 1.

## Implementation stages

1. **Delivered:** Laravel foundation, business migrations, authentication, fixed roles, tenant policies, audited CLI provisioning, safe Traccar service, connection check, Blade workspace, tests and deployment examples.
2. Dashboard statistics, device/vehicle CRUD and safe synchronization.
3. Leaflet map, vehicle list, private live relay, authorized channels and reconnect behavior.
4. Trip history and animated route playback.
5. Geofence drawing/assignments and event ingestion/read receipts.
6. Filtered reports, background exports to CSV/XLSX/PDF.
7. Customer/user management UI, configurable permissions and comprehensive security review.
8. Full-system/load/browser testing, real Traccar integration and production rollout.

## References

- [Laravel 12 documentation](https://laravel.com/docs/12.x)
- [Traccar API authentication](https://www.traccar.org/traccar-api/)
- [Traccar API reference](https://www.traccar.org/api-reference/)
- Local protocol/API contract: `../openapi.yaml`
