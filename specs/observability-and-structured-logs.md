# Observability & Structured Logs

## 1. Overview

### 1.1. Feature Name
**Observability — Per-Review Structured JSON Logs + Redacting Processor**

### 1.2. One-sentence summary
Every Review (Posted / Failed / Skipped) emits exactly one JSON line on the dedicated `cdv-rabbit-reviews` channel with 16 safe fields (correlation_id, tokens, cost, duration, status, error metadata) — never the diff, comment text, prompt, or response — and a Monolog `RedactingProcessor` strips forbidden keys from any log call across the application as a belt-and-suspenders LGPD backstop.

### 1.3. Primary outcome
`tail -F storage/logs/reviews.log | jq` shows one parseable JSON record per review with the 16 expected fields. A grep across the log file for any line containing diff-shaped content (regex `^\+|^-|@@|+++|---`) returns zero matches under any code path.

### 1.4. Version & owner
`v1.0 – Phase 5 / Week 5 (tag phase-5-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
The pipeline runs asynchronously, hits an external LLM, and writes to a third-party SCM. Without structured telemetry, every production issue is "look at the database row". Operators need a parseable stream of review events for dashboards, cost forecasting, and incident diagnostics.

### 2.2. Problem statement
Default Laravel logging is human-readable text + variable structure; useless for `jq`/Loki/Grafana ingestion. Worse, naive `Log::info('...', ['diff' => $diff])` would land customer code in `storage/logs/laravel.log`, violating the no-diff-at-rest invariant.

### 2.3. Goals
- **G1**: One structured JSON line per Review at terminal status.
- **G2**: 16 documented fields covering correlation, identity, status, timing, tokens, cost, errors, secrets-redacted-count, llm-calls-count.
- **G3**: No diff/comment/prompt/response content emitted under any code path.
- **G4**: Application-wide Monolog processor catches stray `Log::*` calls that accidentally include forbidden keys.

### 2.4. Non-goals / out of scope
- Trace propagation / OpenTelemetry (backlog).
- Centralized log shipping (operator-side: Loki/CloudWatch/Datadog — not in app).
- Per-call (not per-review) log lines — telemetry table covers per-Anthropic-call data.
- Real-time UI streaming of logs.

### 2.5. Dependencies
- Monolog 3 (ships with Laravel 13).
- `reviews.correlation_id` column.
- `ReviewPullRequestJob` orchestrator emits the line at every terminal state.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Logging/RedactingProcessor.php` — Monolog processor.
- `app/Logging/ReviewsChannelTap.php` — channel tap wiring the processor.
- `config/logging.php` — new `cdv-rabbit-reviews` channel.
- `reviews.correlation_id` migration + Review model fillable.
- `ReviewPullRequestJob::emitReviewLog()` — invoked from every terminal state branch.

### 3.2. Out-of-scope for this iteration
- Auth/admin/HTTP request logging (default Laravel logger continues to handle).
- Log rotation policy (operational; defer to deploy infra).
- Sampling (every review is logged).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **Ops engineer** — tails the log to debug incidents.
- **CDV finance** — `jq` over a week of logs for cost forecasting.
- **DPO** — periodically greps for diff-shaped content to verify the no-diff invariant holds.

### 4.2. Primary use cases
- As ops, I want one line per review so I can `jq -s 'group_by(.workspace_id)|...'` for cost-by-workspace breakdown.
- As finance, I want `cost_usd_cents` machine-readable to feed a spreadsheet without parsing.
- As DPO, I want a grep-able guarantee that no diff content leaks.

### 4.3. Flow
Job dispatches → orchestrator enters terminal branch → `emitReviewLog()` → fields constructed from `Review` model + computed values → `Log::channel('cdv-rabbit-reviews')->info('review_completed', $payload)` → channel tap injects RedactingProcessor → JsonFormatter writes one line to `storage/logs/reviews.log`.

---

## 5. Functional Requirements

**FR-01 — RedactingProcessor**
- Inputs: Monolog LogRecord.
- Behaviour: walks `context` + `extra` arrays. Any value at a key (case-insensitive) in `[diff, patch, content, body, hunk, code, source]` is replaced with `<<REDACTED>>`.
- Output: sanitized LogRecord forwarded to handlers.

**FR-02 — `cdv-rabbit-reviews` channel**
- Driver: `single` writing to `storage/logs/reviews.log`.
- Formatter: `Monolog\Formatter\JsonFormatter` (one JSON object per line).
- Tap: `ReviewsChannelTap` attaches the RedactingProcessor.
- Level: `info`.

