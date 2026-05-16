# Upstream — `laravel/ai`

This directory collects upstream-facing material about issues we've hit in
[`laravel/ai`](https://github.com/laravel/ai) (installed version: `v0.6.8`).

Each document is written so it can be lifted directly into a GitHub
issue/PR on `laravel/ai` with minimal editing.

| Document | Purpose | Status |
|---|---|---|
| [`ISSUE-provider-options-silent-collision.md`](ISSUE-provider-options-silent-collision.md) | Reproducer + analysis: `HasProviderOptions` silently overwrites reserved Responses-API keys and produces a confusing OpenAI 400 instead of a clear error from the SDK. | Drafted (not yet filed) |
| [`PR-fail-loud-on-reserved-key-collision.md`](PR-fail-loud-on-reserved-key-collision.md) | Proposed fix: a reserved-key guard in `BuildsTextRequests::buildTextRequestBody()` (and the streaming follow-up) that throws an `InvalidArgumentException` naming the colliding key and pointing users at `HasStructuredOutput`. Includes diff sketch + test outline. | Drafted (not yet opened) |

## How we discovered this

While wiring the cdv-rabbit PR-review pipeline to OpenAI, the LLM call
returned:

> `400 Unsupported parameter: 'response_format'. In the Responses API, this parameter has moved to 'text.format'.`

The agent class injected `response_format` via `HasProviderOptions`,
expecting Chat Completions semantics. The SDK's OpenAI driver, however,
hits the **Responses API** unconditionally. The cdv-rabbit-side fix
(switching the agent to implement `HasStructuredOutput` instead) is
tracked separately in this project — these documents are about the
upstream sharp edge that made the misconfiguration silent and the error
message originate from OpenAI instead of the SDK.

## Filing checklist (when ready)

- [ ] Read both documents end-to-end.
- [ ] Strip any cdv-rabbit-specific paths from quoted code blocks.
- [ ] Verify the line numbers in `vendor/laravel/ai/` still match the
      then-current `main` branch of `laravel/ai`.
- [ ] Open the issue first; reference it from the PR body.
- [ ] Add the upstream issue/PR URLs back into the table above.
