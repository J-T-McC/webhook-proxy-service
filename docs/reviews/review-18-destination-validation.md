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

---

# Re-review: Destination validation (#18)

- **Reviewer / date:** Reviewer agent, 2026-08-31
- **Reviewed range:** `9fd6b27`…`HEAD` (`3daf55e`) on `docs/prd-18-destination-validation`. The
  rework since the first review is `315b7f4` (findings 1–5 and 7–10), `af890d4` (plan and task-plan
  amendment for finding 6), `60d7c98` (T19 and T20) and `0cbc96c` / `3daf55e` (documentation
  upkeep). Nothing is pushed and there is no pull request.
- **Review tier: deep.** The task index predates the Review tiers section of
  `docs/standards/review.md` and names no tier, so the Reviewer set it. Deep is the right tier on
  three independent grounds: plan-18 rests on ADR-027, which is exactly the "plan contains a major
  decision" trigger the standard names; the feature's entire subject matter is security posture
  (an outbound request to an unvouched-for URL, a DNS-rebinding pin, an unauthenticated public
  approval surface and a nonce that must never reach a member surface); and T19 adds new fields to
  `ProxySecurityResource`, the one serializer in this feature whose column list is load-bearing for
  AC24. Deep tier obligations were discharged — every changed public interface's callers were
  traced, and the send action, the address guard and pin, the public controller and the nonce
  non-disclosure path were read line by line.
- **Inputs:** this document's original findings; `docs/tasks/destination-validation/index.md` and
  `m05-member-ui.md` (T19, T20); plan-18 § Data Model and § Enforcement; PRD-18 acceptance criteria
  AC5, AC6, AC9, AC10, AC14, AC17–AC20, AC27, AC31, AC34, AC35; design-18 Screen 2 state table and
  Screen 4; `docs/standards/review.md`. Milestones m01–m04 were not re-read; they are unchanged.

## Gates (re-run by the Reviewer, not taken from notes)

| Gate | Result |
|---|---|
| `./vendor/bin/sail test --parallel` | passed — 1160 tests, 1160 passed, 5318 assertions |
| `composer lint` (Pint) | passed |
| `composer types:check` (PHPStan level 7) | passed, 0 errors |
| `npm run build` (vite, on the host) | passed — built in 2.18s |
| `npx vue-tsc --noEmit -p tsconfig.json` (on the host) | passed, exit 0 |
| `npx prettier --check resources/` | **failed — 2 files** (new finding 11) |

The first five match the last recorded state. The sixth was not run in the first review pass and is
a standing toolchain gate in `docs/standards/review.md`; it is reported as a new finding below.

## Verdicts on the original ten findings

**1. Blocker — non-2xx treated as a send failure (AC18): closed.**
`SendDestinationValidationChallenge.php:130` now branches on `$response->redirect()` alone. Any
other HTTP response — 2xx or not — falls through to the success `forceFill`, which persists the
nonce, moves the destination to Pending and records the returned status. The test that encoded the
wrong requirement is gone: `test_a_failed_send_does_not_leave_the_destination_pending_against_a_link_nobody_received`
was replaced by `test_a_non_2xx_response_is_a_successful_send` (fakes a 500, asserts Pending and a
non-null nonce), and its original rationale was moved to a new
`test_a_connection_failure_does_not_leave_the_destination_pending_against_a_link_nobody_received`
that throws a `ConnectionException` — the one case where "a link nobody received" is true. The
signature-verifying receiver that 4xxes an unfamiliar payload now reaches Pending with a usable
link, which was the whole point of the finding.

**2. Blocker — the replay refusal was silent in the dialog (AC9): closed.**
`ReplayDialog.vue` replaced the single `requestError` computed with `requestErrors`, which reads
both `errors.event` and `errors.destinations` from the untyped bag and filters empties; the existing
`AlertError` renders the array. The server side was already correct and is unchanged. A new test,
`test_the_unvalidated_refusal_reaches_the_session_error_bag_the_dialog_reads`, drives the ordinary
non-JSON Inertia round trip with a `from()` referrer and asserts `assertSessionHasErrors('destinations')`
— which is the prop-level assertion the finding asked for, at the layer that actually feeds
`form.errors`.

