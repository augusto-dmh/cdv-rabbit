# GitHub Cloud Integration

## 1. Overview

### 1.1. Feature Name
**GitHub Cloud Integration — App Install, Setup URL Callback, Webhook, Repository Lifecycle**

### 1.2. One-sentence summary
cdv-rabbit connects to GitHub Cloud through a registered GitHub App, exchanges a per-Workspace `installation_id` for short-lived installation access tokens minted from an RS256 JWT, ingests `pull_request.opened` events on a single App-level webhook URL with per-App HMAC-SHA256 verification, and reuses the same `ScmDriverInterface` plumbing as the Bitbucket driver so the rest of the review pipeline is provider-agnostic.

### 1.3. Primary outcome
A real PR opened against a repository owned by a workspace whose `scm_provider=github_cloud` produces a `webhook_deliveries` row with `scm_provider='github_cloud'` and `status='dispatched'`, dispatches a `ReviewPullRequestJob` within ~2 seconds, and posts an AI review comment back on the PR signed by the GitHub App's bot identity within ~60 seconds — using the same `CommentPoster`, telemetry, and cost-reservation code paths that run for the Bitbucket driver.

### 1.4. Version & owner
`v1.0 – Phase 6 (tag phase-6-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
GitHub Cloud is the second SCM target shipped after the MVP, promoted out of the `AGENTS.md §8` backlog in Phase 6. The Bitbucket-Cloud-only assumption baked into the MVP was lifted by `specs/multi-scm-provider-support.md` (column renames, `ScmDriverInterface`, factory, per-provider webhook controllers); this spec is the GitHub-specific half — every concrete API call, env var, and webhook payload assumption that only applies on the GitHub side.

### 2.2. Problem statement
GitHub's authentication and webhook conventions diverge from Bitbucket's in ways that matter:

- Authentication is **GitHub App**-based, not user-token-paste: there is no equivalent to BB's "Atlassian API token" model. Tokens are minted, per installation, by exchanging a short-lived App-level JWT for a 1-hour installation access token.
- Webhooks are **App-level**, not per-repo: GitHub posts every event to the single Webhook URL set on the App, signed with a single per-App secret. There is no per-repo webhook to register, and no per-Workspace `webhook_secret` like the BB flow has.
- The **install flow** has no equivalent to BB's "paste a token in a form": the admin must complete an OAuth-like browser round-trip through `github.com/apps/{slug}/installations/new` and be redirected back to the App's configured **Setup URL** carrying `installation_id` and `setup_action`.

A naïve attempt to make the GitHub driver pretend to be a BB clone (per-Workspace secret, signed state token tacked onto the install URL, paste-a-token wizard) breaks against these primitives. This spec describes the GitHub-shaped path cdv-rabbit takes instead.

### 2.3. Goals
- **G1**: Authenticate every GitHub API call as the GitHub App via `Authorization: token <installation-access-token>`, where the installation access token is minted lazily and cached for 50 minutes (GitHub's natural lifetime is 60 minutes; refresh early).
- **G2**: Mint the App-level JWT with PHP's native `openssl_sign` (RS256, 10-minute TTL, 60-second `iat` clock-skew tolerance) — no external JWT dependency.
- **G3**: Install flow uses the GitHub App **Setup URL** (not OAuth Callback URL): the admin clicks "Install on GitHub" → cdv-rabbit stashes `{workspace_id, expires_at}` in the session under key `scm_github_install` → redirect to `github.com/apps/{slug}/installations/new` → GitHub redirects the browser to the Setup URL with `installation_id` → callback consumes the session marker via `session()->pull` (single-use, atomic) and binds the installation to the workspace.
- **G4**: One Workspace ↔ one `installation_id` at a time, enforced by `unique` on `workspaces.github_installation_id` and a 409 in the callback when an `installation_id` is already linked to another workspace.
- **G5**: Webhook receiver validates `X-Hub-Signature-256` HMAC-SHA256 against the per-App `GITHUB_APP_WEBHOOK_SECRET` env value before any DB write or job dispatch.
- **G6**: `installation.deleted` events are first-class — `GithubInstallationManager::handleUninstall` nullifies `github_installation_id`, marks workspace `unhealthy`, disables all of the workspace's repositories, and forgets the cached installation token. Idempotent.
- **G7**: All other events (PR `synchronize`, `closed`, etc.; push; review threads; releases) are explicitly ignored with 202 — kept out of scope until v0.2 incremental review and v0.2 bot commands ship.

### 2.4. Non-goals / out of scope
- GitHub Enterprise Server (self-hosted) — backlog v1.1+, requires per-instance App registration and `base_url` per Workspace.
- OAuth-during-install flow (Callback URL with `code` exchange) — explicitly rejected in favour of the Setup URL flow; see §14 for the trade-off note.
- Fine-grained PAT or classic PAT as an alternative auth — rejected by `docs/adr/0002-github-app-as-github-scm-auth-model.md`.
- GitHub Reviews API (`POST /pulls/{n}/reviews` with `event: APPROVE | REQUEST_CHANGES`) — backlog; v1.0 posts inline + summary comments only via the issues/comments and pulls/comments endpoints.
- GitHub Check Runs API — backlog.
- `pull_request.synchronize` (push after open) — backlog v0.2 (incremental review).
- Bot commands (`@cdv-rabbit review/pause`) — backlog v0.2.
- `installation.suspend` / `installation.new_permissions_accepted` — ignored for v1.0; revisit if pilot customers hit them.

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Services/Scm/GithubDriver.php` — implements `ScmDriverInterface` (per `multi-scm-provider-support.md` §3.1). 11 contract methods plus `lastRateLimit()`. Maps GitHub Pulls/Issues/Repositories APIs to normalised DTOs.
- `app/Services/Scm/Github/JwtSigner.php` — App-level RS256 JWT minter using `openssl_sign`. No external JWT library.
- `app/Services/Scm/Github/InstallationTokenCache.php` — exchanges the App JWT for a per-installation access token via `POST /app/installations/{id}/access_tokens` and caches it for 50 minutes under `scm:github:installation_token:{installation_id}`.
- `app/Services/Scm/Github/GithubInstallationManager.php` — handles `installation.deleted`. Nullifies the workspace's `github_installation_id`, marks `health=unhealthy`, sets every owned repository's `enabled=false`, and forgets the cached installation token. Idempotent on repeated delivery.
- `app/Http/Controllers/Scm/Github/InstallController.php` — three actions:
  - `show($workspace)` — renders `workspaces/ConnectGithub.vue`.
  - `start($workspace)` — admin-only; stashes `scm_github_install = {workspace_id, expires_at}` (10-minute TTL) in the session and returns `Inertia::location("https://github.com/apps/{slug}/installations/new")`.
  - `callback()` — configured as the GitHub App's **Setup URL**. Consumes the session marker via `session()->pull`, validates TTL, enforces 1:1 `installation_id` uniqueness, persists `installation_id`, calls `verifyCredentials()`, marks `health=healthy`, redirects to the workspace.
