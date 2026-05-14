# Bitbucket Cloud Integration

## 1. Overview

### 1.1. Feature Name
**Bitbucket Cloud Integration — Connect, Webhook, Repository Lifecycle**

### 1.2. One-sentence summary
cdv-rabbit connects to a Bitbucket Cloud workspace using an Atlassian API token, syncs repositories on demand, registers webhooks per enabled repo, and ingests `pullrequest:created` events via an HMAC-authenticated, idempotent endpoint that dispatches a tenant-bound review job.

### 1.3. Primary outcome
A real PR opened against an enabled CDV repository produces a `webhook_deliveries` row with status `dispatched` and a queued `ReviewPullRequestJob` within 2 seconds, with HMAC-SHA256 verification preventing forged deliveries and idempotency preventing duplicates.

### 1.4. Version & owner
`v1.0 – Phase 2 / Week 2 (tag phase-2-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
Bitbucket Cloud is the only SCM target in MVP (DC and GitHub deferred to v0.2/v1.0). All review work originates from a BB webhook, so this layer is the front door of the product.

### 2.2. Problem statement
A naive webhook receiver would accept any payload from any source, persist it raw, and dispatch even on duplicates. We need: cryptographic origin verification, idempotency on BB's at-least-once delivery semantics, and a clean lifecycle for the webhook registration itself.

### 2.3. Goals
- **G1**: Typed BitbucketClient wrapping BB Cloud REST v2 with retry/backoff and rate-limit header capture.
- **G2**: HMAC-SHA256 primary auth on the webhook endpoint with `hash_equals` timing-safe comparison.
- **G3**: URL-path secondary token as defense in depth (per-repo random token).
- **G4**: Idempotent ingestion via unique index on `webhook_deliveries.bitbucket_uuid`.
- **G5**: Workspace connect wizard that validates the API token via `BitbucketClient::me()` before persisting.
- **G6**: Per-repo enable/disable toggle that auto-registers/deletes the webhook on transition.

### 2.4. Non-goals / out of scope
- Bitbucket Data Center support (deferred — separate driver in v0.3+).
- `pullrequest:updated` (push) events — only `pullrequest:created` in MVP (incremental review is v0.2).
- Bot commands (`@cdv-rabbit review/pause`) — v0.2.
- OAuth Consumer flow — v0.3 (self-service SaaS).

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Services/Bitbucket/BitbucketClient.php` (10 methods: me, listRepositories, getRepository, getPullRequest, getDiffStat, getDiff, postPullRequestComment, postInlineComment, registerWebhook, deleteWebhook, updateComment).
- `app/Http/Controllers/Bitbucket/WebhookController.php` (invokable, HMAC + URL-token + idempotency + dispatch).
- `routes/bitbucket.php` + `bootstrap/app.php` route registration.
- `app/Http/Controllers/Workspaces/{Workspace,Connect,Repository}Controller.php`.
- `app/Http/Requests/Workspaces/{Create,Connect,UpdateRepository}WorkspaceRequest.php`.
- `resources/js/pages/workspaces/{Index,Show,Connect}.vue`.
- `EnsureWorkspaceMember` middleware (also documented in `tenancy-and-workspace-isolation.md`).
- `tests/Feature/Bitbucket/{WebhookController,EndToEndSmoke}Test.php`.

### 3.2. Out-of-scope for this iteration
- Per-event filtering by branch regex (`base_branches: ^main$`).
- Workspace-level webhook secret rotation UI (manual via connect wizard re-entry).
- Custom retry on initial webhook registration failure (BB returns the uuid synchronously).

### 3.3. Dependencies
- Workspace + Repository models (Phase 1).
- `webhook_deliveries` table with unique `bitbucket_uuid` index (Phase 1).
- Atlassian API token + service account (operational gate per plan §12).
- Bitbucket Cloud REST v2 endpoints.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV admin** — connects a workspace, picks which repos to enable for AI review.
- **CDV developer** — opens a PR and sees an AI comment appear within ~60s.
- **Bitbucket** — sends webhooks at least once per qualifying event.

### 4.2. Primary use cases
- As an admin, I want to connect my BB workspace by pasting an API token and have cdv-rabbit verify it works before saving.
- As an admin, I want to enable specific repos for review and have the webhook auto-register so I don't have to use BB's webhook UI.
- As a developer, I want my PR to be reviewed exactly once, even if BB retries the delivery.
- As an admin, I want a tampered HMAC request to be rejected with 401 regardless of URL token correctness.

### 4.3. User journey
Admin logs in → "Workspaces" → "+ New" → enters slug → connect wizard step 1 (explainer) → step 2 (paste token + service account) → backend calls `BitbucketClient::me()` → on success, token+account persisted (encrypted) → step 3 (sync repos) → repos table appears → admin toggles `enabled` → backend calls `BitbucketClient::registerWebhook` → BB returns uuid → row updated. Later: developer opens PR → BB POSTs to `/bb/webhook/{repo}/{token}` → HMAC verified → delivery row inserted → job dispatched.

