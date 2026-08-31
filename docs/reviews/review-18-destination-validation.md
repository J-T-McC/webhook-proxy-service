# Review: Destination validation (#18)

- **Reviewer / date:** Reviewer agent, 2026-08-31
- **Scope:** branch `docs/prd-18-destination-validation`, implementation commits `712a978`…`837e0de`
  (M1–M5, T1–T18). Docs commits before `712a978` were consumed as inputs, not reviewed as changes.
- **Inputs:** PRD-18 §Acceptance Criteria (45 ACs, as amended for AC23);
  plan-18; ADR-027 (Accepted); ADR-028 (Accepted); task index binding constraints 1–6 and all
  per-task completion notes; design-18 (reference only — Designer gate dropped by Owner ruling,
  used as screen inventory); `docs/standards/review.md`, `coding.md`, `testing.md`.
- **Gates (run by the Reviewer, not taken from notes):**

| Gate | Result |
|---|---|
| `./vendor/bin/sail test --parallel` | passed — 1146 tests, 5221 assertions |
| `composer lint` (Pint) | passed |
| `composer types:check` (PHPStan L7) | passed, 0 errors |
| `pnpm types:check` (vue-tsc) | passed |
| `pnpm run build` (vite) | passed |

## Summary

The architecture is faithful to the plan and the security core is genuinely strong: the four
enforcement points are exactly where plan-18 put them, all six binding task-index constraints hold,
the nonce never reaches any member surface (never even selected from the database), the challenge
carries no credential, the address guard refuses the right ranges and pins the connection, and the
signed-URL + nonce single-use mechanics are correct and well tested. The deliberate simplifications
recorded in completion notes are recorded honestly.

