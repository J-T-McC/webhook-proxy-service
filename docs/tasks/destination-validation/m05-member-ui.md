# M5 — Member-facing UI

Implements design-18 Screens 1–3. This is the only milestone that reads the design spec. State is
never carried by colour alone.

## T15 — Validation state on the destination row

- **Description:** Show each destination's state — including derived Expired — on the proxy Show
  page, extending `DestinationsCard.vue`. Include when the challenge was sent and when it expires.
  **Never render the link itself** (AC24).
- **Dependencies:** T2.
- **Files:** `resources/js/components/proxies/DestinationsCard.vue`,
  `resources/js/types/proxies.ts`, the resource or DTO exposing the state
- **AC-trace:** PRD-18 AC31, AC32, AC24; design-18 Screen 2.
- **Verify step:** a proxy with one destination in each state renders four distinct treatments.
- **Testing:** a controller/resource test asserting the state reaches the page and the token does
  not; a component test for the four treatments.
- **Completion notes:** Done, 2026-08-31. The state rides the existing `security.destinations`
  map (`ProxySecurityResource`) as a per-id `validation` object — status (with Expired derived
  server-side by `Destination::validationStatus()`), `approved_at`, `challenge_sent_at`,
  `challenge_expires_at`. `validation_nonce` is never selected from the database, so the link's
  secret half cannot reach any member response (AC24) — asserted by
  `ProxySecurityResourceTest::test_the_validation_nonce_never_reaches_the_page` against both
  `show()` and `edit()`. The four treatments (badge variant + icon + label + caption) live in
  `resources/js/data/destinationValidationStates.ts` following the `proxyDeliveryStates` data-const
  pattern, so Screen 1 (T17) reuses them verbatim; `DestinationsCard.vue` renders them in a new
  Validation column between Destination and Delivered %. **Amended 2026-09-01** — the table now
  declares fixed column widths and a minimum width; without them automatic layout gave the
  unbreakable URL 559px and crushed this column to 117px. See
  `docs/fixes/destinations-table-validation-column-width.md`. Deliberate simplifications: the Pending
  caption omits design-18's `{http_status}` and the Unvalidated caption has no "last send failed —
  {reason}" variant, because no send-outcome columns exist on `destinations` (AC35 is traced by no
  task in this plan); upgrade path is storing the last send outcome and threading it through the
  same `validation` object. Component treatments verified by inspection — no JS test framework
  exists (review.md standing note).

## T16 — The Validate action

- **Description:** An explicit Validate control on the destination row whenever the destination is
  not validated, available to members who may update it. Follows the existing immediate-action
  busy-state precedent in `SigningCard.vue` / `useProxyActions.ts`. When rate-limited it says when it
  may be retried rather than presenting a dead button.
- **Dependencies:** T10, T15.
- **Files:** `DestinationsCard.vue`, `resources/js/composables/useProxyActions.ts`, the route and
  controller action for the member-facing send
- **AC-trace:** PRD-18 AC14, AC21, AC44; design-18 Flows C and D.
- **Verify step:** click Validate on an unvalidated destination; it moves to Pending. Click again
  inside five minutes; the retry-after is shown.
- **Testing:** authorization (a member without update permission does not get the action), the
  happy path, and the rate-limited path.
- **Completion notes:** Done, 2026-08-31. New authenticated route
  `POST proxies/{proxy}/destinations/{destination}/validate`
  (`proxies.destinations.validate.store`, scoped bindings) on
  `DestinationValidationSendController` — nested under the proxy per the
  `proxies.destinations.destroy` precedent rather than the plan's flat
  `/destinations/{destination}/validate`, whose path the public signed routes already occupy.
  Gated by `authorize('update', $proxy)`, the same ability `DestinationController::destroy` uses
  (AC44, no new permission). The send is synchronous (`handle()`, not `dispatch()`) so the row
  reads Pending when the redirect lands — Flow C step 3. Rate limits surface as a
  `send_blocked {description, until}` fact on the T15 `validation` object, computed by the new
  `SendDestinationValidationChallenge::blockedBy()` (three fixed plain-language descriptions in
  check order; `availableIn()` now delegates to it) — so the row replaces the button with the
  reason both on a fresh page view and after a blocked click, one mechanism for both (Flow D).
  Busy state per destination id in `useProxyActions.validateDestination()` with a `Spinner` in the
  button, the `SigningCard` precedent. A blocked click redirects back with no toast (the refreshed
  props already carry the line); a failed send flashes a generic error toast — per-reason failure
  copy needs the send outcome stored, the same T15 simplification. Tests:
  `DestinationValidationSendControllerTest` — happy path to Pending, teammate's-proxy 403 with
  nothing sent, second click inside five minutes sends nothing and the page reports the
  5-minute limit with its clear time, unblocked destination reports `send_blocked: null`.
