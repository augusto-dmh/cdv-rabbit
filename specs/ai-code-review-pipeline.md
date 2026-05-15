# AI Code Review Pipeline

## 1. Overview

### 1.1. Feature Name
**AI Code Review Pipeline — From Webhook to Posted Comments**

### 1.2. One-sentence summary
`ReviewPullRequestJob::handle()` orchestrates the full pipeline (kill-switch → cost reservation → PR refresh → diff fetch → secret redaction → XML wrap → Anthropic call via laravel/ai → comment posting), with the diff living only in `handle()` scope and every Anthropic call instrumented for telemetry, cost, error classification, and prompt-caching hits.

### 1.3. Primary outcome
A 200-LOC PR open against an enabled repository receives a summary comment + ≥1 inline comment from the `🤖 cdv-rabbit (AI generated)` bot within 60 seconds; the same PR re-delivered via the webhook produces no duplicate review.

### 1.4. Version & owner
`v1.0 – Phase 3 / Week 3 (tag phase-3-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
This is the heart of the product. Everything in Phase 1 (tenancy) and Phase 2 (ingestion) exists to safely deliver a PR diff to this pipeline; everything in W4-W5 exists to operationally observe and harden it.

### 2.2. Problem statement
Calling an LLM to review code involves: prompt construction, untrusted-content wrapping, secret pre-redaction, model routing, prompt caching (90% read discount), strict output schema, telemetry capture, error taxonomy, comment cap + dedup, kill switch, and atomic cost ceiling. Doing this well is the difference between a credible product and an expensive mess. We use `laravel/ai` (official Laravel 13 SDK) as the agent abstraction and drop to `providerOptions()` for three contracts the SDK doesn't expose first-class.

### 2.3. Goals
- **G1**: Pipeline never persists the diff (LGPD posture preserved).
- **G2**: Pipeline never exceeds the per-PR or per-workspace-day token budget (cost ceiling).
- **G3**: Anthropic structured output via strict `tool_choice` (grammar-constrained sampling — no free-form text leakage even under prompt injection).
- **G4**: Prompt caching maximizes cache hits across PRs of the same workspace (90% read discount on system+tools).
- **G5**: 25-comment cap, AI-generated marker prefix, dedup-update on re-review.
- **G6**: Kill switch halts dispatch within 10s.
- **G7**: All error paths map to a documented retry policy (terminal vs retry-with-backoff vs pause-workspace).

### 2.4. Non-goals / out of scope
- Incremental review on push (v0.2 — `pullrequest:updated` event).
- Bot commands (`@cdv-rabbit review/pause/...`) — v0.2.
- Custom rules / `.cdv-rabbit.yaml` — v0.2.
- Learnings / memory system — v0.2.
- Batch API (50% off) — v0.2.
- Haiku pre-screen for prompt injection — v0.2.
- Response cache by file hash — v0.2.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Jobs/ReviewPullRequestJob.php` — full orchestrator.
- `app/Ai/Agents/ReviewAgent.php` — laravel/ai Agent class (`#[Model('claude-sonnet-4-6')]`, `HasProviderOptions`).
- `app/Services/Llm/LlmDriverInterface.php` + `ClaudeReviewer.php` + `LlmReviewException.php` + `LlmCallTelemetry.php` + 3 DTOs.
- `app/Services/Llm/PromptBuilder.php` — XML envelope per §3.0.5.
- `app/Services/Review/{SkipRules, SecretRedactor, CommentSanitizer, CommentPoster, DiffChunker, CostReservation, CostReservationInterface, ReservationResult}.php` + `FileDiff` + `RedactionResult` value objects.
- `app/Support/{AnthropicErrorClassifier, AnthropicTransportMiddleware, AnthropicHeaderBag, RetryDecision}.php`.
- `app/Providers/AiServiceProvider.php` — wires the AI stack.
- `config/cdv-rabbit/prompts/review_v1.txt` (frozen) + `config/cdv-rabbit/schemas/review_result_v1.json` (frozen).
- `reviews_llm_calls` table (Phase 1 migration, populated here).

### 3.2. Out-of-scope for this iteration
- ~~Multi-provider fallback~~ — implemented in phase-openai (see `multi-llm-provider-support.md`).
- Streaming partial responses to the UI (we consume the final message).
- Per-file parallel LLM calls (sequential in MVP; concurrency optimization deferred).