The feature nonetheless does not meet its PRD at three points, each on the primary member/approver
path rather than an edge: a non-2xx response fails the send (AC18 says the opposite, and the
consequence is that the delivered link can never approve), the replay refusal is invisible in the
dialog that issues it (AC9 forbids exactly that silence), and the confirmation page names no team
(AC27 requires it; it is the page's one piece of identifying disclosure). Three Majors follow
behind them.

**Recommendation: Request changes.** Findings 1–5 return to the Senior Developer; finding 6 is a
plan-coverage defect for the Principal Engineer / Product Manager, not implementer drift.

## Acceptance-criteria coverage

Verified met (evidence in code/tests read during this review):
AC1–AC5, AC7, AC8, AC10–AC13, AC15, AC16, AC19–AC26, AC28–AC30, AC32–AC34, AC36–AC45.
Selected load-bearing checks:

- **AC24** — `validation_nonce` never selected in `ProxySecurityResource` (explicit column list),
  absent from `DestinationResource`, never logged (`destination_id` only), never in a committed
  fixture; asserted by `test_the_validation_nonce_never_reaches_the_page` and repo-wide sweep.
- **AC17 (credential clause)** — no credential header on the challenge; asserted by
  `test_the_challenge_never_carries_the_destinations_stored_credential`. Never routes through
  `DeliverToDestination`; creates no `deliveries`/`delivery_attempts` rows (asserted).
- **AC20/ADR-027 d3** — guard refuses loopback/private/link-local/unique-local/CGN/metadata,
  IPv4 and IPv6 including IPv4-mapped unwrap; refuses if *any* resolved address is refused; fails
  closed on unresolvable/malformed; caller pins via `CURLOPT_RESOLVE` to the checked address.
- **AC22/AC25/AC28** — signature = unguessable, nonce = single-use (replayed POST with still-valid
  signature lands on `already_approved` — tested), 7-day expiry from config; GET never mutates
  (tested); missing destination and wrong nonce report identically (no id enumeration).
- **AC21** — three limits, correct figures, `RateLimiter` facade (binding constraint 6); blocked
  state names the limit and its clear time and replaces the button (Flow D), tested at all three.
- **AC30** — backfill covers soft-deleted rows (deliberate, documented in the migration); new
  destinations default `unvalidated` (tested against the model default, not the factory).
- **Binding constraints 1–6** — all verified intact; pause guard and
  `AdvanceProxyFifoQueue` untouched.

Not met: **AC18** (finding 1), **AC9** in the UI (finding 2), **AC27** (finding 3),
**AC6/AC14** server-side (finding 4), **AC17 method clause** (finding 5), **AC35** (finding 6).
Partial: **AC31** (finding 8). **AC2** is defined in terms of AC18 and is impacted by finding 1.

## Findings

### 1. Blocker — a non-2xx response is treated as a send failure, inverting AC18

- **Where:** `app/Actions/SendDestinationValidationChallenge.php:105`
  (`if ($response->redirect() || ! $response->successful())`); behaviour is asserted as intended by
  `tests/Feature/Destinations/SendDestinationValidationChallengeTest.php:126`
  (`test_a_failed_send_does_not_leave_the_destination_pending_against_a_link_nobody_received`,
  faking a 500).
- **Criterion:** PRD-18 AC18 — "A send succeeds when the destination returns any HTTP response. A
  non-2xx response is not a send failure: the request reached the host, so a human there can still
  find the link." Only connection-level failures, and AC19/AC20 refusals, are send failures.
  design-18 Screen 2 depends on the same reading ("destination responded {http_status}" on a
  Pending row), and its failure-reason list contains no non-2xx case.
- **Consequence:** a webhook receiver that rejects an unrecognised payload with 4xx/5xx — the
  signature-verifying receiver, the very endpoint this feature exists for — never reaches Pending.
  Worse, the link *was* delivered in the request body, but the nonce is only persisted after a 2xx,
  so that delivered link is permanently `invalid`: the recipient who finds it and opens it is told
  the link cannot be used, while the member is told the challenge "could not be sent". The primary
  approval flow is broken for that whole class of destinations.
- **Note on the test:** the test name's rationale ("a link nobody received") is true for a
  connection failure and false for an HTTP response — the request was received. If the Senior
  Developer believes a non-2xx should fail the send (e.g. the receiving host may not have persisted
  the body), that is a requirement disagreement and goes to the Product Manager, not into code.

### 2. Blocker — the replay refusal is silent in the dialog that issues it (AC9)

- **Where:** server refusal is correct — `app/Http/Controllers/ProxyEventReplayController.php`
  (`ValidationException::withMessages(['destinations' => …])`, whole selection refused, URLs
  named). But `resources/js/components/ReplayDialog.vue` renders only the `event` error key
  (`requestError`, line 62; `AlertError` at line 199). A `destinations`-keyed error is swallowed:
  the member clicks Replay against a selection containing a non-Validated destination, the request
  422s, the spinner stops, and nothing at all is shown.
- **Criterion:** PRD-18 AC9 — replay to a non-Validated destination "is **unavailable with the
  reason given** … it is not queued and it does not silently do nothing." The shipped UI does
  silently nothing. design-18 (Scope boundaries) additionally fixes "that the reason shown names
  validation, not a generic failure."
- **Fix shape:** surface `form.errors.destinations` in the dialog (the `AlertError` beside the
  existing `event` rendering is the obvious seam). T7's tests asserted the response only; add the
  prop-level assertion the house pattern uses, and the Vue side is verified by inspection per
  review.md.

### 3. Blocker — the confirmation page discloses no team name (AC27)

- **Where:** `app/Http/Controllers/DestinationValidationController.php` — `outcomeFor()` returns
  only `outcome` / `destinationUrl` / `approveUrl`; `resources/js/pages/destinations/Validate.vue`
  renders "A webhook proxy has been configured…" with no team anywhere, in any outcome.
- **Criterion:** PRD-18 AC27 — the page discloses "the destination URL being approved, **the name
  of the team asking**, and what approving causes." AC17 explicitly carves the team name out of its
  no-team-data rule *for this page* ("no team data beyond the team name shown on the confirmation
  page"). design-18 Screen 4 places {TeamName} in the description, the Team `dt/dd`, and the
  consequence sentence. The approver is deciding whether to accept traffic from a stranger; the
  team name is the one identifying fact the PRD gives them.
- **Also weakened:** AC17's "the minimum needed to identify what is asking" — the challenge body
  identifies the product but not the asker; the page was where that identification was owed.

### 4. Major — no server-side state gate on the member Validate action (AC6, AC14)

- **Where:** `app/Http/Controllers/DestinationValidationSendController.php` `store()` — checks
  authorization and rate limits, never the destination's state.
  `SendDestinationValidationChallenge::handle()` likewise. A direct POST to
  `proxies/{proxy}/destinations/{destination}/validate` for a **Validated** destination sends a
  challenge and, on success, force-fills the destination back to Pending with `validated_at = null`
  (`SendDestinationValidationChallenge.php:114–120`).
- **Criterion:** PRD-18 AC6 — "No other transition exists. No manual un-validation…"; AC14 makes
  the action available only "whenever the destination is not Validated". The UI hides the button on
  Validated rows (`showsValidateAction`), but the interface is not the enforcement surface — the
  PRD makes that argument itself at AC8.
- **Severity reasoning:** graded Major rather than Blocker because the failure direction is
  conservative — it can only move a destination *away* from Validated, never toward it; it requires
  a hand-crafted request by a member who already holds update permission and could stop traffic
  more easily by deleting the destination; and no test asserts the wrong behaviour. Still an
  explicit state-machine AC breached server-side. Fix is one guard clause (refuse or no-op when
  `validation_state === Validated`) plus a test.

### 5. Major — the challenge ignores the destination's configured HTTP method (AC17)

- **Where:** `app/Actions/SendDestinationValidationChallenge.php:93` — `->post(...)`
  unconditionally. `HttpMethod` has two cases, `Post` and `Put`.
- **Criterion:** PRD-18 AC17 — "It is sent to the destination's URL **using the destination's
  configured HTTP method**."
- **Consequence:** a PUT-configured endpoint that accepts only PUT answers the POST challenge with
  405 — and under finding 1 that fails the send, making such destinations unvalidatable. Even with
  finding 1 fixed, the AC's sentence is explicit and the fix is one line
  (`->send($destination->http_method->value, …)`).

### 6. Major — AC35 is implemented by nothing and traced by no task

- **Where:** no send-outcome columns exist on `destinations`; the Pending caption omits design-18's
  `{http_status}`; Unvalidated has no "last send failed — {reason}" variant; a failed manual send
  produces only a generic transient toast. Recorded openly in the T15 and T16 completion notes
  ("AC35 is traced by no task in this plan").
- **Criterion:** PRD-18 AC35 — "The outcome of the most recent validation send is visible —
  delivered, with the response status the destination returned, or failed, with the reason… These
  have different remedies." The rationale bites harder combined with finding 1: today a member
  cannot distinguish "the challenge never arrived" from "it arrived and was rejected" from "nobody
  has opened it".
- **Routing:** this is a **plan/task-plan coverage defect**, not implementer drift — the Senior
  Developer implemented the certified plan faithfully and recorded the gap. It goes to the
  **Principal Engineer** to add the missing tasks (store last-send outcome, thread it through the
  T15 `validation` object — the completion notes already sketch the upgrade path), or to the
  **Product Manager / Project Owner** to amend PRD-18 and defer AC35 explicitly. Until one of those
  happens the AC is unmet at this gate.

### 7. Minor — the pinning fail-closed invariant from plan-18 §Risks is unimplemented

- **Where:** `SendDestinationValidationChallenge::pinnedTo()` hands `CURLOPT_RESOLVE` to the
  client's Guzzle option map with no verification that the transport is cURL; `composer.json` does
  not require `ext-curl`.
- **Criterion:** plan-18 §Risks — "The guard must fail closed if the handler cannot pin, rather
  than sending unpinned." With a non-cURL handler (curl extension absent → Guzzle stream fallback,
  or a future custom handler) the `curl` options are silently ignored and the send goes out
  unpinned, reopening the DNS-rebinding gap the guard exists to close. Theoretical in the current
  deployment (sail images ship curl), hence Minor. Cheapest honest fix: add `"ext-curl": "*"` to
  composer.json `require` and a comment tying it to the pin; a runtime handler assertion is the
  fuller version.

### 8. Minor — ReplayDialog presents destinations with no validation state (AC31 partial)

- **Where:** `resources/js/components/ReplayDialog.vue` lists destination checkboxes with URL and
  method but no validation badge/caption, so a member discovers a non-Validated destination only by
  submitting and being refused (and today, per finding 2, not even then).
- **Criterion:** PRD-18 AC31 — "Validation state is shown wherever a destination is presented."
  design-18 scoped its screens to 1–4 and left replay mechanics to the Principal Engineer, so this
  is graded Minor as a sanctioned narrowing — but once finding 2 is fixed, the refusal message is
  the minimum; a badge in the dialog is the follow-up that makes the refusal rare.

### 9. Minor — a lost retry job for a now-unvalidated destination is held, not resolved (AC10 edge)

- **Where:** `app/Actions/SweepDueRetries.php` `overdueQuery()` excludes non-validated
  destinations. The ordinary retry path is correct — the delayed `RetryDelivery` job fires,
  `DeliverToDestination`'s gate resolves the row as terminal `Skipped`, FIFO settles. But the
  sweep exists precisely for the *lost* delayed job, and for that case an excluded `Retrying` row
  now sits non-terminal indefinitely: never resolved, shown as "Retrying" forever, and — if the
  destination is later re-validated — swept up and delivered then.
- **Criterion:** PRD-18 AC10 — work for a non-Validated destination "is resolved without
  dispatching", not held. The exclusion mirrors pause, but pause is deliberately hold-semantics and
  validation is skip-semantics. Fix shape: let the sweep pick the row up and let the worker gate
  settle it as `Skipped` (i.e. drop the exclusion), or settle it in the sweep directly. Minor
  because it requires a dropped delayed job to reach.

### 10. Minor — T14's exception-handler exclusion was not implemented

- **Where:** T14's file list includes `bootstrap/app.php` / exception-handler configuration for
  excluding the token from exception context; no such change exists on the branch and the
  completion note does not claim one.
- **Criterion:** task T14 deliverable (AC23 as amended). No live leak found: default Laravel
  exception reporting logs neither the request URL nor request context, and the send/refusal log
  lines carry `destination_id` only — so AC23 itself holds today. Either implement the exclusion or
  amend the T14 note to record the default-handler reasoning, so the next reader does not assume
  protection that is not configured.

## Standards check

- Authorization: permission-based via `ProxyPolicy::update` (`TeamPermission` + ownership rule) —
  conforms; no role literals. Public routes' only gate is `signed`, which is the design.
- Enums for persisted vocabulary, actions per ADR-007, config over literals, fail-safe positive
  gate (`where validation_state = validated`, never a negation) — conforms.
- Testing standard: behaviour-level tests, no migration mechanics, `createQuietly` per house
  pattern; the M2 factory-default decision (factory validated, column unvalidated) is sound and its
  reasoning recorded. One test encodes a wrong requirement (finding 1).
- Mass-assignment posture is exemplary: `validation_*` deliberately non-fillable, documented on the
  model, written only via `forceFill` at the two sanctioned sites — this is what makes AC3 hold.
- Vue behaviour verified by inspection per review.md (no JS test framework). A live browser pass
  (badges, warning `aria-describedby`, tooltipless captions at 360px) is deferred to re-review,
  since rework is required regardless.

## Recommendation

**Request changes.** Findings 1–5 (and 7–10 as the Senior Developer sees fit) return to the Senior
Developer on this branch. Finding 6 goes to the Principal Engineer / Product Manager for a coverage
ruling before re-review. Findings 1 and 3 each have an encoded-in-tests / copy dimension — if the
Senior Developer reads the requirement differently, the disagreement goes to the Product Manager as
PRD interpreter, not into the diff.

- **Project Owner decision / date:** _pending_

## Handoff

- **Inputs:** listed above.
- **Outputs:** this review.
- **Next agent:** Senior Developer (findings 1–5); Principal Engineer / Product Manager (finding 6).
- **Re-review:** required after rework — will re-run all gates, re-verify each finding against its
  reproduction, and perform the deferred live browser pass.
