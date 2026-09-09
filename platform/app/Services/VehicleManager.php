<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\TraccarException;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleManager
{
    public function __construct(private TraccarService $traccar, private AuditLogger $audit) {}

    public function create(User $actor, array $data): Vehicle
    {
        Gate::forUser($actor)->authorize('create', Vehicle::class);
        $vehicle = DB::transaction(function () use ($actor, $data) {
            $customer = Customer::lockForUpdate()->findOrFail($data['customer_id']);
            abort_unless($customer->is_active && ($actor->role === Role::SuperAdmin || $actor->customer_id === $customer->id), 403);
            if ($customer->vehicle_limit !== null && $customer->vehicles()->count() >= $customer->vehicle_limit) {
                throw ValidationException::withMessages(['customer_id' => 'This company has reached its vehicle limit. Contact the platform administrator.']);
            }
            $vehicle = new Vehicle;
            $vehicle->customer_id = $data['customer_id'];
            $vehicle->unique_id = $data['unique_id'];
            $vehicle->sync_key = (string) Str::uuid();
            $this->persist($vehicle, $data);
            $this->audit->record('vehicle.created', $actor, $vehicle);

            return $vehicle;
        });

        return $this->sync($actor, $vehicle);
    }

    public function update(User $actor, Vehicle $vehicle, array $data): Vehicle
    {
        return Cache::lock('vehicle-sync:'.$vehicle->id, 180)->block(5, function () use ($actor, $vehicle, $data) {
            $vehicle->refresh();
            Gate::forUser($actor)->authorize('update', $vehicle);
            DB::transaction(function () use ($actor, $vehicle, $data) {
                $this->persist($vehicle, $data);
                $this->audit->record('vehicle.updated', $actor, $vehicle);
            });

            return $this->push($actor, $vehicle);
        });
    }

    private function persist(Vehicle $vehicle, array $data): void
    {
        $previousDriver = $vehicle->driver_id;
        $vehicle->fill($data);
        $vehicle->odometer = $data['odometer'] ?? 0;
        $vehicle->driver_id = $data['driver_id'] ?? null;
        $vehicle->sync_status = 'pending';
        $vehicle->save();
        $vehicle->users()->sync($data['user_ids'] ?? []);
        if ($previousDriver !== $vehicle->driver_id) {
            DB::table('vehicle_driver_history')->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            if ($vehicle->driver_id) {
                DB::table('vehicle_driver_history')->insert(['vehicle_id' => $vehicle->id, 'driver_id' => $vehicle->driver_id, 'assigned_at' => now()]);
            }
        }
    }

    public function sync(User $actor, Vehicle $vehicle): Vehicle
    {
        return Cache::lock('vehicle-sync:'.$vehicle->id, 180)->block(5, function () use ($actor, $vehicle) {
            $vehicle->refresh();
            Gate::forUser($actor)->authorize('update', $vehicle);

            return $this->push($actor, $vehicle);
        });
    }

    public function setActive(User $actor, Vehicle $vehicle, bool $active): Vehicle
    {
        return Cache::lock('vehicle-sync:'.$vehicle->id, 180)->block(5, function () use ($actor, $vehicle, $active) {
            $vehicle->refresh();
            Gate::forUser($actor)->authorize('update', $vehicle);
            DB::transaction(function () use ($actor, $vehicle, $active) {
                $vehicle->is_active = $active;
                $vehicle->sync_status = 'pending';
                $vehicle->save();
                $this->audit->record($active ? 'vehicle.activated' : 'vehicle.deactivated', $actor, $vehicle);
            });

            return $this->push($actor, $vehicle);
        });
    }

    private function push(User $actor, Vehicle $vehicle): Vehicle
    {
        try {
            $remote = $vehicle->traccar_device_id
                ? $this->traccar->getDevice($vehicle->traccar_device_id)
                : $this->traccar->findDeviceByUniqueId($vehicle->unique_id);
            // Recover a timed-out creation only if it carries this exact provisioning key.
            if (! $vehicle->traccar_device_id && $remote && ($remote['attributes']['fleetatlasProvisioningKey'] ?? null) !== $vehicle->sync_key) {
                $vehicle->sync_status = 'failed';
                $vehicle->sync_error = 'This tracker ID already exists in Traccar. It was not linked to avoid assigning another vehicle’s device.';
                $vehicle->save();
                $this->audit->record('vehicle.sync_conflict', $actor, $vehicle);

                return $vehicle;
            }
            $payload = array_replace($remote ?? [], [
                'name' => $vehicle->name, 'uniqueId' => $vehicle->unique_id, 'phone' => $vehicle->sim_number,
                'model' => $vehicle->tracker_model, 'disabled' => ! $vehicle->is_active,
                'attributes' => array_replace($remote['attributes'] ?? [], [
                    'fleetatlasProvisioningKey' => $vehicle->sync_key, 'registration' => $vehicle->registration,
                ]),
            ]);
            $result = $remote ? $this->traccar->updateDevice((int) $remote['id'], $payload) : $this->traccar->createDevice($payload);
            if (empty($result['id'])) {
                throw new TraccarException('upstream');
            }
            $vehicle->traccar_device_id = $result['id'];
            $vehicle->sync_status = 'synced';
            $vehicle->sync_error = null;
            $vehicle->save();
            $this->audit->record('vehicle.synced', $actor, $vehicle);
        } catch (TraccarException $e) {
            $vehicle->sync_status = 'failed';
            $vehicle->sync_error = $e->getMessage();
            $vehicle->save();
            $this->audit->record('vehicle.sync_failed', $actor, $vehicle);
        }

        return $vehicle;
    }
}
