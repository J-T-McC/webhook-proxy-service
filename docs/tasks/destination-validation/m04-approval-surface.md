# M4 — The approval surface

Implements plan-18 § API. Two public unauthenticated routes plus the member-facing Validate action.
The approver has no account and arrives cold from a link.

## T12 — The signed public routes

- **Description:** `GET /destinations/validate/{destination}` renders the confirmation page and is
  **inert** — it never approves, which is what makes a link scanner or mail preview fetcher harmless.
  `POST` to the same path performs the approval. Both carry the nonce as a signed parameter and use
  the `signed` middleware. Links are minted with `URL::temporarySignedRoute` and a 7-day expiry.
  No authentication, no team scope — `BelongsToCurrentTeam` must not silently hide the destination.
- **Dependencies:** T2, T9.
- **Files:** `routes/web.php`, `app/Http/Controllers/DestinationValidationController.php`
- **AC-trace:** PRD-18 AC22, AC26, AC28; ADR-027 decision 1.
- **Verify step:** a GET with a valid signature renders and leaves state unchanged; the POST
  approves.
- **Testing:** own group — a valid GET does not mutate state (the load-bearing AC28 case); a tampered
  signature is refused; an unauthenticated request succeeds (AC26); the team scope does not hide the
  row.

- **Completion notes:** Done, 2026-08-31, built alongside T9 (a signed URL needs its route to exist).
  Both routes sit outside the `{current_team}` prefix and the controller resolves the destination
  `withoutGlobalScope(TeamScope::class)` — the approver has no team, so the scope would otherwise
  hide every destination from its own approval route. `resources/js/app.ts` gained a layout case:
  the default `AppLayout` assumes a signed-in member with team navigation, which is wrong for a page
  reached cold by a stranger.

## T13 — Approval, and the four outcomes

- **Description:** Approval requires the signature to verify, the nonce to equal the destination's
  current `validation_nonce`, and the state to be `pending` and unexpired. The four outcomes are four
  distinct screens per design-18 Screen 4: approved, already approved, expired, and invalid or
  superseded. **The nonce, not the signature, is what makes the link single-use** — a signed URL is
  replayable on its own.
- **Dependencies:** T12.
- **Files:** the T12 controller, `resources/js/pages/destinations/Validate*.vue`
- **AC-trace:** PRD-18 AC3, AC22, AC27, AC29.
- **Verify step:** approve once; a second POST with the same still-valid signature is refused as
  already approved.
- **Testing:** all four outcomes; a superseded nonce is refused while its signature is still valid
  (the single-use proof); an expired challenge is refused even with a good signature and nonce.

- **Completion notes:** Done, 2026-08-31. Four outcomes as four screens. A missing destination and a
  wrong nonce report identically, so the page cannot be used to enumerate destination ids. The POST
  carries its own signature, minted against the same challenge expiry — a signature covers one URL,
  so the GET's does not carry across. 11 tests, including the AC28 proof that a GET does not mutate
  and the single-use proof that a replayed POST with a still-valid signature lands on
  `already_approved`.

## T14 — Keep the link out of everything

- **Description:** The token appears in no `Log::` context, no delivery or analytics record, and is
  excluded from exception-handler context so a trace carrying the request URL does not export it.
  Per Q-18-02, AC23 is scoped to layers this application controls.
- **Dependencies:** T12, T13.
- **Files:** the T12 controller, `app/Actions/SendDestinationValidationChallenge.php`,
  `bootstrap/app.php` or the exception-handler configuration
- **AC-trace:** PRD-18 AC23 (as amended), AC24.
- **Verify step:** trigger a send and a failed approval with logging captured; no token appears.
- **Testing:** log assertions on both paths, and one asserting the token is absent from every
  response body and Inertia prop reaching a member (AC24 — the property the feature rests on).

- **Completion notes:** Done, 2026-08-31, scoped to what Q-18-02's Owner ruling settled: the layers
  this application controls. The token is never logged (the send logs a destination id, never the
  link), never written to a delivery or analytics record, and never handed to the client as a prop —
  covered by `test_the_page_never_receives_the_nonce_as_a_prop`. The token is in the address bar by
  necessity, which is exactly what AC23 was narrowed to accept.
