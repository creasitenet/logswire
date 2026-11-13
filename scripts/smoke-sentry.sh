#!/usr/bin/env bash
#
# smoke-sentry.sh — bootstrap minimal + single-event envelope ingest.
# Prerequisite: stack up (`docker compose up -d --build`).
#
# Usage (from repo root):
#   ./scripts/smoke-sentry.sh
#
# Env (optional):
#   BASE_URL=http://localhost:8080
#   SMOKE_USER=smokeadmin
#   SMOKE_PASSWORD=...        (defaults to a test password on localhost only)
#   PROJECT_NAME=Smoke Test

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BASE_URL="${BASE_URL:-http://localhost:8080}"
SMOKE_USER="${SMOKE_USER:-smokeadmin}"
SMOKE_PASSWORD="${SMOKE_PASSWORD:-}"
PROJECT_NAME="${PROJECT_NAME:-Smoke Test}"

bold() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m: %s\n' "$*" >&2; exit 1; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }

# This script creates an admin account. Never use the known test password on a
# non-local instance; require the operator to provide one explicitly.
if [[ -z "$SMOKE_PASSWORD" ]]; then
  if [[ "$BASE_URL" == http://localhost:* || "$BASE_URL" == http://127.0.0.1:* || "$BASE_URL" == "http://[::1]"* ]]; then
    SMOKE_PASSWORD='smoke-test-pass-12'
  else
    fail "Set SMOKE_PASSWORD explicitly when BASE_URL is not localhost."
  fi
fi

dc() { docker compose exec -T logswire "$@"; }

bold "1. Health"
HEALTH=$(curl -fsS "${BASE_URL}/healthz") || fail "healthz unreachable (is docker compose up?)"
echo "$HEALTH" | grep -q '"db":"up"' || fail "db not up: $HEALTH"
ok "healthz ok"

bold "2. Owner (idempotent)"
if dc bin/console app:owner:create "$SMOKE_USER" --password="$SMOKE_PASSWORD" --admin >/dev/null 2>&1; then
  ok "owner created"
else
  ok "owner already present (skipped create)"
fi

bold "3. Project"
# Reuse latest smoke project when present (each create otherwise adds smoke-test-2…N).
SMOKE_SLUG_PREFIX="${SMOKE_SLUG_PREFIX:-smoke-test}"
EXISTING=$(dc bin/console dbal:run-sql "SELECT slug, public_key FROM projects WHERE slug = '${SMOKE_SLUG_PREFIX}' OR slug LIKE '${SMOKE_SLUG_PREFIX}-%' ORDER BY created_at DESC LIMIT 1" -n 2>/dev/null || true)
ROW=$(printf '%s\n' "$EXISTING" | grep -E '^  [a-z0-9-]' | tail -1 || true)
SLUG=$(printf '%s\n' "$ROW" | awk '{print $1}')
KEY=$(printf '%s\n' "$ROW" | awk '{print $2}')
if [[ -z "$SLUG" || -z "$KEY" ]]; then
  OUT=$(dc bin/console app:project:create "$PROJECT_NAME" 2>&1 | tail -5)
  SLUG=$(printf '%s\n' "$OUT" | sed -n 's/^slug:[[:space:]]*//p')
  KEY=$(printf '%s\n' "$OUT" | sed -n 's/^public_key:[[:space:]]*//p')
  [[ -n "$SLUG" && -n "$KEY" ]] || fail "could not parse project output:\n$OUT"
  ok "project created slug=$SLUG"
else
  ok "project reused slug=$SLUG"
fi

bold "4. Envelope ingest"
ENVL=$(mktemp)
trap 'rm -f "$ENVL"' EXIT
python3 - "$ENVL" <<'PY'
import json, sys, uuid
out = sys.argv[1]
eid = uuid.uuid4().hex
header = json.dumps({"sent_at": "2026-01-01T00:00:00.000Z"}) + "\n"
event = json.dumps({
    "event_id": eid,
    "timestamp": "2026-01-01T00:00:00.000Z",
    "level": "error",
    "platform": "bash",
    "message": "smoke-sentry.sh",
    "exception": {"values": [{"type": "SmokeError", "value": "automated smoke", "stacktrace": {"frames": [
        {"filename": "smoke.sh", "function": "main", "lineno": 1, "in_app": True},
    ]}}]},
}) + "\n"
item_h = json.dumps({"type": "event", "length": len(event.encode()), "content_type": "application/json"}) + "\n"
open(out, "w").write(header + item_h + event)
PY

RESP=$(curl -sS -w "\n%{http_code}" \
  -H "X-Sentry-Auth: Sentry sentry_version=7, sentry_key=${KEY}" \
  -H "Content-Type: application/x-sentry-envelope" \
  --data-binary "@$ENVL" \
  "${BASE_URL}/api/${SLUG}/envelope/")
BODY=$(printf '%s\n' "$RESP" | sed '$d')
CODE=$(printf '%s\n' "$RESP" | tail -n 1)
[[ "$CODE" == "202" ]] || fail "expected 202, got $CODE: $BODY"
ok "ingest 202"

bold "5. Issue persisted"
ROWS=$(dc bin/console dbal:run-sql "SELECT title FROM issues WHERE title LIKE '%SmokeError%' ORDER BY last_seen_at DESC LIMIT 1" 2>/dev/null || true)
echo "$ROWS" | grep -q SmokeError || fail "issue row not found:\n$ROWS"
ok "issue persisted"

bold "6. UI gate"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/issues")
[[ "$CODE" == "302" || "$CODE" == "303" ]] || fail "expected redirect to login, got $CODE"
ok "issues protected ($CODE)"

bold "7. Issue detail (login + issue row)"
ISSUE_ID=$(dc bin/console dbal:run-sql "SELECT id FROM issues ORDER BY last_seen_at DESC LIMIT 1" -n 2>/dev/null | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
[[ -n "$ISSUE_ID" ]] || fail "could not read issue id from the database"

COOKIE_JAR=$(mktemp)
LOGIN_HTML=$(mktemp)
trap 'rm -f "$ENVL" "$COOKIE_JAR" "$LOGIN_HTML"' EXIT
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "${BASE_URL}/" -o "$LOGIN_HTML"
CSRF=$(sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' "$LOGIN_HTML" | head -1)
[[ -n "$CSRF" ]] || fail "could not read login CSRF token"
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "${BASE_URL}/login" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode "_username=${SMOKE_USER}" \
  --data-urlencode "_password=${SMOKE_PASSWORD}" \
  --data-urlencode "_csrf_token=${CSRF}" \
  -o /dev/null -w '%{http_code}\n' | grep -qE '^(302|303)$' || fail "login failed"

DETAIL_CODE=$(curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' "${BASE_URL}/issues/${ISSUE_ID}")
[[ "$DETAIL_CODE" == "200" ]] || fail "issue detail expected 200, got $DETAIL_CODE for /issues/${ISSUE_ID}"
ok "issue detail 200 (${ISSUE_ID})"

bold "Smoke passed"
