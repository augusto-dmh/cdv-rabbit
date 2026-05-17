# Review Pipeline v2 — High-Recall Structured Output with Draft-Critique Pass

## 1. Overview

### 1.1. Feature Name
**Review Pipeline v2 — Walkthrough + Tiered Findings + Collapsed Nitpicks + Same-Provider Critique**

### 1.2. One-sentence summary
`review_result_v2.json` replaces the silence-biased v1 schema with a high-recall structured output (Walkthrough, severity-tagged Findings, collapsed Nitpicks, optional CodeRabbit-style `agent_prompt` per Finding) evaluated by a two-call draft-then-critique pipeline where only critic-approved Findings reach `CommentPoster`, gated per Workspace via `workspaces.review_schema_version` and validated by a golden-PR eval harness with cross-provider LLM-as-judge.

### 1.3. Primary outcome
A 200-LOC PR with 3 real issues produces a Review containing a Walkthrough, 3 Findings (line-anchored, severity-tagged), and 0-N Nitpicks (collapsed in summary) — where v1 returned "no significant issues found". The eval harness proves recall >= 0.7 on 10 golden PRs across both providers before any v2 prompt change merges.

### 1.4. Version & owner
`v1.4 – Phase 7.5 reconciliation (eval harness gap captured). Author: augusto-dmh. ADRs: docs/adr/0005-review-pipeline-v2-recall-stance-and-critique-pass.md (foundation); docs/adr/0006-agent-prompt-per-finding-coderabbit-parity.md (Agent Prompt); docs/adr/0007-eval-harness-v2-coverage-gap.md (eval coverage gap + recall investigation).`

---

## 2. Context & Goals

### 2.1. Product/application context
The AI code review pipeline (`specs/ai-code-review-pipeline.md`) shipped in Phase 3 with a silence-biased v1 prompt. Phase-openai (`specs/multi-llm-provider-support.md`) added OpenAI as a second provider. Phase 7 upgrades the pipeline's quality posture from "prefer silence" to "high recall, critic-filtered" — addressing the DocInt PR #35 failure where v1 returned zero Findings on a PR with 3 orthogonal refactors across 8 files.

### 2.2. Problem statement
`review_v1.txt` instructed the model to stay silent unless confident, producing false negatives on multi-concern PRs. Abstract negative instructions ("do not invent findings") reinforced the silence bias rather than correcting it. The fix requires: (a) a new schema that separates Walkthrough from Findings from Nitpicks, (b) a prompt rewritten around concrete few-shot exemplars, (c) a critic pass that filters hallucinated Findings before posting, and (d) an eval harness that quantifies recall/precision against golden PRs so regressions are caught before merge.

### 2.3. Goals
- **G1**: Recall >= 0.7 on 10 golden PRs (5 DocInt, 3 cdv-rabbit, 2 intranet-cdv) for both Anthropic and OpenAI providers.
- **G2**: Precision maintained — critic pass removes hallucinated Findings; Nitpicks never posted inline.
- **G3**: Single `review_result_v2.json` schema serves both Anthropic strict tool use and OpenAI strict structured output (OpenAI strict intersection: every property `required`, optionals as `{ type: ['string','null'] }`).
- **G4**: Per-Workspace opt-in via `workspaces.review_schema_version` enum (`v1|v2`, default `v1`). Rollback = flip back to `v1`.
- **G5**: Cost ceiling absorbs ~2x call increase (draft + critique) via `config('cdv-rabbit.cost_per_review_factor')`.
- **G6**: Eval harness ships FIRST (W7-T1) — all subsequent tasks are gated on green eval baseline.

### 2.4. Non-goals / out of scope
- Cross-provider critiquing (Claude drafts, GPT critiques) — rejected per ADR 0005 despite stronger empirical quality; doubles the env/DPA/cost surface.
- Per-file parallel critique calls (sequential in MVP).
- Auto-tuning few-shot exemplars from production feedback.
- Changing the v1 schema or prompt (frozen per AC23).
- UI for eval results (CLI-only via `rabbit:eval`).

---

## 3. Scope

### 3.1. In-scope functionality
- `config/cdv-rabbit/schemas/review_result_v2.json` — OpenAI-strict-intersection schema with `walkthrough`, `findings[]` (carrying optional `agent_prompt`), `nitpicks[]`, `risk_level`.
- `app/Services/Llm/Dto/ReviewFindingDto::$agentPrompt` — nullable string preserved across the draft → critic → CommentPoster pipeline.
- `app/Services/Review/CommentSanitizer::sanitize()` — path-aware regex that preserves `@<path>` (Agent Prompt convention) while continuing to strip `@<username>` mentions; improvement applies globally to all fields it sanitizes.
- `app/Services/Review/CommentPoster::renderAgentPromptBlock()` + footer integration in `postInlineFinding()` + nested integration in `postV2Summary()` unresolved bullets.
- `config/cdv-rabbit/prompts/review_v2.txt` — high-recall prompt with 5 positive + 2 negative Laravel-flavored few-shot exemplars in `<example>` tags.
- Migration: `workspaces.review_schema_version` enum column (`v1|v2`, default `v1`).
- `LlmDriverInterface::critiqueDraft(DraftReviewDto): CritiqueResultDto` — new method on both `ClaudeReviewer` and `OpenAiReviewer`.
- `App\Services\Llm\Dto\DraftReviewDto` + `App\Services\Llm\Dto\CritiqueResultDto` — value objects.
- `ReviewPullRequestJob::handle()` — branching on `review_schema_version`: v1 path unchanged; v2 path calls `reviewDiff()` then `critiqueDraft()`, filters Findings, routes to `CommentPoster`.
- `CommentPoster` — v2 path: Walkthrough in summary comment, approved Findings as inline comments, Nitpicks collapsed in summary. Existing v1 path unchanged.
- `tests/Eval/golden/` — 10 golden PR fixtures with `expected_findings.json`.
- `php artisan rabbit:eval` — runs every (provider x schema_version) cell, computes recall/precision, supports `--schema=v2 --provider=anthropic` filtering.
- `tests/Eval/LlmJudge.php` — cross-provider LLM-as-judge (Claude judges GPT output, GPT judges Claude output).
- `config/cdv-rabbit.php` — new keys: `cost_per_review_factor` (default 2), `review_schema_version_default` (default `v1`).

