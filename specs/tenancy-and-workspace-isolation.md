# Tenancy & Workspace Isolation

## 1. Overview

### 1.1. Feature Name
**Tenancy & Workspace Isolation**

### 1.2. One-sentence summary
Every business record in cdv-rabbit is keyed to a `workspace_id` and is read only through a fail-closed global scope that throws when the request/job has not explicitly bound a workspace context, so cross-tenant data access is structurally impossible.

### 1.3. Primary outcome
A query made without first binding a `WorkspaceContext` raises `WorkspaceContextMissingException`; a query made with one bound returns rows for that workspace only — proven by an adversarial test that creates two workspaces and asserts each context sees only its own rows.

### 1.4. Version & owner
`v1.0 – Phase 1 / Week 1 (tag phase-1-complete). Author: augusto-dmh (cdv-rabbit team).`

---

## 2. Context & Goals

### 2.1. Product/application context
cdv-rabbit is an internal-now / SaaS-later AI code review service for Bitbucket Cloud. Even at the internal stage, the data model is multi-tenant from migration 0001 so the SaaS expansion does not require a tenancy rewrite.

### 2.2. Problem statement
Retrofitting tenant isolation onto a flat data model is a known failure mode: it requires schema rewrites, query audits, and weeks of QA. We solve this once, in the foundation, with a structural guard rather than developer discipline.

### 2.3. Goals
- **G1**: Every workspace-scoped model carries a `workspace_id` column from its creating migration.
- **G2**: A single trait (`BelongsToWorkspace`) installs a global scope that filters all reads by the current workspace context.
- **G3**: The global scope fails closed — if no context is bound, it throws rather than silently returning all rows.
- **G4**: Queued jobs inherit context via a queue middleware so async work is as safe as HTTP work.
- **G5**: An adversarial test proves no cross-tenant read leaks across all workspace-scoped models.

