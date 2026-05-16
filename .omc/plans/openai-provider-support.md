# Multi-Provider LLM Support — Add OpenAI Alongside Anthropic

**Version:** v0.3 (post-second-pass Critic verification)
**Owner:** augusto-dmh
**Target:** v0.2.0 (post-MVP)
**Status:** awaiting user approval — design decisions §3 need sign-off before execution

**v0.3 changes (vs v0.2):** Phase C task 4 call-site list corrected (6 → 8 sites); test file names fixed. See §12 change log.

---

## 1. Goal

Allow CDV-Rabbit to drive AI code reviews against **either Anthropic Claude OR OpenAI GPT**, selectable per workspace, without breaking any existing Anthropic-driven invariant (no-diff-at-rest, strict tool use, structured telemetry, cost ceiling).

Out of v0.2 scope (explicitly): runtime fail-over between providers, mixed-provider reviews (e.g. triage on GPT-mini + deep review on Claude), Azure OpenAI / OpenRouter, Bedrock.

---

## 2. RALPLAN-DR — Short

### 2.1 Principles
- **Driver parity, not feature parity.** Both providers MUST produce a `ReviewResultDto` with the same comment shape and 25-cap; provider-specific telemetry (prompt-cache breakdown, rate-limit headers) is nullable.
- **No-diff-at-rest holds across providers.** The local-var-only contract in `ReviewPullRequestJob::handle()` is provider-agnostic; both drivers must NOT persist diff, prompt, or response.
- **One concrete contract per provider, no compatibility shim.** Don't fake "Anthropic-shaped" responses out of OpenAI. Each driver owns its translation to `ReviewResultDto`.
- **Configuration at workspace boundary.** Workspace row picks the provider; env vars hold global API keys. No per-review override in v0.2.
- **Existing tests stay green.** Only new tests for the new driver; Anthropic suite untouched.

### 2.2 Decision Drivers (ranked)
1. **LGPD invariants survive unchanged** — neither provider lands diff content anywhere on disk or in any DB row.
2. **Cost predictability** — telemetry still computes USD/review on both providers; the daily cost ceiling (Lua INCRBY) keeps working.
3. **Operator ergonomics** — flipping a workspace from Anthropic to GPT is a single column update; no env redeploy.

### 2.3 Viable Options (provider selection layer)

| Option | Pros | Cons |
|---|---|---|
| **A. Per-workspace column** `workspaces.llm_provider` (Recommended) | Symmetric with `bitbucket_token`/`webhook_secret`; pilot one tenant on GPT while others stay on Claude; matches existing tenancy boundary | One migration; UI surface in Workspaces > Settings |
| B. Global env-only (`LLM_PROVIDER=anthropic`) | Zero migration; smallest diff | Can't A/B; can't accommodate "this client requires Anthropic DPA" while another wants GPT |
| C. Per-repository column | Maximum flexibility | Premature — pilot signals don't justify the extra surface; backlog |

**Recommendation: A.** Aligns with the rest of the multi-tenant model and unlocks the immediate pilot use case (different CDV clients with different DPAs).

### 2.4 Viable Options (structured-output strategy)

| Option | Pros | Cons |
|---|---|---|
| **A. Native per provider** — Anthropic strict tool_use, OpenAI `response_format: json_schema` strict (Recommended) | Both are first-class supported by providers' own SDKs; identical guarantee (model emits valid JSON) | Two code paths in the drivers; shared JSON schema needs to be valid for both (already is — pure JSON Schema) |
| B. Tool-call on both | OpenAI tool calls also strict-mode; symmetric | OpenAI's first-class structured-outputs is `response_format`, not tool_choice; using tool_choice on OpenAI loses native ergonomics |
| C. Free-form + post-validate | Trivial | Defeats AC23/AC25; one parse failure = whole review lost |