### 3.2. Out-of-scope for this iteration
- Streaming partial critique results to UI.
- Per-Workspace few-shot exemplar overrides.
- Eval dashboard (results are CLI + structured log only).
- Batch API for eval runs (sequential in MVP).
- Rule-based path-validation of `agent_prompt` against the diff file list — promoted to v0.2 if the pilot surfaces hallucinated-path prompts. Rationale in ADR 0006: cost-benefit unfavorable at MVP because the critic already gates the anchor, and `message`/`suggestion` are not similarly validated; introducing a per-field validator only for `agent_prompt` would be inconsistent.
- Cross-file context expansion for `agent_prompt` (multi-file fix recipes that read other files): emerges naturally when the draft LLM has the relevant context in scope; not engineered as a separate retrieval step.

### 3.3. Dependencies
- `laravel/ai ^0.6.8` (existing).
- Both Anthropic and OpenAI API credentials (existing).
- `LlmDriverInterface` from `specs/multi-llm-provider-support.md` (extended with `critiqueDraft()`).
- `CostReservation` from `specs/cost-management-and-ceiling-alerts.md` (factor adjustment).
- 10 golden PRs curated from DocInt, cdv-rabbit, and intranet-cdv (manual curation step).

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **CDV developer** — receives higher-quality reviews with actionable Findings and a Walkthrough that contextualizes the PR.
- **Workspace admin** — opts a Workspace into v2 when eval results are satisfactory; rolls back to v1 if quality regresses.
- **Platform operator** — monitors the ~2x cost increase from the critic pass; uses `rabbit:eval` to validate prompt changes before deploy.
- **DPO** — confirms no new data-at-rest vectors (diff still handle()-scoped; critique call follows same no-persistence contract).

### 4.2. Primary use cases
- As a developer, I want the bot to surface real issues instead of saying "no significant issues found" on a multi-concern PR.
- As a developer, I want cosmetic Nitpicks collapsed in the summary, not cluttering inline comments.
- As an admin, I want to test v2 on one Workspace before rolling out org-wide.
- As an operator, I want to run `rabbit:eval --schema=v2` and see recall/precision numbers before merging any prompt change.

### 4.3. User journey
Operator curates 10 golden PRs → runs `rabbit:eval --schema=v2 --provider=anthropic` → recall >= 0.7 confirmed → admin flips `review_schema_version` to `v2` on a test Workspace → next PR triggers v2 pipeline (draft + critique) → developer sees Walkthrough + Findings + collapsed Nitpicks → admin monitors telemetry for cost and quality → rolls out to remaining Workspaces.

---

## 5. Functional Requirements

**FR-01 — Eval harness: golden PR corpus**
- Trigger: `php artisan rabbit:eval`.
- Behaviour: loads 10 golden PRs from `tests/Eval/golden/{pr_slug}/` (each containing `diff.patch`, `pr_metadata.json`, `expected_findings.json`). Runs the review pipeline for each (provider x schema_version) cell. Computes recall (expected Findings surfaced / total expected) and precision (real Findings / total surfaced) using an LLM-as-judge with rotated provider.
- Output: per-cell recall/precision table to stdout + structured log. Exit code 1 if any cell falls below configured thresholds.
- Edge cases: missing golden PR fixture → skip with warning; API error mid-eval → record as `error` cell, do not fail entire run.

**FR-02 — LLM-as-judge with cross-provider rotation**
- Trigger: called by `rabbit:eval` after each review completes.
- Behaviour: Claude-generated reviews are judged by GPT; GPT-generated reviews are judged by Claude. The judge receives the expected findings + actual findings and returns a per-finding `match|miss|hallucination` verdict.
- Output: `JudgeResultDto` with per-finding verdicts used to compute recall/precision.
- Rationale: eliminates same-family sycophancy bias (ADR 0005).

**FR-03 — Schema v2: OpenAI-strict intersection**
- Trigger: workspace with `review_schema_version=v2` dispatches a review.
- Behaviour: `review_result_v2.json` defines: `walkthrough` (object: `scope` string, `cross_cutting_concerns` string|null, `reviewability_notes` string|null), `findings[]` (object: `path`, `line`, `end_line` int|null, `severity` high|medium|low, `category` bug|security|perf|maintainability, `title`, `message`, `suggested_fix` string|null), `nitpicks[]` (object: `path`, `line`, `message`), `risk_level` high|medium|low. Every property is `required`; optionals encoded as `{ "type": ["string","null"] }`. `additionalProperties: false` at every nesting level.
- Output: single schema file usable by both Anthropic (`tool_choice` strict) and OpenAI (`response_format: json_schema` strict).

