# LGPD Data Protection Posture

## 1. Overview

### 1.1. Feature Name
**LGPD Data Protection Posture — Zero Customer Code At Rest**

### 1.2. One-sentence summary
Customer code (pull-request diffs) is fetched from the Bitbucket API on demand, lives only as a local variable inside `ReviewPullRequestJob::handle()`, and is never persisted to any DB column, log line, queue payload, or filesystem — enforced by structural controls (no `$this->diff` property, redacting failed-job provider, log-key scrubber).

### 1.3. Primary outcome
A failed `ReviewPullRequestJob` containing diff content in scope writes `<<REDACTED>>` to `failed_jobs.payload`, not the diff itself. Serializing the job stub produces a payload under 2KB with zero diff-shaped content.

### 1.4. Version & owner
`v1.0 – Phase 1 / Week 1 (tag phase-1-complete). Author: augusto-dmh.`

---

## 2. Context & Goals

### 2.1. Product/application context
cdv-rabbit processes customer source code (PR diffs) through an external LLM (Anthropic). Brazilian customers' code is intellectual property under LGPD; even short retention requires lawful basis + DPO sign-off + ROPA entry.

### 2.2. Problem statement
The simplest naive implementation would store the diff in the job payload, log it for debugging, and cache it for replay. Each of those is an LGPD-compliance violation waiting to happen. The user's locked decision is to never persist diff content at rest — refetch from Bitbucket when needed.

### 2.3. Goals
- **G1**: No DB column anywhere in the schema stores diff/code/patch content.
- **G2**: No queue job carries the diff as a serializable property.
- **G3**: No log line contains diff content (custom log-key scrubber).
- **G4**: Failed-job storage redacts diff-shaped keys before writing to `failed_jobs.payload`.
- **G5**: A pre-LLM secret-redaction pass strips well-known secret shapes before the diff leaves our perimeter to Anthropic.
- **G6**: Bitbucket API tokens and webhook secrets are encrypted at rest at the model layer.