**Recommendation: A.** Keep Anthropic on `tool_choice + strict` (already proven), use OpenAI's `response_format: { type: "json_schema", json_schema: { strict: true, ... } }` — same effective contract, native to each provider.

### 2.5 Viable Options (cache/telemetry parity)

| Option | Pros | Cons |
|---|---|---|
| **A. Keep DTO Anthropic-shaped + OpenAI driver passes `0` for cache fields** (Recommended) | Minimal blast radius; DTO fields stay `int` (non-nullable) as today; OpenAI driver passes `0` for `cacheCreationInputTokens`/`cacheReadInputTokens` — same pattern `ReviewPullRequestJob::buildAggregatedResult()` already uses when aggregating chunks (job:460-461) | DTO has "Anthropic-flavored" field names lingering |
| B. Rename DTO to provider-neutral (`promptTokens`, `cachedPromptTokens`) + migrate columns | Cleanest long-term | Touches reviews_llm_calls migration, dashboard, telemetry queries, tests |

**Recommendation: A for v0.2** (ship fast), **B as a follow-up** when the GPT pilot proves out. Add `provider` + `model` columns to `reviews_llm_calls` immediately so we can disambiguate retroactively.

---

## 3. Design Decisions Needed Before Execution

| # | Decision | My recommendation |
|---|---|---|
| D1 | Provider selection scope | Per-workspace column (Option 2.3-A) |
| D2 | Default provider for new workspaces | Anthropic (preserves current behavior) |
| D3 | Default OpenAI model for review | `gpt-5-mini` for triage, `gpt-5` for review (mirror of haiku/sonnet tiering); user can override via env |
| D4 | Structured output on OpenAI | `response_format: json_schema` strict (Option 2.4-A) |
| D5 | OpenAI SDK | laravel/ai's OpenAI provider (`#[Provider(OpenAI)]`) — same surface we already use; no raw openai-php client |
| D6 | Cost ceiling Lua key namespace | Keep daily key per workspace, add provider suffix: `cost:reservation:{ws}:{date}:{provider}` (so a workspace switching providers mid-day gets independent ceilings) |
| D7 | Telemetry table | Add columns `provider` (enum: anthropic/openai), `model` (string); keep cache_* columns nullable |
| D8 | Prompt artifact reuse | Same `config/cdv-rabbit/prompts/review_v1.txt` + `schemas/review_result_v1.json` — already provider-neutral. Cache prefix size (1024 tok requirement) is Anthropic-only; OpenAI just ignores it |
| D9 | Encrypted env keys | `OPENAI_API_KEY` lives in env, NOT per-workspace (parity with current ANTHROPIC_API_KEY). Per-workspace keys = backlog |

---

## 4. ADR

**Decision:** Introduce a second driver implementing `LlmDriverInterface`, driven by a `workspaces.llm_provider` enum column, with global env-supplied API keys.

**Drivers:** D1 + D2 (above), the existing principle "configuration at workspace boundary."

**Alternatives considered:** B (env-only) — rejected, blocks A/B pilots. C (per-repo) — rejected, premature.

**Why chosen:** Per-workspace selection matches the existing tenancy model, supports the pilot scenario where two CDV clients sign different DPAs, and requires one small migration. The driver interface already abstracts the call — the change is plumbing, not architecture.

**Consequences:**
- `reviews_llm_calls` gains 2 columns; `workspaces` gains 1 column + 1 cast.
- A new factory binding picks the driver from `Workspace::llm_provider`.
- `rabbit:lgpd-check` gains 2 new gates: `OPENAI_DPA_URL` env populated (if any workspace uses OpenAI), and `Workspace::$casts` keeps tokens encrypted (already covered).
- Dashboard shows `provider` + `model` chips on review Show page.

**Follow-ups (v0.3+):**
- DTO field rename (provider-neutral).
- Azure OpenAI / Bedrock as additional drivers.
- Per-workspace OpenAI keys (current env-only OK for pilot).
- Runtime fail-over policy when one provider degrades.

