<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaynowGateway;
use App\Services\SubscriptionBilling;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['paynow.integration_key' => 'test-only-key', 'paynow.integration_id' => '123', 'paynow.currency' => 'USD', 'paynow.public_url' => 'https://tevera.example', 'paynow.enabled' => true]);
    }

    private function admin(?Customer $customer = null): User
    {
        return User::factory()->create(['role' => Role::Admin, 'customer_id' => ($customer ?? Customer::create(['name' => 'Company A']))->id]);
    }

    public function test_calendar_periods_use_payment_snapshot_and_handle_month_end(): void
    {
        $this->travelTo(Carbon::parse('2028-01-31 12:00:00', 'UTC'));
        foreach ([1 => '2028-02-29', 12 => '2029-01-31'] as $months => $expected) {
            Http::swap(new Factory);
            $user = $this->admin();
            $payment = $this->payment($user);
            $payment->forceFill(['billing_months' => $months])->save();
            Plan::whereKey($payment->plan_id)->update(['billing_months' => 0]);
            Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment))]);
            app(SubscriptionBilling::class)->verify($payment);
            $this->assertSame($expected, $payment->fresh()->period_end->format('Y-m-d'));
            app(SubscriptionBilling::class)->verify($payment);
            $this->assertSame($expected, $user->customer->fresh()->subscription_expires_at->format('Y-m-d'));
        }
        $this->travelBack();
    }

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Pilot', 'amount_cents' => 1000, 'currency' => 'USD', 'vehicle_limit' => 10, 'is_active' => true]);
    }

    private function payment(User $user): Payment
    {
        $plan = $this->plan();

        return Payment::forceCreate(['id' => (string) Str::uuid(), 'customer_id' => $user->customer_id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'amount_cents' => 1000, 'currency' => 'USD', 'vehicle_limit' => 10, 'status' => 'pending', 'poll_url' => 'https://www.paynow.co.zw/interface/checkpayment/?guid=test']);
    }

    private function signed(array $fields): string
    {
        $fields['hash'] = app(PaynowGateway::class)->signature($fields);

        return http_build_query($fields);
    }

    private function paid(Payment $payment, array $changes = []): string
    {
        return $this->signed(array_replace(['reference' => $payment->id, 'paynowreference' => 'PN-'.$payment->id, 'amount' => '10.00', 'status' => 'Paid'], $changes));
    }

    private function gatewayCallback(string $body)
    {
        return $this->call('POST', '/paynow/result', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body);
    }

    public function test_company_onboarding_creates_scoped_admin_and_audit(): void
    {
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->post('/customers', ['name' => 'New company', 'vehicle_limit' => 5, 'admin_name' => 'Owner', 'admin_email' => 'owner@example.com',
            'admin_password' => 'Example-password-123!', 'admin_password_confirmation' => 'Example-password-123!'])->assertSessionHasNoErrors();
        $customer = Customer::where('name', 'New company')->firstOrFail();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'role' => 'admin', 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.created', 'customer_id' => $customer->id]);
        $this->get('/customers/'.$customer->id.'/edit')->assertOk()->assertSee('Subscription access');
    }

    public function test_tenant_cannot_manage_companies_or_plans(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/customers/'.$admin->customer_id.'/edit')->assertForbidden();
        $this->put('/customers/'.$admin->customer_id, ['name' => 'Forged'])->assertForbidden();
        $this->post('/customers/'.$admin->customer_id.'/status', ['is_active' => false])->assertForbidden();
        $this->get('/plans')->assertForbidden();
        $this->post('/plans', [])->assertForbidden();
    }

    public function test_suspension_revokes_sessions_and_blocks_existing_account(): void
    {
        $admin = $this->admin();
        DB::table('sessions')->insert(['id' => 'tenant-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->post('/customers/'.$admin->customer_id.'/status', ['is_active' => false])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'tenant-session']);
        $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($super)->post('/customers/'.$admin->customer_id.'/status', ['is_active' => true])->assertRedirect();
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk();
    }

    public function test_vehicle_limit_prevents_upstream_creation(): void
    {
        $admin = $this->admin();
        $admin->customer->forceFill(['vehicle_limit' => 1])->save();
        Vehicle::forceCreate(['customer_id' => $admin->customer_id, 'name' => 'Existing', 'registration' => 'A1', 'unique_id' => 'one']);
        $this->actingAs($admin)->post('/vehicles', ['customer_id' => $admin->customer_id, 'name' => 'Extra', 'registration' => 'A2', 'unique_id' => 'two'])->assertSessionHasErrors('customer_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_expired_tenant_can_renew_but_cannot_read_tracking(): void
    {
        $admin = $this->admin();
        $admin->customer->forceFill(['billing_required' => true, 'subscription_expires_at' => now()->subDay()])->save();
        $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect('/billing');
        $this->getJson('/tracking/positions')->assertStatus(402);
        $this->get('/billing')->assertOk();
        Http::assertNothingSent();
    }

    public function test_cross_tenant_receipts_and_audit_are_private(): void
    {
        $owner = $this->admin();
        $payment = $this->payment($owner);
        DB::table('audit_logs')->insert(['action' => 'private.company.event', 'customer_id' => $owner->customer_id, 'created_at' => now()]);
        $other = $this->admin();
        $this->actingAs($other)->get('/billing')->assertDontSee($payment->id);
        $this->get('/billing/payments/'.$payment->id)->assertForbidden();
        $this->post('/billing/payments/'.$payment->id.'/check')->assertForbidden();
        $this->get('/audit-log')->assertOk()->assertDontSee('private.company.event');
        Http::assertNothingSent();
    }

    public function test_forged_callback_never_activates_subscription(): void
    {
        $payment = $this->payment($this->admin());
        $this->gatewayCallback('reference='.$payment->id.'&status=Paid&hash=forged')->assertStatus(400);
        $this->assertNull($payment->fresh()->applied_at);
        Http::assertNothingSent();
    }

    public function test_paid_callback_is_polled_and_applied_only_once(): void
    {
        $admin = $this->admin();
        $payment = $this->payment($admin);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment))]);
        $this->gatewayCallback($this->paid($payment))->assertOk();
        $expires = $admin->customer->fresh()->subscription_expires_at->toIso8601String();
        $this->gatewayCallback($this->paid($payment))->assertOk();
        $this->assertSame($expires, $admin->customer->fresh()->subscription_expires_at->toIso8601String());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.renewed', 'subject_reference' => $payment->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'subscription.renewed')->count());
    }

    public function test_wrong_amount_or_reference_is_rejected(): void
    {
        $payment = $this->payment($this->admin());
        foreach ([['amount' => '1.00'], ['reference' => 'wrong']] as $change) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment, $change))]);
            $this->gatewayCallback($this->paid($payment))->assertStatus(503);
            $this->assertNull($payment->fresh()->applied_at);
        }
    }

    public function test_browser_return_cannot_mark_payment_paid(): void
    {
        $admin = $this->admin();
        $payment = $this->payment($admin);
        $this->actingAs($admin)->get('/billing/payments/'.$payment->id.'?status=Paid')->assertOk();
        $this->assertNull($payment->fresh()->applied_at);
        Http::assertNothingSent();
    }

    public function test_checkout_uses_server_price_and_reuses_pending_payment(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->signed(['status' => 'Ok', 'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment/?guid=test', 'browserurl' => 'https://www.paynow.co.zw/payment/123']))]);
        $this->actingAs($admin)->post('/billing/checkout', ['plan_id' => $plan->id, 'amount_cents' => 1, 'customer_id' => 999])->assertRedirect();
        $this->post('/billing/checkout', ['plan_id' => $plan->id])->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['customer_id' => $admin->customer_id, 'amount_cents' => 1000, 'status' => 'pending']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === '10.00');
    }

    public function test_disabled_checkout_and_unsafe_poll_urls_fail_closed(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();
        config(['paynow.enabled' => false]);
        $this->actingAs($admin)->post('/billing/checkout', ['plan_id' => $plan->id])->assertSessionHasErrors('plan_id');
        $payment = $this->payment($admin);
        $payment->update(['poll_url' => 'http://127.0.0.1/admin']);
        $this->post('/billing/payments/'.$payment->id.'/check')->assertSessionHasErrors('payment');
        Http::assertNothingSent();
    }

    public function test_paynow_documented_hash_vector(): void
    {
        config(['paynow.integration_key' => '3e9fed89-60e1-4ce5-ab6e-6b1eb2d4f977']);
        $fields = ['id' => '1201', 'reference' => 'TEST REF', 'amount' => '99.99', 'additionalinfo' => 'A test ticket transaction',
            'returnurl' => 'http://www.google.com/search?q=returnurl', 'resulturl' => 'http://www.google.com/search?q=resulturl', 'status' => 'Message'];
        $this->assertSame('2A033FC38798D913D42ECB786B9B19645ADEDBDE788862032F1BD82CF3B92DEF84F316385D5B40DBB35F1A4FD7D5BFE73835174136463CDD48C9366B0749C689', app(PaynowGateway::class)->signature($fields));
    }

    public function test_refund_holds_access_without_erasing_payment_history(): void
    {
        $admin = $this->admin();
        $payment = $this->payment($admin);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment))]);
        $this->gatewayCallback($this->paid($payment))->assertOk();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment, ['status' => 'Refunded']))]);
        $this->gatewayCallback($this->paid($payment, ['status' => 'Refunded']))->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->applied_at);
        $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect('/billing');
    }

    public function test_unknown_checkout_is_not_retried_and_signed_callback_can_recover_it(): void
    {
        $admin = $this->admin();
        $plan = $this->plan();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::failedConnection()]);
        $this->actingAs($admin)->post('/billing/checkout', ['plan_id' => $plan->id])->assertRedirect();
        $this->post('/billing/checkout', ['plan_id' => $plan->id])->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::firstOrFail();
        $this->assertSame('unknown', $payment->status);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment))]);
        $this->gatewayCallback($this->paid($payment, ['pollurl' => 'https://www.paynow.co.zw/interface/checkpayment/?guid=recovered']))->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_pending_gateway_result_does_not_grant_access_even_when_callback_claims_paid(): void
    {
        $payment = $this->payment($this->admin());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['www.paynow.co.zw/*' => Http::response($this->paid($payment, ['status' => 'Created']))]);
        $this->gatewayCallback($this->paid($payment))->assertOk();
        $this->assertNull($payment->fresh()->applied_at);
    }

    public function test_trial_and_billing_settings_are_saved_only_by_platform_admin(): void
    {
        $admin = $this->admin();
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->put('/customers/'.$admin->customer_id, ['name' => 'Trial company', 'vehicle_limit' => 5, 'billing_required' => 1,
            'trial_ends_at' => now()->addDays(14)->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->assertTrue($admin->customer->fresh()->billing_required);
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk();
    }

    public function test_plan_screens_render_and_password_is_not_flashed_on_validation_error(): void
    {
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->plan();
        $this->actingAs($super)->get('/plans')->assertOk();
        $this->post('/customers', ['name' => '', 'admin_password' => 'Private-password-123!', 'admin_password_confirmation' => 'Private-password-123!'])->assertSessionHasErrors('name');
        $this->assertNull(session('_old_input.admin_password'));
        $this->assertNull(session('_old_input.admin_password_confirmation'));
    }
}
