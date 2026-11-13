#!/usr/bin/env bash
#
# demo-traffic.sh — push realistic, grouped Sentry events into a Logswire project.
#
# Useful to exercise the issues list, stacktraces, filters and the 24 h overview
# before wiring a real Sentry SDK. Works against any reachable Logswire instance:
# the DSN is copied from Projects → "DSN", so the script speaks the same envelope
# protocol as the SDKs.
#
# Usage:
#   ./scripts/demo-traffic.sh --dsn 'http://<key>@localhost:8080/<slug>'
#   ./scripts/demo-traffic.sh --dsn 'https://<key>@logs.example.com/<slug>' --events 100 --spread 120
#   ./scripts/demo-traffic.sh --dsn '...' --loop --interval 10
#   ./scripts/demo-traffic.sh --dsn '...' --only frontend-js,checkout-deadlock
#
# Options:
#   --dsn DSN          Project DSN (required unless SLUG/KEY/BASE_URL are set).
#   --url URL          Ingest base URL when it differs from the DSN host
#                      (e.g. DSN has the public host, script runs on localhost).
#   --events N         Total events to send (default: 30, max per batch: 50).
#   --spread MINUTES   Spread event timestamps over the last N minutes (default: 90).
#   --batch N          Events per envelope/HTTP request (default: 10, max: 50).
#   --loop             Keep sending random batches until Ctrl-C.
#   --interval SECONDS Delay between batches in loop mode (default: 5).
#   --only LIST        Comma-separated scenario ids (see --help for the list).
#   --seed SEED        Seed the generator for reproducible runs.
#   -h, --help
#
# Env equivalents: DSN, BASE_URL, SLUG, KEY, DEMO_EVENTS.
#
# Requires: bash, curl, python3. No Composer autoload, no SDK.
#
# Note: the project rate limiter allows 60 ingest requests/minute. Batching keeps
# a normal run well under it; use --batch 1 only for a handful of events.

set -euo pipefail

DSN="${DSN:-}"
BASE_URL="${BASE_URL:-}"
SLUG="${SLUG:-}"
KEY="${KEY:-}"
EVENTS="${DEMO_EVENTS:-30}"
SPREAD=90
BATCH=10
LOOP=0
INTERVAL=5
ONLY=""
SEED=""

bold() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m: %s\n' "$*" >&2; exit 1; }
warn() { printf '\033[33mWARN\033[0m: %s\n' "$*" >&2; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }

usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
  printf '\nScenarios:\n'
  python3 - "$(basename "$0")" <<'PY'
import sys
scenarios = __import__("json").loads(__import__("os").environ.get("DEMO_SCENARIO_LIST", "[]"))
for row in scenarios:
    print(f"  {row[0]:<22} {row[1]}")
PY
}

SCENARIO_HELP='[
  ["checkout-deadlock", "Doctrine deadlock in a Messenger stock-reservation handler"],
  ["cache-warm", "RuntimeException writing the prod cache pool"],
  ["invoice-typeerror", "TypeError: InvoiceBuilder::total() return type"],
  ["account-null", "Call to a member function on null in a controller"],
  ["api-404", "NotFoundHttpException for an unknown API route"],
  ["frontend-js", "Browser TypeError during checkout (javascript platform)"],
  ["frontend-chunk", "ChunkLoadError after a deploy (javascript platform)"],
  ["db-connections", "PDOException: too many connections (fatal)"],
  ["payment-timeout", "Guzzle cURL error 28 calling the payment provider"],
  ["currency-warning", "PHP warning promoted to ErrorException"]
]'
export DEMO_SCENARIO_LIST="$SCENARIO_HELP"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dsn) DSN="${2:?missing value for --dsn}"; shift 2 ;;
    --url) BASE_URL="${2:?missing value for --url}"; shift 2 ;;
    --events) EVENTS="${2:?missing value for --events}"; shift 2 ;;
    --spread) SPREAD="${2:?missing value for --spread}"; shift 2 ;;
    --batch) BATCH="${2:?missing value for --batch}"; shift 2 ;;
    --interval) INTERVAL="${2:?missing value for --interval}"; shift 2 ;;
    --only) ONLY="${2:?missing value for --only}"; shift 2 ;;
    --seed) SEED="${2:?missing value for --seed}"; shift 2 ;;
    --loop) LOOP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown option: $1 (try --help)" ;;
  esac
