# Design (Symfony)

Dark-only dashboard for self-hosted error tracking and uptime. Custom CSS in
`public/css/site.css` — no Tailwind, no build step.

## Principles

- **Dark shell** for every logged-in page (`color-scheme: dark`, teal accent, slate surfaces).
- **Login** is the only exception: dark marketing pitch + light sign-in card (readable fields).
- **No pure #000 / #fff** on the app canvas; stacktrace panel uses `--console` tokens.
- **SVG charts** in monitors (no chart library).

## Visual direction

The palette is deliberately restrained: teal accent, slate surfaces, KPI tiles and
dense tables. Logswire only ships the error-tracking and uptime features documented
in the README; APM, audits and billing are out of scope.

## Maintenance

One theme ⇒ one pass per UI change. Revisit screenshots in `docs/screenshots/` when the shell changes.
