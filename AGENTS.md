# AGENTS.md — cdv-rabbit project specs for AI agents

> **Purpose.** If you are an AI agent (Claude Code, Codex, Cursor, etc.)
> joining this project mid-stream, read this file first. It captures
> the **current state** and tells you what to read next. The plan
> file describes the target; the git log describes the past;
> this file bridges them.

- **Last updated:** 2026-05-13 (Phase 1 / Week 1 COMPLETE — tag `phase-1-complete`)
- **Repo:** https://github.com/augusto-dmh/cdv-rabbit (private)
- **Branch:** `main` only (trunk-based, no PR workflow yet)
- **Test suite:** 69 tests, 210 assertions, all green on tag `phase-1-complete`

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
3. **`.omc/plans/cdv-rabbit-mvp-minimal.md`** — authoritative plan,
   v1.3, APPROVED WITH MINOR REVISIONS by two Critic passes. Read in
   full when planning or implementing. Especially:
   - §1 Requirements summary (10 locked decisions).
   - §3.0 Anthropic API integration spec (locked technical contract).
   - §3 Implementation Plan week-by-week.
   - §4 Acceptance criteria (26 testable items).
   - §10 File layout (anticipated).
4. **`git log --oneline`** — what's actually been delivered so far
   (commit messages reference plan sections + AC numbers).
5. **`.claude/skills/`** — domain skills loaded for this project
   (laravel-best-practices, pest-testing, inertia-vue-development,
   wayfinder-development, configuring-horizon, fortify-development,
   tailwindcss-development). Activate them when the work touches the
   matching domain.

---

## 3. Current state (Phase 1 / Week 1)

The plan runs 5 phases over 4–6 weeks:
- **W1** domain model + Horizon/Redis + tenancy primitives — ✅ **COMPLETE** (tag `phase-1-complete`)
- W2 Bitbucket integration layer — next, not started
- W3 review pipeline (Claude + caching/streaming/tool_use) — not started
- W4 Inertia UI — not started
- W5 hardening + LGPD + observability — not started
- W6 buffer / pilot — not started

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

### Next phase (W2) — Bitbucket integration layer

Per plan §3 Week 2, the next phase delivers:
- `app/Services/Bitbucket/BitbucketClient.php` (HTTP client wrapping
  BB Cloud REST v2 with retry/backoff, rate-limit honoring).
- `app/Http/Controllers/Bitbucket/WebhookController.php` (HMAC auth,
  idempotency via `webhook_deliveries.bitbucket_uuid`, dispatch).
- Workspace connect wizard pages (`resources/js/pages/workspaces/Connect.vue`).
- Repository discovery + webhook auto-registration on enable.

W2 work has not started. Workers from W1 are idle/shut down. The
next `/team` invocation will spin up a fresh team scoped to W2 tasks.

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
| **HMAC webhook auth** | Primary auth is `X-Hub-Signature` HMAC-SHA256 verification with `hash_equals`. URL token is defense-in-depth only. | Plan §3.0 + AC19 |
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
- GitHub / GitLab driver — v1.0+.
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

## 10. Update protocol for this file

`AGENTS.md` is updated by `team-lead` at each phase boundary
(W1 → W2, etc.) and after any locked-contract change. Workers do
not edit it.
