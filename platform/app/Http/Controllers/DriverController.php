<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Driver;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::where('is_active', true)->when($request->user()->role !== Role::SuperAdmin, fn ($q) => $q->whereKey($request->user()->customer_id))->orderBy('name')->get();

        return view('fleet.drivers', ['customers' => $customers, 'drivers' => Driver::whereIn('customer_id', $customers->pluck('id'))->orderBy('name')->paginate(25)]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $customerRule = Rule::exists('customers', 'id')->where('is_active', true);
        if ($request->user()->role !== Role::SuperAdmin) {
            $customerRule->where('id', $request->user()->customer_id);
        }
        $data = $request->validate([
            'customer_id' => ['required', 'integer', $customerRule], 'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:255',
            'licence_number' => 'nullable|string|max:255', 'licence_expiry' => 'nullable|date',
        ]);
        DB::transaction(function () use ($data, $request, $audit) {
            $driver = new Driver($data);
            $driver->customer_id = $data['customer_id'];
            $driver->save();
            $audit->record('driver.created', $request->user(), $driver);
        });

        return back()->with('status', 'Driver added. Assign them from the vehicle edit screen.');
    }
}
