# Brief: turn email verification on

## What

Everything for email verification was already wired — `Features::emailVerification()`
in `config/fortify.php`, the `verification.notice` / `verification.verify` /
`verification.send` routes, the `auth/VerifyEmail` page, and tests for the
verification screen and the signed link. One thing was missing:
`App\Models\User` did not implement `MustVerifyEmail`, and the interface is what
makes any of it apply.

Without it, Fortify sends no verification notification on registration, and
`EnsureEmailIsVerified` — the `verified` middleware guarding every team route and
the settings group — lets everyone straight through. The feature looked present
and enforced nothing.

The Project Owner asked for it on 2026-08-31.

## Decisions

- **The change is the interface, nothing else.** `class User extends
  Authenticatable implements MustVerifyEmail, PasskeyUser`. No new routes, no
  config change, no migration. The `MustVerifyEmail` *trait* is already inherited
  from `Illuminate\Foundation\Auth\User`, so the methods were always there; only
  the contract that activates them was absent.

- **The routes stay as they are.** The `verified` middleware already sits on the
  `{current_team}` group and on the second settings group. `settings/profile` is
  deliberately in the `auth`-only group and stays reachable while unverified —
  registering with a mistyped address is recoverable only if the person can still
  change it. That boundary now has a test naming the reason.

- **Tests that fail if the interface is ever removed again.** This was the real
  gap: the whole suite passed both before and after the change, so nothing was
  holding the guard in place. Five new tests fail without the interface —
  registration sending the notification, a newly registered user bouncing off the
  dashboard, and unverified access to the dashboard, the proxy list and team
  settings — plus the browser spec below. Verified by removing the interface and
  watching each one fail.

- **The end-to-end spec reads the real message.** The registration spec now
  registers, lands on the verification notice, pulls the link out of Mailpit over
  its HTTP API, follows it and arrives on the new team's dashboard. Mailpit is
  the transport in Sail already; the CI job gains the same `axllent/mailpit`
  service and `MAIL_MAILER=smtp`. What this proves that a feature test cannot is
  that the link inside the delivered message works when a browser follows it.

  The mail helper matches the link by path (`/email/verify`), not by position: a
  Laravel markdown mail opens with the application name linked to the site root,
  so "the first link in the body" is the header.

## Rollout — settled by the Project Owner, 2026-08-31

Both consequences of turning the guard on were put to the Owner before merge and
answered:

- **Existing unverified accounts: no backfill.** An account whose
  `email_verified_at` is null now reaches only `settings/profile` and the
  verification notice, which carries a resend button. Production holds exactly
  one such account, a test account, and the Owner will verify it by hand. A
  backfill migration was therefore not written — and should not be added later
  without a reason of its own, since treating an unproven address as proven is
  the thing this feature exists to prevent.

- **Mail already sends in production.** It is on Mailgun (PR #20), so a new
  registration can complete verification. `.env.example` keeps `MAIL_MAILER=log`,
  which is a local default and not what production reads.

## Done when

- The five feature tests and the browser spec pass, and each fails with the
  interface removed.
- `composer ci:check` and the Playwright suite are green.
