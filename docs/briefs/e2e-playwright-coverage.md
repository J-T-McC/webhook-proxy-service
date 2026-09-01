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
  fixtures written into the database mid-test. A spec that needs a proxy creates
  one through the form; the point of this layer is that the path a user takes is
  reachable. Sessions are established by signing in, not by injecting a cookie.

- **Fixed accounts seeded, everything else built by the spec.** A new artisan
  command, `e2e:seed`, creates them through the same `CreateTeam` action
  registration uses. It is idempotent by reuse rather than by deletion: proxies,
  events and deliveries hold restricting foreign keys, so a command that cleared
  its own rows would start failing the moment a spec had captured one event. It
  also never runs `migrate:fresh` — a developer running the suite by habit would
  otherwise wipe their local database — and it refuses to run when
  `APP_ENV=production`.

  Specs create their own proxies with names carrying a unique suffix, so they do
  not collide with each other and can run in parallel workers.

- **One account and one session per parallel worker.** This is not tidiness. A
  session cookie identifies one server-side session, and Inertia's validation
  errors travel in that session's flash, so two workers submitting forms through
  a shared session consume each other's errors: a rejected form comes back
  populated with the values it was rejected for and no error on any field. That
  failure looked exactly like a broken form, and cost most of the debugging time
  this brief covers. Fortify also throttles login attempts per email — five a
  minute — which an account per worker keeps clear of, and the two specs that
  exercise the login form get accounts of their own so a deliberate wrong
  password never spends the shared budget.

- **Local runs target the running Sail app; CI boots its own server.** The
  config reads `E2E_BASE_URL` (default `http://localhost`, which is what
  `compose.yaml` publishes). Playwright's `webServer` is enabled only when
  `E2E_START_SERVER=1`, which CI sets and a developer does not — locally the app
  is already running, and a second copy fighting for port 80 helps nobody.

- **CI runs `php artisan serve` with `QUEUE_CONNECTION=sync` and no Redis.**
  A webhook posted to the ingest endpoint is then captured and settled before the
  request returns, so a spec can assert on the event queue with no worker to
  supervise and no polling. Nothing in the covered flows needs Horizon.

- **Delivery is never asserted against a real destination.** Destinations are
  created pointing at `https://example.com/...` and stay unvalidated, so the
  consent gate (#18) skips them and no outbound HTTP leaves CI. What the specs
  assert is capture and display: the event exists, its payload renders, replay
  is reachable.

- **Destination validation is out of scope for this layer, and stays uncovered.**
  `SendDestinationValidationChallenge` posts the signed link to the destination
  URL over HTTPS, and `OutboundAddressGuard` refuses private and loopback
  addresses, so no receiving endpoint can be stood up that the guard will talk
  to from inside CI. The flow keeps its feature-test coverage; nothing about it
  is asserted in the browser.

- **No mail, because there is no mail step to cover.** The plan was a Mailpit
  round trip through the verify-email screen. `App\Models\User` does not
  implement `MustVerifyEmail` — the import is commented out — so registration
  signs the new user straight in and there is no emailed link in the flow. CI
  therefore runs no mail service, and the registration spec asserts what the
  application actually does. If verification is ever turned on, that spec is
  where the Mailpit step belongs.

- **The specs assert APP_URL matches the origin they browse.** Signed links and
  the ingest URL shown in the UI are built from `APP_URL`, never from the request
  host, so a mismatch produces failures that read as application bugs. The global
  setup compares the two and fails with the fix in the message. This caught a
  stale local `APP_URL` of `http://localhost:8000` while Sail publishes port 80.

- **The ingest spec sends `X-Forwarded-Proto: https`.** `EnsureIngestIsSecure`
  rejects a plaintext ingest request, and the suite runs over HTTP, so the spec
  presents the header a TLS-terminating load balancer sets in production — which
  `bootstrap/app.php` already trusts. The alternative was weakening a security
  guard for tests.

- **A separate workflow, `e2e.yml`, not a job inside `tests.yml`.** It needs a
  built frontend, a running server and a browser download that the unit and
  feature checks do not, and it fails for different reasons. Both run on every
  pull request.

## Flows covered

1. **Sign in** — valid credentials reach the team dashboard, signing out ends
   the session, and a wrong password is rejected with the dashboard still
   closed afterwards.
2. **Register** — a new user gets a team of their own and lands on its
   dashboard; an email already in use is refused.
3. **Proxy lifecycle** — create a proxy with a destination, see it in the index,
   open it, rename it, pause and resume dispatch, delete it.
4. **Invalid destination** — a `http://` destination URL is refused with the
   error rendered on the field.
5. **Ingest to event** — read the new proxy's ingest URL from its page, POST a
   webhook to it, then find that event in the queue, open it, reveal its masked
   payload and reach the replay dialog.
6. **Team isolation** — a proxy belonging to another team is absent from the
   index and its URL does not resolve.

## Tasks

- Add `@playwright/test`; `playwright.config.ts`; `pnpm test:e2e` scripts.
- `app/Console/Commands/SeedE2eData.php` (`e2e:seed`).
- `tests/e2e/` — the specs plus helpers for sign-in, per-worker sessions,
  unique naming and seeded state.
- Add `data-test` attributes where a spec would otherwise depend on wording.
- `.github/workflows/e2e.yml`.
- Extend `tsconfig.json`, ESLint and Prettier to cover the new TypeScript.
- Update the Testing row in `docs/stack/stack.md`; add an entry to
  `docs/status.md`.

## Done when

- `pnpm test:e2e` passes locally against Sail, and the same specs pass with the
  CI configuration (own server, own database, built assets).
- The workflow runs on pull requests and uploads the HTML report and traces for
  a failed run.
- `composer ci:check` still passes: the new TypeScript is linted, formatted and
  type-checked with everything else.