- **Rework (review-18 finding 4):** the controller now refuses a Validated destination before the
  rate-limit check (error toast, redirect, nothing sent) — the UI hid the button but the server was
  not gating, so a hand-crafted POST could send a challenge that force-filled the destination back
  to Pending (AC6: no manual un-validation route). The action refuses too (T9 rework — that is the
  enforcement surface; this is the feedback layer). Test asserts a Validated destination stays
  Validated with `validated_at` intact and nothing sent.

## T17 — The URL-change warning

- **Description:** Tell the member **before** they save that changing the URL returns the destination
  to unvalidated and stops delivery until it is approved again — not after.
- **Dependencies:** T11.
- **Files:** `resources/js/components/DestinationRows.vue`, `resources/js/pages/proxies/ProxyForm.vue`
- **AC-trace:** PRD-18 AC5, AC34; design-18 Screen 1 and Flow B.
- **Verify step:** edit the URL of a validated destination; the warning appears before submission.
- **Testing:** component test — the warning shows on a URL edit of a validated destination and does
  not show for a new destination or a non-URL edit.
- **Completion notes:** Done, 2026-08-31. Implemented design-18 Screen 1 in full in
  `DestinationRows.vue`: the fieldset-level approval help line, the read-only per-row status
  (badge + caption reused from T15's data const; existing rows only — a row with no `id` shows
  nothing) and the URL-change warning. The warning compares against a `Map` of URLs snapshotted at
  component setup keyed by destination id — the mount-seeded persisted value, never the last
  keystroke — so it appears the instant the field differs from what the server holds and clears on
  reverting, surviving add/remove and failed-submit re-renders. Wording keys off the row's
  persisted state; per design-18 Screen 1's prose (which its own table contradicts), only the
  Validated case gets the bordered `Alert` (`Info` icon) and Pending/Unvalidated/Expired get the
  muted sentence — the prose carries the reasoning, so it won. The URL input's `aria-describedby`
  gains the warning's id while it shows. `ProxyForm.vue` passes `security.destinations` through
  (optional — Create renders no status, and new rows warn about nothing). Data path covered by
  `ProxySecurityResourceTest::test_the_edit_page_carries_each_destinations_validation_state`;
  warning show/hide behaviour verified by inspection — no JS test framework exists.

## T18 — The not-all-validated indicator

- **Description:** Surface on the proxy that some destinations are not receiving events, so a member
  does not discover it by noticing missing deliveries. Header badge plus the row treatment from T15;
  design-18 ruled against a third redundant banner.
- **Dependencies:** T15.
- **Files:** `resources/js/pages/proxies/Show.vue`
- **AC-trace:** PRD-18 AC33; design-18 Screen 3.
- **Verify step:** a proxy with one unvalidated destination shows the indicator; one with all
  validated does not.
- **Testing:** component test for both cases, including the all-unvalidated case where the event
  status still reads Dispatched — the consequence recorded in plan-18 § Architecture, which this
  indicator exists to explain.
- **Completion notes:** Done, 2026-08-31. `unvalidatedCount` computed in `Show.vue` over the
  **live** `proxy.destinations` relation (a soft-deleted destination is not part of the fan-out and
  has nothing to validate), looked up against T15's `security.destinations` validation map —
  Unvalidated, Pending and Expired all count, per Screen 3. Badge (`waiting` variant, `Clock`
  icon, "{n} destination(s) not yet validated") sits in the existing header badge row beside
  Paused, renders only when the count is positive, no positive counterpart and no page-level
  banner. The all-unvalidated Dispatched consequence is already asserted server-side by
  `ProcessIngestedWebhookTest::test_an_event_whose_destinations_are_all_unvalidated_is_still_captured_and_creates_no_attempts`
  (M2); badge show/hide verified by inspection plus a green host `npm run build` — no JS test
  framework exists.

