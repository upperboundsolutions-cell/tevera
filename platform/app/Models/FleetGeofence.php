<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetGeofence extends Model
{
    protected $guarded = ['id', 'vehicle_id', 'sync_key', 'remote_id', 'sync_status'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
