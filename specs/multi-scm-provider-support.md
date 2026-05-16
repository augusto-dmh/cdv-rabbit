# Multi-SCM Provider Support (Bitbucket Cloud + GitHub Cloud)

## 1. Overview

### 1.1. Feature Name
**Multi-SCM Provider Support — Workspace-Scoped Provider Selection (Bitbucket Cloud + GitHub Cloud)**

### 1.2. One-sentence summary
A Workspace admin chooses an SCM Provider (`bitbucket_cloud` or `github_cloud`) at Workspace creation, immutable afterwards; both providers are accessed through a single `ScmDriverInterface` returning normalized DTOs, with the Bitbucket flow retaining the existing API-token + service-account model and the GitHub flow using a GitHub App with a per-Workspace installation, while the locked HMAC contract becomes per-Workspace for BB and per-App for GitHub.

### 1.3. Primary outcome
An admin creates a Workspace with `scm_provider=github_cloud`, completes the GitHub App install flow, picks one repository from the installation-scoped sync, and opens a PR against `main` — cdv-rabbit posts inline AI-generated review comments within ~60 seconds, identically in shape and timing to the existing Bitbucket flow. Switching providers on an existing Workspace is impossible; customers migrating create a new Workspace and leave the prior Workspace as a historical archive.

### 1.4. Version & owner
`v1.0 – Phase 6 (planned, post-MVP). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
The MVP shipped Bitbucket Cloud only (`specs/bitbucket-cloud-integration.md`, Phase 2, `phase-2-complete`). The MVP plan §11 listed "GitHub / GitLab driver — v1.0+" as backlog and `AGENTS.md §8` flagged the same. With MVP code complete (`phase-5-complete` / `v0.1.0-mvp`) and operational sign-offs pending production, Phase 6 promotes GitHub Cloud out of backlog into the first post-MVP feature. The work is spec-first: implementation begins only after this spec, its ADRs (0001..0004), and the AGENTS.md updates are approved. The existing `multi-llm-provider-support.md` is the structural precedent — both features share the "second provider" shape (driver interface, factory, per-Workspace selection, parity tests).

### 2.2. Problem statement
The current codebase is Bitbucket-shaped at every layer: `app/Services/Bitbucket/BitbucketClient.php` returns BB-shaped JSON arrays; `ReviewPullRequestJob` reads `$pr['source']['branch']['name']` style paths; schema columns are `bitbucket_uuid`, `bitbucket_workspace_slug`, etc.; the webhook controller hard-codes BB's headers and event names; the connect wizard hard-codes the token-paste UX. Adding GitHub without an abstraction layer would mean parallel duplicated code branches; adding it badly (e.g. trying to make `GithubClient` pretend to be BB-shaped) would propagate provider-leak through every downstream caller. The fix is a normalized boundary at the `ScmDriverInterface`, schema renames to drop provider names from column identifiers, and per-provider webhook controllers that share only the post-validation `WebhookIngestionPipeline`.

### 2.3. Goals
- **G1**: A Workspace is born with an SCM Provider chosen at creation (`scm_provider` enum, default `bitbucket_cloud`, never editable).
- **G2**: `ScmDriverInterface` exposes a flat wide surface returning normalized DTOs (`RepositoryDto`, `PullRequestDto`, `FileChangeDto`, `CommentHandle`, `WebhookHandle`, `CredentialCheck`); provider-shaped JSON dies inside drivers.
- **G3**: `ScmDriverFactory::make(Workspace)` mirrors `LlmDriverFactory`; resolved at job runtime inside `ReviewPullRequestJob::handle()`.
- **G4**: `BitbucketClient` is renamed and reshaped to `BitbucketDriver implements ScmDriverInterface` in `app/Services/Scm/`; the `app/Services/Bitbucket/` directory is removed.
- **G5**: `GithubDriver` authenticates via the GitHub App installation flow: app-level RSA private key in env, per-Workspace `github_installation_id`, short-lived installation access tokens minted via JWT.
- **G6**: Webhook ingestion is split per provider: `Bitbucket/WebhookController` (existing, intact) and `Github/WebhookController` (new) share a `WebhookIngestionPipeline` for delivery insert + job dispatch; HMAC verification is per-provider, single linear path each.
- **G7**: Schema renames generalize provider names: `bitbucket_workspace_slug → scm_owner_slug`, `repositories.bitbucket_uuid → scm_repo_id`, `webhook_deliveries.bitbucket_uuid → scm_delivery_id`, `repositories.webhook_uuid → scm_webhook_uuid`, `repositories.full_slug → full_name`. New columns: `workspaces.scm_provider`, `workspaces.github_installation_id` (unique nullable), `webhook_deliveries.scm_provider`.
- **G8**: `ReviewPullRequestJob` is provider-agnostic — same `ReviewResultDto`, same `CommentPoster`, same `reviews_llm_calls` telemetry, same cost ceiling, same redaction posture for both providers.

### 2.4. Non-goals / out of scope
- GitHub Enterprise Server (self-hosted) — backlog v1.1+, base URL + App-per-instance differ.
- Bitbucket Data Center — backlog v1.1+, same shape rationale.
- OAuth App as an alternative to GitHub App — rejected, see ADR 0002.
- Fine-grained / classic PAT as an alternative to GitHub App — rejected, see ADR 0002.
- Multiple installations or multiple SCM Owners per Workspace — rejected, see ADR 0001.
- GitHub Reviews API (formal review submission with `APPROVE/REQUEST_CHANGES`) — backlog; MVP-equivalent posts inline comments only.
- GitHub Check Runs API for status badges — backlog.
- GitHub code suggestions (commit patches) — already backlog v1.0+ as "Autofix" in `AGENTS.md §8`.
- Bot commands (`@cdv-rabbit review/pause`) — already backlog v0.2.
- `pull_request.synchronize` (push-after-open / incremental review) — already backlog v0.2.
- Migration tooling to move reviews/comments from a BB Workspace to a GH Workspace — manual; create new Workspace.
- Workspace transfer between GitHub orgs — manual.

---

## 3. Scope

### 3.1. In-scope functionality
- New `App\Services\Scm\Contracts\ScmDriverInterface` (11 methods: `verifyCredentials`, `listRepositories`, `getRepository`, `getPullRequest`, `getChangedFiles`, `getDiff`, `postPullRequestComment`, `postInlineComment`, `updateComment`, `registerWebhook`, `deleteWebhook`), plus a `lastRateLimit()` accessor on each driver implementation for observability.
- New DTOs in `App\Services\Scm\Dto\`: `RepositoryDto`, `PullRequestDto`, `FileChangeDto`, `InlineCommentPayload`, `CommentHandle`, `WebhookHandle`, `CredentialCheck`.
- `App\Services\Scm\BitbucketDriver` (rename + reshape of existing `BitbucketClient`).
- `App\Services\Scm\GithubDriver` (new) with `App\Services\Scm\Github\JwtSigner` and `App\Services\Scm\Github\InstallationTokenCache` helpers.
- `App\Services\Scm\ScmDriverFactory` mirroring `LlmDriverFactory`.
- `App\Enums\ScmProvider` backed string enum (`bitbucket_cloud`, `github_cloud`).
- Schema migrations (see §7) — 3 migrations, one per affected table.
- `App\Http\Controllers\Scm\Github\InstallController` (start + callback actions).
- Session-backed install marker: `start()` stashes `{workspace_id, expires_at}` in the user's session under key `scm_github_install`, consumed (single-use) by `callback()` via `session()->pull(...)`. No signed state token is needed because GitHub's Setup URL flow (which we use, see FR-02) does not preserve a custom `state` query param.
- `App\Http\Controllers\Github\WebhookController` (HMAC-256 + idempotency + dispatch; also handles `installation.deleted`).
- `App\Services\Webhook\WebhookIngestionPipeline` (shared by BB and GH controllers post-validation).
- `App\Services\Scm\Github\GithubInstallationManager` (handles uninstall: nullify ID, mark unhealthy, disable repos).
- Frontend: provider radio added to `pages/workspaces/Index.vue` create form; `pages/workspaces/Connect.vue` branches on `scm_provider`; new `pages/workspaces/ConnectGithub.vue`.
- `app/Http/Requests/Workspaces/CreateWorkspaceRequest` ratifies `scm_provider`.
- `app/Http/Requests/Workspaces/UpdateWorkspaceRequest` rejects `scm_provider` changes (422).
- Pest tests in `tests/Feature/Scm/Github/` (install controller, webhook controller, installation lifecycle) and `tests/Unit/Services/Scm/` (driver factory, both drivers, DTO mapping).
- New env: `GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_SLUG` (for redirect URL).
- `routes/scm/github.php` (install + callback) — registered alongside existing `routes/bitbucket.php` (webhook).

### 3.2. Out-of-scope for this iteration
- See §2.4. Specifically: no GHES/BBDC, no OAuth/PAT, no Reviews/Checks API, no migration tooling, no bot commands.

### 3.3. Dependencies
- `firebase/php-jwt` or equivalent for App JWT minting (subject to dependency approval per `CLAUDE.md`).
- GitHub App "cdv-rabbit-bot" registered on GitHub (operational sign-off — see `AGENTS.md §7`).
- Decisions ratified in ADR 0001, 0002, 0003, 0004 (this folder).
- `CONTEXT.md` glossary entries for **Workspace**, **SCM Provider**, **SCM Owner**, **LLM Provider** (committed alongside this spec).
- `LlmDriverFactory` from `multi-llm-provider-support.md` (structural precedent).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV admin (Bitbucket)** — connects a Workspace by pasting an Atlassian API token and a service account login; identical flow to the MVP.
- **CDV admin (GitHub)** — clicks "Install on GitHub" in the connect wizard, picks an org or user account on GitHub, picks repositories, lands back on cdv-rabbit with the Workspace connected.
- **CDV developer** — opens a PR on a Workspace-enabled repository on either provider and sees an inline AI review within ~60s, posted from a stable bot identity.
- **GitHub App "cdv-rabbit-bot"** — the non-human bot identity that posts comments and consumes the webhook stream for every install.

### 4.2. Primary use cases
- As an admin on GitHub, I want to install the cdv-rabbit App on my org and have it auto-discover repos so I don't have to register webhooks manually.
- As an admin migrating off Bitbucket, I want a new Workspace on GitHub without losing access to my BB Workspace's historical reviews.
- As a developer, I want my GitHub PR reviewed by the same logic as my Bitbucket PR (same prompt, same models, same comment format).
- As an admin, I want uninstalling the cdv-rabbit App on GitHub to cleanly stop all activity on the linked Workspace without requiring me to log into cdv-rabbit first.
- As an admin on a personal GitHub account (not an org), I want to install the App and run reviews on my personal repos.

### 4.3. User journey (GitHub flow)
Admin logs in → "Workspaces" → "+ New" form (now with `scm_provider` radio: Bitbucket / GitHub) → picks GitHub, submits → Workspace exists with `scm_provider=github_cloud`, no `installation_id` yet → admin opens Show page → "Connect GitHub" CTA → wizard step 1 explainer → step 2 button "Install on GitHub" → redirect to `github.com/apps/cdv-rabbit-bot/installations/new?state={signed}` → admin picks org/account + repos on GitHub → GitHub redirects to `/scm/github/install/callback?installation_id={id}&setup_action=install&state={signed}` → backend validates state, persists `installation_id`, calls `verifyCredentials()`, marks `health=healthy` → step 3 sync repos (installation-scoped) → admin toggles repo enabled → no per-repo webhook registration (App handles it) → row stays `enabled=true`. Later: developer opens PR → GitHub POSTs to `/scm/github/webhook` with `X-Hub-Signature-256` → cdv-rabbit verifies against `GITHUB_APP_WEBHOOK_SECRET` → delivery row inserted → `ReviewPullRequestJob` dispatched.

---

## 5. Functional Requirements

**FR-01 — Provider selection at Workspace creation**
- `POST /workspaces` body requires `scm_provider in:bitbucket_cloud,github_cloud`; missing/unknown → 422.
- `scm_provider` is persisted at creation and never editable; `PATCH /workspaces/{slug}` ignores any `scm_provider` in payload and returns 422 if present.
- `UpdateWorkspaceRequest::rules()` does not list `scm_provider`; an unauthorized attempt is caught at validation, not silently dropped.

**FR-02 — GitHub App install flow**
- `POST /scm/github/install/start` (auth: workspace admin) stashes `{workspace_id, expires_at}` (10-minute TTL) in the admin's session under key `scm_github_install`.
- Response is a 302 redirect to `https://github.com/apps/{GITHUB_APP_SLUG}/installations/new`. The GitHub App is configured with **Setup URL** pointing to the callback below, which is where GitHub will send the admin's browser after a successful install (carrying `installation_id` and `setup_action`).
- `GET /scm/github/install/callback` is the configured Setup URL. It consumes the session marker (`session()->pull('scm_github_install')`), validates `expires_at`, persists `installation_id` to the Workspace identified by the marker's `workspace_id`, calls `GithubDriver::verifyCredentials()`, marks `health=healthy`.
- Callback rejects: missing session marker (no `start()` preceded this hit) → 403; expired marker → 403; `installation_id` already on another Workspace → 409 with explanatory body. The marker is consumed atomically by `pull`, so a replayed callback request (same browser, refreshed) returns 403 because the second read finds no marker.
- `setup_action=install` is the happy path; `setup_action=update` triggers a `listRepositories` re-sync only, no mapping change.
- User account installations (`account.type=User` on GH side) are accepted without 422; downstream code does not distinguish.

