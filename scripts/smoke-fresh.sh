#!/usr/bin/env bash
#
# smoke-fresh.sh — wipe the dev PostgreSQL volume, restart stack, run full smoke.
#
# Usage (from repo root):
#   ./scripts/smoke-fresh.sh
#
# Env: same as smoke-sentry.sh (BASE_URL, SMOKE_USER, SMOKE_PASSWORD, …)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

bold() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m: %s\n' "$*" >&2; exit 1; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }

bold "1. Stop stack and delete the PostgreSQL volume (destructive)"
docker compose down -v --remove-orphans
ok "stack removed"

bold "2. Start stack (migrations on boot)"
docker compose up -d --build logswire

BASE_URL="${BASE_URL:-http://localhost:8080}"
for _ in $(seq 1 60); do
  if curl -fsS "${BASE_URL}/healthz" 2>/dev/null | grep -q '"db":"up"'; then
    ok "healthz ready"
    break
  fi
  sleep 2
done
curl -fsS "${BASE_URL}/healthz" | grep -q '"db":"up"' || fail "stack not healthy after wait"

bold "3. Automated smoke"
chmod +x scripts/smoke-sentry.sh
./scripts/smoke-sentry.sh