- `app/Http/Controllers/Github/WebhookController.php` — single invokable. HMAC-SHA256 verification against `GITHUB_APP_WEBHOOK_SECRET`, then either dispatches via `WebhookIngestionPipeline` (for `pull_request.opened`) or calls `GithubInstallationManager::handleUninstall` (for `installation.deleted`).
- `routes/scm/github.php` — `POST /scm/github/webhook`, `POST /scm/github/install/start/{workspace}`, `GET /scm/github/install/callback`. The first is csrf-exempt; the install routes live behind `auth + verified` middleware.
- `routes/web.php` — adds `GET /workspaces/{workspace:slug}/connect-github` under the existing `workspace.member` group, calling `InstallController::show`.
- `resources/js/pages/workspaces/ConnectGithub.vue` — Inertia page with two states (connected / not connected) and a `<Link method="post">` to the start endpoint.
- `resources/js/pages/workspaces/Show.vue` — branches the "Connect" CTA between Bitbucket and GitHub on the workspace's `scm_provider`.
- `resources/js/pages/workspaces/Index.vue` — provider radio (`bitbucket_cloud` / `github_cloud`) on the New Workspace form.
- `app/Providers/AppServiceProvider` — singleton bindings for `JwtSigner` and `InstallationTokenCache` reading `config('services.github.*')`.
- `config/services.php` — `github.base_url`, `github.app_id`, `github.app_slug`, `github.app_private_key`, `github.app_webhook_secret`.
- `config/cdv-rabbit.php` — `github_dpa_url` (LGPD check #10).
- Tests under `tests/Feature/Github/`, `tests/Feature/Scm/Github/`, `tests/Unit/Services/Scm/` covering AC29..AC38 GH-side.

### 3.2. Out-of-scope for this iteration
- A success toast on the workspace page after a healthy callback round-trip (UX parity gap with BB connect; documented in §14).
- `installation.suspend` handling (treat as ignored event in v1.0).
- Multi-installation per Workspace (rejected by `docs/adr/0001-strict-1-to-1-workspace-and-scm-owner.md`).
- App-private-key rotation tooling — operators rotate by replacing `GITHUB_APP_PRIVATE_KEY` and redeploying.

### 3.3. Dependencies
- GitHub App registered at `github.com/settings/apps`, with **Setup URL** pointing to `/scm/github/install/callback`, **Webhook URL** pointing to `/scm/github/webhook`, "Pull request" event subscribed, and the permissions listed in `docs/playbooks/github-pr-review-manual-test.md`.
- Env vars populated: `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET` (operational gate per `AGENTS.md §7`).
- `GITHUB_DPA_URL` populated whenever a `github_cloud` workspace exists (LGPD check #10).
- `multi-scm-provider-support.md` ratified (provides `ScmDriverInterface`, factory, DTOs, schema renames). This spec inherits all the abstraction decisions there.
- `ai-code-review-pipeline.md` — the `ReviewPullRequestJob` consumer of the SCM driver.
- ADRs 0001..0004 (cardinality, App-not-PAT, asymmetric HMAC, driver interface shape).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV admin (GitHub)** — clicks "Install on GitHub" in the workspace's Connect Github page, picks an org or user account on GitHub, picks repositories, lands back on cdv-rabbit with the workspace marked connected.
- **CDV developer** — opens a PR on a Workspace-enabled GitHub repository and expects an inline AI review within ~60s, posted from the GitHub App's bot identity (e.g. `cdv-rabbit-bot[bot]`).
- **GitHub App "cdv-rabbit-bot"** — the non-human identity that authenticates every API call cdv-rabbit makes against GitHub on behalf of an admin's installation.

### 4.2. Primary use cases
- As an admin on GitHub, I want to install the cdv-rabbit App on my org or personal account and have it auto-discover the repositories I selected, without registering individual repo webhooks.
- As a developer, I want my GitHub PR reviewed by the same logic, prompt, and LLM provider as my Bitbucket PR — the SCM choice should not change the review behaviour.
- As an admin, I want uninstalling the cdv-rabbit App in GitHub's settings page to cleanly stop all activity on the linked workspace, without requiring me to log into cdv-rabbit first.
- As an admin on a personal GitHub account (`account.type=User`), I want the install flow to accept my account exactly like an org install would.

### 4.3. User journey
Admin logs in → "Workspaces" → "+ New" form with `scm_provider=github_cloud` selected → workspace created with `github_installation_id IS NULL` → workspace Show page → "Connect GitHub" CTA → `ConnectGithub.vue` → "Install on GitHub" button POSTs to `/scm/github/install/start/{slug}` → backend stashes session marker, returns `Inertia::location(github.com/apps/{slug}/installations/new)` → Inertia client navigates the full browser to GitHub → admin picks account + repos + Install → GitHub redirects the browser to the App's Setup URL `/scm/github/install/callback?installation_id={id}&setup_action=install` → backend `pull`s the session marker, persists `installation_id`, calls `verifyCredentials()`, marks `health=healthy`, redirects to `/workspaces/{slug}` → admin sees the workspace connected. Later: developer opens a PR → GitHub POSTs to `/scm/github/webhook` with `X-Hub-Signature-256` → cdv-rabbit verifies, inserts `webhook_deliveries`, dispatches `ReviewPullRequestJob`, comment posted on PR.

---

## 5. Functional Requirements

**FR-01 — GitHub App authentication (App JWT → installation token)**
- `JwtSigner::mint()` produces an RS256 JWT with claims `{iat: now-60, exp: now+600, iss: app_id}` signed by `GITHUB_APP_PRIVATE_KEY` using `openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)`. No external JWT library.
- `InstallationTokenCache::tokenFor($installationId)` calls `POST /app/installations/{id}/access_tokens` with `Authorization: Bearer {jwt}`, `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, and caches the returned `token` for 50 minutes under `scm:github:installation_token:{id}`. Throws `RuntimeException` on failure (401/404/etc.).
- All `GithubDriver` API calls send `Authorization: token {installation-access-token}`.

**FR-02 — GitHub install flow (Setup URL + session marker)**
- `POST /scm/github/install/start/{workspace}` (auth: workspace admin) stashes `scm_github_install = {workspace_id, expires_at: now+600}` in the admin's session and returns `Inertia::location("https://github.com/apps/{GITHUB_APP_SLUG}/installations/new")`.
- The 302 (or Inertia 409 for XHR clients) is the only redirect; there is no custom `?state=...` query because GitHub's Setup URL flow does not preserve custom state.
- `GET /scm/github/install/callback` (configured as the App's **Setup URL** on github.com) consumes the session marker via `session()->pull('scm_github_install')`. Missing or expired marker → 403 JSON. Empty/missing `installation_id` query → 400 JSON.
- 1:1 enforcement (AC32): when another Workspace already has the same `github_installation_id`, return 409 with the existing workspace slug; preserve the original mapping; do not touch the requesting workspace.
- On success: persist `github_installation_id`, set `health=healthy`, call `GithubDriver::verifyCredentials()`. If the verifyCredentials call returns invalid, mark `health=unhealthy` but keep the mapping (so the admin can troubleshoot rather than re-install).
- Redirect to `workspaces.show` route on the workspace's slug.

**FR-03 — Webhook receiver (HMAC-SHA256, per-App secret)**
- `POST /scm/github/webhook` (csrf-exempt) is the App's single Webhook URL.
- Verify `X-Hub-Signature-256` header equals `sha256=` + `hash_hmac('sha256', raw_body, GITHUB_APP_WEBHOOK_SECRET)`, comparison via `hash_equals`. Missing or mismatched → 401 with no DB write.
- Dispatch by `X-GitHub-Event` header + payload `action`:
  - `pull_request` + `action=opened` → resolve repository by `repository.id` (matched against `repositories.scm_repo_id`); if the repo is unknown or disabled, return 202 ignored; otherwise hand off to `WebhookIngestionPipeline::ingestPullRequestCreated`.
  - `installation` + `action=deleted` → call `GithubInstallationManager::handleUninstall(installation.id)`; respond 202.
  - Anything else → respond 202 "event ignored", no DB write.
- Idempotency: the shared `WebhookIngestionPipeline` enforces uniqueness on `webhook_deliveries.scm_delivery_id` (sourced from `X-GitHub-Delivery`); duplicate delivery → 200 "duplicate", no second dispatch.

**FR-04 — `GithubDriver` (implements `ScmDriverInterface`)**
- 11 contract methods returning normalised DTOs. The `scmRepoId` parameter is the GitHub numeric repository id stored as a string.
- `verifyCredentials()` calls `GET /installation/repositories?per_page=1`. On 2xx, returns `CredentialCheck(valid=true, identity="gh-installation:{id}")`. On 4xx/5xx, returns invalid with the status code in `reason`.
- `listRepositories()` paginates `GET /installation/repositories?per_page=100` via the `Link: ...; rel="next"` header.
- `getPullRequest`, `getChangedFiles`, `getDiff` resolve both owner and repo name from `repositories.full_name` ("owner/name") captured at sync time, keyed by `scm_repo_id`. Two private helpers — `resolveOwner($scmRepoId)` and `resolveRepoName($scmRepoId)` — encapsulate the lookup and construct URLs like `/repos/{owner}/{repo}/pulls/{n}`. `workspaces.scm_owner_slug` is **not** consulted on the GH driver path (the column is Bitbucket-specific and stays null on GH workspaces).
- `getDiff()` requests `Accept: application/vnd.github.v3.diff` on the PR endpoint and returns the raw unified diff body.
- `postPullRequestComment()` posts to `POST /repos/{owner}/{repo}/issues/{n}/comments` (GitHub treats top-level PR comments as issue comments).
- `postInlineComment()` posts to `POST /repos/{owner}/{repo}/pulls/{n}/comments` with `{commit_id: payload.headSha, path, line, side: 'RIGHT', body}`. `side=RIGHT` is hardcoded — v1.0 only comments on additions/context.
- `updateComment()` uses `PATCH /repos/{owner}/{repo}/pulls/comments/{cid}`.
- `registerWebhook()` and `deleteWebhook()` are documented no-ops (the GitHub App emits webhooks to its configured URL automatically); both return immediately without an API call.
- `lastRateLimit()` exposes `{remaining, limit, reset}` captured from `X-RateLimit-*` response headers on the most recent call.

**FR-05 — `installation.deleted` handler**
- `GithubInstallationManager::handleUninstall($installationId)`:
  1. Look up Workspace by `github_installation_id`. If null, idempotent return (forget the cached token, log, exit).
  2. Update `github_installation_id=null`, `health=WorkspaceHealth::Unhealthy`.
  3. Bulk update `repositories.enabled=false` for every repo owned by the workspace (uses `Repository::withoutWorkspaceScope()`).
  4. `InstallationTokenCache::forget($installationId)` to drop any cached token.

**FR-06 — Workspace creation validation (AC27)**
- `CreateWorkspaceRequest` requires `scm_provider in:bitbucket_cloud,github_cloud`. Defined in `multi-scm-provider-support.md` §5 FR-01; restated here for completeness because a workspace cannot be born `github_cloud` without this rule.

### 5.2. Error and edge cases
- Admin opens the Setup URL directly (no preceding `start()` call): no session marker → 403.
- Admin starts the flow but takes longer than 10 minutes to complete on GitHub: `expires_at < now()` → 403; admin must restart from the workspace.
- Replayed Setup URL hit after a successful install (e.g. browser back-button refresh): the session marker was already consumed by the first hit → 403.
- `installation_id` already mapped to a different workspace: 409 with `existing_workspace_slug` in body; the admin must uninstall on GitHub first or use the other workspace.
- Webhook arrives for a `repository.id` that has no row in `repositories` (or has `enabled=false`): 202 ignored, no dispatch.
- App is suspended via `installation.suspend`: out of scope for v1.0; currently treated as ignored event. A pilot customer hitting this would still have `github_installation_id` populated but all API calls would 403 — `verifyCredentials` would surface this as `health=unhealthy` on next call.
- Installation token cache hit but token has been revoked on GitHub's side: the next API call returns 401; `InstallationTokenCache` does **not** auto-invalidate. Currently surfaces as job failure; backlog: detect 401 and re-mint once.

---

## 6. Non-Functional Requirements

- **Reliability**: GitHub retries failed webhooks with its own backoff (we return 5xx only on internal errors). Idempotency on `scm_delivery_id` prevents duplicate review dispatch.
- **Performance**: webhook handler completes in <300ms p95 (HMAC verify + 2 DB writes + queue push). API calls inside `ReviewPullRequestJob` are bounded by GitHub's 5000 req/h primary rate limit (App-installation tier).
- **Install session TTL**: 10 minutes. Long enough for a real install on GitHub, short enough that an abandoned flow doesn't linger in the session.
- **Installation token cache TTL**: 50 minutes (GitHub's tokens live 60; refresh early to avoid edge expiry mid-request).
- **App JWT TTL**: 10 minutes (GitHub's maximum). Re-minted on each token exchange — JWTs are not cached.
- **Security**: HMAC primary auth with `hash_equals` timing-safe; private key only in env; session marker single-use via `session()->pull()`.

---

## 7. Data Model & Contracts

### 7.1. Schema touch points
All schema changes for GH support landed in Phase 6 (`multi-scm-provider-support.md` §7). The GitHub-specific subset:

- `workspaces.github_installation_id` — `string unique nullable`. Populated by `InstallController::callback`. Nullified by `GithubInstallationManager::handleUninstall`.
- `workspaces.scm_provider = 'github_cloud'` — enum value (string) selected at workspace creation and immutable.
- `repositories.scm_repo_id` — stores GitHub's numeric repository id as a string. Used as the URL component the webhook controller matches against `repository.id` from the payload.
- `webhook_deliveries.scm_provider = 'github_cloud'` — populated by the GitHub `WebhookController` for self-describing audit logs.
- `webhook_deliveries.scm_delivery_id` — populated from the `X-GitHub-Delivery` header; unique across the table (GH UUIDs and BB UUIDs do not collide in practice).

### 7.2. Env vars consumed
| Var | Purpose | Where read |
|---|---|---|
| `GITHUB_APP_ID` | App-level identifier (numeric, decimal string) | `JwtSigner::__construct` |
| `GITHUB_APP_SLUG` | URL slug under `github.com/apps/{slug}` | `InstallController::start` |
| `GITHUB_APP_PRIVATE_KEY` | RSA private key (PEM, multi-line) | `JwtSigner::__construct` |
| `GITHUB_APP_WEBHOOK_SECRET` | Per-App webhook signing secret | `Github\WebhookController::__invoke` |
| `GITHUB_BASE_URL` | Override for GHES (v1.1+), defaults `https://api.github.com` | `InstallationTokenCache`, `GithubDriver` |
| `GITHUB_DPA_URL` | LGPD check #10 declaration | `LgpdCheckCommand::checkGithubDpaUrl` |

### 7.3. GitHub API contracts consumed
- `POST /app/installations/{installation_id}/access_tokens` (App JWT auth) — installation token exchange.
- `GET /installation/repositories` (installation token auth, paginated) — `listRepositories` + `verifyCredentials`.
- `GET /repositories/{id}` — alternative repo lookup (currently unused beyond DTO mapping symmetry).
- `GET /repos/{owner}/{repo}/pulls/{n}` — pull request metadata.
- `GET /repos/{owner}/{repo}/pulls/{n}` with `Accept: application/vnd.github.v3.diff` — raw unified diff.
- `GET /repos/{owner}/{repo}/pulls/{n}/files` (paginated) — changed files for size pre-flight.
- `POST /repos/{owner}/{repo}/issues/{n}/comments` — top-level PR comment.
- `POST /repos/{owner}/{repo}/pulls/{n}/comments` — inline review comment with `commit_id`.
- `PATCH /repos/{owner}/{repo}/pulls/comments/{cid}` — comment update.

### 7.4. Webhook event payloads consumed
- `pull_request` event with `action=opened` — primary review trigger.
- `installation` event with `action=deleted` — uninstall lifecycle.
- All other events (`pull_request.synchronize/closed/...`, `push`, `pull_request_review`, etc.) — 202 ignored.

### 7.5. Session marker format
- Key: `scm_github_install`
- Value: `['workspace_id' => int, 'expires_at' => unix_ts]`
- Lifetime: 10 minutes (`expires_at = time() + 600` at stash time)
- Consumption: `$request->session()->pull('scm_github_install')` — atomic read-and-remove, single-use by construction.

---

## 8. AI/Agent Design

Not directly. `ReviewPullRequestJob` is provider-agnostic via `ScmDriverFactory`: it sees `ScmDriverInterface`, never `GithubDriver`. The system prompt, tool schema, prompt caching, secret redaction, and cost-reservation logic are unchanged from the Bitbucket path. The only LLM-shaped detail is that `InlineCommentPayload::$headSha` is consumed by `GithubDriver::postInlineComment` (GitHub requires `commit_id` on inline comments; Bitbucket ignores it).

---

## 9. UX & Interaction Design

- `pages/workspaces/Index.vue` "New workspace" form gains a Bitbucket Cloud / GitHub Cloud radio. Default focus on Bitbucket Cloud (current customer base).
- `pages/workspaces/Show.vue` branches the connect CTA on `scm_provider`:
  - `bitbucket_cloud` → `<Link>` to the existing `ConnectController::edit` (token paste wizard).
  - `github_cloud` → `<Link>` to `/workspaces/{slug}/connect-github` (`InstallController::show`).
- `pages/workspaces/ConnectGithub.vue` — two states:
  - Not connected (`github_installation_id IS NULL`): explainer + a single Inertia `<Link method="post">` button targeting `/scm/github/install/start/{slug}`. POST triggers an Inertia XHR; backend returns `Inertia::location(github.com/apps/...)` → 409 + `X-Inertia-Location` → client navigates the full browser to GitHub.
  - Connected (`github_installation_id` populated): green confirmation card showing the installation id, with a note that uninstalling is done on GitHub's settings page (the App emits `installation.deleted` back to us automatically).
- No success toast yet after a healthy callback round-trip (UX parity gap with BB connect; tracked in §14).
- All backend calls go through Wayfinder typed routes regenerated by `php artisan wayfinder:generate` after any route addition.

---

## 10. System Architecture & Integration

### 10.1. Route registration
- `routes/scm/github.php` registers:
  - `POST /scm/github/webhook` (csrf-exempt, no auth) → `Github\WebhookController`.
  - `POST /scm/github/install/start/{workspace}` (auth + verified) → `InstallController::start`.
  - `GET /scm/github/install/callback` (auth + verified) → `InstallController::callback`.
- `routes/web.php` registers:
  - `GET /workspaces/{workspace:slug}/connect-github` (auth + verified + workspace.member) → `InstallController::show`.
- `bootstrap/app.php` `withRouting()->then` callback groups `routes/scm/github.php` under the `web` middleware alongside `routes/bitbucket.php`.

### 10.2. Provider bindings
- `AppServiceProvider::register()` binds:
  - `JwtSigner::class` as singleton constructed with `config('services.github.app_id')` + `config('services.github.app_private_key')`.
  - `InstallationTokenCache::class` as singleton constructed with the `JwtSigner` and `config('services.github.base_url')`.
- `ScmDriverFactory::make($workspace)` (from `multi-scm-provider-support.md`) routes `github_cloud` workspaces to `GithubDriver`, which auto-resolves `InstallationTokenCache` via container injection.

### 10.3. Integration touch points
- `multi-scm-provider-support.md` — provides the `ScmDriverInterface` contract, DTO types, factory, schema renames, and the shared `WebhookIngestionPipeline` this driver hooks into.
- `bitbucket-cloud-integration.md` — parallel SCM driver; this spec is the GH-side equivalent. Both share the same downstream `ReviewPullRequestJob` and `CommentPoster`.
- `ai-code-review-pipeline.md` — consumer of the SCM driver. The job's contracts (prompt caching, strict tool use, 25-comment cap, AI marker prefix) apply identically.
- `tenancy-and-workspace-isolation.md` — `EnsureWorkspaceMember` guards the install routes; the webhook route lives outside the workspace.member group (workspace is resolved from the payload's `repository.id`).
- `lgpd-data-protection-posture.md` — `GITHUB_APP_PRIVATE_KEY` and `GITHUB_APP_WEBHOOK_SECRET` are env-only and never DB-persisted. `github_installation_id` is non-sensitive (an integer public id) and stored unencrypted.
- `lgpd-compliance-tooling.md` — `LgpdCheckCommand` check #10 fails when any `github_cloud` workspace exists but `GITHUB_DPA_URL` is unset.
- `cost-management-and-ceiling-alerts.md` — per-Workspace daily token ceiling applies independently of `scm_provider`.

---

## 11. Validation: Acceptance Criteria & Test Strategy

GitHub-specific subset of the AC matrix introduced by `multi-scm-provider-support.md` §11:

| AC | Statement | Test file |
|---|---|---|
| **AC29** | `POST /scm/github/install/start` returns 302 to `github.com/apps/{slug}/installations/new` (no `?state=`) and stashes `{workspace_id, expires_at}` in session under `scm_github_install`. | `tests/Feature/Scm/Github/InstallControllerTest.php` |
| **AC30** | Callback with valid session marker + `installation_id` persists `installation_id`, calls `verifyCredentials()`, marks `health=healthy`. User-account installations accepted without 422. | `tests/Feature/Scm/Github/InstallControllerTest.php` |
| **AC31** | Callback with missing / expired / replayed session marker → 403. No persistence. | `tests/Feature/Scm/Github/InstallControllerTest.php` (three distinct cases) |
| **AC32** | Callback where `installation_id` is already mapped to another workspace → 409; original mapping preserved. | `tests/Feature/Scm/Github/InstallControllerTest.php` |
| **AC33** | `pull_request.opened` with valid `X-Hub-Signature-256` → `webhook_deliveries` row inserted with `scm_provider=github_cloud`; `ReviewPullRequestJob` dispatched within 2s. | `tests/Feature/Github/WebhookControllerTest.php` |
| **AC34** | Missing or mismatched HMAC → 401; no DB write; no dispatch. | `tests/Feature/Github/WebhookControllerTest.php` |
| **AC35** | Duplicate `X-GitHub-Delivery` → 200 "duplicate"; no second `webhook_deliveries` row; no duplicate job dispatch. | `tests/Feature/Github/WebhookControllerTest.php` |
| **AC36** | `installation.deleted` → nullifies `github_installation_id`, marks `health=unhealthy`, disables every owned repo; idempotent on repeated delivery. | `tests/Feature/Github/WebhookControllerTest.php` |
| **AC37 (GH half)** | `ScmDriverFactory::make` resolves `GithubDriver` for `scm_provider=github_cloud`. | `tests/Unit/Services/Scm/ScmDriverFactoryTest.php` |
| **AC38** | `ReviewPullRequestJob` source contains no provider-specific symbols — no `bitbucket_cloud`, no `github_cloud`, no concrete driver class names. (Both providers go through the interface.) | `tests/Unit/Services/Scm/ScmArchTest.php` |

Supplementary tests:
- `tests/Unit/Services/Scm/GithubDriverTest.php` — `verifyCredentials` happy/401 paths; pagination via Link header; inline comment payload assertions; JwtSigner mint verified against a generated public key via `openssl_verify`; rate-limit header capture; `registerWebhook` / `deleteWebhook` no-op semantics.
- `tests/Feature/Scm/Phase6AcMatrixTest.php` — grep-based scanner asserting every AC27..AC38 has a named test reference somewhere in `tests/`.

Manual smoke: `docs/playbooks/github-pr-review-manual-test.md` against a real repository (`augusto-dmh/DocInt`).

---

## 12. Telemetry, Observability & Evaluation Metrics

- Every `GithubDriver` API call writes a structured log entry to the `bitbucket` channel (channel name retained for backward compatibility; a follow-up rename to `scm` is tracked in `multi-scm-provider-support.md` §14).
- GitHub rate-limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Limit`, `X-RateLimit-Reset`) captured per call and exposed via `GithubDriver::lastRateLimit()`.
- `webhook_deliveries.scm_provider='github_cloud'` makes the per-event audit trail self-describing without a join.
- `reviews_llm_calls` is unaffected: `provider` there refers to the LLM provider (anthropic | openai), orthogonal to `scm_provider`.
- Future health endpoint check: `scm_github_app` reachability (mirroring the existing Anthropic/OpenAI checks); not in v1.0.

---

## 13. Security, Privacy & Compliance

- **Per-App webhook secret** (`GITHUB_APP_WEBHOOK_SECRET`) — env-only, never DB-persisted. Rotation requires updating GitHub's App settings and redeploying with the new env value; brief mismatch window during rotation is accepted operational cost (`AGENTS.md §4` locked contract reflects the per-App scheme).
- **App private key** (`GITHUB_APP_PRIVATE_KEY`) — env-only PEM. Rotation invalidates all live installation tokens by virtue of next JWT-mint failing (signature mismatch on GitHub's side); cache holds them up to 50 minutes, which is acceptable.
- **Session marker** — single-use via `session()->pull`, 10-minute TTL, bound to the admin's authenticated session cookie which is domain-scoped to `APP_URL`. No HMAC/nonce machinery needed beyond what Laravel's session already provides.
- **1:1 binding** — `unique` on `workspaces.github_installation_id` plus the callback's 409 prevents two workspaces from claiming the same installation.
- **`github_installation_id`** — stored unencrypted (non-secret integer-shaped public id).
- **LGPD posture** — no diff at rest (the `GithubDriver::getDiff` result lives in a local variable inside `ReviewPullRequestJob::handle`, never persisted). `RedactingFailedJobProvider` carries over from MVP. `GITHUB_DPA_URL` declaration enforced by `rabbit:lgpd-check` #10 whenever a `github_cloud` workspace exists.
- **Bot identity** — comments post as the App (e.g. `cdv-rabbit-bot[bot]`), never tied to a human user account.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Setup URL flow doesn't carry custom `state` — session-marker compensates but only works for the original admin's browser | Accepted; documented in `docs/adr/0002-github-app-as-github-scm-auth-model.md` + this spec §5 FR-02 |
| OAuth-during-install (Callback URL with `code` exchange) would let any logged-in admin complete the flow but adds an OAuth round-trip cost; rejected | Documented; see §2.4 |
| Webhook secret can't be rotated per-Workspace | Accepted; same shape as any per-app shared secret |
| `installation_id` race during concurrent callbacks | Mitigated by `unique` DB constraint — second insert fails at the DB level even if the app-level check both pass |
| GitHub App suspended via `installation.suspend` | Out of scope for v1.0; treated as ignored event. Track if a pilot customer triggers it |
| Channel rename `bitbucket` → `scm` not done | Tracked in `multi-scm-provider-support.md` §14 |

Open questions:

- **`scm_owner_slug` on GH workspaces**: the column is Bitbucket-specific and the GH driver no longer reads it (owner is derived from `repositories.full_name` at request time). The column is left null on GH workspaces. Open question is whether to repurpose it for display/audit (e.g. showing the org name in the workspace header) — defer until a concrete UI need surfaces.
- **No success toast on the workspace page after a healthy callback**: UX parity gap with BB connect. A small `Inertia::flash('toast', ...)` before the redirect would close this.
- **`installation_id` cache invalidation on 401**: when GitHub revokes a token mid-cache-TTL (e.g. admin re-installs with new permissions), the next API call 401s and the job fails. Backlog: detect 401 in driver, call `InstallationTokenCache::forget`, retry once.
- **`installation.suspend` / `installation.new_permissions_accepted` events**: silently ignored today. Revisit if pilot use surfaces a need.
- **GitHub Enterprise Server**: `GITHUB_BASE_URL` is already a config knob, but the App-per-instance assumption and on-prem certs aren't tested. Backlog v1.1+.

---

## 15. Change Log

- **v1.0 (2026-05-16 / `phase-6-complete`)** — Initial GitHub Cloud integration shipped as Phase 6 of the multi-SCM-provider work. Commits: `9efd470` (GithubDriver + JwtSigner + InstallationTokenCache, W6-T4), `19d2df8` (Github WebhookController + WebhookIngestionPipeline + GithubInstallationManager, W6-T5), `a60debb` (InstallController + scm_provider immutability, W6-T6 — superseded by `81fb19e` for the install-flow refactor), `1630399` (Phase 6 verifier — arch invariants + AC matrix, W6-T7), `32be80f` (LGPD check #10 + frontend wizard + AGENTS.md update), `12a73f2` (Inertia::location fix for the install start), `81fb19e` (state-token → session-marker refactor — current install-flow contract). 397/1199 Pest tests green at tag.
