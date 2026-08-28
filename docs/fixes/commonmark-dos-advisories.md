# Fix: commonmark-dos-advisories

- **Date:** 2026-08-27
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
`composer audit` reported six denial-of-service advisories against
`league/commonmark` 2.8.3, a transitive dependency pulled in by `laravel/framework`
(which already allows `^2.8.1` in `composer.json` — no framework version change
needed):

- `PKSA-5mzr-szzf-z6cn` (medium) — DoS via deeply nested XML output, affects `<2.9.0`.
- `PKSA-cqd6-fg4n-nxpf` (high) — DoS via colliding heading slugs, affects `<2.9.0`.
- `PKSA-1q6p-sqkj-8mmj` (high) — DoS via duplicate footnote definitions, affects `<2.9.0`.
- `PKSA-mc58-w91n-f5gv` (high) — DoS via adjacent inline attribute blocks, affects `<2.9.0`.
- `CVE-2026-71488` / `GHSA-2q4p-g7hv-5rgv` (high) — quadratic-time DoS parsing crafted
  Markdown, affects `<2.9.0`.
- `CVE-2026-71478` / `GHSA-29pj-957v-52mc` (medium) — `AttributesExtension` href/src
  unsafe-link filter bypass via embedded control bytes, affects `<=2.8.3`.

## Cause
`league/commonmark` was locked at 2.8.3, below the 2.9.0 release that fixed all six
issues.

## Fix
Ran `composer update league/commonmark --with-dependencies` (transitive upgrade
within the existing `^2.8.1` constraint from `laravel/framework` — no
`composer.json` change, and `league/commonmark` does not appear there directly).
Composer resolved to 2.10.0, the latest release still satisfying `^2.8.1` and every
other package's existing constraints; the resolver also re-locked one of
`league/commonmark`'s own dependencies to its latest allowed patch:

- `league/commonmark`: 2.8.3 → 2.10.0.
- `nette/schema`: v1.3.5 → v1.3.6 (required by `league/config` at `^1.2`, itself a
  dependency of `league/commonmark`; unavoidable side effect of re-resolving
  `league/commonmark`'s dependency subtree, not a constraint change).

No other package moved. `composer.json`'s `require`/`require-dev` blocks are
byte-identical (`git diff composer.json` is empty); `league/commonmark`'s own PHP
requirement is unchanged (`^7.4 || ^8.0`), so no framework or PHP version bump was
needed and the hard-stop escalation condition never triggered.

Only `composer.lock` changed.

## Verification
`composer audit` after the update reports "No security vulnerability advisories
found" — all six `league/commonmark` advisories cleared.

Searched the codebase for real call sites into the library (`CommonMark`,
`Str::markdown`, `Str::inlineMarkdown`, custom mail markdown views): none of those
exist directly, but `app/Notifications/Teams/TeamInvitation.php` returns an
`Illuminate\Notifications\Messages\MailMessage`, which Laravel's `MailChannel`
renders through `Illuminate\Mail\Markdown` → `League\CommonMark\GithubFlavored
MarkdownConverter` — a real, if indirect, call site. The existing coverage in
`tests/Feature/Teams/TeamInvitationTest.php` only inspected `MailMessage` properties
(`actionUrl`, `introLines`); nothing exercised the actual markdown-to-HTML
conversion. That was a genuine gap, so added
`test_invitation_email_renders_to_html_through_commonmark`, which calls the
message's own `render()` method (the same public API `MailChannel` uses internally)
with a team/user name containing HTML-special and markdown-special characters, and
asserts the output is escaped HTML containing the expected content — confirming the
CommonMark conversion still runs correctly end to end post-upgrade.

Gates:
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.
- `./vendor/bin/sail test --parallel` (full suite): 881 passed / 881, 4115
  assertions — fully green.
- `pnpm run format:check`: passed.
- `pnpm run lint:check`: failed, but only on files under
  `.claude/worktrees/autopilot-docs/` — a separate agent's parallel git worktree
  checkout, gitignored but not eslint-ignored, unrelated to this change and outside
  this repo's own source tree. Confirmed zero errors outside that directory (`grep
  -c` on the output showed 100% of flagged files under `.claude/worktrees/`); ran
  the gate twice with an identical result both times per the infrastructure-retry
  rule. Not a defect in this change.
- `pnpm run build`: passed.

## Follow-ups
None.