done

# --- Resolve DSN -------------------------------------------------------------

if [[ -n "$DSN" ]]; then
  if [[ "$DSN" =~ ^([a-zA-Z][a-zA-Z0-9+.-]*)://([^@/]+)@([^/]+)/([^/]+)/?$ ]]; then
    SCHEME="${BASH_REMATCH[1]}"
    KEY="${BASH_REMATCH[2]}"
    [[ -z "$BASE_URL" ]] && BASE_URL="${SCHEME}://${BASH_REMATCH[3]}"
    SLUG="${BASH_REMATCH[4]}"
  else
    fail "cannot parse DSN '$DSN' (expected scheme://key@host/slug)"
  fi
fi

[[ -n "$SLUG" && -n "$KEY" && -n "$BASE_URL" ]] \
  || fail "need --dsn, or BASE_URL + SLUG + KEY (copy the DSN from Projects)"
[[ "$BASE_URL" =~ ^https?:// ]] || fail "base URL must start with http:// or https:// (got '$BASE_URL')"

[[ "$EVENTS" =~ ^[0-9]+$ ]] && (( EVENTS > 0 )) || fail "--events must be a positive integer"
[[ "$BATCH" =~ ^[0-9]+$ ]] && (( BATCH > 0 )) || fail "--batch must be a positive integer"
(( BATCH <= 50 )) || fail "--batch max is 50 (LOGSWIRE_INGEST_MAX_ITEMS)"
[[ "$INTERVAL" =~ ^[0-9]+$ ]] || fail "--interval must be an integer (seconds)"
[[ "$SPREAD" =~ ^[0-9]+([.][0-9]+)?$ ]] || fail "--spread must be a number of minutes"
[[ -z "$SEED" ]] && SEED="$RANDOM$RANDOM"

BASE_URL="${BASE_URL%/}"
URL="${BASE_URL}/api/${SLUG}/envelope/"
[[ -z "$ONLY" ]] && ONLY="all"

bold "Demo traffic → ${BASE_URL} (project: ${SLUG})"

if ! curl -fsS --max-time 5 "${BASE_URL}/healthz" 2>/dev/null | grep -q '"db":"up"'; then
  warn "healthz not OK on ${BASE_URL} — continuing anyway"
else
  ok "healthz ok"
fi
ok "scenario pool: ${ONLY}"

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

# Generate one envelope and print the scenario ids it contains.
generate_batch() { # count spread_minutes seed outfile
  python3 - "$1" "$2" "$3" "$4" <<'PY'
import json, random, sys, uuid
from datetime import datetime, timezone

count, spread, seed, out_path = int(sys.argv[1]), float(sys.argv[2]), sys.argv[3], sys.argv[4]
rng = random.Random(seed)
now = datetime.now(timezone.utc).timestamp()

def iso(ts):
    return datetime.fromtimestamp(ts, timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")

def frame(filename, function, lineno, in_app, abs_path=None, module=None):
    out = {"filename": filename, "function": function, "lineno": lineno, "in_app": in_app}
    if abs_path:
        out["abs_path"] = abs_path
    if module:
        out["module"] = module
    return out

def php_stack(rows):
    return [frame(f, fn, ln, app, abs_path=ap, module=mod) for f, fn, ln, app, ap, mod in rows]

CHROME = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
SAFARI = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15"

scenarios = [
    {
        "id": "checkout-deadlock",
        "weight": 5,
        "platform": "php",
        "level": "error",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-worker-2",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "messenger",
        "transaction": "checkout.reserve_stock",
        "exception": {
            "type": "Doctrine\\DBAL\\Exception\\DeadlockException",
            "value": "An exception occurred while executing 'UPDATE stock SET reserved = reserved + ? WHERE sku = ?': SQLSTATE[40P01]: Deadlock detected: deadlock detected",
            "frames": php_stack([
                ("vendor/doctrine/dbal/src/Connection.php", "Doctrine\\DBAL\\Connection::executeStatement", 1120, False, "/app/vendor/doctrine/dbal/src/Connection.php", "doctrine.dbal"),
                ("src/Repository/StockRepository.php", "App\\Repository\\StockRepository::reserve", 88, True, "/app/src/Repository/StockRepository.php", "app"),
                ("src/Service/Checkout/ReserveStockHandler.php", "App\\Service\\Checkout\\ReserveStockHandler::__invoke", 54, True, "/app/src/Service/Checkout/ReserveStockHandler.php", "app"),
                ("vendor/symfony/messenger/Middleware/HandleMessageMiddleware.php", "Symfony\\Component\\Messenger\\Middleware\\HandleMessageMiddleware::handle", 116, False, "/app/vendor/symfony/messenger/Middleware/HandleMessageMiddleware.php", "symfony.messenger"),
                ("vendor/symfony/messenger/Worker.php", "Symfony\\Component\\Messenger\\Worker::handleMessage", 175, False, "/app/vendor/symfony/messenger/Worker.php", "symfony.messenger"),
            ]),
        },
        "tags": {"transaction": "checkout.reserve_stock", "queue": "async", "region": "eu-west-3"},
        "fingerprint": ["{{ default }}", "checkout.reserve_stock", "deadlock"],
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
    },
    {
        "id": "cache-warm",
        "weight": 3,
        "platform": "php",
        "level": "error",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-web-3",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "console",
        "transaction": "catalog:warm",
        "exception": {
            "type": "RuntimeException",
            "value": "Unable to write cache file \"/app/var/cache/prod/pools/app/8f/c3/8fc3d2f0d4f0\".",
            "frames": php_stack([
                ("vendor/symfony/filesystem/Filesystem.php", "Symfony\\Component\\Filesystem\\Filesystem::dumpFileToTmpDir", 631, False, "/app/vendor/symfony/filesystem/Filesystem.php", "symfony.filesystem"),
                ("vendor/symfony/cache/Adapter/FilesystemAdapter.php", "Symfony\\Component\\Cache\\Adapter\\FilesystemAdapter::doSave", 372, False, "/app/vendor/symfony/cache/Adapter/FilesystemAdapter.php", "symfony.cache"),
                ("src/Service/Catalog/CatalogWarmer.php", "App\\Service\\Catalog\\CatalogWarmer::warm", 61, True, "/app/src/Service/Catalog/CatalogWarmer.php", "app"),
                ("src/Command/WarmCatalogCommand.php", "App\\Command\\WarmCatalogCommand::execute", 38, True, "/app/src/Command/WarmCatalogCommand.php", "app"),
                ("vendor/symfony/console/Command/Command.php", "Symfony\\Component\\Console\\Command\\Command::run", 279, False, "/app/vendor/symfony/console/Command/Command.php", "symfony.console"),
                ("bin/console", None, 17, False, "/app/bin/console", None),
            ]),
        },
        "tags": {"command": "catalog:warm", "host": "shop-web-3"},
        "fingerprint": ["{{ default }}", "catalog.warm", "cache_write"],
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
    },
    {
        "id": "invoice-typeerror",
        "weight": 2,
        "platform": "php",
        "level": "fatal",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-web-1",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "app",
        "transaction": "App\\Controller\\InvoiceController::pdf",
        "exception": {
            "type": "TypeError",
            "value": "App\\Service\\Billing\\InvoiceBuilder::total(): Return value must be of type int, string returned",
            "frames": php_stack([
                ("src/Service/Billing/InvoiceBuilder.php", "App\\Service\\Billing\\InvoiceBuilder::total", 147, True, "/app/src/Service/Billing/InvoiceBuilder.php", "app"),
                ("src/Controller/InvoiceController.php", "App\\Controller\\InvoiceController::pdf", 73, True, "/app/src/Controller/InvoiceController.php", "app"),
                ("vendor/symfony/http-kernel/HttpKernel.php", "Symfony\\Component\\HttpKernel\\HttpKernel::handleRaw", 181, False, "/app/vendor/symfony/http-kernel/HttpKernel.php", "symfony.http_kernel"),
                ("public/index.php", None, 5, False, "/app/public/index.php", None),
            ]),
        },
        "tags": {"transaction": "invoice.pdf", "payment_provider": "stripe"},
        "fingerprint": ["{{ default }}", "invoice.total", "TypeError"],
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
    },
    {
        "id": "account-null",
        "weight": 2,
        "platform": "php",
        "level": "error",
        "environment": "staging",
        "release": "shop@1.43.0-rc1",
        "server_name": "shop-staging-1",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "app",
        "transaction": "App\\Controller\\AccountController::show",
        "exception": {
            "type": "Error",
            "value": "Call to a member function getEmail() on null",
            "frames": php_stack([
                ("src/Controller/AccountController.php", "App\\Controller\\AccountController::show", 58, True, "/app/src/Controller/AccountController.php", "app"),
                ("vendor/symfony/http-kernel/HttpKernel.php", "Symfony\\Component\\HttpKernel\\HttpKernel::handleRaw", 181, False, "/app/vendor/symfony/http-kernel/HttpKernel.php", "symfony.http_kernel"),
                ("public/index.php", None, 5, False, "/app/public/index.php", None),
            ]),
        },
        "tags": {"controller": "AccountController", "route": "/account"},
        "fingerprint": ["{{ default }}", "account.show", "null_user"],
        "request": {"method": "GET", "url": "https://staging.shop.example.com/account", "headers": {"User-Agent": SAFARI}},
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
        "user": {"id": "u_1042", "email": "camille@example.com", "ip_address": "198.51.100.24"},
    },
    {
        "id": "api-404",
        "weight": 2,
        "platform": "php",
        "level": "warning",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-api-2",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "request",
        "transaction": "GET /api/v2/orders",
        "exception": {
            "type": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException",
            "value": "No route found for \"GET https://shop.example.com/api/v2/orders\"",
            "frames": php_stack([
                ("vendor/symfony/http-kernel/EventListener/RouterListener.php", "Symfony\\Component\\HttpKernel\\EventListener\\RouterListener::onKernelRequest", 132, False, "/app/vendor/symfony/http-kernel/EventListener/RouterListener.php", "symfony.http_kernel"),
                ("vendor/symfony/http-kernel/HttpKernel.php", "Symfony\\Component\\HttpKernel\\HttpKernel::handleRaw", 158, False, "/app/vendor/symfony/http-kernel/HttpKernel.php", "symfony.http_kernel"),
                ("public/index.php", None, 5, False, "/app/public/index.php", None),
            ]),
        },
        "tags": {"route": "/api/v2/orders", "status_code": "404"},
        "fingerprint": ["{{ default }}", "api.v2.orders", "404"],
        "request": {"method": "GET", "url": "https://shop.example.com/api/v2/orders", "headers": {"User-Agent": CHROME}, "env": {"REMOTE_ADDR": "203.0.113.77"}},
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
        "user": {"ip_address": "203.0.113.77"},
    },
    {
        "id": "frontend-js",
        "weight": 4,
        "platform": "javascript",
        "level": "error",
        "environment": "production",
        "release": "web@2026.11.12",
        "server_name": "cdn",
        "sdk": {"name": "sentry.javascript.browser", "version": "8.42.0"},
        "logger": "javascript",
        "transaction": "/checkout/payment",
        "exception": {
            "type": "TypeError",
            "value": "Cannot read properties of undefined (reading 'total')",
            "frames": [
                frame("app.9f2c1a.js", "Object.renderSummary", 1, True, "https://shop.example.com/assets/app.9f2c1a.js", "app"),
                frame("app.9f2c1a.js", "CheckoutPayment.render", 1, True, "https://shop.example.com/assets/app.9f2c1a.js", "app"),
                frame("app.9f2c1a.js", "c", 1, False, "https://shop.example.com/assets/app.9f2c1a.js", "vendor"),
            ],
        },
        "tags": {"browser": "Chrome 130", "route": "/checkout/payment"},
        "fingerprint": ["{{ default }}", "checkout.payment.render", "total_undefined"],
        "request": {"method": "GET", "url": "https://shop.example.com/checkout/payment", "headers": {"User-Agent": CHROME}},
        "contexts": {
            "os": {"name": "Windows", "version": "11"},
            "browser": {"name": "Chrome", "version": "130.0.0"},
            "runtime": {"name": "javascript", "version": "130.0.0"},
        },
        "user": {"id": "u_9081", "email": "alex@example.com", "ip_address": "192.0.2.51"},
        "breadcrumbs": {"values": [
            {"type": "navigation", "category": "navigation", "timestamp": None, "data": {"from": "/cart", "to": "/checkout/payment"}},
            {"type": "http", "category": "fetch", "timestamp": None, "data": {"method": "POST", "url": "/api/cart/summary", "status_code": 500}},
            {"type": "ui.click", "category": "ui.click", "timestamp": None, "message": "button#pay"},
        ]},
    },
    {
        "id": "frontend-chunk",
        "weight": 2,
        "platform": "javascript",
        "level": "fatal",
        "environment": "production",
        "release": "web@2026.11.13",
        "server_name": "cdn",
        "sdk": {"name": "sentry.javascript.browser", "version": "8.42.0"},
        "logger": "javascript",
        "transaction": "/account/invoices",
        "exception": {
            "type": "ChunkLoadError",
            "value": "Loading chunk 42 failed. (error: https://shop.example.com/assets/42.7b1d.js)",
            "frames": [
                frame("app.9f2c1a.js", "__webpack_require__.f.j", 1, False, "https://shop.example.com/assets/app.9f2c1a.js", "webpack"),
                frame("app.9f2c1a.js", "Object.loadInvoices", 1, True, "https://shop.example.com/assets/app.9f2c1a.js", "app"),
                frame("app.9f2c1a.js", "n", 1, False, "https://shop.example.com/assets/app.9f2c1a.js", "vendor"),
            ],
        },
        "tags": {"route": "/account/invoices", "deploy": "web@2026.11.13"},
        "fingerprint": ["{{ default }}", "account.invoices", "chunk_load"],
        "request": {"method": "GET", "url": "https://shop.example.com/account/invoices", "headers": {"User-Agent": SAFARI}},
        "contexts": {
            "os": {"name": "macOS", "version": "15.1"},
            "browser": {"name": "Safari", "version": "18.1"},
            "runtime": {"name": "javascript", "version": "18.1"},
        },
        "user": {"id": "u_3311", "email": "lea@example.com", "ip_address": "192.0.2.88"},
    },
    {
        "id": "db-connections",
        "weight": 1,
        "platform": "php",
        "level": "fatal",
        "environment": "staging",
        "release": "shop@1.43.0-rc1",
        "server_name": "shop-staging-2",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "doctrine",
        "transaction": "GET /api/v1/products",
        "exception": {
            "type": "PDOException",
            "value": "SQLSTATE[08006] [7] connection to server at \"db\" (172.18.0.4), port 5432 failed: FATAL:  sorry, too many connections",
            "frames": php_stack([
                ("vendor/doctrine/dbal/src/Driver/PDO/Connection.php", "Doctrine\\DBAL\\Driver\\PDO\\Connection::connect", 38, False, "/app/vendor/doctrine/dbal/src/Driver/PDO/Connection.php", "doctrine.dbal"),
                ("vendor/doctrine/dbal/src/Connection.php", "Doctrine\\DBAL\\Connection::connect", 412, False, "/app/vendor/doctrine/dbal/src/Connection.php", "doctrine.dbal"),
                ("src/Repository/ProductRepository.php", "App\\Repository\\ProductRepository::findActive", 44, True, "/app/src/Repository/ProductRepository.php", "app"),
                ("public/index.php", None, 5, False, "/app/public/index.php", None),
            ]),
        },
        "tags": {"database": "postgres", "pool": "default"},
        "fingerprint": ["{{ default }}", "postgres", "too_many_connections"],
        "request": {"method": "GET", "url": "https://staging.shop.example.com/api/v1/products", "headers": {"User-Agent": "curl/8.5.0"}, "env": {"REMOTE_ADDR": "198.51.100.90"}},
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
    },
    {
        "id": "payment-timeout",
        "weight": 3,
        "platform": "php",
        "level": "error",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-web-2",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "http_client",
        "transaction": "checkout.authorize_payment",
        "exception": {
            "type": "GuzzleHttp\\Exception\\ConnectException",
            "value": "cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.payments.example.com/v1/charges",
            "frames": php_stack([
                ("vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php", "GuzzleHttp\\Handler\\CurlFactory::createRejection", 161, False, "/app/vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php", "guzzle"),
                ("vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php", "GuzzleHttp\\Handler\\CurlHandler::__invoke", 47, False, "/app/vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php", "guzzle"),
                ("src/Service/Payment/PaymentGateway.php", "App\\Service\\Payment\\PaymentGateway::charge", 92, True, "/app/src/Service/Payment/PaymentGateway.php", "app"),
                ("src/Service/Checkout/AuthorizePaymentHandler.php", "App\\Service\\Checkout\\AuthorizePaymentHandler::__invoke", 47, True, "/app/src/Service/Checkout/AuthorizePaymentHandler.php", "app"),
            ]),
        },
        "tags": {"provider": "payments.example.com", "timeout_ms": "30000"},
        "fingerprint": ["{{ default }}", "payment.charge", "timeout"],
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
    },
    {
        "id": "currency-warning",
        "weight": 2,
        "platform": "php",
        "level": "warning",
        "environment": "production",
        "release": "shop@1.42.0",
        "server_name": "shop-api-1",
        "sdk": {"name": "sentry.php", "version": "4.12.0"},
        "logger": "php",
        "transaction": "POST /api/v1/orders",
        "exception": {
            "type": "ErrorException",
            "value": "Undefined array key \"currency\"",
            "frames": php_stack([
                ("src/Service/Order/OrderNormalizer.php", "App\\Service\\Order\\OrderNormalizer::normalize", 119, True, "/app/src/Service/Order/OrderNormalizer.php", "app"),
                ("src/Controller/Api/OrderController.php", "App\\Controller\\Api\\OrderController::create", 66, True, "/app/src/Controller/Api/OrderController.php", "app"),
                ("public/index.php", None, 5, False, "/app/public/index.php", None),
            ]),
        },
        "tags": {"endpoint": "/api/v1/orders", "php_error": "E_WARNING"},
        "fingerprint": ["{{ default }}", "order.normalize", "currency_key"],
        "request": {"method": "POST", "url": "https://shop.example.com/api/v1/orders", "headers": {"User-Agent": "ShopClient/2.4 (PHP 8.3)"}, "env": {"REMOTE_ADDR": "203.0.113.19"}},
        "contexts": {"os": {"name": "Linux", "version": "6.8.0-45-generic"}, "runtime": {"name": "php", "version": "8.3.14"}},
        "user": {"id": "api_client_77", "ip_address": "203.0.113.19"},
    },
]

only = [s.strip() for s in __import__("os").environ.get("DEMO_ONLY", "all").split(",") if s.strip()]
pool = scenarios if not only or only == ["all"] else [s for s in scenarios if s["id"] in only]
if not pool:
    sys.exit(f"no scenario matches --only {only}")

if count < len(pool):
    chosen = rng.sample(pool, count)
else:
    chosen = list(pool)
    rng.shuffle(chosen)
    weights = [s["weight"] for s in pool]
    while len(chosen) < count:
        chosen.append(rng.choices(pool, weights=weights, k=1)[0])
rng.shuffle(chosen)

events = []
for scenario in chosen:
    ts = now - (rng.uniform(0, spread * 60) if spread > 0 else 0)
    exception = scenario["exception"]
    event = {
        "event_id": uuid.uuid4().hex,
        "timestamp": iso(ts),
        "platform": scenario["platform"],
        "level": scenario["level"],
        "logger": scenario.get("logger", "app"),
        "server_name": scenario["server_name"],
        "release": scenario["release"],
        "environment": scenario["environment"],
        "sdk": scenario["sdk"],
        "tags": scenario["tags"],
        "fingerprint": scenario["fingerprint"],
        "contexts": scenario.get("contexts", {}),
        "exception": {
            "values": [{
                "type": exception["type"],
                "value": exception["value"],
                "mechanism": {"type": "generic", "handled": scenario["level"] not in ("fatal",)},
                "stacktrace": {"frames": exception["frames"]},
            }],
        },
    }
    if scenario.get("transaction"):
        event["transaction"] = scenario["transaction"]
    if scenario.get("request"):
        event["request"] = scenario["request"]
    if scenario.get("user"):
        event["user"] = scenario["user"]
    if scenario.get("breadcrumbs"):
        crumbs = scenario["breadcrumbs"]
        for crumb in crumbs["values"]:
            if crumb.get("timestamp") is None:
                crumb["timestamp"] = ts
        event["breadcrumbs"] = crumbs
    if scenario.get("extra"):
        event["extra"] = scenario["extra"]
    events.append((scenario["id"], event))

header = {"sent_at": iso(now)}
if len(events) == 1:
    header["event_id"] = events[0][1]["event_id"]

lines = [json.dumps(header, separators=(",", ":"))]
for _, event in events:
    payload = json.dumps(event, separators=(",", ":"), ensure_ascii=False)
    lines.append(json.dumps({"type": "event", "length": len(payload.encode()), "content_type": "application/json"}, separators=(",", ":")))
    lines.append(payload)

with open(out_path, "w", encoding="utf-8") as fh:
    fh.write("\n".join(lines) + "\n")

print(",".join(sorted({sid for sid, _ in events})))
PY
}

send_envelope() { # envelope_file
  local body_file="$TMP_DIR/body.json"
  local code
  code=$(curl -sS --max-time 30 -o "$body_file" -w '%{http_code}' \
    -H "X-Sentry-Auth: Sentry sentry_version=7, sentry_key=${KEY}, sentry_client=logswire-demo/1.0" \
    -H 'Content-Type: application/x-sentry-envelope' \
    --data-binary "@$1" \
    "$URL") || fail "curl failed for ${URL}"

  case "$code" in
    202) return 0 ;;
    401) fail "401 missing/invalid sentry_key — check the DSN" ;;
    403) fail "403 project disabled" ;;
    404) fail "404 unknown project '${SLUG}' or key mismatch — copy the DSN from Projects" ;;
    413) fail "413 payload too large — lower --batch" ;;
    429)
      local retry
      retry=$(sed -n 's/.*"retry_after":\([0-9]*\).*/\1/p' "$body_file" 2>/dev/null || true)
      fail "429 rate limited (retry in ${retry:-60}s) — lower --batch or use --loop"
      ;;
    *) fail "ingest returned ${code}: $(cat "$body_file")" ;;
  esac
}

