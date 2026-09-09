<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'platform:create-user {--role=super_admin} {--customer= : Existing customer ID}';

    protected $description = 'Create an account using an interactive hidden password prompt';

    public function handle(AuditLogger $audit): int
    {
        $role = Role::tryFrom((string) $this->option('role'));
        if (! $role) {
            $this->error('Invalid role.');

            return self::FAILURE;
        }
        $customer = $this->option('customer') ? Customer::find($this->option('customer')) : null;
        if (($role !== Role::SuperAdmin && ! $customer?->is_active) || ($role === Role::SuperAdmin && $this->option('customer'))) {
            $this->error('Tenant roles require an active --customer ID. Super admins must not have a customer.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Name'), 'email' => strtolower(trim((string) $this->ask('Email'))), 'password' => $this->secret('Password (12+ characters, upper/lowercase, number and symbol)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'password' => ['required', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $role, $customer, $audit) {
            $user = new User($data);
            $user->role = $role;
            $user->customer_id = $customer?->id;
            $user->is_active = true;
            $user->save();
            $audit->record('user.created_cli', null, $user);
        });
        $this->info('Account created.');

        return self::SUCCESS;
    }
}