**FR-03 — Bitbucket connect flow (unchanged)**
- Existing 3-step wizard, `ConnectController::update`, `BitbucketClient::me()` validation, encrypted persistence — preserved without functional change beyond the namespace move (`BitbucketClient` → `BitbucketDriver`) and DTO return type (`CredentialCheck` instead of raw array).

**FR-04 — `ScmDriverInterface` and DTOs**
- 12 public methods (verifyCredentials, listRepositories, getRepository, getPullRequest, getChangedFiles, getDiff, postPullRequestComment, postInlineComment, updateComment, registerWebhook, deleteWebhook, plus the `lastRateLimit()` accessor where applicable).
- Return types are DTOs or scalars; never provider-shaped arrays.
- `registerWebhook(string $scmRepoId, string $callbackUrl, string $secret): ?WebhookHandle` — BB returns a handle with `scmWebhookUuid`; GH returns `null` (App-level webhook handled).
- `deleteWebhook(string $scmRepoId, ?WebhookHandle $handle): void` — BB calls the API; GH no-op.
- All call sites (`ConnectController`, `RepositoryController`, `CommentPoster`, `ReviewPullRequestJob`) type-hint `ScmDriverInterface` and obtain instances via `ScmDriverFactory::make($workspace)`.

**FR-05 — `BitbucketDriver`**
- Implements `ScmDriverInterface`. Mapped from the existing `BitbucketClient` methods, with return types converted from `array` to DTOs at the boundary.
- Bearer token from `workspace.bitbucket_token`. Webhook URL passed to `registerWebhook` posts to BB API endpoint, persists the returned UUID into `repositories.scm_webhook_uuid`.

