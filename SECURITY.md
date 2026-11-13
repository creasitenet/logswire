# Security — Logswire OSS

Threat model for a **single-tenant**, self-hosted instance. Not a penetration test checklist.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub Security Advisories:
**Security** tab → **Report a vulnerability**. Do not open a public issue for an
undisclosed vulnerability.

## Assets & trust boundaries

| Asset | Who can access | Risk |
|-------|----------------|------|
| Dashboard (issues, stacktraces) | Users with Symfony session | Sensitive: payloads may contain PII/secrets |
| Ingest `POST /api/{slug}/envelope/` | Anyone with **project DSN key** | Write-only; no UI data |
| DSN `public_key` | Embedded in app config | Treat as **bearer secret** (rotation = new project key) |
| PostgreSQL data (volume + dumps) | Host / container | Full data dump |
| Telegram token (env) | Instance admin | Can send messages to your chat |

## Default model: one hostname, Symfony auth on the UI

| Route | Auth | Content |
|-------|------|---------|
| `POST /api/{slug}/envelope/` | **DSN key** (`X-Sentry-Auth: sentry_key=…`) | Public ingest (Sentry-compatible) |
| `/healthz` | none | Docker health probe |
| Dashboard (`/`, `/dashboard`, `/issues`, `/dashboard/…`) | **Symfony session** | Overview, issues, stacktraces, monitors |

**NPM / Traefik:** TLS on the hostname; do **not** put an Access List on the **same host** that serves your app DSN.

Reason: an Access List usually applies to the whole vhost. Sentry SDKs do not send proxy credentials → ingest is blocked.

## DSN public by design

Sentry-compatible ingest **must** accept requests authenticated only by `sentry_key` (long-lived API key in your apps).

Mitigations:

- One project per environment; deactivate unused projects.
- Avoid exposing the DSN in public client bundles when errors can be sent server-side only.
- Rate limit: `api_write` sliding window (see `config/packages/rate_limiter.yaml`).
- Payload limits: `LOGSWIRE_INGEST_*` → **413** when exceeded ([`docs/INGEST.md`](docs/INGEST.md)).

## CSP & frontend scripts

`SecurityHeadersSubscriber` sets `script-src 'self'`. HTMX is vendored in `public/js/htmx.min.js` (not a CDN).

## Reverse proxy & TLS

- Terminate TLS at the proxy; keep `APP_PUBLIC_URL` on `https://…` so generated DSNs match reality.
- Set `TRUSTED_PROXIES` so Symfony honors `X-Forwarded-Proto` / `Host` from **one** trusted hop only.

See [`README.md`](README.md) for a Caddy example.

## Local accounts (no web signup)

- First admin: `docker compose exec logswire bin/console app:owner:create <user> --password='…' --admin`
- Password reset: CLI only (`app:user:password`). Minimum 8 characters.

## NPM Access List trap

If you protect `logswire.example.com` with an Access List, you also block `https://logswire.example.com/api/.../envelope/`.

### Advanced: two subdomains

| Host | NPM | Usage |
|------|-----|--------|
| `ingest.example.com` | TLS only, **no** Access List | SDK DSN |
| `logswire.example.com` | Access List | UI only |

Align `APP_PUBLIC_URL` / displayed DSNs with the ingest host.

## Uptime probes

The `probe` service requests only URLs stored on monitors. An authenticated admin sets them via the Twig UI (`MonitorService` validates `http://` / `https://` on create and update). There is no API to aim the probe at an arbitrary URL.

The probe does **not** re-validate on every tick (the URL is trusted once persisted). Compromise of the database or an admin account could still point probes elsewhere — same trust level as changing nginx upstreams.

RFC1918 and metadata IPs are **allowed** on purpose (check your own LAN). This is not a hardened outbound SSRF firewall. Redirects are followed (up to 10 hops): choose monitor targets you trust not to redirect to `file://` or unexpected hosts.

## Sensitive event data

Sentry payloads may contain PII, cookies, tokens. Limit dashboard viewers; split projects; retention is automatic (see README).

## Backups

Encrypted backups are your responsibility. Use `app:db:backup` (plain SQL via `pg_dump`) and copy dumps off the host; restrict permissions on `var/backups/`. The live database lives in the Compose Postgres volume — see [`README.md`](README.md#backups).
