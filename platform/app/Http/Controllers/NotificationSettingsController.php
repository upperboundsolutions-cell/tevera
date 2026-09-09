<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\FleetEventNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
{
    public function index(Request $request)
    {
        $preference = DB::table('notification_preferences')->where('user_id', $request->user()->id)->first();

        return view('fleet.notifications', ['preference' => $preference, 'types' => FleetEventNotifications::TYPES, 'deliveries' => DB::table('notification_deliveries')->where('user_id', $request->user()->id)->whereIn('vehicle_id', Vehicle::visibleTo($request->user())->select('id'))->latest()->paginate(20)]);
    }

    public function save(Request $request)
    {
        $data = $request->validate(['events' => 'nullable|array|max:5', 'events.*' => ['string', Rule::in(array_keys(FleetEventNotifications::TYPES))], 'email_enabled' => 'nullable|boolean']);
        DB::table('notification_preferences')->updateOrInsert(['user_id' => $request->user()->id], ['events' => json_encode($data['events'] ?? []), 'email_enabled' => $request->boolean('email_enabled'), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Notification preferences saved for your account.');
    }
}