**FR-06 — `GithubDriver`**
- Implements `ScmDriverInterface`. JWT minted on-the-fly with `GITHUB_APP_ID` and `GITHUB_APP_PRIVATE_KEY`; exchanged for an installation access token (1h TTL) cached in Redis keyed by `installation_id`.
- `verifyCredentials()` calls `GET /installation` (returns App-installed metadata); on success returns `CredentialCheck(valid=true, identity="installation:{id}")`.
- `listRepositories()` calls `GET /installation/repositories` (paginated).
- `postInlineComment()` uses `POST /repos/{owner}/{repo}/pulls/{n}/comments` with `commit_id` from the `InlineCommentPayload::$headSha` and `side: 'RIGHT'` hardcoded for MVP.
- `getDiff()` requests `Accept: application/vnd.github.v3.diff` on the PR endpoint.
- `registerWebhook` / `deleteWebhook` are documented no-ops.

**FR-07 — Per-provider webhook controllers**
- `Bitbucket/WebhookController` (existing) unchanged in shape; minor adjustments for the renamed columns (`scm_delivery_id`, `scm_repo_id`).
- `Github/WebhookController` (new) handles:
  - `X-GitHub-Event: pull_request` + `action: opened` → HMAC-SHA256 verification against `GITHUB_APP_WEBHOOK_SECRET`, idempotency via `X-GitHub-Delivery`, dispatch through `WebhookIngestionPipeline::ingestPullRequestCreated($repository, $event)`.
  - `X-GitHub-Event: installation` + `action: deleted` → inline handler calling `GithubInstallationManager::handleUninstall($installationId)`. No `WebhookIngestionPipeline` involvement (no PR to review).
  - All other events → 202 with "event ignored" body.
