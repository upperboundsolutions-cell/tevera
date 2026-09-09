# Local Traccar and vehicle map

For the current receiver configuration and management screens, see [Fleet management](FLEET-MANAGEMENT.md). The single-receiver setup described below was the initial map test and has since been replaced by all supported receivers with documented port overrides.

Updated 2026-09-07. The repository's Traccar 6.15.3 server source was compiled using Java 21 and Gradle 9.5.1. The local server uses its own H2 database under `target/traccar-local`, separate from Laravel. Its API listens on `127.0.0.1:8082`.

Laravel now has an authenticated map at `/tracking`, also embedded on `/dashboard`. Leaflet and its CSS are bundled locally through Vite. The base map starts in Harare and fits vehicles when GPS positions exist. It includes search, status filtering, a vehicle sidebar, full screen, GPS details and pagination of 100 vehicles per page. Speeds are converted from Traccar knots to km/h.

The map loads latest positions on opening and when **Refresh positions** is selected. It does not yet implement continuous WebSocket updates, historical playback or geofence drawing. No fabricated vehicle data is installed. Registering and linking actual devices is required for markers; no devices existed when this installation was initialized.

## Restart the local server

From the repository root in PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools/start-traccar-local.ps1
```

This process-only script policy override does not alter the machine's policy. Keep the terminal running. The configuration is `.tools/traccar-local.xml`. The local Osmand receiver is restricted to loopback; selecting/configuring the real tracker protocol and network exposure is still required before receiving physical device traffic. The local setup is not an installed Windows service and does not automatically start at boot.

To rebuild after server source changes:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tools/build-traccar-local.ps1
```

Start Laravel in a separate terminal:

```powershell
cd platform
& ..\.tools\php83\php.exe artisan serve --host=127.0.0.1 --port=8000
```

The first Traccar account is a generated backend service administrator. Its secret is stored in Laravel's ignored `.env`, never sent to JavaScript. It is separate from your FleetAtlas administrator account. Do not rerun `.tools/connect-traccar.php` on an existing installation; that bootstrap deliberately refuses to replace credentials or register into a nonempty server.

## Map data security

`TrackingController` obtains visible local vehicles using `Vehicle::visibleTo` before contacting Traccar. Requests include only those device IDs, using repeated query parameters as required by Traccar. Results are matched back to the authorized vehicles and reduced to display fields; arbitrary position attributes and unmatched upstream devices are discarded. Empty fleets make no upstream position request. The endpoint requires an active authenticated session and is throttled.

OpenStreetMap attribution stays visible, and the Referrer-Policy sends the requesting origin for tile requests. CSP permits the specific tile host and Leaflet's positioning styles while retaining same-origin scripts. The standard tile service requires internet access and is subject to the [OSM tile policy](https://operations.osmfoundation.org/policies/tiles/); arrange an appropriate tile provider for production fleet scale. See the [Leaflet reference](https://leafletjs.com/reference.html) for map APIs.

Verification: 32 automated tests passed with 157 assertions; production asset compilation passed. Traccar authenticated connectivity passed. Browser visual verification was unavailable because no browser was connected.
