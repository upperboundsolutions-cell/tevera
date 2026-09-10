<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrackingShareController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate(['vehicle_id' => 'required|integer', 'label' => 'required|string|max:100', 'hours' => 'required|integer|min:1|max:168']);
        $v = Vehicle::visibleTo($r->user())->where('is_active', true)->whereNotNull('traccar_device_id')->findOrFail($d['vehicle_id']);
        $token = bin2hex(random_bytes(32));
        DB::table('tracking_shares')->insert(['vehicle_id' => $v->id, 'user_id' => $r->user()->id, 'token_hash' => hash('sha256', $token), 'label' => $d['label'], 'expires_at' => now()->addHours($d['hours']), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('share_url', route('share.show', $token))->with('status', 'Tracking link created. Anyone with this link can view this vehicle until it expires or you revoke it.');
    }

    public function revoke(Request $r, int $share)
    {
        $q = DB::table('tracking_shares')->where('id', $share)->whereIn('vehicle_id', Vehicle::visibleTo($r->user())->select('id'));
        abort_unless($q->exists(), 404);
        $q->update(['revoked_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Tracking link revoked.');
    }

    public function show(string $token, TraccarService $traccar)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $share = DB::table('tracking_shares')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($share, 404);
        $owner = User::find($share->user_id);
        abort_unless($owner && $owner->canAccessPlatform() && (! $owner->customer || $owner->customer->subscriptionAllowsAccess()), 404);
        $v = Vehicle::visibleTo($owner)->where('is_active', true)->findOrFail($share->vehicle_id);
        $position = null;
        $unavailable = false;
        try {
            foreach ($traccar->getLatestPositions((int) $v->traccar_device_id) as $p) {
                if ((int) ($p['deviceId'] ?? 0) !== (int) $v->traccar_device_id || ($p['valid'] ?? false) !== true || ! is_numeric($p['latitude'] ?? null) || ! is_numeric($p['longitude'] ?? null) || abs($p['latitude']) > 90 || abs($p['longitude']) > 180 || empty($p['fixTime'])) {
                    continue;
                }
                try {
                    $time = CarbonImmutable::parse($p['fixTime']);
                } catch (\Throwable) {
                    continue;
                }
                if ($time->gt(now()->addMinute())) {
                    continue;
                }
                $position = ['latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude'], 'time' => $time->toIso8601String(), 'stale' => $time->lt(now()->subMinutes(10))];
                break;
            }
        } catch (TraccarException) {
            $unavailable = true;
        }

        return response()->view('fleet.shared', compact('share', 'position', 'unavailable'))->header('X-Robots-Tag','noindex, nofollow')->header('Referrer-Policy','no-referrer');
    }
}