export DEMO_ONLY="$ONLY"

TOTAL=0
BATCHES=0

if (( LOOP )); then
  bold "Loop mode: 1–3 events every ${INTERVAL}s — Ctrl-C to stop"
  while true; do
    N=$(( RANDOM % 3 + 1 ))
    (( N > BATCH )) && N="$BATCH"
    ENVL="$TMP_DIR/envelope.ndjson"
    IDS=$(generate_batch "$N" 0 "$SEED-${BATCHES}" "$ENVL")
    send_envelope "$ENVL"
    TOTAL=$(( TOTAL + N ))
    BATCHES=$(( BATCHES + 1 ))
    printf '  %4d events  batch %-4d 202  %s\n' "$TOTAL" "$BATCHES" "$IDS"
    sleep "$INTERVAL"
  done
fi

bold "Sending ${EVENTS} events (batch ≤ ${BATCH}, timestamps spread over ${SPREAD} min)"
REMAINING="$EVENTS"
while (( REMAINING > 0 )); do
  N="$BATCH"
  (( N > REMAINING )) && N="$REMAINING"
  ENVL="$TMP_DIR/envelope.ndjson"
  IDS=$(generate_batch "$N" "$SPREAD" "$SEED-${BATCHES}" "$ENVL")
  send_envelope "$ENVL"
  TOTAL=$(( TOTAL + N ))
  BATCHES=$(( BATCHES + 1 ))
  REMAINING=$(( REMAINING - N ))
  printf '  %4d/%d events  batch %-4d 202  %s\n' "$((EVENTS - REMAINING))" "$EVENTS" "$BATCHES" "$IDS"
done

bold "Done: ${TOTAL} events in ${BATCHES} envelope(s)"
printf '  Open %s → Issues (seed: %s)\n' "$BASE_URL" "$SEED"
