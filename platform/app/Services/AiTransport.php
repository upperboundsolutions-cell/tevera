<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiTransport
{
    public function endpoint(): ?string
    {
        $provider = config('ai.provider');
        if ($provider === 'openai') {
            return 'https://api.openai.com/v1/responses';
        }
        if ($provider === 'anthropic') {
            return 'https://api.anthropic.com/v1/messages';
        }
        if ($provider === 'gemini') {
            $model = (string) config('ai.model');

            return preg_match('/^[a-zA-Z0-9._-]+$/', $model)
                ? 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent' : null;
        }
        if (! in_array($provider, ['compatible', 'ollama'], true)) {
            return null;
        }
        $base = rtrim((string) config('ai.base_url'), '/');
        $parts = parse_url($base);
        if (! $parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        // Only server-admin configuration chooses endpoints. Remote traffic requires TLS.
        if (($parts['scheme'] ?? '') !== 'https' && ! (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            return null;
        }

        return $base.($provider === 'ollama' ? '/api/chat' : '/chat/completions');
    }

    public function send(array $payload): Response
    {
        $url = $this->endpoint();
        if (! $url) {
            throw new RuntimeException('Invalid AI endpoint.');
        }
        $provider = config('ai.provider');
        $request = Http::acceptJson()->timeout(30)->connectTimeout(8)->withoutRedirecting();
        if ($provider === 'anthropic') {
            $request = $request->withHeaders(['x-api-key' => config('ai.key'), 'anthropic-version' => '2023-06-01']);
        } elseif ($provider === 'gemini') {
            $request = $request->withHeaders(['x-goog-api-key' => config('ai.key')]);
        } elseif (config('ai.key')) {
            $request = $request->withToken(config('ai.key'));
        }
        if ($provider === 'openai') {
            return $request->post($url, $payload);
        }
        if ($provider === 'anthropic') {
            $response = $request->post($url, [
                'model' => $payload['model'], 'max_tokens' => $payload['max_output_tokens'],
                'system' => $payload['instructions'],
                'messages' => [['role' => 'user', 'content' => $payload['input']]],
            ]);
            $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");

            return $this->normalize($response->successful() && $response->json('stop_reason') === 'end_turn', $text);
        }
        if ($provider === 'gemini') {
            $response = $request->post($url, [
                'systemInstruction' => ['parts' => [['text' => $payload['instructions']]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $payload['input']]]]],
                'generationConfig' => ['maxOutputTokens' => $payload['max_output_tokens']],
            ]);
            $text = collect($response->json('candidates.0.content.parts', []))->filter(fn ($part) => empty($part['thought']) && isset($part['text']))->pluck('text')->implode("\n");

            return $this->normalize($response->successful() && $response->json('candidates.0.finishReason') === 'STOP' && ! $response->json('promptFeedback.blockReason'), $text);
        }
        $body = ['model' => $payload['model'], 'stream' => false, 'messages' => [
            ['role' => 'system', 'content' => $payload['instructions']],
            ['role' => 'user', 'content' => $payload['input']],
        ]];
        if ($provider === 'ollama') {
            $body['options'] = ['num_predict' => $payload['max_output_tokens']];
        } else {
            $body['max_tokens'] = $payload['max_output_tokens'];
        }
        $response = $request->post($url, $body);
        $complete = $provider === 'ollama'
            ? $response->json('done') === true && $response->json('done_reason') === 'stop'
            : $response->json('choices.0.finish_reason') === 'stop';
        $text = $provider === 'ollama' ? $response->json('message.content') : $response->json('choices.0.message.content');

        return $this->normalize($response->successful() && $complete, $text);
    }

    private function normalize(bool $complete, mixed $text): Response
    {
        if (! $complete || ! is_string($text) || trim($text) === '') {
            throw new RuntimeException('AI response failed or was incomplete.');
        }

        return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        ], JSON_THROW_ON_ERROR)));
    }
}