---

## 5. Implementation Steps

### 5.1 Phase A — schema + config (1 task)
1. **Migration 1**: `workspaces.llm_provider` enum (`anthropic`|`openai`) default `anthropic` NOT NULL.
2. **Migration 2**: `reviews_llm_calls.provider` (string nullable for legacy rows), `reviews_llm_calls.model` (string nullable).
3. Update `Workspace` fillable + `casts` (no encryption — enum value, not a secret).
4. Update `ReviewsLlmCall` fillable.
5. `config/ai.php` — confirm laravel/ai's OpenAI provider is wired; add `openai` connection with `api_key` from env.
6. `config/cdv-rabbit.php` — add `openai_dpa_url` env var hook, mirroring `anthropic_dpa_url`.

### 5.2 Phase B — driver + shared services (4 tasks)
1. **Extract prompt/schema loader** (NEW — addresses Critic Critical #1).
   - Today `ReviewPullRequestJob:221-224` calls `app(ClaudeReviewer::class)->getSystemPrompt()` / `getToolSchema()` directly, bypassing the interface. This silently forces every workspace through Claude's prompt loading even if the resolved driver is OpenAI.
   - Add `getSystemPrompt(): string` and `getToolSchema(): array` to `LlmDriverInterface`.
   - Move concrete implementations into `ClaudeReviewer` (already there) AND `OpenAiReviewer`.
   - Update `ReviewPullRequestJob:220-224` to call `$llm->getSystemPrompt()` / `$llm->getToolSchema()` via the injected driver — remove the `app(ClaudeReviewer::class)` line + the stale comment on line 220.
2. **`app/Services/Llm/OpenAiReviewer.php`** implementing the (now expanded) `LlmDriverInterface`:
   - Uses laravel/ai with a new `app/Ai/Agents/OpenAiReviewAgent.php` carrying `#[Provider(Lab::OpenAI)] #[Model('gpt-5')] #[MaxTokens(4096)]` (mirror of `ReviewAgent`).
   - `providerOptions()` returns `['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'review_result', 'schema' => $schema, 'strict' => true]]]`.
   - Translates the SDK response into `ReviewResultDto` (request_id from response headers, token counts from usage block, `cacheCreationInputTokens` + `cacheReadInputTokens` = `0` — DTO fields are non-nullable `int`).
3. **`app/Support/OpenAiErrorClassifier.php`** + **`OpenAiHeaderBag.php`** — siblings of the Anthropic versions, mapping OpenAI error codes (`rate_limit_exceeded`, `insufficient_quota`, `context_length_exceeded`, `server_error`) onto the shared `RetryDecision` enum.
4. **`app/Services/Llm/LlmDriverFactory.php`** (NEW — addresses Critic Critical #2).
   - Single method: `make(Workspace $workspace): LlmDriverInterface`.
   - Reads `$workspace->llm_provider` and returns the appropriate concrete: `app(ClaudeReviewer::class)` or `app(OpenAiReviewer::class)`.
   - Reason: Laravel's contextual binding resolves at **constructor time**, but `ReviewPullRequestJob` only knows the workspace inside `handle()` (line 69: `Workspace::find($this->workspaceId)`). The container never sees `$this->workspace->llm_provider`. A factory called explicitly from `handle()` is the canonical pattern.

### 5.3 Phase C — wire-up (4 tasks)
1. **`app/Providers/AppServiceProvider.php`** — keep `LlmDriverInterface::class => ClaudeReviewer::class` as the default bind (so other consumers — tests, future tinker scripts — still resolve). Register `LlmDriverFactory` as a singleton.
2. **`ReviewPullRequestJob`** — remove the `LlmDriverInterface $llm` constructor parameter; instead, after loading the workspace in `handle()`, call `$llm = app(LlmDriverFactory::class)->make($workspace)`. Pass that local `$llm` through to telemetry + retries.
3. **`LlmCallTelemetry::record()`** — extend signature with `string $provider` (and keep the existing `string $modelId` already there). Update the `ReviewsLlmCall::create()` array to include `provider`. Update the call site in `ReviewPullRequestJob:235` to pass `$workspace->llm_provider`.
4. **`CostReservation` + `CostReservationInterface`** — extend `reserve(int $workspaceId, int $tokens, int $dailyCap)` → `reserve(int $workspaceId, string $provider, int $tokens, int $dailyCap)`. Same on `consumed()`, `release()`. Update the Lua key from `workspace:{id}:tokens:{date}` to `workspace:{id}:tokens:{date}:{provider}` per D6. Update **8 call sites in `ReviewPullRequestJob`** that pass `$this->workspaceId`:
   - Line 114 — `reserve()`
   - Lines 145, 175, 186, 208, 275, 335 — `release()` (six sites; 186 = empty-diff early return, 335 = inside `handleLlmException()`)
   - Line 262 — `consumed()`
   
   Lines 113 (`dailyCapFor($workspace)`) and 131 (`notifyIfThresholdExceeded($workspace, ...)`) take a `Workspace` object directly and do NOT gain a `$provider` parameter in v0.2 — they remain per-workspace (consistent with the cost-cap-UX-gap acknowledgement: a workspace's `daily_token_cap` applies independently to each provider bucket).
   
   Test doubles: update `tests/Fakes/FakeCostReservation.php` (implements `CostReservationInterface`, so PHP fatal-errors at test boot until updated) and the assertions in `tests/Feature/Review/CostReservationTest.php`.

> **Cost-cap UX gap (Critic Major):** with per-provider buckets, today's single `workspaces.daily_token_cap` column applies to BOTH buckets independently — a workspace effectively gets 2× the cap if it uses both providers in one day. Acceptable for v0.2 (Anthropic is default; cross-day provider switches are rare). v0.3 backlog: add `daily_token_cap_openai` column OR convert to a cents-based budget shared across providers.

### 5.4 Phase D — UI (1 task)
1. **`resources/js/pages/workspaces/Connect.vue`** or new **`Settings.vue`** — dropdown to flip `llm_provider` (admin-only). Wayfinder typed action.
2. **`resources/js/pages/reviews/Show.vue`** — render `provider` + `model` as a small badge near the cost figure.
3. **`WorkspaceController::update`** — accept the new field in its FormRequest, scoped to admins.

### 5.5 Phase E — compliance + ops (1 task)
1. **`LgpdCheckCommand`** — add check #9: if any workspace has `llm_provider = openai`, `config('cdv-rabbit.openai_dpa_url')` must be non-empty. Same red/green semantics.
2. **`HealthController`** — extend the `anthropic_api` check to also probe OpenAI base URL when any workspace uses it (or just always-probe both, simpler).
3. **`AGENTS.md`** — append §3.6 "Multi-provider notes" (single paragraph + link to this plan).
4. **`specs/ai-code-review-pipeline.md`** — append v1.1 change-log entry; bump the §5 contract to mention both providers and the provider-selection rule.

### 5.6 Phase F — tests (1 task)
1. **`tests/Feature/Services/Llm/OpenAiReviewerTest.php`** — 6 cases mirroring the Anthropic suite: happy path, malformed JSON refusal (shouldn't happen with strict), rate-limit retry, context-length error, partial-tool-use, secrets-redactor-runs-before-call.
2. **`tests/Feature/Jobs/ReviewPullRequestJobWithOpenAiTest.php`** — 3 cases: workspace flagged openai dispatches OpenAiReviewer; switching mid-day uses correct cost-reservation key; provider+model land in the telemetry row.
3. **`tests/Feature/Workspaces/LlmProviderSwitchTest.php`** — 2 cases: admin can flip; non-admin gets 403.
4. **`tests/Feature/Console/LgpdCheckCommandTest.php`** — extend with 2 new cases for check #9.
5. **`tests/AcCoverage.md`** — add AC27 (multi-provider) + AC28 (openai DPA gate); update the index.

---

## 6. Acceptance Criteria

| AC | Statement | Test |
|---|---|---|
| AC27 | A workspace with `llm_provider=openai` and a valid PR triggers a successful review whose comments land on the BB PR; the diff appears in NO database column, log line, or file. | `ReviewPullRequestJobWithOpenAiTest::openai_path_emits_no_diff` |
| AC28 | `rabbit:lgpd-check` returns exit 1 when at least one workspace uses OpenAI and `OPENAI_DPA_URL` env is empty; exit 0 once populated. | `LgpdCheckCommandTest::openai_dpa_gate_*` |
| AC29 | Switching `llm_provider` on a workspace from anthropic to openai mid-day does NOT bypass the existing-day cost ceiling — both providers count toward separate buckets and both can independently exhaust. | `CostReservationProviderIsolationTest` |
| AC30 | Telemetry row records `provider` + `model` for every LLM call after this change ships; legacy rows pre-migration leave them NULL. | `ReviewsLlmCallSchemaTest` |
| AC31 | Anthropic suite remains 329 tests green — no regression. | `php artisan test --compact` |

---

## 7. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| OpenAI structured-output strict mode rejects our review-result JSON schema (uses anyOf/nullable patterns Anthropic accepts but OpenAI doesn't) | Medium | Block ship | Phase A first task: feed the existing `review_result_v1.json` to OpenAI's schema validator; bump to `review_result_v2.json` with adjustments if needed (don't mutate v1 — Anthropic still uses it). |
| Cost calculator hardcoded to Anthropic pricing | High (we know it is) | Wrong USD figures for GPT | Add a `Pricing` value object keyed on `{provider, model}`; populate from `config/cdv-rabbit/pricing.php` (operator-editable). Anthropic kept as-is. |
| laravel/ai's OpenAI provider doesn't expose `response_format` first-class | Low (it's a documented OpenAI feature; SDK has provider options escape hatch) | Driver code gets a workaround like the one we used for strict tool_choice | Already the pattern (`HasProviderOptions` trait); replicate. |
| OpenAI returns extra fields the schema doesn't list and strict mode rejects | Low | Review fails | Strict mode prevents this server-side; if it does happen, the driver maps to `RetryDecision::ABORT` like Anthropic schema violations. |
| Workspace admin flips provider mid-incident expecting fail-over | Medium | Confusion — provider switch doesn't migrate in-flight reviews | Document explicitly in the UI dropdown: "Applies to NEW reviews only. In-flight reviews complete on previous provider." |

---

## 8. Pre-mortem (3 scenarios)

1. **OpenAI rate-limits us out of nowhere during a busy hour.** Without fail-over (out of scope), all GPT-workspace reviews go `Skipped` with `error_class=rate_limit`. Mitigation: documented; operators flip workspace back to Anthropic until OpenAI recovers. Add a §8 backlog note for v0.3 fail-over policy.

2. **OpenAI's GPT-5 hallucinates a finding about a "leaked api_key" that's actually a public Stripe test key in a fixtures file.** Pre-existing risk (Claude does this too); already mitigated by AC6 marker + AC5 25-cap + kill switch. The provider switch doesn't change this surface.

3. **Cost forecasting breaks because GPT-5 input/output ratio differs from Sonnet's, and our daily ceiling (priced for Sonnet) under-shoots for GPT.** Mitigation: the Lua ceiling is in raw tokens, not dollars. Operators tuning the ceiling already do it per workspace; the per-provider Lua-key suffix (D6) means anthropic-tuned ceilings don't accidentally apply to OpenAI buckets.

---

## 9. Test Plan (Expanded)

- **Unit:** `OpenAiErrorClassifier` truth-table (12 codes × 4 retry decisions = 48 cases driven by a dataset).
- **Integration:** `OpenAiReviewerTest` with mocked HTTP responses for the 6 happy/error paths.
- **E2E:** `ReviewPullRequestJobWithOpenAiTest` dispatches the full job with a workspace flagged openai; asserts comments land on a faked BB PR and zero diff content appears in any persisted artifact.
- **Observability:** assert structured log line for an openai review includes `provider="openai"` and the proper `model` value; cache_* fields are absent (or null), not lying about zero.

---

## 10. Estimate

| Phase | Tasks | Est. effort |
|---|---|---|
| A — schema + config | 1 | 30 min |
| B — driver + shared services | 4 | 3 h |
| C — wire-up | 4 | 2 h |
| D — UI | 1 | 1 h |
| E — compliance + ops | 1 | 30 min |
| F — tests | 1 | 2 h (added cases for factory + per-provider cost) |
| **Total** | **12 tasks** | **~9 h** |

Single-worker estimate; with `/team 2:executor` likely ~5 h wall clock. The growth vs v0.1 reflects Critic-mandated additions (factory, telemetry signature, cost reservation signature, prompt loader extraction).

---

## 11. Out of Scope (defer to v0.3+)

- Mixed-provider reviews (e.g. GPT-mini triage + Claude deep review).
- Per-workspace API keys (env-only for now).
- Runtime fail-over / circuit breaker between providers.
- Azure OpenAI, OpenRouter, Bedrock as additional drivers.
- DTO field rename (Anthropic-flavored names linger — fine for pilot).
- Per-repository provider override.
- UI cost forecast that knows about provider pricing differentials.

---

## 12. Change Log

- **v0.3 (2026-05-14)** — Post-second-pass Critic verification. Critic confirmed Critical #1, Critical #2, Major #3, Major #4 fully closed. Major #5 was partially closed: revision missed 2 `release()` call sites. Fixed:
  - Phase C task 4 — call-site enumeration expanded from 6 to 8 (added 186 + 335); lines 113 + 131 explicitly excluded from `$provider` param (they operate on `Workspace`, not `workspaceId`).
  - Test file names corrected: `tests/Fakes/FakeCostReservation.php` (was `CostReservationFake`), `tests/Feature/Review/CostReservationTest.php` (was `CostReservationLuaTest`).
  - All 5 v0.1 Critic findings now verified closed against the real codebase.
- **v0.2 (2026-05-14)** — Post-Critic revision. Fixed:
  - **Critical #1** (`ReviewPullRequestJob` hardcodes `app(ClaudeReviewer::class)` at line 221-224): Phase B task 1 now extracts `getSystemPrompt()` + `getToolSchema()` into the `LlmDriverInterface` itself; job uses the injected `$llm` instead.
  - **Critical #2** (contextual binding impossible — workspace not available at constructor time): Phase B task 4 introduces `LlmDriverFactory::make(Workspace)`, called from inside `handle()` after workspace load. Phase C task 1-2 wires it.
  - **Major #3** (DTO field nullability mismatch): §2.5-A reworded — OpenAI driver passes `0` for cache fields, matching the existing `buildAggregatedResult()` pattern at job:460-461. No DTO type changes.
  - **Major #4** (`LlmCallTelemetry::record()` lacks `$provider` parameter): Phase C task 3 explicitly adds it + updates the call site at job:235.
  - **Major #5** (D6 cost-key suffix needs interface + 6 call-site changes): Phase C task 4 lists all 6 call sites (114/145/175/209/262/275) + `CostReservationInterface` signature change + Lua key update.
  - Cost-cap UX gap (Critic Minor): documented as v0.3 backlog inside Phase C.
  - Task count: 7 → 12. Effort: ~6.5 h → ~9 h.
- **v0.1 (2026-05-14)** — Initial draft. Critic returned `REVISE` with 2 critical + 3 major findings (see Critic transcript).
