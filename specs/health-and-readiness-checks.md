# Health & Readiness Checks

## 1. Overview

### 1.1. Feature Name
**Health & Readiness — Multi-Dep `/up` Endpoint**

### 1.2. One-sentence summary
`GET /up` runs five concurrent dependency checks (DB, Redis, Horizon supervisor freshness, Bitbucket API, Anthropic API), capping at 5 seconds total, and returns `200 + status=healthy` only when all are green or `503 + per-check breakdown` when any are degraded — giving ops a single endpoint for liveness + readiness signals.

### 1.3. Primary outcome
`curl localhost/up` during a healthy state returns 200 with all five `ok=true` checks and a millisecond duration each. Take BB API down (or simulate via 5xx) and the same call returns 503 with `bitbucket_api.ok=false` and the other four still ok — within 6 seconds total even with one timeout.

### 1.4. Version & owner
`v1.0 – Phase 5 / Week 5 (tag phase-5-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
Laravel ships a default `/up` route that returns 200 if PHP boots. That's a liveness probe, not a readiness probe. A real production deploy needs to know whether outbound dependencies (BB API, Anthropic API) and internal (DB, Redis, Horizon) are functional before declaring the instance "ready to take traffic."

### 2.2. Problem statement
Without per-dep visibility, the only ops-side signal for "reviews aren't running" is "PRs aren't getting comments." That diagnostic loop is hours. `/up` with per-dep breakdown collapses it to seconds.

### 2.3. Goals
- **G1**: Single endpoint covering DB + Redis + Horizon + BB + Anthropic.
- **G2**: Per-check duration_ms so the slow dep is identifiable from the response alone.
- **G3**: Each external check capped at 2s; aggregate at 5s. Hard time bound.
- **G4**: 200/503 binary status for orchestrators (Kubernetes, Laravel Cloud) + JSON detail for humans.
- **G5**: `version` key (git short SHA or config fallback) for deploy verification.

### 2.4. Non-goals / out of scope
- Per-tenant health (separate `/up?workspace=...` route — backlog).
- Metrics endpoint (`/metrics` Prometheus format — backlog).
- Trace propagation via the health-check route — not useful.

### 2.5. Dependencies
- Laravel `Http`, `DB`, `Redis` facades.
- Horizon supervisor heartbeat keys on Redis (`horizon:masters` sorted set).
- `config/services.php` for BB base URL.
- `config/ai.php` for Anthropic base URL.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Http/Controllers/Health/HealthController.php` (invokable).
- Removal of Laravel's auto-registered `/up` from `bootstrap/app.php` (`withRouting(health: ...)`).
- Custom `/up` route in `routes/web.php`.
- Concurrent check execution via `Concurrency::run([...])`.
- 5 check closures, each timeboxed.
- 503 short-circuit when any check fails.
- `version` key (git SHA fallback to config).

### 3.2. Out-of-scope for this iteration
- Smart degradation logic (e.g., "degraded but serving" with partial 200) — current contract is binary.
- Cache the health result for N seconds to avoid hammering dependencies under high probe frequency — backlog.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **Kubernetes / Laravel Cloud / load balancer** — uses `/up` for readiness probes.
- **On-call engineer** — `curl /up` during an incident.
- **CI/CD pipeline** — post-deploy verification step.

### 4.2. Primary use cases
- As K8s, I want a 200/503 binary so I can rotate pods cleanly.
- As on-call, I want per-dep ok=false to know which dep to investigate.
- As CI, I want a `version` key matching the deployed git SHA so I can fail the deploy if it shows an old version.

### 4.3. Flow
External caller → `GET /up` → controller starts 5 concurrent closures → each completes or times out within 2s → aggregate evaluated → 200 if all ok, else 503 → JSON response with per-check breakdown + version.

---

## 5. Functional Requirements

**FR-01 — Five concurrent checks**
- DB: `DB::connection()->getPdo()` — cheap, sync.
- Redis: `Redis::ping()` — cheap, sync.
- Horizon: read `horizon:masters` sorted set; for each supervisor, compute age via `zscore`. Any supervisor with age > 60s = stale = degraded. If no supervisors at all = degraded (Horizon not running).
- Bitbucket: HEAD to `config('services.bitbucket.base_url')` with `Http::timeout(2)`. 200 or 401 = reachable; 5xx or timeout = degraded.
- Anthropic: HEAD/GET to the configured Anthropic base, same 2s timeout, same 200/401 = reachable convention.

**FR-02 — Aggregate**
- All `ok=true` → 200 + status="healthy".
- Any `ok=false` → 503 + status="degraded".

