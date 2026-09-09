<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FleetAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->withoutVite();
        Http::preventStrayRequests();

        return User::factory()->create(['role' => Role::Admin, 'customer_id' => Customer::create(['name' => 'Own company'])->id]);
    }

    public function test_disabled_assistant_does_not_send_a_request(): void
    {
        $user = $this->manager();
        config(['ai.enabled' => false]);
        $this->actingAs($user)->get('/assistant')->assertOk()->assertSee('Setup required');
        $this->post('/assistant', ['question' => 'Hello'])->assertSessionHasErrors('question');
        Http::assertNothingSent();
    }

    public function test_assistant_uses_only_opted_in_scoped_counts_and_escapes_output(): void
    {
        $user = $this->manager();
        config(['ai.enabled' => true, 'ai.key' => 'secret-test-key', 'ai.model' => 'test-model']);
        Vehicle::forceCreate(['customer_id' => $user->customer_id, 'name' => 'Private vehicle name', 'registration' => 'ABC', 'unique_id' => 'secret-imei']);
        $other = Customer::create(['name' => 'Other']);
        Vehicle::forceCreate(['customer_id' => $other->id, 'name' => 'Other vehicle', 'registration' => 'DEF', 'unique_id' => 'other-imei']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '<script>bad()</script>']]]]])]);
        $this->actingAs($user)->post('/assistant', ['question' => 'Explain counts', 'include_summary' => 1, 'customer_id' => $other->id])->assertRedirect('/assistant');
        Http::assertSent(function ($request) {
            $input = json_decode($request['input'], true);

            return $input['authorized_fleet_counts']['vehicles'] === 1 && $request['store'] === false
                && ! str_contains($request['input'], 'imei') && ! str_contains($request['input'], 'Private vehicle name');
        });
        $this->get('/assistant')->assertSee('&lt;script&gt;bad()', false)->assertDontSee('<script>bad()', false)->assertDontSee('secret-test-key');
    }

    public function test_summary_is_not_sent_without_opt_in_and_viewers_cannot_use_ai(): void
    {
        $user = $this->manager();
        config(['ai.enabled' => true, 'ai.key' => 'secret', 'ai.model' => 'test-model']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Setup help']]]]])]);
        $this->actingAs($user)->post('/assistant', ['question' => 'Setup help'])->assertRedirect('/assistant');
        Http::assertSent(fn ($request) => json_decode($request['input'], true)['authorized_fleet_counts'] === []);
        $viewer = User::factory()->create(['role' => Role::Customer, 'customer_id' => $user->customer_id]);
        $this->actingAs($viewer)->post('/assistant', ['question' => 'Hi'])->assertForbidden();
        Http::assertSentCount(1);
    }
}
