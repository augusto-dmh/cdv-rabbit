# LGPD Compliance Tooling

## 1. Overview

### 1.1. Feature Name
**LGPD Compliance Tooling — `rabbit:purge-stale` + `rabbit:lgpd-check`**

### 1.2. One-sentence summary
Two artisan commands harden the LGPD posture established in Phase 1: `rabbit:purge-stale` enforces a retention policy (soft-delete reviews >365 days, hard-delete after a 30-day grace, hard-delete webhook receipts >90 days) on a daily schedule; `rabbit:lgpd-check` is a CI-runnable deploy gate that fails the deploy until 9 structural + operator-managed posture checks all pass (including DPO sign-off, Anthropic DPA URL, **and OpenAI DPA URL when any workspace uses OpenAI**).

### 1.3. Primary outcome
A fresh deploy that lacks `ANTHROPIC_DPA_URL` env var OR `storage/app/dpo-signoff.json` returns exit 1 from `php artisan rabbit:lgpd-check` and the CI step blocks the deploy. Once both are set, the command exits 0 and the deploy proceeds. Separately, a Review row older than 395 days no longer exists in any table after the nightly purge runs.

### 1.4. Version & owner
`v1.0 – Phase 5 / Week 5 (tag phase-5-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
LGPD compliance is operational, not theoretical. Phase 1 established the "no diff at rest" posture and encrypted credentials; Phase 5 enforces retention windows + automates the deploy-time DPO/DPA sign-off check.

### 2.2. Problem statement
Without retention enforcement, "no diff at rest" silently degrades over time as metadata accumulates indefinitely. Without a deploy-time gate, a regression that introduces a forbidden column or unbinds the redacting failed-job provider could ship to production unnoticed.

### 2.3. Goals
- **G1**: Daily scheduled purge removes stale reviews + cascading children + old webhook deliveries.
- **G2**: Configurable retention thresholds via env vars (no code change to extend retention).
- **G3**: `rabbit:lgpd-check` runs 9 structural + operator-managed checks; binary exit 0/1.
- **G4**: Each check has a positive + negative test for regression coverage.
- **G5**: CI-friendly output — colorized table for humans, exit code for automation.

### 2.4. Non-goals / out of scope
- Cross-workspace data export (e.g., GDPR Article 20 portability) — backlog.
- User-initiated deletion requests — backlog.
- Workspace-level retention overrides — global config only in MVP.
- Audit log persistence for purge runs (only log channel emission today).

### 2.5. Dependencies
- `workspaces.kill_switch_enabled` + `reviews.deleted_at` columns.
- `SoftDeletes` trait on Review/ReviewComment/ReviewsLlmCall.
- Laravel Schedule registry.
- `BelongsToWorkspace::withoutWorkspaceScope()` for cross-workspace operations.

---

## 3. Scope

### 3.1. In-scope functionality
- Migration adding `deleted_at` to reviews, review_comments, reviews_llm_calls, webhook_deliveries.
- `app/Console/Commands/PurgeStaleReviews.php` — signature `rabbit:purge-stale {--dry-run}`.
- `app/Console/Commands/LgpdCheckCommand.php` — signature `rabbit:lgpd-check`.
- `config/cdv-rabbit.php` `retention` section + `anthropic_dpa_url` + `dpo_signoff_path` keys.
- `routes/console.php` schedule registration.
- Feature tests for both commands.

### 3.2. Out-of-scope for this iteration
- Workspace-self-service "delete all my data" button.
- Per-customer retention agreements.
- Multi-region data residency (single Brazilian region in MVP).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **DPO** — runs `rabbit:lgpd-check` periodically; signs `storage/app/dpo-signoff.json` annually.
- **CI/CD pipeline** — runs `rabbit:lgpd-check` on every deploy; fails the deploy on red.
- **Operator** — runs `rabbit:purge-stale --dry-run` to preview deletions before the nightly schedule fires.
- **Workspace admin** — passive beneficiary; trusts the system to enforce retention.

### 4.2. Primary use cases
- As a DPO, I want a one-command audit of the LGPD posture so I can sign off without reading code.
- As CI, I want a binary exit code so I can `set -e` the deploy.
- As an operator, I want `--dry-run` so I can confirm what tomorrow's purge will delete.
- As a workspace admin, I want the audit + retention to be invisible — I never have to think about it.

### 4.3. Flow (purge)
Schedule fires daily at 03:00 → `PurgeStaleReviews::handle()` → phase 1 (soft-delete >365d reviews + cascade) → phase 2 (hard-delete soft-deleted >395d + cascade) → phase 3 (hard-delete webhook_deliveries >90d) → emit structured log line per phase.

### 4.4. Flow (lgpd-check)
CI step → `php artisan rabbit:lgpd-check` → 8 checks run → table printed → exit 0 if all green, exit 1 otherwise → CI deploys or aborts.

---

## 5. Functional Requirements

### 5.1. `rabbit:purge-stale`

**FR-01 — Phase 1: soft-delete reviews**
- Trigger: command invocation.
- Behaviour: `Review::withoutWorkspaceScope()->where('created_at', '<', now()->subDays($softDays))->whereNull('deleted_at')->delete()`. Cascade to review_comments + reviews_llm_calls via explicit queries.
- Output: count of soft-deleted rows logged.

**FR-02 — Phase 2: hard-delete soft-deleted**
- Trigger: same invocation.
- Behaviour: `Review::onlyTrashed()->where('deleted_at', '<', now()->subDays($graceDays))->forceDelete()`. Cascade children.
- Output: count of hard-deleted rows logged.

**FR-03 — Phase 3: webhook_deliveries**
- Trigger: same invocation.
- Behaviour: `WebhookDelivery::where('created_at', '<', now()->subDays($webhookDays))->delete()` (no soft-delete — receipts are not user data).
- Output: count logged.

**FR-04 — `--dry-run` flag**
- Inputs: `--dry-run` boolean.
- Behaviour: ALL three phases run the SELECT counts but NO mutating queries. Printed plan with what WOULD be deleted.

### 5.2. `rabbit:lgpd-check`

**FR-05 — Nine checks**
1. **Schema audit** — no forbidden column name on (reviews, review_comments, reviews_llm_calls, webhook_deliveries).
2. **Encryption casts** — Workspace::$casts has bitbucket_token + webhook_secret as `encrypted:string`.
3. **Failed-jobs provider** — `queue.failer` resolves to `RedactingFailedJobProvider`.
4. **Redacting processor wired** — `cdv-rabbit-reviews` channel has `ReviewsChannelTap`.
5. **Secret redactor sanity** — fixture secret returns `<<SECRET_REDACTED>>`.
6. **Retention scheduled** — Schedule registry contains `rabbit:purge-stale` daily.
7. **Anthropic DPA env** — `config('cdv-rabbit.anthropic_dpa_url')` non-empty.
8. **DPO sign-off** — `storage/app/dpo-signoff.json` exists + valid JSON + `signed_at` within 12 months.
9. **OpenAI DPA env (conditional)** — if any `workspaces.llm_provider = 'openai'` exists, `config('cdv-rabbit.openai_dpa_url')` must be non-empty.

**FR-06 — Output**
- Colorized table (green ✓ / red ✗) with check name + ok + reason.
- Exit 0 if all ok, exit 1 if any not ok.

### 5.3. Error and edge cases
- DPO sign-off file missing → red with "File not found: ..."
- DPO sign-off file present but `signed_at` > 12 months → red with "Sign-off stale: signed YYYY-MM-DD".
- Anthropic DPA URL empty/null → red with "Env var ANTHROPIC_DPA_URL not set".
- Schedule registry empty (Schedule not loaded yet) → red.
- Custom queue.failer binding (someone re-bound it) → red.

---

## 6. Non-Functional Requirements

- **Idempotency**: `rabbit:purge-stale` is safe to run multiple times per day (later runs find nothing).
- **Performance**: full purge across a few thousand rows completes in seconds. `lgpd-check` < 100ms total.
- **Observability**: every purge phase emits one structured log line; `lgpd-check` writes nothing — only stdout.

---

## 7. Data Model & Contracts

### 7.1. New columns (W5-T3 migration)
- `reviews.deleted_at`, `review_comments.deleted_at`, `reviews_llm_calls.deleted_at`, `webhook_deliveries.deleted_at` — all nullable timestamp.

### 7.2. Config (W5-T3 + W5-T4)
```php
'retention' => [
    'soft_delete_days' => env('RABBIT_RETENTION_SOFT_DELETE_DAYS', 365),
    'hard_delete_grace_days' => env('RABBIT_RETENTION_HARD_DELETE_GRACE_DAYS', 30),
    'webhook_delivery_days' => env('RABBIT_RETENTION_WEBHOOK_DELIVERY_DAYS', 90),
],
'anthropic_dpa_url' => env('ANTHROPIC_DPA_URL'),
'openai_dpa_url' => env('OPENAI_DPA_URL'),
'dpo_signoff_path' => storage_path('app/dpo-signoff.json'),
```

### 7.3. `dpo-signoff.json` schema
```json
{
  "signer": "string (DPO name)",
  "signed_at": "ISO 8601 date",
  "scope": "string (description of what was signed)"
}
```

---

## 8. AI/Agent Design

Not applicable.

---

## 9. UX & Interaction Design

- CLI only. No UI in MVP.
- `lgpd-check` table is human-friendly (colorized).
- Future UI surface: a workspace-settings page showing "DPO sign-off: 2026-04-12 (signed by ...)" — backlog.

---

## 10. System Architecture & Integration

### 10.1. Schedule registration
- `routes/console.php`: `Schedule::command('rabbit:purge-stale')->daily()->at('03:00')->withoutOverlapping()`.

### 10.2. Integration touch points
- `tenancy-and-workspace-isolation.md` — purge uses `withoutWorkspaceScope()` (escape hatch documented).
- `lgpd-data-protection-posture.md` — this feature operationalizes the policy that file describes.
- `observability-and-structured-logs.md` — purge phases emit structured log lines.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Phase 5 acceptance:
- **AC13** — `php artisan rabbit:lgpd-check` returns exit 0 with all gates green; exit 1 with any red. Tested in `LgpdCheckCommandTest` (17 cases) + `Phase5HardeningSmokeTest` (case 4).

Supplementary:
- `tests/Feature/Console/PurgeStaleReviewsCommandTest.php` — 6 cases covering each phase + cascade + dry-run + multi-workspace.
- `tests/Feature/Console/LgpdCheckCommandTest.php` — 17 cases (8 checks × ~2 cases each + aggregate green + aggregate one-red).

---

## 12. Telemetry, Observability & Evaluation Metrics

- Purge runs emit per-phase log lines: `{kind: 'soft_delete', count: 12, run_id: ...}`.
- `lgpd-check` writes nothing to logs (intentional — output is for human/CI eyes).
- Run frequency: schedule fires daily at 03:00 UTC.

---

## 13. Security, Privacy & Compliance

- The purge command is destructive — `--dry-run` is the safety net for operators.
- DPO sign-off file lives in `storage/app/` which is gitignored by default — never committed.
- `ANTHROPIC_DPA_URL` is operator-managed env var; cdv-rabbit doesn't validate the URL itself, only that it's non-empty.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Operator forgets to populate DPO sign-off → deploy fails | Intentional — that's the gate's purpose. Document in deploy runbook. |
| Retention thresholds too aggressive for a regulated client | Configurable via env; per-workspace override is backlog. |
| `lgpd-check` becomes outdated (more LGPD checks emerge) | Easy to extend — add a check method + 2 tests; pattern is established. |
| Multi-region data residency not addressed | Out of scope for MVP (Brazil-only). Backlog. |

Open: should the purge run also emit a "purge_run_complete" event for downstream notification systems? (Backlog.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-5-complete)** — Initial implementation. Commits: `786d9d8` (W5-T3 purge command + schedule + migration), `7022192` (W5-T4 lgpd-check command), Phase 5 verifier commit (T5 smoke case 4 covering both commands). Tests: PurgeStaleReviewsCommandTest (6 cases) + LgpdCheckCommandTest (17 cases) + Phase5HardeningSmokeTest cases 3-4.
- **v1.1 (2026-05-15 / phase-openai)** — Added check #9: OpenAI DPA URL gate. `OPENAI_DPA_URL` must be set when any workspace uses OpenAI. Config key `openai_dpa_url` added. Tests: LgpdCheckCommandTest updated (check 9 pass/fail/no-workspace cases).
