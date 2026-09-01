# Technical Plan: Destination validation (#18)

- **Status:** **Approved, 2026-08-31.** Self-certified by the Principal Engineer; ADR-027's two open
  decisions accepted by the Project Owner the same day. Outstanding questions: none. Q-18-02 was
  closed by Owner ruling and PRD-18 AC23 amended to match.
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-18-destination-validation.md` (Approved, Project Owner, 2026-08-31)
- **Design:** `docs/design/design-18-destination-validation.md` — reference, not a gate. The Owner
  dropped the Designer phase for this item on 2026-08-31 on the grounds that the UI is state badges,
  one action and a four-outcome confirmation page. The spec is accurate about which components are
  extended and is used as the screen inventory.
- **Answered question:** `docs/questions/prd-18-q-18-01-validation-enforcement-and-send-safety.md`
- **Open question:** `docs/questions/prd-18-q-18-02-ac23-log-guarantee.md` (Product Manager)

## Overview

A destination carries a validation state. Only a validated destination has `deliveries` rows created
for it, and only a validated destination is sent to. Approval happens when somebody at the receiving
end opens a signed link the product sent to the destination's own URL and confirms it. The whole
feature is a state column, a gate in four places, one guarded outbound send, and two public routes.

## Architecture

**No new architectural seam.** The work extends the ADR-001 pipeline spine at the point where it
already selects destinations, and adds one dispatchable action alongside the existing ones.

Enforcement, per Q-18-01 item 1 — **pause's own two points are the wrong granularity** (pause is
per-proxy, validation is per-destination) so this feature does not reuse them. The four places:

1. **`ProcessIngestedWebhook`, the delivery-row creation loop** (currently line 94). Filter to
   validated destinations. This is AC8's queue-check. Because no row is created, there is no unit of
   work to skip, hold or park — AC10 and AC11 fall out of the shape rather than needing handling.
2. **`DeliverToDestination`, on the worker.** Re-check at send time; a destination can leave the
   validated state between row creation and send, via AC5's URL edit or a challenge expiring under a
   retry backoff. This is AC8's dispatch-gate. A delivery whose destination is no longer validated is
   resolved without an attempt record.
3. **`SweepDueRetries`.** Re-dispatches from existing rows and never passes through point 1, so it
   must exclude non-validated destinations, mirroring its existing `paused_at` exclusion at line 49.
4. **`ProxyEventReplayController`.** Pre-creates its own rows for a chosen subset and bypasses point
   1 entirely. AC9 requires replay to a non-validated destination to be unavailable *with the reason
   given*, so this is a controller and policy refusal, not a silent worker drop.

`SweepStalledFifoDispatches` re-drives existing rows and inherits point 2. No fifth path exists:
`DeliverStep` iterates `deliveries` rows and never reads `$proxy->destinations`.

**FIFO note (Q-18-01 item 2).** When every destination of a FIFO proxy is unvalidated, zero rows are
created and `AdvanceProxyFifoQueue::settleOrHold()` settles the row as done. `ProcessIngestedWebhook`
carries a comment warning against exactly this for *pause*, where it would be silent loss. For
validation it is the required behaviour: the queue advances, nothing is held, and AC10's
"never delivered retroactively" holds. **Known consequence:** the event's status still flips to
`Dispatched`, because that write is gated on the dispatch being the original one, not on any row
existing. An event that reached nobody reads as dispatched; design-18's Screen 3 indicator is what
tells the member the truth. Recorded, not hidden.

## Data Model

**`destinations` gains seven columns.** ADR-027 decision 1 — a change to a table holding live data.
The last two were added on 2026-08-31 by a separate Project Owner approval, in the ruling on
review-18 finding 6; the count above was previously written as four while listing five, and is
corrected here.

- `validation_state` — string-backed enum, `unvalidated` / `pending` / `validated`. Not null.
- `validated_at` — nullable timestamp.
- `validation_challenge_sent_at` — nullable timestamp, drives design-18's "sent, awaiting approval"
  copy and the rate-limit messaging.
- `validation_challenge_expires_at` — nullable timestamp.
- `validation_nonce` — nullable string. Regenerated on every challenge send.
- `validation_last_send_status` — nullable unsigned small integer. The HTTP status the
  destination returned on the most recent send that reached it.
- `validation_last_send_failure` — nullable string, backed by a new
  `App\Enums\DestinationValidationSendFailure` enum: `unreachable`, `address_refused`,
  `redirected`. The reason the most recent send did not reach the destination.

**The last send's outcome is stored, and it is not a fifth state.** PRD-18 AC35 requires that a
member can tell "the challenge never arrived" from "it arrived and was rejected" from "nobody has
opened it" — three situations with three different remedies. The first two are outcomes of an
action, not conditions of the destination, which is exactly why PRD-18 refused a `send-failed`
state and put the distinction in these two columns instead. Exactly one of the pair is ever set:
every send writes one and clears the other, so the row always describes a single attempt. A send
refused before it is attempted — the destination is already Validated, or a rate limiter is
tripped — touches neither column, because nothing was sent and the previous outcome is still the
most recent one. The reason is stored as a key rather than as prose: design-18 forbids
implementation jargon in this copy, so the exception message never becomes member-facing text and
the wording stays where the rest of the validation copy lives.

**Four product states, three stored.** PRD-18 AC1 names Unvalidated, Pending, Expired and Validated.
`Expired` is **derived**, not stored: state is `pending` and `validation_challenge_expires_at` has
passed. This is deliberate — a stored `expired` would need a scheduled sweeper to write it, and a
sweeper that falls behind would leave a destination reading as pending past its expiry. Deriving it
makes the display always correct and needs no job. The enforcement query is unaffected and stays
`where('validation_state', Validated)`: expired is not validated under either representation.

**New enum** `App\Enums\DestinationValidationState`, string-backed, per the architecture standard
that persisted domain vocabulary lives in `Enums/` and is the source of truth for `Rule::enum` and
casts.

**Backfill.** The migration sets every existing row to `validated` with `validated_at` at migration
time — PRD-18 AC30, the Owner-approved grandfathering. ADR-027 decision 2, because it writes
production data and cannot be undone by rolling the migration back.

**State transitions.** Unvalidated → Pending on a challenge send. Pending → Validated on approval.
Any state → Unvalidated on a URL change (AC5), which also clears the nonce, both challenge
timestamps and both last-send columns, voiding any outstanding link. The outcome columns clear with
them because they describe a send to the old address and would misdescribe the new one. A fresh
send from Pending or Expired replaces the nonce, which is what makes the previous link inert
without a revocation list.

## API

Two **public, unauthenticated** routes — the approver has no account (AC26):

- `GET /destinations/validate/{destination}` — `signed` middleware. Renders the confirmation page.
  Inert: renders only, never approves. This is what makes a link scanner or mail preview fetcher
  harmless (AC28).
- `POST /destinations/validate/{destination}` — `signed` middleware. Performs the approval.

Both carry the nonce as a signed parameter. Approval requires the signature to verify, the nonce to
equal the destination's current `validation_nonce`, and the state to be `pending` and unexpired.
Signature failure, nonce mismatch, expiry and already-validated are four distinct outcomes rendered
as four distinct screens, per design-18 Screen 4.

One authenticated route: `POST /destinations/{destination}/validate`, the member-facing Validate
action, authorised by the existing update-destination permission (AC44 adds nothing to the #2 model).

## Services

**`URL::temporarySignedRoute()` with the `signed` middleware is the whole token mechanism.** Per the
Owner's standing preference for first-party functionality: no token table, no random-string
generation, no expiry column consulted for validity, no hand-rolled comparison. AC22's three
properties map directly — unguessable comes from the signature, the 7-day expiry is the method's own
argument, and single-use comes from the nonce plus the state check, since a signed URL is not
single-use on its own.

**`App\Services\OutboundAddressGuard`** — new, and the only genuinely custom security code in the
feature, because nothing first-party does this. Q-18-01 item 3: resolve the host once, refuse if any
returned address is loopback, private, link-local, unique-local or cloud-metadata, then **pin the
connection to the validated address** via cURL's `CURLOPT_RESOLVE` through the HTTP client's Guzzle
option map. Check and connection are then the same address by construction, which is what closes the
DNS-rebinding gap. ADR-027 decision 3.

**Redirects are refused, not followed**, which is what makes AC19 sufficient — pinning does not
extend to a second hop, and a validation challenge has no legitimate reason to be redirected. A
redirect response is a failed validation send.

**`App\Actions\SendDestinationValidationChallenge`** — dispatchable (`AsJob`), the ADR-005 timing
seam, consistent with `DeliverToDestination`. It builds the fixed challenge body, applies the guard,
sends, and records the outcome on the destination. **It does not go through the delivery pipeline and
creates no `deliveries` or `delivery_attempts` rows**: a validation send is not a delivery, it must
not appear in #11's measures (AC42), and per AC17 it must not carry the destination's stored
credential — routing it through `DeliverToDestination` would attach that credential and turn a URL
edit plus an automatic send into a credential-exfiltration path.

**Rate limiting** uses the `RateLimiter` facade, following the existing named-limiter pattern in
`app/Providers/FortifyServiceProvider.php`. AC21's three limits: one send per destination per five
minutes, ten per destination per day, one hundred per team per day. A blocked send reports when it
may be retried (design-18 Flow D) rather than presenting a dead button.

## Validation

Form Request rules for the destination form are unchanged except that a changed `url` triggers the
AC5 reset in the controller's transaction. The address guard is **not** a form-validation rule: it
runs at send time, because a hostname that validates at save time can resolve elsewhere later, and
because AC40 scopes address refusal to validation sends only — ordinary delivery to grandfathered
destinations at private addresses keeps working until their URL changes.

## Risks

- **Grandfathering is irreversible in practice.** Rolling the migration back drops the columns; it
  does not restore a pre-migration notion of which destinations were trusted. Owner-approved via
  AC30 and carried as ADR-027 decision 2.
- **AC23 was narrowed to this application's own layers** by Owner ruling on 2026-08-31, closing
  Q-18-02. The signed-URL shape is settled and the confirmation page stays plain. Residual risk is
  bounded by HTTPS in transit, single use, and the 7-day expiry — not by the URL being signed, which
  prevents tampering rather than replay.
- **The event-status consequence** described under Architecture — an event with all destinations
  skipped reads as `Dispatched`. Behavioural, visible, recorded here rather than discovered in review.
- **`CURLOPT_RESOLVE` binds the plan to the cURL transport.** If the HTTP client is ever configured
  with a non-cURL handler the pinning silently stops applying. The guard must fail closed if the
  handler cannot pin, rather than sending unpinned.

## Dependencies

No new packages. Everything used is already in the stack: Laravel's URL signing and `signed`
middleware, the `RateLimiter` facade, the HTTP client's Guzzle options, `lorisleiva/laravel-actions`
per ADR-007, and Inertia for the two public pages. `docs/stack/stack.md` needs no change.

## Implementation Notes

- The four gate points are the acceptance surface: a test per point, plus one proving no fifth path
  exists by asserting `DeliverStep` never consults `$proxy->destinations`.
- The nonce comparison, not the signature, is what makes a link single-use. A test should prove a
  second POST with a still-valid signature is refused after approval.
- The address guard needs tests against a literal private address, a hostname resolving to one, and a
  hostname whose resolution changes between check and send — the last proving the pin holds.
- Per `docs/standards/testing.md`, tests group by action; no migration-mechanics tests.

## Handoff

- **Inputs:** PRD-18 (Approved), design-18 (reference), Q-18-01 (Answered), `docs/standards/architecture.md`.
- **Outputs:** this plan; ADR-027; Q-18-02 to the Product Manager.
- **Outstanding Questions:** Q-18-02, scoped to the confirmation page.
- **Next Agent:** **Task Planner.** ADR-027 is Accepted and nothing is outstanding.
