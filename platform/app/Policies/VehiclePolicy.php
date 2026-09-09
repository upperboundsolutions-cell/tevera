<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function create(User $user): bool
    {
        return $user->canAccessPlatform() && $user->role->managesFleet();
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return Vehicle::visibleTo($user)->whereKey($vehicle->id)->exists();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->role->managesFleet() && $this->view($user, $vehicle);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $this->update($user, $vehicle);
    }
}
