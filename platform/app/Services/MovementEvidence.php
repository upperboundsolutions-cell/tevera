<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MovementEvidence
{
    public function __construct(private TraccarService $traccar) {}

    public function collect(User $actor, Vehicle $vehicle, CarbonImmutable $from, CarbonImmutable $to): array
    {
        Gate::forUser($actor)->authorize('view', $vehicle);
        abort_unless($actor->role->managesFleet(), 403);
        if ($to <= $from || $from->diffInSeconds($to) > 86400 || $to->isFuture()) {
            throw ValidationException::withMessages(['to' => 'Choose a past time range of up to 24 hours.']);
        }
        if (! $vehicle->traccar_device_id) {
            throw ValidationException::withMessages(['vehicle_id' => 'Link this vehicle to Traccar before requesting movement reports.']);
        }
        $evidence = ['vehicle' => ['name' => $vehicle->name, 'registration' => $vehicle->registration, 'make' => $vehicle->make, 'model' => $vehicle->model],
            'from_utc' => $from->toIso8601String(), 'to_utc' => $to->toIso8601String(), 'retrieved_at_utc' => now()->utc()->toIso8601String(),
            'limitations' => ['Only fully contained trips/stops and events in this window are included.', 'At most 50 records per report and 50 driver assignments are shown; totals cover displayed records only.',
                'Driver assignments record responsibility, not proof of who physically drove.', 'No live location or continuous route trace is included. Missing reports do not prove the vehicle stayed still.'],
            'trips' => [], 'stops' => [], 'events' => [], 'driver_assignments' => [], 'truncated' => []];
        foreach (['trips' => 'getTrips', 'stops' => 'getStops', 'events' => 'getEvents'] as $kind => $method) {
            $rows = $this->traccar->$method((int) $vehicle->traccar_device_id, $from->toIso8601String(), $to->toIso8601String());
            $valid = [];
            foreach ($rows as $row) {
                if (! is_array($row) || (int) ($row['deviceId'] ?? 0) !== (int) $vehicle->traccar_device_id) {
                    continue;
                }
                try {
                    $startValue = $row[$kind === 'events' ? 'eventTime' : 'startTime'] ?? null;
                    $endValue = $kind === 'events' ? $startValue : ($row['endTime'] ?? null);
                    if (! $startValue || ! $endValue) {
                        continue;
                    }
                    $start = CarbonImmutable::parse($startValue)->utc();
                    $end = CarbonImmutable::parse($endValue)->utc();
                    if ($start < $from || $end > $to || $end < $start) {
                        continue;
                    }
                } catch (\Throwable $error) {
                    continue;
                }
                $record = ['start_utc' => $start->toIso8601String(), 'end_utc' => $end->toIso8601String()];
                foreach (['startAddress', 'endAddress', 'address', 'type'] as $field) {
                    if (isset($row[$field]) && is_scalar($row[$field])) {
                        $record[$field] = mb_substr((string) $row[$field], 0, 180);
                    }
                }
                foreach (['startLat', 'startLon', 'endLat', 'endLon', 'lat', 'lon'] as $field) {
                    if (isset($row[$field]) && is_numeric($row[$field])) {
                        $record[$field] = round((float) $row[$field], 6);
                    }
                }
                if ($kind !== 'events') {
                    $record['duration_minutes'] = round($start->diffInSeconds($end) / 60, 1);
                }
                if ($kind === 'trips') {
                    $record['distance_km'] = isset($row['distance']) && is_numeric($row['distance']) ? round(max(0, (float) $row['distance']) / 1000, 2) : null;
                    $record['max_speed_kmh'] = isset($row['maxSpeed']) && is_numeric($row['maxSpeed']) ? round(max(0, (float) $row['maxSpeed']) * 1.852, 1) : null;
                }
                $valid[] = $record;
            }
            usort($valid, fn ($a, $b) => strcmp($a['start_utc'], $b['start_utc']));
            $evidence['truncated'][$kind] = count($valid) > 50;
            foreach (array_slice($valid, 0, 50) as $index => $record) {
                $evidence[$kind][] = ['reference' => strtoupper(substr($kind, 0, 1)).($index + 1)] + $record;
            }
        }
        $assignments = DB::table('vehicle_driver_history as history')->join('drivers', 'drivers.id', '=', 'history.driver_id')
            ->where('history.vehicle_id', $vehicle->id)->where('drivers.customer_id', $vehicle->customer_id)
            ->where('history.assigned_at', '<', $to)->where(fn ($q) => $q->whereNull('history.ended_at')->orWhere('history.ended_at', '>', $from))
            ->orderBy('history.assigned_at')->limit(51)->get(['drivers.name', 'history.assigned_at', 'history.ended_at']);
        $evidence['truncated']['driver_assignments'] = $assignments->count() > 50;
        foreach ($assignments->take(50) as $index => $assignment) {
            $evidence['driver_assignments'][] = ['reference' => 'D'.($index + 1), 'driver_name' => $assignment->name,
                'assigned_at_utc' => CarbonImmutable::parse($assignment->assigned_at, 'UTC')->toIso8601String(),
                'ended_at_utc' => $assignment->ended_at ? CarbonImmutable::parse($assignment->ended_at, 'UTC')->toIso8601String() : null];
        }
        $evidence['displayed_distance_km'] = round(array_sum(array_column($evidence['trips'], 'distance_km')), 2);

        return $evidence;
    }
}
