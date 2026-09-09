<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function account(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'Acme'])->id], $attributes));
    }

    public function test_guests_are_redirected_and_login_has_security_headers(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in to your workspace')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_login_remember_logout_and_audits(): void
    {
        $user = $this->account();
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password', 'remember' => '1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
        $this->get('/dashboard')->assertOk()->assertDontSee('Test connection');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_inactive_accounts_and_customers_cannot_login(): void
    {
        $user = $this->account(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->is_active = true;
        $user->save();
        $user->customer->is_active = false;
        $user->customer->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_revokes_existing_access(): void
    {
        $user = $this->account();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->is_active = false;
        $user->save();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_even_with_correct_password_after_failures(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_reset_is_single_use_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = $this->account();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'];
        $this->post('/reset-password', $payload)->assertRedirect('/login');
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset']);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_password_recovery_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])->assertSessionHas('status', 'If that account is available, a password reset link has been sent.');
        Notification::assertNothingSent();
    }

    public function test_invalid_reset_token_cannot_change_password(): void
    {
        $user = $this->account();
        $this->post('/reset-password', ['email' => $user->email, 'token' => 'invalid', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_account_status_command_protects_last_super_admin(): void
    {
        $user = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->artisan('platform:user-status', ['email' => $user->email, 'status' => 'inactive'])->assertExitCode(1);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_status_command_deactivates_and_audits_tenant_account(): void
    {
        $user = $this->account();
        $this->artisan('platform:user-status', ['email' => $user->email, 'status' => 'inactive'])->assertExitCode(0);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated_cli', 'subject_id' => $user->id]);
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_user_supplied_names_are_escaped_in_blade(): void
    {
        $user = $this->account(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_cli_customer_and_user_provisioning(): void
    {
        $this->artisan('platform:create-customer')->expectsQuestion('Customer name', 'Provisioned fleet')
            ->expectsQuestion('Contact email (optional)', 'fleet@example.com')->assertExitCode(0);
        $customer = Customer::where('name', 'Provisioned fleet')->firstOrFail();
        $this->artisan('platform:create-user', ['--role' => 'admin', '--customer' => $customer->id])
            ->expectsQuestion('Name', 'Fleet Admin')->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password (12+ characters, upper/lowercase, number and symbol)', 'Strong-password-123!')->assertExitCode(0);
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame($customer->id, $user->customer_id);
        $this->assertTrue(Hash::check('Strong-password-123!', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created_cli', 'subject_id' => $user->id]);
    }
}
