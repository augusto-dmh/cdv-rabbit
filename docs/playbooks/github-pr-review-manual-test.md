# Manual test — GitHub PR review against `augusto-dmh/DocInt`

End-to-end smoke for the multi-SCM provider feature using a real GitHub
repository you own. Walks through registering the GitHub App, tunnelling
your localhost, connecting a workspace, and opening a PR that triggers a
real OpenAI (ChatGPT) review.

**Target repo:** https://github.com/augusto-dmh/DocInt
**Time:** ~15 min the first time, ~3 min on every subsequent run.

---

> **Note on the LLM provider:** the default for new workspaces is
> Anthropic Claude. This playbook switches the workspace to OpenAI
> (`llm_provider=openai`) in step 6 so the actual review call goes
> through the OpenAI API.

## 0. Pre-reqs

- Local Laravel dev stack working: PHP 8.4, Composer + Node deps installed.
  No Docker needed — the project ships with `DB_CONNECTION=sqlite`,
  `QUEUE_CONNECTION=database`, `CACHE_STORE=database` in `.env`, so jobs
  + cache + DB all live in `database/database.sqlite`. (If you've
  switched `QUEUE_CONNECTION=redis` for Horizon, run Redis your usual
  way and substitute `php artisan horizon` for the worker command in
  step 4 — otherwise ignore Horizon for this playbook.)
- **Run `php artisan migrate`** before starting. The dev SQLite file
  (`database/database.sqlite`) may pre-date the Phase 6 schema changes,
  so even if you've been running tests for a while, the dev DB likely
  lacks the new `scm_provider`, `scm_owner_slug`,
  `github_installation_id`, `scm_repo_id`, `scm_webhook_uuid`, and
  `scm_delivery_id` columns. Skipping this step surfaces later as
  `SQLSTATE[HY000]: General error: 1 table workspaces has no column
  named scm_provider` the moment you submit the New Workspace form.
- `ngrok` installed and authed (`ngrok config add-authtoken <your-token>`).
- `jq` installed.
- `OPENAI_API_KEY` valid in `.env` (the review job calls OpenAI for real).
- You're logged in as `augusto-dmh` on github.com in your browser.

---

## 1. Start the dev tunnel (do this first)

GitHub requires both **Webhook URL** and **Callback URL** to be filled
in at App creation time — they can't be blank or placeholders. So you
need a public URL ready before step 2.

In one terminal:

```bash
./scripts/dev-tunnel.sh
```

You'll see something like:

```
OK  Tunnel up:  https://abc-123.ngrok-free.app
    APP_URL in .env updated.
```

**Keep this terminal open** for the rest of the session. Copy that
public URL — you'll paste it into the App form in the next step.

> ⚠️ On the free ngrok plan the URL changes every session. After the
> first registration, every subsequent dev session means: re-run this
> script, then go to https://github.com/settings/apps/cdv-rabbit-bot-dev
> → **Edit** and update **Webhook URL** + **Callback URL** with the new
> ngrok URL. (Paid plan gives a stable subdomain and skips this step.)

## 2. Register the GitHub App (one-time)

Browse to https://github.com/settings/apps/new and fill in (substitute
`https://abc-123.ngrok-free.app` with your actual tunnel URL from step 1):

