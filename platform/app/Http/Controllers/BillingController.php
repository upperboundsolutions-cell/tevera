<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\PaynowGateway;
use App\Services\SubscriptionBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function index(Request $request, PaynowGateway $gateway)
    {
        $super = $request->user()->role === Role::SuperAdmin;
        $payments = Payment::when(! $super, fn ($q) => $q->where('customer_id', $request->user()->customer_id))->latest()->paginate(25);

        return view('billing.index', ['payments' => $payments, 'plans' => Plan::where('is_active', true)->get(),
            'customer' => $request->user()->customer, 'ready' => $gateway->ready(), 'super' => $super]);
    }

    public function checkout(Request $request, SubscriptionBilling $billing)
    {
        abort_unless($request->user()->role === Role::Admin, 403);
        $data = $request->validate(['plan_id' => 'required|integer|exists:plans,id']);
        $payment = $billing->checkout($request->user(), Plan::findOrFail($data['plan_id']));

        return redirect()->route('billing.payment', $payment);
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        abort_unless($request->user()->role === Role::SuperAdmin || ($request->user()->role === Role::Admin && $request->user()->customer_id === $payment->customer_id), 403);
    }

    public function show(Request $request, Payment $payment)
    {
        $this->authorizePayment($request, $payment);

        return view('billing.payment', compact('payment'));
    }

    public function check(Request $request, Payment $payment, SubscriptionBilling $billing)
    {
        $this->authorizePayment($request, $payment);
        try {
            $billing->verify($payment);
        } catch (\Throwable $error) {
            return back()->withErrors(['payment' => 'Payment could not be verified. No subscription was changed. Try again or contact support with your payment reference.']);
        }

        return back()->with('status', 'Payment status checked securely with Paynow.');
    }

    public function webhook(Request $request, PaynowGateway $gateway, SubscriptionBilling $billing)
    {
        try {
            $fields = $gateway->verified($request->getContent());
        } catch (\Throwable $error) {
            return response('Invalid signature', 400);
        }
        $payment = Payment::find($fields['reference'] ?? '');
        if (! $payment) {
            return response('Unknown reference', 404);
        }
        try {
            // Recover an initiation timeout only from a signed callback, then independently poll it.
            if (! $payment->poll_url) {
                $payment->update(['poll_url' => $gateway->safeUrl($fields['pollurl'] ?? '', true)]);
            }
            $billing->verify($payment);
        } catch (\Throwable $error) {
            return response('Verification pending', 503);
        }

        return response('OK');
    }

    public function audit(Request $request)
    {
        $events = DB::table('audit_logs')->when($request->user()->role !== Role::SuperAdmin,
            fn ($q) => $q->where('customer_id', $request->user()->customer_id))->orderByDesc('id')->paginate(50);

        return view('billing.audit', compact('events'));
    }
}