### 3.3. Dependencies
- `laravel/ai ^0.6.8` (official Laravel AI SDK).
- Anthropic API key + budget.
- All of Phase 1 and Phase 2 features.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV developer** — opens PRs and receives bot comments.
- **CDV admin** — pulls the kill switch when something goes wrong.
- **DPO** — periodically audits that diff never lands at rest.

### 4.2. Primary use cases
- As a developer, I want a useful review on my PR within ~60s.
- As a developer, I want one inline comment per real issue, not 50 nitpicks.
- As an admin, I want to halt the bot in < 10s if it starts misbehaving on a critical PR.
- As a developer, I want re-running the review (force-push) to update existing comments, not flood the PR with duplicates.

### 4.3. User journey
PR opens → webhook arrives → `ReviewPullRequestJob` dispatched → kill-switch checked → cost reserved → BB refresh → diff fetched → chunked → secret-redacted → XML-wrapped → laravel/ai call (with cache_control + strict tool_choice) → response parsed into ReviewResultDto → telemetry recorded → comments posted (capped + marker-prefixed + deduped) → Review marked Posted.

---

## 5. Functional Requirements

**FR-01 — Pre-flight: kill switch**
- Trigger: job start.
- Behaviour: if `$workspace->kill_switch_enabled` true OR `config('cdv-rabbit.killed')` true → mark Review.status = Skipped, return.
- Output: no LLM call, no cost spent.

**FR-02 — Pre-flight: cost reservation (atomic)**
- Trigger: after kill-switch.
- Behaviour: `CostReservation::reserve($workspaceId, ESTIMATED_TOKENS_PER_PR, dailyCap)` (Lua INCRBY, atomic). Denial → mark Failed with `error_class=cost_ceiling`, alert mailable already enqueued by reserve(), return.
- Output: tokens reserved against the daily ceiling, or job aborts.

**FR-03 — Pre-flight: BB PR state + head_sha**
- Trigger: after cost reservation.
- Behaviour: `BitbucketClient::getPullRequest`; if state != OPEN → release reserved tokens, mark Skipped, return. If head_sha changed, update Review.head_sha and continue with new sha.
- Output: confirmed-active PR at known head.

**FR-04 — Pre-flight: diff-stat size check**
- Trigger: after PR state check.
- Behaviour: `BitbucketClient::getDiffStat` → if `lines_added + lines_removed > 8000` → post "diff too large to review" summary, mark Skipped, return.
- Output: PR fits within review budget or is rejected with explanation.

**FR-05 — Diff fetch (LOCAL VAR ONLY)**
- Trigger: after size check.
- Behaviour: `$diff = BitbucketClient::getDiff(...)`. The `$diff` variable lives only in `handle()` scope. Never assigned to `$this`, never logged, never written to DB.
- Output: diff content in memory; AC16 contract preserved.

**FR-06 — File chunking + filtering**
- Trigger: after diff fetch.
- Behaviour: `DiffChunker::chunk` → for each FileDiff, `SkipRules::isFileExcluded || isFileTooLarge` drops. Empty remaining → post "no reviewable changes" summary, mark Posted, return.
- Output: ordered list of reviewable FileDiffs.

**FR-07 — Secret redaction**
- Trigger: per FileDiff before LLM call.
- Behaviour: `SecretRedactor::redact` replaces matches with `<<SECRET_REDACTED>>`; per-file counts aggregated into `Review.secrets_redacted`.
- Output: sanitized FileDiff suitable for Anthropic.

**FR-08 — XML envelope wrap**
- Trigger: per FileDiff after redaction.
- Behaviour: `PromptBuilder::wrap($diff, $prMetadata)` produces `<pr_metadata>...</pr_metadata><diff>...</diff>` with `<`/`>`/`&` escaped to `&lt;`/`&gt;`/`&amp;` inside user content. AC24 — escape, not reject.
- Output: envelope ready for the user message.