### 2.4. Non-goals / out of scope
- Per-user RBAC inside a workspace (every workspace member is `admin` in MVP).
- Workspace-level rate limiting (covered by `cost-management-and-ceiling-alerts.md`).
- Workspace soft-delete or merge flows.
- Cross-workspace shared data (deliberately disallowed).

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Concerns/WorkspaceContext.php` singleton service.
- `app/Exceptions/WorkspaceContextMissingException.php` fail-closed exception.
- `app/Concerns/BelongsToWorkspace.php` Eloquent trait with global scope + creating hook + `withoutWorkspaceScope()` escape hatch.
- `app/Queue/BindWorkspaceMiddleware.php` queue middleware reading `$job->workspaceId` and binding/clearing the context around `$next($job)`.
- `app/Queue/WorkspaceAwareJob.php` documentary interface.
- `app/Http/Middleware/EnsureWorkspaceMember.php` HTTP middleware for the `/workspaces/{workspace:slug}/*` route group.
- Workspace + Repository + Review + ReviewComment + ReviewsLlmCall + WebhookDelivery models using the trait where appropriate.

### 3.2. Out-of-scope for this iteration
- Workspace-scoped database connections (we use a single database with row-level filtering).
- Per-workspace queues (one shared `reviews` Horizon supervisor).
- Audit logging of context bind/clear (would be useful but not load-bearing for MVP).

### 3.3. Dependencies
- Laravel 13.7 Eloquent global scopes.
- Laravel queue middleware contract (`handle($job, Closure $next)`).
- WorkspaceContext is bound in the service container as a singleton.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **Internal CDV developer** — opens PRs in CDV's Bitbucket workspace; never has visibility into another workspace.
- **Future external workspace admin (v0.3 SaaS)** — owns their workspace and must not see any other workspace's reviews, even with a leaked URL.
- **Operator / cdv-rabbit maintainer** — runs CLI commands (`rabbit:purge-stale`, `rabbit:lgpd-check`) that legitimately span workspaces.

### 4.2. Primary use cases / user stories
- As a CDV developer, I want my workspace's reviews to be invisible to any other workspace so that internal data stays internal.
- As a SaaS workspace admin (future), I want a tampered URL pointing at another workspace's review to return 403, not the review.
- As an operator, I want a documented escape hatch (`withoutWorkspaceScope()`) so cross-workspace maintenance commands can run without disabling the safety net globally.

### 4.3. User journey / flow summary
On every HTTP request hitting `/workspaces/{workspace:slug}/...`, `EnsureWorkspaceMember` resolves the workspace, verifies the user is on its `workspace_user` pivot, binds `WorkspaceContext`, and clears it on `terminate()`. On every queued job listing `BindWorkspaceMiddleware`, the middleware binds `WorkspaceContext` from `$job->workspaceId` before `handle()` and clears it in `finally`. Any Eloquent query inside either path reads through the trait's global scope; outside either path, queries throw.

---

## 5. Functional Requirements

### 5.1. Core flows

**FR-01 — WorkspaceContext singleton API**
- Inputs: workspace id (int).
- Behaviour: `bind(int)`, `current(): int` (throws if unbound), `optional(): ?int`, `clear()`, `bound(): bool`.
- Output: container singleton resolved consistently across the request/job lifecycle.

**FR-02 — Fail-closed global scope**
- Trigger: any Eloquent read on a model using `BelongsToWorkspace`.
- Behaviour: reads `WorkspaceContext::current()`. If unbound → `throw new WorkspaceContextMissingException(...)`. If bound → `where workspace_id = ?` appended to the query.
- Output: tenant-scoped result set, or thrown exception.

**FR-03 — Creating-event auto-fill**
- Trigger: any `$model->save()` on a workspace-scoped model.
- Behaviour: if `workspace_id` is null and `WorkspaceContext` is bound, the trait fills it before INSERT. If neither is set, the INSERT fails on the NOT NULL constraint.
- Output: stored row with correct `workspace_id`.

**FR-04 — `withoutWorkspaceScope()` escape hatch**
- Inputs: no parameters; called statically on the model.
- Behaviour: returns an Eloquent builder with the global scope removed. The caller owns the breach and must document why (purge command, LGPD check command).
- Output: cross-tenant builder.

**FR-05 — Queue-side bind/clear**
- Trigger: a job lists `BindWorkspaceMiddleware` in its `middleware()` method.
- Behaviour: middleware reads `$job->workspaceId` (throws `InvalidArgumentException` if missing), binds context, invokes `$next($job)` inside try/finally so `clear()` runs on success or exception.
- Output: tenant-safe `handle()` execution.

**FR-06 — HTTP-side bind/clear**
- Trigger: any route inside the `workspace.member` middleware group.
- Behaviour: middleware resolves the `{workspace}` route binding (via custom `Route::bind` that bypasses the global scope, since workspaces are root tenants), checks `workspace_user` membership (403 if not), binds context, clears on `terminate()`.
- Output: tenant-safe controller execution.

### 5.2. Error and edge cases
- Job with no `workspaceId` property → `InvalidArgumentException` at middleware entry, not silent context absence.
- Job throws mid-handle → `clear()` still runs (try/finally), context never leaks.
- Two concurrent requests in the same worker process → each request's middleware lifecycle owns its bind/clear; Laravel's per-request container scopes the singleton correctly.
- Console command without context → must explicitly use `withoutWorkspaceScope()` or bind context manually; running a workspace-scoped model query in tinker without binding throws (intentional).

---

## 6. Non-Functional Requirements

- **Reliability**: zero cross-tenant read leaks under adversarial test (AC12).
- **Performance**: the global scope adds a single `WHERE workspace_id = ?` predicate; for indexed tables this is sub-millisecond.
- **Maintainability**: developers cannot accidentally forget tenancy because absence of binding throws loudly.
- **Observability**: every `WorkspaceContextMissingException` is logged with the calling stack trace via Laravel's exception handler (default behaviour).

---

## 7. Data Model & Contracts

### 7.1. Tables carrying `workspace_id`
| Table | Column | Notes |
|---|---|---|
| workspaces | id (root) | The tenant itself; not scoped. |
| workspace_user | workspace_id | Pivot; not scoped by trait, but explicit relationship guards apply. |
| repositories | workspace_id | Scoped. cascadeOnDelete from workspace. |
| reviews | workspace_id | Scoped. |
| review_comments | workspace_id | Scoped (denormalized for tenant safety; redundant with review.workspace_id). |
| webhook_deliveries | (none) | NOT scoped — ingests before tenant is resolved. |
| reviews_llm_calls | workspace_id | Scoped. Indexed (workspace_id, created_at) for telemetry dashboards. |

### 7.2. Contracts
- `WorkspaceAwareJob` interface (documentary): `workspaceId(): int`.
- `BindWorkspaceMiddleware::handle($job, Closure $next): mixed`.
- `EnsureWorkspaceMember::handle(Request $request, Closure $next): Response` + `terminate(Request, Response): void`.

---

## 8. AI/Agent Design

Not applicable. This feature is pure framework plumbing — no LLM or agent involvement.

---

## 9. UX & Interaction Design

- HTTP failure for non-member: 403 Forbidden via `abort(403)`. The Inertia layer renders Laravel's default 403 page. (Future: shadcn-vue branded error page — backlog.)
- No user-facing UI element advertises the tenancy guard; correctness is invisible by design.

---

## 10. System Architecture & Integration

### 10.1. Service container bindings
- `WorkspaceContext::class` → singleton, registered in `AppServiceProvider::register()`.

### 10.2. Provider registrations
- `EnsureWorkspaceMember` aliased to `workspace.member` in `bootstrap/app.php`.
- Custom `Route::bind('workspace', ...)` in `AppServiceProvider::boot()` to resolve the slug binding while bypassing the global scope on the `Workspace` model itself (workspaces are not workspace-scoped — chicken/egg).

### 10.3. Integration with adjacent features
- `bitbucket-cloud-integration.md` — webhook ingestion writes `webhook_deliveries` rows BEFORE binding context (the BB UUID is matched to a repository, which then provides the workspace_id passed into the job).
- `ai-code-review-pipeline.md` — `ReviewPullRequestJob` carries `workspaceId` as its first property; `BindWorkspaceMiddleware` binds the context before any model load.
- `lgpd-data-protection-posture.md` — encryption and secret redaction operate orthogonally; tenancy is the access-layer guard, LGPD is the storage-layer guard.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Plan acceptance criteria covered by this feature:

- **AC12** — Every workspace-scoped query in production logs returns zero rows from another workspace's data. Test: `tests/Feature/Tenancy/CrossWorkspaceIsolationTest.php` (5 cases).
- **AC18** — `BelongsToWorkspace` global scope throws `WorkspaceContextMissingException` when no context is bound. Test: same file + `tests/Unit/Concerns/BelongsToWorkspaceTest.php`.

Supplementary tests:
- `tests/Unit/Concerns/WorkspaceContextTest.php` — singleton round-trip, throw-when-unbound, optional null path, clear, singleton scope (5 cases).
- `tests/Feature/Queue/BindWorkspaceMiddlewareTest.php` — bind/clear happy + on-throw + missing-workspaceId throw (3 cases).
- `tests/Feature/Middleware/EnsureWorkspaceMemberTest.php` — non-member 403, member 200 with context bound, context cleared on terminate.

---

## 12. Telemetry, Observability & Evaluation Metrics

- `WorkspaceContextMissingException` is logged with stack trace via the default exception handler.
- No additional structured metric — the absence of cross-tenant leak is binary and tested, not measured continuously.

---

## 13. Security, Privacy & Compliance

- **LGPD posture**: this feature is half of the LGPD story (access guard). The other half lives in `lgpd-data-protection-posture.md` (storage guard).
- **Threat model**: malicious or buggy code that bypasses the trait would only succeed by either (a) explicitly calling `withoutWorkspaceScope()` — auditable via grep, or (b) using raw `DB::table(...)` queries — discouraged by `laravel-best-practices`.
- **Limitation**: the trait protects Eloquent reads, not raw `DB::` queries. The codebase convention is Eloquent-only; CI grep could enforce this in the future.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Forgetting to bind context in a new console command produces a runtime throw, not a compile-time error | Accepted (fail-closed is the desired behaviour; tests catch it). |
| `withoutWorkspaceScope()` is a developer trapdoor | Accepted; documented and grep-able. |
| Per-request singleton works only because Laravel's container is request-scoped — long-running workers must re-resolve | Mitigated by `BindWorkspaceMiddleware` clear-on-finish. |
| Models with denormalized `workspace_id` (e.g., `review_comments`) duplicate data | Accepted — defense in depth, ~4-byte column overhead. |

Open: should `withoutWorkspaceScope()` emit a deprecation-style log line to make breaches visible? (Backlog.)

---

## 15. Change Log

- **v1.0 (2026-05-13 / phase-1-complete)** — Initial Phase 1 implementation. Commits: `74d8c04` (WorkspaceContext + exception), `92c4d68` (BelongsToWorkspace + models + factories), `c1cc4c5` (BindWorkspaceMiddleware), `03b2145` (EnsureWorkspaceMember). Tests: 69/210 green at tag.