**FR-04 — Prompt v2: few-shot exemplars**
- Trigger: v2 review call.
- Behaviour: `review_v2.txt` replaces the v1 silence-bias instructions with a high-recall stance. Embeds 5 positive exemplars (real Laravel code with expected Findings) and 2 negative exemplars (clean code where no Finding is expected) wrapped in `<example>` / `<counter_example>` tags. Exemplars are Laravel-flavored to match the primary codebase style.
- Output: system prompt loaded by both `ClaudeReviewer` and `OpenAiReviewer` when schema version is v2.

**FR-05 — Two-call draft then critique (same-provider)**
- Trigger: v2 review call completes draft.
- Behaviour: `LlmDriverInterface::critiqueDraft(DraftReviewDto): CritiqueResultDto`. The critic receives the original diff + the draft review and returns per-Finding verdicts (`approve|reject` + optional `reason`). Only Findings with `approve` reach `CommentPoster`. Rejected Findings are logged in `reviews_llm_calls` with `role=critique`. Critic uses the same provider as the drafter (no cross-provider).
- Output: filtered `ReviewResultDto` with only approved Findings.
- Error handling: critic call failure → fall back to posting the unfiltered draft (degraded but not silent). Logged with `error_class=critique_failed`.

**FR-06 — CommentPoster v2 path**
- Trigger: v2 filtered `ReviewResultDto`.
- Behaviour: Walkthrough posted as the summary comment body. Approved Findings posted as inline comments (same 25-cap, same `🤖 cdv-rabbit (AI generated):` prefix, same dedup logic). Nitpicks collapsed into a `<details><summary>Nitpicks (N)</summary>...</details>` block at the end of the summary comment. Never posted inline.
- Output: PR receives structured review matching the CONTEXT.md glossary definitions.

**FR-07 — Per-Workspace rollout gate**
- Trigger: admin PATCH `/workspaces/{slug}` with `review_schema_version=v2`.
- Behaviour: validated via FormRequest. Column persisted. Next `ReviewPullRequestJob::handle()` reads the workspace's `review_schema_version` and branches to the v1 or v2 pipeline path.
- Rollback: admin flips back to `v1`. No migration needed. In-flight v2 reviews complete normally.

**FR-08 — Agent Prompt per Finding (ADR 0006)**
- Trigger: v2 draft LLM emits `findings[].agent_prompt` (nullable, max 1500 chars) in the same tool-use call that produces `message` and `suggestion`.
- Behaviour: `agent_prompt` is excluded from the critic payload by `DraftReviewDto::toCriticInputArray()` (field whitelist) so the critic continues to verdict the anchor only; on approval, the original prompt rides through `withFindingsAtIndices()` and reaches `CommentPoster`. `CommentPoster::postInlineFinding()` appends a `<details><summary>🤖 Prompt for AI Agents</summary>` block at the comment footer with a PHP-fixed preamble (`"Verify each finding against the current code and only fix it if needed."`) and the `CommentSanitizer`-sanitized body inside a triple-backtick fence. Findings with `agent_prompt: null` (or whitespace-only) render with no `<details>` block — clean comment, no empty-prompt ceremony. Unresolved Findings (SCM 422 fallback) carry the same `<details>` block as a nested element inside the summary's unresolved-Findings section.
- Sanitizer behaviour: `CommentSanitizer::sanitize()` regex preserves `@<path>` references (slash-bearing) and strips `@<username>` mentions (no slash) — improvement applies globally to `message`, `suggestion`, `walkthrough`, and `nitpick.message` as a side benefit.
- Output: PR inline comment ready for copy-paste into Claude Code, Cursor, or any third-party coding agent without re-reading the PR.

### 5.2. Error and edge cases
- Critic call returns 429/5xx → fall back to unfiltered draft; log `critique_failed`.
- v2 review returns 0 Findings + 0 Nitpicks → post Walkthrough-only summary (analogous to AC26 for v1).
- Golden PR fixture has 0 expected findings → recall is trivially 1.0; precision is N/A (skip cell).
- Workspace switches from v2 to v1 mid-flight → in-flight v2 job completes; next job uses v1.

---

## 6. Non-Functional Requirements

- **Cost**: ~2x per-review due to critique call. Absorbed via `cost_per_review_factor` config applied to the cost reservation estimate. No schema change to `reviews_llm_calls` — the critique call is a separate row with `role=critique`.
- **Latency**: p95 target increases from 60s to 90s for v2 (draft ~45s + critique ~30s for 200-LOC PR). Acceptable for async pipeline.
- **Reliability**: critique failure is non-fatal (FR-05 fallback). v2 inherits all v1 retry/error handling.
- **Maintainability**: v1 and v2 coexist; v1 path is unchanged. `review_schema_version` enum is extensible to `v3` without migration.
- **AI quality**: recall >= 0.7 on golden corpus. Precision >= 0.8 (critic should remove > 80% of hallucinated Findings). Measured by `rabbit:eval`.

---

## 7. Data Model & Contracts