---

## 5. Functional Requirements

**FR-01 — `BitbucketClient` typed wrapper**
- All requests carry `Authorization: Bearer {token}` from the workspace's encrypted bitbucket_token.
- 3-retry exponential backoff on 5xx + 429; `Retry-After` honored on 429.
- Captures `X-RateLimit-Remaining` / `X-RateLimit-Limit` per call; exposed via `lastRateLimit()`.
- Base URL via `config('services.bitbucket.base_url')` (default `https://api.bitbucket.org/2.0`).
- All methods log to the `bitbucket` log channel.

**FR-02 — Webhook receiver auth flow**
- Step 1: route binds `{repository}` (model binding via `webhook_token` URL slug). Mismatched URL token → 404.
- Step 2: read `X-Hub-Signature` header → compute `hash_hmac('sha256', $request->getContent(), $repository->workspace->webhook_secret)` → compare with `hash_equals`. Missing or mismatched → 401.
- Step 3: read `X-Event-Key` → if not `pullrequest:created` → 202 with "event ignored" body, no dispatch.
- Step 4: read `X-Hook-UUID` → check `webhook_deliveries.bitbucket_uuid` unique index → if exists, return 200 with status=`duplicate`, no dispatch.
- Step 5: inside DB transaction, insert `webhook_deliveries` (status=`received`), dispatch `ReviewPullRequestJob` with only safe scalars, update row status=`dispatched`.
- Step 6: return 202 + `delivery_id`.

**FR-03 — Connect wizard backend**
- `ConnectController::edit` returns Inertia view with the current state.
- `ConnectController::update` validates `bitbucket_workspace_slug` (regex), `bitbucket_token` (min 20), `bitbucket_service_account` (string) → calls `BitbucketClient::me()` → on 401 returns 422 validation error → on success persists (encrypted) + sets `health = healthy`.
- `ConnectController::destroy` nulls the token columns (rotates).

**FR-04 — Repo sync + enable lifecycle**
- `RepositoryController::sync` calls `BitbucketClient::listRepositories` → upserts each by `bitbucket_uuid` → updates `last_synced_at`.
- `RepositoryController::update` on `enabled` false→true: generates `webhook_token` (40-char hex), calls `BitbucketClient::registerWebhook(...)` with the signed BB-webhook URL + workspace's `webhook_secret` + events `['pullrequest:created']`, persists returned `webhook_uuid`.
- On true→false: calls `BitbucketClient::deleteWebhook(...)`, nulls `webhook_uuid` + `webhook_token`. Idempotent (re-enabling an already-enabled repo is a no-op).

**FR-05 — Inertia/Vue UI**
- `pages/workspaces/Index.vue` — workspace cards + new-workspace inline form.
- `pages/workspaces/Show.vue` — health badge, repos table with sync button + per-row toggle, "Connect Bitbucket" CTA when token absent, kill-switch toggle, reviews placeholder.
- `pages/workspaces/Connect.vue` — 3-step stepper, form for slug + token + service account, success/revoke state. All actions via Wayfinder typed imports.

### 5.2. Error and edge cases
- Workspace not connected when admin toggles enable: 422 with message "Connect Bitbucket first."
- BB returns 401 on `me()`: connect-wizard validation error.
- BB returns 5xx on `registerWebhook`: enable transaction rolls back; row stays `enabled=false`.
- HMAC missing AND URL token correct: 401 (HMAC is primary).
- Duplicate `X-Hook-UUID` race: unique constraint catches; only one insert succeeds.
- Force-push between dispatch and job execution: AC1 still satisfied (delivery + dispatch happen at receive time, regardless of subsequent head_sha changes).

---

## 6. Non-Functional Requirements

- **Reliability**: 5xx responses to BB retry under backoff; no manual reconciliation needed.
- **Performance**: webhook handler completes in <300ms p95 (DB insert + 1 unique-check + 1 queue push).
- **Security**: timing-safe HMAC comparison; URL token is defense-in-depth.
- **Observability**: every BB API call structured-logs to `bitbucket` channel; webhook deliveries persist regardless of outcome.

---

## 7. Data Model & Contracts

### 7.1. Tables introduced
- `webhook_deliveries(id, bitbucket_uuid UNIQUE, repository_id, event_type, status, processed_at, created_at)` — Phase 1 migration.

### 7.2. Columns introduced on Phase 1 tables
- `workspaces.webhook_secret` (encrypted:string) — used for HMAC by webhook controller.
- `repositories.webhook_token` (nullable string, 40-char hex when set).
- `repositories.webhook_uuid` (nullable string, set by BB on registerWebhook).