**3. Blocker — the confirmation page disclosed no team name (AC27): closed.**
`DestinationValidationController` gained a `teamName()` helper resolving the name by team id (the
visitor has no team for a scoped relation to resolve through) and passes it on `approvable`,
`approved`, `already_approved` and `expired`. It is correctly withheld on `invalid`, which has no
resolved challenge to name a team from and must stay indistinguishable between a missing
destination and a wrong nonce (AC28). `Validate.vue` names the team in the description and in the
consequence sentence on every resolved outcome, matching design-18 Screen 4's framing.
`test_the_page_names_the_asking_team_on_every_resolved_outcome_but_never_on_invalid` asserts the
exact name on `approvable`, presence on `expired` and `already_approved`, and absence on `invalid`.
The `approved` (POST) outcome passes `teamName` in code but is not covered by that test; the
omission is cosmetic, not a defect.

**4. Major — no server-side state gate on the member Validate action (AC6, AC14): closed, in two
layers.** `SendDestinationValidationChallenge::handle()` now returns false and logs
`destination.validation_send_refused_validated` before doing anything when the destination is
already Validated — this is the enforcement surface, and it covers every caller including the
queued job. `DestinationValidationSendController::store()` adds the feedback layer above it, an
error toast and a redirect, so a member who somehow issues the request is told why. The layering is
stated in comments at both sites. Two tests: `test_a_validated_destination_is_refused_a_send`
(action level, asserts `Http::assertNothingSent()` and that the state is still Validated) and
`test_a_validated_destination_is_refused_and_stays_validated` (controller level, asserts
`validated_at` survives). The hand-crafted POST can no longer force-fill a Validated destination
back to Pending.

Caller trace (deep tier): the new guard has two call paths.
`DestinationValidationSendController::store()` is the member action. `ProxyController::challengeDestinations()`
is the automatic send after a URL change — and it is unaffected, because the URL-change `forceFill`
sets `validation_state` to Unvalidated and saves inside the transaction, while
`challengeDestinations()` runs after the transaction commits and re-resolves the destination from
the database by id. The guard therefore never blocks the AC5 automatic send, which would have been
the way this fix could regress.

**5. Major — the challenge ignored the destination's configured HTTP method (AC17): closed.**
`SendDestinationValidationChallenge.php:110` is now
`->send($destination->http_method->value, $destination->url, ['json' => …])`.
`test_the_challenge_uses_the_destinations_configured_http_method` configures a PUT destination and
asserts `$request->method() === 'PUT'`.

**6. Major — AC35 implemented by nothing and traced by no task: closed, and closed the way the
Owner ruled.** The finding was routed for a coverage ruling and the Project Owner ruled on
2026-08-31 to implement rather than defer, approving the two nullable columns. plan-18 § Data Model
now lists seven columns (and corrects its own previously wrong count), explains why the outcome is
two columns rather than a fifth state, and records that a send refused before it is attempted
touches neither column. The task index records the reopening of M5 for T19 and T20 only, with
T15–T18 standing as shipped. Both tasks are implemented and are reviewed as new work below. The AC
is now met: a member can distinguish "never arrived" (a failure key), "arrived and was rejected" (a
non-2xx status on a Pending row) and "nobody has opened it" (a Pending row with a 2xx status).

On the no-ADR call: **it was right.** The ADR bar in CLAUDE.md and in the architecture standard is
decisions that are expensive to reverse. Two nullable columns with no default, no index, no
constraint and no read path in any gate or query are about as cheap to reverse as a schema change
gets — the migration's own `down()` is honest that dropping them loses recorded outcomes and
nothing else, and no query, gate or state derivation reads either column. The reasoning that would
have justified an ADR — a new state in the state machine — is exactly the reasoning plan-18
explicitly rejects. The Owner approval is recorded in three places (the plan, the task index and the
migration docblock), which is what an ADR would have bought here anyway.

**7. Minor — the pinning fail-closed invariant was unimplemented: closed.** `composer.json` now
requires `"ext-curl": "*"`, and `pinnedTo()`'s docblock ties the requirement to the pin explicitly
("Removing that requirement reopens the DNS-rebinding gap this method exists to close"). This is
the cheaper of the two fixes the finding offered rather than the runtime handler assertion, and it
is sufficient: with the extension a hard Composer requirement, Guzzle cannot fall back to the
stream handler for want of cURL, so the silent-unpinned path is unreachable rather than merely
unlikely. A future custom handler would still bypass it, but that is now a change someone has to
make deliberately against a comment that warns them.

