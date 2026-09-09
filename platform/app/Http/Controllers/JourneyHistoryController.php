<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class JourneyHistoryController extends Controller
{
    public function index(Request $request)
    {
        return view('fleet.history', ['vehicles' => Vehicle::visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'registration'])]);
    }

    public function data(Request $request, TraccarService $traccar)
    {
        $data = $request->validate(['vehicle_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d\TH:i', 'to' => 'required|date_format:Y-m-d\TH:i|after:from']);
        $vehicle = Vehicle::visibleTo($request->user())->findOrFail($data['vehicle_id']);
        $from = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['from'], 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['to'], 'UTC');
        if ($from->diffInSeconds($to) > 86400 || $to->isFuture()) {
            return response()->json(['message' => 'Choose a past range of up to 24 hours.'], 422);
        }
        if (! $vehicle->traccar_device_id) {
            return response()->json(['message' => 'This vehicle has no linked tracker.'], 422);
        }
        try {
            $route = $traccar->getRoute($vehicle->traccar_device_id, $from->toIso8601String(), $to->toIso8601String());
            $events = $traccar->getEvents($vehicle->traccar_device_id, $from->toIso8601String(), $to->toIso8601String());
        } catch (TraccarException $error) {
            return response()->json(['message' => 'History could not be fetched. Check the tracking connection and retry.'], 503);
        }
        $points = [];
        $safeEvents = [];
        foreach ($route as $point) {
            if (! is_array($point) || (int) ($point['deviceId'] ?? 0) !== (int) $vehicle->traccar_device_id || ($point['valid'] ?? false) !== true) {
                continue;
            }
            try {
                if (empty($point['fixTime'])) {
                    continue;
                } $time = CarbonImmutable::parse($point['fixTime']);
            } catch (\Throwable $error) {
                continue;
            }
            if ($time < $from || $time > $to || ! is_numeric($point['latitude'] ?? null) || ! is_numeric($point['longitude'] ?? null)
                || abs((float) $point['latitude']) > 90 || abs((float) $point['longitude']) > 180) {
                continue;
            }
            $points[] = ['time' => $time->utc()->toIso8601String(), 'latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude'],
                'speed_kmh' => is_numeric($point['speed'] ?? null) ? round(max(0, (float) $point['speed']) * 1.852, 1) : null];
        }
        usort($points, fn ($a, $b) => strcmp($a['time'], $b['time']));
        foreach ($events as $event) {
            if (! is_array($event) || (int) ($event['deviceId'] ?? 0) !== (int) $vehicle->traccar_device_id) {
                continue;
            }
            try {
                if (empty($event['eventTime'])) {
                    continue;
                } $time = CarbonImmutable::parse($event['eventTime']);
            } catch (\Throwable $error) {
                continue;
            }
            if ($time < $from || $time > $to) {
                continue;
            }
            $safeEvents[] = ['time' => $time->utc()->toIso8601String(), 'type' => mb_substr((string) ($event['type'] ?? 'unknown'), 0, 100)];
        }
        usort($safeEvents, fn ($a, $b) => strcmp($a['time'], $b['time']));

        return response()->json(['vehicle' => $vehicle->name, 'points' => array_slice($points, 0, 5000), 'events' => array_slice($safeEvents, 0, 500),
            'points_truncated' => count($points) > 5000, 'events_truncated' => count($safeEvents) > 500, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]);
    }
}
