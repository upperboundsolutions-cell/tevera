<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateCustomer extends Command
{
    protected $signature = 'platform:create-customer';

    protected $description = 'Create an active customer workspace interactively';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Customer name'), 'email' => $this->ask('Contact email (optional)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::create($data);
            DB::table('audit_logs')->insert([
                'customer_id' => $customer->id, 'action' => 'customer.created_cli',
                'subject_type' => Customer::class, 'subject_id' => $customer->id, 'created_at' => now(),
            ]);

            return $customer;
        });
        $this->info('Customer created. ID: '.$customer->id);

        return self::SUCCESS;
    }
}
