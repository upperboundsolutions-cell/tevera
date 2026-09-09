<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetEventNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_are_owned_by_signed_in_user(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'Own'])->id]);
        $this->actingAs($user)->post('/notifications', ['user_id' => 999, 'events' => ['geofenceEnter'], 'email_enabled' => 1])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'email_enabled' => 1]);
        $this->get('/notifications')->assertOk()->assertSee('Event notifications');
        $this->post('/notifications', ['events' => ['madeUp']])->assertSessionHasErrors('events.0');
    }

    public function test_notifications_filter_tenants_and_deduplicate_events(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        config(['mail.default' => 'log', 'traccar.url' => 'https://tracker.example', 'traccar.username' => 'service', 'traccar.password' => 'secret']);
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'Own'])->id]);
        $vehicle = Vehicle::forceCreate(['customer_id' => $user->customer_id, 'name' => 'Own vehicle', 'registration' => 'ABC', 'unique_id' => 'abc', 'traccar_device_id' => 42]);
        Vehicle::forceCreate(['customer_id' => Customer::create(['name' => 'Other'])->id, 'name' => 'Other vehicle', 'registration' => 'DEF', 'unique_id' => 'def', 'traccar_device_id' => 99]);
        DB::table('notification_preferences')->insert(['user_id' => $user->id, 'events' => '["geofenceEnter"]', 'email_enabled' => true, 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)]);
        Http::fake(['tracker.example/*' => Http::response([
            ['id' => 1, 'deviceId' => 42, 'type' => 'geofenceEnter', 'eventTime' => now()->subMinute()->toIso8601String()],
            ['id' => 2, 'deviceId' => 99, 'type' => 'geofenceEnter', 'eventTime' => now()->subMinute()->toIso8601String()],
            ['id' => 3, 'deviceId' => 42, 'type' => 'geofenceEnter', 'eventTime' => now()->subHour()->toIso8601String()],
        ])]);
        app(FleetEventNotifications::class)->run();
        app(FleetEventNotifications::class)->run();
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notification_deliveries', ['event_id' => 1, 'vehicle_id' => $vehicle->id, 'status' => 'previewed']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'deviceId=99'));
        $user->is_active = false;
        $user->save();
        Http::fake();
        app(FleetEventNotifications::class)->run();
        Http::assertNothingSent();
    }
}
