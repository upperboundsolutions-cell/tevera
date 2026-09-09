<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubscriptionBilling
{
    public function __construct(private PaynowGateway $gateway, private AuditLogger $audit) {}

    public function checkout(User $actor, Plan $plan): Payment
    {
        abort_unless($actor->role === Role::Admin && $actor->canAccessPlatform(), 403);

        return Cache::lock('billing:'.$actor->customer_id, 60)->block(3, function () use ($actor, $plan) {
            $payment = DB::transaction(function () use ($actor, $plan) {
                $customer = Customer::lockForUpdate()->findOrFail($actor->customer_id);
                $plan->refresh();
                if (! $this->gateway->ready() || ! $plan->is_active || $plan->currency !== config('paynow.currency') || $plan->amount_cents < 1) {
                    throw ValidationException::withMessages(['plan_id' => 'This plan is not available for checkout yet.']);
                }
                if ($customer->billing_review_required) {
                    throw ValidationException::withMessages(['plan_id' => 'A payment needs merchant review. Contact support before making another payment.']);
                }
                if ($customer->vehicles()->count() > $plan->vehicle_limit) {
                    throw ValidationException::withMessages(['plan_id' => 'Choose a plan that covers all existing vehicles.']);
                }
                if ($customer->subscription_expires_at?->isFuture() && $customer->plan_id !== $plan->id) {
                    throw ValidationException::withMessages(['plan_id' => 'Plan changes are available after the current paid period ends. You can renew your current plan now.']);
                }
                $pending = Payment::where('customer_id', $customer->id)->whereIn('status', ['initiating', 'pending', 'unknown'])->first();
                if ($pending) {
                    return $pending;
                }
                $payment = new Payment;
                $payment->id = (string) Str::uuid();
                $payment->fill(['customer_id' => $customer->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
                    'amount_cents' => $plan->amount_cents, 'currency' => $plan->currency, 'vehicle_limit' => $plan->vehicle_limit,
                    'billing_months' => $plan->billing_months]);
                $payment->save();
                $this->audit->record('payment.created', $actor, $payment);

                return $payment;
            });
            if (! $payment->wasRecentlyCreated) {
                return $payment;
            }
            try {
                $result = $this->gateway->initiate($payment, $actor->email);
                DB::transaction(function () use ($payment, $result) {
                    $fresh = Payment::lockForUpdate()->findOrFail($payment->id);
                    $fresh->fill($result);
                    if (in_array($fresh->status, ['initiating', 'unknown'], true)) {
                        $fresh->status = 'pending';
                    }
                    $fresh->save();
                });
            } catch (\Throwable $error) {
                // A timeout can occur after Paynow created the transaction. Never blindly retry it.
                Payment::whereKey($payment->id)->where('status', 'initiating')->update(['status' => 'unknown']);
                $this->audit->record('payment.initiation_unconfirmed', $actor, $payment);
            }

            return $payment;
        });
    }

    public function verify(Payment $payment): void
    {
        $result = $this->gateway->poll($payment);
        $amount = $result['amount'] ?? '';
        if (! preg_match('/^\d{1,10}\.\d{2}$/', $amount) || $amount !== number_format($payment->amount_cents / 100, 2, '.', '')
            || ($result['reference'] ?? '') !== $payment->id || empty($result['paynowreference'])) {
            throw new RuntimeException('Payment reference or amount does not match.');
        }
        $status = strtolower($result['status'] ?? '');
        if (! in_array($status, ['paid', 'awaiting delivery', 'delivered', 'created', 'sent', 'cancelled', 'disputed', 'refunded'], true)) {
            throw new RuntimeException('Unrecognized payment status.');
        }
        DB::transaction(function () use ($payment, $result, $status) {
            $customer = Customer::lockForUpdate()->findOrFail($payment->customer_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $payment->gateway_reference = $result['paynowreference'];
            if (in_array($status, ['paid', 'awaiting delivery', 'delivered'], true)) {
                if (! $payment->applied_at) {
                    $start = $customer->subscription_expires_at?->isFuture() ? $customer->subscription_expires_at->copy() : now();
                    $payment->period_start = $start;
                    $payment->period_end = $payment->billing_months
                        ? $start->copy()->addMonthsNoOverflow($payment->billing_months)
                        : $start->copy()->addDays(30);
                    $payment->applied_at = now();
                    $customer->plan_id = $payment->plan_id;
                    $customer->vehicle_limit = $payment->vehicle_limit;
                    $customer->billing_required = true;
                    $customer->subscription_expires_at = $payment->period_end;
                    $customer->save();
                    $this->audit->record('subscription.renewed', null, $payment);
                }
                $payment->status = 'paid';
            } elseif (in_array($status, ['disputed', 'refunded'], true)) {
                $payment->status = $status;
                if ($payment->applied_at) {
                    $customer->billing_review_required = true;
                    $customer->save();
                }
                $this->audit->record('payment.'.$status, null, $payment);
            } elseif (! $payment->applied_at) {
                $payment->status = $status === 'cancelled' ? 'cancelled' : 'pending';
            }
            $payment->save();
        });
    }
}
