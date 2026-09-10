<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetInsights;
use App\Services\MaintenanceNotifications;
use App\Services\PaynowGateway;
use App\Services\ScheduledFleetReports;
use App\Services\TraccarService;
use App\Services\WhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FleetOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'Fleet'])->id]);
    }

    private function vehicle(User $u, string $name = 'Truck'): Vehicle
    {
        return Vehicle::forceCreate(['customer_id' => $u->customer_id, 'name' => $name, 'registration' => uniqid(), 'unique_id' => uniqid(), 'odometer' => 1000, 'traccar_device_id' => random_int(100, 99999)]);
    }

    public function test_operations_pages_and_tenant_isolation(): void
    {
        $this->withoutVite();
        $u = $this->owner();
        $v = $this->vehicle($u);
        $other = $this->vehicle($this->owner(), 'Secret truck');
        $this->actingAs($u);
        foreach (['fuel', 'maintenance', 'sharing', 'reports'] as $tab) {
            $this->get('/operations?tab='.$tab)->assertOk()->assertDontSee('Secret truck');
        }
        $this->post('/operations/fuel', ['vehicle_id' => $other->id, 'filled_on' => date('Y-m-d'), 'litres' => 20, 'odometer' => 1100, 'cost' => 30, 'currency' => 'USD'])->assertNotFound();
        $this->post('/operations/maintenance', ['vehicle_id' => $other->id, 'title' => 'Oil', 'due_on' => date('Y-m-d')])->assertNotFound();
        $this->post('/operations/shares', ['vehicle_id' => $other->id, 'label' => 'Delivery', 'hours' => 2])->assertNotFound();
        $this->get('/operations/report?vehicle_id='.$other->id.'&from='.date('Y-m-d').'&to='.date('Y-m-d'))->assertNotFound();
    }

    public function test_fuel_consumption_and_maintenance_completion(): void
    {
        $this->withoutVite();
        $u = $this->owner();
        $v = $this->vehicle($u);
        $this->actingAs($u);
        foreach ([[1000, 20], [1200, 20]] as [$km,$litres]) {
            $this->post('/operations/fuel', ['vehicle_id' => $v->id, 'filled_on' => date('Y-m-d'), 'litres' => $litres, 'odometer' => $km, 'cost' => 30, 'currency' => 'USD', 'full_tank' => 1])->assertRedirect();
        }
        $this->get('/operations')->assertSee('10 L/100 km');
        $this->assertEquals(1200, $v->fresh()->odometer);
        $this->post('/operations/maintenance', ['vehicle_id' => $v->id, 'title' => 'Oil service', 'due_odometer' => 1100])->assertRedirect();
        $this->get('/operations?tab=maintenance')->assertSee('Service due');
        $id = DB::table('maintenance_tasks')->value('id');
        $this->post('/operations/maintenance/'.$id.'/complete', ['cost' => 50, 'currency' => 'ZWG'])->assertRedirect();
        $this->assertDatabaseHas('maintenance_tasks', ['id' => $id, 'cost_cents' => 5000, 'currency' => 'ZWG']);
    }

    public function test_share_expires_revokes_and_hides_private_fields(): void
    {
        $this->withoutVite();
        $u = $this->owner();
        $v = $this->vehicle($u, 'Private name');
        $this->actingAs($u)->post('/operations/shares', ['vehicle_id' => $v->id, 'label' => 'Your parcel', 'hours' => 1])->assertRedirect();
        $url = session('share_url');
        $this->assertNotEmpty($url);
        $this->get('/operations?tab=sharing')->assertOk()->assertSee('Active')->assertSee('Revoke');
        $this->assertDatabaseMissing('tracking_shares', ['token_hash' => basename($url)]);
        $this->mock(TraccarService::class)->shouldReceive('getLatestPositions')->andReturn([['deviceId' => $v->traccar_device_id, 'valid' => true, 'latitude' => -17.8, 'longitude' => 31, 'fixTime' => now()->toIso8601String(), 'attributes' => ['secret' => 'sensitive']]]);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertSee('Your parcel')->assertDontSee('Private name')->assertDontSee('sensitive');
        $this->travel(2)->hours();
        $this->get($url)->assertNotFound();
        $this->travelBack();
        $this->actingAs($u)->post('/operations/shares/'.DB::table('tracking_shares')->value('id').'/revoke')->assertRedirect();
        $this->get($url)->assertNotFound();
    }

    public function test_share_stops_when_creator_loses_vehicle_access(): void
    {
        $this->withoutVite();
        $u = $this->owner();
        $v = $this->vehicle($u);
        $this->actingAs($u)->post('/operations/shares', ['vehicle_id' => $v->id, 'label' => 'Parcel', 'hours' => 1]);
        $url = session('share_url');
        $u->is_active = false;
        $u->save();
        $this->get($url)->assertNotFound();
    }

    public function test_report_filters_upstream_and_calculates_observed_metrics(): void
    {
        $this->withoutVite();
        $u = $this->owner();
        $v = $this->vehicle($u);
        $from = CarbonImmutable::now()->subHour()->startOfSecond();
        $to = $from->addMinutes(2);
        $id = $v->traccar_device_id;
        $points = [['deviceId' => $id, 'valid' => true, 'fixTime' => $from->toIso8601String(), 'speed' => 0, 'attributes' => ['ignition' => true, 'totalDistance' => 1000, 'fuel' => 50]], ['deviceId' => $id, 'valid' => true, 'fixTime' => $to->toIso8601String(), 'speed' => 0, 'attributes' => ['ignition' => true, 'totalDistance' => 2000, 'fuel' => 35]], ['deviceId' => 999999, 'valid' => true, 'fixTime' => $to->toIso8601String(), 'speed' => 0, 'attributes' => ['totalDistance' => 999999]]];
        $mock = $this->mock(TraccarService::class);
        $mock->shouldReceive('getRoute')->andReturn($points);
        $mock->shouldReceive('getEvents')->andReturn([['id' => 1, 'deviceId' => $id, 'type' => 'deviceOverspeed', 'eventTime' => $to->toIso8601String()], ['id' => 2, 'deviceId' => 999999, 'type' => 'deviceOverspeed', 'eventTime' => $to->toIso8601String()]]);
        config(['fleet.fuel_sensor_unit' => 'litres']);
        $row = app(FleetInsights::class)->report($v, $from, $to);
        $this->assertEquals(1, $row['distance_km']);
        $this->assertEquals(2, $row['idle_minutes']);
        $this->assertEquals(95, $row['score']);
        $this->assertEquals(15, $row['fuel_drop_litres']);
        $this->actingAs($u)->get('/operations/report?vehicle_id='.$v->id.'&from='.$from->toDateString().'&to='.$to->toDateString())->assertOk()->assertSee('Coaching suggestions');
    }

    public function test_snapshot_does_not_count_stale_locations_as_moving(): void
    {
        $u = $this->owner();
        $v = $this->vehicle($u);
        $mock = $this->mock(TraccarService::class);
        $mock->shouldReceive('getLatestPositionsForDevices')->andReturn([['deviceId' => $v->traccar_device_id, 'valid' => true, 'speed' => 40, 'fixTime' => now()->subDay()->toIso8601String()]]);
        $mock->shouldReceive('fleetReport')->andReturn([]);
        $mock->shouldReceive('getDevicesByIds')->andReturn([['id' => $v->traccar_device_id, 'status' => 'online']]);
        $this->actingAs($u)->getJson('/fleet-insights')->assertOk()->assertJsonPath('counts.moving', 0)->assertJsonPath('counts.unknown', 1);
    }

    public function test_whatsapp_requires_consent_and_uses_template(): void
    {
        $u = $this->owner();
        $this->actingAs($u)->post('/notifications', ['whatsapp_enabled' => 1, 'whatsapp_number' => '+263771234567'])->assertSessionHasErrors('whatsapp_consent');
        config(['fleet.whatsapp.enabled' => true, 'fleet.whatsapp.account_sid' => 'AC'.str_repeat('a', 32), 'fleet.whatsapp.auth_token' => 'secret', 'fleet.whatsapp.from' => 'whatsapp:+14155551234', 'fleet.whatsapp.content_sid' => 'HX'.str_repeat('b', 32)]);
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
        $this->assertSame('accepted', app(WhatsAppGateway::class)->send('+263771234567', 'Speeding', 'Truck', '2026-09-10'));
        Http::assertSent(fn ($r) => $r['To'] === 'whatsapp:+263771234567' && isset($r['ContentSid']) && ! isset($r['Body']));
    }

    public function test_currency_signatures_are_separate(): void
    {
        config(['paynow.integration_key' => 'usd', 'paynow.currency' => 'USD', 'paynow.accounts.ZWG.integration_key' => 'zig']);
        $g = app(PaynowGateway::class);
        $fields = ['reference' => 'one'];
        $this->assertNotSame($g->signature($fields, 'USD'), $g->signature($fields, 'ZWG'));
        $body = http_build_query($fields + ['hash' => $g->signature($fields, 'ZWG')]);
        $this->assertSame('one', $g->verified($body, 'ZWG')['reference']);
        $this->expectException(\RuntimeException::class);
        $g->verified($body, 'USD');
    }

    public function test_scheduled_reports_are_not_sent_twice(): void
    {
        Mail::fake();
        $u = $this->owner();
        $this->actingAs($u)->post('/operations/schedule', ['frequency' => 'daily'])->assertRedirect();
        DB::table('report_schedules')->update(['next_run_at' => now()->subMinute()]);
        app(ScheduledFleetReports::class)->run();
        app(ScheduledFleetReports::class)->run();
        $this->assertDatabaseHas('report_schedules', ['user_id' => $u->id, 'last_status' => 'previewed']);
    }

    public function test_non_managers_cannot_write_operations(): void
    {
        $u = $this->owner();
        $u->role = Role::Customer;
        $u->save();
        $this->actingAs($u)->get('/operations')->assertForbidden();
        $this->post('/operations/fuel', [])->assertForbidden();
    }

    public function test_maintenance_alerts_are_scoped_and_sent_once_per_day(): void
    {
        Mail::fake();
        $u = $this->owner();
        $v = $this->vehicle($u);
        $other = $this->vehicle($this->owner());
        DB::table('notification_preferences')->insert(['user_id' => $u->id, 'events' => '["maintenanceDue"]', 'email_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$v, $other] as $vehicle) {
            DB::table('maintenance_tasks')->insert(['vehicle_id' => $vehicle->id, 'title' => 'Oil service', 'due_on' => today(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(MaintenanceNotifications::class)->run();
        app(MaintenanceNotifications::class)->run();
        $this->assertDatabaseCount('maintenance_deliveries',1);
        $this->assertDatabaseHas('maintenance_deliveries',['user_id' => $u->id, 'status' => 'previewed']);
    }
    public function test_maintenance_documents_cannot_be_downloaded_by_another_tenant(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $owner = $this->owner();
        $vehicle = $this->vehicle($owner);
        $this->actingAs($owner)->post('/operations/maintenance', [
            'vehicle_id' => $vehicle->id, 'title' => 'Service', 'due_on' => today()->toDateString(),
            'document' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $id = DB::table('maintenance_tasks')->value('id');
        $this->get('/operations/maintenance/'.$id.'/document')->assertOk();
        $this->actingAs($this->owner())->get('/operations/maintenance/'.$id.'/document')->assertNotFound();
    }
}
