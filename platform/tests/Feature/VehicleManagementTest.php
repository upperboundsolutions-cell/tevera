<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VehicleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->customer = Customer::create(['name' => 'Fleet A']);
        $this->admin = User::factory()->create(['customer_id' => $this->customer->id, 'role' => Role::Admin]);
        config(['traccar.url' => 'https://traccar.example', 'traccar.username' => 'service', 'traccar.password' => 'secret']);
        Http::preventStrayRequests();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['customer_id' => $this->customer->id, 'name' => 'Delivery Truck', 'registration' => 'ABC123', 'unique_id' => '867000123456789', 'tracker_model' => 'Any supported tracker', 'tracker_protocol' => 'gt06'], $changes);
    }

    private function fakeCreation(): void
    {
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 71], 200));
    }

    public function test_registers_vehicle_device_driver_and_user_assignments(): void
    {
        $this->fakeCreation();
        $driver = new Driver(['name' => 'Driver']);
        $driver->customer_id = $this->customer->id;
        $driver->save();
        $viewer = User::factory()->create(['customer_id' => $this->customer->id]);
        $this->actingAs($this->admin)->post('/vehicles', $this->payload(['driver_id' => $driver->id, 'user_ids' => [$viewer->id]]))->assertRedirect();
        $vehicle = Vehicle::firstOrFail();
        $this->assertSame('synced', $vehicle->sync_status);
        $this->assertSame(71, $vehicle->traccar_device_id);
        $this->assertDatabaseHas('vehicle_user', ['vehicle_id' => $vehicle->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseHas('vehicle_driver_history', ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'ended_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle.created', 'subject_type' => Vehicle::class]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['uniqueId'] === '867000123456789' && $r['attributes']['fleetatlasProvisioningKey'] === $vehicle->sync_key);
    }

    public function test_rejects_other_customer_driver_and_user_assignments_before_api(): void
    {
        Http::fake();
        $other = Customer::create(['name' => 'B']);
        $driver = new Driver(['name' => 'Other driver']);
        $driver->customer_id = $other->id;
        $driver->save();
        $viewer = User::factory()->create(['customer_id' => $other->id]);
        $this->actingAs($this->admin)->post('/vehicles', $this->payload(['driver_id' => $driver->id, 'user_ids' => [$viewer->id]]))->assertSessionHasErrors(['driver_id', 'user_ids.0']);
        $this->post('/vehicles', $this->payload(['customer_id' => $other->id]))->assertSessionHasErrors('customer_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_operator_cannot_register_or_edit_vehicles(): void
    {
        Http::fake();
        $operator = User::factory()->create(['customer_id' => $this->customer->id, 'role' => Role::Operator]);
        $this->actingAs($operator)->get('/vehicles/create')->assertForbidden();
        $this->post('/vehicles', $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_timeout_after_remote_creation_can_be_retried_without_duplicate_device(): void
    {
        $remote = null;
        $creates = 0;
        Http::fake(function ($request) use (&$remote, &$creates) {
            if ($request->method() === 'GET') {
                return Http::response($remote ? [$remote] : []);
            }
            if ($request->method() === 'POST') {
                $creates++;
                $remote = ['id' => 71, 'uniqueId' => $request['uniqueId'], 'attributes' => $request['attributes']];
                throw new ConnectionException('Timed out after upstream write');
            }

            return Http::response(['id' => 71]);
        });
        $this->actingAs($this->admin)->post('/vehicles', $this->payload())->assertSessionHasErrors('sync');
        $vehicle = Vehicle::firstOrFail();
        $this->assertSame('failed', $vehicle->sync_status);
        $this->post('/vehicles/'.$vehicle->id.'/sync')->assertSessionHas('status');
        $this->assertSame('synced', $vehicle->fresh()->sync_status);
        $this->assertSame(1, $creates);
    }

    public function test_existing_unrelated_traccar_device_is_not_claimed(): void
    {
        Http::fake(['*' => Http::response([['id' => 99, 'uniqueId' => '867000123456789', 'attributes' => []]])]);
        $this->actingAs($this->admin)->post('/vehicles', $this->payload())->assertSessionHasErrors('sync');
        $this->assertNull(Vehicle::firstOrFail()->traccar_device_id);
        Http::assertSentCount(1);
    }

    public function test_updates_preserve_remote_attributes_and_deactivation_disables_device(): void
    {
        $this->fakeCreation();
        $this->actingAs($this->admin)->post('/vehicles', $this->payload());
        $vehicle = Vehicle::firstOrFail();
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response($r->method() === 'GET' ? [['id' => 71, 'attributes' => ['speedLimit' => 80]]] : ['id' => 71]));
        $this->put('/vehicles/'.$vehicle->id, $this->payload(['name' => 'Renamed Truck']))->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'Renamed Truck' && $r['attributes']['speedLimit'] === 80);
        $this->post('/vehicles/'.$vehicle->id.'/status', ['is_active' => '0'])->assertSessionHas('status');
        $this->assertFalse($vehicle->fresh()->is_active);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['disabled'] === true);
    }

    public function test_foreign_vehicle_cannot_be_viewed_edited_or_synchronized(): void
    {
        $this->fakeCreation();
        $this->actingAs($this->admin)->post('/vehicles', $this->payload());
        $vehicle = Vehicle::firstOrFail();
        $other = Customer::create(['name' => 'Other']);
        $admin = User::factory()->create(['role' => Role::Admin, 'customer_id' => $other->id]);
        $this->actingAs($admin)->get('/vehicles')->assertOk()->assertDontSee('Delivery Truck');
        $this->get('/vehicles/'.$vehicle->id.'/edit')->assertForbidden();
        $this->post('/vehicles/'.$vehicle->id.'/sync')->assertForbidden();
        $this->put('/vehicles/'.$vehicle->id, $this->payload())->assertForbidden();
        $this->post('/vehicles/'.$vehicle->id.'/status', ['is_active' => '0'])->assertForbidden();
    }

    public function test_duplicate_local_identifier_is_rejected_before_remote_creation(): void
    {
        $this->fakeCreation();
        $this->actingAs($this->admin)->post('/vehicles', $this->payload());
        $this->post('/vehicles', $this->payload(['registration' => 'NEW123']))->assertSessionHasErrors('unique_id');
        Http::assertSentCount(2);
    }

    public function test_custom_non_imei_tracker_identifiers_are_supported(): void
    {
        $this->fakeCreation();
        $this->actingAs($this->admin)->post('/vehicles', $this->payload(['unique_id' => 'phone+unit@fleet']))->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['uniqueId'] === 'phone+unit@fleet');
    }

    public function test_management_screens_render_and_protocol_catalog_is_available(): void
    {
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super);
        foreach (['/vehicles', '/devices', '/vehicles/create?customer='.$this->customer->id, '/customers', '/drivers', '/users', '/tracker-protocols'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/tracker-protocols?search=teltonika')->assertSee('teltonika')->assertSee('5027');
    }

    public function test_customer_admin_cannot_create_super_admin_or_other_tenant_user(): void
    {
        $other = Customer::create(['name' => 'Other']);
        $data = ['name' => 'Bad assignment', 'email' => 'new@example.com', 'password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!', 'role' => 'super_admin', 'customer_id' => $other->id];
        $this->actingAs($this->admin)->post('/users', $data)->assertSessionHasErrors(['role', 'customer_id']);
        $this->get('/customers')->assertForbidden();
    }

    public function test_customer_creation_and_user_creation_work_from_ui(): void
    {
        $super = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($super)->post('/customers', ['name' => 'New fleet'])->assertRedirect();
        $customer = Customer::where('name', 'New fleet')->firstOrFail();
        $this->post('/users', ['name' => 'Customer viewer', 'email' => 'viewer@example.com', 'password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!', 'role' => 'customer', 'customer_id' => $customer->id])->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'viewer@example.com', 'customer_id' => $customer->id, 'role' => 'customer']);
    }
}
