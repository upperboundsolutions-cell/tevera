# AI providers

Fleet Assistant and Movement Analysis share the server-wide provider setting.
Only a server administrator changes this configuration. Company access checks
and explicit consent to include evidence apply regardless of provider.

For a local model already installed in Ollama:

```dotenv
AI_ENABLED=true
AI_PROVIDER=ollama
AI_BASE_URL=http://127.0.0.1:11434
AI_MODEL=your-installed-model-name
AI_API_KEY=
```

Ollama must be running separately. This integration does not install or download
models. It uses the native `/api/chat` endpoint, without streaming, following
https://docs.ollama.com/api/chat. Slow models may exceed the 30-second timeout.
Model quality and hardware capacity must be evaluated with actual fleet evidence.

For a service implementing OpenAI-compatible Chat Completions:

```dotenv
AI_ENABLED=true
AI_PROVIDER=compatible
AI_BASE_URL=https://your-provider.example/v1
AI_MODEL=your-provider-model-id
AI_API_KEY=your-provider-key
```

The base URL must include the provider's API prefix; `/chat/completions` is
appended. The provider must support system/user messages, `max_tokens`, and
non-streamed text responses with `finish_reason=stop`. Compatibility is not a
claim of support for every vendor API. Native Anthropic/Gemini formats are also
supported. There is no automatic fallback to another provider.

For Claude set `AI_PROVIDER=anthropic`, `AI_MODEL` to your available Claude model
ID and `AI_API_KEY` to your Anthropic key. The native Messages endpoint is fixed
to `https://api.anthropic.com/v1/messages`; `AI_BASE_URL` is ignored.
Reference: https://platform.claude.com/docs/en/api/messages/create

For Gemini set `AI_PROVIDER=gemini`, `AI_MODEL` to the model ID without a
`models/` prefix, and `AI_API_KEY` to your Gemini Developer API key. The native
Google generateContent endpoint is used; `AI_BASE_URL` is ignored. Vertex AI
OAuth and AWS Bedrock signing are not native integrations here.
Reference: https://ai.google.dev/api/generate-content

Other vendors can be connected through `compatible` if they or an independently
configured gateway implement the documented Chat Completions contract. Otherwise
they need a dedicated transport adapter. There is no universal AI API format.
These integrations support text-based fleet assistance, not image generation,
audio, tool execution or every provider-specific feature. Native integrations
have mocked contract tests; real provider credentials are required for live tests.

For existing OpenAI installations, `AI_PROVIDER=openai` retains the Responses
integration and supports existing `OPENAI_API_KEY` / `OPENAI_MODEL` values.
Generic `AI_API_KEY` / `AI_MODEL` values take precedence when set. Legacy OpenAI
keys are never automatically reused for other providers.

Remote endpoints require HTTPS. Plain HTTP is accepted only for loopback hosts.
In Docker, loopback refers to the app container, not the host. Use an accessible
HTTPS inference endpoint and pass AI variables into the app container environment.
Do not expose an unauthenticated model server publicly. Provider retention rules
vary; the UI identifies the configured provider before sharing evidence.

After changes run `php artisan config:clear` (or rebuild your production config
cache), and restart any long-running workers. Keys remain server-side.
