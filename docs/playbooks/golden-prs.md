# Golden-PR Corpus — Curation Playbook

The golden-PR corpus is the offline oracle that `php artisan rabbit:eval` uses to grade every v2 prompt / schema / pipeline change against. It is the operationalisation of the AC50 locked contract in `AGENTS.md` §4: **v2 prompt or schema changes do not merge without a positive delta (or no regression) vs this corpus**. A corpus that is too small or too biased produces a misleading baseline; a corpus that is high-quality and diverse is the single most valuable investment in long-term review quality.

This playbook is the protocol for adding a new fixture without my help. Follow each step.

## Corpus targets (per ADR 0005 + spec §11)

10 golden fixtures, distributed across source repos:

| Source repo | Quota | Currently seeded | Notes |
|---|---|---|---|
| `augusto-dmh/DocInt` | 5 | 3 (PR #35, PR #31, PR #28) | The primary review consumer; rich Laravel surface; high signal. |
| `augusto-dmh/cdv-rabbit` (this repo) | 3 | 0 | Self-eval: dogfood the reviewer on its own history. |
| `augusto-dmh/intranet-cdv` | 2 | 0 | Diversity across a larger / older codebase. |

Diversity is more important than count. A corpus of 10 PRs that are all controller-slim refactors gives less signal than 5 PRs covering: a controller refactor, a migration, a fix, a feature, and a security-sensitive change.

## Quality bar for a fixture

A fixture is only useful if the `expected_findings.json` is **what a competent human reviewer would actually have flagged on that PR**, not what cdv-rabbit currently emits. Every expected Finding must satisfy all four of these:

1. **Real**. There is a concrete, line-anchored issue on a `+` line of the diff.
2. **Specific**. Identifies a specific problem, not a generic "could be improved".
3. **Actionable**. Includes a direction the author could move in.
4. **Justified**. The `rationale` field explains why a human reviewer would flag it.

If you cannot satisfy all four, don't include the Finding. Better to ship a fixture with 2 expected Findings than 8 weak ones — the eval harness's recall/precision math is corrupted by noisy expectations.

## Anatomy of a fixture

Each fixture lives in its own directory under `tests/Eval/golden/{fixture_id}/` and contains exactly three files:

```
tests/Eval/golden/
  docint-pr-35/
    diff.patch             # unified diff, captured from `git diff`
    pr_metadata.json       # title / branch / author / size / capture source
    expected_findings.json # human-curated expected Findings + walkthrough mentions
```

### `diff.patch`

Plain `git diff` output between the PR's base sha and head sha. Capture verbatim — do not edit. Example:

```bash
cd /path/to/source-repo
git diff <base-sha>..<head-sha> > /path/to/cdv-rabbit/tests/Eval/golden/<fixture-id>/diff.patch
```

If the source repo isn't checked out locally, use `gh pr diff <number> --repo owner/repo > diff.patch`.

### `pr_metadata.json`

```json
{
  "fixture_id": "docint-pr-31",
  "source_repo": "augusto-dmh/DocInt",
  "pr_number": 31,
  "title": "refactor: slim DocumentController via service extraction",
  "branch": "refactor/slim-document-controller",
  "base": "main",
  "author": "augusto-dmh",
  "file_count": 20,
  "additions": 1785,
  "deletions": 513,
  "captured_from": "git diff 9aae890..82277a3 in /path/to/DocInt"
}
```

The `captured_from` field is for reproducibility — record exactly which shas were diffed and from which checkout.

### `expected_findings.json`

```json
{
  "fixture_id": "docint-pr-31",
  "pr_summary": "Short prose paragraph describing what the PR does. Helps reviewers later understand why each expected Finding belongs.",
  "expected_findings": [
    {
      "path": "app/Services/Documents/DocumentBulkReviewService.php",
      "line_range": [1, 306],
      "severity": "medium",
      "category": "bug",
      "rationale": "Why a human reviewer would flag this. Should reference the specific concern, not just the file.",
      "must_find": true
    }
  ],
  "expected_walkthrough_mentions": [
    "phrases the Walkthrough should mention — at least one cross-cutting concern"
  ]
}
```

Field semantics:
- `line_range`: `[start, end]` — the range within the file the Finding should anchor on. `rabbit:eval`'s LLM-as-judge matches with a tolerance of ±5 lines.
- `severity` / `category`: must match the v2 schema enum exactly (`high|medium|low` and `bug|security|perf|maintainability`).
- `must_find: true` Findings count against recall when missed; `must_find: false` are "bonus" — useful for testing precision without inflating expected-recall denominators.
- `rationale`: only stored in the fixture; never sent to the LLM under eval. It exists so a future maintainer can audit the corpus.
- `expected_walkthrough_mentions`: strings the Walkthrough section should reference. The judge does substring matching (not strict regex).

## Step-by-step protocol for adding a new fixture

1. **Pick a candidate PR.** Aim for one that varies the corpus (a different category, a different size, a different repo from the existing fixtures). Cap at ~2000 lines added; very large PRs are awkward for the LLM context and provide diluted signal.
2. **Capture the diff** with `git diff base..head` or `gh pr diff` (above).
3. **Read the diff yourself.** Before writing any expected Finding, read every `+` line. Note the things that concern you.
4. **Filter to the 2–6 highest-value Findings.** Don't list everything — list the ones a competent reviewer would actually post inline. The four-part quality bar above is the gate.
5. **Write `expected_findings.json`** with rationale on every Finding.
6. **Write `pr_metadata.json`** including the exact shas you diffed.
7. **Verify the fixture loads** via the existing tests:
   ```bash
   php artisan test --compact --testsuite=Unit,Feature
   ```
   The `GoldenFixtureTest::it loads every fixture directory under the corpus root` test will assert your new fixture is discoverable.
8. **Optionally validate against v1 baseline** to see what cdv-rabbit currently produces vs. what you expect:
   ```bash
   RABBIT_EVAL_LIVE=1 php artisan rabbit:eval --provider=anthropic --schema=v1 --filter=<fixture-id>
   ```
   (Requires API keys + budget; not gated automatically.)
9. **Open a small PR** with just the fixture directory. Title shape: `feat(eval): seed golden PR <fixture-id> (source repo <repo>)`. Body should explain why the PR was chosen and which concerns the expected Findings cover.

## Anti-patterns

- **Don't list every nit.** Nitpicks live in v2's `nitpicks` array, not in expected Findings.
- **Don't expect a Finding on a file the PR doesn't actually change.** The reviewer is scope-locked to `+` lines.
- **Don't write rationales that just paraphrase the message.** Rationales explain the reasoning a human reviewer would apply — they are documentation for future maintainers, not boilerplate.
- **Don't seed two fixtures that share the same content** (e.g. two PRs from the same branch family that have identical diffs). The corpus is meant to span behaviours; duplicates inflate the count without adding signal.
- **Don't include vendor / lockfile / generated files in the diff capture.** The fixture should reflect what cdv-rabbit will actually see — scope-filtered by `SkipRules`.

## Roadmap

Current seed (3 of 10):
- `docint-pr-35` — refactor: extract dashboard aggregations + cross-tenant guard trait (8 files, 233/-110). 4 expected Findings covering trait abort-code info-leak, aggregator N+1 risk, enum back-compat, three-orthogonal-refactors walkthrough concern.
- `docint-pr-31` — refactor: slim DocumentController via service extraction (20 files, 1785/-513). 5 expected Findings covering transactional boundaries on bulk operations, N+1 in extracted bulk service, authorization-check loss during extraction, presenter eager-loading, form-request sharing.
- `docint-pr-28` — fix: pdf viewer blank canvas (6 files, 30/-4). 4 expected Findings covering npm postinstall portability, Map.prototype polyfill safety, hardcoded asset URLs vs subpath deploys, race-window in renderScale watcher. **Fix-shape** PR — exercises reviewer behaviour on small targeted changes, distinct from the two large refactor fixtures.

Suggested next fixtures (do not require my involvement — follow the protocol above):
- `docint-pr-29` — `test: pin architectural seams` — test-only change. Tests that the reviewer correctly produces few/no Findings on test additions.
- `cdv-rabbit/<commit>` — pick any commit from this repo's main history that you have an opinion on. Self-eval helps catch the reviewer being too lenient on its own code.
- `intranet-cdv/<commit>` — older Laravel surface. Tests generalisation.
