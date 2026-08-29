> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M10 — Outbound header policy corrections (ADR-025 Decision 2; ADR-026 Decision A)

Two Owner-approved decisions, from two ADRs decided on the same day. Both tasks are outbound-only;
neither touches inbound verification, which by the time either runs no longer exists (removed at M11
above). Physically placed here, after M11, per ADR-026 § *Sequencing and build order*, Steps 4 and 5.
**T51 was going to carry ADR-025 Decision 1's five-name reduction; it is superseded before being built
by ADR-026 Decision A's wider seven-name reduction — see T51's own status note and T55 below, which
replaces it.**

**Two consequences the ADRs routed to other roles — both now landed, recorded here as done rather than
outstanding:**
- **PRD-10 AC55** used to read "the same three headers" in a context describing the Standard Webhooks
  specification names on the outbound signing path. After T50, the outbound header *names* are
  `WebhookProxy-Id`/`WebhookProxy-Timestamp`/`WebhookProxy-Signature`; only the *value format* remains
  Standard-Webhooks-compatible (`v1,<base64>`). **Landed: PRD-10 `## Amendment C`, Product Manager,
  commit `3015b28`.** Amendment C withdraws AC23–AC28, AC46 and AC50–AC53 in full (the inbound-
  verification criteria ADR-026 Decision B removes), and narrows AC29, AC11, AC10, AC44, AC43, AC55,
  AC60, AC38 and AC64 rather than withdrawing them. **AC29 specifically survives, in its signing half
  only, and carries its own do-not-withdraw note** — the cap of two, the immediate discard on a second
  rotation inside an overlap, the ruling-2a before-save disclosure, the fixed 24 hours, ending an
  overlap early, and the destination-credential exclusion are all still live requirements, pinned at
  **T43** (the surviving disclosure surface) and re-certified at **T49**; only its inbound-verification
  clauses fall.
