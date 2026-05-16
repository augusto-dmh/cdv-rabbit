# Phase 6 — Multi-SCM Provider Support — Task Graph

**Source of truth:** `specs/multi-scm-provider-support.md` (v1.0).
**Design decisions:** `docs/adr/0001-strict-1-to-1-workspace-and-scm-owner.md` through `docs/adr/0004-scm-driver-interface-shape.md`.
**Glossary:** `CONTEXT.md`.
**New ACs:** AC27..AC38 (spec §11).

## Sequencing

Tasks are ordered by dependency, not by parallelism opportunity. Most tasks can be parallelized within a team session, but the strict ordering below ensures each step builds on a verified foundation.

| ID | Title | Depends on | Notes |
|---|---|---|---|
| W6-T1 | Schema migrations + enum + model casts | — | 3 migrations (workspaces, repositories, webhook_deliveries) per spec §7. Add `App\Enums\ScmProvider`. Update factories. Run existing test suite — must stay green after column renames. |
| W6-T2 | DTOs + `ScmDriverInterface` + `ScmDriverFactory` + exception | W6-T1 | New `app/Services/Scm/` namespace; no driver implementations yet. Unit tests for factory resolution (AC37) and exception throwing. |
| W6-T3 | `BitbucketDriver` rename + reshape (DTO returns) | W6-T2 | Move `app/Services/Bitbucket/BitbucketClient.php` → `app/Services/Scm/BitbucketDriver.php`; convert all `array` returns to DTOs at the boundary; adjust 4 call sites (`ConnectController`, `RepositoryController`, `CommentPoster`, `ReviewPullRequestJob`) to type-hint `ScmDriverInterface`. Delete `app/Services/Bitbucket/`. Existing 113 BB tests must stay green (regression contract). |
| W6-T4 | `GithubDriver` implementation | W6-T2, W6-T3 | JWT signer, installation token cache (Redis-backed, 1h TTL), all 11 interface methods + `lastRateLimit()`. Unit tests with `Http::fake()` covering happy paths, 401/404, rate-limit capture. `registerWebhook`/`deleteWebhook` are documented no-ops returning `null`/`void`. |
| W6-T5 | `Github/WebhookController` + `WebhookIngestionPipeline` | W6-T4 | Per-provider HMAC verification, idempotency, dispatch through shared pipeline. Includes `installation.deleted` inline handler calling `GithubInstallationManager`. ACs AC33, AC34, AC35, AC36. Refactor `Bitbucket/WebhookController` minimally to use the same pipeline (no semantic change). |
| W6-T6 | `Github/InstallController` (Setup URL flow) + connect wizard UI | W6-T4 | Session-stashed `{workspace_id, expires_at}` marker (10-min TTL, single-use via session pull) in `start()` — no signed state token needed because GitHub's Setup URL flow doesn't preserve custom `state` params. `start`/`callback` actions, `CreateWorkspaceRequest` adds `scm_provider` validation, `UpdateWorkspaceRequest` rejects `scm_provider`, frontend `ConnectGithub.vue` + provider radio in `Index.vue` + branching in `Show.vue`. ACs AC27, AC28, AC29, AC30, AC31, AC32. Wayfinder regen required. |
| W6-T7 | Phase 6 verifier — parity tests, arch test, smoke | W6-T1..W6-T6 | `tests/Unit/Services/Scm/ScmArchTest.php` (arch). `tests/Feature/Scm/Github/EndToEndSmokeTest.php` (full ingest → review post). Parity test: same fake PR ingested via BB vs GH paths produces shape-identical `ReviewResultDto` and identical comment posting behaviour (AC38). All 12 new ACs proven by named tests. Tag `phase-6-complete` on green. |

## Operational gates (must clear before W6-T4 end-to-end testing)

These are not coding tasks; they unlock the work:

1. GitHub App "cdv-rabbit-bot" registered at github.com/settings/apps (or org-scoped equivalent).
2. `GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_SLUG` populated in staging `.env`.
3. GitHub DPA signed and `GITHUB_DPA_URL` env populated (if `rabbit:lgpd-check` #10 added — open question per spec §14).
4. Public webhook endpoint reachable from GitHub (operational, not coding — pilot infra).

## Out of scope for Phase 6

Per spec §2.4: GHES, BBDC, OAuth/PAT, multi-installation, Reviews/Checks API, code suggestions, bot commands, push events, migration tooling. Do not extend the task graph to cover these; they are v1.1+.

## Commit / branching style (per `AGENTS.md §6`)

- Trunk-based on `main`. One commit per completed task (W6-T1 through W6-T7), conventional-commit prefixes `feat(scm):`, `feat(github):`, `refactor(scm):`, `chore(scm):` as appropriate.
- Worker agents do not commit; team-lead owns git per `AGENTS.md §6`.
- Phase tag `phase-6-complete` applied at W6-T7 verifier exit.

## Risks tracked

- **Channel rename `bitbucket` → `scm`**: cross-cutting sweep affecting `config/logging.php`, `BitbucketDriver`, all places using `Log::channel('bitbucket')`. Decide in W6-T3 whether to rename now or defer (post-Phase 6 follow-up).
- **`installation.suspend` event**: GH emits when admin suspends without uninstalling. Spec §14 marks as open question; Phase 6 ignores by default. Add to backlog if pilot customer triggers it.
- **`installation_id` race during callback**: two concurrent callbacks with the same `installation_id` could both pass the uniqueness check before the first insert lands. Mitigation: use a transactional `INSERT ... ON CONFLICT` pattern or rely on the unique constraint to reject the second insert at the DB level.

## Verification before tag

- All 12 new ACs (AC27..AC38) covered by named Pest tests.
- All 26 MVP ACs still pass (regression).
- `vendor/bin/pint --dirty --format agent` clean.
- No `app/Services/Bitbucket/` directory remains.
- `php artisan route:list --only-vendor=0` shows `/scm/github/install/start`, `/scm/github/install/callback`, `/scm/github/webhook`, `/bb/webhook/...` all present.
- `database-schema` MCP confirms the 4 renames and 2 added columns.

## Estimated effort (rough)

W6-T1: 0.5d. W6-T2: 0.5d. W6-T3: 1.0d. W6-T4: 1.5d. W6-T5: 1.0d. W6-T6: 1.5d. W6-T7: 1.0d. Total ~7 days of focused work, assuming the operational gates are cleared in parallel.
