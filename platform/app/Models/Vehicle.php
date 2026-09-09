<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Vehicle extends Model
{
    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'registration', 'make', 'model', 'year', 'vin', 'vehicle_type', 'fuel_type', 'tank_capacity', 'odometer', 'sim_number', 'tracker_model', 'tracker_protocol', 'installation_date', 'notes'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'installation_date' => 'date', 'driver_id' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vehicle_user');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessPlatform()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->role === Role::SuperAdmin) {
            return $query;
        }
        $query->where('customer_id', $user->customer_id);
        if (! $user->role->managesFleet()) {
            $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));
        }

        return $query;
    }
}