- Both controllers reject missing/mismatched signatures with 401 before any DB write or dispatch.

**FR-08 — `WebhookIngestionPipeline` (shared)**
- One method: `ingestPullRequestCreated(Repository $repo, NormalizedPullRequestEvent $event): WebhookDelivery`.
- Inside a DB transaction: inserts `webhook_deliveries` row (status `received` → `dispatched`), dispatches `ReviewPullRequestJob` with only safe scalars (workspace_id, repository_id, pr_number, scm_delivery_id).
- No HMAC, no event-name parsing here — those live in the calling controller.

**FR-09 — Installation lifecycle on uninstall**
- `GithubInstallationManager::handleUninstall($installationId)`:
  1. Looks up Workspace by `github_installation_id`.
  2. Nullifies `github_installation_id`.
  3. Sets `health=unhealthy`.
  4. Updates all the Workspace's repositories to `enabled=false`.
  5. Logs to `bitbucket` channel (channel name retained for backward compatibility; renamed to `scm` in a follow-up PR with channel-renaming sweep).
- Idempotent: repeated `installation.deleted` for the same `installation_id` after step 1 is a no-op (lookup returns null).

### 5.2. Error and edge cases
- Workspace `github_cloud` without `github_installation_id` populated (callback never completed): `verifyCredentials` returns invalid; admin sees "complete connection" CTA; no `ReviewPullRequestJob` dispatches because no webhook arrives.
- `installation_id` in callback already on another Workspace: 409 conflict; original mapping untouched; current Workspace remains unconnected; admin instructed to uninstall on GH first or use the existing Workspace.
- Replayed callback (session marker already consumed by an earlier successful or failing callback): 403.
- GH App's `webhook_secret` rotated: env reload required; in-flight webhooks during rotation may fail HMAC (acceptable — operational note).
- `pull_request` action other than `opened`: 202, no dispatch (`synchronize` etc. are backlog v0.2).
- Force-push between dispatch and job execution: same posture as BB (delivery + dispatch happen at receive time).
- GitHub App suspended (`installation.suspend` event): out of scope — treated as ignored event in v1.0; may convert to backlog item if seen in practice.

