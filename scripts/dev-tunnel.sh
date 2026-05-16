#!/usr/bin/env bash
# Start an ngrok tunnel pointing at the local Laravel dev server, sync the
# resulting public URL into .env, and print the webhook URLs to paste into the
# GitHub App settings on github.com.
#
# Requires: ngrok (with authtoken configured) + jq.
# Usage:
#   ./scripts/dev-tunnel.sh           # default port 8000
#   PORT=8080 ./scripts/dev-tunnel.sh

set -euo pipefail

PORT="${PORT:-8000}"
ENV_FILE="${ENV_FILE:-.env}"
NGROK_API="http://localhost:4040/api/tunnels"
NGROK_LOG="/tmp/cdv-rabbit-ngrok.log"
NGROK_PID_FILE="/tmp/cdv-rabbit-ngrok.pid"

cd "$(dirname "$0")/.."

if ! command -v ngrok >/dev/null 2>&1; then
    echo "FAIL ngrok not installed. https://ngrok.com/download" >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "FAIL jq not installed (apt install jq / brew install jq)." >&2
    exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
    echo "FAIL $ENV_FILE not found in $(pwd)." >&2
    exit 1
fi

# Reuse an existing tunnel if one is already running, otherwise start a new one.
if curl -fsS "$NGROK_API" >/dev/null 2>&1; then
    echo "  ngrok already running — reusing existing tunnel."
else
    echo "  Starting ngrok on port $PORT (log: $NGROK_LOG)…"
    nohup ngrok http "$PORT" --log=stdout >"$NGROK_LOG" 2>&1 &
    echo $! >"$NGROK_PID_FILE"

    # Wait for BOTH the API to be reachable AND a tunnel to actually be registered
    # (there's a brief race between the API returning 200 and the tunnel session
    # publishing its public_url into /api/tunnels).
    for _ in {1..30}; do
        if curl -fsS "$NGROK_API" 2>/dev/null \
            | jq -e '.tunnels[] | select(.proto == "https") | .public_url' >/dev/null 2>&1; then
            break
        fi
        sleep 0.5
    done

    if ! curl -fsS "$NGROK_API" 2>/dev/null \
        | jq -e '.tunnels[] | select(.proto == "https") | .public_url' >/dev/null 2>&1; then
        if grep -qE "ERR_NGROK_4018|authentication failed|requires a verified account" "$NGROK_LOG" 2>/dev/null; then
            cat >&2 <<'MSG'
FAIL ngrok authtoken is not configured.

Fix:
  1) Sign up (free):  https://dashboard.ngrok.com/signup
  2) Copy your token: https://dashboard.ngrok.com/get-started/your-authtoken
  3) Install it:      ngrok config add-authtoken <your-token>
  4) Retry:           pkill -f 'ngrok http' ; ./scripts/dev-tunnel.sh
MSG
            exit 1
        fi

        echo "FAIL ngrok did not become ready after 15s. Check $NGROK_LOG." >&2
        exit 1
    fi
fi

PUBLIC_URL="$(curl -fsS "$NGROK_API" | jq -r '.tunnels[] | select(.proto == "https") | .public_url' | head -n1)"

if [[ -z "$PUBLIC_URL" || "$PUBLIC_URL" == "null" ]]; then
    # Surface the real reason from the ngrok log when possible.
    if grep -qE "ERR_NGROK_4018|authentication failed|requires a verified account" "$NGROK_LOG" 2>/dev/null; then
        cat >&2 <<'MSG'
FAIL ngrok authtoken is not configured.

Fix:
  1) Sign up (free):  https://dashboard.ngrok.com/signup
  2) Copy your token: https://dashboard.ngrok.com/get-started/your-authtoken
  3) Install it:      ngrok config add-authtoken <your-token>
  4) Retry:           pkill -f 'ngrok http' ; ./scripts/dev-tunnel.sh
MSG
        exit 1
    fi

    echo "FAIL Could not extract https public URL from ngrok API." >&2
    echo "     Inspect $NGROK_LOG for the underlying ngrok error." >&2
    exit 1
fi

# Patch APP_URL in .env (keep a one-shot backup).
cp "$ENV_FILE" "${ENV_FILE}.bak.$(date +%s)"

if grep -qE "^APP_URL=" "$ENV_FILE"; then
    sed -i.tmp -E "s|^APP_URL=.*|APP_URL=${PUBLIC_URL}|" "$ENV_FILE"
    rm -f "${ENV_FILE}.tmp"
else
    echo "APP_URL=${PUBLIC_URL}" >>"$ENV_FILE"
fi

cat <<EOF

OK  Tunnel up:  $PUBLIC_URL
    APP_URL in $ENV_FILE updated.

Next steps (paste into your GitHub App at https://github.com/settings/apps):
  Callback URL : $PUBLIC_URL/scm/github/install/callback
  Webhook URL  : $PUBLIC_URL/scm/github/webhook
  Homepage URL : $PUBLIC_URL  (optional)

Bitbucket parity (if you also test BB webhooks):
  set BITBUCKET_WEBHOOK_BASE_URL=$PUBLIC_URL/bb/webhook in $ENV_FILE.

Now run the dev server in another terminal:
  composer run dev

Stop the tunnel:
  kill \$(cat $NGROK_PID_FILE)  # or just Ctrl+C the ngrok process
EOF
