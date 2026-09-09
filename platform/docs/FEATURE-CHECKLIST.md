# TEVERA tracking release checklist

Baseline: the Traccar 6.15.3 source in this repository. This is a capability checklist, not a claim of full Traccar Web parity.

| Capability | Current state |
| --- | --- |
| Latest vehicle map | Implemented; authorized fleet pages of 100 vehicles |
| Automatic updates | 15-second polling with pause control; hidden tabs do not poll; not WebSocket streaming |
| Journey playback | Implemented; 24-hour UTC range, valid GPS fixes, timeline, point-based playback rates |
| Route gaps | Lines connect fixes; exact roads between reports are unknown |
| Events | Recorded events for one vehicle/time range; max 500 displayed; no email/SMS/push delivery |
| Circular geofences | Create, synchronize/link to one vehicle, retry and delete; GPS-based entry/exit events evaluated by Traccar |
| Geofence drawing/editing | Polygon drawing, editing and multiple-vehicle assignment remain pending |
| Trips/stops/driver history | Movement analysis previews, bounded records with evidence |
| AI analysis | Provider integration implemented; live model needs configuration and evaluation |
| Driver identity | Recorded assignment history; not proof of who physically drove |
| Speeding/towing/power alarms | Display recorded Traccar events; generation depends on receiver data and server configuration |
| Notification rules and delivery | Per-account email preferences and delivery history implemented; local preview by default, SMTP needs configuration; ten-minute polling window; SMS/push pending |
| Device commands/import/groups/custom attributes | Pending; hardware/protocol capability varies |
| Scheduled reports and exports | Pending |
| Fuel, CAN, cameras and immobilization | Require compatible devices; dedicated management UI pending |
| Maintenance/dispatch/proof of delivery | Pending |
| Sharing links, connection diagnosis, daily AI briefings | Pending |

History filters upstream results by authorized device, timestamp and allowed fields. The first 5,000 valid positions and 500 events are returned, with explicit truncation flags; choose a shorter range when capped. Report requests fetch source reports before filtering, so very high reporting rates may require a server-side report limit or a shorter maximum window on large deployments.

Geofences are tenant-scoped through vehicle ownership. A per-record synchronization key recovers ambiguous creation timeouts. Local records survive synchronization failures for retry. Deletion verifies the owned key before deleting the upstream zone. No geofence operation sends device commands. Existing remote zones are not imported automatically.

Validation: run the feature suite and npm build. Before production release, verify browser playback and real GPS entry/exit events with a test device; the automated suite uses controlled upstream responses. These features do not complete the pending items above.