---

## 6. Non-Functional Requirements

- **Reliability**: 5xx responses to GitHub cause GH to retry under its own backoff (mirrors BB at-least-once posture).
- **Performance**: webhook handler completes in <300ms p95 on both providers (DB insert + unique check + queue push).
- **Security**: HMAC primary auth on both providers, `hash_equals` timing-safe; GH install flow uses a session-backed, single-use, time-bounded marker (10-min TTL, consumed via `session()->pull`); GH App private key only in env, never DB-persisted.
- **State token TTL**: 10 minutes — long enough for admin to complete install on GH UI, short enough to bound CSRF window.
- **Observability**: every GH API call logs to a structured channel; rate-limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) captured per call.
- **Compatibility**: no behavioural change to existing Bitbucket Workspaces beyond renamed columns and class names; the 113 Phase 2 Pest tests pass without semantic edits (only import paths and column references update).

---

## 7. Data Model & Contracts

### 7.1. Tables touched (3 migrations)

**Migration A — `add_scm_columns_to_workspaces`**
| Op | Column | Type | Notes |
|---|---|---|---|
| ADD | `scm_provider` | `string NOT NULL DEFAULT 'bitbucket_cloud'` | Mirrors `llm_provider` shape; cast to `App\Enums\ScmProvider` enum at model level |
| RENAME | `bitbucket_workspace_slug` → `scm_owner_slug` | (intact: `string unique nullable`) | Unique constraint renamed accordingly |
| ADD | `github_installation_id` | `string unique nullable` | Enforces 1:1 at DB level; nullable until install callback completes |
| KEEP | `bitbucket_token` | `text nullable, encrypted cast` | BB-only |
| KEEP | `bitbucket_service_account` | `string nullable` | BB-only |
| KEEP | `webhook_secret` | `text nullable, encrypted cast` | BB-only (GH uses env `GITHUB_APP_WEBHOOK_SECRET`) |

**Migration B — `rename_columns_on_repositories`**
| Op | Column | Notes |
|---|---|---|
| RENAME | `bitbucket_uuid` → `scm_repo_id` | `string unique`; namespace disjoint between BB UUIDs and GH numeric IDs |
| RENAME | `full_slug` → `full_name` | Both BB and GH APIs natively return `full_name` |
| RENAME | `webhook_uuid` → `scm_webhook_uuid` | BB-only, nullable |
| KEEP | `webhook_token` | BB-only defense-in-depth URL token |

**Migration C — `rename_and_add_columns_on_webhook_deliveries`**
| Op | Column | Notes |
|---|---|---|
| RENAME | `bitbucket_uuid` → `scm_delivery_id` | Unique index renamed |
| ADD | `scm_provider` | `string nullable` — denormalized for self-describing audit logs; populated at insert from `$repository->workspace->scm_provider` |

### 7.2. New enums
- `App\Enums\ScmProvider`: backed string, cases `BitbucketCloud => 'bitbucket_cloud'`, `GithubCloud => 'github_cloud'`.

### 7.3. GitHub API contracts consumed
- `GET /installation` — installation metadata (`verifyCredentials`).
- `GET /installation/repositories` — installation-scoped repository list (paginated).
- `GET /repos/{owner}/{repo}` — single repository.
- `GET /repos/{owner}/{repo}/pulls/{n}` — pull request (with `Accept: application/vnd.github.v3.diff` for raw diff).
- `GET /repos/{owner}/{repo}/pulls/{n}/files` — changed files (paginated).
- `POST /repos/{owner}/{repo}/issues/{n}/comments` — top-level PR comment (GH treats top-level as issue comment).
- `POST /repos/{owner}/{repo}/pulls/{n}/comments` — inline review comment (requires `commit_id`, `path`, `line`, `side`).
- `PATCH /repos/{owner}/{repo}/pulls/comments/{cid}` — update comment.
- `POST /app/installations/{installation_id}/access_tokens` — exchange JWT for 1h installation access token.