### 7.1. New migration
- `workspaces.review_schema_version` — `varchar` enum (`v1|v2`), default `v1`, not null. Added via `ALTER TABLE workspaces ADD COLUMN review_schema_version varchar(10) NOT NULL DEFAULT 'v1'`.
- `ReviewSchemaVersion` backed enum in `app/Enums/ReviewSchemaVersion.php`: `V1 = 'v1'`, `V2 = 'v2'`.

### 7.2. New frozen artifacts
- `config/cdv-rabbit/schemas/review_result_v2.json` — tool name `review_result_v2` (distinct from v1 per §3.0.2 cache invalidation rule).
- `config/cdv-rabbit/prompts/review_v2.txt` — high-recall prompt with few-shot exemplars.

### 7.3. New DTOs
- `App\Services\Llm\Dto\DraftReviewDto` — wraps the raw v2 tool output before critique (summary + findings + nitpicks + telemetry).
- `App\Services\Llm\Dto\CritiqueResultDto` — per-Finding `approve|reject` verdicts; exposes `approvedFindingIndices()` + `rejectedFindings(DraftReviewDto)`.
- `App\Services\Llm\Dto\ReviewFindingDto` — single Finding (path, line, severity, category, message, nullable suggestion).
- `App\Services\Llm\Dto\ReviewNitpickDto` — single Nitpick (path, line, message).
- `App\Services\Llm\Dto\ReviewSummaryV2Dto` — v2 summary (overview + risk_level + walkthrough + files_analyzed[]).
- LLM-judge verdicts are returned as plain arrays from `App\Services\Eval\LlmJudge::judge()` — no dedicated DTO class (kept light because the eval surface is a single consumer).

### 7.4. Extended contracts
- `LlmDriverInterface` gains: `reviewDiffV2(string, array, string): DraftReviewDto` AND `critiqueDraft(string, array, string): CritiqueResultDto`. Both implemented by `ClaudeReviewer` and `OpenAiReviewer`. Each driver also exposes `getReviewSystemPromptForVersion('v1'|'v2')`, `getReviewToolSchemaForVersion('v1'|'v2')`, `getCriticSystemPrompt()`, and `getCriticToolSchema()` so the job picks the right artifacts per call.
- `LlmCallRole` enum (PHP-side authoritative) gains `Draft` (`'draft'`) and `Critique` (`'critique'`) alongside existing `Triage|Review|Summary`. Persisted in `reviews_llm_calls.role` widened from `enum(...)` to plain `varchar(16)` via the W7-T2-followup migration `000004_widen_reviews_llm_calls_role.php`.
- `PromptBuilder::wrap(string $diff, array $prMetadata, bool $annotateLines = false): string` — extended with an opt-in `annotateLines` flag. When true, every `+` line in the diff is prefixed with its absolute head-side line number as `+[L<N>] <content>` via the private `annotateLineNumbers()` helper. v2 path passes `annotateLines: true`; v1 path stays `false` (AGENTS.md §4 v1 freeze).
- `App\Services\Review\DiffPositionResolver` — new opportunistic pre-flight resolver for `(path, line)` corrections. Constructor takes `list<FileDiff>`. `resolve(string $path, int $line): ?array{path,line}` snaps namespace-cased paths (`App/...`) to filesystem casing (`app/...`) via a case-insensitive lookup, AND snaps off-by-a-few lines to the nearest `+` line within ±5. Wired into `CommentPoster::postV2()` as an optional 5th argument; legacy callers pass null and get pass-through behaviour. Strictly additive: Findings only get better, never blocked.
- `CommentPoster::postV2()` — adds an SCM-failure fallback path. When the SCM driver throws `RuntimeException` on an inline post (typical: GitHub 422 "path could not be resolved"), the Finding is folded into a `<details>` block in the summary body rather than crashing the whole review. `tryPostInlineFinding()` is the new internal helper that wraps the post + logs `CommentPoster: inline post fell back to summary` at warning level.

### 7.5. Existing contracts preserved
- `review_result_v1.json` frozen (AC23 snapshot test unchanged).
- `review_v1.txt` frozen.
- v1 pipeline path in `ReviewPullRequestJob::handle()` unchanged.
- `CostReservationInterface` signature unchanged; reservation amount adjusted by `cost_per_review_factor`.

---

## 8. AI/Agent Design

### 8.1. Draft call (v2)
- Model: workspace's configured provider (Sonnet 4.6 for Anthropic, GPT-4o for OpenAI).
- System prompt: `review_v2.txt` (high-recall stance + 7 few-shot exemplars + line-number protocol).
- Tool/schema: `review_result_v2` with strict mode.
- Prompt caching: same `cache_control: ephemeral` pattern as v1 (§3.0.2). Tool name change (`review_result_v2`) automatically invalidates v1 caches.
- User message: XML envelope (`<pr_metadata>` + `<diff>`) as v1 (§3.0.5), but the `<diff>` body is **line-annotated**: every `+` line is prefixed with `+[L<N>] <content>` where `N` is the absolute head-side line number. The prompt instructs the model to copy `N` verbatim into `findings[].line` rather than counting. Annotation is performed by `PromptBuilder::annotateLineNumbers()` (called only on the v2 path).

