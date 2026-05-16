# AGENTS.md — cdv-rabbit project specs for AI agents

> **Purpose.** If you are an AI agent (Claude Code, Codex, Cursor, etc.)
> joining this project mid-stream, read this file first. It captures
> the **current state** and tells you what to read next. The plan
> file describes the target; the git log describes the past;
> this file bridges them.

- **Last updated:** 2026-05-16 (Phase 6 shipped — multi-SCM provider tag `phase-6-complete`)
- **Test suite:** 329 tests, 1029 assertions, all green on tag `phase-5-complete`
- **Repo:** https://github.com/augusto-dmh/cdv-rabbit (private)
- **Branch:** `main` only (trunk-based, no PR workflow yet)

---

## 1. What this project is

`cdv-rabbit` is an AI code review service for **Bitbucket Cloud**,
modeled on CodeRabbit, powered by **Anthropic Claude** (Sonnet 4.6
default, Haiku 4.5 triage, Opus 4.7 reserved). Built on Laravel 13 +
Inertia/Vue 3 + Fortify starter kit.

**Audience.** Internal Clube do Valor use first, architected for
multi-tenant SaaS later — every business table carries `workspace_id`
from migration 0001.

**Non-negotiable principles** (from plan §2.1):
1. LGPD-first — customer code never sits in our DB.
2. Tenancy boundary is enforced via a fail-closed global scope.
3. Fail visibly, fail cheaply (idempotent webhooks, capped LLM cost,
   structured per-review telemetry).
4. Reuse the starter kit (Fortify, Inertia, Wayfinder, shadcn-vue).
5. Bot output is untrusted text — sanitize before posting.

---

## 2. Read these in this order

1. **`CLAUDE.md`** (project root) — conventions: PHP 8.4 style, pint,
   Pest 4 tests per change, no docs unless asked, no inline comments,
   Laravel Boost MCP preference.
