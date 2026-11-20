# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First public release: **errors + uptime** on PostgreSQL, with Telegram as the
only alert channel.

### Added

- **Uptime monitors**: HTTP(S) checks with HEAD→GET fallback, accepted 2xx/3xx,
  timeout and in-cycle retries. Targets are admin-supplied `http(s)` URLs
  (see the SSRF notes in [`SECURITY.md`](SECURITY.md)).
- Dedicated `probe` Compose service running `app:probe:loop` (signal-aware loop,
  exponential backoff while a monitor is DOWN).
- Incidents (open/close on transitions, duration) and metric buckets
  (hourly 10 days, daily 90 days) with hourly retention pruning.
- Telegram `[uptime] DOWN` / `[uptime] RECOVERED` alerts, sent on transitions only.
- Monitors UI: list, detail (24 h strip, 14-day rollup, incident history), CRUD
  and enable/disable toggle.
- **Errors**: Sentry-compatible ingest (DSN `https://<key>@<host>/<slug>`;
  SDKs append `/api/.../envelope/` themselves). Missing `level` defaults to
  `error`; numeric `timestamp` values accepted. `events.stack_hash` detects
  divergent stacktraces; the latest occurrence keeps the full payload while older
  ones are trimmed. Replay tests for `sentry.php` and `@sentry/browser` envelopes.
- Resolved issues reopen automatically on a new event, with optional alert via
  `LOGSWIRE_TELEGRAM_RENOTIFY_REOPENED`.
- Issue list filters on `since` / `until` (inclusive), per-row Resolve/Reopen
  actions returning to the filtered list.
- Post-login **Overview** (`/dashboard`): counts, recent issues, monitors down; README screenshots updated.
- `app:db:backup` / `app:db:restore` (`pg_dump` / `psql`); **Settings** (admin):
  Telegram test, retention status, payload-based disk projection (`app:retention:cleanup` for manual purge).
- GitHub Actions CI (PHPStan, PHPUnit, PostgreSQL UPSERT replay) and a
  multi-arch (`amd64`/`arm64`) GHCR image published on `main` and `v*` tags.
- `scripts/demo-traffic.sh`: realistic grouped Sentry traffic (PHP/browser
  exceptions, warnings, fatals, request/user context, tags, 10 scenarios,
  `--events`, `--spread`, `--batch`, `--only`, `--loop`) for demoing and
  exercising the ingest → issues → stacktrace pipeline without an SDK.
- Compose **`worker`** service: Symfony Scheduler (daily error retention, hourly
  uptime retention prune) and Messenger queues (`notifications`, `maintenance`) on
  the Doctrine transport (`dragonmantank/cron-expression` for the daily cron trigger).
- Async **Telegram** for new/reopened issues and monitor DOWN/RECOVERED (ingest and
  probe enqueue only).

### Changed

- **PostgreSQL 16 is now the production database** (SQLite is dev/test only);
  migrations are portable and replay from an empty schema.
- UI is **dark-only** (no theme toggle); login keeps a light card on the dark pitch.
- Every application-specific environment variable uses the `LOGSWIRE_` prefix;
  `.env.example`, `README.md` and `SECURITY.md` document the same set.
- Automatic error retention and uptime pruning run on the **worker**, not on
  `app:probe:loop` (`LOCK_DSN=flock:/data/locks` on the shared runtime volume).
- Compose uses a **`logswire-runtime`** volume for sessions, retention state, and
  file locks; entrypoint prepares permissions before `www-data`.
- Retention no longer runs on `/healthz`; `/healthz` is a cheap liveness probe.
- Telegram alerting split into transport (`TelegramClient`), configured channel
  (`TelegramChannel`) and domain alerters (`IssueAlerter`, `MonitorAlerter`).
- Issue query-string parsing extracted from `IssueController` into the
  `IssueFilters` DTO.
- Fonts are self-hosted and the CSP no longer needs `'unsafe-inline'`; inline
  handlers were replaced by delegated `data-confirm` / `data-autosubmit`.
- Ingest clips oversized event fields to column limits (no failed insert on long SDK
  strings).
- Filters and toggles work without JavaScript (`noscript` fallbacks).
- `DbBackupCommand` / `DbRestoreCommand` share `PostgresToolCommand`; monitor
  interval floor lives on `Monitor::MIN_INTERVAL_SEC`; project URLs use `path()`;
  dashboard is English-only (`symfony/translation` removed).
- Telegram messages escape legacy Markdown control characters in titles,
  culprits and monitor names.

### Removed

- Dead code, unused UI CSS, `symfony/form`, `symfony/validator`, `twig/extra-bundle`,
  `scripts/debug-session-token.php`, `Dockerfile.dev`.
- Unused `symfony/messenger` dependency.
- Reflection-based event hydration on the read path.
- `app:issues:repair-orphans` (Postgres FKs make orphaned rows unreachable in normal ops).

### Fixed

- Issue detail orders same-second occurrences by id, so the latest occurrence
  (the one whose full payload is kept) is always displayed — previously a tie on
  `received_at` could show a trimmed occurrence and "No stacktrace on the latest
  occurrence".

### Security

- **Production secret guards**: the app refuses to boot in `APP_ENV=prod` with the
  `.env.example` `APP_SECRET`, and Compose now requires a non-empty
  `POSTGRES_PASSWORD`.
- **Network exposure**: the app port is published on `127.0.0.1` only; the bundled
  Caddyfile caps request bodies at `INGEST_MAX_BODY_MB` (default 12, configurable
  without rebuilding the image) and answers common credential/WordPress/Adminer
  probes with a cheap 404.
- **Ingest scrubbing**: `PayloadScrubber` redacts password/token/cookie/
  authorization values in stored raw payloads (`LOGSWIRE_SCRUB_SENSITIVE`, default
  on).
- **Headers & caching**: HSTS is emitted when `APP_PUBLIC_URL` is HTTPS, authenticated
  pages are `Cache-Control: no-store`, and CSP/COOP/CORP/`object-src` were tightened.
- **Robustness**: `/healthz` is rate-limited (trusted proxies and their own health
  probes are exempt, so a reverse proxy cannot trigger false 429s); malformed UUIDs
  return 404/400 instead of 500; password minimum is 12 characters and a password
  reset invalidates live sessions.
- **Operations**: retention indexes on `events.received_at` / `issues.last_seen_at`,
  backups chmod `0600`, and CI runs `composer audit`.
- `/healthz` no longer exposes database errors in JSON; post-login and ingest
  redirects reject off-site targets; logout is POST + CSRF; ingest is rate-limited
  and gzip bodies are bounded before decode.
- Uptime probes **always block cloud metadata endpoints** (`169.254.169.254`,
  `169.254.170.2`, `100.100.100.200`, `fd00:ec2::254`, `fd00:ec2::23`,
  `metadata.google.internal`, `metadata.goog`), even when private LAN targets stay
  allowed for homelab use. The check runs on the monitor URL and on every redirect
  hop; redirects are now followed manually (10 hops, http(s) only).
