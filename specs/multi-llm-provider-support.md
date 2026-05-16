# Multi-LLM Provider Support (Anthropic + OpenAI)

## 1. Overview

### 1.1. Feature Name
**Multi-LLM Provider Support — Workspace-Scoped Provider Selection (Anthropic Claude + OpenAI GPT)**

### 1.2. One-sentence summary
Workspace admins can choose between Anthropic Claude and OpenAI GPT as the LLM backend for code review via a `workspaces.llm_provider` enum column, with provider selection resolved at job runtime via `LlmDriverFactory` and both providers producing identical `ReviewResultDto` output while maintaining separate cost ceilings per provider.

### 1.3. Primary outcome
An admin switches a workspace from `anthropic` (default) to `openai` via PATCH `/workspaces/{slug}`. The next dispatched `ReviewPullRequestJob` picks up the new provider, routes the LLM call to OpenAI via `OpenAiReviewer`, and posts identical review comments. Both providers share the same comment-posting, telemetry, cost-management, and LGPD-compliance infrastructure. A workspace using OpenAI cannot deploy unless `OPENAI_DPA_URL` env var is set (compliance gate via `rabbit:lgpd-check` check #9).

### 1.4. Version & owner
`v1.0 – Phase phase-openai (2026-05-15). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
The code review pipeline (`ai-code-review-pipeline.md`) was architected from the start with laravel/ai as a provider-agnostic abstraction. Phase-openai realizes that abstraction by adding a second provider backend (OpenAI) alongside Anthropic, allowing workspace admins to pick the provider that best matches their cost, latency, or compliance posture.

### 2.2. Problem statement
- **Flexibility**: some customers prefer GPT-4o for speed or familiarity; others prefer Claude for cost or capabilities.
- **Cost isolation**: Anthropic and OpenAI have different token pricing and rate limits; a single daily ceiling would conflate them. Per-provider ceilings preserve budget accountability.
- **Compliance**: OpenAI data processing requires a signed DPA; Anthropic has separate terms. Both must be acknowledged before use.

### 2.3. Goals
- **G1**: Workspace admins can switch provider via UI (PATCH `/workspaces/{slug}` with `llm_provider` in payload).
- **G2**: Provider is resolved inside `ReviewPullRequestJob::handle()` via `LlmDriverFactory::make(Workspace)` — late binding ensures each job sees the current workspace config.
- **G3**: Both providers produce identical `ReviewResultDto` — downstream code (comment posting, telemetry, cost tracking) is provider-agnostic.
- **G4**: Cost ceilings are per-provider-per-day (Redis key includes provider suffix). Anthropic and OpenAI cannot deplete each other's budget.
- **G5**: OpenAI DPA compliance is gated: `rabbit:lgpd-check` check #9 blocks deploy if any workspace uses OpenAI but `OPENAI_DPA_URL` env is unset.
- **G6**: Health endpoint (`GET /up`) includes OpenAI API reachability check (same 401 = reachable convention as Anthropic).
- **G7**: Telemetry (`reviews_llm_calls` table) records `provider` and `model` columns to disambiguate calls across providers.

### 2.4. Non-goals / out of scope
- Per-provider pricing/billing UI (cost data exists; display is backlog W4-W5).
- Streaming responses to browser (both providers consume to final message, same as Anthropic MVP).
- Auto-fallback on provider failure (manual switch via admin UI; no transparent failover in MVP).
- Parallel per-file calls across providers (sequential within a single provider).
- Per-workspace per-provider configuration of model selection (both providers use fixed models in MVP: Claude Sonnet 4.6, GPT-4o).

---

## 3. Scope

### 3.1. In-scope functionality
- `workspaces.llm_provider` column (`varchar` enum: `anthropic|openai`, default `anthropic`).
- `App\Services\Llm\LlmDriverInterface` — 4 methods: `reviewDiff()`, `getSystemPrompt()`, `getToolSchema()`, `getModel()`.
- `App\Services\Llm\LlmDriverFactory::make(Workspace): LlmDriverInterface` — singleton factory resolving driver per workspace.
- `App\Services\Llm\ClaudeReviewer` — existing Anthropic implementation, implements interface.
- `App\Services\Llm\OpenAiReviewer` — new OpenAI implementation, uses `OpenAiReviewAgent` with `gpt-4o` model.
- `App\Support\OpenAiErrorClassifier` — maps OpenAI HTTP error codes to `RetryDecision` enum.
- `App\Support\OpenAiHeaderBag` — extracts rate-limit headers from OpenAI responses.
- `reviews_llm_calls` schema: add nullable `provider` and `model` varchar columns (populated by telemetry layer).
- `App\Http\Controllers\Workspaces\WorkspaceController::update()` — validate `llm_provider` as `in:anthropic,openai`; persist.
- Redis Lua key scheme update: `workspace:{id}:tokens:{date}:{provider}` (per-provider daily ceiling).
- `CostReservationInterface` method signatures: all now include `string $provider` parameter.
- `config/cdv-rabbit.php` — add `openai_dpa_url => env('OPENAI_DPA_URL')`.
- `Health/HealthController` — add `openai_api` check (same reachability convention: 401 = ok).
- `Console/Commands/LgpdCheckCommand` — add check #9: if any workspace has `llm_provider='openai'` AND `OPENAI_DPA_URL` is unset → fail.
- Review list + detail UI: display provider+model badge (e.g. `claude-sonnet-4-6` or `gpt-4o`).
- Workspace settings page (`resources/js/pages/workspaces/Show`): add `llm_provider` dropdown.

### 3.2. Out-of-scope for this iteration
- Per-workspace model overrides (all Anthropic workspaces use Sonnet 4.6; all OpenAI use GPT-4o).
- Provider-specific prompt tuning (system prompt + tool schema are shared).
- Cost forecasting or spending dashboard (telemetry exists; UI is backlog).

### 3.3. Dependencies
- `laravel/ai ^0.6.8` (existing; supports both Anthropic and OpenAI providers).
- Anthropic API credentials + DPA URL (existing).
- OpenAI API credentials + DPA URL (new; gated by check #9).
- `LgpdCheckCommand` from `lgpd-compliance-tooling.md` (extended with check #9).
- `CostReservation` from `cost-management-and-ceiling-alerts.md` (updated to support per-provider keys).
- `HealthController` from `health-and-readiness-checks.md` (extended with OpenAI check).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **Workspace admin** — wants to choose the LLM provider that best fits their needs (cost, speed, compliance).
- **DPO / compliance officer** — needs to ensure OpenAI DPA is signed and `OPENAI_DPA_URL` is set before any workspace can use OpenAI.
- **Platform operator** — needs to monitor both providers' rate limits and cost consumption separately.
- **CDV developer** — receives identical review comments regardless of which provider the workspace uses.

### 4.2. Primary use cases
- As an admin, I want to switch my workspace to OpenAI GPT-4o because it's faster for my team's code style.
- As a DPO, I want a deployment gate that prevents OpenAI workspaces from running without the DPA URL signed.
- As an operator, I want to monitor Anthropic and OpenAI token consumption separately so I can budget for each.
- As a developer, I want the same quality review comments whether my workspace uses Claude or GPT-4o.

### 4.3. User journey
Admin navigates to workspace settings → selects `OpenAI GPT-4o` from dropdown → saves. Next PR webhook arrives → job dispatches → `LlmDriverFactory::make($workspace)` returns `OpenAiReviewer` → reviewDiff() calls OpenAI API → response parsed to identical `ReviewResultDto` → comments posted. Operator runs `php artisan rabbit:lgpd-check` → check #9 verifies `OPENAI_DPA_URL` is set → deploy proceeds.

---

## 5. Functional Requirements

**FR-01 — Provider column and default**
- Trigger: workspace creation (migration).
- Behaviour: `workspaces.llm_provider` varchar(20), nullable in old rows, defaults to `anthropic` for new rows.
- Output: every workspace has a provider choice; backward-compatible.

**FR-02 — `LlmDriverFactory::make(Workspace)` late-binding resolution**
- Trigger: `ReviewPullRequestJob::handle()` calls `app(LlmDriverFactory::class)->make($workspace)`.
- Behaviour:
  - If `$workspace->llm_provider === 'anthropic'` → return new `ClaudeReviewer`.
  - If `$workspace->llm_provider === 'openai'` → return new `OpenAiReviewer`.
  - Default (null/missing) → return `ClaudeReviewer` (backward compatible).
- Output: appropriate driver instance.
- Note: Factory is a singleton; Workspace is loaded dynamically inside handle(), so contextual container binding is impossible. Late binding in the method body is the pattern.

**FR-03 — `LlmDriverInterface` contract**
- All implementations expose:
  - `reviewDiff(string $systemPrompt, array $toolSchema, string $userMessage, array $providerOptions=[]): ReviewResultDto`.
  - `getSystemPrompt(): string`.
  - `getToolSchema(): array`.
  - `getModel(): string`.
- Both Anthropic and OpenAI implementations return identical `ReviewResultDto` structure.

**FR-04 — `ClaudeReviewer` implementation (updated)**
- Trigger: `reviewDiff()` called from orchestrator.
- Behaviour:
  - Uses `ReviewAgent` with `#[Model('claude-sonnet-4-6')]` attribute.
  - Applies `providerOptions(['tool_choice' => ['type'=>'tool', 'name'=>'review_result'], 'cache_control' => ['type'=>'ephemeral']])`.
  - Streams response via `.stream()->then(cb)->foreach(...)`.
  - Returns `ReviewResultDto` with cache token counts populated.
- Output: parsed review result, telemetry with cache tokens recorded.

**FR-05 — `OpenAiReviewer` implementation (new)**
- Trigger: `reviewDiff()` called from orchestrator.
- Behaviour:
  - Uses `OpenAiReviewAgent` with `#[Provider(Lab::OpenAI)] #[Model('gpt-4o')] #[MaxTokens(4096)]` attributes.
  - Agent implements `Laravel\Ai\Contracts\HasStructuredOutput`. Its `schema(JsonSchema $schema)` method translates the project's JSON-Schema array (`review_result_v1.json`) into the `Illuminate\JsonSchema\Types\Type` tree the SDK feeds into the Responses-API `body.text.format`. **Not** `HasProviderOptions` injecting `response_format` — that is the Chat-Completions key and is silently rejected by the OpenAI gateway's `/responses` endpoint (see `docs/upstream/laravel-ai/`).
  - Note: OpenAI has no prompt-caching equivalent. `cacheCreationInputTokens=0`, `cacheReadInputTokens=0` always.
  - Uses non-streaming `prompt()` (laravel/ai rejects `stream()` + `HasStructuredOutput` with "Streaming structured output is not currently supported").
  - Returns identical `ReviewResultDto` (cache token fields = 0 for OpenAI).
- Output: parsed review result, telemetry recorded.

**FR-06 — `OpenAiErrorClassifier` (new)**
- Trigger: `OpenAiReviewer::reviewDiff()` catches an HTTP exception.
- Behaviour: map OpenAI error codes to `RetryDecision`:
  - `rate_limit_exceeded` (429) → `RetryWithBackoff`.
  - `insufficient_quota`, `context_length_exceeded`, `invalid_request_error` → `Terminal`.
  - `server_error`, `api_error` (5xx) → `RetryWithBackoff`.
  - Other exceptions → `Terminal`.
- Output: `RetryDecision` enum value used by orchestrator.

**FR-07 — Cost reservation per-provider isolation (Lua key update)**
- Trigger: `ReviewPullRequestJob::handle()` calls `CostReservation::reserve($workspaceId, $provider, $estimatedTokens, $dailyCap)`.
- Behaviour: Redis Lua script uses key `workspace:{id}:tokens:{YYYYMMDD}:{provider}` instead of `workspace:{id}:tokens:{YYYYMMDD}`. Each provider has an independent daily counter and ceiling.
- Output: separate token budgets for Anthropic and OpenAI; no cross-provider depletion.

**FR-08 — `CostReservationInterface` signature update**
- All methods gain `string $provider` parameter:
  - `reserve(int $workspaceId, string $provider, int $tokens, int $dailyCap): ReservationResult`.
  - `consumed(int $workspaceId, string $provider): int`.
  - `release(int $workspaceId, string $provider, int $tokens): void`.

**FR-09 — Telemetry schema update**
- Trigger: `LlmCallTelemetry::record()` persists a call.
- Behaviour: populate `reviews_llm_calls.provider` (string, e.g. `'anthropic'` or `'openai'`) and `reviews_llm_calls.model` (e.g. `'claude-sonnet-4-6'` or `'gpt-4o'`).
- Output: disambiguated telemetry rows; legacy rows (before this phase) have NULL in both columns.

**FR-10 — Workspace settings PATCH endpoint validation**
- Trigger: `PATCH /workspaces/{slug}` with `llm_provider` in payload.
- Behaviour: validate `llm_provider` with rule `in:anthropic,openai`. On error, redirect back with validation error. On success, update workspace, redirect to show.
- Output: workspace persisted with new provider; next job sees it.

**FR-11 — Health endpoint OpenAI check**
- Trigger: `GET /up` request.
- Behaviour: add a 6th concurrent check for OpenAI API reachability (HEAD/GET to configured OpenAI base URL with 2s timeout). Convention: 401 = reachable (same as Anthropic); 5xx or timeout = degraded.
- Output: `checks.openai_api` in JSON response with `ok` + `duration_ms`.

**FR-12 — LGPD compliance gate check #9 (new)**
- Trigger: `php artisan rabbit:lgpd-check` runs.
- Behaviour: query `workspaces` table; if any row has `llm_provider='openai'` AND `config('cdv-rabbit.openai_dpa_url')` is empty → return fail (red check). Otherwise pass (green check).
- Output: table row printed; check #9 status determines overall exit code.

**FR-13 — Config key for OpenAI DPA URL**
- Trigger: application bootstrap.
- Behaviour: `config/cdv-rabbit.php` has key `'openai_dpa_url' => env('OPENAI_DPA_URL')`.
- Output: env var can be read via `config('cdv-rabbit.openai_dpa_url')`.

### 5.2. Error and edge cases
- Workspace has `llm_provider=null` (legacy from before this phase) → factory defaults to `ClaudeReviewer`.
- OpenAI API returns 429 rate-limit → classified as `RetryWithBackoff` → Horizon retries.
- OpenAI API returns 400 `invalid_request_error` (bad prompt) → classified as `Terminal` → review marked Failed.
- Admin switches provider mid-day → next job sees new provider and reserves from the new provider's daily bucket (separate from the old provider's consumption).
- Admin tries to enable OpenAI workspace but `OPENAI_DPA_URL` is unset → `rabbit:lgpd-check` fails on deploy; operator must set env and redeploy.