- **`design-10` carried stale outbound copy on Flow G step 5** ("carries the Standard Webhooks
  signature headers"), which goes stale after T50 regardless of the removal — the header *names* become
  `WebhookProxy-*`, and only the value format/algorithm stays Standard-Webhooks-shaped. **Landed: the
  `design-10` revision, Designer, commit `622b454`** — Screens 1 and 4 and Flows A, B and C are
  withdrawn in full, and correction B2 (the AC29 ruling-2a disclosure) is restated for its single
  surviving surface, the signing dialog (T43).

**Both parallel tracks named in ADR-026 § Impact have now landed**, so this document's task content
above (T45, T49, and every superseded/narrowed status note) is written against the *finished* PRD-10
and `design-10`, not against a pending amendment. **PRD-16 is also withdrawn** (Product Manager, commit
`a7a32e5`) — `docs/architecture/prd-16-template-model-feasibility.md`, the separate feasibility study
ADR-026 § Impact keeps for its provider-construction evidence, is unaffected by PRD-16's own
withdrawal. None of this reopens any task above; T45–T55 were already written to the post-removal
shape these commits ratify. **T49's final sweep still re-certifies against them** — not because they
might still be in flux, but because a closing regression pass should read the actual committed
documents rather than trust a summary, this one included.

## T50 — Outbound signing header rename: `webhook-*` → `WebhookProxy-*` (ADR-025 Decision 2)
> **Sequencing note (ADR-026, 2026-08-28) — read before starting this task.** This task now runs after
> **T52–T54** (ADR-026's inbound-verification removal), not immediately after T39/T40 as originally
> planned. **Scope is unchanged below — the rename itself is exactly as specified.** But this task's
> own "Explicitly not touched" list and its final inbound-regression `Testing` filter, both below, name
> `app/Verification/StandardWebhooksScheme.php`, `DeliveryUnitResolver`'s AC27 verification-header map,
> and four inbound test files/suites. **All of that is gone by the time this task runs** — deleted at
> T53 — which makes the caution moot rather than wrong (ADR-026 § *Sequencing and build order*, Step
> 4: "its argument becomes stronger" once nothing named `webhook-` remains anywhere but this one
> production file). Do not attempt to restore, exercise, or regression-test any of the named-gone
> files. The one still-applicable check from this task's own `Testing` line is the **outbound** suite
> filter — `OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SigningAllOrNoneFailureTest|OutboundSigningIntegrationTest`
> — run only that; skip the `StandardWebhooksSchemeTest|InboundVerifierTest|InboundVerificationIntegrationTest|DeliveryUnitResolverTest`
> filter entirely, since none of those files exist any more.
- **Description:** `OutboundHeaders::signingHeaders()` (T34) currently emits three header names —
  `webhook-id`, `webhook-timestamp`, `webhook-signature`. Rename all three, together, to
  `WebhookProxy-Id`, `WebhookProxy-Timestamp`, `WebhookProxy-Signature`. Nothing else about signing
  changes: the value format stays `v1,<base64>`, space-delimited, one entry per live signing secret
  (still capped at two, AC29); the signed content stays the exact dispatched bytes (`$unit->payload`);
  the `webhook-id` **value**'s own derivation, `msg_{dispatch_uuid}_{destination_id}`, is unchanged —
  only the outbound header *names* move. Scoped narrowly: `signingHeaders()`'s three return-array keys,
  its own doc comment and `OutboundHeaders`' class-level doc comment (both currently name the old
  headers), and the header-name string literals in the tests that assert them.
  **The trap this task exists to prevent, stated explicitly because ADR-025 names it as the likely
  failure mode: inbound is untouched.** `App\Verification\StandardWebhooksScheme` reads
  `webhook-id`/`webhook-timestamp`/`webhook-signature` off the **inbound** request — those are the
  names the Standard Webhooks specification defines and the names an actual sender transmits, and
  renaming them would break verification for every `standard-webhooks`-scheme proxy. Likewise
  `App\Services\DeliveryUnitResolver`'s AC27 verification-header map
  (`VerificationScheme::StandardWebhooks => ['webhook-id', 'webhook-timestamp', 'webhook-signature']`)
  stays exactly as it is — it names headers to **strip inbound-forwarded copies of**, not headers this
  proxy emits outbound. **A global find-and-replace of the string `webhook-` anywhere in this codebase
  is not this task's implementation** and must not be attempted; the correct change touches exactly one
  production file.
- **Dependencies:** T34, T35, T39, T40 (the task rewrites code and test assertions those four tasks
  already landed)
- **Files:**
  - `app/Support/OutboundHeaders.php` (production — `signingHeaders()`'s three return keys, plus the
    class-level and method-level doc comments naming the old headers)
  - `tests/Unit/Support/OutboundHeadersSigningTest.php` (T34's own suite — asserts `webhook-id`/`webhook-timestamp`/`webhook-signature`
    array keys directly against `OutboundHeaders::build()`'s return value)
  - `tests/Unit/Support/OutboundHeadersSigningRegressionTest.php` (T35 — asserts the absence of the
    old three keys on an unsigned/disabled proxy; assert the absence of the new three instead)
  - `tests/Feature/Delivery/SigningAllOrNoneFailureTest.php` (T39 — one `hasHeader('webhook-signature')`
    assertion)
  - `tests/Feature/Delivery/OutboundSigningIntegrationTest.php` (T40 — the widest set of literal
    `webhook-id`/`webhook-timestamp`/`webhook-signature` header-name assertions across AC54, AC55/AC59,
    AC58, AC60, AC61 and AC64's own test methods)
  - **Explicitly not touched, named so a later reader can confirm the diff by inspection:**
    `app/Verification/StandardWebhooksScheme.php`; `app/Services/DeliveryUnitResolver.php`'s AC27
    verification-header map; `tests/Unit/Services/DeliveryUnitResolverTest.php` (asserts
    `['webhook-id', 'webhook-timestamp', 'webhook-signature']` against `$unit->verificationHeaderNames` —
    stays passing, unmodified); `tests/Unit/Verification/StandardWebhooksSchemeTest.php`;
    `tests/Unit/Services/InboundVerifierTest.php`; `tests/Feature/Ingest/InboundVerificationIntegrationTest.php`;
    `resources/js/pages/proxies/ProxyForm.vue` (Screen 1's inbound copy); `design-10` lines 543–548
    (the inbound `standard-webhooks` header-name list a sender must send).
- **Acceptance Criteria:**
  - `OutboundHeaders::build()` on a signing-enabled proxy returns exactly `WebhookProxy-Id`,
    `WebhookProxy-Timestamp`, `WebhookProxy-Signature` as header names; no `webhook-id`/`webhook-timestamp`/`webhook-signature`
    key appears anywhere in its return value.
  - Value format and signed content are unchanged from T34/T35/T39/T40's own already-pinned behaviour:
    one `v1,<base64>` entry per live secret (capped at two, AC29), `WebhookProxy-Id` identical across
    an attempt and its retry, different on replay, different per destination of one dispatch despite a
    shared signing key (AC60); the signature still verifies via `StandardWebhooks::verify()` (T7) over
    the exact dispatched bytes (AC59).
  - `app/Verification/StandardWebhooksScheme.php` and `DeliveryUnitResolver`'s AC27 map are unchanged
    by this task's diff — confirmed by inspection, not merely by omission — and
    `DeliveryUnitResolverTest`'s existing `['webhook-id', 'webhook-timestamp', 'webhook-signature']`
    assertion against `$unit->verificationHeaderNames` still passes with zero edits.
  - A `standard-webhooks`-scheme proxy still verifies a real inbound request signed with the
    specification's own `webhook-id`/`webhook-timestamp`/`webhook-signature` headers, exactly as before
    this task — run as an explicit regression pass against the inbound suites named above, proving they
    needed no edits, not merely left alone.
  - Every header-name literal in the T34, T35, T39 and T40 suites is updated to the new names; no test
    anywhere in this feature still asserts a `webhook-*` name on the outbound path.
- **Testing:** no new test file — updates the literal string assertions in the four existing files
  named above. Run `./vendor/bin/sail test --filter "OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SigningAllOrNoneFailureTest|OutboundSigningIntegrationTest"`
  for the outbound side, plus `--filter "StandardWebhooksSchemeTest|InboundVerifierTest|InboundVerificationIntegrationTest|DeliveryUnitResolverTest"`
  as the explicit inbound-unchanged regression pass.
- **Sequencing constraint, carried here per ADR-025 § Sequencing, not only in this document's own
  report:** this task **must land before item #10's branch (`feat/item-10-sensitive-data`) merges to
  `main`**. Merging the old header names first and renaming afterward turns the rename into a breaking
  change for any member who has already configured a receiver against `webhook-id`/`webhook-timestamp`/`webhook-signature`,
  with no notification surface available to warn them (PRD-10 AC55 ships none). Placed in M10, after
  M9's closing sweep (T49) — see T49's own amended Dependencies and its new AC bullet above, which
  re-certify the finished, renamed header set as part of the feature's actual close-out.
- **Completion notes:** Renamed `OutboundHeaders::signingHeaders()`'s three return-array keys —
  `webhook-id`/`webhook-timestamp`/`webhook-signature` → `WebhookProxy-Id`/`WebhookProxy-Timestamp`/`WebhookProxy-Signature`
  — and the two doc comments naming the old headers (the method's own and the class-level one, the
  latter also updated to cite ADR-026 alongside ADR-008 for `STRIPPED_HEADERS`'s ongoing
  non-involvement). Value format, signed content, and the `msg_{dispatch_uuid}_{destination_id}`
  derivation are byte-for-byte unchanged — confirmed by the untouched surrounding code, not merely
  by the task's own framing.

  Updated every header-name string literal in the four named test suites
  (`OutboundHeadersSigningTest`, `OutboundHeadersSigningRegressionTest`,
  `SigningAllOrNoneFailureTest`, `OutboundSigningIntegrationTest`) to the new names. Two tests needed
  more than a literal swap because their scenario was the collision Decision 2 removes, not merely
  its name: `OutboundHeadersSigningTest::an_inbound_webhook_signature_header_never_reaches_a_destination_as_the_proxys_own`
  and `OutboundSigningIntegrationTest::test_ac64_outbound_webhook_headers_are_the_proxys_own_even_when_the_inbound_request_carried_them`
  each forge an *inbound* `webhook-id`/`webhook-timestamp`/`webhook-signature` trio to prove the
  proxy's own emitted value displaces it. Renamed the forged trio to the new `WebhookProxy-*` names
  too, matching AC64's own note that the scenario becomes "trivially satisfiable" post-rename (no
  real sender sends `WebhookProxy-*`) — this now exercises a hypothetical spoofing attempt rather
  than the Svix-collision case, which no longer exists. Left `OutboundHeadersTest.php` (T26,
  untouched by this task's file list) exactly as it was: its one `webhook-signature` fixture is an
  unrelated, sender-originated header forwarded verbatim with no signing configured, and stays
  correct unmodified regardless of the rename.

  `app/Verification/StandardWebhooksScheme.php` and `DeliveryUnitResolver`'s AC27 verification-header
  map do not exist any more (removed at T53, per this task's own amended sequencing note) — confirmed
  by `find`, not assumed. `DeliveryUnitResolverTest.php` (still present, unlike the three fully-deleted
  inbound suites) was grepped and carries zero reference to `webhook-id` or any verification-header
  concept any more; it needed no edit and was re-run as the harmless residual regression check.

  **`StandardWebhooks::verify()` needed nothing.** Read the file: `verify(string $id, int $timestamp,
  string $body, string $signatureHeaderValue, array $secrets)` takes no header names as arguments at
  all — the caller extracts values from whatever header names it holds, and `verify()` never sees a
  header name. `OutboundHeadersSigningTest`/`OutboundSigningIntegrationTest`'s own assertions confirm
  it verifies correctly reading values out of the newly-named `WebhookProxy-*` headers.

  Gates: `composer lint` and `composer types:check` both green. Targeted filter
  (`OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SigningAllOrNoneFailureTest|OutboundSigningIntegrationTest`)
  — 19/19 passing, 108 assertions. `DeliveryUnitResolverTest` alone — 9/9 passing, 28 assertions (the
  other three named inbound suites in the task's original filter no longer exist, per its own amended
  note — confirmed by `find` before skipping, not merely trusted). Full suite,
  `./vendor/bin/sail test --parallel` — **1003/1003 passing, 4749 assertions**, unchanged from the
  T52–T54 baseline (this task edits only header-name literals in existing tests; it adds none).
  `pnpm types:check`, `pnpm format:check`, `pnpm build` all green. `pnpm lint:check` reproduces the
  same ~730 pre-existing `import/order`/parsing errors T52's completion notes already attributed to
  nested `.claude/worktrees/*` checkouts left by other concurrent agent sessions — none in a file this
  task touched (this task edits no frontend file at all).

## T51 — Provider signature headers pass through unconditionally (ADR-025 Decision 1)
> **Status: SUPERSEDED — replaced by ADR-026 Decision A, 2026-08-28, before being built (this task was
> never started; the original body below is preserved for the record only).** This task's premise —
> that the AC27 per-proxy verification-header strip inside `OutboundHeaders::build()` is what keeps a
> member's own `shared-secret` value from leaking via provider-signature pass-through — **is void**:
> that strip is removed at **T53**, because inbound verification itself no longer exists (ADR-026
> Decision B). Decision A also reduces `DeliveryUnit::STRIPPED_HEADERS` by **seven** entries, not this
> task's five — `cookie` and `authorization` leave too. **Superseded in full by T55**, which carries
> Decision A's own reasoning instead: pass-through is safe because no member-configurable verification
> header exists to leak, not because of a strip this task depended on. Do not build against the
> original scope below; it is kept only so a reader can see exactly what changed and why.
- **Description:** Remove five names from `DeliveryUnit::STRIPPED_HEADERS`: `stripe-signature`,
  `x-hub-signature`, `x-hub-signature-256`, `x-signature`, `x-webhook-signature`. Every other entry in
  that constant stays exactly as is, same casing, same order — `host`, the RFC 7230 §6.1 hop-by-hop set
  (`connection`, `keep-alive`, `proxy-authenticate`, `proxy-authorization`, `te`, `trailer`,
  `transfer-encoding`, `upgrade`), `content-length`, `cookie`, `authorization`. Approved as
  **unconditional** pass-through (ADR-025's own gated ruling) — it applies to every proxy alike, with
  no per-proxy opt-in/opt-out toggle; no task in this document, this one included, adds one.
  **Why this is safe now, stated as the reasoning that makes it safe rather than left implicit:** the
  per-proxy verification-header strip inside `OutboundHeaders::build()` (T26, AC27) removes *this
  proxy's own* configured verification header value — a `shared-secret` value, or the
  `standard-webhooks` names — before the destination request is sent, independently of this task and
  unaffected by it. That strip is what keeps a member's own `shared-secret` value from leaving via this
  path even after these five names stop being stripped; it is not a coincidence, and this task must not
  weaken, loosen, or make it conditional.
- **Dependencies:** T26 (built the AC27 per-proxy verification-header strip this task's safety rests
  on)
- **Files:**
  - `app/Pipeline/DeliveryUnit.php` (production — the five-line removal from `STRIPPED_HEADERS`; its
    doc comment at the constant's declaration currently reads "Outbound signing is #10" for this block,
    which should be corrected to state that these five names now pass through unconditionally rather
    than being reserved/stripped pending #10)
  - `tests/Unit/Pipeline/DeliveryUnitTest.php` (`test_forward_headers_keeps_benign_and_strips_sensitive_case_insensitively` —
    the five provider-signature header fixtures move from the "stripped" assertion loop to the
    "forwarded" assertion list; the closing `assertCount` changes from 2 to 7 forwarded headers)
  - **Explicitly not touched:** the AC27 per-proxy verification-header strip inside
    `OutboundHeaders::build()` (T26) — must stay exactly as built; `host`, the RFC 7230 hop-by-hop set,
    `content-length`, `cookie`, `authorization` — none of the remaining `STRIPPED_HEADERS` entries move.
- **Acceptance Criteria:**
  - `DeliveryUnit::STRIPPED_HEADERS` no longer contains `stripe-signature`, `x-hub-signature`,
    `x-hub-signature-256`, `x-signature`, or `x-webhook-signature`; every other entry is unchanged,
    same casing, same order.
  - A request carrying `Stripe-Signature`/`X-Hub-Signature`/`X-Hub-Signature-256`/`X-Signature`/`X-Webhook-Signature`
    (any casing) now appears, unmodified, in `DeliveryUnit::forwardHeaders()`'s return value for a
    proxy with no verification scheme configured.
  - The pass-through is unconditional: identical regardless of the proxy's own verification scheme
    (`shared-secret`, `standard-webhooks`, or none) and regardless of whether the proxy also has a
    `credential_header_name` collision (T26's existing collision rule still governs any actual name
    clash, unaffected by this task).
  - On a proxy with `shared-secret` verification configured under a header name that happens to
    collide with one of the five newly-passed-through names, the proxy's own verification-header value
    is still stripped by `OutboundHeaders::build()`'s existing AC27 step before the destination request
    is sent — a dedicated test on a deliberately colliding header name, proving the AC27 strip still
    wins over the new pass-through.
  - `OutboundHeadersTest`'s (T26) AC37 byte-identical baseline and `OutboundHeadersSigningRegressionTest`'s
    (T35) AC63 byte-identical baseline both still pass unmodified — this task changes what forwards,
    not the composition order or any other header-building behaviour.
  - A repository-wide search for each of the five literal strings, run before this task is marked
    complete, confirms no other test anywhere asserted one of them as stripped on the outbound path.
- **Testing:** extends `tests/Unit/Pipeline/DeliveryUnitTest.php` (the five-header move,
  `assertCount(7, ...)`); one new dedicated test — in `tests/Unit/Support/OutboundHeadersTest.php` or a
  new file, either acceptable as long as it is independently identifiable as *the* AC27-strip-still-wins
  guard — proving the collision case above.
- **Sequencing constraint, carried here per ADR-025 § Sequencing, not only in this document's own
  report:** this task **must land with or after item #10, never before**. The AC27 per-proxy
  verification-header strip this task's safety rests on exists **only on this branch**
  (`feat/item-10-sensitive-data`, T26). Removing these five names from `STRIPPED_HEADERS` on `main`
  ahead of #10 would let a member's own `shared-secret` verification value reach a destination for any
  proxy without that AC27 strip in place yet. Placed in M10, alongside T50, both landing within item
  #10's own branch and therefore after the AC27 strip that makes this safe.
- **Completion notes:** _pending_

## T55 — Outbound strip list reduces to the technically required minimum (ADR-026 Decision A — replaces T51)
- **Description:** Reduce `DeliveryUnit::STRIPPED_HEADERS` to exactly ten entries: `host`,
  `content-length`, and the RFC 7230 §6.1 hop-by-hop set (`connection`, `keep-alive`,
  `proxy-authenticate`, `proxy-authorization`, `te`, `trailer`, `transfer-encoding`, `upgrade`).
  Remove exactly **seven** entries: `cookie`, `authorization`, `stripe-signature`, `x-hub-signature`,
  `x-hub-signature-256`, `x-signature`, `x-webhook-signature`. Every remaining entry keeps its existing
  casing and order. Rewrite the constant's docblock: it is now a **transport-scoped deny-list only** —
  an entry belongs only because forwarding it would produce a malformed or misrouted request under a
  specification that can be cited (`host` — routing/request-smuggling and the ADR-006 outbound guard;
  `content-length` — recomputed for the body actually sent; the hop-by-hop set — RFC 7230 §6.1 scopes
  these to a single transport connection), never because of what its value might contain. **Why this is
  safe now, stated as the reasoning that replaces T51's void premise:** no member can configure an
  inbound verification secret any more (ADR-026 Decision B, T52–T54), so there is no header whose value
  this service put there for a sender to leak — the hazard the old per-proxy AC27 strip existed to
  prevent is removed at its source, not mitigated at this boundary. `Authorization` and `Cookie` now
  reach every destination of the proxy — an accepted trade, ruled by the Project Owner, not reopened
  here (ADR-026 Decision A). **`proxy-authorization` stays**, on hop-by-hop grounds alone — it is a
  credential, and it is retained while `authorization` is released; state this explicitly in the
  docblock so a later reader does not "correct" it out for looking inconsistent.

  **The credential collision is now the ordinary case, and precedence already handles it, unchanged.**
  PRD-10 AC30 defaults a destination credential's header name to `Authorization`. ADR-023 Decision 2's
  existing precedence rule — every forwarded header whose lowercased name matches a lowercased added
  name is dropped before the added set is merged — resolves it correctly with no code change: the
  destination receives the credential this member configured for it, never the sender's, and never two
  `Authorization` headers. This task does not touch that rule; it only makes the collision fire far
  more often than before.
- **Dependencies:** T54 (Decision A's safety argument depends on there being no member-configurable
  inbound verification header — that dependency is substantive, not merely a preferred ordering), T26
- **Files:**
  - `app/Pipeline/DeliveryUnit.php` (production — the seven-entry removal from `STRIPPED_HEADERS` and
    the docblock rewrite described above)
  - `tests/Unit/Pipeline/DeliveryUnitTest.php` (the seven header fixtures move from the "stripped"
    assertion to the "forwarded" assertion; count the fixture for the closing `assertCount`, don't
    hardcode a number quoted in any document)
  - `tests/Feature/Delivery/DeliverToDestinationTest.php` (extended — the one new test named below)
  - **Explicitly not touched:** ADR-023 Decision 2's precedence rule; any AC27 strip (there is none any
    more — removed at T53); `OutboundHeaders::build()`'s credential composition (T26/T28).
- **Acceptance Criteria:**
  - `DeliveryUnit::STRIPPED_HEADERS` contains exactly the ten entries named above, same casing/order as
    previously for the survivors; `cookie`, `authorization`, and the five provider-signature names are
    absent.
  - A request carrying `Cookie`, `Authorization`, and any one of the five provider-signature headers
    (any casing), dispatched by a proxy whose destination has no credential of its own, forwards all
    three unmodified.
  - **The one new test ADR-026 § Impact names as worth adding, dedicated, in
    `DeliverToDestinationTest`:** a request carrying `Authorization`, `Cookie`, and a provider-signature
    header (e.g. `Stripe-Signature`), dispatched to a destination that carries its own credential under
    the default `Authorization` header name, delivers the destination's credential (not the sender's),
    delivers `Cookie` and the provider-signature header unchanged, and emits **exactly one**
    `Authorization` header — proving ADR-023 Decision 2's existing precedence rule resolves the now-
    ordinary collision correctly, unmodified by this task.
  - `proxy-authorization` (any casing) is still stripped — the hop-by-hop set is unaffected by this
    task.
  - `OutboundHeadersTest`'s (T26) AC37 byte-identical baseline and `OutboundHeadersSigningRegressionTest`'s
    (T35) AC63 byte-identical baseline both still pass unmodified.
  - A repository-wide search for each of the seven removed literal strings, run before this task is
    marked complete, confirms no other test anywhere still asserts one of them as stripped on the
    outbound path.
  - `composer lint`, `composer types:check`, `./vendor/bin/sail test --parallel` all green.
- **Testing:** extends `tests/Unit/Pipeline/DeliveryUnitTest.php` (the seven-header move); one new test
  in `tests/Feature/Delivery/DeliverToDestinationTest.php` for the credential-collision-plus-forwarded-
  headers case described above.
- **Sequencing constraint, carried here per ADR-026 § Sequencing, not only in this document's own
  report:** this task **must land with or after T54, never before** — its safety argument is only true
  once inbound verification is fully removed. Unlike T51's original (now-superseded) constraint, there
  is no "must land with or after item #10" concern distinct from that, because by this point in the
  branch's own history item #10's inbound-verification capability has already been withdrawn on this
  same branch.
- **Completion notes:** Reduced `DeliveryUnit::STRIPPED_HEADERS` from seventeen entries to the ten
  named — `host`, `content-length`, and the RFC 7230 §6.1 hop-by-hop set. Removed exactly the seven
  named: `cookie`, `authorization`, `stripe-signature`, `x-hub-signature`, `x-hub-signature-256`,
  `x-signature`, `x-webhook-signature`. Every surviving entry keeps its existing order. Rewrote the
  constant's docblock to state it is a transport-scoped deny-list only, and added an inline comment
  directly above `proxy-authorization` recording that it stays on hop-by-hop grounds alone, not
  credential grounds, so a later reader does not "correct" it out for looking inconsistent with
  `authorization`'s absence — both points ADR-026 asked to be reflected in the code, not only in this
  note.

  `tests/Unit/Pipeline/DeliveryUnitTest.php`'s single fixture test moved the seven names from the
  "stripped" assertion to a `$forwardable` list asserted forwarded, and the closing `assertCount` uses
  `count($forwardable)` rather than a hardcoded number, per the task's own instruction.

  Added the one new test ADR-026 § Impact names, in `DeliverToDestinationTest.php`:
  `test_the_destinations_own_credential_wins_over_a_same_named_forwarded_header_while_cookie_and_provider_signature_forward_unchanged`
  — a destination with its own credential under the default `Authorization` header name, dispatched a
  request carrying inbound `Authorization`, `Cookie` and `Stripe-Signature`. Asserts the destination's
  credential wins (exactly one `Authorization` header, its value the destination's, not the sender's)
  and that `Cookie` and `Stripe-Signature` forward unchanged — proving ADR-023 Decision 2's existing
  precedence rule resolves the now-ordinary collision correctly, unmodified by this task.

  **One test outside this task's own Files list needed a matching update, found by the AC's own
  repository-wide-search instruction rather than missed by it:**
  `tests/Feature/Ingest/IngestFanOutTest.php::test_header_forwarding_end_to_end` asserted, end-to-end
  through the real ingest path, that `Cookie`/`Authorization`/`Stripe-Signature` were stripped — the
  exact `! $r->hasHeader(...)` shape a plain-string grep for the removed literals as "stripped"
  assertions does not catch, but the task's own required search ("no test anywhere still asserts one
  of them as stripped on the outbound path") does. Updated to assert the three now forward unchanged,
  keeping `Connection` (hop-by-hop) as the still-stripped case in the same test. Not listed in T55's
  own Files, but this is the direct-caller check the implementation ethos requires ("check every
  caller of anything you change") and the AC's search explicitly anticipates a test like this
  surfacing — fixed rather than escalated, since the task itself supplies the correction criterion.

  `OutboundHeadersTest.php`'s (T26) AC37 byte-identical baseline and
  `OutboundHeadersSigningRegressionTest.php`'s (T35) AC63 byte-identical baseline both still pass
  unmodified — both fixtures already carried an `Authorization` header and neither call configures a
  credential, so the assertion (`forwardHeaders()` output equals `OutboundHeaders::build()`'s) holds
  symmetrically regardless of whether `authorization` is stripped. `OutboundHeadersTest.php`'s AC38
  collision test (`assertArrayNotHasKey('authorization', $result)`) also needed no change — it asserts
  the credential-collision displacement mechanism (ADR-023 Decision 2, case-insensitive match against
  an *added* header), not `STRIPPED_HEADERS`, and that mechanism is untouched by this task.

  Repository-wide search for each of the seven removed literal strings, plus a separate search for
  `hasHeader('Cookie')`/`hasHeader('Authorization')`/provider-signature-name `hasHeader` assertions,
  confirmed no other test anywhere still asserts one of them as stripped on the outbound path once
  `IngestFanOutTest.php` was corrected.

  Gates: `composer lint`, `composer types:check` both green. Filtered run
  (`DeliveryUnitTest|DeliverToDestinationTest|OutboundHeadersTest|OutboundHeadersSigningRegressionTest`)
  — 30/30 passing, 125 assertions; `IngestFanOutTest` alone — 7/7 passing, 36 assertions. Full suite,
  `./vendor/bin/sail test --parallel` — **1004/1004 passing, 4753 assertions**: +1 test
  (`DeliverToDestinationTest`'s new collision test) and +4 assertions net over the T50 baseline
  (1003/4749), accounting for the new test's own five assertions against the `IngestFanOutTest`
  rewrite's identical assertion count as before (5 forwarded/stripped checks either way — content
  changed, count did not). `pnpm types:check`, `pnpm format:check`, `pnpm build` all green.
  `pnpm lint:check` reproduces the same ~730 pre-existing `import/order`/parsing errors T52's
  completion notes attributed to nested `.claude/worktrees/*` checkouts from other concurrent agent
  sessions — none in a file this task touched (this task edits no frontend file).

---