**FR-03 — Response shape**
```json
{
  "status": "healthy",
  "checks": {
    "db": {"ok": true, "duration_ms": 5},
    "redis": {"ok": true, "duration_ms": 1},
    "horizon": {"ok": true, "duration_ms": 3, "supervisor_age_seconds": 12},
    "bitbucket_api": {"ok": true, "duration_ms": 87},
    "anthropic_api": {"ok": true, "duration_ms": 124}
  },
  "version": "3409cc6"
}
```

**FR-04 — Timeouts**
- Each external HTTP check: 2s.
- Aggregate: 5s (enforced via `Concurrency::run` timeout or explicit cancellation).

**FR-05 — Version key**
- Reads `git rev-parse --short HEAD` at runtime via `exec(...)` with a 1s timeout OR falls back to `config('cdv-rabbit.version', 'unknown')`.

### 5.2. Error and edge cases
- Horizon Redis connection unavailable: treat as degraded (Redis check would already catch this).
- BB returns 200 from the base URL (it does — `https://api.bitbucket.org/2.0/` returns a discovery doc): counts as reachable. 401 also reachable (means we're talking to BB, just not authed).
- Anthropic base URL returns 401: same — reachable.
- Network timeout > 2s: counts as ok=false on that check.
- One check exception thrown: caught, recorded as ok=false with the exception class as detail.

---

## 6. Non-Functional Requirements

- **Latency**: aggregate p95 < 200ms when all deps are healthy; up to 5s when one dep times out.
- **Reliability**: the endpoint itself must be robust to dep failures — never 500 (always 200 or 503).
- **Cost**: each call hits 2 external APIs; at high probe frequency this could rate-limit. Mitigation: cache-result-for-N-seconds backlog item.

---

## 7. Data Model & Contracts

No new tables. Reads only from external systems.

The JSON response shape is the contract. Probe-frequency / cache decisions are operational.

---

## 8. AI/Agent Design

Not applicable.

---

## 9. UX & Interaction Design

CLI / HTTP. No UI in MVP.

---

## 10. System Architecture & Integration

### 10.1. Route registration
- `routes/web.php`: `GET /up` → `HealthController`.
- `bootstrap/app.php`: `health: '/up'` removed from `withRouting()`.

### 10.2. Concurrent execution
- Laravel 11+ `Concurrency::run([...])` runs closures in parallel forks (or sync fallback in test env if forking unavailable).
- Each closure returns a `[ok, duration_ms, optional_extras]` tuple.

### 10.3. Integration touch points
- `bitbucket-cloud-integration.md` — uses the same `services.bitbucket.base_url`; if that config is wrong, BB check fails.
- `cost-management-and-ceiling-alerts.md` — Redis check verifies the same Redis CostReservation relies on.
- `observability-and-structured-logs.md` — independent; logging works regardless of health endpoint state.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Phase 5 acceptance:
- **AC15** (full coverage; was partial after W2) — `/up` returns 200 only when DB + Redis + BB API + Horizon + Anthropic are reachable.

Tests:
- `tests/Feature/Health/HealthControllerTest.php` — 9 cases:
  - all-healthy → 200, status=healthy.
  - DB down → 503.
  - Redis down → 503.
  - BB 5xx → 503.
  - Anthropic timeout → 503.
  - Horizon stale → 503.
  - BB 401 → still reachable (200).
  - version key present.
  - aggregate completes within 6s even with one timed-out check.
- `tests/Feature/Phase5/Phase5HardeningSmokeTest.php` — case 2: full-stack healthy → 200.

---

## 12. Telemetry, Observability & Evaluation Metrics

The endpoint IS observability. Probe systems (Kubernetes, LB) produce histories; not stored by the app.

---

## 13. Security, Privacy & Compliance

- No auth on `/up` — endpoint is intentionally public so probes don't need credentials. Per-check responses don't leak sensitive data (no tokens, no workspace IDs, no PII).
- Rate limiting on `/up` not enforced in MVP (probes are trusted). Backlog: per-IP cap to prevent abuse.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| High-frequency probes hammer Anthropic/BB rate limits | Mitigation: cache health response for 10s — backlog. |
| Public endpoint reveals which deps are degraded | Acceptable: no secrets disclosed; standard for SaaS health endpoints. |
| Concurrency::run forks under Pest may behave differently | Tests use sync fallback; production uses forks. |
| `version` via `exec` could fail in restricted environments | Falls back to config('cdv-rabbit.version', 'unknown'). |

Open: should we add a `/ready` endpoint distinct from `/up` (liveness vs readiness)? (Backlog — Kubernetes pattern.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-5-complete)** — Initial implementation. Commits: `3409cc6` (W5-T2 controller + 9 tests), `(W5-T5 commit)` smoke case 2. Tests: HealthControllerTest (9 cases) + Phase5HardeningSmokeTest case 2.