**FR-09 — Anthropic call via laravel/ai**
- Trigger: per envelope.
- Behaviour: `ClaudeReviewer::reviewDiff(systemPrompt, toolSchema, envelope, options)` constructs a `ReviewAgent` invocation with:
  - `#[Model('claude-sonnet-4-6')]`, `#[MaxTokens(4096)]`, `#[Timeout(300)]`.
  - `providerOptions(['cache_control' => ['type' => 'ephemeral']])` — applied on last system block + last tool def.
  - `providerOptions(['tool_choice' => ['type' => 'tool', 'name' => 'review_result'], 'strict' => true])` — forced strict tool use (escape hatch per plan §3.0.10).
  - `.stream().then(cb)` + `foreach` drain to capture the final `StreamedAgentResponse` (the only public way; `$streamedResponse` is protected).
- Output: ReviewResultDto with parsed `summary { overview, risk_level }` and `comments[]`.

**FR-10 — Telemetry capture**
- Trigger: post-call (success or fail).
- Behaviour: `LlmCallTelemetry::record(Review, modelId, role, ReviewLlmCallResult, httpStatus, errorType?)` writes a `reviews_llm_calls` row with all 4 token fields + request_id + ratelimit_tokens_remaining + ratelimit_tokens_reset + duration_ms + http_status + error_type. Headers pulled from `app(AnthropicHeaderBag::class)` (request-scoped, populated by `AnthropicTransportMiddleware`).
- Output: persisted call row (no prompt/response text — LGPD).

**FR-11 — Comment posting**
- Trigger: per Review after all LLM calls aggregate.
- Behaviour: `CommentPoster::post(Review, ReviewResultDto)`:
  - If `comments` empty AND `summary.risk_level === 'low'` → post only summary (AC26).
  - Else cap inline at 25; overflow folded into summary as "+N more findings" (AC5).
  - Every comment prefixed `🤖 cdv-rabbit (AI generated):` (AC6).
  - Look up existing `review_comments` rows at (review.pr, path, line) → `updateComment` instead of duplicate post (AC7).
  - All comment text runs through `CommentSanitizer` before posting.
- Output: PR receives comments; `review_comments` rows persist metadata only (no code).

**FR-12 — Status persistence**
- Trigger: end of handle (success path).
- Behaviour: Review.status = Posted; finished_at, prompt_tokens, completion_tokens, cost_usd_cents, secrets_redacted persisted.

**FR-13 — Error handling**
- Trigger: any exception thrown by ClaudeReviewer.
- Behaviour: wrap via `AnthropicErrorClassifier` → `LlmReviewException` carrying `RetryDecision`:
  - `Terminal` → mark Review.status = Failed with error_class + error_message; post single "review failed: {correlation_id}" comment; `CostReservation::release` reserved tokens; return (no re-throw).
  - `RetryWithBackoff` → re-throw; Horizon backoff 10/30/90s ladder.
  - `PauseWorkspace` → mark workspace `health = paused`; re-throw or fail terminally (decision: terminal in MVP, manual unpause via UI).

### 5.2. Error and edge cases
- BB API returns 401 mid-job (token revoked between dispatch and execute) → classifier → `PauseWorkspace` → workspace paused, alert.
- Anthropic returns 529 (overloaded) → classifier → `RetryWithBackoff` → Horizon retries.
- LLM returns non-tool-call response (theoretical, given strict tool_choice) → caught at SDK layer, treated as Terminal.
- Comments array has 30 items → 25 posted inline, 5 folded into summary.
- Force-push during BB diff fetch (head_sha mismatch) → re-fetched with new sha; AC1 dispatch already happened so the review continues.

---

## 6. Non-Functional Requirements

- **Reliability**: every job either Posted, Failed, or Skipped — never lost.
- **Performance**: target p95 < 60s end-to-end for 200-LOC PRs.
- **Cost**: per-PR token cap 200_000; per-workspace daily ceiling (default 200_000 tokens), break-even on caching after 1 reuse within 5-min TTL.
- **Security**: prompt injection cannot break the JSON schema; sanitizer strips `@mentions`, BB issue refs, HTML, embedded images.

---

## 7. Data Model & Contracts

### 7.1. Tables populated
- `reviews` (status, tokens, cost, secrets_redacted, error_class, error_message, head_sha).
- `review_comments` (metadata only — no code).
- `reviews_llm_calls` (per-Anthropic-call telemetry).
- `webhook_deliveries` (referenced; populated by webhook receiver in Phase 2).

