<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function positions(Request $request, TraccarService $traccar): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $vehicles = Vehicle::visibleTo($request->user())->where('is_active', true)->orderBy('name')->paginate(100);
        $ids = $vehicles->getCollection()->pluck('traccar_device_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        try {
            $positions = collect($ids ? $traccar->getLatestPositionsForDevices($ids) : [])->keyBy('deviceId');
            $devices = collect($ids ? $traccar->getDevicesByIds($ids) : [])->keyBy('id');
        } catch (TraccarException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'vehicles' => $vehicles->getCollection()->map(function (Vehicle $vehicle) use ($positions, $devices) {
                $position = $positions->get($vehicle->traccar_device_id);
                $device = $devices->get($vehicle->traccar_device_id);

                return [
                    'id' => $vehicle->id, 'name' => $vehicle->name, 'registration' => $vehicle->registration,
                    'status' => $device['status'] ?? 'unknown', 'lastUpdate' => $device['lastUpdate'] ?? null,
                    'position' => $position ? array_intersect_key($position, array_flip([
                        'latitude', 'longitude', 'speed', 'altitude', 'course', 'fixTime', 'serverTime', 'valid',
                    ])) + ['attributes' => array_intersect_key($position['attributes'] ?? [], array_flip(['ignition', 'motion', 'batteryLevel', 'battery', 'sat', 'fuel']))] : null,
                ];
            })->values(),
            'page' => $vehicles->currentPage(), 'lastPage' => $vehicles->lastPage(), 'total' => $vehicles->total(),
            'fetchedAt' => now()->toIso8601String(),
        ]);
    }
}
