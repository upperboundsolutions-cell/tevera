<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_is_present_on_dashboard_and_tracking_page(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('id="fleet-map"', false);
        $this->get('/tracking')->assertOk()->assertSee('id="fleet-map"', false);
    }

    public function test_empty_fleet_never_requests_all_upstream_positions(): void
    {
        Http::fake();
        $user = User::factory()->create(['role' => Role::SuperAdmin]);
        $this->actingAs($user)->getJson('/tracking/positions')->assertOk()->assertJsonPath('vehicles', []);
        Http::assertNothingSent();
    }

    public function test_map_excludes_other_tenants_and_filters_upstream_fields(): void
    {
        config(['traccar.url' => 'https://traccar.example', 'traccar.username' => 'service', 'traccar.password' => 'secret']);
        $a = Customer::create(['name' => 'A']);
        $b = Customer::create(['name' => 'B']);
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => $a->id]);
        foreach ([[$a, 41], [$a, 42], [$b, 99]] as [$customer, $device]) {
            $vehicle = new Vehicle(['name' => 'Vehicle '.$device, 'registration' => 'REG'.$device]);
            $vehicle->customer_id = $customer->id;
            $vehicle->traccar_device_id = $device;
            $vehicle->save();
        }
        Http::fake([
            'https://traccar.example/api/positions*' => Http::response([
                ['deviceId' => 41, 'latitude' => -17.8, 'longitude' => 31.0, 'attributes' => ['ignition' => true, 'secret' => 'DO-NOT-EXPOSE']],
                ['deviceId' => 99, 'latitude' => 1, 'longitude' => 1],
            ]),
            'https://traccar.example/api/devices*' => Http::response([['id' => 41, 'status' => 'online']]),
        ]);
        $this->actingAs($user)->getJson('/tracking/positions')->assertOk()->assertJsonCount(2, 'vehicles')
            ->assertJsonPath('vehicles.0.position.latitude', -17.8)->assertDontSee('DO-NOT-EXPOSE')->assertDontSee('REG99');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'positions?deviceId=41&deviceId=42'));
        Http::assertSentCount(2);
    }

    public function test_positions_require_authentication(): void
    {
        $this->getJson('/tracking/positions')->assertUnauthorized();
    }
}
