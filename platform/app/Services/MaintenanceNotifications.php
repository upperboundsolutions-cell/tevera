<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MaintenanceNotifications
{
    public function run(): void
    {
        $lock = Cache::lock('fleet-maintenance-alerts', 1800);
        if (! $lock->get()) {
            return;
        }
        try {
            foreach (DB::table('notification_preferences')->where(fn ($q) => $q->where('email_enabled', true)->orWhere('whatsapp_enabled', true))->get() as $pref) {
                if (! in_array('maintenanceDue', json_decode($pref->events, true) ?? [])) {
                    continue;
                }
                $u = User::find($pref->user_id);
                if (! $u || ! $u->canAccessPlatform() || ($u->customer && ! $u->customer->subscriptionAllowsAccess())) {
                    continue;
                }
                $tasks = DB::table('maintenance_tasks')->join('vehicles', 'vehicles.id', '=', 'maintenance_tasks.vehicle_id')->whereIn('vehicle_id', Vehicle::visibleTo($u)->select('id'))->whereNull('completed_at')->where(fn ($q) => $q->whereDate('due_on', '<=', today())->orWhereColumn('due_odometer', '<=', 'vehicles.odometer'))->select('maintenance_tasks.id', 'title', 'vehicles.name')->get();
                foreach ($tasks as $task) {
                    $key = ['user_id' => $u->id, 'task_id' => $task->id, 'sent_on' => today()->toDateString()];
                    if (! DB::table('maintenance_deliveries')->insertOrIgnore($key + ['status' => 'sending'])) {
                        continue;
                    }
                    $status = 'disabled';
                    $wa = null;
                    if ($pref->email_enabled) {
                        try {
                            Mail::raw('Service due: '.$task->title."\nVehicle: ".$task->name."\nReview Maintenance in TEVERA.", fn ($m) => $m->to($u->email)->subject('TEVERA: maintenance due'));
                            $status = in_array(config('mail.default'), ['log', 'array']) ? 'previewed' : 'accepted';
                        } catch (\Throwable) {
                            $status = 'uncertain';
                        }
                    }
                    if ($pref->whatsapp_enabled) {
                        $wa = app(WhatsAppGateway::class)->send($pref->whatsapp_number, 'Maintenance due: '.$task->title, $task->name, now()->toIso8601String());
                    }
                    DB::table('maintenance_deliveries')->where($key)->update(['status' => $status, 'whatsapp_status' => $wa]);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
