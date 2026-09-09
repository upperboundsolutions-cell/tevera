<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_shows_device_facing_settings_and_port_overrides_without_api_secrets(): void
    {
        $this->withoutVite();
        $company = Customer::create(['name' => 'Fleet']);
        $user = User::factory()->create(['role' => Role::Admin, 'customer_id' => $company->id]);
        config(['traccar.device_host' => 'gps.example.com', 'traccar.client_url' => 'https://phone.example.com',
            'traccar.port_overrides.gt06' => 25023, 'traccar.password' => 'PRIVATE-API-SECRET', 'traccar.url' => 'http://internal-engine:8082']);
        $this->actingAs($user)->get('/tracker-setup?protocol=gt06')->assertOk()->assertSee('gps.example.com')
            ->assertSee('25023')->assertSee('https://phone.example.com')->assertDontSee('PRIVATE-API-SECRET')->assertDontSee('internal-engine');
        $this->get('/tracker-setup?protocol=invalid')->assertSessionHasErrors('protocol');
    }

    public function test_setup_access_and_unconfigured_address(): void
    {
        $this->withoutVite();
        $this->get('/tracker-setup')->assertRedirect('/login');
        $company = Customer::create(['name' => 'Fleet']);
        $viewer = User::factory()->create(['role' => Role::Customer, 'customer_id' => $company->id]);
        $this->actingAs($viewer)->get('/tracker-setup')->assertForbidden();
        $manager = User::factory()->create(['role' => Role::FleetManager, 'customer_id' => $company->id]);
        config(['traccar.device_host' => null]);
        $this->actingAs($manager)->get('/tracker-setup')->assertOk()->assertSee('Not configured yet');
    }
}