### 8.2. Critique call (v2)
- Model: same provider and model as draft (same-provider constraint from ADR 0005).
- System prompt: `critic_v1.txt` — a **separate** frozen artifact under `config/cdv-rabbit/prompts/`. Cached as its own ephemeral prefix, independent of the draft's cache.
- Tool/schema: `critic_result_v1.json` — a separate frozen artifact under `config/cdv-rabbit/schemas/`. Same OpenAI-strict intersection rules as `review_result_v2.json` (required fields, no optionals, no extra properties).
- Input: the draft review's findings array + the original unified diff, packaged as the user message via `PromptBuilder::wrap()` (annotation not applied — the critic operates on Findings, not on raw `+` lines).
- Output: per-Finding verdict (`approve` / `reject` with `reason`).
- Failure handling: if the critique call throws (timeout, 5xx, schema validation failure), the job catches and posts the **unfiltered** draft (AC47). `error_class=critique_failed` is persisted in `reviews_llm_calls`. The review still completes; the author sees the draft's full Findings list.

### 8.3. Few-shot exemplar design
- 5 positive exemplars: real Laravel code snippets with known issues (N+1 query, missing authorization, SQL injection via raw query, missing validation, race condition in Redis). Each shows the expected Finding with correct severity/category.
- 2 negative exemplars: clean Laravel code (well-structured controller, properly scoped query) where the expected output is 0 Findings. Teaches the model that silence is correct when code is clean.
- Wrapped in `<example>` / `<counter_example>` tags per Anthropic prompting guidance.

### 8.4. Eval: LLM-as-judge
- Judge model: rotated cross-provider (Claude reviews judged by GPT-4o; GPT-4o reviews judged by Claude Sonnet 4.6).
- Judge prompt: receives `expected_findings.json` + actual review output. Returns per-finding `match|miss|hallucination`.
- Recall = matched / (matched + missed). Precision = matched / (matched + hallucinated).

---

## 9. UX & Interaction Design

### 9.1. PR comment format (v2)
Summary comment body:
```
🤖 cdv-rabbit (AI generated):

## Walkthrough
{walkthrough.scope}

{walkthrough.cross_cutting_concerns — if non-null}

{walkthrough.reviewability_notes — if non-null}

## Risk: {risk_level}

<details>
<summary>Nitpicks ({count})</summary>

- `{path}:{line}` — {message}
- ...

</details>
```

Inline comments: same format as v1 but with added `[{severity}] [{category}]` prefix before the message body.

### 9.2. Workspace settings
- New dropdown: "Review Schema Version" with options `v1 (classic)` / `v2 (high-recall)`. Default `v1`.

---

## 10. System Architecture & Integration

### 10.1. Pipeline flow (v2 path)
```
ReviewPullRequestJob::handle()
  → workspace.review_schema_version == 'v2'?
    → yes:
      1. Pre-flight (kill switch, cost reservation × cost_per_review_factor, PR state, diff-stat)
      2. Diff fetch → chunk → redact → XML wrap
      3. LlmDriver::reviewDiff() with v2 schema/prompt → DraftReviewDto
      4. LlmDriver::critiqueDraft(DraftReviewDto) → CritiqueResultDto
      5. Filter: only approved Findings
      6. CommentPoster::postV2(Walkthrough, approved Findings, Nitpicks)
      7. Telemetry: 2 rows in reviews_llm_calls (role=review + role=critique)
    → no: existing v1 path (unchanged)
```

### 10.2. Integration points
- `specs/ai-code-review-pipeline.md` — v2 extends the pipeline; v1 path preserved.
- `specs/multi-llm-provider-support.md` — both providers implement `critiqueDraft()`.
- `specs/cost-management-and-ceiling-alerts.md` — reservation estimate multiplied by `cost_per_review_factor`.
- `CONTEXT.md` — Review, Walkthrough, Finding, Nitpick glossary terms used throughout.

### 10.3. Deployment & configuration
- `workspaces.review_schema_version` defaults to `v1`. No workspace sees v2 until explicitly opted in.
- `config('cdv-rabbit.cost_per_review_factor')` defaults to 2.
- Eval harness (`rabbit:eval`) runs in CI or manually; not triggered by webhooks.

---

## 11. Validation: Acceptance Criteria & Test Strategy

### 11.1. Acceptance criteria (AC39..AC56)

