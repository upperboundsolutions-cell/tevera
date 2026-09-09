<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    public function record(string $action, ?User $actor = null, ?Model $subject = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actor?->id, 'customer_id' => $subject instanceof Customer ? $subject->id : ($subject?->customer_id ?? $actor?->customer_id), 'action' => $action,
            'subject_type' => $subject ? $subject::class : null, 'subject_id' => $subject instanceof Payment ? null : $subject?->id,
            'subject_reference' => $subject instanceof Payment ? $subject->id : null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now(),
        ]);
    }
}