| Field | Value |
|---|---|
| GitHub App name | `cdv-rabbit-bot-dev` |
| Homepage URL | `https://abc-123.ngrok-free.app` |
| **Callback URL** | `https://abc-123.ngrok-free.app/scm/github/install/callback` |
| Setup URL | _(leave blank)_ |
| **Webhook URL** | `https://abc-123.ngrok-free.app/scm/github/webhook` |
| **Webhook secret** | generate something random (e.g. `openssl rand -hex 32`) — **save it** |
| Where can this GitHub App be installed? | **Only on this account** (it's a dev App for your `augusto-dmh` account only; "Any account" is for the eventual v0.3 self-service SaaS) |

**Permissions → Repository:**

| Permission | Access |
|---|---|
| Pull requests | Read and write |
| Issues | Read and write _(GitHub PR top-level comments use the issues API)_ |
| Contents | Read |
| Metadata | Read _(required, default)_ |

**Subscribe to events:** check **only `Pull request`**. The `installation`
event (which the controller uses to handle `installation.deleted` per
AC36) is delivered automatically to every GitHub App — it doesn't
appear in the subscription list and you don't need to opt in. Leave
everything else unchecked.

Click **Create GitHub App**. You'll land on the App's settings page
(`https://github.com/settings/apps/cdv-rabbit-bot-dev`) with a yellow
banner near the top:

> ⚠️ Registration successful. You must generate a private key in order
> to install your GitHub App.

From this same page, collect three things:

1. **App ID** — shown in the "About" section at the top of the page
   (e.g. `1234567`). Save it.
2. **App slug** — already visible in the page's URL after
   `/settings/apps/`. For our example, it's `cdv-rabbit-bot-dev`. Save it.
3. **Private key** — scroll down to the **Private keys** section near
   the bottom of the page and click **Generate a private key**. Your
   browser downloads
   `cdv-rabbit-bot-dev.YYYY-MM-DD.private-key.pem` (the date in the
   filename is today's date). Keep this `.pem` file — you'll paste its
   contents into `.env` in step 3.1. GitHub does not let you re-download
   it later; if you lose it, generate a new one from the same section
   and delete the old one.

---

## 3. Populate `.env`

Append (or update) these in `.env`. The straightforward ones first:

```dotenv
GITHUB_APP_ID=1234567
GITHUB_APP_SLUG=cdv-rabbit-bot-dev
GITHUB_APP_WEBHOOK_SECRET=<the secret you generated in step 2>
GITHUB_DPA_URL=https://docs.github.com/en/site-policy/privacy-policies/github-data-protection-agreement

OPENAI_API_KEY=sk-...
OPENAI_DPA_URL=https://openai.com/policies/data-processing-addendum
```

### 3.1. The tricky one — `GITHUB_APP_PRIVATE_KEY`

GitHub gave you a file like
`cdv-rabbit-bot-dev.2026-05-16.private-key.pem` when you clicked
**Generate a private key** in step 2. Its content looks like:

```
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
...lots of base64 lines...
zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz
-----END RSA PRIVATE KEY-----
```

**How to paste it into `.env`:**

1. Print the file:
   ```bash
   cat ~/Downloads/cdv-rabbit-bot-dev.*.private-key.pem
   ```

2. Select the **entire** output (from `-----BEGIN RSA PRIVATE KEY-----`
   through `-----END RSA PRIVATE KEY-----`, both header lines included).

3. In `.env`, paste it **literally — actual line breaks, no `\n`, no
   indentation** — wrapped in double quotes:

   ```dotenv
   GITHUB_APP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
   MIIEowIBAAKCAQEAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
   ...lots of base64 lines...
   zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz
   -----END RSA PRIVATE KEY-----"
   ```

   - Opening `"` goes **right before** `-----BEGIN RSA PRIVATE KEY-----`.
   - Closing `"` goes **right after** `-----END RSA PRIVATE KEY-----`,
     on the same line.
   - Every middle line stays flush-left (no leading spaces or tabs).
   - Laravel's dotenv parser (`vlucas/phpdotenv`) reads multi-line
     double-quoted values natively — no `\n` escaping needed.

4. Save the file.

**Sanity check** — these two snippets should pass:

```bash
php artisan config:clear

php artisan tinker --execute 'echo strlen(config("services.github.app_private_key")), PHP_EOL;'
# expected: a number around 1700-1800 (a 2048-bit RSA key in PEM form)

php artisan tinker --execute 'echo app(App\Services\Scm\Github\JwtSigner::class)->mint(), PHP_EOL;'
# expected: a JWT string in three dot-separated parts (xxxxx.yyyyy.zzzzz)
# if you see "Failed to sign GitHub App JWT" the .env value is malformed
```

If `strlen` is 0 or the JWT mint throws, common culprits are: missing
opening or closing `"`, an indented middle line, CRLF line endings
sneaked in by a Windows clipboard, or a stray space after one of the
header lines.

> The Anthropic LGPD check (#7) is independent of this test — leave
> `ANTHROPIC_DPA_URL` set if it already is, otherwise that check will
> show FAIL but only matters when a workspace uses `anthropic`.

Clear config cache so the new values are picked up:

```bash
php artisan config:clear
```

Sanity-check LGPD posture:

```bash
php artisan rabbit:lgpd-check
```

You may see `DPO sign-off on record (< 1 year)` fail — that's an operational
artefact unrelated to this test. The OpenAI + GitHub DPA checks should
read PASS once you switch the workspace to `llm_provider=openai` in step 6.

---

## 4. Boot the app

Two terminals (keep the tunnel one running too):

```bash
# terminal A: dev server + vite (whatever `composer run dev` wires up)
composer run dev
```

```bash
# terminal B: queue worker for the reviews queue
php artisan queue:work --queue=reviews
```

Since `QUEUE_CONNECTION=database`, the worker pulls from the SQLite
`jobs` table — no Redis required. If you flipped to Redis + Horizon
already, run `php artisan horizon` instead.

Now open your browser at **the ngrok URL** (e.g.
`https://palpably-systemizable-thi.ngrok-free.dev`) — **not**
`http://localhost:8000`. Log in.

> You don't need to edit `APP_URL` by hand — `./scripts/dev-tunnel.sh`
> from step 1 already overwrote it with the live ngrok URL (you saw
> `APP_URL in .env updated.` in its output). Confirm with
> `grep ^APP_URL= .env` if you want.
>
> Why it matters: Laravel issues your session cookie bound to the
> domain in `APP_URL`. If you log in via `localhost`, the cookie won't
> travel along when GitHub redirects your browser to the callback at
> `<ngrok>/scm/github/install/callback`, the `auth` middleware sees no
> session, and the install aborts.

---

## 5. Create the workspace

1. **Workspaces** → "+ New workspace"
2. Fill:
   - **Workspace name:** `DocInt`
   - **Slug:** `docint`
   - **SCM provider:** **GitHub Cloud**
3. Submit. You land on the workspace's Show page.

## 6. Switch the LLM provider to OpenAI

New workspaces default to `llm_provider=anthropic`. Switch to OpenAI
before the first review fires — two equivalent ways:

**UI:** on the workspace's Show page there's an **AI Provider** select.
Pick **OpenAI GPT** and submit (the `WorkspaceController::update`
PATCH persists it).

**Tinker:**

```bash
php artisan tinker --execute 'App\Models\Workspace::where("slug","docint")->update(["llm_provider" => "openai"]);'
```

Verify:

```bash
php artisan tinker --execute 'echo App\Models\Workspace::where("slug","docint")->first()->llm_provider;'
# expected: openai
```

---

## 7. Connect the GitHub App to your account

1. Click **Connect GitHub**.
2. On the ConnectGithub page click **Install on GitHub** — your browser
   POSTs to `/scm/github/install/start/docint`, cdv-rabbit signs a state
   token, and you're redirected to
   `https://github.com/apps/cdv-rabbit-bot-dev/installations/new?state=...`.
3. On GitHub: **Install** → choose **augusto-dmh** (your user account) →
   pick **Only select repositories** → **DocInt** → **Install**.
4. GitHub redirects back to `/scm/github/install/callback` →
   cdv-rabbit persists the `installation_id`, runs `verifyCredentials`
   (hits `GET /installation/repositories` once), marks the workspace
   `health=healthy`, redirects you to `/workspaces/docint`.

You should now see the workspace with `health=healthy`.

---

## 8. Workaround — set the SCM owner slug

The `Show.vue` "Sync repositories" button is gated on `scm_owner_slug`.
For a Bitbucket workspace, this gets populated by the connect wizard.
For GitHub, the install callback doesn't currently populate it. Patch
it manually for now:

```bash
php artisan tinker --execute 'App\Models\Workspace::where("slug", "docint")->update(["scm_owner_slug" => "augusto-dmh"]);'
```

Reload `/workspaces/docint`. The **Sync repositories** button is now visible.

---

## 9. Sync + enable the DocInt repo

1. Click **Sync repositories**. Behind the scenes:
   `GithubDriver::listRepositories()` calls `GET /installation/repositories`,
   maps each entry to a `RepositoryDto`, and upserts into the
   `repositories` table keyed on `scm_repo_id` (GitHub's numeric repo id).
2. **DocInt** appears in the repos table.
3. Click **Enable** on the DocInt row. For Bitbucket this would call
   `registerWebhook` — on GitHub this is a no-op (the App-level webhook
   already routes events to your tunnel).

The repo row should be `enabled=true`. Verify:

```bash
php artisan tinker --execute '\App\Models\Repository::where("full_name", "augusto-dmh/DocInt")->first(["scm_repo_id", "full_name", "enabled"])->toArray() |> print_r(...)'
```

---

## 10. Open a PR on DocInt

On https://github.com/augusto-dmh/DocInt:

1. Create a small change on a branch (e.g. `chore/cdv-rabbit-test`). A
   one-file, ~10-line change is enough to exercise the pipeline without
   eating OpenAI tokens.
2. Open a PR against `main` (or whatever the default branch is).

Within ~2 seconds the GitHub App's webhook fires
`pull_request.action=opened` → POST to
`https://<ngrok>/scm/github/webhook` → cdv-rabbit:

1. Verifies `X-Hub-Signature-256` against `GITHUB_APP_WEBHOOK_SECRET`.
2. Resolves the repo by `repository.id` (numeric GH id) against
   `repositories.scm_repo_id`.
3. Inserts a `webhook_deliveries` row with `scm_provider=github_cloud`.
4. Dispatches `ReviewPullRequestJob` onto the `reviews` queue.

Horizon picks it up; the job:

1. Mints an installation access token (cached 50 min).
2. Calls `getPullRequest`, `getChangedFiles`, `getDiff` against the
   GitHub API.
3. Sends the diff through `OpenAiReviewer` (real OpenAI call) — uses
   `gpt-4o` with `response_format: json_schema` strict mode for
   structured output.
4. Posts the result back: a top-level comment via
   `POST /repos/augusto-dmh/DocInt/issues/{n}/comments` and any inline
   comments via `POST /repos/.../pulls/{n}/comments` with `commit_id`
   set to the PR's head SHA.

---

## 11. Verify

**On the PR page** — you should see a comment from `cdv-rabbit-bot-dev[bot]`
starting with `🤖 cdv-rabbit (AI generated):` within ~30-60s of opening
the PR.

**In the queue worker terminal** — you should see
`App\Jobs\ReviewPullRequestJob` log a `Processed` line within a few
seconds of the webhook arriving. (If you opted into Horizon instead,
browse `https://<ngrok>/horizon` → Recent Jobs.)

**In the UI** — `/workspaces/docint/reviews` lists the review.

**In the DB** — verify the audit trail:

```bash
php artisan tinker --execute '
echo "webhook_deliveries:\n";
print_r(\DB::table("webhook_deliveries")->orderByDesc("id")->limit(3)->get(["scm_delivery_id","scm_provider","status"])->toArray());
echo "\nreviews:\n";
print_r(\DB::table("reviews")->orderByDesc("id")->limit(3)->get(["status","pull_request_number","head_sha"])->toArray());
echo "\nreview_comments:\n";
print_r(\DB::table("review_comments")->orderByDesc("id")->limit(5)->get(["file_path","line","comment_type","bitbucket_comment_id"])->toArray());
'
```

Expected:
- `webhook_deliveries[0].scm_provider == 'github_cloud'`
- `webhook_deliveries[0].status == 'dispatched'`
- `reviews[0].status == 'posted'`
- `review_comments` has 1 summary + N inline rows (N ≤ 25 per AC5)
- `reviews_llm_calls[0].provider == 'openai'` and `model == 'gpt-4o'`
  (or whichever model is wired in `config/cdv-rabbit.models.review`)

---

## 12. Test the uninstall path (AC36)

On https://github.com/settings/installations → find `cdv-rabbit-bot-dev`
→ **Uninstall**. GitHub fires `installation.action=deleted` to your
webhook. Expected effects:

- `workspaces.docint.github_installation_id` → `NULL`
- `workspaces.docint.health` → `unhealthy`
- All repos for that workspace → `enabled=false`

Re-install to recover: repeat step 7.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 401 on the webhook | `GITHUB_APP_WEBHOOK_SECRET` mismatch | Re-paste the secret from the App page into `.env`, `php artisan config:clear` |
| 404 webhook | URL still pointing to old ngrok | Re-paste the new ngrok URL in the App's Webhook URL field |
| Callback shows 403 | Replayed state token (nonce is single-use) | Click **Install on GitHub** again from the workspace page |
| Callback shows 409 | This installation_id is already mapped to another Workspace row | Either reuse that workspace or uninstall + reinstall the App |
| Review job fails on `getDiff` | App not granted access to DocInt | On github.com/settings/installations → **Configure** → grant repo access |
| Webhook arrives but no review starts | Worker not running (`php artisan queue:work` terminal closed) | Restart the worker on the `reviews` queue |
| Worker logs `Connection refused [tcp://127.0.0.1:6379]` | You set `QUEUE_CONNECTION=redis` without Redis running | Either start Redis or revert to `QUEUE_CONNECTION=database` |
| Review job fails with `401 from OpenAI` | `OPENAI_API_KEY` wrong or missing | Re-paste, `php artisan config:clear` |
| LGPD check #9 fails (OpenAI) | `OPENAI_DPA_URL` env var empty | Set it in `.env` (any valid URL marking that you accepted the DPA) |
| Review uses Claude instead of GPT | Forgot step 6 (workspace still on `llm_provider=anthropic`) | Run the tinker update from step 6 |
| No comment posted | Comment cap reached (25) OR kill switch on OR cost ceiling hit | Check `reviews_llm_calls` rows and `reviews.error_message` |
| Comment posts but reads "Bitbucket comment" anywhere | UI label leftover; the persisted column `review_comments.bitbucket_comment_id` was kept BB-named on purpose (not in Phase 6 rename scope) | Cosmetic; ignore for the test |

---

## Cleanup

```bash
# stop the tunnel
kill $(cat /tmp/cdv-rabbit-ngrok.pid) 2>/dev/null || true

# uninstall the App on github.com if you don't want it tracked
#   github.com/settings/installations → Uninstall

# wipe the test workspace
php artisan tinker --execute '\App\Models\Workspace::where("slug","docint")->delete();'
```

Your `.env` had a backup created by `dev-tunnel.sh` at
`.env.bak.<timestamp>` — restore it if you want to revert `APP_URL`.
