<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Approved company plans. Preserve subsequent administrator edits on reseeding.
        foreach ([['TEVERA Monthly', 3000, 1], ['TEVERA Yearly', 29900, 12]] as [$name, $amount, $months]) {
            Plan::firstOrCreate(['name' => $name], ['amount_cents' => $amount, 'currency' => 'USD', 'billing_months' => $months, 'vehicle_limit' => 10, 'is_active' => true]);
        }
    }
}
