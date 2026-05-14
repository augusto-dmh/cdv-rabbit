# Review Dashboard

## 1. Overview

### 1.1. Feature Name
**Review Dashboard — Reviews Index + Detail UI**

### 1.2. One-sentence summary
A workspace-scoped Inertia/Vue UI for browsing AI-review history: paginated index with filters by repository / status / date range, and a detail page showing per-call telemetry (model, tokens, request_id, cost, rate-limit headers) plus posted-comments metadata — never the comment text or diff content.

### 1.3. Primary outcome
A workspace admin lands on `/workspaces/{slug}/reviews`, filters by status=failed + date range, drills into a single failing review, and sees the model used, token breakdown, cost in USD, request_id (for Anthropic support), error_class, and the file paths the bot commented on — without any code/text leaking to the UI layer.

### 1.4. Version & owner
`v1.0 – Phase 4 / Week 4 (tag phase-4-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
Phase 1-3 built the engine; Phase 4 builds the cockpit. Admins need visibility into what the bot is doing, what it cost, and which reviews failed, so they can decide whether to flip the kill switch (`kill-switch-control.md`), raise the budget, or rotate the BB token.

### 2.2. Problem statement
The persisted state from Phase 1-3 is queryable but invisible to non-developers. Without a UI surface, the cost-per-PR forecast, the cache-hit rate, and the per-review error_class are operationally useless.

### 2.3. Goals
- **G1**: List reviews with three filters (repository, status, date range) + pagination.
- **G2**: Per-review detail with per-Anthropic-call telemetry (the `reviews_llm_calls` rows from Phase 3).
- **G3**: Surface BB deep-links so admins can jump from review row → BB PR in one click.
- **G4**: NEVER render diff content, comment text, or prompt/response text — the storage layer doesn't have it, and the UI must respect that boundary.
- **G5**: All navigation via Wayfinder typed actions; no hardcoded URLs.

### 2.4. Non-goals / out of scope
- Charts / aggregate dashboards (per-day cost, per-repo trend) — deferred to v0.2.
- Bulk operations (cancel running reviews, retry failed) — v0.2.
- Per-review markdown export — v0.2.
- Real-time updates via Inertia poll — current UI is request/refresh; polling deferred.

### 2.5. Dependencies
- `ai-code-review-pipeline.md` (produces the `reviews` and `reviews_llm_calls` rows).
- `tenancy-and-workspace-isolation.md` (all queries inherit the global scope).
- shadcn-vue primitives + Wayfinder + Inertia v3.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Http/Controllers/Reviews/ReviewController.php` — index + show.
- `app/Http/Requests/Reviews/IndexReviewsRequest.php` — filter validation.
- `resources/js/pages/reviews/Index.vue` — paginated table with filter bar.
- `resources/js/pages/reviews/Show.vue` — detail page with LLM-calls + comments-metadata tables.
- `resources/js/components/AppSidebar.vue` — "Reviews" nav link.
- `Review::getCostUsdAttribute()` accessor for formatted display.
- `Workspace::reviews()` HasMany relation.
- Shared prop `currentWorkspaceKillSwitchEnabled` (used by sidebar + kill-switch page).