### 7.3. BB API contracts (consumed)
- `GET /user` — token validation.
- `GET /repositories/{workspace}` — paginated list.
- `POST /repositories/{w}/{r}/hooks` — webhook registration.
- `DELETE /repositories/{w}/{r}/hooks/{uuid}` — webhook removal.
- `POST /repositories/{w}/{r}/pullrequests/{id}/comments` — comment posting.
- `PUT /repositories/{w}/{r}/pullrequests/{id}/comments/{cid}` — comment update.

---

## 8. AI/Agent Design

Not directly. The webhook hands off to `ReviewPullRequestJob` (covered in `ai-code-review-pipeline.md`). This spec only stages the input.

---

## 9. UX & Interaction Design

- Inertia + Vue 3 (Composition API, `<script setup>`).
- shadcn-vue components (Card, Badge, Input, Button, Table) — no new dependencies.
- Wayfinder for ALL backend route calls — no hardcoded URLs.
- Loading states use existing `Form` component patterns.
- Validation errors render under inputs via Inertia's error bag.
- Three-step stepper is inline custom (uses Tailwind utilities; no new UI library).

---

## 10. System Architecture & Integration

### 10.1. Route registration
- `routes/bitbucket.php` registered via `bootstrap/app.php` `then:` callback in `withRouting()`.
- `routes/web.php` carries the workspace + repository routes inside `auth + verified + workspace.member`.

### 10.2. Provider/middleware
- `EnsureWorkspaceMember` aliased `workspace.member`.
- Custom `Route::bind('workspace', ...)` in `AppServiceProvider::boot()` bypasses the global scope for the workspace itself.

### 10.3. Integration touch points
- `tenancy-and-workspace-isolation.md` — `EnsureWorkspaceMember` binds context for the workspace UI routes; webhook ingestion runs OUTSIDE the workspace.member group (it knows the workspace via the repo binding).
- `lgpd-data-protection-posture.md` — `Workspace::$bitbucket_token` and `$webhook_secret` encrypted casts apply here.
- `ai-code-review-pipeline.md` — `ReviewPullRequestJob` is dispatched from `WebhookController`; the job re-fetches the diff via `BitbucketClient`.

---

## 11. Validation: Acceptance Criteria & Test Strategy

- **AC2** — Duplicate `X-Hook-UUID` → no second delivery row, no duplicate job dispatch. Tests: `WebhookControllerTest` + `EndToEndSmokeTest`.
- **AC15 (partial)** — `/up` health endpoint returns 200 (full BB + Anthropic reachability checks deferred to W5). Test: `EndToEndSmokeTest`.
- **AC19** — Missing or wrong HMAC → 401 regardless of URL token correctness. Test: `WebhookControllerTest`.

Supplementary:
- `BitbucketClientTest` — 9 cases (Http::fake happy paths, auth header, 429+Retry-After backoff, 404→null).
- `WorkspaceControllerTest` + `ConnectWorkspaceTest` — index isolation, create+admin auto-add, token-validation flow.
- `RepositorySyncTest` + `RepositoryEnableTest` — upsert, enable+webhook lifecycle, disable+cleanup.

---

## 12. Telemetry, Observability & Evaluation Metrics

- Every `BitbucketClient` call writes a structured log entry to the `bitbucket` channel.
- `lastRateLimit()` exposes BB's per-key rate budget (consumed by future health check in W5).
- `webhook_deliveries` table is itself a per-event audit log.

---

## 13. Security, Privacy & Compliance

- HMAC primary auth defeats URL-token leaks (the v1.0 M3 Critic fix).
- `webhook_secret` rotates by re-running the connect wizard (no in-place rotation UI yet).
- Workspace API token is encrypted at rest (see `lgpd-data-protection-posture.md`).
- Webhook URL is per-repo random — leak surface bounded.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| BB API rate-limit exhaustion during burst | Mitigated by per-workspace queue concurrency (Horizon `reviews` supervisor) + cost ceiling (see `cost-management-and-ceiling-alerts.md`). |
| Service-account ownership change → 401 storm | Pre-mortem Scenario 4 mitigation: 15-min `me()` health probe + auto-pause workspace on consecutive 401s. (Health probe deferred to W5.) |
| Webhook secret rotation requires re-issuing all repo webhooks | Accepted operational cost; rare. |
| Non-IP-allowlisted attacker with leaked HMAC could forge events | Backlog: ASN-based anomaly alert (Pre-mortem Scenario 5). |

Open: should we accept `pullrequest:updated` events for force-push detection in MVP? Currently deferred to v0.2 (incremental review).

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-2-complete)** — Initial Phase 2 implementation. Commits: `cfd4599` (BitbucketClient), `6b7f215` (WebhookController), `03b2145` (routes + EnsureWorkspaceMember), `7bdc0b8` (connect backend), `760ce2d` (connect UI), `b2a4955` (repo sync + enable lifecycle), `565ff93` (Phase 2 E2E smoke). Tests: 113/351 green at tag.
