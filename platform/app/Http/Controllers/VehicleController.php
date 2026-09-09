<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Exceptions\TraccarException;
use App\Http\Requests\VehicleRequest;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TraccarService;
use App\Services\VehicleManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleController extends Controller
{
    public function index(Request $request, TraccarService $traccar)
    {
        $data = $request->validate(['search' => 'nullable|string|max:128', 'status' => 'nullable|in:active,inactive']);
        $query = Vehicle::visibleTo($request->user())->with(['customer', 'driver'])->orderBy('name');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('registration', 'like', '%'.$data['search'].'%')->orWhere('unique_id', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('is_active', $data['status'] === 'active');
        }
        $vehicles = $query->paginate(25)->withQueryString();
        $ids = $vehicles->getCollection()->pluck('traccar_device_id')->filter()->all();
        $deviceData = collect();
        $positionData = collect();
        $connectionError = null;
        try {
            if ($ids) {
                $deviceData = collect($traccar->getDevicesByIds($ids))->keyBy('id');
                $positionData = collect($traccar->getLatestPositionsForDevices($ids))->keyBy('deviceId');
            }
        } catch (TraccarException $e) {
            $connectionError = $e->getMessage();
        }

        return view('fleet.index', ['vehicles' => $vehicles, 'devices' => $request->routeIs('devices.index'), 'deviceData' => $deviceData, 'positionData' => $positionData, 'connectionError' => $connectionError]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Vehicle::class);

        return $this->form($request, new Vehicle);
    }

    public function edit(Request $request, Vehicle $vehicle)
    {
        Gate::authorize('update', $vehicle);

        return $this->form($request, $vehicle);
    }

    private function form(Request $request, Vehicle $vehicle)
    {
        $request->validate(['customer' => 'nullable|integer']);
        $customers = Customer::where('is_active', true)->when($request->user()->role !== Role::SuperAdmin, fn ($q) => $q->whereKey($request->user()->customer_id))->orderBy('name')->get();
        $customerId = $vehicle->customer_id ?? ($request->user()->role === Role::SuperAdmin ? $request->integer('customer') : $request->user()->customer_id);
        if ($customerId) {
            abort_unless($customers->contains('id', $customerId), 403);
        }

        return view('fleet.form', [
            'vehicle' => $vehicle, 'customers' => $customers, 'customerId' => $customerId,
            'users' => $customerId ? User::where('customer_id', $customerId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'drivers' => $customerId ? Driver::where('customer_id', $customerId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'assignedUsers' => $vehicle->exists ? $vehicle->users()->pluck('users.id')->all() : [],
        ]);
    }

    public function store(VehicleRequest $request, VehicleManager $manager)
    {
        try {
            $vehicle = $manager->create($request->user(), $request->validated());
        } catch (LockTimeoutException) {
            return back()->withInput()->withErrors(['sync' => 'Device synchronization is busy. Please try again.']);
        }

        return $this->result($vehicle);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle, VehicleManager $manager)
    {
        try {
            return $this->result($manager->update($request->user(), $vehicle, $request->validated()));
        } catch (LockTimeoutException) {
            return back()->withInput()->withErrors(['sync' => 'Device synchronization is busy. Please try again.']);
        }
    }

    public function sync(Request $request, Vehicle $vehicle, VehicleManager $manager)
    {
        Gate::authorize('update', $vehicle);
        try {
            return $this->result($manager->sync($request->user(), $vehicle));
        } catch (LockTimeoutException) {
            return back()->withErrors(['sync' => 'Another update is in progress. Try again shortly.']);
        }
    }

    public function status(Request $request, Vehicle $vehicle, VehicleManager $manager)
    {
        Gate::authorize('update', $vehicle);
        $request->validate(['is_active' => 'required|boolean']);
        try {
            return $this->result($manager->setActive($request->user(), $vehicle, $request->boolean('is_active')));
        } catch (LockTimeoutException) {
            return back()->withErrors(['sync' => 'Another update is in progress. Try again shortly.']);
        }
    }

    private function result(Vehicle $vehicle)
    {
        $response = redirect()->route('vehicles.edit', $vehicle);

        return $vehicle->sync_status === 'synced'
            ? $response->with('status', 'Vehicle saved and synchronized with Traccar.')
            : $response->withErrors(['sync' => 'Vehicle saved locally. '.$vehicle->sync_error.' Use Retry sync after resolving the issue.']);
    }
}
