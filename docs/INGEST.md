# Sentry ingest — HTTP contract

Reference for operators and SDK integrators. Security context: [`SECURITY.md`](../SECURITY.md).

## Endpoints

| Method | Path | Body |
|--------|------|------|
| `POST` | `/api/{slug}/envelope/` | `application/x-sentry-envelope` (NDJSON) |

`{slug}` is the last segment of the DSN: SDKs append `/api/<project-slug>/envelope/` themselves. The DSN copied from the UI therefore looks like `https://<public_key>@<host>/<slug>` (no `/api/` prefix, no trailing slash).

The legacy `/api/{slug}/store/` endpoint (single JSON event) is **not** served and
answers `404`. Every Sentry SDK released since 2020 speaks the envelope protocol;
Sentry deprecated `/store/` in 2022, so there is nothing to gain from emulating
it. Upgrade the SDK instead.

## Authentication

Required:

```http
X-Sentry-Auth: Sentry sentry_version=7, sentry_key=<public_key>
```

The `Sentry ` scheme prefix is optional and case-insensitive, and `sentry_key`
may appear in any position (SDKs send it last; some curl examples put it first).

Query `?sentry_key=<public_key>` also works (not recommended).

| Situation | HTTP |
|-----------|------|
| Missing key | **401** |
| Unknown slug / key mismatch | **404** |
| Project disabled | **403** |
| Invalid body | **400** |
| Body too large | **413** |
| Rate limited | **429** |
| Accepted | **202** |

## Envelope format

- First line: envelope header (JSON, ignored except debug).
- Then zero or more pairs: item header line (`type`, `length`) + payload.
- Handled types: **`event`** only.
- Ignored types (counted in `dropped`): `transaction`, `attachment`, `client_report`, etc.
- **`Content-Encoding: gzip`** is supported.

Typical response:

```json
{
  "id": "42",
  "ids": ["42"],
  "events": 1,
  "dropped": 0
}
```

An envelope with no `event` (e.g. only `client_report`) → **202** with `events: 0`.

## Limits (`.env`)

| Variable | Default |
|----------|---------|
| `LOGSWIRE_INGEST_MAX_BODY_BYTES` | 10485760 (10 MiB) |
| `LOGSWIRE_INGEST_MAX_ITEMS` | 50 |
| `LOGSWIRE_INGEST_MAX_ITEM_BYTES` | 1048576 (1 MiB) |
| `LOGSWIRE_SCRUB_SENSITIVE` | `1` (enabled) |
| `INGEST_MAX_BODY_MB` | 12 (bundled Caddyfile only) |

## Sensitive data

Before the raw payload is stored, `PayloadScrubber` replaces values whose key
looks like a credential (`password`, `token`, `secret`, `authorization`,
`cookie`, `api_key`, `private_key`, `credit_card`, `ssn`, …) with
`[Filtered]`. This covers both `request.headers` objects and SDK variants that
send header pairs. The mapped columns used by the UI (message, stacktrace,
request URL, tags…) come from the original event and keep their debugging value.

Set `LOGSWIRE_SCRUB_SENSITIVE=0` only if you really need untouched payloads.
The proxy should also enforce a body limit: the bundled Caddyfile reads
`INGEST_MAX_BODY_MB` (default 12), which must stay **at or above**
`LOGSWIRE_INGEST_MAX_BODY_BYTES / 1 MiB`. The controller bounds reads as a
second line of defence.

## Tests

```bash
composer test
./scripts/smoke-sentry.sh
```

## Demo traffic

`scripts/demo-traffic.sh` sends realistic, grouped events through the same
envelope endpoint as the SDKs — PHP and browser exceptions, warnings, fatals,
request/user context, tags, environments and releases:

```bash
./scripts/demo-traffic.sh --dsn 'http://<public_key>@localhost:8080/<slug>'
./scripts/demo-traffic.sh --dsn '...' --events 100 --spread 120 --batch 20
./scripts/demo-traffic.sh --dsn '...' --loop --interval 10
./scripts/demo-traffic.sh --dsn '...' --only checkout-deadlock,frontend-js
```

Repeated scenarios reuse their fingerprint, so occurrences accumulate on the same
issue. Ten scenarios ship with the script; `--help` lists them. It only needs
bash, curl and python3, and works from a laptop against a remote instance
(`--url` overrides the DSN host when the DSN points at the public URL).
