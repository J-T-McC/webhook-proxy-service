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
  Validation column between Destination and Delivered %. Deliberate simplifications: the Pending
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

## T17 — The URL-change warning

- **Description:** Tell the member **before** they save that changing the URL returns the destination
  to unvalidated and stops delivery until it is approved again — not after.
- **Dependencies:** T11.
- **Files:** `resources/js/components/DestinationRows.vue`, `resources/js/pages/proxies/ProxyForm.vue`
- **AC-trace:** PRD-18 AC5, AC34; design-18 Screen 1 and Flow B.
- **Verify step:** edit the URL of a validated destination; the warning appears before submission.
- **Testing:** component test — the warning shows on a URL edit of a validated destination and does
  not show for a new destination or a non-URL edit.

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
