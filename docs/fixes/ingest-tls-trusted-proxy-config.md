# Fix: ingest-tls-trusted-proxy-config

- **Date:** 2026-08-25
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
`bootstrap/app.php` configured no trusted proxies. `EnsureIngestIsSecure`
(`app/Http/Middleware/EnsureIngestIsSecure.php:15`) rejects any ingest request where
`$request->isSecure()` is `false`, and already documented the dependency: "behind a
TLS-terminating load balancer, trusted proxies + X-Forwarded-Proto." Without trusted
proxies configured, Laravel/Symfony ignores `X-Forwarded-Proto` entirely and derives
`isSecure()` from the app's own (plaintext, TLS terminated upstream) connection to the
load balancer — so behind such a load balancer the ingest guard would evaluate the
wrong scheme. This was already flagged, unfixed, in `docs/reviews/review-01-walking-
skeleton.md` (finding #2) at item #1, deferred because no deploy target existed yet.

Not in scope here (already resolved separately): a proxy's rendered ingest URL
appearing as `http://` was a *different*, already-fixed symptom — the Project Owner
corrected `APP_URL`/`INGEST_URL` in the deployment environment. Ingest URLs are built
solely from `config('ingest.url')` (`app/Models/Proxy.php:122`, ADR-006) and this fix
does not touch that.

## Cause
No call to `Illuminate\Foundation\Configuration\Middleware::trustProxies()` existed in
`bootstrap/app.php`, so Symfony's `Request` never trusted any upstream proxy and never
read forwarded headers.

## Fix
`bootstrap/app.php` — added, first in the `withMiddleware()` closure:

```php
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

`at: '*'` (trust the immediate connection, whatever its address) rather than a
specific CIDR/IP list: no deploy target or load-balancer IP range is defined anywhere
in this repo (`docs/stack/stack.md` — "Deployment: Not yet defined — Owner decision";
no Dockerfile/`fly.toml`/Vapor config), so there is no concrete IP list to hard-code,
and inventing one would be a guess at infrastructure not yet decided. `at: '*'` is
Laravel's own documented alternative for exactly this case (platforms without a
static, enumerable proxy IP range — AWS ALB/ELB, Heroku, Cloudflare all resolve via
this same setting in Laravel's official docs/starter kits). It is safe under the same
assumption every one of those platforms relies on: the app must never be reachable
except through that load balancer — an infra/network-layer guarantee this line does
not itself enforce, and is out of scope for an app-layer fix. `EnsureIngestIsSecure`'s
existing app-layer 403 remains as the defense-in-depth backstop regardless.

Header set: the four standard forwarding headers (`For`/`Host`/`Port`/`Proto`) —
enough for `isSecure()` (`Proto`) plus correct client-IP/host/port resolution
elsewhere in the app, without also trusting `X-Forwarded-AWS-ELB`/prefix headers this
app has no use for.

## Verification
Regression tests pin the guard's behaviour both with and without forwarded headers
(`tests/Feature/Ingest/IngestControllerTest.php`), confirmed to fail before the fix
(verified via `git stash` of `bootstrap/app.php`, tests kept) and pass after:

- `test_https_forwarded_via_a_trusted_proxy_header_is_accepted` — a request whose own
  connection to the app is plain HTTP (as it is for every instance behind a
  TLS-terminating load balancer) but carrying `X-Forwarded-Proto: https` is accepted
  (202). **Failed with 403 before the fix** — the exact reported symptom.
- `test_forwarded_proto_of_http_is_still_rejected` — an explicit
  `X-Forwarded-Proto: http` is still rejected (403); trusting the header is not a
  blanket bypass.
- Pre-existing `test_non_https_request_is_rejected` (no forwarded header, plain HTTP —
  still 403) and `test_valid_token_returns_202`/every other test in the file (direct
  HTTPS, unaffected) continue to pass, confirming the change is additive.

Full gates:
- `./vendor/bin/sail test --filter IngestControllerTest`: 15 passed / 44 assertions.
- `./vendor/bin/sail test --parallel` (full suite): **716 passed / 716, 2618
  assertions** — fully green.
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.

## Follow-ups
None for this fix. When a deploy target is decided (Owner decision, per
`docs/stack/stack.md`), revisit whether the load balancer's IP range is static enough
to narrow `at: '*'` to an explicit list — not required for correctness, but would
tighten the trust boundary beyond "network-layer isolation only."
