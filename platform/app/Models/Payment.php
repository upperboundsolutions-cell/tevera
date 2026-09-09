<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $hidden = ['poll_url'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }
}