### 5.3. Backward compatibility
- Existing workspaces with `llm_provider=NULL` → treated as `anthropic` by factory (no breaking change).
- Existing `reviews_llm_calls` rows (before this phase) have `provider=NULL` and `model=NULL` (no migration required; data stays as-is).
- Cost ceiling checks still work: if a workspace never had per-provider keys used before, Redis keys are fresh per provider on first reserve.

---

## 6. Non-Functional Requirements

- **Latency**: both providers should complete reviews within p95 < 60s for 200-LOC PRs (target same as Anthropic MVP; OpenAI p95 may be slightly faster or slower; tuned empirically).
- **Cost**: per-provider-per-day ceiling enforced (independent budgets). Anthropic: default 200k tokens/day. OpenAI: operator-configurable (same interface).
- **Reliability**: provider switch is transparent to downstream code; any provider error maps to a standard `RetryDecision` taxonomy.
- **Compliance**: OpenAI workspaces cannot run without `OPENAI_DPA_URL` set (gate at deploy time).
- **Observability**: telemetry distinguishes providers via `reviews_llm_calls.provider` column for per-provider cost + latency analysis.

---

## 7. Data Model & Contracts

### 7.1. Tables changed
- `workspaces.llm_provider` — varchar(20), nullable, default NULL (factory treats NULL as 'anthropic').
- `reviews_llm_calls.provider` — varchar(20), nullable (NULL for legacy rows; 'anthropic' or 'openai' for new calls).
- `reviews_llm_calls.model` — varchar(100), nullable (NULL for legacy; 'claude-sonnet-4-6' or 'gpt-4o' for new calls).

