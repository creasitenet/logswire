# Roadmap

Planned work after the first public release (**v1.0**: errors + uptime + Telegram).
This file is the public wish list only — shipping status lives in
[`CHANGELOG.md`](CHANGELOG.md).

**Not on this roadmap:** OpenTelemetry/APM, a public JSON dashboard API, hosted
SaaS features, or multi-language UI (English-only for now).

---

## How it works today (operational)

| Task | What runs it |
|------|----------------|
| HTTP uptime checks | `probe` service (`app:probe:loop`) |
| Error retention prune | `worker` (Scheduler → Messenger, throttled ~once per day) |
| Uptime retention prune | `worker` (hourly schedule) |
| Telegram (errors + uptime) | `worker` (`notifications` queue) |
| Manual error prune | `app:retention:cleanup` (any time) |

**If the `worker` container is stopped**, automatic retention prunes and outbound alerts stall
(ingest and the UI still work; the DB can grow beyond policy). Uptime checks need
`probe` only.

---

## Background jobs (Messenger / scheduler)

- [x] Error and uptime **retention prune** on Symfony Scheduler + Messenger, independent of probes.
- [x] Compose **`worker`** service (same image as web/probe).
- [ ] Optional Compose **profile** so stacks without uptime still document minimal services (`web` + `worker` + `postgres`).
- [ ] Overview hints when **probe** or **worker** heartbeat is stale.

## Notifications

- [x] **Telegram** alerts **asynchronously** (Messenger).
- [ ] Additional channels (same event types: new issue, reopen, uptime DOWN/RECOVERED):
      - Discord
      - Slack
      - Email (SMTP)
- [ ] **Notification rules** (examples we have in mind):
      - Route per project or per monitor
      - Mute by level / environment / release
      - Quiet hours
      - Separate destinations for errors vs uptime

## Uptime & issues (UI / product)

- [ ] Clearer **probe coverage** in the UI (gaps vs down — empty slots already mean “no probes”, but surfacing “probe worker stale” on Overview is useful).

## Housekeeping

- [ ] **Overview** tweaks: probe/retention health hints, fresher screenshots in docs.
- [ ] Squash or document multi-migration policy as the schema grows.

## Later (maybe)

- [ ] **Per-project retention** override (global `LOGSWIRE_RETENTION_DAYS` stays the default).
- [ ] Evaluate **Redis** Messenger transport if queue polling ever shows up in metrics (Doctrine is the default today).
