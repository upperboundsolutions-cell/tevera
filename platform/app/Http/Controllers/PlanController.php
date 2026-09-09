<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('billing.plans', ['plans' => Plan::orderBy('amount_cents')->get()]);
    }

    public function save(Request $request, AuditLogger $audit, ?Plan $plan = null)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'amount_cents' => 'required|integer|min:1|max:100000000',
            'currency' => 'required|string|regex:/^[A-Z]{3}$/', 'vehicle_limit' => 'required|integer|min:1|max:1000000', 'is_active' => 'required|boolean',
            'billing_months' => 'sometimes|required|integer|in:0,1,12']);
        $plan ??= new Plan;
        $plan->fill($data)->save();
        $audit->record('plan.saved', $request->user(), $plan);

        return back()->with('status', 'Plan saved. Changes apply to new checkout attempts; existing payments retain their original price.');
    }
}
