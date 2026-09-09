<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class FleetEventNotifications
{
    public const TYPES = ['geofenceEnter' => 'Geofence entry', 'geofenceExit' => 'Geofence exit', 'deviceOverspeed' => 'Recorded speeding', 'deviceOffline' => 'Tracker offline', 'alarm' => 'Tracker alarm'];

    public function run(): void
    {
        $lock = Cache::lock('fleet-event-notifications', 1800);
        if (! $lock->get()) {
            return;
        }
        try {
            $to = CarbonImmutable::now('UTC');
            $from = $to->subMinutes(10);
            foreach (DB::table('notification_preferences')->where('email_enabled', true)->get() as $pref) {
                $user = User::find($pref->user_id);
                if (! $user || ! $user->canAccessPlatform() || ($user->customer && ! $user->customer->subscriptionAllowsAccess())) {
                    continue;
                }
                $types = array_intersect(json_decode($pref->events, true) ?? [], array_keys(self::TYPES));
                if (! $types) {
                    continue;
                }
                foreach (Vehicle::visibleTo($user)->where('is_active', true)->whereNotNull('traccar_device_id')->cursor() as $vehicle) {
                    foreach (app(TraccarService::class)->getEvents($vehicle->traccar_device_id, $from->toIso8601String(), $to->toIso8601String()) as $event) {
                        if (($event['deviceId'] ?? null) != $vehicle->traccar_device_id || ! in_array($event['type'] ?? null, $types, true) || ! is_numeric($event['id'] ?? null) || empty($event['eventTime'])) {
                            continue;
                        }
                        try {
                            $when = CarbonImmutable::parse($event['eventTime'])->utc();
                        } catch (\Throwable) {
                            continue;
                        }
                        if ($when->lt($from) || $when->gt($to) || $when->lt(CarbonImmutable::parse($pref->updated_at, 'UTC'))) {
                            continue;
                        }
                        $row = ['user_id' => $user->id, 'event_id' => $event['id']];
                        DB::table('notification_deliveries')->insertOrIgnore($row + ['vehicle_id' => $vehicle->id, 'event_type' => $event['type'], 'occurred_at' => $when, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                        $delivery = DB::table('notification_deliveries')->where($row)->first();
                        if (! in_array($delivery->status, ['pending', 'failed'], true)) {
                            continue;
                        }
                        // Fail closed after an ambiguous send: an operator must inspect it instead of resending blindly.
                        DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'sending', 'updated_at' => now()]);
                        try {
                            if (! in_array(config('mail.default'), ['log', 'array', 'smtp'], true)) {
                                throw new \RuntimeException('Configure smtp or local log mailer');
                            }
                            Mail::raw(self::TYPES[$event['type']]."\nVehicle: ".$vehicle->name."\nRecorded: ".$when->toIso8601String()."\nReview the event in TEVERA. GPS events depend on tracker data.", fn ($m) => $m->to($user->email)->subject('TEVERA: '.self::TYPES[$event['type']]));
                            $status = in_array(config('mail.default'), ['log', 'array']) ? 'previewed' : 'accepted';
                        } catch (\Throwable) {
                            $status = 'uncertain';
                        }
                        DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'updated_at' => now()]);
                    }
                }
            }
            Cache::put('deployment:notifications', time(), 3600);
        } finally {
            $lock->release();
        }
    }
}
