# Fail loud when `HasProviderOptions` collides with reserved Responses-API keys

**Closes:** (link to issue from
[`ISSUE-provider-options-silent-collision.md`](ISSUE-provider-options-silent-collision.md))

## Motivation

See the linked issue for the full reproduction. In short: an agent
implementing `HasProviderOptions` can return keys (e.g. `response_format`)
that get `array_merge`-ed into a Responses-API body and silently break
the request. The user discovers this only as an opaque OpenAI 400.

The right contract for structured output is `HasStructuredOutput`, but
nothing in the SDK currently nudges the user there.

## Proposed change

Add a small reserved-key guard inside the OpenAI Responses-API request
builder. When agent-supplied provider options collide with reserved
body keys, throw an `InvalidArgumentException` that names the offending
key(s) and points at `HasStructuredOutput`.

### Scope

Two call sites:

1. `src/Gateway/OpenAi/Concerns/BuildsTextRequests.php::buildTextRequestBody()`
   — top-of-call body assembly.
2. `src/Gateway/OpenAi/Concerns/HandlesTextStreaming.php` (around lines
   364-391) — the tool-call follow-up request that re-uses the same
   `array_merge` pattern. Keep the guard consistent across both.

### Reserved keys

These are the keys the builder writes into `$body` or that the
Responses-API treats as structural. The legacy Chat-Completions
`response_format` is included because it is the most common
misconfiguration and is unconditionally wrong on this endpoint.

```php
private const RESERVED_RESPONSES_BODY_KEYS = [
    'model',
    'input',
    'text',
    'tools',
    'tool_choice',
    'stream',
    'max_output_tokens',
    'response_format', // legacy Chat-Completions shape; not valid here
];
```

### Diff sketch

`src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` — replace the
final `array_merge` with a guarded merge:

```diff
-        $providerOptions = $options?->providerOptions(
-            Lab::tryFrom($provider->driver()) ?? $provider->driver()
-        );
-
-        if (filled($providerOptions)) {
-            $body = array_merge($body, $providerOptions);
-        }
+        $providerOptions = $options?->providerOptions(
+            Lab::tryFrom($provider->driver()) ?? $provider->driver()
+        );
+
+        if (filled($providerOptions)) {
+            $this->assertNoReservedResponsesKeyCollision($providerOptions);
+            $body = array_merge($body, $providerOptions);
+        }
```

…and add the helper to the same trait:

```php
/**
 * Reject HasProviderOptions returns that would silently overwrite
 * structural Responses-API body keys (notably `response_format`,
 * which belongs to Chat Completions and is rejected by /responses).
 *
 * @param  array<string, mixed>  $providerOptions
 */
private function assertNoReservedResponsesKeyCollision(array $providerOptions): void
{
    $collisions = array_intersect_key(
        $providerOptions,
        array_flip(self::RESERVED_RESPONSES_BODY_KEYS),
    );

    if ($collisions === []) {
        return;
    }

    $keys = implode(', ', array_keys($collisions));
    $suggestion = isset($collisions['response_format'])
        ? ' Implement '
            .\Laravel\Ai\Contracts\HasStructuredOutput::class
            .' to define a JSON schema for the Responses API.'
        : '';

    throw new \InvalidArgumentException(
        "providerOptions() for the OpenAI driver returned reserved Responses-API key(s): {$keys}.{$suggestion}"
    );
}
```

A symmetric `assertNoReservedResponsesKeyCollision()` call goes into
the streaming follow-up at `HandlesTextStreaming.php` so the same
misconfiguration can't slip through on a tool-call round-trip.

### Out of scope

- Auto-translating the legacy `response_format` Chat-Completions shape
  into Responses-API `text.format`. Silent translation hides
  cross-API drift; this PR deliberately throws instead.
- Adding similar guards for Chat-Completions-shaped sibling providers
  (DeepSeek, Mistral, Groq, OpenRouter). They legitimately accept
  `response_format`, so the reserved-key list is OpenAI-Responses-API
  specific. A follow-up could introduce a per-provider reserved list
  if useful.

## Tests

Add three new cases under
`tests/Gateway/OpenAi/BuildsTextRequestsTest.php` (or the closest
existing test for `OpenAiGateway`):

1. **Throws on `response_format` collision.** Build an agent whose
   `providerOptions()` returns `['response_format' => [...]]`; assert
   `InvalidArgumentException`, assert the message mentions
   `HasStructuredOutput`.
2. **Throws on each reserved key.** Datasetted test (one row per
   reserved key) confirming each is rejected.
3. **Non-reserved provider options still pass through.** A
   provider-option like `metadata` or `user` should `array_merge`
   without throwing. Asserts the existing pass-through path is intact.

If `HandlesTextStreaming` has its own test, add one mirror test there
covering the tool-call follow-up body.

## README touch-up (optional but useful)

A short paragraph in the README under "Structured Output" pointing
users at `HasStructuredOutput` and explicitly noting that
`HasProviderOptions` must not be used to inject Chat-Completions-shaped
`response_format` against OpenAI. Reduces future occurrences.

## Risk

Low. The change is a single guard executed once per request build;
worst case it converts a remote 400 into a local exception with a
clearer message, on a misconfiguration that was already broken.
