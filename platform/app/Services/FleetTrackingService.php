<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Gate;

class FleetTrackingService
{
    public function __construct(private TraccarService $traccar) {}

    public function latestPositions(User $user, Vehicle $vehicle): array
    {
        Gate::forUser($user)->authorize('view', $vehicle);
        if (! $vehicle->is_active || ! $vehicle->traccar_device_id) {
            return [];
        }

        // Defense in depth: upstream output is filtered as well as the request.
        return array_values(array_filter($this->traccar->getLatestPositions($vehicle->traccar_device_id),
            fn (array $position) => (int) ($position['deviceId'] ?? 0) === (int) $vehicle->traccar_device_id));
    }
}