### 2.4. Non-goals / out of scope
- End-to-end encryption between cdv-rabbit and Anthropic (Anthropic's TLS + ZDR DPA covers transit + retention).
- Per-workspace KMS key separation (single Laravel APP_KEY in MVP).
- Code-content hashing for cache reuse (deferred to v0.2 — would re-introduce content-derived storage).

---

## 3. Scope

### 3.1. In-scope functionality
- `app/Queue/RedactingFailedJobProvider.php` — wraps the default failed-job provider; redacts keys [diff, patch, content, body, hunk, code, source] recursively before persistence.
- `app/Events/RedactionApplied.php` — fired on each redaction for telemetry.
- `app/Services/Review/SecretRedactor.php` — pre-LLM regex pass for AWS, GCP, PEM private keys, JWT shapes.
- `Workspace` model: `bitbucket_token` and `webhook_secret` use the `encrypted:string` cast.
- `ReviewPullRequestJob` contract: diff lives only in `handle()` local scope; properties limited to `workspaceId/repositoryId/pullRequestNumber/headSha`.
- Tests proving each guard works under adversarial input.

### 3.2. Out-of-scope for this iteration
- DPO sign-off recording (operational, not code).
- Anthropic DPA tracking (operational).
- Data Processing Record (ROPA) generation (legal-team artifact).
- Per-LLM-call cost auditing for compliance reporting (covered by `cost-management-and-ceiling-alerts.md` from a different angle).

### 3.3. Dependencies
- Laravel's `encrypted:string` cast + `APP_KEY`.
- `FailedJobProviderInterface` from `illuminate/queue`.
- Pre-existing `BitbucketClient` for diff refetch.

---

## 4. User Personas & Use Cases

### 4.1. Personas
- **DPO / legal counsel** — needs to certify cdv-rabbit's data flow before pilot.
- **Internal CDV engineer** — needs the bot to work without worrying their code leaks elsewhere.
- **Future external customer (v0.3 SaaS)** — same concern, plus regulatory exposure to clients/auditors.

### 4.2. Primary use cases
- As the DPO, I want to inventory exactly where customer code lives in cdv-rabbit so I can sign off on the LGPD posture.
- As a CDV engineer, I want a failed review job NOT to leave my code visible in any database I or anyone else can query.
- As the operator, I want to forcefully scrub any historical state without database surgery.

### 4.3. Flow
PR opens → webhook arrives → job dispatches with safe properties → handle() refetches diff into local var → diff is chunked, redacted for secrets, wrapped in XML → sent to Anthropic → response parsed → comments posted to BB → job exits → diff variable garbage-collected. At no step is the diff persisted.

---

## 5. Functional Requirements

**FR-01 — `ReviewPullRequestJob` carries no diff**
- Inputs: 4 readonly properties (workspaceId, repositoryId, pullRequestNumber, headSha).
- Behaviour: `handle()` fetches diff into a local `$diff` variable; never assigns to `$this`.
- Output: serialized job payload under 2KB, containing no diff-shaped content.

**FR-02 — `RedactingFailedJobProvider` recursive key redaction**
- Inputs: payload string (JSON-encoded job state on failure).
- Behaviour: decode → walk JSON tree → for any key (case-insensitive) in [diff, patch, content, body, hunk, code, source], replace value with `<<REDACTED>>` → emit `RedactionApplied` event → re-encode → delegate to inner `DatabaseUuidFailedJobProvider::log`.
- Output: redacted row in `failed_jobs.payload`.

**FR-03 — `SecretRedactor` pre-LLM regex pass**
- Inputs: a single FileDiff's hunks.
- Behaviour: regex match against AWS AKID (exactly 20 chars), AWS secret in `aws_secret_access_key = ...` context, Google service-account `private_key`, PEM `-----BEGIN ... PRIVATE KEY-----` multi-line, JWT three-segment shape. Replace matches with `<<SECRET_REDACTED>>`. Return `RedactionResult { sanitized, count, matchedPatterns }`.
- Output: counted-and-sanitized diff suitable for Anthropic.

**FR-04 — Encrypted credentials at rest**
- Inputs: plaintext token/secret entering via the connect wizard.
- Behaviour: `Workspace::$casts['bitbucket_token'] = 'encrypted:string'` and `$casts['webhook_secret'] = 'encrypted:string'`. Laravel's encrypter ciphers on save, deciphers on retrieve. Raw DB column never contains plaintext.
- Output: ciphertext column; plaintext available only via model accessor.

**FR-05 — Log-key scrubber (descoped — see §14)**
- Original spec called for a custom `config/logging.php` formatter that strips diff-shaped keys from log payloads. **Decision**: deferred to v0.2 because no production code currently logs diff content; AC4 + AC16 + AC17 already prove the surface is clean. If a regression introduces diff logging, the formatter is the next backstop.

---

## 6. Non-Functional Requirements

- **Compliance**: posture must satisfy LGPD legitimate-interest + DPO sign-off (operational gate).
- **Reliability**: redaction must run on every failed-job persistence, no exceptions.
- **Performance**: regex pass adds ~5-15 ms per file in profile testing (acceptable; runs once per file per review).
- **Auditability**: `RedactionApplied` events can be logged or sent to a future ROPA-aware destination.

---

## 7. Data Model & Contracts

### 7.1. Columns explicitly NOT created
- `review_comments.body` / `.content` — not persisted; bot comment text lives only in Bitbucket.
- `reviews.diff` / `.patch` — never existed.
- `reviews_llm_calls.prompt` / `.response` — telemetry table holds counts only.
- `webhook_deliveries.payload_json` — only the BB UUID + status are stored, no body.

### 7.2. Encrypted columns
- `workspaces.bitbucket_token` (encrypted:string).
- `workspaces.webhook_secret` (encrypted:string).

### 7.3. Contracts
- `RedactingFailedJobProvider implements FailedJobProviderInterface` — proxies find/all/ids/forget/flush unchanged.
- `RedactionApplied` event payload: `workspace_id?: int, redactedKeys: string[]`.
- `RedactionResult` value object: `string $sanitized, int $count, string[] $matchedPatterns`.

---

## 8. AI/Agent Design

The pre-LLM `SecretRedactor` is the only AI-adjacent surface in this spec: it reduces what reaches Anthropic. The prompt-injection defense and tool-use schema enforcement live in `ai-code-review-pipeline.md` and `prompt-caching-and-strict-tool-use.md`.

---

## 9. UX & Interaction Design

No direct UX surface. The connect wizard's "token" field accepts plaintext; encryption at rest is invisible to the user. Future: in-UI badge showing "your token is encrypted at rest" + link to the LGPD posture page (backlog).

---

## 10. System Architecture & Integration

### 10.1. Service container bindings
- `queue.failer` → singleton of `RedactingFailedJobProvider` wrapping `DatabaseUuidFailedJobProvider`, registered in `AppServiceProvider::register()`.

### 10.2. Provider registrations
- `RedactionApplied` listeners (none in MVP; backlog: alert on >N redactions/hour).

### 10.3. Integration touch points
- `tenancy-and-workspace-isolation.md` — orthogonal access guard; LGPD is the storage guard.
- `ai-code-review-pipeline.md` — `SecretRedactor` runs inside `ReviewPullRequestJob::handle()` between diff fetch and Anthropic call.
- `bitbucket-cloud-integration.md` — encrypted token cast applies when the connect wizard persists the workspace's BB credentials.

---

## 11. Validation: Acceptance Criteria & Test Strategy

Plan acceptance criteria covered:

- **AC4** — Diff content never written to any DB column, log, or filesystem. Tests: schema audit + AC17 redaction + AC16 serialization.
- **AC16** — `serialize($job)` for `ReviewPullRequestJob` contains no diff-shaped content; serialized size < 2KB. Test: `tests/Unit/Jobs/JobSerializationLeakTest.php`.
- **AC17** — `failed_jobs.payload` row from a thrown job contains `<<REDACTED>>`, not the diff. Test: `tests/Feature/Queue/FailedJobRedactionTest.php`.
- **AC9** — Secret-shaped strings never leave the process toward Anthropic. Test: `tests/Unit/Services/Review/SecretRedactorTest.php` (datasets per pattern) + end-to-end coverage in `tests/Feature/Jobs/ReviewPullRequestJobTest.php`.

Workspace token encryption:
- `tests/Unit/Models/WorkspaceTokenEncryptionTest.php` — raw DB column ≠ plaintext; model accessor returns plaintext.

---

## 12. Telemetry, Observability & Evaluation Metrics

- `RedactionApplied` event count per workspace per day — proxy metric for "how often is our safety net firing?". A non-zero count is informational; a high count signals a regression or unusual workload.
- `reviews.secrets_redacted` column — per-review count of secret-redactor hits, persisted alongside cost telemetry.

---

## 13. Security, Privacy & Compliance

- **Lawful basis**: legitimate interest (improving code quality with developer consent within the workspace).
- **Anthropic transit/processing**: TLS + Anthropic's commercial terms (zero-data-retention available at enterprise tier).
- **International transfer**: data crosses to Anthropic's US infrastructure under SCC-equivalent contractual safeguards (operational, not code).
- **DPO sign-off**: required gate before GA pilot — listed in plan §12.

---

## 14. Risks, Trade-offs & Open Questions

| Risk / Trade-off | Status |
|---|---|
| Developer adds `$this->diff = $diff` in a future ReviewPullRequestJob refactor → AC16 test catches it on next run | Mitigated by AC16. |
| `RedactingFailedJobProvider` recursion depth could be exploited by pathological payload | Bounded by job-serialization shape; theoretical risk only. |
| `SecretRedactor` regex may miss novel secret formats | Documented as future work; high-entropy detection deferred. |
| Log-key scrubber descoped (FR-05) | Backstop; reintroduce on first regression. |
| Anthropic outage replays diff fetch under high load | Acceptable; refetch is bounded by BB rate limits + cost ceiling. |

Open: should `RedactionApplied` events page operators on burst? (Backlog — `alert-on-redaction-burst.md` proposed.)

---

## 15. Change Log

- **v1.0 (2026-05-13 / phase-1-complete)** — Initial Phase 1 + Phase 3 implementation. Commits: `6856629` + `bb5a1e5` (RedactingFailedJobProvider), `f5e2417` (encryption + AC16/AC17 tests). Pre-LLM `SecretRedactor` shipped in W3-T3 (commit in `eb74aca`'s parent).
