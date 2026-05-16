# `HasProviderOptions` silently overwrites reserved Responses-API keys, producing a confusing OpenAI 400

**Package:** `laravel/ai`
**Installed version:** `v0.6.8`
**Driver affected:** OpenAI (Responses API path)

## Summary

When an agent implements `HasProviderOptions` and returns one of the
keys reserved by the Responses-API body (`response_format`, `text`,
`model`, `input`, `tools`, `tool_choice`, `stream`,
`max_output_tokens`), the SDK does an unconditional `array_merge` of
those options into the request body. There's no validation, no
warning, and no documentation pointing users to the correct contract
(`HasStructuredOutput`).

The user-visible symptom is an OpenAI HTTP 400 like:

> `Unsupported parameter: 'response_format'. In the Responses API, this parameter has moved to 'text.format'. Try again with the new parameter.`

…which looks like an OpenAI problem and gives no hint that the SDK has
a first-class API (`HasStructuredOutput`) for the same intent.

This is a developer-experience papercut: the misconfiguration is
trivially detectable inside the SDK, but is currently surfaced only by
the remote API after a round-trip.

## Reproduction (minimal)

```php
use Laravel\Ai\Attributes\{Model, Provider};
use Laravel\Ai\Contracts\{Agent, HasProviderOptions};
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
class BrokenAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        // Intent: structured output. This is the Chat Completions shape;
        // it does NOT match what the Responses API expects.
        return [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'demo',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['ok' => ['type' => 'boolean']],
                        'required' => ['ok'],
                    ],
                ],
            ],
        ];
    }
}

(new BrokenAgent)->prompt('hi'); // OpenAI returns 400.
```

Expected: the SDK fails loudly *before* the HTTP call, with a message
naming the offending key and pointing at `HasStructuredOutput`.

Actual: the SDK silently builds a Responses-API body, `array_merge`s
the `response_format` key into it, sends it, and the user sees an
opaque OpenAI 400.

## Where this happens in the source

All references are against `v0.6.8`.

### 1. OpenAI driver targets the Responses API unconditionally

`src/Gateway/OpenAi/OpenAiGateway.php:73` (non-streaming):

```php
fn () => $this->client($provider, $timeout)->post('responses', $body),
```

`src/Gateway/OpenAi/OpenAiGateway.php:107` (streaming) and
`src/Gateway/OpenAi/Concerns/HandlesTextStreaming.php:397` (tool-call
follow-up) do the same. There is no Chat-Completions path for OpenAI in
v0.6.8 — the URI is hard-coded.

The body builder's docblock confirms the intent
(`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:13-14`):

```php
/**
 * Build the request body for the OpenAI Responses API.
 */
```

### 2. The structured-output schema is built as `text.format`

`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:34-36, 61-75`:

```php
if (filled($schema)) {
    $body['text'] = $this->buildSchemaFormat($schema);
}
...
return [
    'format' => [
        'type' => 'json_schema',
        'name' => $schemaArray['name'] ?? 'schema_definition',
        'schema' => Arr::except($schemaArray, ['name']),
        'strict' => true,
    ],
];
```

This is the **correct** Responses-API shape.

### 3. The collision: `HasProviderOptions` is `array_merge`-ed without filtering

`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php:47-53`:

```php
$providerOptions = $options?->providerOptions(
    Lab::tryFrom($provider->driver()) ?? $provider->driver()
);

if (filled($providerOptions)) {
    $body = array_merge($body, $providerOptions);
}
```

Any reserved key returned by the agent silently overrides the body
that the SDK just carefully built. Including the very keys that the
Responses API will then reject.

### 4. The first-class alternative the user should be using

`src/Contracts/HasStructuredOutput.php:8-16`:

```php
interface HasStructuredOutput
{
    public function schema(JsonSchema $schema): array;
}
```

This is what `GeneratesText` / `StreamsText` consume:

`src/Providers/Concerns/GeneratesText.php:61`:

```php
$schema = $agent instanceof HasStructuredOutput
    ? $agent->schema(new JsonSchemaTypeFactory)
    : null;
```

`src/Providers/Concerns/StreamsText.php:39` is structurally identical.
The returned schema flows through `generateText()` / `streamText()`
into `buildTextRequestBody(..., ?array $schema, ...)` and ends up as
`body.text.format` — exactly what the API expects.

But `HasStructuredOutput` is undocumented in the README, and there's no
runtime nudge from `HasProviderOptions` toward it.

## Reserved keys (proposed list, OpenAI Responses API)

These are the keys `BuildsTextRequests::buildTextRequestBody()` writes
into `$body` (or that the API treats as structural) before the
provider-option merge:

- `model`
- `input`
- `text` (where the SDK puts `format` for structured output)
- `tools`
- `tool_choice`
- `stream`
- `max_output_tokens`
- `response_format` (legacy Chat-Completions key — always wrong on
  Responses API)

A guard against this exact list at the merge point would have caught
the reproducer above.

## Severity

- Not a wrong-output bug; calls fail outright with 400.
- Affects any user attempting structured output without realising
  `HasStructuredOutput` exists. The Chat-Completions `response_format`
  shape is widely documented elsewhere (OpenAI cookbook, blog posts),
  so reaching for it via `HasProviderOptions` is a natural mistake.
- Low cost to fix: one helper, one throw, two call sites.

## Suggested next steps

A PR with a fail-loud reserved-key guard plus a one-line README note
on `HasStructuredOutput`. Sketch in
[`PR-fail-loud-on-reserved-key-collision.md`](PR-fail-loud-on-reserved-key-collision.md).

## Environment

- PHP 8.4
- Laravel 13
- `laravel/ai` `v0.6.8`
- OpenAI Responses API (any model that requires it; reproduced against
  `gpt-4o`).
