<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $fleet = Vehicle::visibleTo($user);
        $steps = [
            ['Add vehicles', $fleet->exists(), 'vehicles.create', 'Register a vehicle and its exact tracker identifier.'],
            ['Link a tracker', (clone $fleet)->where('sync_status', 'synced')->whereNotNull('traccar_device_id')->exists(), 'devices.index', 'A linked device is registered; this does not prove that GPS is arriving.'],
            ['Configure event preferences', DB::table('notification_preferences')->where('user_id', $user->id)->where('email_enabled', true)->exists(), 'notifications.index', 'Select events for your account. Local mode previews messages only.'],
        ];

        return view('fleet.readiness', compact('steps'));
    }
}