**8. Minor — ReplayDialog presented destinations with no validation state (AC31): closed.**
`DestinationResource` gained a `validation_status` display field (derived through the model's
existing `validationStatus()`, so Expired is computed rather than stored), `ProxyDestination` in
`types/proxies.ts` gained the matching typed field, and `ReplayDialog.vue` renders the shared
validation badge on any row that is not Validated — deliberately quiet on the normal case. The data
path holds: `ProxyEventController` loads the relation with `loadMissing('destinations')`, so every
column `validationStatus()` reads is present. See new finding 13 on its test coverage.

**9. Minor — a lost retry for a now-unvalidated destination was held rather than resolved (AC10):
closed, by the first of the two fix shapes offered.** `SweepDueRetries::overdueQuery()` no longer
excludes non-validated destinations; the sweep picks the row up like any other and
`DeliverToDestination`'s dispatch-gate settles it as terminal `Skipped`, which also settles the FIFO
line. The `paused_at` exclusion in `handle()` is untouched, so item #15's hold semantics are intact
and the two are no longer conflated. Both tests were rewritten to run the work rather than fake the
queue: they now assert `Http::assertNothingSent()` **and** that the delivery refreshes to `Skipped`,
which is a stronger assertion than the `Queue::assertNothingPushed()` they replaced. The resume path
(`forProxy()`) shares the query and is covered by its own test. See new finding 12 on the
documentation that this fix left behind.

**10. Minor — T14's exception-handler exclusion was not implemented: closed, by the second option
offered.** The finding allowed either implementing the exclusion or amending the note to record the
default-handler reasoning. The T14 note now does the latter, explicitly and in a way that survives
being read literally: it states that the exclusion deliberately stays unimplemented, gives the
reasoning (Laravel's default handler reports class, message and trace but not the request URL or
query string, and this application registers no reporting callback, context processor or third-party
error tracker), and assigns the obligation forward to whoever first adds an error tracker or a
`context()`/`report()` customisation. That is the outcome the finding asked for — no reader will now
assume protection that is not configured.

## T19 and T20 — reviewed as new work

These two tasks have had no prior review pass. Both are accepted.

**T19 — record the outcome of the last validation send.** The migration adds
`validation_last_send_status` (nullable unsigned small integer) and `validation_last_send_failure`
(nullable string) after `validation_nonce`, with a docblock that explains why the pair exists and
why it is not a fifth state. The new `App\Enums\DestinationValidationSendFailure` follows
`DestinationValidationState`'s shape exactly — string-backed, three cases, each documented against
the AC it comes from. The model declares both properties, casts the failure to the enum, and leaves
both columns out of the `#[Fillable]` list, which preserves the mass-assignment posture the first
review called exemplary: no request payload can reach them, and every write goes through
`forceFill` at a sanctioned site.

The single-outcome invariant holds in the code, not merely in the comments. The success path writes
`$response->status()` and nulls the failure in the same `forceFill` it already performed, so a
successful send costs no extra database write. `recordFailure()` writes the key and nulls the
status. There is no path that writes one without clearing the other. The two early returns —
already-Validated and rate-limited — sit above `recordAttempt()` and touch neither column, which is
correct: nothing was sent, so the previous outcome is still the most recent one, and overwriting it
would tell the member the opposite of what happened. Both are asserted by tests that seed a prior
outcome and show it still standing afterwards.

The three failure exits map one-to-one onto the three enum cases with nothing left over, and the
redirect case deliberately stores `redirected` rather than the 302, which is right: a redirect is a
failed send under AC19 and the member's remedy is the address, not the status code. Reason keys are
stored rather than prose, and `test_the_stored_failure_is_a_key_and_never_the_underlying_error_text`
asserts against `getRawOriginal` that no cURL error text reaches the column — a good test, because
it checks the stored value rather than the cast one. `ProxyController`'s existing URL-change
`forceFill` clears both columns alongside the nonce and timestamps, extending the one reset path
rather than adding a second, and the dispatch test asserts it. `ProxySecurityResource` adds the two
columns to its explicit `get()` column list and exposes them as `last_send_status` and
`last_send_failure` inside the existing `validation` object, the failure as `?->value` so the wire
carries the key. **`validation_nonce` is still absent from that column list** — the AC24 discipline
that makes leaking it impossible rather than merely avoided survives the change, which was the main
thing to check here. Seven new tests cover one case per exit plus the two non-attempt paths, and
`ProxySecurityResourceTest` covers answered, failed and never-sent rows on the page.