### 3.2. Out-of-scope for this iteration
- Reviews export (CSV/JSON) — backlog.
- Review filtering by `risk_level` (the LLM's own classification) — could be added later.
- Sortable columns — backlog.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV workspace admin** — primary operator.
- **CDV engineer** — looks at a review their PR triggered.
- **DPO / auditor** — inspects telemetry to confirm no diff content is rendered.

### 4.2. Primary use cases
- As an admin, I want to filter to failed reviews so I can investigate quickly.
- As an engineer, I want to click a review row and see what the bot ran, what it cost, and what it said.
- As an auditor, I want confidence that no diff content ever reaches the UI.
- As an admin, I want pagination that preserves filter state when I page forward.

### 4.3. Flow
Sidebar → "Reviews" (visible only with workspace context) → Index page → filter bar (repo + status + date range) → row → Show page with Timeline / Cost / LLM Calls / Comments cards. PR# is a BB deep-link.

---

## 5. Functional Requirements

**FR-01 — Filter validation**
- Inputs: `repository_id?: int`, `status?: ReviewStatus`, `date_from?: Y-m-d`, `date_to?: Y-m-d`, `per_page?: int 1..100`.
- Behaviour: `IndexReviewsRequest` validates types + enum + existence of `repository_id` on the workspace's repositories (cross-tenant tampered ids → 422).
- Output: validated array consumed by the controller.

**FR-02 — Paginated index**
- Trigger: GET `/workspaces/{slug}/reviews`.
- Behaviour: `Review::query()->where(filters)->orderByDesc('created_at')->paginate($per_page)` — global scope adds `workspace_id` automatically. Eager-loads `repository` + `latestLlmCalls`.
- Output: Inertia render of `reviews/Index` with `reviews` paginator + `repositories` (for filter select).

**FR-03 — Detail page**
- Trigger: GET `/workspaces/{slug}/reviews/{review}`.
- Behaviour: Route-model binding for `{review}` runs through the global scope; an id from another workspace returns 404. Eager-loads `comments` (metadata only) + `llmCalls`.
- Output: Inertia render of `reviews/Show`.

**FR-04 — Index UI: filter bar**
- shadcn-vue Select for repository (options derived from repositories prop or current page's reviews).
- shadcn-vue Select for status (5 enum values).
- Native date pickers (no DateRange component in current shadcn-vue install).
- All mutations call `router.get(..., { preserveScroll: true, preserveState: true })`.

**FR-05 — Index UI: table**
- Columns: PR# (linked to BB), repo, status Badge (color per state), created_at (relative + absolute on hover), tokens (formatted), cost USD, duration.
- Empty state when no rows.
- Native-button pagination (avoids shadcn Button + v-text-v-html lint friction).

**FR-06 — Show UI: cards**
- Timeline (started_at, finished_at, duration).
- Cost & Tokens (prompt, completion, total, USD, secrets_redacted).
- LLM Calls table (per `reviews_llm_calls` row).
- Comments metadata table (path, line, type, BB id deep-link, posted_at). NEVER renders comment text.
- Failed-status banner with error_class + error_message + correlation_id.

**FR-07 — Sidebar nav**
- "Reviews" link below "Workspaces", visible only when `currentWorkspace` shared prop is non-null. Uses `ReviewController.index` Wayfinder action with the workspace slug bound.

### 5.2. Error and edge cases
- Unauthenticated → Fortify redirect to login.
- Non-member → 403 via `workspace.member` middleware.
- Tampered `{review}` id (another workspace) → 404 via global scope.
- Empty filter date_to before date_from → controller does not currently enforce ordering; backlog.
- Massive review count (10k+) → paginator handles; UI doesn't degrade.

---

## 6. Non-Functional Requirements

- **Performance**: p95 index render < 200ms for the typical workspace (a few hundred reviews).
- **Accessibility**: shadcn-vue primitives carry baseline ARIA; no custom regressions.
- **Internationalization**: not addressed in MVP; UI strings English-only (backlog: PT-BR per CDV preference).
- **Browser support**: matches starter-kit baseline (modern evergreen browsers).

---

## 7. Data Model & Contracts

No new tables/columns. Reads:
- `reviews` (filtered + paginated).
- `repositories` (for filter select).
- `review_comments` (metadata join on show).
- `reviews_llm_calls` (telemetry join on show).

Inertia shared props added:
- `currentWorkspaceKillSwitchEnabled: bool | null` (HandleInertiaRequests).

---

## 8. AI/Agent Design

Not applicable — this feature renders persisted data; no LLM calls.

---

## 9. UX & Interaction Design

- Layout: `AppLayout` with breadcrumbs.
- Filter bar: 4 inputs in a horizontal flex; collapses to grid on mobile.
- Status badge color map:
  - Queued: muted gray.
  - Running: blue.
  - Posted: green.
  - Failed: red.
  - Skipped: amber.
- Cost is always USD; no currency switching in MVP.
- request_id rendered truncated (first 8 chars) with a copy-to-clipboard button.

---

## 10. System Architecture & Integration

### 10.1. Route layer
- `routes/web.php`: `workspaces.reviews.index` + `workspaces.reviews.show` inside the `auth + verified + workspace.member` group.

### 10.2. Inertia layer
- Pages auto-discovered from `resources/js/pages/`.
- Wayfinder regenerates `@/actions/App/Http/Controllers/Reviews/ReviewController` on every `npm run dev`/`build`.

### 10.3. Integration touch points
- `tenancy-and-workspace-isolation.md` — `workspace.member` middleware binds context; global scope filters queries.
- `ai-code-review-pipeline.md` — produces every row this page reads.
- `kill-switch-control.md` — sidebar surfaces the kill-switch state badge alongside the Reviews link.

---

## 11. Validation: Acceptance Criteria & Test Strategy

No new ACs introduced — this feature is read-only UI on top of Phase 1-3 ACs.

Existing AC reinforcement:
- **AC12** (cross-workspace isolation) — `UiAuthorizationTest` confirms an authenticated user cannot show a review from a workspace they don't belong to (404, not 403, because the global scope rejects the model resolution).
- **AC14** (full suite green) — maintained at 288/874.

Tests added:
- `tests/Feature/Reviews/ReviewControllerTest.php` — 11 cases (filters, pagination, tenant isolation, accessor format).
- `tests/Feature/Phase4/Phase4UiSmokeTest.php` — 8 cases (each UI page 200; filter narrowing; pagination).
- `tests/Feature/Phase4/UiAuthorizationTest.php` — 10 cases (unauthenticated / non-member / cross-workspace).
- `tests/Feature/Navigation/SidebarNavTest.php` — 5 cases (shared props by route context).

---

## 12. Telemetry, Observability & Evaluation Metrics

This feature does not emit new metrics. It SURFACES Phase 3 telemetry (the four token fields + request_id + ratelimit headers + duration_ms) so admins can monitor cache-hit rates, cost spikes, and error_class distributions.

---

## 13. Security, Privacy & Compliance

- Every row passes through the tenant-scoped global scope — no cross-workspace leakage possible without `withoutWorkspaceScope()` (auditable).
- The UI never renders code or comment text; the storage layer doesn't have it (LGPD posture).
- `request_id` is non-sensitive (Anthropic-side correlation only).
- The cost field is per-workspace data; visible only to workspace members.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Filter date_from > date_to produces empty result | Acceptable; backlog: client-side validation. |
| repositories prop derivation falls back to current page's rows | Acceptable; alternative is a backend round-trip; current behaviour is good enough for MVP. |
| No sortable columns | Backlog. |
| No CSV export | Backlog. |
| Pagination state lost on hard reload | `preserveState: true` works for SPA navigation; deep links carry the query string, so hard-reload preserves filters too. |

Open: should the LLM Calls table show running totals (sum of tokens / cost) at the bottom? (Backlog.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-4-complete)** — Initial Phase 4 implementation. Commits: `50dc944` (W4-T1 backend), `86fc1ef` (W4-T2 UI), W4-T4 verifier commit (Phase 4 smoke + auth + nav). 288/874 tests green at tag.