## T19 — Record the outcome of the last validation send

- **Description:** Store what happened on a destination's most recent validation send, so the
  interface can tell a member which of three different situations they are in. Closes review-18
  finding 6, which found AC35 implemented by nothing and traced by no task — a plan-coverage gap the
  Senior Developer recorded honestly in the T15 and T16 completion notes rather than papering over.
  Two nullable columns are added to `destinations`: `validation_last_send_status`, an unsigned small
  integer holding the HTTP status the destination returned on a send that reached it, and
  `validation_last_send_failure`, a string holding a reason key for a send that did not reach it.
  Exactly one of the two is ever set. Every send clears the other, so the pair always describes one
  outcome rather than two outcomes from different attempts.
  **The reason is stored as a key, never as member-facing prose.** `SendDestinationValidationChallenge`
  has exactly three failure exits and the reason key names which one was taken: `address_refused`
  when `OutboundAddressGuard` rejects the address (AC20), `unreachable` when the HTTP call throws
  (DNS failure, refused connection, timeout), and `redirected` when the response is a redirect
  (AC19). The exception message is never stored — design-18 forbids implementation jargon in this
  copy, and T20 owns the wording. Introduce a backed enum for the three keys, following
  `DestinationValidationState`'s existing shape.
  **The two early returns are not sends and must not touch either column.** A send refused because
  the destination is already Validated, and one refused by a rate limiter, both return before
  `recordAttempt()`; neither reached the destination, so neither has an outcome to report, and
  overwriting a real previous outcome with a non-attempt would tell the member the opposite of what
  happened.
  **A URL change clears both columns**, alongside the nonce and challenge timestamps it already
  clears (AC5). The stored outcome describes a send to the old address; leaving it in place would
  attribute it to the new one. Extend whatever already performs that reset rather than adding a
  second reset path.
- **Dependencies:** T10, T15.
- **Owner gate:** the schema change was approved by the Project Owner on 2026-08-31, in the ruling on
  review-18 finding 6. No ADR — two nullable columns and a three-value enum are cheap to reverse.
- **Files:** a new migration under `database/migrations/`, `app/Models/Destination.php`,
  `app/Enums/DestinationValidationSendFailure.php` (new),
  `app/Actions/SendDestinationValidationChallenge.php`,
  `app/Http/Resources/ProxySecurityResource.php`
- **AC-trace:** PRD-18 AC35, AC18, AC19, AC20; design-18 Screen 2 state table.
- **Verify step:** send a challenge to a URL that returns 404 — the row records status 404 and no
  failure. Point a destination at a private address and send — the row records the
  `address_refused` key and no status. Hit the rate limit and send again — neither column changes.
- **Testing:** extend `SendDestinationValidationChallengeTest` with one case per exit: a 2xx and a
  non-2xx both store their status and clear any prior failure; a guard refusal, a transport
  exception and a redirect each store their own reason key and clear any prior status; a
  rate-limited send and a send at a Validated destination leave both columns exactly as they were.
  Extend `ProxySecurityResourceTest` to assert both fields reach the page inside the existing
  `validation` object, and that `validation_nonce` still does not.
- **Completion notes:** Done, 2026-08-31. Migration
  `2026_08_31_000002_add_last_send_outcome_to_destinations_table.php` adds
  `validation_last_send_status` (nullable unsigned small integer) and
  `validation_last_send_failure` (nullable string), both after `validation_nonce`; new backed enum
  `App\Enums\DestinationValidationSendFailure` with the three keys, cast on the model and absent
  from its `#[Fillable]` list like every other validation column. In
  `SendDestinationValidationChallenge` the three failure exits below `recordAttempt()` call one new
  private `recordFailure()` helper, which writes the key and nulls the status; the success
  `forceFill` writes `$response->status()` and nulls the failure in the same save it already
  performed, so a send costs no extra write. The two early returns — Validated, and rate-limited —
  are untouched, asserted by two tests each showing a seeded prior outcome still standing
  afterwards. A redirect stores `redirected` and no status: the 302 is a failed send under AC19 and
  the member's remedy is the address, not the code. `ProxyController`'s existing URL-change
  `forceFill` clears both alongside the nonce and timestamps, asserted in
  `DestinationValidationDispatchTest::test_changing_a_destinations_url_resets_it_and_dispatches_a_fresh_challenge`.
  `ProxySecurityResource` selects the two columns and exposes them as `last_send_status` and
  `last_send_failure` inside the existing `validation` object, the failure as `?->value` so the
  wire carries the key rather than a serialized enum. Seven new tests in
  `SendDestinationValidationChallengeTest` (22 total, all green) — one per exit, plus one asserting
  the raw stored failure never contains the cURL error text — and one in `ProxySecurityResourceTest`
  covering answered, failed and never-sent rows. No ADR: the Owner approved the schema change in the
  ruling on review-18 finding 6, and two nullable columns are cheap to reverse.

