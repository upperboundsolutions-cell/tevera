<?php

namespace Tests\Feature;

use App\Services\FleetAssistant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_native_providers_send_evidence_and_extract_only_answer_text(): void
    {
        foreach (['anthropic', 'gemini'] as $provider) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            config(['ai.enabled' => true, 'ai.provider' => $provider, 'ai.model' => 'test-model', 'ai.key' => 'native-key']);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($provider === 'anthropic'
                ? ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => 'private'], ['type' => 'text', 'text' => 'Trip [T1]']]]
                : ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['thought' => true, 'text' => 'private'], ['text' => 'Trip [T1]']]]]]])]);
            $this->assertSame('Trip [T1]', app(FleetAssistant::class)->answer('Explain', [], ['ref' => 'T1']));
            Http::assertSent(function ($r) use ($provider) {
                $input = $provider === 'anthropic' ? $r['messages'][0]['content'] : $r['contents'][0]['parts'][0]['text'];

                return $r->hasHeader($provider === 'anthropic' ? 'x-api-key' : 'x-goog-api-key', 'native-key')
                    && ! $r->hasHeader('Authorization') && ! str_contains($r->url(), 'native-key')
                    && json_decode($input, true)['movement_evidence']['ref'] === 'T1';
            });
        }
    }

    public function test_native_providers_reject_blocked_or_truncated_outputs(): void
    {
        foreach (['anthropic', 'gemini'] as $provider) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            config(['ai.enabled' => true, 'ai.provider' => $provider, 'ai.model' => 'test-model', 'ai.key' => 'test']);
            Http::fake(['*' => Http::response($provider === 'anthropic' ? ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => 'partial']]] : ['promptFeedback' => ['blockReason' => 'SAFETY']])]);
            try {
                app(FleetAssistant::class)->answer('Help', []);
                $this->fail('Incomplete response accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('The assistant could not respond. Please try again later.', $e->getMessage());
            }
        }
    }

    public function test_local_ollama_receives_evidence_without_a_key(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'ollama', 'ai.model' => 'local-model', 'ai.key' => null, 'ai.base_url' => 'http://127.0.0.1:11434']);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => 'Evidence [T1]']])]);
        $answer = app(FleetAssistant::class)->answer('Explain', [], ['trips' => [['ref' => 'T1']]]);
        $this->assertSame('Evidence [T1]', $answer);
        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization') && $r['stream'] === false && $r['messages'][0]['role'] === 'system' && json_decode($r['messages'][1]['content'], true)['movement_evidence']['trips'][0]['ref'] === 'T1');
    }

    public function test_compatible_provider_uses_its_endpoint_and_key(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'compatible', 'ai.model' => 'hosted-model', 'ai.key' => 'provider-key', 'ai.base_url' => 'https://ai.example/v1']);
        Http::preventStrayRequests();
        Http::fake(['ai.example/v1/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Fleet help']]]])]);
        $this->assertSame('Fleet help', app(FleetAssistant::class)->answer('Help', []));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer provider-key') && $r['model'] === 'hosted-model' && $r['max_tokens'] === 1800);
    }

    public function test_remote_plain_http_is_not_ready(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'compatible', 'ai.model' => 'test', 'ai.base_url' => 'http://remote.example/v1']);
        Http::preventStrayRequests();
        $this->assertFalse(app(FleetAssistant::class)->ready());
        Http::assertNothingSent();
    }

    public function test_truncated_response_is_not_presented_as_a_complete_analysis(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'compatible', 'ai.model' => 'test', 'ai.key' => null, 'ai.base_url' => 'https://ai.example/v1']);
        Http::fake(['ai.example/*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'Partial analysis']]]])]);
        $this->expectExceptionMessage('The assistant could not respond. Please try again later.');
        app(FleetAssistant::class)->answer('Help', []);
    }
}
