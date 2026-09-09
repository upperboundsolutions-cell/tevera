<?php

namespace App\Http\Controllers;

use App\Models\FleetGeofence;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\TraccarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GeofenceController extends Controller
{
    public function index(Request $request)
    {
        $vehicles = Vehicle::visibleTo($request->user())->orderBy('name')->get(['id', 'name']);

        return view('fleet.geofences', ['vehicles' => $vehicles, 'fences' => FleetGeofence::with('vehicle')->whereIn('vehicle_id', $vehicles->pluck('id'))->latest()->paginate(25)]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['vehicle_id' => 'required|integer', 'name' => 'required|string|max:120', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'radius' => 'required|integer|between:25,100000']);
        $vehicle = Vehicle::visibleTo($request->user())->findOrFail($data['vehicle_id']);
        Gate::authorize('update', $vehicle);
        if (! $vehicle->traccar_device_id) {
            return back()->withErrors(['vehicle_id' => 'Link the tracker first.']);
        }
        $fence = new FleetGeofence($data);
        $fence->vehicle_id = $vehicle->id;
        $fence->sync_key = (string) Str::uuid();
        $fence->save();
        $audit->record('geofence.created', $request->user(), $vehicle);

        return $this->sync($request, $fence, app(TraccarService::class));
    }

    public function sync(Request $request, FleetGeofence $fence, TraccarService $traccar)
    {
        Gate::authorize('update', $fence->vehicle);

        return Cache::lock('geofence:'.$fence->id, 180)->block(3, function () use ($fence, $traccar) {
            $fence->refresh();
            try {
                $remote = collect($traccar->allGeofences())->first(fn ($g) => ($g['attributes']['teveraFenceKey'] ?? null) === $fence->sync_key);
                if (! $remote) {
                    if ($fence->remote_id) {
                        throw new \RuntimeException('Remote record missing');
                    }
                    $remote = $traccar->createGeofence(['name' => $fence->name, 'area' => "CIRCLE ({$fence->latitude} {$fence->longitude}, {$fence->radius})", 'attributes' => ['teveraFenceKey' => $fence->sync_key]]);
                }
                if (empty($remote['id'])) {
                    throw new \RuntimeException;
                }
                $fence->remote_id = $remote['id'];
                $fence->save();
                $linked = collect($traccar->getGeofences($fence->vehicle->traccar_device_id))->contains(fn ($g) => (int) ($g['id'] ?? 0) === (int) $fence->remote_id);
                if (! $linked) {
                    $traccar->linkGeofence($fence->vehicle->traccar_device_id, $fence->remote_id);
                }
                $fence->sync_status = 'synced';
                $fence->save();

                return redirect()->route('geofences.index')->with('status', 'Geofence linked. Future entry/exit events depend on received GPS reports.');
            } catch (\Throwable $error) {
                $fence->sync_status = 'failed';
                $fence->save();

                return redirect()->route('geofences.index')->withErrors(['geofence' => 'Geofence synchronization failed. Check Traccar, then retry this record; do not create a duplicate.']);
            }
        });
    }

    public function destroy(Request $request, FleetGeofence $fence, TraccarService $traccar, AuditLogger $audit)
    {
        Gate::authorize('update', $fence->vehicle);

        return Cache::lock('geofence:'.$fence->id, 180)->block(3, function () use ($request, $fence, $traccar, $audit) {
            try {
                $remote = collect($traccar->allGeofences())->first(fn ($g) => ($g['attributes']['teveraFenceKey'] ?? null) === $fence->sync_key);
                if ($remote) {
                    $traccar->deleteGeofence($remote['id']);
                }
                $audit->record('geofence.deleted', $request->user(), $fence->vehicle);
                $fence->delete();

                return back()->with('status', 'Geofence deleted.');
            } catch (\Throwable $error) {
                return back()->withErrors(['geofence' => 'Deletion could not be confirmed. The local record is retained; retry after checking Traccar.']);
            }
        });
    }
}
