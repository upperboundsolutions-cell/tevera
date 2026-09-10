<?php

namespace App\Services;

use App\Exceptions\TraccarException;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FleetInsights
{
    public function __construct(private TraccarService $traccar) {}

    public function snapshot(User $user): array
    {
        $vehicles = Vehicle::visibleTo($user)->where('is_active', true)->get();
        $ids = $vehicles->pluck('traccar_device_id')->filter()->all();
        $positions = collect($ids ? $this->traccar->getLatestPositionsForDevices($ids) : [])->keyBy('deviceId');
        $devices = collect($ids ? $this->traccar->getDevicesByIds($ids) : [])->keyBy('id');
        $counts = array_fill_keys(['moving', 'parked', 'idling', 'offline', 'unknown'], 0);
        $attention = [];
        foreach ($vehicles as $vehicle) {
            $p = $positions->get($vehicle->traccar_device_id);
            $d = $devices->get($vehicle->traccar_device_id);
            $fresh = false;
            try {
                $fresh = ! empty($p['fixTime']) && CarbonImmutable::parse($p['fixTime'])->between(now()->subMinutes(10), now()->addMinute()) && ($p['valid'] ?? false) === true;
            } catch (\Throwable) {
            }
            $state = ($d['status'] ?? '') === 'offline' ? 'offline' : 'unknown';
            if (($d['status'] ?? '') === 'online' && $fresh && is_numeric($p['speed'] ?? null)) {
                $state = ($p['speed'] ?? 0) > 0.54 ? 'moving' : (($p['attributes']['ignition'] ?? null) === true ? 'idling' : 'parked');
            }
            $counts[$state]++;
            if (in_array($state, ['offline', 'unknown', 'idling'])) {
                $attention[] = ['vehicle' => $vehicle->name, 'issue' => match ($state) {
                    'offline' => 'Tracker offline: check power and SIM connectivity','idling' => 'Engine on while stationary: review idle time',default => 'No fresh GPS fix: inspect tracker connection'
                }, 'url' => route('tracking')];
            }
        }
        $tasks = DB::table('maintenance_tasks')->join('vehicles', 'vehicles.id', '=', 'maintenance_tasks.vehicle_id')->whereIn('vehicle_id', $vehicles->pluck('id'))->whereNull('completed_at')->where(fn ($q) => $q->whereDate('due_on', '<=', today())->orWhereColumn('due_odometer', '<=', 'vehicles.odometer'))->select('maintenance_tasks.title', 'vehicles.name')->get();
        foreach ($tasks as $task) {
            $attention[] = ['vehicle' => $task->name, 'issue' => 'Service due: '.$task->title, 'url' => $user->can('manage-fleet') ? route('operations.index', ['tab' => 'maintenance']) : route('vehicles.index')];
        }
        $distance = null;
        $summaryUnavailable = false;
        if ($ids) {
            try {
                $key = 'fleet-summary:'.hash('sha256', config('traccar.url').':'.now('UTC')->toDateString().':'.implode(',', $ids));
                $summary = Cache::remember($key, 120, fn () => $this->traccar->fleetReport('summary', $ids, CarbonImmutable::now('UTC')->startOfDay()->toIso8601String(), now()->toIso8601String()));
                $rows = collect($summary)->filter(fn ($row) => in_array((int) ($row['deviceId'] ?? 0), array_map('intval', $ids), true) && is_numeric($row['distance'] ?? null) && $row['distance'] >= 0);
                $distance = $rows->isNotEmpty() ? round($rows->sum('distance') / 1000, 1) : null;
                $events = Cache::remember($key.':events', 120, fn () => $this->traccar->fleetReport('events', $ids, now()->subHour()->toIso8601String(), now()->toIso8601String()));
                foreach ($events as $event) {
                    $vehicle = $vehicles->firstWhere('traccar_device_id', $event['deviceId'] ?? null);
                    if (! $vehicle || ! in_array($event['type'] ?? '', ['alarm', 'deviceOverspeed', 'geofenceExit']) || empty($event['eventTime'])) {
                        continue;
                    }
                    try {
                        if (! CarbonImmutable::parse($event['eventTime'])->between(now()->subHour(), now())) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                    $attention[] = ['vehicle' => $vehicle->name, 'issue' => match ($event['type']) {
                        'alarm' => 'Tracker alarm: review possible unusual movement','deviceOverspeed' => 'Recorded speeding: review the journey',default => 'Geofence exit: confirm the planned route'
                    }, 'url' => route('history.index')];
                }
            } catch (TraccarException) {
                $summaryUnavailable = true;
            }
        }

        return ['today_distance_km' => $distance, 'summary_unavailable' => $summaryUnavailable] + compact('counts', 'attention') + ['fetched_at' => now()->toIso8601String()];
    }

    public function report(Vehicle $vehicle, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = ['vehicle' => $vehicle->name, 'driver' => $vehicle->driver?->name ?? 'Unassigned', 'distance_km' => null, 'idle_minutes' => null, 'speeding' => 0, 'harsh_events' => 0, 'score' => null, 'fuel_drop_litres' => null, 'status' => 'No tracker'];
        if (! $vehicle->traccar_device_id) {
            return $result;
        }
        $id = (int) $vehicle->traccar_device_id;
        $inRange = function ($row, $field) use ($id, $from, $to) {
            if ((int) ($row['deviceId'] ?? 0) !== $id || empty($row[$field])) {
                return false;
            } try {
                return CarbonImmutable::parse($row[$field])->between($from, $to);
            } catch (\Throwable) {
                return false;
            }
        };
        $points = collect($this->traccar->getRoute($id, $from->toIso8601String(), $to->toIso8601String()))->filter(fn ($p) => $inRange($p, 'fixTime') && ($p['valid'] ?? false) === true)->sortBy('fixTime')->values();
        $events = collect($this->traccar->getEvents($id, $from->toIso8601String(), $to->toIso8601String()))->filter(fn ($e) => $inRange($e, 'eventTime'))->unique('id');
        $result['status'] = $points->isEmpty() ? 'No GPS samples' : 'Recorded samples';
        $result['speeding'] = $events->where('type', 'deviceOverspeed')->count();
        $result['harsh_events'] = $events->filter(fn ($e) => in_array($e['attributes']['alarm'] ?? '', ['hardBraking', 'hardAcceleration', 'hardCornering']))->count();
        $distance = 0;
        $idle = 0;
        $drop = null;
        $distanceSamples = 0;
        $idleSamples = 0;
        foreach ($points as $i => $p) {
            if (! $i) {
                continue;
            } $previous = $points[$i - 1];
            $seconds = CarbonImmutable::parse($previous['fixTime'])->diffInSeconds(CarbonImmutable::parse($p['fixTime']));
            if ($seconds <= 0 || $seconds > 300) {
                continue;
            }
            $a = $p['attributes'] ?? [];
            $b = $previous['attributes'] ?? [];
            if (is_numeric($a['totalDistance'] ?? null) && is_numeric($b['totalDistance'] ?? null)) {
                $delta = $a['totalDistance'] - $b['totalDistance'];
                if ($delta >= 0 && $delta / $seconds <= 70) {
                    $distance += $delta;
                    $distanceSamples++;
                }
            }
            if (isset($a['ignition'],$b['ignition'],$p['speed'],$previous['speed'])) {
                $idleSamples++;
                if ($a['ignition'] === true && $b['ignition'] === true && $p['speed'] < 0.54 && $previous['speed'] < 0.54) {
                    $idle += $seconds;
                }
            }
            if (config('fleet.fuel_sensor_unit') === 'litres' && is_numeric($a['fuel'] ?? null) && is_numeric($b['fuel'] ?? null) && ($b['fuel'] - $a['fuel']) >= config('fleet.fuel_drop_litres')) {
                $drop = ($drop ?? 0) + $b['fuel'] - $a['fuel'];
            }
        }
        $result['distance_km'] = $distanceSamples ? round($distance / 1000, 1) : null;
        $result['idle_minutes'] = $idleSamples ? round($idle / 60, 1) : null;
        $result['fuel_drop_litres'] = $drop;
        if ($points->count() >= 2) {
            $result['score'] = max(0,100 - $result['speeding'] * 5 - $result['harsh_events'] * 8 - (int) floor($idle / 1800));
        }

        return $result;
    }
}