### 7.2. Migration
- `2026_05_14_000001_add_llm_provider_to_workspaces_table.php` — adds `llm_provider` column.
- `2026_05_14_000002_add_provider_model_to_reviews_llm_calls_table.php` — adds `provider` and `model` columns.

### 7.3. Config
```php
// config/cdv-rabbit.php
'openai_dpa_url' => env('OPENAI_DPA_URL'),
```

### 7.4. Contracts
- `LlmDriverInterface::reviewDiff(string, array, string, array=[]): ReviewResultDto`.
- `LlmDriverFactory::make(Workspace): LlmDriverInterface`.
- `CostReservationInterface::reserve(int, string, int, int): ReservationResult` (added `$provider` param).
- `CostReservationInterface::consumed(int, string): int` (added `$provider` param).
- `CostReservationInterface::release(int, string, int): void` (added `$provider` param).

---

## 8. AI/Agent Design

### 8.1. Dual provider abstraction via laravel/ai
- Both providers use laravel/ai's agent pattern: PHP 8.4 attributes + tool invocation.
- `ReviewAgent` (Anthropic) uses `#[Model('claude-sonnet-4-6')]`.
- `OpenAiReviewAgent` (OpenAI) uses `#[Provider(Lab::OpenAI)] #[Model('gpt-4o')]`.
- Both implement the same `reviewDiff()` method signature and return identical `ReviewResultDto`.

