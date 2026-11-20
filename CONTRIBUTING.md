# Contributing

Bug reports and pull requests are welcome. This repo targets **Symfony developers** who run the stack locally or in Docker.

## Quality checks

A fresh clone has no `.env` (it is git-ignored). `composer install` creates one
from `.env.example` if needed; edit `APP_SECRET` and `POSTGRES_PASSWORD` before
running a real instance. Tests only need `.env.test`.

```bash
composer qa            # PHPStan + PHPUnit
composer test-pg       # replays the bucket UPSERT on PostgreSQL (opt-in)
./scripts/smoke-sentry.sh   # needs Docker up + .env
```

PHPUnit uses a file-backed SQLite schema (`var/test.db`) by default; `composer test-pg` needs a reachable PostgreSQL and honours `LOGSWIRE_PG_DSN`
(default `postgresql://logswire:logswire@127.0.0.1:5433/logswire`; Compose
publishes its `postgres` service on `127.0.0.1:5433`). It creates and drops its
own `logswire_upsert_test` schema, so it never touches your data.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs `composer audit`,
PHPStan and PHPUnit on PHP 8.5 for every push and pull request, plus
`composer test-pg` against a `postgres:16-alpine` service.

## Smoke test

Validates the README promise end-to-end:

```bash
docker compose up -d --build
chmod +x scripts/smoke-sentry.sh
./scripts/smoke-sentry.sh
```

The script creates an admin account. On a non-local `BASE_URL`, set
`SMOKE_PASSWORD` explicitly; the known local test password is refused.

Fresh local DB (destructive):

```bash
./scripts/smoke-fresh.sh
```

Realistic demo data (grouped issues, stacktraces, releases, environments):

```bash
./scripts/demo-traffic.sh --dsn "$DSN" --events 30
```

Ingest contract details (gzip, 401, 413) and demo scenarios: [`docs/INGEST.md`](docs/INGEST.md).

## Code layout

Symfony MVC: thin controllers, `Service/<feature>/`, Doctrine repositories.

```
src/
  Controller/     # Twig UI + Sentry ingest HTTP
  Command/        # CLI — see README « Console commands »
  Entity/ Repository/
  Service/
    Ingest/ Issue/ Project/ User/ Telegram/ Retention/ Database/
```

No `Domain/` / `Application/` layers — keep it readable for any Symfony contributor.

### Retention vocabulary

- **Retention** — how long we keep data (`LOGSWIRE_RETENTION_DAYS`, uptime hour/day windows).
- **Prune** — scheduled `DELETE` of rows past that window (errors: `RetentionService` via worker; uptime: `UptimeRetentionPruner`).
- **`app:retention:cleanup`** — manual error prune (same deletes as the worker, not throttled). CLI name stays *cleanup* for familiarity.

## Database changes

1. Edit the Doctrine entity in `src/Entity/`.
2. Add SQL to `migrations/Version20260101000000.php` for fresh installs **or** add a new `Version*.php` migration for existing deployments.
3. `composer qa` (SQLite via entities).
4. On Postgres: `doctrine:migrations:migrate` on a throwaway DB, then `doctrine:schema:validate`.

Keep this repository self-contained: do not vendor other products or private source trees.

## Screenshots (README)

PNG files live in `docs/screenshots/`. After a visible UI change, capture logged-in pages at a common width (~1280px):

| File | Route |
|------|--------|
| `overview.png` | `/dashboard` |
| `issues.png` | `/issues` |
| `monitors.png` | `/dashboard/monitors` |
| `monitor-detail.png` | a monitor detail page |

`dashboard.png` is kept as a copy of `issues.png` for older links. Update the README hero (`overview.png`) when the post-login landing page changes.

## Release checklist (v1.x)

1. `composer qa` (+ smoke if possible)
2. No secrets, `.env`, or backup dumps (`var/backups/`) in git
3. `README.md`, `SECURITY.md`, `CHANGELOG.md` up to date (rename `[Unreleased]`
   to the release version and date)
4. Tag: `git tag -a v1.x.y -m "…"` and push — [`docker.yml`](.github/workflows/docker.yml)
   publishes the multi-arch image to `ghcr.io/<owner>/<repo>` with semver tags
   and `latest`.