### 7.2. Frozen artifacts
- `config/cdv-rabbit/prompts/review_v1.txt` (~2324 tokens — clears the 1024 minimum cacheable prefix).
- `config/cdv-rabbit/schemas/review_result_v1.json` — exact §3.0.3 schema, `strict: true`, `additionalProperties: false`.

### 7.3. Contracts
- `LlmDriverInterface::reviewDiff(string, array, string, array): ReviewResultDto`.
- `LlmDriverFactory::make(Workspace): LlmDriverInterface`.
- `CostReservationInterface::reserve(int, string, int, int): ReservationResult`.
- `RetryDecision` enum: `Terminal | RetryWithBackoff | PauseWorkspace`.

---

## 8. AI/Agent Design

### 8.1. Provider abstraction
- `laravel/ai` as the agent SDK (provider-agnostic, with provider-specific escape hatches via `providerOptions()`).
- `ReviewAgent` class uses PHP 8.4 attributes for provider + model + max_tokens + timeout.

### 8.2. Prompt design
- System prompt (`review_v1.txt`): role definition, severity guide, untrusted-content directive, output constraints, quality bar — all in a single ~2324-token block that becomes the cache prefix.
- Tool schema (`review_result_v1.json`): strict mode + `additionalProperties: false` at every nesting level.
- User message: XML-wrapped `<pr_metadata>...</pr_metadata><diff>...</diff>` with content escaped.

### 8.3. Streaming consume
- `.stream()->then(callback)->foreach(...)` is the documented pattern for capturing the final `StreamedAgentResponse` (the response object is `protected` on the streamable; the callback is the only public capture point).

### 8.4. Error mapping
- laravel/ai exception types → walked via `previous` chain to find the underlying Guzzle HTTP status → `AnthropicErrorClassifier::classify` → `RetryDecision`.

---

## 9. UX & Interaction Design

- Posted comments use a marker prefix (`🤖 cdv-rabbit (AI generated):`) so developers instantly recognize bot vs human (AC6).
- Severity is inferable from the comment (high/medium/low/nit) — exposed in the comment body.
- "Review failed" comments carry a `correlation_id` so support can trace.
- The bot account name on Bitbucket is the workspace's `bitbucket_service_account` (configurable per workspace).

---

## 10. System Architecture & Integration