## T20 — Show the outcome of the last validation send

- **Description:** Render T19's stored outcome in the two captions design-18 already specifies but
  that shipped without it, completing AC35's member-facing half. The Pending caption gains the
  response status the destination returned: "Sent {sent_at}, destination responded {http_status} —
  waiting on someone at this address to approve it. Expires {expires_at}." The Unvalidated caption
  gains a failed-send variant: "Last attempt failed to send — {reason}. Nothing has been asked of
  this destination yet." The three `{reason}` phrases are design-18's verbatim, mapped from T19's
  reason keys in the frontend, where all the other validation copy already lives:
  `unreachable` renders "could not reach this address"; `address_refused` renders "this address
  can't be used for validation" — never named as an internal-address rule, since the member's remedy
  is the same either way; `redirected` renders "this address redirected elsewhere, which validation
  doesn't follow".
  Both captions keep the existing fallback discipline: a row with no recorded outcome — every row
  backfilled by T3, and every row whose only send predates T19 — renders today's wording unchanged
  rather than a gap or an "undefined". Expired and Validated captions are untouched.
- **Dependencies:** T19.
- **Files:** `resources/js/data/destinationValidationStates.ts`
- **AC-trace:** PRD-18 AC35, AC34; design-18 Screen 2 state table and its failure-reason copy block.
- **Verify step:** on the proxy Show page, a Pending destination that answered 404 reads "…
  destination responded 404 …"; an Unvalidated destination whose last send was refused reads "Last
  attempt failed to send — this address can't be used for validation. …"; a destination that has
  never been sent a challenge reads today's "No validation challenge has been sent yet."
- **Testing:** no JS test framework exists (the standing note in `docs/standards/review.md`), so the
  three reason mappings and both fallbacks are verified by inspection plus a green host
  `npm run build`. The data path behind them is covered server-side by T19's
  `ProxySecurityResourceTest` additions.
- **Completion notes:** Done, 2026-08-31. `DestinationValidation` gains `last_send_status` and
  `last_send_failure`, mirroring `ProxySecurityResource` exactly as the interface's contract
  requires; new exported `DestinationValidationSendFailure` value union mirrors the PHP enum, with a
  `SEND_FAILURE_REASONS` map holding design-18's three phrases verbatim. `destinationValidationCaption`
  gained two branches and nothing else: Unvalidated returns the failed-send sentence when a failure
  is recorded and today's wording otherwise, and Pending interpolates ", destination responded
  {status}" only when a status is present. Both fallbacks follow the file's existing discipline, so
  every row backfilled by T3 and every row whose only send predates T19 reads exactly as it did
  before — no gap, no "undefined". Expired and Validated are untouched. `address_refused` renders
  "this address can't be used for validation" and is never named as an internal-address rule, per
  design-18. Verified by inspection plus a green host `npm run build` and a clean `vue-tsc --noEmit`
  — no JS test framework exists (the standing note in `docs/standards/review.md`); the data path
  behind both branches is covered server-side by T19's tests. **Amended 2026-09-01** — all five
  captions and the rate-limited line were shortened at the Owner's direction, and design-18's
  state table was updated to match. AC34 reserves wording to the Designer and freezes only the
  obligation, so no PRD amendment was needed; every fact each criterion requires survives, and
  `{sent_at}` is no longer interpolated into any caption, and Validated carries no caption at all —
  it asks nothing of anybody, so AC34 has nothing to require of it, and its row is now a single
  line. `destinationValidationCaption` returns `string | null` accordingly and both consumers
  (`DestinationsCard.vue`, `DestinationRows.vue`) skip the element when it is null. Verified live
  with Playwright across all four states — see `docs/fixes/destinations-table-validation-column-width.md`.