### 8.2. Tool/schema equivalence
- Tool schema (`review_result_v1.json`) is identical across providers (both Anthropic and OpenAI support JSON schema strict mode).
- Anthropic uses `tool_choice` force (via `HasProviderOptions`); OpenAI uses laravel/ai's `HasStructuredOutput` contract, which the SDK renders as Responses-API `body.text.format`. The same project-side JSON Schema feeds both paths.
- System prompt is identical (no provider-specific tuning in MVP).

### 8.3. Error handling
- `AnthropicErrorClassifier` for Anthropic errors.
- `OpenAiErrorClassifier` for OpenAI errors.
- Both map to same `RetryDecision` enum → orchestrator is provider-agnostic.

### 8.4. Streaming
- Anthropic streams responses; `ClaudeReviewer` consumes via the `.stream()->then(cb)->foreach(...)` pattern.
- OpenAI uses non-streaming `prompt()`. laravel/ai v0.6.8 throws `InvalidArgumentException("Streaming structured output is not currently supported")` when `stream()` is combined with `HasStructuredOutput`, and structured output is non-negotiable for us (it's how the orchestrator parses the response into `ReviewResultDto`). `OpenAiReviewer` therefore awaits the final `AgentResponse` directly. Downstream consumers (telemetry, parser) operate on the aggregated response in both paths.

---

## 9. UX & Interaction Design

### 9.1. Workspace settings page
- Workspace admin navigates to settings.
- Dropdown shows `Anthropic Claude (default)` and `OpenAI GPT-4o`.
- Selected value persists via PATCH request.
- Validation error on invalid value redirected with flash message.

### 9.2. Review detail / list pages
- Each review shows a provider+model badge (e.g. "claude-sonnet-4-6" or "gpt-4o").
- Badge helps users understand which provider was used for a given review.

### 9.3. No UI for per-provider caps
- Operator configures caps via env vars or direct DB write (backlog: admin UI in W4-W5).

---

## 10. System Architecture & Integration

### 10.1. Runtime flow
```
ReviewPullRequestJob::handle()
  → LlmDriverFactory::make($workspace) — resolves provider
  → if anthropic: new ClaudeReviewer()
  → if openai: new OpenAiReviewer()
  → $driver->reviewDiff(...) — uniform interface
  → ReviewResultDto returned
  → CommentPoster::post() — provider-agnostic
```

### 10.2. Service bindings
- `LlmDriverFactory` — singleton, registered in `AiServiceProvider`.
- Each driver (`ClaudeReviewer`, `OpenAiReviewer`) is instantiable; factory calls `make()` which new's them up (no DI on Workspace since it's loaded in handle()).

