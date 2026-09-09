# Vehicle and GPS management

## Add your first vehicle

1. Sign in and open **Customers**. Create a workspace for the fleet (use your own company name for your own fleet).
2. Open **Vehicles → Add vehicle / GPS device**. Choose the customer, then **Load workspace**.
3. Enter the vehicle name, registration and exact identifier transmitted by the tracker. This can be an IMEI or a configured device ID.
4. Add vehicle make/model, year, VIN, fuel/tank details, odometer, SIM number, tracker model/protocol, installation date and notes as needed.
5. Optionally select the driver and users allowed to track the vehicle.
6. Select **Register vehicle & GPS device**. The application creates the device through Traccar's API and links the returned device ID.
7. Configure the physical tracker with the reachable server address, matching protocol port and SIM/APN settings. After it reports GPS data, open **Vehicle map → Refresh positions**.

The odometer is business metadata in kilometres; it does not reset Traccar's GPS-derived odometer. Selecting a protocol records installation information; it does not remotely configure the tracker.

## Tracker compatibility and receiver ports

Registration is protocol-neutral. GPS decoding remains entirely in the Traccar server compiled from this repository. The **Tracker protocols** page contains 266 entries extracted from this build's `src/main/java/org/traccar/config/PortConfigSuffix.java`, with a link to the official model/protocol lookup.

All default receivers are enabled locally. Seven Windows port conflicts were resolved using these overrides:

| Protocol | Default | Local configured port |
| --- | --- | --- |
| carscop | 5040 | 25040 |
| starlink | 5136 | 25136 |
| dmt | 5137 | 25137 |
| its | 5179 | 25179 |
| mobilogix | 5216 | 25216 |
| thinkpower | 5228 | 25228 |
| dsf22 | 5236 | 25236 |

Overrides are set in `.tools/traccar-local.xml` and mirrored in `TRACCAR_PORT_OVERRIDES` in Laravel's `.env`; the protocol page displays them. Keep both synchronized if changing receiver ports. The private REST API remains on loopback port 8082, not a universal tracker receiver port.

No firewall/router rules were added. Local listeners do not establish cellular/public reachability. Physical trackers still require a reachable host and the appropriate TCP/UDP forwarding. Device setup commands and APN settings depend on the hardware and SIM provider.

## Available management

- **Vehicles:** add/edit metadata, search, active/deactivated filters, driver/user assignments, activation/deactivation and sync retry.
- **GPS devices:** identifier, tracker model/protocol, customer, upstream status, last report and available last position.
- **Drivers:** create contact/licence records; assign from the vehicle form. Driver changes are recorded in assignment history.
- **Customers:** create/list workspaces, available to Super Admin.
- **Users:** create tenant admins, fleet managers, operators and customer accounts; activate/deactivate them. Tenant admins stay within their own customer.
- **Tracker protocols:** searchable source-derived protocol and port reference.

Customer administrators and fleet managers see their own customer's fleet. Operators/customer users require explicit vehicle assignments. Server validation rejects forged cross-customer driver/user assignments. The customer and tracker identifier stay fixed after registration; ownership transfers and tracker replacement need separate audited workflows.

Deactivation preserves history and disables the Traccar device. If synchronization fails, local access is deactivated immediately while the UI explains that the remote tracker update still needs a retry. Permanent deletion is not exposed here.

## Synchronization

Local business changes are saved first. Each vehicle has `pending`, `synced` or `failed` status and a **Retry sync** action. A provisioning UUID saved in Traccar's device attributes lets a retry safely recognize a device created before an HTTP timeout. An unrelated existing device is never silently assigned to another customer.

Updates are serialized per vehicle using cache locks. Existing remote attributes are preserved. Synchronization is synchronous with manual retries, not a background queue. Credentials stay server-side; administrative actions are audited without passwords or raw upstream bodies.

## Installation and main files

Run `php artisan migrate`, `npm ci` and `npm run build` for this update. No new Composer dependency is required. Migration `2026_09_07_000001_add_vehicle_sync_and_driver.php` adds driver, protocol and sync fields.

Main code is in `app/Http/Controllers/{Vehicle,Customer,Driver,UserManagement,Protocol}Controller.php`, `app/Http/Requests/VehicleRequest.php`, `app/Services/VehicleManager.php`, `resources/views/fleet/`, `resources/css/fleet.css`, `resources/data/traccar-protocols.json` and `tests/Feature/VehicleManagementTest.php`.

## Verification and remaining stages

44 automated tests passed with 216 assertions on SQLite and MariaDB. Migration rollback/reapply passed on MariaDB. A real HTTP test registered a vehicle, created its Traccar device, submitted an Osmand GPS report, verified the coordinates in Laravel's map data and confirmed deactivation disabled the upstream device. All temporary records were removed. Receiver startup reported zero bind failures after applying the local overrides.

This is not full Traccar Web parity yet. Automatic WebSockets, history/playback, geofence editing, alerts, reports/exports, commands, importing existing Traccar devices and complete customer/driver/user editing remain later stages. Map positions currently refresh on opening or by button. Visual browser inspection and physical/cellular tracker testing remain unverified.