2. **This file (`AGENTS.md`)** — current state + reading order.
3. **`specs/*.md`** — feature-level specifications (15-section
   pattern from `specs/spec-artifacts-guidelines.md`, adapted from
   the `../nexus` project's `spec-artifacts-guidelines.md`). Read
   the spec for the feature you are about to touch BEFORE looking
   at the implementation. Existing specs cover:
   - `tenancy-and-workspace-isolation.md` (Phase 1)
   - `lgpd-data-protection-posture.md` (Phase 1 + 3)
   - `bitbucket-cloud-integration.md` (Phase 2)
   - `github-cloud-integration.md` (Phase 6)
   - `multi-scm-provider-support.md` (Phase 6)
   - `ai-code-review-pipeline.md` (Phase 3)
   - `cost-management-and-ceiling-alerts.md` (Phase 3)
   - `review-dashboard.md` (Phase 4)
   - `kill-switch-control.md` (Phase 4)
   - `observability-and-structured-logs.md` (Phase 5)
   - `health-and-readiness-checks.md` (Phase 5)
   - `lgpd-compliance-tooling.md` (Phase 5)
4. **`.omc/plans/cdv-rabbit-mvp-minimal.md`** — authoritative plan,
   v1.4, APPROVED WITH MINOR REVISIONS by two Critic passes. Read in
   full when planning or implementing. Especially:
   - §1 Requirements summary (10 locked decisions).
   - §3.0 Anthropic API integration spec (locked technical contract).
   - §3 Implementation Plan week-by-week.
   - §4 Acceptance criteria (26 testable items).
   - §10 File layout (anticipated).
5. **Git log** — what's actually been delivered. Commit messages
   reference plan sections + AC numbers + spec files.
6. **`.claude/skills/`** — domain skills loaded for this project
   (laravel-best-practices, pest-testing, inertia-vue-development,
   wayfinder-development, configuring-horizon, fortify-development,
   tailwindcss-development, ai-sdk-development, claude-api).
   Activate them when the work touches the matching domain.

---

## 3. Current state (Phase 1 / Week 1)

The plan runs 5 phases over 4–6 weeks:
- **W1** domain model + Horizon/Redis + tenancy primitives — ✅ **COMPLETE** (tag `phase-1-complete`)
- **W2** Bitbucket integration layer — ✅ **COMPLETE** (tag `phase-2-complete`)
- **W3** review pipeline (Claude via laravel/ai + caching/streaming/tool_use) — ✅ **COMPLETE** (tag `phase-3-complete`)
- **W4** reviews UI + kill switch UI — ✅ **COMPLETE** (tag `phase-4-complete`)
- **W5** hardening + LGPD + observability — ✅ **COMPLETE** (tag `phase-5-complete`)
- W6 buffer / pilot — operational gate (DPO sign-off, Anthropic budget, BB service account, prod infra)

🎉 **MVP CODE COMPLETE.** All 5 phases shipped, all 26 acceptance criteria covered, 329/1029 tests green. Remaining items before GA are operational sign-offs listed in plan §12.

### Phase 6 — Multi-SCM Provider Support — ✅ COMPLETE (tag `phase-6-complete`)

cdv-rabbit gained GitHub Cloud as a second SCM Provider alongside Bitbucket Cloud. Provider is chosen per Workspace at creation and immutable thereafter via `workspaces.scm_provider` enum. Both providers implement `ScmDriverInterface` (flat wide surface, normalized DTOs) and are resolved via `ScmDriverFactory::make(Workspace)`, mirroring the `LlmDriverFactory` precedent. GitHub auth is via GitHub App (not PAT) with per-Workspace `github_installation_id`. Webhook controllers are split per provider; the HMAC scheme is asymmetric (per-Workspace for BB, per-App for GH). Source of truth: `specs/multi-scm-provider-support.md`. Design decisions: `docs/adr/0001-strict-1-to-1-workspace-and-scm-owner.md` through `docs/adr/0004-scm-driver-interface-shape.md`. Glossary: `CONTEXT.md`. New ACs: AC27..AC38 — all covered by named Pest tests, verified by `tests/Feature/Scm/Phase6AcMatrixTest.php`.

#### W6 task graph + status (all complete)

| ID | Title | Commit |
|---|---|---|
| W6-T1 | Schema migrations + ScmProvider enum + model/factory updates | `b1038b6` |
| W6-T2 | ScmDriverInterface + 7 DTOs + ScmDriverFactory + exception | `905d24d` |
| W6-T3 | BitbucketClient → BitbucketDriver (DTOs); call sites rewired | `f09e8fb` |
| W6-T4 | GithubDriver + JwtSigner (RS256 native) + InstallationTokenCache | `9efd470` |
| W6-T5 | Github/WebhookController + WebhookIngestionPipeline + uninstall | `19d2df8` |
| W6-T6 | Scm/Github/InstallController (session-based) + AC27/AC28 | `a60debb` |
| W6-T7 | Phase 6 verifier — arch invariants + AC matrix scanner | `1630399` |

Phase 6 verifier: 397 tests, 1199 assertions, all green. The 113 Phase 2 BB tests still pass after the rename + DTO reshape.

**Production gating** (operational, NOT code): GitHub App "cdv-rabbit-bot" registered at github.com/settings/apps with `GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_SLUG` populated in env; GitHub DPA signed and `GITHUB_DPA_URL` populated (LGPD check #10).

### W1 task graph + status (all complete)

| ID | Title | Status | Commit |
|---|---|---|---|
| T1 | Infrastructure (Horizon, Redis, Postgres, env) | ✅ | `ff46c06` |
| T2 | 7 migrations (workspaces, repos, reviews, comments, webhooks, llm_calls) | ✅ | `d27f786` |
| T3 | WorkspaceContext singleton + fail-closed exception | ✅ | `74d8c04` |
| T4 | Models + BelongsToWorkspace + enums + factories | ✅ | `92c4d68` |
| T5 | BindWorkspaceMiddleware + WorkspaceAwareJob contract | ✅ | `c1cc4c5` |
| T6 | RedactingFailedJobProvider + RedactionApplied event | ✅ | `6856629` + fix `bb5a1e5` |
| T7 | Phase 1 Pest test suite (AC4, AC12, AC16, AC17, AC18) | ✅ | (this commit) |

Phase 1 verifier: 69 tests, 210 assertions, all green. Acceptance
criteria covered: AC4 (partial — diff never in failed_jobs), AC12
(cross-tenant isolation), AC16 (no diff in serialized job), AC17
(failed-job redaction), AC18 (fail-closed global scope).

Tag `phase-1-complete` applied. W2 is the next phase.

### What is on disk right now

- Tenancy primitives (`app/Concerns/WorkspaceContext.php`,
  `app/Concerns/BelongsToWorkspace.php`, exception class).
- 5 backed enums in `app/Enums/`.
- 6 Eloquent models in `app/Models/` (Workspace, Repository, Review,
  ReviewComment, WebhookDelivery, ReviewsLlmCall) with the correct
  casts and relationships.
- 6 factories in `database/factories/` with workspace-aware states.
- 7 migrations in `database/migrations/2026_05_13_00000{1..7}_*.php`.
- LGPD safety net: `app/Queue/RedactingFailedJobProvider.php` +
  `app/Events/RedactionApplied.php` registered as `queue.failer`.
- Horizon installed and configured with 3 supervisors (`default`,
  `reviews`, `bitbucket-api`) in `config/horizon.php`.
- `.env.example` carries Redis + Anthropic + Horizon env vars.

### W2 task graph + status (all complete)

| ID | Title | Commit |
|---|---|---|
| W2-T1 | BitbucketClient service (HTTP wrapper for BB Cloud REST v2) | `cfd4599` |
| W2-T2 | Webhook receiver — HMAC + idempotency + dispatch | `6b7f215` |
| W2-T3 | Routes + EnsureWorkspaceMember middleware | `03b2145` |
| W2-T4 | Workspace connect wizard backend | `7bdc0b8` |
| W2-T5 | Workspace connect wizard UI (Inertia/Vue) | `760ce2d` |
| W2-T6 | Repo sync + enable/disable + webhook lifecycle | `b2a4955` |
| W2-T7 | Phase 2 Pest test pass + end-to-end smoke | (this commit) |

Phase 2 verifier: 113 tests, 351 assertions, all green. Acceptance
criteria covered: AC2 (idempotency end-to-end), AC15 partial (`/up`
health endpoint returns 200), AC19 (HMAC primary auth on webhook).

Tag `phase-2-complete` applied.

### Next phase (W3) — Review pipeline (Claude via `laravel/ai`)

Per plan §3 Week 3 + §3.0 (Anthropic technical contract) + §3.0.10
(Laravel AI SDK integration strategy, v1.4 addition).

**Foundation:** `composer require laravel/ai` (official Laravel 13
package, https://laravel.com/docs/13.x/ai-sdk). Used as the primary
abstraction for agent/provider plumbing, streaming, model routing,
and the four token-usage fields. Drops to `providerOptions()` escape
hatch for the three §3.0 contracts the SDK does not expose with
first-class typed APIs:
1. Strict tool use (`tool_choice: {type:"tool", name:"review_result"}` +
   `strict: true` + `additionalProperties: false`) — passed via
   `providerOptions()` raw payload.
2. `request_id` + rate-limit headers — captured via a Guzzle
   middleware attached to the underlying transport.
3. Error taxonomy (429/529 retry, 400/401/413 terminal) — our
   `AnthropicErrorClassifier` wraps the SDK exceptions.

W3 delivers:
- `ReviewPullRequestJob::handle()` — real logic (currently a stub).
  Diff stays in a local variable, never persisted (AC16/AC17).
- `app/Services/Llm/{LlmDriverInterface, ClaudeReviewer}.php` with
  prompt caching (`cache_control: ephemeral`), strict tool use
  (`tool_choice: { type: "tool", name: "review_result" }`, `strict: true`),
  streaming helper, XML untrusted-content wrapping (§3.0.5).
- `app/Services/Llm/PromptBuilder.php` (XML wrapping per §3.0.5).
- `app/Services/Llm/LlmCallTelemetry.php` (persists `reviews_llm_calls`
  with 4 token-usage fields + request_id + rate-limit headers).
- `app/Services/Review/{SkipRules, SecretRedactor, CommentSanitizer,
  CommentPoster, DiffChunker, CostReservation}.php`.
- `app/Support/AnthropicErrorClassifier.php` (maps HTTP+type → retry
  policy per §3.0.7).
- Frozen artifacts: `config/cdv-rabbit/prompts/review_v1.txt` +
  `config/cdv-rabbit/schemas/review_result_v1.json` (AC23 enforces
  schema freeze via snapshot test).
- ACs added in W3: AC1, AC3, AC5, AC6, AC7, AC8, AC9, AC10, AC11,
  AC20, AC21, AC22, AC23, AC24, AC25, AC26.

W2 workers will be shut down before W3 team spins up.

---

## 3.6 Multi-provider notes

cdv-rabbit supports Anthropic Claude (default) and OpenAI GPT side-by-side. Provider is selected per workspace via `workspaces.llm_provider` (enum: `anthropic`|`openai`, default `anthropic`). Both providers implement the same `LlmDriverInterface`; the `LlmDriverFactory` resolves the correct driver at job execution time inside `ReviewPullRequestJob::handle()` after loading the workspace. OpenAI uses `response_format: json_schema` strict for structured output; Anthropic uses `tool_choice` strict. Cost reservation Lua keys are scoped per provider (`workspace:{id}:tokens:{date}:{provider}`), giving each provider an independent daily ceiling. `LlmCallTelemetry` records `provider` + `model` for every call. `rabbit:lgpd-check` check #9 gates on `OPENAI_DPA_URL` env being set whenever any workspace uses OpenAI. See `specs/ai-code-review-pipeline.md` §15 and `.omc/plans/openai-provider-support.md`.

---

## 4. Locked technical contracts (DO NOT CHANGE without bumping a version)

These are the contracts that future commits must honor. Full
rationale lives in plan §3.0.

| Contract | What is locked | Why |
|---|---|---|
| **No diff at rest** | Diff is fetched from Bitbucket API into a local variable inside `handle()`. Never `$this->diff`, never logged, never DB-persisted. | LGPD posture (plan §1, AC4, AC16, AC17) |
| **WorkspaceContext fail-closed** | The global scope throws `WorkspaceContextMissingException` when unbound. Never silently returns all rows. | Tenant isolation (AC18) |
| **Workspace tokens encrypted at rest** | `Workspace::$bitbucket_token` and `$webhook_secret` use the `encrypted:string` cast. | LGPD + secret handling |
| **Failed-job redaction** | `RedactingFailedJobProvider` strips keys in `[diff, patch, content, body, hunk, code, source]` from `failed_jobs.payload` before persistence. | Closes the v1.0 B1 BLOCKER |
| **Prompt caching on every Claude call** | `cache_control: { type: "ephemeral" }` on last tool def + last system block. System prompt + tool schema are frozen artifacts. Cache hit recorded as `cache_read_input_tokens` in `reviews_llm_calls`. | 90% read discount; ITPM bypass (plan §3.0.2) |
| **Strict tool use** | Anthropic `tool_choice: { type: "tool", name: "review_result" }` with `strict: true` and `additionalProperties: false`. Grammar-constrained sampling, no validation retries. | Schema conformance (plan §3.0.3, AC23) |
| **HMAC webhook auth (per-provider, asymmetric)** | Bitbucket: primary auth is `X-Hub-Signature` HMAC-SHA256 with `hash_equals` against the per-Workspace `webhook_secret`; URL token (`repositories.webhook_token`) is defense-in-depth. GitHub: primary auth is `X-Hub-Signature-256` HMAC-SHA256 against the per-App `GITHUB_APP_WEBHOOK_SECRET` env value; no URL token. Both controllers reject missing/mismatched signatures with 401 before any DB write or dispatch. | Plan §3.0 + AC19 + AC33 + AC34 + ADR 0003 |
| **Atomic cost ceiling** | Per-workspace daily token ceiling enforced via Redis Lua `INCRBY`. No TOCTOU race. | Closes v1.0 M1 |
| **Comment cap + AI label** | Max 25 inline comments per PR, every comment prefixed `🤖 cdv-rabbit (AI generated):`. Kill switch can halt dispatch within 10 s (AC8). | Plan §3 Week 3 + AC5/AC6/AC8 |

---

## 5. Conventions to honor (also in CLAUDE.md)

- **Tests per change.** Every commit ships with new or updated Pest
  tests. Run `php artisan test --compact --filter=<name>` after edits.
- **`vendor/bin/pint --dirty --format agent`** after every PHP edit
  before reporting work complete.
- **PHP 8.4 strict types + constructor promotion**. Curly braces
  always. PHPDoc over inline comments. TitleCase enum keys.
- **Laravel Boost MCP first.** Prefer `search-docs`, `database-schema`,
  `database-query` over raw bash equivalents.
- **No new top-level directories without approval.**
- **No dependency changes without approval** (Horizon + predis were
  pre-approved by the plan; anything else needs a sign-off).
- **No docs files** unless explicitly requested. This `AGENTS.md` is
  the documented exception.

---

## 6. Git workflow

- Trunk-based on `main`. No feature branches yet.
- 1 commit per completed task (T1, T2, …), conventional-commit style:
  `feat(domain):`, `feat(infra):`, `feat(db):`, `feat(tenancy):`,
  `feat(lgpd):`, `fix(queue):`, `chore:`.
- Workers do NOT commit — `team-lead` (orchestrator agent) commits per
  task with messages that reference the plan section and AC numbers.
- Identity for this repo is set locally:
  `augusto-dmh <130018859+augusto-dmh@users.noreply.github.com>`.
  Global identity is untouched.
- Every commit ends with the Claude co-author tag (collaborative
  authorship transparency).
- Phase tags applied at phase verifier exit: `phase-1-complete`,
  `phase-2-complete`, etc.

---

## 7. External sign-offs still pending (block production, not dev)

Per plan §12:
- DPO sign-off on the no-diff-at-rest LGPD posture.
- Anthropic API budget approval for the pilot (~200–400 USD/mo,
  refined after W3 telemetry; effective cost likely 40–60% lower
  once prompt caching kicks in).
- Dedicated Bitbucket service account on the CDV workspace with the
  documented scopes (`pullrequest:read/write`, `repository:read/write`,
  `webhook`, `account:read`).
- Production Redis + Postgres availability confirmed.

---

## 8. Things explicitly OUT of MVP (backlog)

If you find yourself implementing these, stop — they are backlog
items (plan §11):
- Incremental review on push (`pullrequest:updated` event) — v0.2.
- Bot commands (`@cdv-rabbit review/full/pause/...`) — v0.2.
- `.cdv-rabbit.yaml` repo-level config — v0.2.
- Learnings / memory system — v0.2.
- Haiku `harmlessness_screen` pre-screen — v0.2.
- Batch API integration (50% off) — v0.2.
- Response cache by `(sha256(file_content), prompt_version)` — v0.2.
- Stripe / billing — v0.3.
- Bitbucket OAuth Consumer self-service onboarding — v0.3.
- Slack/Teams/Discord notifications — v0.2.
- External tool orchestration (Semgrep, Gitleaks, ESLint) — v0.2.
- Bitbucket Data Center — v1.0+.
- GitHub Cloud driver — **promoted to Phase 6** (planned); see `specs/multi-scm-provider-support.md` and ADRs `docs/adr/0001..0004.md`.
- GitLab driver — v1.1+.
- GitHub Enterprise Server / Bitbucket Data Center drivers — v1.1+.
- IDE companion — v1.0+.
- Autofix (commit patches back) — v1.0+.
- Sequence diagram generation — v1.0+.

---

## 9. If you are about to write code

1. Identify which **task ID** (T1..T7 for W1) your change belongs to.
   If none, you are out of scope.
2. Read the plan section the task references.
3. Check the **locked contracts** in §4 above — do not violate.
4. Activate the matching skill (laravel-best-practices, pest-testing,
   etc.).
5. Use Laravel Boost MCP (`search-docs`, `database-schema`).
6. Write the code, write the tests, run pint, run tests.
7. Mark the task `completed` via `TaskUpdate`.
8. Message `team-lead` — do **not** commit yourself. team-lead owns
   git.

---

## 10. Update protocol — AGENTS.md AND specs

`AGENTS.md` is updated by `team-lead` at each phase boundary
(W1 → W2, etc.) and after any locked-contract change. Workers do
not edit it.

### Spec maintenance (CRITICAL — modeled on nexus's spec-driven workflow)

**Every implemented feature MUST have a corresponding `specs/*.md`
document following the 15-section pattern in
`specs/spec-artifacts-guidelines.md`.** This is non-negotiable. The
specs are the single source of truth that AI agents, new engineers,
and the DPO consult to understand what the system does, why, and
how it satisfies LGPD/AC contracts.

**When to update a spec:**
- A new feature lands → write a new spec following the 15-section
  template before merging the feature commits.
- An existing feature changes behaviour, contracts, or scope → bump
  the spec's version in §15 Change Log and update the affected
  sections inline. Do NOT delete the prior change-log entry.
- An AC is added/removed/renumbered → update §11 of the affected
  specs.
- A locked technical contract in this AGENTS.md §4 changes → update
  the relevant spec(s) and AGENTS.md §4 in the same commit.
- A pre-mortem scenario is added to the plan → cross-reference it
  in §14 Risks of the affected spec(s).

**Spec writing convention** (per `nexus-plan-feature` skill inspiration):
- Filename: kebab-case, descriptive (`feature-area.md`).
- 15 numbered sections in the exact order listed in
  `specs/spec-artifacts-guidelines.md`.
- Assumptions marked explicitly when filling gaps.
- §11 Validation lists every AC the feature covers and the test
  file(s) that prove it.
- §15 Change Log entries reference commit SHAs and phase tags.

**Where the spec is NOT the right artifact:**
- Operational runbooks (deploy steps, on-call playbooks) — separate.
- Plan-level cross-feature decisions — those live in
  `.omc/plans/cdv-rabbit-mvp-minimal.md`.
- Per-task implementation breakdowns — those live in the team task
  list during a phase and are discarded after the phase tags.
