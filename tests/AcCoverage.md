# AC Coverage Index — cdv-rabbit MVP

Maps each acceptance criterion (AC1–AC26) to its owning test file(s).
Updated at Phase 4 completion (W4-T4).

| AC | Description (short) | Status | Owning test file(s) |
|----|---------------------|--------|---------------------|
| AC1 | `pullrequest:created` webhook creates review row within 2s | ✅ | `tests/Feature/Bitbucket/EndToEndSmokeTest.php`, `tests/Feature/Bitbucket/WebhookControllerTest.php` |
| AC2 | Duplicate webhook (same UUID) does not produce second review | ✅ | `tests/Feature/Bitbucket/EndToEndSmokeTest.php`, `tests/Feature/Bitbucket/WebhookControllerTest.php` |
| AC3 | Review fetches diff, calls Claude, posts ≥1 inline + 1 summary comment | ✅ | `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC4 | Diff content never written to DB, log, or filesystem | ✅ | `tests/Unit/Jobs/JobSerializationLeakTest.php`, `tests/Feature/Queue/FailedJobRedactionTest.php`, `tests/Feature/Services/Review/CommentPosterTest.php` |
| AC5 | Inline comments capped at 25 per review | ✅ | `tests/Feature/Services/Review/CommentPosterTest.php` |
| AC6 | Every bot comment prefixed with `🤖 cdv-rabbit (AI generated):` | ✅ | `tests/Feature/Services/Review/CommentPosterTest.php` |
| AC7 | Re-running review updates existing `(path, line)` comments — no duplicates | ✅ | `tests/Feature/Services/Review/CommentPosterTest.php` |
| AC8 | Kill switch (per-workspace) stops dispatch within 10s | ✅ | `tests/Feature/Jobs/ReviewPullRequestJobTest.php`, `tests/Feature/Review/KillSwitchE2eTest.php`, `tests/Feature/Admin/KillSwitchControllerTest.php` (UI path + global env flag) |
| AC9 | Secrets matching redaction regex never reach Anthropic | ✅ | `tests/Unit/Services/Review/SecretRedactorTest.php`, `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC10 | Prompt-injection payload in diff is XML-escaped before LLM call | ✅ | `tests/Unit/Services/Llm/PromptBuilderTest.php`, `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC11 | Daily cost ceiling halts dispatch + sends alert email | ✅ | `tests/Feature/Review/CostReservationTest.php`, `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC12 | Cross-workspace query isolation (global scope enforced) | ✅ | `tests/Feature/Tenancy/CrossWorkspaceIsolationTest.php` |
| AC13 | `php artisan rabbit:lgpd-check` returns exit 0 | 🔲 | Deferred to W5 (hardening phase) |
| AC14 | Full test suite green on CI | ✅ | CI run / `php artisan test --compact` |
| AC15 | Health endpoint `/up` returns 200 when healthy | ✅ | `tests/Feature/Bitbucket/EndToEndSmokeTest.php` |
| AC16 | Serialized `ReviewPullRequestJob` contains zero diff content, < 2KB | ✅ | `tests/Unit/Jobs/JobSerializationLeakTest.php`, `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC17 | `failed_jobs.payload` after mid-handle throw contains zero diff lines | ✅ | `tests/Feature/Queue/FailedJobRedactionTest.php` |
| AC18 | `BelongsToWorkspace` scope throws `WorkspaceContextMissingException` when unbound | ✅ | `tests/Feature/Tenancy/CrossWorkspaceIsolationTest.php`, `tests/Unit/Concerns/BelongsToWorkspaceTest.php` |
| AC19 | Missing/invalid HMAC signature returns 401 | ✅ | `tests/Feature/Bitbucket/WebhookControllerTest.php` |
| AC20 | Two concurrent jobs at ceiling — exactly one passes (atomic Lua) | ✅ | `tests/Feature/Review/CostReservationTest.php` |
| AC21 | Cache prefix > 1024 tokens (precondition); cache_read_input_tokens > 0 on repeat (main) | ✅ (precondition) / 🔲 (live-Anthropic main, deferred to W6 pilot) | `tests/Feature/Llm/CacheablePrefixSizeTest.php`, `tests/Feature/Llm/ClaudeReviewerTest.php` |
| AC22 | Every `reviews_llm_calls` row has non-empty `request_id` | ✅ | `tests/Unit/Services/Llm/LlmCallTelemetryTest.php`, `tests/Feature/Llm/ClaudeReviewerTest.php` |
| AC23 | `review_result_v1` schema frozen — byte-for-byte fixture match | ✅ | `tests/Feature/Llm/SchemaFreezeTest.php` |
| AC24 | XML-injection in diff is escaped (`<` → `&lt;`) before envelope | ✅ | `tests/Unit/Services/Llm/PromptBuilderTest.php` |
| AC25 | 400/401/402/403/404/413 terminal; 429/5xx/529 retried with backoff | ✅ | `tests/Feature/Llm/ClaudeReviewerTest.php`, `tests/Feature/Jobs/ReviewPullRequestJobTest.php` |
| AC26 | Empty comments + risk_level=low → summary only, status=posted | ✅ | `tests/Feature/Services/Review/CommentPosterTest.php` |

## Cross-cutting integration

| Suite | Description | Status | File |
|-------|-------------|--------|------|
| Phase3 smoke | 3-file PR: binary + lock skipped, PHP reviewed, no diff in DB | ✅ | `tests/Feature/Phase3/Phase3IntegrationSmokeTest.php` |
| Phase4 UI smoke | Admin walks workspaces→show→reviews→review show→kill switch; all 200 | ✅ | `tests/Feature/Phase4/Phase4UiSmokeTest.php` |
| Phase4 authorization | Unauthenticated redirects, non-member 403, cross-workspace 404 | ✅ | `tests/Feature/Phase4/UiAuthorizationTest.php` |
| Phase4 sidebar nav | Shared props (currentWorkspace, killSwitchEnabled) correct by route | ✅ | `tests/Feature/Navigation/SidebarNavTest.php` |

## Legend

- ✅ Covered with passing test(s)
- 🔲 Deferred: noted in plan with explicit rationale (W5 hardening or W6 live-API pilot)