### 10.3. Cost reservation flow
```
$provider = $workspace->llm_provider ?? 'anthropic';
$result = $costReservation->reserve($workspace->id, $provider, $estimatedTokens, $cap);
if ($result->denied()) {
  // Mark review Failed, release, return
}
// ... LLM call ...
$consumed = $result->granted;
$costReservation->release($workspace->id, $provider, $refundAmount); // on job failure
```

### 10.4. Integration touch points
- `ai-code-review-pipeline.md` — orchestrator updated to call `$driver->reviewDiff()` instead of direct `ClaudeReviewer`.
- `cost-management-and-ceiling-alerts.md` — `CostReservation` Lua key now includes provider suffix; methods gain `$provider` param.
- `health-and-readiness-checks.md` — adds 6th check for OpenAI API.
- `lgpd-compliance-tooling.md` — adds check #9 for OpenAI DPA URL.
- `observability-and-structured-logs.md` — telemetry layer records `provider` and `model` columns.

---

## 11. Validation: Acceptance Criteria & Test Strategy

### 11.1. Acceptance criteria (AC27–AC31)

**AC27** — Provider can be switched via PATCH
- Test: `WorkspaceControllerTest` → PATCH `/workspaces/{slug}` with valid `llm_provider` → 302 redirect + workspace.llm_provider updated.
- Invalid value (e.g. `'gpt-3'`) → 422 validation error returned.

