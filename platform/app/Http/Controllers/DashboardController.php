<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $fleet = Vehicle::visibleTo($request->user());
        $metrics = (clone $fleet)->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count, COALESCE(SUM(CASE WHEN traccar_device_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS linked_count, COALESCE(SUM(CASE WHEN sync_status IN ('failed', 'pending') THEN 1 ELSE 0 END), 0) AS attention_count")->first();

        return view('dashboard', [
            'vehicleCount' => (int) $metrics->total,
            'activeCount' => (int) $metrics->active_count,
            'linkedCount' => (int) $metrics->linked_count,
            'attentionCount' => (int) $metrics->attention_count,
            'recentVehicles' => (clone $fleet)->with('customer')->latest()->limit(5)->get(),
        ]);
    }
}
