<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetUserStatus extends Command
{
    protected $signature = 'platform:user-status {email} {status : active or inactive}';

    protected $description = 'Activate or deactivate an account and invalidate its sessions';

    public function handle(AuditLogger $audit): int
    {
        if (! in_array($this->argument('status'), ['active', 'inactive'], true)) {
            $this->error('Use active or inactive.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($audit) {
            // Lock all super admins in a consistent order to protect the last active admin.
            $admins = User::where('role', Role::SuperAdmin->value)->orderBy('id')->lockForUpdate()->get();
            $user = User::where('email', strtolower($this->argument('email')))->lockForUpdate()->first();
            if (! $user) {
                $this->error('Account not found.');

                return self::FAILURE;
            }
            $active = $this->argument('status') === 'active';
            if (! $active && $user->is_active && $user->role === Role::SuperAdmin && $admins->where('is_active', true)->count() <= 1) {
                $this->error('Cannot deactivate the last active super admin.');

                return self::FAILURE;
            }
            $user->is_active = $active;
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record($active ? 'user.activated_cli' : 'user.deactivated_cli', null, $user);
            $this->info('Account status updated.');

            return self::SUCCESS;
        });
    }
}