**T20 — show the outcome.** `DestinationValidation` gains the two fields mirroring the resource
exactly, plus an exported `DestinationValidationSendFailure` value union whose docblock names the
PHP enum as authoritative. `SEND_FAILURE_REASONS` holds design-18's three phrases; I checked all
three against design-18 lines 328–333 and they are verbatim, including the deliberate refusal to
name `address_refused` as an internal-address rule. The Pending and Unvalidated captions match
design-18's Screen 2 state table (lines 321–322) word for word. The badge on a failed-send row is
untouched, which is also what the state table specifies — the row is still Unvalidated and only the
caption differs.

Both fallbacks are correct and are the part of this task most likely to have gone wrong. A row with
no recorded failure renders today's "No validation challenge has been sent yet." unchanged; a
Pending row with no recorded status omits the ", destination responded {status}" clause entirely
rather than rendering a gap or an "undefined". Every row backfilled by T3 and every row whose only
send predates T19 therefore reads exactly as it did before. Expired and Validated captions are not
touched. The conditional on the status uses `!== null`, not a truthiness test, which matters: a
status of 0 is not reachable here, but the strict comparison is the right habit and vue-tsc agrees.

## New findings

### 11. Minor — `prettier --check resources/` fails on two files, both from this branch

- **Where:** `resources/js/data/destinationValidationStates.ts` (introduced by T20 in `60d7c98`:
  the `DestinationValidationSendFailure` union is written on one line where Prettier wants three,
  and the `redirected` entry of `SEND_FAILURE_REASONS` exceeds the print width) and
  `resources/js/data/proxyDeliveryStates.ts` (introduced earlier on this branch by `5a9fc8e`: the
  `skipped` option object is written on one line). Both are on the branch —
  `git log 9fd6b27..HEAD -- resources/js/data/proxyDeliveryStates.ts` returns the one commit that
  broke it — so neither is pre-existing noise.
- **Criterion:** `docs/standards/review.md` → Toolchain / CI gates, "Prettier `--check` (with
  tailwind plugin) passes over `resources/`", from `docs/stack/stack.md` → Formatting. The
  repository provides the gate as `pnpm format:check`.
- **Consequence:** `composer ci:check`'s frontend formatting step will fail on the pull request.
  Nothing about the shipped behaviour is affected.
- **Reviewer's own miss:** this gate was not in the first review's table. It should have been, and
  the `proxyDeliveryStates.ts` breakage would have been caught a pass earlier. Recorded here rather
  than quietly, because the Senior Developer is now being asked to fix something from a milestone
  that was signed off.
- **Fix shape:** `pnpm format` (or `npx prettier --write resources/`) and commit. No judgement call
  involved.

### 12. Minor — plan-18 and the task index still describe the retry-sweep gate that finding 9 removed

- **Where:** `docs/plans/plan-18-destination-validation.md` § Enforcement, point 3: "**`SweepDueRetries`.**
  Re-dispatches from existing rows and never passes through point 1, so it must exclude
  non-validated destinations, mirroring its existing `paused_at` exclusion at line 49." Also
  § Verification, "The four gate points are the acceptance surface: a test per point", and
  `docs/tasks/destination-validation/index.md` binding constraint 1, "**The gate is four points, not
  two.** Delivery-row creation, the worker, the retry sweep and the replay controller."
- **Criterion:** CLAUDE.md — "State a ruling once, in the artifact that made it"; documentation.md →
  Document lifecycle. plan-18 was amended for finding 6 in the same pass but not for finding 9, so
  two governing documents now assert an enforcement point that the code deliberately no longer has.
- **The code is right, the documents are stale.** Removing the exclusion was one of the two fix
  shapes the original finding 9 offered, and it is the better one: the sweep never reaches the
  network, the worker's dispatch-gate is the actual refusal, and holding the row was the defect.
  The Senior Developer recorded the reversal in the T6 rework note, which is the right place for the
  implementation record but not the right place to overturn a binding constraint the Principal
  Engineer wrote.
- **Routing:** **Principal Engineer**, not the Senior Developer. plan-18 § Enforcement point 3
  should say that the sweep deliberately carries no validation filter and why, § Verification should
  say three enforcement points plus a settle-on-sweep proof, and binding constraint 1 should be
  restated to match. A reader who checks the code against plan-18 today finds a contradiction with
  no explanation, which is exactly the failure mode the "read it literally" carve-out in CLAUDE.md
  exists to prevent.

### 13. Minor — `DestinationResource.validation_status` has no test asserting it reaches the page

