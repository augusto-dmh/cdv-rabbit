# Kill Switch Control

## 1. Overview

### 1.1. Feature Name
**Kill Switch — Per-Workspace UI Toggle + Global Env Override**

### 1.2. One-sentence summary
Two independent kill switches halt the AI review pipeline: a per-workspace toggle owned by workspace admins (UI dialog + audit-logged action) and a global env-backed flag (`CDV_RABBIT_KILLED=true`) for operator emergency stops; either flipped on means `ReviewPullRequestJob::handle()` marks the review Skipped before any Anthropic call.

### 1.3. Primary outcome
An admin clicks the BIG RED button on `/workspaces/{slug}/admin/kill-switch`, confirms in the dialog, and within 10 seconds any newly dispatched review for that workspace exits with status Skipped without spending Anthropic tokens.

### 1.4. Version & owner
`v1.0 – Phase 4 / Week 4 (tag phase-4-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
The AI review pipeline can misbehave: hallucinated security findings on a critical PR, runaway cost, prompt-injection success producing harmful output. The kill switch is the primary rollback mechanism (plan §6 Pre-mortem Scenario 3). It must be fast, obvious, and unambiguous.

### 2.2. Problem statement
A naive deploy-to-disable approach takes minutes and requires a developer. The kill switch must work in seconds, from a web UI, by any workspace admin — and be undoable just as fast.

### 2.3. Goals
- **G1**: Per-workspace toggle visible in a single UI page (BIG RED button).
- **G2**: Admin-only authorization (workspace_user.role = admin).
- **G3**: Confirmation dialog with optional reason field — prevents accidental flips.
- **G4**: Global env flag (`CDV_RABBIT_KILLED`) for operator-level emergency-stop-all-workspaces.
- **G5**: Both flags consulted in `ReviewPullRequestJob::handle()` BEFORE any Anthropic call; either flip → Skipped, no cost.
- **G6**: AC8 contract: from flip to "no new job calls LLM" must be ≤ 10s.

### 2.4. Non-goals / out of scope
- Audit log UI (the column is flipped + reason captured at controller level; UI rendering of past flips is v0.2).
- Time-bounded kill switch ("kill for 1 hour, then auto-resume") — backlog.
- Per-repository kill switch — backlog.
- Slack/email broadcast on kill — backlog.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Http/Controllers/Admin/KillSwitchController.php`.
- `app/Http/Requests/Admin/UpdateKillSwitchRequest.php`.
- `config/cdv-rabbit.php` with `killed` key backed by `CDV_RABBIT_KILLED` env.
- `app/Jobs/ReviewPullRequestJob.php` kill-check condition.
- `resources/js/pages/admin/KillSwitch.vue`.
- Routes in `routes/web.php` (edit + update under `workspace.member`).
- Feature tests for backend + AC8 reinforcement.

### 3.2. Out-of-scope for this iteration
- Re-arming alert (notify admins when kill switch was on for > 24h).
- Multi-step revocation (e.g., admin → owner approval for un-kill).
- Slack/Discord integration.

### 3.3. Dependencies
- `workspaces.kill_switch_enabled` column (Phase 1 migration).
- `workspace_user.role` (Phase 1).
- shadcn-vue Dialog primitive.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **Workspace admin** — primary operator.
- **CDV ops engineer** — flips the global env flag.
- **DPO** — confirms the kill switch path is testable + reversible for compliance audits.

### 4.2. Primary use cases
- As an admin, I want a clearly labelled, hard-to-miss kill switch so I can stop the bot under stress.
- As ops, I want a global flag to halt ALL workspaces at once without per-tenant flipping.
- As a developer who fat-fingered, I want a confirmation dialog so I don't accidentally pause production.

### 4.3. Flow
Admin → sidebar shows kill-switch state badge → Admin clicks "Kill Switch" → `/workspaces/{slug}/admin/kill-switch` → reads current state → clicks BIG RED → Dialog confirms with optional reason → submit → state updated → next dispatched job sees the flag on → Skipped.

---

## 5. Functional Requirements

**FR-01 — Show kill-switch page**
- Trigger: GET `/workspaces/{slug}/admin/kill-switch`.
- Behaviour: `KillSwitchController::edit` returns Inertia view with `kill_switch_enabled` + `kill_switch_global` (the env-flag value).
- Output: Vue page renders state + button.

**FR-02 — Update kill switch**
- Trigger: PUT `/workspaces/{slug}/admin/kill-switch`.
- Behaviour: `UpdateKillSwitchRequest` validates `kill_switch_enabled: bool` + `reason?: string max:500`. authorize() requires workspace.admin via pivot. Controller updates the column + logs the change (user_id + reason + before/after).
- Output: redirect back with a flash message.

**FR-03 — Global env flag**
- Inputs: `CDV_RABBIT_KILLED` env var (default false).
- Behaviour: `config('cdv-rabbit.killed')` returns the boolean. No UI to flip; operator updates env + reloads.
- Output: applied alongside the per-workspace flag.

