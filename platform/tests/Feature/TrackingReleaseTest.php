<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\FleetGeofence;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrackingReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function fleet(): array
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['traccar.url' => 'https://tracker.example', 'traccar.username' => 'service', 'traccar.password' => 'secret']);
        $company = Customer::create(['name' => 'A']);
        $admin = User::factory()->create(['role' => Role::Admin, 'customer_id' => $company->id]);
        $vehicle = Vehicle::forceCreate(['customer_id' => $company->id, 'name' => 'Own', 'registration' => 'A', 'traccar_device_id' => 42]);
        $this->actingAs($admin);

        return [$admin, $vehicle];
    }

    public function test_history_filters_device_time_and_private_attributes(): void
    {
        [$user, $vehicle] = $this->fleet();
        $point = ['deviceId' => 42, 'valid' => true, 'latitude' => -17.8, 'longitude' => 31.0, 'fixTime' => '2026-01-01T12:00:00Z', 'speed' => 10, 'attributes' => ['secret' => 'PRIVATE']];
        Http::fake(['*/reports/route*' => Http::response([$point, array_replace($point, ['deviceId' => 99]), array_replace($point, ['fixTime' => '2025-01-01T00:00:00Z'])]), '*/reports/events*' => Http::response([['deviceId' => 42, 'eventTime' => '2026-01-01T12:00:00Z', 'type' => 'geofenceEnter'], ['deviceId' => 99, 'eventTime' => '2026-01-01T12:00:00Z', 'type' => 'SECRET']])]);
        $this->getJson('/journeys/data?'.http_build_query(['vehicle_id' => $vehicle->id, 'from' => '2026-01-01T00:00', 'to' => '2026-01-02T00:00']))->assertOk()->assertJsonCount(1, 'points')->assertJsonPath('points.0.speed_kmh', 18.5)->assertJsonCount(1, 'events')->assertDontSee('PRIVATE')->assertDontSee('SECRET');
        $this->get('/journeys')->assertOk();
    }

    public function test_other_tenant_cannot_request_history_or_change_geofence(): void
    {
        [$user, $vehicle] = $this->fleet();
        $fence = FleetGeofence::forceCreate(['vehicle_id' => $vehicle->id, 'sync_key' => (string) Str::uuid(), 'name' => 'Secret zone', 'latitude' => 1, 'longitude' => 2, 'radius' => 250]);
        $other = User::factory()->create(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'B'])->id]);
        $this->actingAs($other)->get('/geofences')->assertOk()->assertDontSee('Secret zone');
        $this->post('/geofences/'.$fence->id.'/sync')->assertForbidden();
        $this->delete('/geofences/'.$fence->id)->assertForbidden();
        $this->getJson('/journeys/data?'.http_build_query(['vehicle_id' => $vehicle->id, 'from' => '2026-01-01T00:00', 'to' => '2026-01-02T00:00']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_geofence_is_created_and_linked_to_authorized_device(): void
    {
        [$user, $vehicle] = $this->fleet();
        Http::fake(function ($r) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/geofences')) {
                return Http::response(['id' => 77]);
            } if (str_ends_with($r->url(), '/permissions')) {
                return Http::response(null, 204);
            }

return Http::response([]);
        });
        $this->post('/geofences', ['vehicle_id' => $vehicle->id, 'name' => 'Depot', 'latitude' => -17.8, 'longitude' => 31.0, 'radius' => 250])->assertRedirect('/geofences');
        $this->assertDatabaseHas('fleet_geofences', ['vehicle_id' => $vehicle->id, 'remote_id' => 77, 'sync_status' => 'synced']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/permissions') && $r['deviceId'] === 42 && $r['geofenceId'] === 77);
    }

    public function test_failed_sync_is_retained_and_recovered_without_creating_duplicate(): void
    {
        [$user, $vehicle] = $this->fleet();
        $fence = FleetGeofence::forceCreate(['vehicle_id' => $vehicle->id, 'sync_key' => (string) Str::uuid(), 'name' => 'Depot', 'latitude' => 1, 'longitude' => 2, 'radius' => 250, 'sync_status' => 'failed']);
        Http::fake(['*' => Http::response([['id' => 77, 'attributes' => ['teveraFenceKey' => $fence->sync_key]]])]);
        $this->post('/geofences/'.$fence->id.'/sync')->assertRedirect('/geofences');
        $this->assertSame('synced', $fence->fresh()->sync_status);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
}