### 7.4. Webhook payload contracts consumed
- `pull_request` event (`action=opened`): used for ingestion.
- `installation` event (`action=deleted`): used for uninstall handling.
- Other events: ignored.

### 7.5. State token format
- HMAC-SHA256 of `base64url({"w": workspace_id, "exp": unix_ts, "n": nonce})` with `APP_KEY`-derived secret.
- Encoded as `{payload_b64}.{sig_b64}`.
- Nonce stored in Redis with key `scm:gh:install:nonce:{nonce}` and TTL 10 minutes; deleted on first valid use.

---

## 8. AI/Agent Design

Not directly. `ReviewPullRequestJob` is provider-agnostic: it resolves `ScmDriverInterface` via factory, calls `getPullRequest`, `getDiff`, `getChangedFiles`, runs the LLM driver, and posts comments through the same `CommentPoster` regardless of SCM. The prompt, the tool schema (`review_result_v1`), the cache control, and the cost reservation logic are all unchanged.

---

## 9. UX & Interaction Design

- `pages/workspaces/Index.vue` — "+ New Workspace" inline form gains a `scm_provider` radio (two options for now: Bitbucket Cloud, GitHub Cloud). Default focus on Bitbucket Cloud (current customer base).
- `pages/workspaces/Show.vue` — "Connect" CTA label reads "Connect Bitbucket" or "Connect GitHub" based on `scm_provider`; clicking routes to the right wizard page.
- `pages/workspaces/Connect.vue` — used only when `scm_provider=bitbucket_cloud` (unchanged from MVP).
- `pages/workspaces/ConnectGithub.vue` — new page: brief explainer + a single button "Install on GitHub" that POSTs to `/scm/github/install/start` (Wayfinder-typed). After install, the Show page renders the connected state.
- All routes via Wayfinder per project convention.
- No new shadcn-vue components introduced.

---

## 10. System Architecture & Integration

### 10.1. Namespace layout (new)
```
app/Services/Scm/
├── Contracts/ScmDriverInterface.php
├── Dto/
│   ├── RepositoryDto.php
│   ├── PullRequestDto.php
│   ├── FileChangeDto.php
│   ├── InlineCommentPayload.php
│   ├── CommentHandle.php
│   ├── WebhookHandle.php
│   └── CredentialCheck.php
├── Exceptions/UnsupportedScmProviderException.php
├── Github/
│   ├── JwtSigner.php
│   ├── InstallationTokenCache.php
│   └── GithubInstallationManager.php
├── BitbucketDriver.php
├── GithubDriver.php
└── ScmDriverFactory.php
```

### 10.2. Controller and route layout
- `app/Http/Controllers/Bitbucket/WebhookController.php` (intact — minor column references update).
- `app/Http/Controllers/Github/WebhookController.php` (new).
- `app/Http/Controllers/Scm/Github/InstallController.php` (new — `start` + `callback` actions).
- `routes/bitbucket.php` (existing — BB webhook).
- `routes/scm/github.php` (new — webhook + install).
- `bootstrap/app.php` `withRouting()` registers the new route file.

### 10.3. Cross-cutting touch points
- `app/Services/Webhook/WebhookIngestionPipeline.php` (new) — shared by both webhook controllers post-validation.
- `app/Providers/AppServiceProvider.php` registers `ScmDriverFactory` as singleton.
- `ReviewPullRequestJob::handle()` — type-hint changes from `BitbucketClient` to `ScmDriverInterface`; constructor receives factory or resolves at runtime.
- `CommentPoster` — type-hint changes from `BitbucketClient` to `ScmDriverInterface`; method signatures unchanged.
- `ConnectController` and `RepositoryController` — same type-hint substitution.

### 10.4. Integration with sibling features
- `tenancy-and-workspace-isolation.md` — `EnsureWorkspaceMember` guards the new install routes; webhook routes remain outside the workspace.member group (provider-discovered).
- `lgpd-data-protection-posture.md` — `github_installation_id` is non-sensitive (an integer-shaped public ID); no encryption cast needed. App-level private key + webhook secret live in env; never DB-persisted.
- `multi-llm-provider-support.md` — orthogonal: a `github_cloud` Workspace can pick `openai` or `anthropic` LLM independently.
- `ai-code-review-pipeline.md` — driver swap is transparent; `ReviewPullRequestJob` keeps its prompt-cache + strict-tool-use contracts.
- `bitbucket-cloud-integration.md` — superseded only in the sections covering schema column names; the Bitbucket-specific behavior described there remains accurate for the BB driver path.

