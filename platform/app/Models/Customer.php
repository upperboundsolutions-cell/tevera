<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'email', 'phone'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'billing_required' => 'boolean', 'subscription_expires_at' => 'datetime', 'trial_ends_at' => 'datetime'];
    }

    public function subscriptionAllowsAccess(): bool
    {
        return ! $this->billing_review_required && (! $this->billing_required || $this->subscription_expires_at?->isFuture() || $this->trial_ends_at?->isFuture());
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
