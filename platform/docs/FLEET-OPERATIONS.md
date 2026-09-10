# Fleet operations

The overview now shows fleet states, today's recorded distance, recent alarms/speeding/geofence exits, and overdue service tasks. New navigation: Fuel & costs, Maintenance, Customer tracking links, and Reports & driver scores. Low data mode stops automatic tracking refresh and hides map tiles; manual refresh still works. Map libraries load only where needed.

## Installation and cPanel

Back up the database/private storage, upload the changes and built `public/build` assets, then run from `platform/` with PHP 8.3+:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan queue:restart
```

The domain document root must be `platform/public`. Keep `storage/app/private` private. Maintenance documents are downloaded through an authenticated, fleet-scoped route.

On cPanel, add a once-per-minute cron job using the host's PHP 8.3 CLI path and your account's absolute project path:

```sh
/usr/local/bin/php /home/ACCOUNT/tevera/platform/artisan schedule:run >> /home/ACCOUNT/tevera-scheduler.log 2>&1
```

Traccar still needs a separate Java server/VPS. Use HTTPS for public TEVERA and its protected Traccar connection. Existing queue jobs need a supervised worker. Do not use a development PHP server for public hosting.

## WhatsApp and maintenance alerts

Configure `WHATSAPP_ENABLED`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WHATSAPP_FROM` (whatsapp:+number), and `TWILIO_WHATSAPP_CONTENT_SID` in the server environment. Create an approved template with variables **1: alert description, 2: vehicle name, 3: recorded timestamp**. Enable WhatsApp only after testing the approved sender/template. Users opt in on Notifications with their international-format number and explicit consent. Email and WhatsApp are independent.

Tracker alerts run every minute over the preceding ten minutes. Maintenance alerts run hourly, at most once per overdue task/user/day. Accepted means provider acceptance, not handset delivery. Uncertain/sending records need operator review; ambiguous sends are not blindly retried. Tracker delivery activity is shown in Notifications; maintenance activity is stored in `maintenance_deliveries`. Long tracker polling outages need manual event review. Implementation tests send no real messages.

[Twilio template documentation](https://www.twilio.com/docs/whatsapp/tutorial/send-whatsapp-notification-messages-templates)

## Fuel and driving data

Manual consumption uses all fill-up litres and odometer distance between complete tank fills. Record every fill-up; two full readings are required. USD and ZiG costs remain separate. Fuel entries advance the recorded vehicle odometer but never decrease it. Service reminders use that recorded odometer, so update it regularly.

Sensor-based suspected fuel drops are disabled by default (`FLEET_FUEL_SENSOR_UNIT=unknown`). Set it to `litres` only when all fuel sensors in this installation are calibrated in litres. `FLEET_FUEL_DROP_LITRES=10` sets the investigation threshold. Reports compare valid samples no more than five minutes apart. A drop is not proof of theft; sensor errors and normal use can cause drops. No synthetic fuel data is generated.

Scorecard distance requires increasing `totalDistance` counters in metres. Idling requires ignition/speed samples. Gaps above five minutes are excluded. Dashboard distance uses Traccar summary reports, cached for two minutes; dashboard dates are UTC. Score starts at 100, subtracting 5 per speeding event, 8 per harsh driving event, and 1 per 30 observed idle minutes. Missing GPS samples yield no score. Event detection must be enabled. Driver labels are current assignments, not historical driver identification.

## Customer links

Managers create links for 1-168 hours and revoke them at any time. Only a hash of the random 256-bit token is stored; the full link is displayed once. Public pages show the customer-facing label, timestamp and coordinates, with manual refresh and stale-location labels. Vehicle registration, driver details, history and internal tracker IDs are excluded. Links stop working on expiry, revocation, vehicle deactivation or loss of the creator's access/subscription. Anyone holding a link can view its location.

## Reports

Individual reports/CSV exports cover up to seven days. Existing Playback & events supplies journey replay and events. Daily/weekly summaries cover the preceding UTC day/week and include overdue maintenance. Each schedule is restricted to its owner's current vehicle access at execution. Runs occur on the first hourly scheduler pass after 06:00 server time. Configure SMTP for real delivery; log/array mailers are previews. An ambiguous send is not automatically retried.

## USD, ZiG and EcoCash

Use `USD` and `ZWG` (ZiG) plans. Configure `PAYNOW_USD_INTEGRATION_ID`, `PAYNOW_USD_INTEGRATION_KEY`, `PAYNOW_ZWG_INTEGRATION_ID`, and `PAYNOW_ZWG_INTEGRATION_KEY` for separate settlement accounts. Existing `PAYNOW_INTEGRATION_ID`, `PAYNOW_INTEGRATION_KEY` and `PAYNOW_CURRENCY` remain the default-currency fallback.

Enable EcoCash on each relevant Paynow integration for hosted checkout. The server selects keys from the stored payment currency, validates signatures and independently polls/verifies the amount/reference before granting access. No exchange rates or currency conversion are assumed. Confirm supported currency/methods with the merchant account before enabling payments.

[Paynow initiation documentation](https://developers.paynow.co.zw/docs/paynow/initiate_transaction/)

## Verification

Run `vendor/bin/phpunit` against the dedicated in-memory SQLite test configuration and `npm run build`. FleetOperationsTest covers tenant boundaries, roles, consumption, maintenance completion, link expiry/revocation/access loss, report filtering, stale GPS state, WhatsApp consent/template requests, currency-specific signatures and scheduled reports. Real WhatsApp/SMTP delivery, GPS sensors and merchant settlement need configured-provider testing. Browser visual verification was unavailable because no browser was connected.
