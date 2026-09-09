<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MovementAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function setupFleet(): array
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['traccar.url' => 'https://tracker.example', 'traccar.username' => 'service', 'traccar.password' => 'secret', 'ai.enabled' => false]);
        $company = Customer::create(['name' => 'Fleet']);
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => $company->id]);
        $vehicle = Vehicle::forceCreate(['customer_id' => $company->id, 'name' => 'Truck', 'registration' => 'ABC', 'traccar_device_id' => 42]);
        $this->actingAs($user);

        return [$user, $vehicle];
    }

    private function payload(Vehicle $vehicle): array
    {
        return ['vehicle_id' => $vehicle->id, 'from' => '2026-01-01T00:00', 'to' => '2026-01-02T00:00', 'action' => 'preview'];
    }

    public function test_preview_filters_other_devices_and_uses_history_and_correct_units(): void
    {
        [$user, $vehicle] = $this->setupFleet();
        $driver = Driver::forceCreate(['customer_id' => $user->customer_id, 'name' => 'Historical Driver', 'phone' => 'PRIVATE-PHONE']);
        DB::table('vehicle_driver_history')->insert(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'assigned_at' => '2026-01-01 01:00:00', 'ended_at' => '2026-01-01 05:00:00']);
        $trip = ['deviceId' => 42, 'startTime' => '2026-01-01T02:00:00Z', 'endTime' => '2026-01-01T03:00:00Z', 'distance' => 12000, 'maxSpeed' => 10];
        Http::fake(['*/reports/trips*' => Http::response([$trip, array_replace($trip, ['deviceId' => 99, 'startAddress' => 'OTHER-TENANT'])]), '*' => Http::response([])]);
        $this->post('/movement-analysis', $this->payload($vehicle))->assertOk()->assertSee('Historical Driver')->assertSee('12 km')->assertSee('18.5')->assertDontSee('OTHER-TENANT')->assertDontSee('PRIVATE-PHONE');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r['deviceId'] === 42);
    }

    public function test_cross_tenant_and_invalid_window_make_no_upstream_requests(): void
    {
        [$user, $vehicle] = $this->setupFleet();
        $other = Customer::create(['name' => 'Other']);
        $foreign = Vehicle::forceCreate(['customer_id' => $other->id, 'name' => 'Hidden', 'registration' => 'DEF', 'traccar_device_id' => 99]);
        $this->post('/movement-analysis', $this->payload($foreign))->assertNotFound();
        $this->post('/movement-analysis', array_replace($this->payload($vehicle), ['to' => '2026-01-03T00:00']))->assertSessionHasErrors('to');
        Http::assertNothingSent();
    }

    public function test_ai_requires_consent_and_receives_only_selected_evidence(): void
    {
        [$user, $vehicle] = $this->setupFleet();
        config(['ai.enabled' => true, 'ai.key' => 'test-secret', 'ai.model' => 'test-model']);
        $data = array_replace($this->payload($vehicle), ['action' => 'ai', 'question' => 'Summarize movements']);
        $this->post('/movement-analysis', $data)->assertSessionHasErrors('share_movement');
        Http::assertNothingSent();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'No reports returned; movement cannot be determined.']]]]]), '*' => Http::response([])]);
        $this->post('/movement-analysis', $data + ['share_movement' => 1])->assertOk()->assertSee('movement cannot be determined');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.openai.com') && json_decode($r['input'], true)['movement_evidence']['vehicle']['registration'] === 'ABC');
    }
}