**AC28** — Deployment gate: OpenAI DPA URL required
- Test: `LgpdCheckCommandTest` → if any workspace has `llm_provider='openai'` AND `OPENAI_DPA_URL` unset → check #9 fails (red), exit 1.
- Passing case: `OPENAI_DPA_URL` set → check #9 passes (green), exit 0.
- Edge case: no OpenAI workspaces → check #9 passes regardless of env var.

**AC29** — OpenAI error classification
- Test: `OpenAiReviewerTest` → OpenAI returns 429 `rate_limit_exceeded` → `OpenAiErrorClassifier` returns `RetryWithBackoff` enum.
- OpenAI returns 400 `insufficient_quota` → returns `Terminal`.
- OpenAI returns 400 `context_length_exceeded` → returns `Terminal`.

**AC30** — Terminal errors mark review Failed; RetryWithBackoff re-throws
- Test: `ReviewPullRequestJobWithOpenAiTest` → OpenAI returns Terminal error → review.status = Failed, no re-throw.
- OpenAI returns RetryWithBackoff → exception re-thrown, Horizon retries.

**AC31** — Health endpoint includes OpenAI check
- Test: `HealthControllerTest` → `/up` response has `checks.openai_api.ok` and `checks.openai_api.duration_ms`.
- OpenAI API returns 401 → `openai_api.ok = true` (reachable).
- OpenAI API times out → `openai_api.ok = false`.
- Aggregate: if `openai_api.ok = false` → overall status = 503.

### 11.2. Test strategy
- Unit tests for each classifier (`OpenAiErrorClassifier`), driver (`OpenAiReviewer`).
- Integration tests for provider-switching workflow (`WorkspaceControllerTest`).
- E2E tests for full pipeline with OpenAI (`ReviewPullRequestJobWithOpenAiTest`).
- Compliance tests for LGPD check #9 (`LgpdCheckCommandTest` case for check #9).
- Health endpoint tests updated (`HealthControllerTest`).

### 11.3. Example test cases

| Test | Scenario | Expected |
|---|---|---|
| `test_anthropic_provider_default` | Workspace with `llm_provider=null` | Factory returns `ClaudeReviewer` |
| `test_openai_provider_explicit` | Workspace with `llm_provider='openai'` | Factory returns `OpenAiReviewer` |
| `test_openai_429_retryable` | OpenAI returns 429 | `OpenAiErrorClassifier` → `RetryWithBackoff` |
| `test_openai_400_terminal` | OpenAI returns 400 `invalid_request_error` | `OpenAiErrorClassifier` → `Terminal` |
| `test_provider_switch_updates_cost_bucket` | Workspace switches from anthropic to openai mid-day | Anthropic consumed separate from OpenAI |
| `test_openai_dpa_gate_fails_without_env` | `OPENAI_DPA_URL` unset; workspace uses openai | `rabbit:lgpd-check` check #9 fails |
| `test_health_endpoint_includes_openai_api` | `GET /up` with OpenAI reachable | Response has `checks.openai_api.ok=true` |

---

## 12. Telemetry, Observability & Evaluation Metrics

### 12.1. Per-call telemetry
- `reviews_llm_calls` row records:
  - `provider` = 'anthropic' or 'openai'.
  - `model` = 'claude-sonnet-4-6' or 'gpt-4o'.
  - `prompt_tokens`, `completion_tokens` (both providers).
  - `cache_creation_input_tokens`, `cache_read_input_tokens` (Anthropic only; 0 for OpenAI).
  - `duration_ms`, `http_status`, `error_type`.