**FR-04 — Job kill check**
- Trigger: first step of `ReviewPullRequestJob::handle()`.
- Behaviour: `if ($workspace->kill_switch_enabled || config('cdv-rabbit.killed')) { mark Skipped; return; }`. No Anthropic call, no cost reservation consumption, no diff fetch.
- Output: Review row with status Skipped + correlation in logs.

**FR-05 — UI confirmation dialog**
- Trigger: click on BIG RED button.
- Behaviour: shadcn Dialog opens with current-state text + reason textarea + Cancel/Confirm buttons.
- Output: Confirm fires the Wayfinder typed action; Cancel closes without change.

### 5.2. Error and edge cases
- Non-admin user reaches the page (URL guessing): 403 via UpdateKillSwitchRequest::authorize().
- Global env flag is true while per-workspace is false: jobs still skipped — operator override wins.
- Global env flipped at runtime: requires worker restart for env reload (operational note documented).
- Kill switch flipped between dispatch and execute: handle() reads current value at execute time, so the flip applies even to already-queued jobs.

---

## 6. Non-Functional Requirements

- **Latency**: AC8 demands ≤ 10s from flip to first-job-skipped (no new LLM call). Achieved because handle() reads the column fresh at start.
- **Reversibility**: the toggle is symmetric; un-killing is just another flip.
- **Auditability**: every flip logs actor + reason + before/after to the Laravel log channel.

---

## 7. Data Model & Contracts

### 7.1. Columns (already present from Phase 1)
- `workspaces.kill_switch_enabled` (bool default false).

### 7.2. Config
- `config/cdv-rabbit.php` with `killed` key, `env('CDV_RABBIT_KILLED', false)`.

### 7.3. No new migrations

---

## 8. AI/Agent Design

Not applicable directly. This feature gates `ai-code-review-pipeline.md`.

---

## 9. UX & Interaction Design

- BIG RED button: Tailwind `bg-red-600 hover:bg-red-700 text-white px-8 py-4 text-xl font-bold rounded-lg`.
- Page heading: "Kill Switch — {workspace.name}".
- Current state badge: "AI reviews are currently **ENABLED**" (green) or "**PAUSED**" (red).
- Dialog: shadcn Dialog (not AlertDialog — same UX, available primitive).
- Reason textarea inside the dialog, optional, max 500 chars.
- Audit-log placeholder section reads "Audit log available in v0.2" (deferred per scope decision).

---

## 10. System Architecture & Integration

### 10.1. Route layer
- `routes/web.php`: `workspaces.admin.kill-switch.edit` + `.update` inside `workspace.member` group.

### 10.2. Config layer
- `config/cdv-rabbit.php` is a top-level config file; `config/cdv-rabbit/` is also a directory (prompts/ + schemas/). Both coexist — Laravel's `config:show` walks the tree.

### 10.3. Integration touch points
- `ai-code-review-pipeline.md` — `ReviewPullRequestJob::handle()` checks both flags FIRST (FR-04).
- `cost-management-and-ceiling-alerts.md` — independent; the kill switch fires before cost reservation.
- `review-dashboard.md` — sidebar surfaces the kill-switch state via the shared prop `currentWorkspaceKillSwitchEnabled`.

---

## 11. Validation: Acceptance Criteria & Test Strategy

- **AC8** — Kill switch halts dispatch within 10s. Tests:
  - `tests/Feature/Jobs/ReviewPullRequestJobTest.php` (kill_switch_enabled = true → no LLM call).
  - `tests/Feature/Review/KillSwitchE2eTest.php` (toggle column + dispatch + assert no Anthropic call).
  - `tests/Feature/Admin/KillSwitchControllerTest.php` (UI path: 8 cases including global env flag).

Authorization:
- `tests/Feature/Phase4/UiAuthorizationTest.php` — non-admin → 403; cross-workspace → 404.

---

## 12. Telemetry, Observability & Evaluation Metrics

- Each kill switch flip logs to the Laravel log channel with actor + reason + before/after.
- `Review.status = Skipped + error_class = 'kill_switch'` is the runtime evidence that jobs are being short-circuited; admins can query the dashboard to confirm.

---

## 13. Security, Privacy & Compliance

- Admin-only authorization via FormRequest.
- No customer code in the audit log (only metadata: actor + reason text + state).
- Global env flag is operator-only — workspace admins cannot affect other tenants.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Workspace admins can pause their own workspace freely | Accepted (it's their tenant). |
| Global env flag requires worker restart for env reload | Documented operational caveat. |
| No time-bounded kill ("auto-resume after N hours") | Backlog. |
| No alert when kill is on > 24h | Backlog. |
| Audit log not surfaced in UI | Deferred to v0.2; logs are queryable today. |

Open: should we additionally require a second admin to UN-kill a paused workspace (4-eyes principle)? (Backlog — adds friction; defer until pilot signals demand.)

---

## 15. Change Log

- **v1.0 (2026-05-14 / phase-4-complete)** — Initial Phase 4 implementation. Commits: `e44c14b` (W4-T3 backend + UI + config), W4-T4 verifier commit (8 AC8 + auth tests). 288/874 tests green at tag.
