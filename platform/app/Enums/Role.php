<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case FleetManager = 'fleet_manager';
    case Operator = 'operator';
    case Customer = 'customer';

    public function managesFleet(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin, self::FleetManager], true);
    }
}