---

## 11. Validation: Acceptance Criteria & Test Strategy

### 11.1. Acceptance criteria (12 new, AC27..AC38)

**Provider selection (2)**
- **AC27** — `scm_provider` is required at `POST /workspaces`; missing or value outside `{bitbucket_cloud, github_cloud}` → 422. Tests: `WorkspaceControllerTest`.
- **AC28** — `scm_provider` is immutable after creation; `PATCH /workspaces/{slug}` with `scm_provider` in payload → 422. Test: `WorkspaceControllerTest`.

**Connect — GitHub App install flow (4)**
- **AC29** — `POST /scm/github/install/start` returns 302 redirect to `github.com/apps/{slug}/installations/new` (no `?state=`) and stashes `{workspace_id, expires_at}` in the session under key `scm_github_install` with a 10-minute TTL. The marker is single-use — consumed atomically by `session()->pull()` on the first callback hit. Test: `Github/InstallControllerTest`.
- **AC30** — `GET /scm/github/install/callback` with valid state + `installation_id` persists `installation_id` on the matching Workspace, calls `verifyCredentials()`, marks `health=healthy`. Includes the case of a user-account installation (`account.type=User` accepted without 422). Test: `Github/InstallControllerTest`.
- **AC31** — Callback with invalid signature / expired token / replayed nonce → 403; no `installation_id` persisted. Test: `Github/InstallControllerTest`.
- **AC32** — Callback with `installation_id` already mapped to another Workspace → 409; original mapping preserved; current Workspace stays unconnected. Test: `Github/InstallControllerTest`.

**Webhook ingestion — GitHub side (4)**
- **AC33** — GH `pull_request` `action=opened` with valid `X-Hub-Signature-256` HMAC-SHA256 (verified against `GITHUB_APP_WEBHOOK_SECRET`) → `webhook_deliveries` row inserted with `scm_provider=github_cloud`, `ReviewPullRequestJob` dispatched within 2s. Mirror of AC1. Test: `Github/WebhookControllerTest`.
- **AC34** — GH webhook missing `X-Hub-Signature-256` or with mismatched HMAC → 401, no DB write, no dispatch. Mirror of AC19. Test: `Github/WebhookControllerTest`.
- **AC35** — GH webhook with duplicate `X-GitHub-Delivery` → no second `webhook_deliveries` row, no duplicate dispatch. Mirror of AC2; validates `scm_delivery_id` unique index covers both providers. Test: `Github/WebhookControllerTest`.
- **AC36** — GH `installation` `action=deleted` → Workspace's `github_installation_id` nullified, `health=unhealthy`, all `repositories.enabled=false`. Idempotent on repeated delivery. Test: `Github/WebhookControllerTest` + `GithubInstallationManagerTest`.

**Driver parity (2)**
- **AC37** — `ScmDriverFactory::make($workspace)` returns `BitbucketDriver` for `bitbucket_cloud`, `GithubDriver` for `github_cloud`; unknown value throws `UnsupportedScmProviderException`. Test: `ScmDriverFactoryTest`.
- **AC38** — `ReviewPullRequestJob` against a `github_cloud` Workspace produces a `ReviewResultDto` identical in shape to one from `bitbucket_cloud`; downstream code paths (`CommentPoster`, `LlmCallTelemetry`, `CostReservation`) are provider-agnostic (no `scm_provider` branching). Test: `ReviewPullRequestJobGithubTest` + parity assertions.

### 11.2. Test strategy notes
- `Github/WebhookControllerTest` mirrors the structure of `Bitbucket/WebhookControllerTest`, with `Http::fake()` only for outbound calls; HMAC is computed inline like in BB tests.
- `Github/InstallControllerTest` uses a frozen clock for state-token TTL assertions; cache uses array driver.
- `ScmDriverFactoryTest` is a unit test asserting class resolution per enum value.
- `tests/Unit/Services/Scm/ScmArchTest.php` (`pest --architecture`) asserts both drivers implement `ScmDriverInterface` (covers AC37 statically).
- Regression contract (implicit, not a numbered AC): the existing 113 Phase 2 BB feature tests pass without semantic edits after the rename + reshape.

---

## 12. Telemetry, Observability & Evaluation Metrics

