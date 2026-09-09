<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaynowGateway;
use App\Services\SubscriptionBilling;
use Illuminate\Console\Command;

class ReconcilePayment extends Command
{
    protected $signature = 'billing:reconcile {reference} {--poll-url= : Merchant-verified Paynow poll URL for an unconfirmed initiation}';

    protected $description = 'Verify a single payment with Paynow after a callback failure or merchant investigation';

    public function handle(PaynowGateway $gateway, SubscriptionBilling $billing): int
    {
        $payment = Payment::findOrFail($this->argument('reference'));
        try {
            if ($this->option('poll-url')) {
                $payment->poll_url = $gateway->safeUrl($this->option('poll-url'), true);
            }
            $billing->verify($payment);
            if ($payment->isDirty('poll_url')) {
                $payment->save();
            }
        } catch (\Throwable $error) {
            $this->error('Payment verification failed. Confirm the reference and poll URL with Paynow; no unverified access was granted.');

            return self::FAILURE;
        }
        $this->info('Verified payment status: '.$payment->fresh()->status);

        return self::SUCCESS;
    }
}