### 12.2. Cost analysis
- Per-workspace per-provider consumption available via `CostReservation::consumed($workspaceId, $provider)`.
- Daily breakdown: `reviews` rows aggregated by `workspace_id`, `provider`, `date`.
- Cost formula per provider (updated pricing):
  - Anthropic Sonnet 4.6: `prompt × $3/MTok + cache_creation × $3.75/MTok + cache_read × $0.30/MTok + output × $15/MTok`.
  - OpenAI GPT-4o: `prompt × $2.50/MTok + output × $10/MTok` (no caching).

### 12.3. Latency metrics
- Per-provider p95 latency (completion_time - start_time from telemetry).
- Anthropic likely slower on first call (cache miss); subsequent calls faster (cache hit).
- OpenAI consistent per call (no caching).

### 12.4. Alerts
- OpenAI rate-limit alerts: if `RetryWithBackoff` count exceeds threshold → notify operator.
- DPA URL missing on deploy → CI gate blocks (not a runtime alert).

---

## 13. Security, Privacy & Compliance

### 13.1. AuthN/AuthZ
- Only workspace admins can switch provider (existing CRUD authorization on workspace settings).

### 13.2. Data handling
- Both Anthropic and OpenAI calls receive redacted diffs (secret redaction happens pre-LLM, provider-agnostic).
- No diff persistence (same as Anthropic MVP).
- Telemetry records no actual diff content — only token counts.

### 13.3. Compliance
- **LGPD**: check #9 ensures OpenAI DPA URL is set before any workspace can use OpenAI provider.
- **Data processing agreements**: operator must have signed OpenAI DPA before setting `OPENAI_DPA_URL` env.
- **Audit**: `reviews_llm_calls.provider` column enables per-provider audit trails.

### 13.4. Rate limiting
- Anthropic: native rate limit handling via `AnthropicErrorClassifier`.
- OpenAI: native rate limit handling via `OpenAiErrorClassifier`.
- Both map to `RetryWithBackoff` → Horizon retry ladder (10/30/90s).

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Mitigation |
|---|---|
| Provider selection per-workspace creates operational complexity | Pattern is simple: single enum column, late binding in factory. No per-file or per-call overrides. |
| Different pricing per provider requires cost tracking | Separate per-provider daily ceiling and `consumed()` API makes it visible. Cost dashboard is backlog. |
| OpenAI has no prompt caching equivalent | Accepted: Anthropic gets cache benefits; OpenAI inherent latency. Both under 60s target. |
| Switching provider mid-day depletes old provider's budget | Acceptable: admin manually switches. Automatic fallback deferred. |
| DPA URL env var can be forgotten on deploy | Mitigated by check #9: CI fails if missing. Operator must set before deploying OpenAI workspace. |
| laravel/ai SDK differences between providers | Mitigated by wrapping in `LlmDriverInterface`. If SDK API changes, update both drivers behind interface. |

Open questions:
- Should we support per-workspace per-provider model overrides in a future phase? (Backlog: currently fixed to Sonnet 4.6 + GPT-4o.)
- Should OpenAI workspaces have a different default daily cap than Anthropic? (Backlog: currently same 200k token cap for both.)

---

## 15. Change Log

- **v1.0 (2026-05-15 / phase-openai)** — Initial multi-provider support. Added `workspaces.llm_provider` column (default 'anthropic'). Introduced `LlmDriverFactory` for late-binding resolution. Implemented `OpenAiReviewer` with GPT-4o model. `OpenAiErrorClassifier` maps errors to `RetryDecision`. Cost reservation Lua key now includes provider suffix (`workspace:{id}:tokens:{date}:{provider}`) for per-provider budgets. `CostReservationInterface` methods gain `string $provider` param. Telemetry schema: `reviews_llm_calls.provider` + `reviews_llm_calls.model` columns (nullable for backward compatibility). Health endpoint adds `openai_api` check. `rabbit:lgpd-check` adds check #9: `OPENAI_DPA_URL` env must be set when any workspace uses OpenAI. UI: workspace settings dropdown + review detail provider badge. AC27–AC31 all green. 345/345 tests passing.