| # | Criterion | How to verify | W7 task |
|---|---|---|---|
| AC39 | `php artisan rabbit:eval --schema=v2 --provider=anthropic` returns recall >= 0.7 on the 10 golden PRs. | Eval harness integration test (gated CI job against live API). | W7-T1 |
| AC40 | `php artisan rabbit:eval --schema=v2 --provider=openai` returns recall >= 0.7 on the 10 golden PRs. | Eval harness integration test (gated CI job against live API). | W7-T1 |
| AC41 | LLM-as-judge uses cross-provider rotation: Claude reviews judged by GPT, GPT reviews judged by Claude. No same-provider judge cell exists. | Unit test on `LlmJudge` routing logic. | W7-T1 |
| AC42 | `review_result_v2.json` passes both Anthropic strict tool use validation and OpenAI strict structured output validation (OpenAI-strict intersection: all properties `required`, optionals as `{ type: ['string','null'] }`). | Unit test: feed schema to both provider validators. Snapshot test (mirrors AC23 for v2). | W7-T2 |
| AC43 | `workspaces.review_schema_version` enum defaults to `v1`. A Workspace with `v1` never triggers the v2 pipeline path. | Feature test: dispatch review for v1 workspace, assert single LLM call (no critique), assert v1 comment format. | W7-T2 |
| AC44 | `review_v2.txt` contains exactly 5 positive + 2 negative few-shot exemplars, each wrapped in `<example>` or `<counter_example>` tags. Combined system prompt + tool schema exceeds 1024 tokens (cacheable prefix threshold). | Unit test: parse prompt for tag counts; token count assertion (mirrors AC21 precondition). | W7-T3 |
| AC45 | `LlmDriverInterface::critiqueDraft()` is implemented by both `ClaudeReviewer` and `OpenAiReviewer`. Critique uses the same provider as the draft (no cross-provider). | Architecture test (`pest --architecture`): both classes implement the method. Integration test: mock shows same provider for both calls. | W7-T4 |
| AC46 | Only Findings with `approve` verdict from the critic reach `CommentPoster`. Rejected Findings are excluded from inline comments but logged in `reviews_llm_calls` with `role=critique`. | Feature test: provide a draft with 5 Findings, mock critic to reject 2, assert 3 inline comments posted. | W7-T4 |
| AC47 | Critic call failure (timeout, 5xx) does NOT silence the review. Fallback: unfiltered draft is posted. `error_class=critique_failed` logged. | Feature test: mock critic to throw, assert draft Findings posted, assert error_class persisted. | W7-T4 |
| AC48 | Nitpicks are collapsed into a `<details>` block in the summary comment. Never posted as inline comments. | Unit test on `CommentPoster` v2 path. | W7-T4 |
| AC49 | Flipping `review_schema_version` from `v2` back to `v1` on a Workspace causes the next review to use the v1 pipeline path. No migration needed. | Feature test: create v2 workspace, flip to v1, dispatch, assert v1 path taken. | W7-T5 |
| AC50 | v2 prompt changes do not merge without a positive delta (or no regression) vs the golden baseline from `rabbit:eval`. Enforced by locked contract in AGENTS.md §4. | CI gate: `rabbit:eval --schema=v2` must exit 0 before merge. | W7-T5 |
| AC51 | Every v2 review posts a `pending` commit status on the PR head SHA at job start and a final `success` (no high-severity Findings) or `failure` (≥1 high-severity Finding) status at job end, recorded in `reviews.status_check_state`. Lets consumer repos (e.g. DocInt) gate auto-merge on `cdv-rabbit/review`. | Feature test: dispatch review, assert pending status posted; complete review with mocked Findings, assert correct final status; `status_check_state` column reflects both transitions. | W7-T5 |
| AC52 | Every Finding inline comment with non-null `agent_prompt` renders a `<details><summary>🤖 Prompt for AI Agents</summary>` block at the footer, containing the fixed preamble + the sanitized prompt body inside a triple-backtick fence. | Feature test on `CommentPoster::postV2`: build a Finding with `agentPrompt='In \`@app/Foo.php\` around lines...', post, assert body contains the `<details>` markers + preamble + body. | W7.5-T5 |
| AC53 | `CommentSanitizer::sanitize()` preserves `@<path>` references (slash-bearing) and continues stripping `@<username>` mentions (no slash). | Unit test: 4 dedicated cases (`@src/app/Foo.php` survives, `@alice` and `@cdv-team` strip, mixed message handles both correctly). | W7.5-T2 |
| AC54 | `agent_prompt` is excluded from the critic input payload by `DraftReviewDto::toCriticInputArray()` (field-whitelist), and `withFindingsAtIndices()` preserves the original prompt on approved Findings so it reaches `CommentPoster::postV2()` intact. | Two unit tests: (1) `toCriticInputArray()` output keys do NOT include `agent_prompt`; (2) `withFindingsAtIndices([0])` on a draft with non-null `agent_prompt` preserves the string verbatim. | W7.5-T4 |
| AC55 | Findings that fail to anchor inline (SCM 422 fallback) carry their `agent_prompt` as a nested `<details>` block inside the unresolved-Findings section of the summary comment. | Feature test: fake SCM driver throws RuntimeException on `postInlineComment`; assert summary body contains `Unresolved Findings (1)` + `Prompt for AI Agents` + sanitized prompt body. | W7.5-T6 |
| AC56 | Findings with `agent_prompt: null` (or whitespace-only) render WITHOUT a `<details>` block — the inline comment stays clean. | Two unit tests on `CommentPoster::postV2`: null prompt → no `Prompt for AI Agents` substring; whitespace-only prompt → same. | W7.5-T5 |

### 11.2. Test strategy

**Unit tests:**
- `tests/Unit/Services/Llm/Dto/DraftReviewDtoTest.php` — construction, serialization.
- `tests/Unit/Services/Llm/Dto/CritiqueResultDtoTest.php` — filtering logic.
- `tests/Unit/Services/Review/CommentPosterV2Test.php` — Walkthrough formatting, Nitpick collapsing, Finding cap.
- `tests/Unit/Eval/LlmJudgeTest.php` — cross-provider routing, verdict parsing.
- `tests/Unit/SchemaFreezeV2Test.php` — snapshot of `review_result_v2.json`.

**Feature/Integration tests:**
- `tests/Feature/Jobs/ReviewPullRequestJobV2Test.php` — full v2 pipeline with faked LLM + faked SCM.
- `tests/Feature/Eval/EvalCommandTest.php` — `rabbit:eval` with faked golden PRs + faked LLM responses.
- `tests/Feature/Workspaces/ReviewSchemaVersionTest.php` — PATCH + rollback + pipeline branching.

**Eval tests (gated CI, live API):**
- `tests/Eval/GoldenPrEvalTest.php` — runs `rabbit:eval` against live APIs; asserts recall/precision thresholds.

---

## 12. Telemetry, Observability & Evaluation Metrics

### 12.1. Per-review telemetry (v2 additions)
- `reviews_llm_calls` gains rows with `role=critique` (one per v2 review).
- `reviews_llm_calls.schema_version` — recorded so dashboards can filter v1 vs v2 call patterns.
- Structured log line includes `schema_version`, `critique_findings_approved`, `critique_findings_rejected`, `nitpick_count`.

### 12.2. Eval metrics
- Per (provider x schema_version) cell: recall, precision, mean Findings per review, mean Nitpicks per review, mean latency, mean cost.
- Stored as structured log output from `rabbit:eval`; no DB persistence in MVP.

### 12.3. Cost model
- v1: 1 LLM call per review.
- v2: 2 LLM calls per review (draft + critique). Cost reservation multiplied by `cost_per_review_factor` (default 2).
- Per-provider daily ceiling unchanged; the factor adjusts the per-review reservation estimate so the ceiling catches v2 cost sooner.

---

## 13. Security, Privacy & Compliance

### 13.1. LGPD posture (unchanged)
- Diff stays in `handle()` scope for both draft and critique calls. Critique receives the draft review JSON (no diff re-fetch).
- `RedactingFailedJobProvider` covers both calls (same job, same scope).
- `rabbit:lgpd-check` unchanged; no new data-at-rest vectors.

### 13.2. DPA mapping
- Critique call uses the same provider as the draft. No cross-provider call means no new DPA requirement. `OPENAI_DPA_URL` gate (check #9) covers OpenAI workspaces unchanged.

### 13.3. Prompt injection
- v2 schema (`review_result_v2`) uses `additionalProperties: false` at every level (same defense as v1).
- Critique call receives the draft review as data, not as instructions (XML-wrapped if needed).
- Few-shot exemplars are frozen artifacts, not user-controllable.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Mitigation |
|---|---|
| ~2x cost per review from critique call | `cost_per_review_factor` config; per-workspace ceiling unchanged; operator monitors cost delta. |
| ~1.5x latency per review | Acceptable for async pipeline; p95 target 90s vs 60s. |
| Critic may rubber-stamp hallucinated Findings (same-provider sycophancy) | Cross-provider judge in eval harness catches this; same-provider critic is a pragmatic MVP trade-off (ADR 0005). |
| Few-shot exemplars may not generalize beyond Laravel codebases | Exemplars are the starting point; eval harness quantifies generalization; workspace-specific exemplars are backlog. |
| Golden PR corpus too small (10 PRs) | Sufficient for MVP signal; corpus grows as v2 is piloted. |
| v1 → v2 migration could confuse developers used to the old format | Per-Workspace opt-in; admin controls rollout pace; Walkthrough is strictly additive. |
| LLM miscounts absolute line numbers in long diffs (~30+ lines), causing SCM to reject the inline anchor with 422 | `PromptBuilder::annotateLineNumbers()` prefixes every `+` line with `+[L<N>]` so the model copies, not counts. Validated live on DocInt PR #36 + #37. |
| LLM emits namespace-cased path (`App/...`) instead of filesystem casing (`app/...`); GitHub paths are case-sensitive | `DiffPositionResolver` opportunistically snaps path via case-insensitive lookup against actual diff paths, plus ±5-line snap to the nearest valid `+` line. |
| Off-the-happy-path inline post failures crash the whole review and post a "review failed" comment | `CommentPoster::postV2()` catches `RuntimeException` from the SCM driver and folds the affected Finding into a `<details>` block in the summary body; review still completes with `status_check_state=success` where applicable. |
| Eval harness cannot validate v2 baseline (ADR 0007) — `rabbit:eval` rejects `--schema=v2`; `LlmJudge` requires both Anthropic + OpenAI keys for cross-provider rotation | Mitigated by live-PR smoke against DocInt as the de facto quality signal during the gap. Action items in ADR 0007 §1-3 close it: wire v2 into `EvalCommand`, add a `same-provider` rotation flag, investigate the recall-zero signal surfaced by the standalone mini-eval on the OpenAI v2 path. |

**Open questions:**
- Should the critique call receive the full diff or only the draft review JSON? ADR 0005 says "original diff + draft review". Confirm during W7-T4 implementation.
- Should `rabbit:eval` support a `--baseline` flag to compare against a previous eval run? Useful but possibly backlog.

---

## 15. Change Log

- **v1.0 (2026-05-16 / pre-implementation)** — Initial spec authored from `grill-with-docs` session decisions locked in ADR 0005. 12 new ACs (AC39..AC50). No code changes yet; implementation gated on W7-T1 (eval harness ships first). Glossary terms (Review, Walkthrough, Finding, Nitpick) from CONTEXT.md used throughout. Dual-provider symmetry (Anthropic + OpenAI) documented in every section.
- **v1.1 (2026-05-16 / pre-implementation)** — Added AC51 (commit status check on PR head SHA) after the user surfaced that consumer repos (e.g. DocInt) have auto-merge enabled and v2's ~75s p95 latency would make reviews land post-merge without a status-check gate. Operational rationale in `.omc/plans/cdv-rabbit-review-pipeline-v2.md` §6.1. Total ACs now 13 (AC39..AC51).
- **v1.2 (2026-05-16 / post-implementation)** — Reconciled with the real-world dry-runs on DocInt PR #36 (1 file, +63) and PR #37 (8 files, +315). Three production-blocking bugs were fixed in-line on the branch and are now reflected here:
  - **`a497602`** — OpenAi-strict-intersection schema (`{type: ['string','null']}`) crashed `OpenAiReviewAgent::translateNode()` because the laravel/ai type-tree translator only handled single-string types. Fixed via a nullable-union detector that strips `null` and propagates via `Type::nullable()`. The W7-T4 no-op migration for `reviews_llm_calls.role` was also wrong (SQLite enforces `enum(...)` as a CHECK constraint) — replaced by a real `widen_reviews_llm_calls_role` migration. §7.4 contracts updated.
  - **`f8082b5`** — `CommentPoster::postV2()` now catches SCM 422s on inline posting and folds the rejected Finding into a `<details>` summary section instead of crashing the review. §7.4 and §14 updated. Tracks AC47-adjacent fail-open behaviour but at the posting layer, not the critic layer.
  - **`653c261`** — `PromptBuilder::annotateLineNumbers()` (new) prefixes every `+` line with `+[L<N>]` so the LLM doesn't count. `DiffPositionResolver` (new component in `app/Services/Review/`) opportunistically snaps namespace-cased paths to filesystem casing AND snaps off-by-a-few lines to the nearest `+` line within ±5. Both are strictly additive — Findings only get better. `review_v2.txt` gains a "Line-number protocol" section explaining the `[L<N>]` annotation contract. §7.4, §8.1, §14 updated.

  Outcome: DocInt PR #36 produced 2 inline comments (lines 25, 58) with correct severity/category/AI marker. DocInt PR #37 produced 7 inline comments + a structured HIGH-risk summary with Walkthrough naming all major concerns; recall ~7/13 substantive plants, zero false positives.

  No new ACs in v1.2 — the fixes operationalise existing AC45/AC46/AC47/AC48 behaviour against real APIs. Suite still 460/460 green; test fakes were updated where they bypassed the SDK type-translator (Task #6 in the session task list). All three fixes are atomic commits on the same branch and ride into Phase 7's single PR.
- **v1.4 (2026-05-16 / Phase 7.5 reconciliation — eval harness coverage gap)** — Standalone mini-eval against the 10 golden fixtures via OpenAI v2 produced **recall = 0.000** on 5 fixtures (5 rate-limited, never landed); live PR (DocInt #39) produced 2 inline Findings with correctly-rendered `agent_prompt` blocks. Root cause analysis in `docs/adr/0007-eval-harness-v2-coverage-gap.md`: `EvalCommand` rejects `--schema=v2` (exit 2), `LlmJudge::ROTATION` is hard-coded cross-provider with no same-provider fallback, and the recall-zero may be amplified by feeding unchunked diffs (mini-eval) vs the per-file chunking the production pipeline uses. Locked contract in `AGENTS.md` §4 marked **declared but non-load-bearing** until ADR 0007 §1-3 close. Agent Prompt feature merges with the gap stated openly — feature is strictly additive and cannot mask the recall regression (live PR #39 validates AC52..AC56 directly on production GitHub comments). No new ACs in v1.4; existing AC39 / AC40 / AC50 are now formally blocked by ADR 0007 follow-ups. §14 Risks gets a new row pointing at the ADR.
- **v1.3 (2026-05-16 / Phase 7.5 — Agent Prompt feature)** — Added CodeRabbit-parity `agent_prompt` per Finding. Decisions locked in `docs/adr/0006-agent-prompt-per-finding-coderabbit-parity.md` and `CONTEXT.md` glossary entry "Agent Prompt". Schema gains `findings[].agent_prompt` as `{ "type": ["string","null"], "maxLength": 1500 }` in `required[]` (OpenAI-strict intersection preserved). `review_v2.txt` gains a new "Agent Prompt rules" section before "## Examples"; the 5 positive few-shot exemplars each ship with an `agent_prompt` example; "Forbidden content" amended to allow `@<path>` inside `agent_prompt`. `CommentSanitizer::sanitize()` regex changes from `/@[A-Za-z0-9._-]+/` to `/@(?![\w._-]*\/)[\w._-]+/` to preserve `@<path>` (slash-bearing) while continuing to strip `@<username>` — improvement flows globally, not just to the new code path. `CommentPoster::postInlineFinding()` renders a new `<details>🤖 Prompt for AI Agents</summary>` block at the comment footer (preamble fixed in PHP, body sanitized); `CommentPoster::postV2Summary()` nests the same block inside unresolved-Findings bullets. Critic strip+rehydrate is automatic by construction: `DraftReviewDto::toCriticInputArray()` already whitelists fields (so `agent_prompt` never reaches the critic) and `withFindingsAtIndices()` preserves the original DTO objects (so the prompt rides on approved Findings). No code change in `ReviewPullRequestJob`. 5 new ACs (AC52..AC56); total ACs now 18 (AC39..AC56). `cost_per_review_factor` bump deferred to post-pilot. `rabbit:eval` re-run required on merge (locked contract AGENTS.md §4). Spec-authoring sources: grill-with-docs session decisions.