**FR-03 — Per-review emission**
- Trigger: every terminal branch in `ReviewPullRequestJob::handle()` — Posted, Failed, Skipped (kill switch, cost ceiling, PR closed, diff too large, no reviewable files).
- Fields (16 total):
  1. `correlation_id` (UUID).
  2. `workspace_id`.
  3. `repository_id`.
  4. `pull_request_number`.
  5. `head_sha` (8 chars).
  6. `status` (`Posted` | `Failed` | `Skipped`).
  7. `started_at` (ISO 8601).
  8. `finished_at` (ISO 8601).
  9. `duration_ms`.
  10. `prompt_tokens`.
  11. `completion_tokens`.
  12. `cost_usd_cents`.
  13. `secrets_redacted` (count).
  14. `error_class` (nullable).
  15. `error_message` (nullable, truncated to 200 chars).
  16. `llm_calls_count`.
- Output: one log line per review terminal state.

### 5.2. Error and edge cases
- A test or job legitimately needing `diff` in a debug log → out of scope; never legitimate per LGPD.
- Failed job throws DURING `emitReviewLog()` itself → Laravel's default exception handling logs the failure to `laravel.log`; the structured log line is missing. Backlog: deferred-emit pattern to guarantee emission even on emit-time failure.
- Log file disk-full → standard Monolog behaviour (fall through to error channel); no special handling.

---

## 6. Non-Functional Requirements

- **Performance**: log emission is synchronous but adds <1 ms per review. Acceptable.
- **Reliability**: at-least-once emission per terminal branch. Multiple terminal-state changes within one handle() are accidental and discouraged; the orchestrator should `return` after every terminal branch.
- **Compliance**: zero diff content in log file under any code path (AC4 reinforcement).

---

## 7. Data Model & Contracts

### 7.1. Migration
- `reviews.correlation_id` (nullable UUID, set on dispatch).

### 7.2. Log line contract
The 16-field shape is the contract operators consume. Adding or renaming fields is a breaking change for downstream parsers — bump a `version` field if needed (currently implicit v1).

---

## 8. AI/Agent Design

Not applicable. This feature observes the AI pipeline; it doesn't invoke it.

---

## 9. UX & Interaction Design

CLI / tail-based consumption. No UI surface in MVP. Backlog: a UI page rendering the last 100 review log lines with filter chips (status, workspace).

---

## 10. System Architecture & Integration

### 10.1. Logging stack
- Monolog 3 → JsonFormatter → file handler.
- RedactingProcessor wired via channel tap.

### 10.2. Integration touch points
- `ai-code-review-pipeline.md` — every terminal branch emits the log.
- `lgpd-data-protection-posture.md` — RedactingProcessor is the application-wide log backstop.
- `health-and-readiness-checks.md` — health endpoint does NOT depend on the log channel; logging is fire-and-forget.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Phase 5 acceptance:
- **AC4** (no diff in any log/DB/filesystem) — reinforced by RedactingProcessor + the curated 16-field emission. Tested in `Phase5HardeningSmokeTest` (case 5).

Supplementary:
- `tests/Feature/Logging/StructuredReviewLogTest.php` — 4 cases:
  - Posted writes a line with all 16 fields.
  - Failed includes error_class + truncated error_message, no stack trace.
  - RedactingProcessor strips forbidden keys from arbitrary log calls.
  - Log file parses as JSON line-by-line.

---

## 12. Telemetry, Observability & Evaluation Metrics

This feature IS the observability surface for reviews. Downstream consumers (Loki, Grafana, Datadog, plain `jq` pipelines) feed off `reviews.log`:
- Cost per workspace per day = sum of cost_usd_cents grouped by workspace_id + date(started_at).
- Cache-hit rate per workspace = read from `reviews_llm_calls` (telemetry table; not duplicated here).
- Skipped-reason distribution = group_by status=Skipped + error_class.
- p95 duration = percentile(duration_ms).

---

## 13. Security, Privacy & Compliance

- **LGPD**: the curated 16-field set ensures no customer code is logged. RedactingProcessor catches regressions. AC4 invariant maintained.
- **request_id**: NOT emitted on the per-review log (per-call telemetry table holds Anthropic request_ids; the review-level log uses our own correlation_id).
- **error_message truncation** to 200 chars defends against unexpected long values; we don't expect code in these messages, but truncation is a cheap safety net.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| A future regression that introduces `Log::info('...', ['diff' => $diff])` | Mitigated by RedactingProcessor; AC4 test prevents it from going unnoticed. |
| Synchronous emission could fail during graceful shutdown | Acceptable; orchestrator's terminal branches happen before shutdown. |
| 16-field contract bumps require coordinated update of downstream parsers | Documented; bump a `schema_version` field if ever needed. |
| Log file grows without rotation | Operational concern; rotate via logrotate / cloud log shipping. |

Open: should we also emit a per-LLM-call log line in addition to the per-review summary? (Backlog — duplicates the telemetry table but easier for log-only consumers.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-5-complete)** — Initial implementation. Commits: `07beb80` (W5-T1 logging + RedactingProcessor), `7022192`'s parent (W5-T5 smoke). Tests added: StructuredReviewLogTest (4 cases) + Phase5HardeningSmokeTest case 5.