- Every `GithubDriver` API call logs structured entries to the `scm` log channel (rename of existing `bitbucket` channel — sweep in same PR set).
- GitHub rate-limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) captured per call and exposed via `GithubDriver::lastRateLimit()`.
- `webhook_deliveries` rows are themselves the per-event audit log; the `scm_provider` column makes them self-describing without join.
- `reviews_llm_calls` is unaffected: `provider` column there refers to LLM provider only.
- Future health endpoint check: `scm_github_app` reachability (reuses the `401 = reachable` convention from Anthropic/OpenAI checks).

---

## 13. Security, Privacy & Compliance

- **State token CSRF protection**: HMAC signature + single-use nonce + 10-min TTL on the GH install callback. Without these, an attacker linking an admin to a crafted install URL could bind a different installation to that admin's Workspace.
- **GitHub App private key**: stored only in env (`GITHUB_APP_PRIVATE_KEY`), never DB-persisted. Rotation requires env reload and re-deploy; existing installation tokens (1h TTL) are invalidated by re-minting on next call.
- **Webhook secret rotation (GH)**: changing `GITHUB_APP_WEBHOOK_SECRET` requires syncing the App's webhook secret in the GH UI and a redeploy; brief window of failed signature verification during rotation is acceptable operational cost.
- **Installation ID is non-secret**: stored unencrypted in `workspaces.github_installation_id`; `unique` constraint prevents binding the same installation to two Workspaces.
- **LGPD posture preserved**: no diff at rest (the `Github` driver fetches diff into a local variable inside `handle()`, never persists); RedactingFailedJobProvider unchanged; AC4/AC16/AC17 carry over.
- **DPA**: a separate GitHub DPA must be on file before any `github_cloud` Workspace can run reviews in production. See §14 open question for the LGPD-check #10 candidate.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Asymmetric HMAC scheme (per-Workspace BB vs per-App GH) creates two code paths to audit | Mitigated by per-provider controllers (ADR 0003): each path is linear and small; no `switch ($provider)` inside HMAC verification |
| GitHub App-level secret cannot be rotated per-Workspace | Accepted; operational risk same shape as any per-app shared secret (Anthropic API key etc.) |
| `installation.deleted` race with in-flight `ReviewPullRequestJob` | Job sees nullified `github_installation_id`; `GithubDriver::verifyCredentials` returns invalid; job logs and exits without posting comment. Add explicit short-circuit in `ReviewPullRequestJob::handle` |
| GitHub webhook firehose on a large org with the App installed | App scoped to picked repos at install time; firehose bounded by admin's selection. Cost ceiling (`workspaces.daily_token_cap`) still applies |
| State token signed with `APP_KEY` derivative — same surface as Laravel signed routes | Accepted; same trust boundary as the rest of the app |
| Renaming columns in prod is a brief migration but breaks any external query consumers | Phase 5 already complete; no external consumers exist yet; migrate before the pilot lands |
| Channel rename `bitbucket` → `scm` is a cross-cutting sweep | Done in same PR set; log searches must update too |

Open questions:

- **LGPD check #10 for GitHub DPA**: should `rabbit:lgpd-check` fail when any Workspace uses `github_cloud` but `GITHUB_DPA_URL` env is unset (mirror of OpenAI check #9 from `multi-llm-provider-support.md`)? Lean yes; needs DPO confirmation before adding.
- **`installation.suspend` handling**: GH emits this when the App is suspended (admin pauses without uninstalling). v1.0 treats it as ignored; pre-mortem if any pilot customer hits this.
- **Anthropic comment-poster identity on GH**: confirm "🤖 cdv-rabbit (AI generated):" prefix renders cleanly as comments authored by the GitHub App — App account display name needs to match.
- **Backfill in production**: zero rows now, but the migration order must run before pilot. Verify with `database-schema` MCP after each migration in CI.

---

## 15. Change Log

- **v1.0 (2026-05-15)** — Initial spec authored from grilling session (`grill-with-docs` skill). Decisions ratified across ADRs `docs/adr/0001-strict-1-to-1-workspace-and-scm-owner.md`, `docs/adr/0002-github-app-as-github-scm-auth-model.md`, `docs/adr/0003-per-provider-webhook-controllers-and-asymmetric-hmac.md`, `docs/adr/0004-scm-driver-interface-shape.md`. Glossary entries in `CONTEXT.md`. 12 new ACs AC27..AC38. No code changes yet; implementation gated on `AGENTS.md` Phase 6 promotion + operational sign-offs (GitHub App registration, GitHub DPA).
