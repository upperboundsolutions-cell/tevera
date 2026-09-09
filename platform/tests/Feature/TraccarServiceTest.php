<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\TraccarException;
use App\Jobs\DeploymentHeartbeat;
use App\Models\Customer;
use App\Models\User;
use App\Services\TraccarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TraccarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['traccar.url' => 'https://traccar.example', 'traccar.username' => 'service@example.com', 'traccar.password' => 'service-secret']);
        Http::preventStrayRequests();
    }

    public function test_backend_authentication_and_device_filter(): void
    {
        Http::fake(['https://traccar.example/api/devices*' => Http::response([['id' => 42, 'name' => 'Vehicle']], 200)]);
        $this->assertSame(42, app(TraccarService::class)->getDevice(42)['id']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('service@example.com:service-secret')) && $r['id'] === 42);
    }

    public function test_private_docker_transport_requires_explicit_exact_host_opt_in(): void
    {
        Http::fake(['*' => Http::response([])]);
        config(['traccar.url' => 'http://traccar:8082', 'traccar.private_host' => null]);
        try {
            app(TraccarService::class)->checkConnection();
            $this->fail('Private HTTP must require opt-in');
        } catch (TraccarException $error) {
            $this->assertSame('configuration', $error->reason);
        }
        Http::assertNothingSent();
        config(['traccar.private_host' => 'traccar']);
        app(TraccarService::class)->checkConnection();
        Http::assertSentCount(1);
        config(['traccar.url' => 'http://untrusted.example:8082']);
        $this->expectException(TraccarException::class);
        app(TraccarService::class)->checkConnection();
    }

    public function test_health_screen_is_platform_only_and_reports_heartbeats(): void
    {
        Http::fake(['*' => Http::response([])]);
        $customer = Customer::create(['name' => 'Tenant']);
        $admin = User::factory()->create(['role' => Role::Admin, 'customer_id' => $customer->id]);
        $this->actingAs($admin)->get('/system-health')->assertForbidden();
        Http::assertNothingSent();
        Cache::put('deployment:scheduler', time());
        (new DeploymentHeartbeat)->handle();
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->get('/system-health')->assertOk()->assertSee('Healthy')->assertDontSee('service-secret');
        $this->artisan('platform:health')->assertSuccessful();
    }

    public function test_errors_never_include_raw_upstream_body(): void
    {
        $sequence = Http::sequence();
        foreach ([400, 401, 403, 404, 422, 500, 302] as $code) {
            $sequence->push('SECRET upstream stack trace', $code);
        }
        Http::fake(['*' => $sequence]);
        foreach ([400 => 'validation', 401 => 'authentication', 403 => 'authentication', 404 => 'not_found', 422 => 'validation', 500 => 'upstream', 302 => 'upstream'] as $code => $reason) {
            try {
                app(TraccarService::class)->getDevices();
                $this->fail('Expected a failure');
            } catch (TraccarException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('SECRET', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
        }
    }

    public function test_connection_failure_is_sanitized(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(TraccarException::class);
        $this->expectExceptionMessage('could not be reached');
        app(TraccarService::class)->getDevices();
    }

    public function test_non_local_plain_http_is_rejected_before_request(): void
    {
        config(['traccar.url' => 'http://traccar.example']);
        Http::fake();
        try {
            app(TraccarService::class)->checkConnection();
            $this->fail('Expected configuration rejection');
        } catch (TraccarException $e) {
            $this->assertSame('configuration', $e->reason);
            Http::assertNothingSent();
        }
    }

    public function test_malformed_json_is_rejected(): void
    {
        Http::fake(['*' => Http::response('<html>Proxy error</html>', 200)]);
        $this->expectException(TraccarException::class);
        app(TraccarService::class)->getDevices();
    }

    public function test_writes_are_not_retried_and_id_cannot_be_overridden(): void
    {
        Http::fake(['*' => Http::response(['id' => 42], 200)]);
        app(TraccarService::class)->updateDevice(42, ['id' => 99, 'name' => 'Truck']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['id'] === 42);
    }

    public function test_connection_check_is_super_admin_only_and_does_not_leak_devices(): void
    {
        Http::fake(['*' => Http::response([['id' => 42, 'name' => 'PRIVATE-VEHICLE']], 200)]);
        $customer = Customer::create(['name' => 'A']);
        $admin = User::factory()->create(['role' => Role::Admin, 'customer_id' => $customer->id]);
        $this->actingAs($admin)->post('/settings/traccar/check')->assertForbidden();
        Http::assertNothingSent();
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->from('/dashboard')->post('/settings/traccar/check')->assertRedirect('/dashboard')->assertSessionHas('status');
        $this->get('/dashboard')->assertOk()->assertDontSee('service-secret')->assertDontSee('PRIVATE-VEHICLE');
    }
}
