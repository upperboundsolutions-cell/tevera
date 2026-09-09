<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    public function index()
    {
        return view('fleet.customers', ['customers' => Customer::withCount(['users', 'vehicles'])->orderBy('name')->paginate(25)]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        if ($request->filled('admin_email')) {
            $request->merge(['admin_email' => Str::lower(trim($request->input('admin_email')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40',
            'vehicle_limit' => 'nullable|integer|min:1|max:1000000',
            'admin_name' => 'nullable|required_with:admin_email,admin_password|string|max:255',
            'admin_email' => 'nullable|required_with:admin_name,admin_password|email|max:255|unique:users,email',
            'admin_password' => ['nullable', 'required_with:admin_name,admin_email', 'confirmed', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $customer = DB::transaction(function () use ($data, $request, $audit) {
            $customer = Customer::create($data);
            $customer->vehicle_limit = $data['vehicle_limit'] ?? null;
            $customer->save();
            if (! empty($data['admin_email'])) {
                $admin = new User(['name' => $data['admin_name'], 'email' => $data['admin_email'], 'password' => $data['admin_password']]);
                $admin->customer_id = $customer->id;
                $admin->role = Role::Admin;
                $admin->save();
                $audit->record('user.created', $request->user(), $admin);
            }
            $audit->record('customer.created', $request->user(), $customer);

            return $customer;
        });

        return redirect()->route('customers.edit', $customer)->with('status', 'Company workspace created. Add vehicles and share the login address with its administrator.');
    }

    public function edit(Customer $customer)
    {
        $customer->loadCount(['users', 'vehicles']);

        return view('fleet.company', compact('customer'));
    }

    public function update(Request $request, Customer $customer, AuditLogger $audit)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'vehicle_limit' => 'nullable|integer|min:1|max:1000000', 'billing_review_required' => 'sometimes|boolean', 'billing_required' => 'sometimes|boolean', 'trial_ends_at' => 'nullable|date']);
        DB::transaction(function () use ($customer, $data, $request, $audit) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            $customer->fill($data);
            $customer->vehicle_limit = $data['vehicle_limit'] ?? null;
            if (array_key_exists('billing_required', $data)) {
                $customer->billing_required = $data['billing_required'];
            }
            if (array_key_exists('billing_review_required', $data)) {
                $customer->billing_review_required = $data['billing_review_required'];
            }
            if (array_key_exists('trial_ends_at', $data)) {
                $customer->trial_ends_at = $data['trial_ends_at'];
            }
            $customer->save();
            $audit->record('customer.updated', $request->user(), $customer);
        });

        return back()->with('status', 'Company settings saved. Existing vehicles are retained when lowering a limit.');
    }

    public function status(Request $request, Customer $customer, AuditLogger $audit)
    {
        $request->validate(['is_active' => 'required|boolean']);
        DB::transaction(function () use ($request, $customer, $audit) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            $customer->is_active = $request->boolean('is_active');
            $customer->save();
            if (! $customer->is_active) {
                $users = User::where('customer_id', $customer->id)->where('role', '!=', Role::SuperAdmin->value);
                DB::table('sessions')->whereIn('user_id', (clone $users)->select('id'))->delete();
                $users->update(['remember_token' => Str::random(60)]);
            }
            $audit->record($customer->is_active ? 'customer.activated' : 'customer.suspended', $request->user(), $customer);
        });

        return back()->with('status', $customer->fresh()->is_active ? 'Company access restored.' : 'Company access suspended. GPS reception continues on the tracking server.');
    }
}
