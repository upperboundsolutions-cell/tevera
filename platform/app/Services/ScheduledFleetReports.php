<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ScheduledFleetReports
{
    public function run(): void
    {
        $lock = Cache::lock('fleet-scheduled-reports', 3600);
        if (! $lock->get()) {
            return;
        }
        try {
            foreach (DB::table('report_schedules')->where('next_run_at', '<=', now())->get() as $s) {
                $u = User::find($s->user_id);
                if (! $u || ! $u->canAccessPlatform() || ($u->customer && ! $u->customer->subscriptionAllowsAccess())) {
                    continue;
                }
                // Claim before sending. Ambiguous delivery is never automatically retried.
                $days = $s->frequency === 'weekly' ? 7 : 1;
                DB::table('report_schedules')->where('id', $s->id)->update(['next_run_at' => now()->addDays($days)->startOfDay()->addHours(6), 'last_status' => 'sending', 'updated_at' => now()]);
                $to = CarbonImmutable::now('UTC')->startOfDay()->subSecond();
                $from = $to->subDays($days - 1)->startOfDay();
                $lines = ['TEVERA fleet report: '.$from->toDateString().' to '.$to->toDateString().' UTC', 'Figures reflect available GPS samples. Driver names are current assignments.'];
                foreach (Vehicle::visibleTo($u)->where('is_active', true)->with('driver')->cursor() as $v) {
                    try {
                        $row = app(FleetInsights::class)->report($v, $from, $to);
                        $lines[] = $v->name.': '.($row['distance_km'] ?? 'Unknown').' km; idle '.($row['idle_minutes'] ?? 'Unknown').' min; speeding '.$row['speeding'].'; harsh events '.$row['harsh_events'].'; score '.($row['score'] ?? 'Unavailable').'; '.$row['status'];
                    } catch (\Throwable) {
                        $lines[] = $v->name.': tracking unavailable';
                    }
                }
                $tasks = DB::table('maintenance_tasks')->join('vehicles', 'vehicles.id', '=', 'maintenance_tasks.vehicle_id')->whereIn('vehicle_id', Vehicle::visibleTo($u)->select('id'))->whereNull('completed_at')->where(fn ($q) => $q->whereDate('due_on', '<=', today())->orWhereColumn('due_odometer', '<=', 'vehicles.odometer'))->select('title', 'vehicles.name')->get();
                foreach ($tasks as $t) {
                    $lines[] = 'Service due: '.$t->name.' - '.$t->title;
                }
                try {
                    Mail::raw(implode("\n", $lines), fn ($m) => $m->to($u->email)->subject('TEVERA fleet summary'));
                    $status = in_array(config('mail.default'), ['log', 'array']) ? 'previewed' : 'accepted';
                } catch (\Throwable) {
                    $status = 'uncertain';
                }
                DB::table('report_schedules')->where('id', $s->id)->update(['last_status' => $status, 'updated_at' => now()]);
            }
        } finally {
            $lock->release();
        }
    }
}
