# Cost Management & Daily Ceiling Alerts

## 1. Overview

### 1.1. Feature Name
**Cost Management — Atomic Daily Token Reservation + Threshold Alerts**

### 1.2. One-sentence summary
Every workspace has a daily Anthropic-token budget enforced via a Redis Lua atomic check-and-INCRBY (race-free even under concurrent jobs), with a configurable threshold percentage that triggers an email to workspace admins before the budget is exhausted.

### 1.3. Primary outcome
Two concurrent `ReviewPullRequestJob` invocations for the same workspace cannot both succeed in reserving more than the daily cap allows together; one succeeds, one is denied with `error_class=cost_ceiling`. A workspace whose consumption crosses 70% of its cap receives a single alert email per day.

### 1.4. Version & owner
`v1.0 – Phase 3 / Week 3 (tag phase-3-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
LLM costs scale with input/output tokens. Without a hard ceiling, a single pathological PR (generated code, monorepo migration) or a hostile actor (replay webhook with forged HMAC) could rack up enormous Anthropic bills. This feature is the financial blast-radius limiter.

### 2.2. Problem statement
A naive per-workspace daily counter check would have a TOCTOU race: two concurrent jobs read the same value, both decide they fit under the cap, both proceed, both record their cost — total exceeds the cap. The fix is to make check-and-record a single atomic operation.

### 2.3. Goals
- **G1**: Per-workspace daily token cap enforced atomically (no race condition).
- **G2**: Configurable threshold (default 70%) triggers a single alert email per workspace per day.
- **G3**: Cap is per UTC day; key rolls over at midnight UTC with 26-hour TTL (timezone slack).
- **G4**: Reservation API supports release (refund on failed jobs).
- **G5**: Mailable scaffolding exists; full SMTP wiring is operational (deferred to W5).

### 2.4. Non-goals / out of scope
- Hourly or per-PR ceiling (per-PR is implicit via `max_tokens=4096` + max-LLM-calls; hourly not modeled).
- Billing / Stripe integration (v0.3 SaaS).
- Cost forecasting UI (deferred).
- Multi-currency display (always USD cents in MVP).
- Cross-workspace pooled budgets (not in MVP).

---

## 3. Scope

### 3.1. In-scope functionality
- `resources/lua/cost_reservation_check_incr.lua` — atomic check-and-INCRBY.
- `app/Services/Review/CostReservation.php` (+ `CostReservationInterface.php`).
- `app/Services/Review/ReservationResult.php` — value object.
- `app/Mail/DailyTokenSpendApproaching.php` — queued mailable.
- `resources/views/mail/daily-token-spend-approaching.blade.php` — plain-text template.
- `workspaces.daily_token_cap` + `daily_token_cap_alert_threshold` columns.
- `app/Providers/AppServiceProvider` binding of the interface.

### 3.2. Out-of-scope for this iteration
- UI control to change the cap per workspace (admin-only env override or migration default in MVP).
- Cost rollover (unused budget does not carry over).
- Programmatic cost forecasting per repo.

### 3.3. Dependencies
- Redis (production) + predis/predis PHP client (CI uses Mockery facade fake).
- `Workspace` model + migration (Phase 1).
- `ReviewPullRequestJob` as the primary caller.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV admin / workspace owner** — sets the cap; receives alerts.
- **Anthropic finance owner** — sees the bill at end of month.
- **ReviewPullRequestJob** — programmatic caller.

### 4.2. Primary use cases
- As an admin, I want a cap on how much I can spend per day so a runaway job can't drain my Anthropic budget.
- As an admin, I want to be warned BEFORE the cap is hit, not after, so I can investigate.
- As the orchestrator, I want a single API call to atomically check + reserve tokens or fail loudly.

### 4.3. User journey
Operator sets `daily_token_cap=200000` (default) and `threshold=70` (default) at workspace creation. Job arrives → `CostReservation::reserve($workspaceId, 80000, 200000)` → Lua check: current 90000 + 80000 = 170000 ≤ 200000 → INCRBY 80000 → return success. Next job arrives same day → current 170000 + 80000 = 250000 > 200000 → return failure. Meanwhile when current crosses 140000 (70% of 200000) → `notifyIfThresholdExceeded` fires the mailable once per day.

---

## 5. Functional Requirements

**FR-01 — `reserve` atomic check-and-INCRBY**
- Inputs: `int $workspaceId, int $tokens, int $dailyCap`.
- Behaviour: Redis EVAL of the Lua script. Key: `workspace:{id}:tokens:{YYYYMMDD}` (UTC date). Lua: GET current; if current + tokens > cap → return -current (negative signals denial); else INCRBY tokens, EXPIRE 26h (idempotent EXPIRE refresh on every successful reserve), return new total.
- Output: `ReservationResult { granted: bool, consumed: int, cap: int }`; helpers `remaining()` and `denied()`.

**FR-02 — `consumed` non-atomic read**
- Inputs: `int $workspaceId`.
- Behaviour: GET on today's UTC key; 0 if missing.
- Output: int.

**FR-03 — `release` decrement (refund)**
- Inputs: `int $workspaceId, int $tokens`.
- Behaviour: DECRBY bounded at 0 via Lua (we don't want negative counters from over-release).
- Output: void.

**FR-04 — `dailyCapFor` per-workspace lookup**
- Inputs: `Workspace`.
- Behaviour: read `workspace.daily_token_cap` (default 200_000 if column missing or null).
- Output: int.

**FR-05 — `notifyIfThresholdExceeded` alert dispatch**
- Inputs: `Workspace, int $currentConsumption`.
- Behaviour: if `$current > threshold% × cap` AND the alert has not been sent today (Redis key `workspace:{id}:alert_sent:{YYYYMMDD}` SET NX) → dispatch `DailyTokenSpendApproaching` mailable to all workspace admins.
- Output: void; mailable queued.

### 5.2. Error and edge cases
- Date rollover at 00:00 UTC: new key naturally created on next reserve; old key TTL expires within 26h. No cross-day contamination.
- Redis unavailable: reserve throws (no fallback granting — fail-closed). Job handler catches and marks Failed.
- Cap == 0 (someone misconfigures): every reserve is denied; explicit; operator can fix via env or seeder.
- threshold == 0: every reserve triggers alert (annoying); validate at config-load layer that 1 <= threshold <= 99.

---

## 6. Non-Functional Requirements

- **Atomicity**: Lua block runs single-threaded on Redis; no race possible regardless of how many clients call concurrently.
- **Performance**: single EVAL roundtrip per reserve (~1-2 ms typical Redis latency).
- **Reliability**: 26h TTL with EXPIRE refresh on every successful reserve guarantees correct lifecycle even with timezone drift.
- **Alerting frequency**: at most one alert per workspace per day per threshold band.

---

## 7. Data Model & Contracts

### 7.1. Columns added (Phase 3 migration)
- `workspaces.daily_token_cap` (unsigned int, default 200_000).
- `workspaces.daily_token_cap_alert_threshold` (tinyint, default 70).

### 7.2. Redis keys
- `workspace:{id}:tokens:{YYYYMMDD}` — counter, TTL 26h.
- `workspace:{id}:alert_sent:{YYYYMMDD}` — once-per-day alert marker.

### 7.3. Contracts
- `CostReservationInterface::reserve(int, int, int): ReservationResult`.
- `CostReservationInterface::release(int, int): void`.
- `ReservationResult` with `granted()`, `denied()`, `remaining()`.

---

## 8. AI/Agent Design

Not directly. This feature gates the AI calls in `ai-code-review-pipeline.md` but does not invoke any model itself.

---

## 9. UX & Interaction Design

- Mailable template is plain-text, addressed to workspace admins.
- Subject line: `[cdv-rabbit] {workspace.name}: daily token spend at {percent}% of cap`.
- Body: current consumption + cap + percent + recommended actions ("review spending in dashboard", "increase cap", "investigate runaway reviews").
- No in-UI control to change cap in MVP — operator changes via tinker, seeder, or future admin route.

---

## 10. System Architecture & Integration

### 10.1. Service bindings
- `CostReservationInterface::class` → `CostReservation` in `AppServiceProvider::register()`.
- Lua script loaded once per process from `resources/lua/cost_reservation_check_incr.lua`; Redis caches the SHA on first EVAL.

### 10.2. Caller integration
- `ReviewPullRequestJob::handle()` calls `reserve` immediately after the kill-switch check (FR-02 in `ai-code-review-pipeline.md`).
- On terminal error in handle, `release` refunds the reserved tokens so the workspace's budget isn't permanently consumed by a failed job.

### 10.3. Test environment
- `phpunit.xml` sets `REDIS_CLIENT=predis` because phpredis is not always installed in CI.
- `CostReservationTest` uses Mockery `shouldReceive()` on the Redis facade — the Lua block is atomic on the Redis server regardless of client; the unit test verifies the contract is INVOKED correctly, not that Redis itself is atomic (we trust Redis).

---

## 11. Validation: Acceptance Criteria & Test Strategy

- **AC11** — Per-workspace daily cost ceiling halts dispatch when exceeded; sends an alert email to workspace admins. Test: `CostReservationTest` covers cap denial + alert dispatch; integration covers the orchestrator wiring.
- **AC20** — Concurrent reserves cannot both pass the check. Test: `CostReservationTest` asserts the Lua INCRBY contract is invoked correctly; the atomicity is a Redis property (trusted, not re-tested).

Supplementary cases in `CostReservationTest` (13 cases):
- Single reserve happy path returns success and increments counter.
- Reserve exceeding cap returns failure with current value unchanged.
- Release decrements (bounded at 0).
- Date rollover isolation (day N reserves do not touch day N+1).
- Alert fires above threshold; suppressed below; once-per-day deduplication.

---

## 12. Telemetry, Observability & Evaluation Metrics

- `consumed(workspaceId)` is available for admin dashboards (W4-W5).
- Failed reservations land in `reviews.error_class = 'cost_ceiling'` — countable per workspace per day.
- Alert dispatches are inferable from the `alert_sent` Redis key + mail logs.

---

## 13. Security, Privacy & Compliance

- Daily cap is per-workspace — no cross-workspace leakage of consumption data.
- Mailable recipients are workspace admins only (resolved from `workspace_user` pivot at dispatch time).
- No customer code in the mailable body — just numbers.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Default cap of 200k tokens might be too high or too low for typical workloads | Empirically tunable; calibrate during W6 pilot. |
| Lua script lives in a separate file → deploy must include it | Documented; `composer dump-autoload` doesn't help here, but Laravel's deployment copies `resources/`. |
| Release on failure could be exploited to "free" tokens (call reserve then deliberately fail) | Practically harmless — the cap is still enforced per day; the attacker has spent the LLM tokens already. |
| Mailable mass-sends if many workspaces cross threshold simultaneously | Each mailable is queued; no synchronous blast. Threshold-band dedup limits churn. |
| Manual cap change requires DB write | Acceptable for MVP. Backlog: per-workspace settings UI in W4. |

Open: should we add an HOURLY rate limit on TOP of the daily cap to defend against pathological bursts within a day? (Backlog — feels like over-engineering for MVP, but Pre-mortem Scenario 2 hinted at it.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-3-complete)** — Initial Phase 3 implementation. Commit: `eb74aca` (W3-T5 CostReservation + Lua + mailable + migration). Wired into orchestrator in `d64b0cb` (W3-T7). 13/13 unit tests at landing; AC11 + AC20 green.
