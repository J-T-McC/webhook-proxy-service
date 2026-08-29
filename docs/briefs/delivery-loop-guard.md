# Brief: delivery loop guard

## What

A destination URL pointing back at this service's own ingest endpoint creates
an unbounded delivery loop: delivery lands on `/ingest/{token}`, creates a new
`webhook_event`, dispatches again, forever. Two independent guards close the
direct case; a hop counter bounds the indirect case; redirect-following is
turned off so a destination cannot route around the host checks with a 3xx.

## Decisions

- **Save-time 422** on `destinations.*.url`, both `StoreProxyRequest` and
  `UpdateProxyRequest`: reject a host equal to the ingest host, and reject an
  IP-literal host. Ingest host is `parse_url(config('ingest.url'),
  PHP_URL_HOST)` — never the request `Host` header (ADR-006 guard). Shared
  host-comparison logic lives in `App\Support\IngestHostGuard`, used by the
  new `App\Rules\NotSelfReferencingDestinationUrl` rule and by the send-time
  backstop below, so the two checks can never drift apart.
- **Send-time backstop** in `DeliverToDestination::send()`: the same self-host
  comparison, immediately before the HTTP call. Rows saved before this rule
  existed are never re-validated by a form rule, and `INGEST_URL` can change
  so a previously-valid destination becomes self-referential after the fact.
  Fails that attempt with a clear `error_summary`, caught by the method's
  existing `catch (Throwable $e)` — no new catch path. Does not re-check
  IP-literal (static at save time, not something `INGEST_URL` can change into
  existing since IP-literal); that is a save-time-only guard.
- **`Http::withoutRedirecting()`** on the delivery client. Guzzle follows
  redirects by default, which would otherwise let a destination answering 302
  route around both host checks. A 3xx now settles as an ordinary failed
  attempt through the existing `$response->successful()` path.
- **Hop marker — survived two scope reversals; record why it stays.** The
  save-time 422 closes *direct* self-reference; a hop counter is not
  defence-in-depth for that case — if a direct loop ever occurred, the rule
  itself failed and that is the bug to fix. The hop marker exists only for
  *indirect* cycles, where the destination host is genuinely not ours and no
  host rule ever applies: a third-party relay the user wired back to us, or a
  customer domain that CNAMEs to us. Outbound stamp `WebhookProxy-Hops`,
  composed through `OutboundHeaders::build()` on the same displacement path
  that already prevents a forwarded credential/signing-header collision, so a
  forwarded inbound copy of this header is displaced rather than emitted
  alongside ours. Ingest reads the inbound value (absent or non-numeric = 0);
  outbound emits inbound + 1. At the limit, ingest rejects with **508 Loop
  Detected** before capture — no `webhook_event` row, no dispatch. That
  rejection reaches back as the delivering side's HTTP response, so it
  settles as an ordinary failed attempt (non-2xx) through the existing retry
  policy — no separate "ingest rejection" plumbing needed, and the proxy is
  never auto-paused. New config key `ingest.max_hops`, env `INGEST_MAX_HOPS`,
  default 3, documented in `.env.example`.
  **Known limit, stated plainly:** the counter only survives a hop if the
  intermediary forwards unknown headers. A relay that rebuilds the request
  from scratch resets the count to zero and this guard misses that ring. It
  does hold for our own service chained back to itself, because we forward
  inbound headers to destinations, so our own counter returns to us intact.
- **`DeliveryUnit::STRIPPED_HEADERS` stays untouched** — it is the fixed
  ADR-008/ADR-026 deny-list; the hop header is an *added* header, composed
  the same way the credential/signing headers already are, never a member of
  that list.

## Out of scope (known remaining gap)

DNS resolution, private/loopback/link-local/metadata IP-range blocking, and
connection IP pinning are not implemented. General SSRF is not closed by this
change — only self-reference to this service's own ingest host (direct or
via the hop-bounded indirect case) and IP-literal destination hosts at save
time.

## Done when

422 rules on both requests + send-time backstop + `withoutRedirecting()` +
hop marker (stamp, displacement, ingest 508 + accept-below-limit, non-numeric
= 0) landed, tests green, `composer lint`/`types:check` clean.
