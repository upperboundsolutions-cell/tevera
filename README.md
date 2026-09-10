# TEVERA

**Vehicle Tracking & Fleet Management**  
**Upper Bound Solutions PVT · Always ahead.**

TEVERA brings vehicle locations, fleet operations, customer management and billing into one workspace. Built for fleet owners and tracking businesses, it provides a clear view of vehicles, journeys and the tasks that need attention.

## Features

- **Fleet overview:** vehicle states, recorded daily distance, recent alerts and overdue servicing.
- **Vehicle tracking:** live location updates, vehicle details, geofences and journey playback.
- **Fuel management:** fill-up records, running-cost comparisons and consumption calculations.
- **Driver insights:** recorded driving events, scorecards and coaching suggestions.
- **Maintenance:** service reminders by date or recorded mileage, repair costs and private documents.
- **Customer tracking links:** temporary location sharing with expiry and revocation.
- **Reports:** downloadable CSV reports and scheduled daily or weekly email summaries.
- **Notifications:** configurable email and WhatsApp alerts.
- **Business management:** customer workspaces, role-based access, subscription plans and audit logs.
- **Payments:** separate USD and ZiG integration settings, with EcoCash available through configured Paynow checkout.
- **Interface:** a monochrome dashboard, dark maps, mobile layouts and low-data mode.

Tracking and driver insights depend on available GPS data. Sensor-based fuel monitoring requires calibrated hardware. WhatsApp, email and payments require configured provider accounts. See the [fleet operations guide](platform/docs/FLEET-OPERATIONS.md) for setup and feature limitations.

## Run locally on Windows

For the prepared local installation:

1. Start **MySQL** in the XAMPP Control Panel.
2. Double-click **Start-TEVERA.cmd**.
3. Sign in at the address opened by the launcher.

The launcher normally uses `http://127.0.0.1:8000/login` and selects another local port if needed. The prepared installation uses a private PHP 8.3 runtime under `.tools`; that runtime and your credentials are not included in Git.

For a fresh installation, follow the [installation guide](platform/docs/INSTALLATION.md) to install PHP 8.3+, application dependencies and the database, build the frontend and create an administrator account. The GPS receiver runs as a separate service.

## Host on a VPS

One Linux VPS can host TEVERA's web application, GPS server, databases, scheduler and queue worker using the included Docker deployment.

After installing the prerequisites and configuring your domain as described in the [VPS deployment guide](deploy/README.md):

```bash
cd /opt/tevera
chmod +x tevera
./tevera setup
./tevera start
./tevera admin
./tevera link-traccar
./tevera status
```

The `link-traccar` command connects TEVERA to its underlying GPS engine. Customers access TEVERA through your HTTPS domain; GPS devices send data to the configured receiver ports.

## Project structure

| Path | Purpose |
| --- | --- |
| `platform/` | Laravel application, frontend, fleet operations and billing |
| `src/`, `schema/`, `templates/` | GPS server source, database schema and server templates |
| `deploy/` | Docker deployment, HTTPS configuration and backup tools |
| `tools/` | Local startup and maintenance utilities |
| `tevera` | Linux deployment management command |
| `Start-TEVERA.cmd` | Windows application launcher |

## Documentation

- [Fleet operations and integrations](platform/docs/FLEET-OPERATIONS.md)
- [Application installation](platform/docs/INSTALLATION.md)
- [Single-VPS deployment and backups](deploy/README.md)
- [Adding vehicles and GPS devices](platform/docs/FLEET-MANAGEMENT.md)
- [Architecture](platform/docs/ARCHITECTURE.md)

## Attribution and licenses

TEVERA uses the Traccar open-source GPS engine. Upstream copyright notices and the Apache License are retained in [LICENSE.txt](LICENSE.txt) and the relevant source files. The repository also includes [LICENSE](LICENSE). Third-party components retain their respective licenses.
