<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetTrackingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tenant_roles_are_denied_other_customers_even_with_bad_pivot_assignment(): void
    {
        $a = Customer::create(['name' => 'A']);
        $b = Customer::create(['name' => 'B']);
        $vehicle = new Vehicle(['name' => 'B vehicle', 'registration' => 'B-01']);
        $vehicle->customer_id = $b->id;
        $vehicle->save();
        foreach ([Role::Admin, Role::FleetManager, Role::Operator, Role::Customer] as $role) {
            $user = User::factory()->create(['role' => $role, 'customer_id' => $a->id]);
            $user->vehicles()->attach($vehicle);
            $this->assertFalse(Gate::forUser($user)->allows('view', $vehicle));
            $this->assertSame(0, Vehicle::visibleTo($user)->count());
        }
    }

    public function test_assignment_required_for_operators_and_customers_but_managers_see_own_fleet(): void
    {
        $customer = Customer::create(['name' => 'A']);
        $vehicle = new Vehicle(['name' => 'A vehicle', 'registration' => 'A-01']);
        $vehicle->customer_id = $customer->id;
        $vehicle->save();
        foreach ([Role::Operator, Role::Customer] as $role) {
            $user = User::factory()->create(['role' => $role, 'customer_id' => $customer->id]);
            $this->assertFalse(Gate::forUser($user)->allows('view', $vehicle));
            $user->vehicles()->attach($vehicle);
            $this->assertTrue(Gate::forUser($user)->allows('view', $vehicle));
            $this->assertFalse(Gate::forUser($user)->allows('update', $vehicle));
        }
        $manager = User::factory()->create(['role' => Role::FleetManager, 'customer_id' => $customer->id]);
        $this->assertTrue(Gate::forUser($manager)->allows('update', $vehicle));
    }

    public function test_no_upstream_request_is_made_for_unauthorized_vehicle(): void
    {
        Http::fake();
        $customer = Customer::create(['name' => 'A']);
        $vehicle = new Vehicle(['name' => 'Vehicle', 'registration' => 'A-01']);
        $vehicle->customer_id = $customer->id;
        $vehicle->traccar_device_id = 42;
        $vehicle->save();
        $user = User::factory()->create(['role' => Role::Customer, 'customer_id' => $customer->id]);
        try {
            app(FleetTrackingService::class)->latestPositions($user, $vehicle);
            $this->fail('Access should be denied');
        } catch (AuthorizationException) {
            Http::assertNothingSent();
        }
    }

    public function test_user_cannot_mass_assign_privileges(): void
    {
        $user = new User(['name' => 'User', 'email' => 'user@example.com', 'password' => 'password', 'role' => Role::SuperAdmin, 'customer_id' => 99, 'is_active' => true]);
        $this->assertSame(Role::Customer, $user->role);
        $this->assertNull($user->customer_id);
    }

    public function test_authorized_positions_are_filtered_against_upstream_overexposure(): void
    {
        config(['traccar.url' => 'https://traccar.example', 'traccar.username' => 'service', 'traccar.password' => 'secret']);
        Http::fake(['*' => Http::response([['id' => 1, 'deviceId' => 42], ['id' => 2, 'deviceId' => 99]])]);
        $customer = Customer::create(['name' => 'A']);
        $vehicle = new Vehicle(['name' => 'Vehicle', 'registration' => 'A-01']);
        $vehicle->customer_id = $customer->id;
        $vehicle->traccar_device_id = 42;
        $vehicle->save();
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => $customer->id]);
        $this->assertSame([['id' => 1, 'deviceId' => 42]], app(FleetTrackingService::class)->latestPositions($user, $vehicle));
        Http::assertSent(fn ($request) => $request['deviceId'] === 42);
    }

    public function test_inactive_customer_and_unattached_admin_have_no_visibility(): void
    {
        $customer = Customer::create(['name' => 'A']);
        $customer->is_active = false;
        $customer->save();
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => $customer->id]);
        $this->assertFalse($user->canAccessPlatform());
        $unattached = User::factory()->create(['role' => Role::Admin, 'customer_id' => null]);
        $this->assertFalse($unattached->canAccessPlatform());
        $this->assertSame(0, Vehicle::visibleTo($unattached)->count());
    }
}