- **Where:** `app/Http/Resources/DestinationResource.php` — the new `validation_status` field.
  `grep -rn "validation_status" tests/` returns nothing. Its only consumer,
  `ReplayDialog.vue`'s badge, is Vue and has no JS test framework.
- **Criterion:** `docs/standards/review.md` → Review scope, "Every task and PRD acceptance criterion
  is verified against the running code" (AC31). Every other serialized validation field in this
  feature is asserted on the page — `ProxySecurityResourceTest` does it three times, including for
  T19's two new fields in the same commit range — so this is a gap against the feature's own
  established pattern, not against an abstract ideal.
- **Consequence:** finding 8's closure is the one part of this rework verified by neither a test nor
  a browser pass. The field is read through `validationStatus()`, which throws on a null
  `validation_state`, and it is emitted for every destination on the events pages; a regression here
  would be a 500 on the events index, not a cosmetic miss.
- **Fix shape:** one assertion in the events-page controller test that a destination's
  `proxy.destinations` entry carries the expected `validation_status`, ideally including the derived
  `expired` case, which is the value with logic behind it.

## The live browser pass — still outstanding

The first review deferred a live browser pass to re-review. It has **not** been performed and could
not be: this Reviewer session has no Playwright, browser or screenshot tool available. It remains
outstanding and is the one part of this feature's UI that has been verified only by reading code.
Recording what it would need to cover, so whoever runs it does not have to re-derive the list:

- **The two new T20 captions on the proxy Show page.** A Pending destination that answered a non-2xx
  reads "Sent {date}, destination responded 404 — waiting on someone at this address to approve it.
  Expires {date}."; an Unvalidated destination whose last send failed reads "Last attempt failed to
  send — {reason}. Nothing has been asked of this destination yet." for each of the three reasons.
- **Both T20 fallbacks, which is the more important half.** A destination backfilled by T3 and a
  Pending destination with no recorded status must render today's wording with no gap, no stray
  comma and no "undefined" — this is where a template-interpolation mistake would show and where
  reading the code is least conclusive.
- **The ReplayDialog badge (finding 8).** The badge appears on non-Validated rows, is absent on
  Validated ones, and does not disturb the checkbox row's layout or its label association.
- **The ReplayDialog refusal (finding 2).** Select a non-Validated destination, submit, and confirm
  the `destinations` error actually renders in the `AlertError` — the server-side assertion proves
  the key is in the bag, not that the member sees it, which was the substance of the finding.
- **The confirmation page (finding 3)** at `destinations/Validate`, on all four resolved outcomes:
  the team name renders in the description and the consequence sentence, and nothing renders in its
  place on `invalid`.
- **The a11y and responsive items carried over from the first review:** badge and caption legibility
  at 360px with no tooltip dependency, the URL-change warning's `aria-describedby` association, and
  keyboard operation of the Validate button and its rate-limited replacement.

## Recommendation

**Approve with follow-ups.** All six blocking findings from the first review — three Blockers and
three Majors — are closed, and closed at the layer each finding named rather than worked around.
The four Minors are closed too, two of them by the alternative fix shape the finding offered, which
is a legitimate closure. T19 and T20 are good work: the single-outcome invariant is enforced by the
code rather than asserted by comments, the AC24 nonce discipline survives a change to the very
resource that had to be edited to carry the new fields, and both T20 fallbacks are handled so that
no existing row's caption changes. All five previously-run gates are green, on 14 more tests and 97
more assertions than the first pass.

Three Minors remain and none of them blocks: a Prettier failure that is a one-command fix (finding
11, Senior Developer), a plan and task-index contradiction left behind by finding 9's fix (finding
12, **Principal Engineer**), and a missing test on one new resource field (finding 13, Senior
Developer). Finding 11 will fail CI on the pull request and should be fixed before it is opened;
findings 12 and 13 can follow.

The Project Owner should note that the live browser pass is still outstanding and take that into
account in the release decision — it is the only verification this feature was promised and has not
received.

- **Project Owner decision / date:** _pending_

## Handoff

- **Inputs:** listed at the top of this section.
- **Outputs:** this re-review section.
- **Next agent:** Project Owner, for the release decision. Findings 11 and 13 to the Senior
  Developer; finding 12 to the Principal Engineer.
- **Re-review:** not required. The three open findings are Minor and independently verifiable —
  finding 11 by re-running `pnpm format:check`, finding 13 by the test itself, finding 12 by
  reading the amended plan.
