# Brief: Playwright end-to-end coverage

## What

The suite has no browser-level coverage at all — `docs/stack/stack.md` records
the gap as an open Owner decision ("Frontend: none... no Vitest/Jest/Playwright").
Every feature test asserts on Inertia props or a redirect, so a page that renders
props correctly but ships a broken form, an unreachable button or a route the
frontend never links to still passes CI. Two defects of exactly that shape were
found by hand in the last week (the crushed validation column, and the
unreachable expired-link branch in `DestinationValidationController`).

This adds Playwright, a small set of specs covering the flows the product cannot
function without, and a GitHub Actions workflow that runs them on every pull
request.

The Project Owner asked for this on 2026-08-31, which is the approval the stack
table's "Owner decision to close" was waiting on.

## Decisions

- **Playwright, Chromium only, `tests/e2e/`.** One browser. Cross-browser
  rendering is not a risk this product carries; the specs exist to prove flows
  work, not to prove Tailwind renders in WebKit. Config at `playwright.config.ts`
  in the repo root, `testIdAttribute: 'data-test'` so the specs use the
  attribute the codebase already writes (`data-test`, not `data-testid`).

- **The specs drive the app the way a person does.** No test-only routes, no
  logging in by injecting a session cookie, no fixtures written straight into the
  database mid-test. A spec that needs a proxy creates one through the form; the
  point of this layer is that the path a user takes is reachable.

- **One seeded account, everything else built by the spec.** A new artisan
  command, `e2e:seed`, creates a fixed verified user (`e2e@example.com`) and its
  personal team through the same `CreateTeam` action registration uses. It is
  idempotent — it deletes and recreates that one account and nothing else — so it
  never touches other data in the database it runs against. This is deliberate:
  `migrate:fresh` in a global setup would wipe a developer's local database the
  first time somebody ran the suite by habit. The command refuses to run when
  `APP_ENV=production`.

  Specs create their own proxies with names carrying a unique suffix, so they do
  not collide with each other and can run in parallel workers.

- **Local runs target the running Sail app; CI boots its own server.** The
  config reads `E2E_BASE_URL` (default `http://localhost`, which is what
  `compose.yaml` publishes). Playwright's `webServer` is enabled only when
  `E2E_START_SERVER=1`, which CI sets and a developer does not — locally the app
  is already running, and a second copy fighting for port 80 helps nobody.

- **CI runs `php artisan serve` with `QUEUE_CONNECTION=sync` and no Redis.**
  Inline queue processing means a webhook posted to the ingest endpoint is
  captured and its delivery settled before the request returns, so a spec can
  assert on the event queue immediately with no worker to supervise and no
  polling. Nothing in the covered flows needs Horizon.

- **Delivery is never asserted against a real destination.** Destinations are
  created pointing at `https://example.com/...` and stay unvalidated, so the
  consent gate (#18) skips them and no outbound HTTP leaves CI. What the specs
  assert is capture and display: the event exists, its payload renders, replay
  is reachable.

- **Destination validation approval is out of scope for this layer.**
  `SendDestinationValidationChallenge` posts the signed link to the destination
  URL over HTTPS, and `OutboundAddressGuard` refuses private and loopback
  addresses, so there is no way to stand up a receiving endpoint the guard will
  talk to from inside CI. The flow keeps its feature-test coverage. What the
  specs do cover is the member-facing half: the Validate action is reachable and
  the row reports back.

- **Mailpit for the one flow that needs a real email.** Registration lands on the
  verify-email screen, and the link out of it only exists in an email. CI runs
  the same `axllent/mailpit` image `compose.yaml` already uses locally, and the
  spec reads the message through Mailpit's HTTP API. Every other spec is
  mail-free.

- **A separate workflow, `e2e.yml`, not a job inside `tests.yml`.** It needs a
  built frontend, a running server and a browser download that the unit and
  feature checks do not, and it fails for different reasons. Both run on every
  pull request.

## Flows covered

1. **Sign in** — valid credentials reach the dashboard; a wrong password is
   rejected with the error visible; sign out returns to the login screen.
2. **Register and verify** — registration lands on the verify-email screen, the
   emailed link verifies the account, and the new user reaches their own team's
   dashboard.
3. **Proxy lifecycle** — create a proxy with a destination, see it in the index,
   open it, rename it, pause and resume dispatch, delete it.
4. **Ingest to event** — read the new proxy's ingest URL from its page, POST a
   webhook to it, then find that event in the queue, open it, read its payload
   and replay it.
5. **Team isolation** — a proxy belonging to another team is absent from the
   index and its URL does not resolve.

## Tasks

- Add `@playwright/test`; `playwright.config.ts`; `pnpm test:e2e` scripts.
- `app/Console/Commands/SeedE2eDataCommand.php` (`e2e:seed`).
- `tests/e2e/` — the five specs plus small helpers for sign-in, unique naming
  and Mailpit.
- Add `data-test` attributes where a spec would otherwise depend on wording.
- `.github/workflows/e2e.yml`.
- Update the Testing row in `docs/stack/stack.md`; add an entry to
  `docs/status.md`.

## Done when

- `pnpm test:e2e` passes locally against Sail, and the same specs pass in CI.
- The workflow runs on pull requests and uploads the HTML report and traces for
  a failed run.
- `composer ci:check` still passes: the new TypeScript is linted, formatted and
  type-checked with everything else.