### 10.1. Service bindings (in `AiServiceProvider` + `AppServiceProvider`)
- `AnthropicHeaderBag::class` → scoped singleton.
- `LlmDriverInterface::class` → `ClaudeReviewer`.
- `CostReservationInterface::class` → `CostReservation`.
- `Http::globalMiddleware(AnthropicTransportMiddleware)` to capture response headers regardless of where the laravel/ai SDK makes the call from (the SDK's `CreatesAnthropicClient` trait uses `Http::baseUrl` directly with no DI seam).

### 10.2. Horizon supervisors
- `reviews` queue with concurrency 4, tries 3, timeout 300s, backoff 10/30/90s.
- `bitbucket-api` queue dedicated to comment posting so write bursts don't starve review jobs (planned; current implementation uses `default`).

### 10.3. Integration touch points
- `tenancy-and-workspace-isolation.md` — `BindWorkspaceMiddleware` binds context for the job.
- `lgpd-data-protection-posture.md` — diff stays in `handle()` scope; secrets redacted pre-LLM; failed-jobs redaction is the structural backstop.
- `bitbucket-cloud-integration.md` — `BitbucketClient` is the only diff source; `CommentPoster` writes back via same client.
- `cost-management-and-ceiling-alerts.md` — `CostReservation` is the gate before any Anthropic call.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Phase 3 acceptance criteria covered (per the plan):
- **AC1** — Dispatch in < 2s (covered by Phase 2 webhook test, regression-checked).
- **AC3** — End-to-end review post in < 60s for 200-LOC PR. Test: `ReviewPullRequestJobTest`.
- **AC5** — 25-comment cap. Test: `CommentPosterTest`.
- **AC6** — AI-generated marker prefix. Test: `CommentPosterTest`.
- **AC7** — Update existing comment vs duplicate. Test: `CommentPosterTest`.
- **AC8** — Kill switch halts dispatch. Test: `ReviewPullRequestJobTest` + `KillSwitchE2eTest`.
- **AC9** — Secret redaction. Tests: `SecretRedactorTest` + integration.
- **AC10** — 3 prompt-injection fixtures rejected by schema. Test: `ReviewPullRequestJobTest`.
- **AC21** — `cache_creation_input_tokens > 0` on first call; `cache_read_input_tokens > 0` on subsequent within TTL. Test: `ClaudeReviewerTest` (telemetry layer) + live-Anthropic gate deferred to W6 pilot.
- **AC22** — `request_id` populated. Test: `LlmCallTelemetryTest`.
- **AC23** — Schema freeze snapshot. Test: `SchemaFreezeTest`.
- **AC24** — XML escape, not reject. Test: `PromptBuilderTest`.
- **AC25** — Error taxonomy retry vs terminal. Test: `AnthropicErrorClassifierTest` + integration.
- **AC26** — Empty comments + risk=low → summary only. Test: `CommentPosterTest`.

---

## 12. Telemetry, Observability & Evaluation Metrics

- Per-call: `reviews_llm_calls` row (all 4 token fields + request_id + ratelimit headers + duration_ms + http_status + error_type).
- Per-review: `reviews` row aggregates (prompt_tokens, completion_tokens, cost_usd_cents, secrets_redacted, error_class, error_message, status).
- Cost computation: `cost_usd_cents = ROUND(input × $3/MTok + cache_creation × $3.75/MTok + cache_read × $0.30/MTok + output × $15/MTok)` for Sonnet 4.6.
- Cache hit-rate per workspace = `cache_read_input_tokens / (cache_read + cache_creation + input)` averaged over a window.

---

## 13. Security, Privacy & Compliance

- Strict `tool_choice` + grammar-constrained sampling defeats prompt-injection attempts to produce free-form harmful comments.
- XML escape prevents envelope-breaking payloads (AC24).
- Sanitizer strips potentially abuse-vector tokens (`@mentions`, embedded HTML/images).
- Comments labeled AI-generated (AC6) — no spoofing of human reviewers.
- All Anthropic calls instrumented with `request_id` for support escalation.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Forced `tool_choice` produces comments even on trivial PRs | Mitigated by AC26 (empty + risk=low → summary only). |
| Prompt-injection that bypasses the schema | Not 100% preventable at model layer; real defenses are AC6 marker + 25-cap + kill switch + W6 pilot human-in-the-loop. |
| Cache invalidation on schema bump | Documented migration path: `review_result_v2` (new tool name) ships alongside `review_v2.txt`. |
| Anthropic outage | All calls return Terminal/RetryWithBackoff via classifier; reviews go Failed visibly, never silently. |
| Large monorepo PR with 8000+ lines | Skipped with explanation; AC11 cost ceiling further bounds blast radius. |
| laravel/ai 0.x stability | Pre-1.0; mitigated by abstracting behind `LlmDriverInterface` so Anthropic SDK fallback is possible if the SDK API changes. |

Open: should we add a max-LLM-calls-per-job cap as a belt-and-suspenders complement to the cost ceiling? (Backlog.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-3-complete)** — Initial Phase 3 implementation. Commits: `d3b53bb` (W3-T1 foundation), `8378d28` (W3-T2 frozen artifacts), `eb74aca`'s parent (W3-T3 services), `eb74aca` (W3-T5 CostReservation), `37ce7c2` (W3-T4 ClaudeReviewer), `bc5e748` (W3-T6 CommentPoster + telemetry), `d64b0cb` (W3-T7 orchestrator), Phase 3 verifier commit (W3-T8). Tests: 245+/645+ green.
- **v1.1 (2026-05-14 / phase-openai)** — Added OpenAI GPT as second provider. Per-workspace `llm_provider` column (`anthropic`|`openai`). `LlmDriverFactory` resolves the correct driver at job runtime inside `handle()`. `CostReservation` Lua key now includes provider suffix (`workspace:{id}:tokens:{date}:{provider}`) so each provider has an independent daily ceiling. `LlmCallTelemetry` records `provider` + `model` columns (nullable for legacy rows). `rabbit:lgpd-check` adds check #9: `OPENAI_DPA_URL` env must be set when any workspace uses OpenAI. Health endpoint adds `openai_api` reachability check. AC27–AC31 added (multi-provider e2e, OpenAI DPA gate, provider cost isolation, telemetry schema, Anthropic regression).
