# Review: Sensitive data handling — item #10

- **Reviewer / date:** Reviewer, 2026-08-28
- **Scope:** `feat/item-10-sensitive-data` at `368ee23`, diff `main..HEAD` — 89 commits,
  44 live tasks across 12 milestone files (T1–T15, T22, T26–T43, T45–T50, T52–T55; nine
  superseded-and-removed, three partially superseded, two withdrawn unbuilt). Source diff
  42 files, +2989/−47 under `app/`, `resources/js/`, `database/`, `routes/`, `config/`.
  Two migrations.
- **Inputs verified:** `docs/product/prd-10-sensitive-data-handling.md` (Approved, Project Owner
  2026-08-27; **Amendments A, B and C**, C withdrawing AC23–AC28, AC46, AC50–AC53 and narrowing
  AC10, AC11, AC29, AC38, AC43, AC44, AC55, AC60, AC64) · `docs/design/design-10-sensitive-data-handling.md`
  (Approved as amended; Screens 1 and 4 and Flows A–C withdrawn; two 2026-08-28 amendments) ·
  `docs/plans/plan-10-sensitive-data-handling.md` (fully approved, incl. `## Revision A`) ·
  `docs/tasks/sensitive-data-handling/` (index + `m01`–`m12`, every live task carrying completion
  notes) · `docs/architecture/adr-021`…`adr-026` (**ADR-026 governing where it conflicts with
  anything older**) · `docs/standards/` (coding, testing, planning, documentation) ·
  `docs/reviews/review-07-enhanced-mode-toggle.md` (house format and severity precedent).
- **Method:** milestone by milestone, each milestone's code read against its own milestone file
  rather than against the completion notes' account of it. Claims that a note asserts are
  re-derived in the tree; two are re-derived at runtime rather than by reading.

## Gate results

The Project Owner recorded the gates as already run and asked that they not be re-run. The
backend suite was re-run once anyway, because a recommendation that rests on someone else's
test result is not an independent review; it is cheap here (~8 s parallel) and it reproduces
exactly. The remaining gates are taken as recorded.

| Gate | Command | Result |
|---|---|---|
| Backend suite | `./vendor/bin/sail test --parallel` | **`{"tool":"paratest","result":"passed","tests":1016,"passed":1016,"assertions":4809,"duration_ms":8425}`**, exit 0 — exactly the claimed 1016/4809 |
| Code style | `composer lint` | recorded clean (not re-run) |
| Static analysis | `composer types:check` | recorded clean, PHPStan L7 (not re-run) |
| Frontend gates | `pnpm lint:check` / `types:check` / `format:check` / `build` | recorded clean (not re-run) |
| Live browser walk | T49, Flows D–I, both themes, 360px, production build | recorded in `m12-hardening-regression-sweep.md` (not re-run) |

## Findings by milestone

Findings are numbered in the order they were found and classified where they land. Severity
follows the house scheme: **Blocker** — violates an acceptance criterion or breaks function;
**Major** — violates the plan, the approved design spec, or a standard materially; **Minor** —
follow-up or style; **Nit** — recorded, no action expected.

### M1 — Data model (T1–T3)

**No findings.**

`2026_08_27_000001_add_sensitive_data_handling_schema.php` matches the Owner-approved change set
(flag 1) entry for entry: `proxy_secrets` with the nine columns and the single named
`proxy_secrets_proxy_id_purpose_is_current_unique` partial-unique index, three nullable `proxies`
columns, three nullable `destinations` columns, `down()` a `dropIfExists` plus two `dropColumn`
calls, no index touched on either existing table, no backfill. `ProxySecret` carries the
`encrypted` cast on `value` **and** `$hidden = ['value']` (ADR-021 Decision 6.1's second guard),
and `scopeLive()` implements the "current, or superseded-but-not-yet-expired" predicate with
`orderByDesc('is_current')` so the current secret is always first in the live set — which is what
makes AC58's two-entry signature list ordered rather than incidental. `SecretPurpose` is correctly
left a backed enum with a single `Signing` case rather than collapsed to a constant (ADR-026
Decision 3).

Only two migrations exist on the branch, as the plan requires: T1's and T54's removal migration.

### M2 — Obfuscation engine (T4–T6)

**No findings.**

`SensitiveFields::DEFAULTS` is exactly 23 entries — 8 password, 7 token, 8 credit-card — matching
ADR-024 Decision 5, with `secret`/`api_key`/`private_key`/`client_secret` genuinely absent and
`cvv`/`pwd` genuinely present. No two entries collide under `normalise()`.
`SensitiveFieldMatcher` checks defaults before additions, so plan-10 Technical ruling 2's tie-break
is structural rather than a separate branch. `PayloadObfuscator` replaces a matched value with
literal `null` and `continue`s before recursing, so C6's whole-value replacement is likewise
structural — a sensitive object cannot leak a sub-key because the walk never enters it. AC16 holds
in the strongest form available: the output carries no character, no length, and no equality signal,
because every replaced value is the same literal.

Array indices are never tested as names, and RFC 6901 escaping applies `~` before `/` in the
correct order.

### Finding 1 (Nit) — a JSON object with sequential numeric keys is walked as an array

`app/Support/PayloadObfuscator.php:48` uses `array_is_list()` to distinguish a JSON object from a
JSON array. `json_decode('{"0":"a","1":"b"}', true)` produces a PHP list, so such an object's keys
are treated as positions and never offered to `matchFor()`. No default name is numeric, so this can
only bite a proxy whose AC13 addition is an all-digit field name — and the same conversion already
governs how the envelope re-encodes for display, independently of this feature. Recorded because
the constraint is invisible at the call site, not because any AC is at risk.

### M3 — Standard Webhooks primitive (T7)

**No findings against the criteria.**

`StandardWebhooks::sign()` is `base64_encode(hash_hmac('sha256', "<id>.<timestamp>.<body>", key, true))`
— base64 not hex, over the specification's signed content, exactly as AC55 now states directly.
`TOLERANCE_SECONDS` is a class constant rather than config, which is what keeps the specification's
tolerance from becoming a product-tunable value. `verify()` correctly survives as the receiver-side
oracle the outbound signing tests verify against; ADR-026 § *What stays, and why* names this class
by name as the one most likely to be over-deleted, and it was not.

### Finding 2 (Nit) — an undecodable signing secret signs under an empty key rather than failing

`StandardWebhooks::decodeSecret()` (`app/Support/StandardWebhooks.php:143`) returns `''` when
strict `base64_decode` fails, so `sign()` would compute an HMAC under an empty key rather than
raising. This is unreachable in the shipped product — AC56 makes the product the sole generator of
a signing secret and `SecretStore::generate()` always emits `whsec_` + valid base64 — so it is a
Nit, not a Minor. It is recorded because the failure shape ("silently degrade rather than fail
loudly") is the one AC11 exists to forbid, and a future path that let a secret in by another route
would inherit it.

### M4 — Revealed-payload envelope (T8–T9)

**No findings.**

`ProxyEventPayloadController` guards `payload_cleaned_at` before either branch, so a cleaned event
returns the same empty 410 whatever its stored body would have parsed as (AC21's
obfuscation-versus-retention distinction is preserved at the transport level, not just in the UI).
The JSON branch emits `{format, document, obfuscated}` with `nosniff` and `no-store, private`; the
non-JSON branch is byte-identical to the pre-feature response and makes no field-level claim
(AC22). `array_map` over the pointer index preserves its string keys — correct, since exactly one
array is passed.

`PayloadViewer.vue` branches on the response's `Content-Type` rather than on what it requested,
which is the right side of ADR-024's server-decides rule; the `[Hidden]` token is an inert `<span>`
with no click handler, no `tabindex` and no role, carrying both a native `title` and an `sr-only`
node (C8, N1), and renders through `v-text` with no `v-html` introduced.

### M5 — Sensitive-fields configuration (T10–T12)

**No findings.**

`sensitive_fields` / `sensitive_fields.*` rules are present on both `StoreProxyRequest` and
`UpdateProxyRequest` (`nullable|array|max:100`, `string|max:128|regex:/\S/`), and
`ProxyController::sensitiveFieldAdditions()` trims, drops blanks and de-duplicates by normalised
form while keeping the first occurrence's spelling. `defaultSensitiveFieldNames` is emitted on
`create()` and `edit()` sourced directly from the T4 constant and is absent from `index()`, exactly
as Technical ruling 3 requires. AC12's "may not remove from it or edit it" is enforced
structurally — the default list is code, never persisted per proxy, so there is nothing a member
could remove.

The full-replace-on-submission semantics for `sensitive_fields` (as opposed to the write-only
"absent means unchanged" contract the actual secret fields use) is a deliberate, documented
divergence pinned by its own test. It is correct: `sensitive_fields` is not a secret, and binding
constraint 8's list does not include it.

<!-- M6 onwards appended below as each milestone is reviewed. -->

### M6 — `SecretStore` (T13–T15, T22's surviving part)

`SecretStore` is the right shape and the AC29 cap is a genuine write-path property rather than an
aspiration: `replace()` deletes any already-superseded row, demotes the current one and inserts the
new one inside a single `DB::transaction()`, so three consecutive rotations cannot leave three rows
even briefly, and a second rotation inside a running overlap deletes the oldest outright rather
than merely moving its expiry. `liveFor()` throws `SecretUnavailableException` rather than
excluding an undecryptable row — a partial list would be indistinguishable from a completed
rotation, which is exactly AC11's failure mode. The exception's message names only the purpose;
no proxy id, no ciphertext fragment, no value. Liveness is a property of the data
(`ProxySecret::scopeLive()`), not of the sweeper, so an unrun job cannot make an expired secret
live.

**Technical ruling 14 re-derived independently, not accepted:** `grep -rn "ProxySecret\|proxy_secrets" app/`
returns exactly four executable query sites — `SecretStore` (all five operations plus `statusFor()`),
`ExpireProxySecrets`, `PurgeExpiredProxySecrets`, and `Proxy::secrets()`'s relation definition.
The two Action classes are T15, which binding constraint 5 permits by name; `Proxy::secrets()`
has no caller anywhere. `ProxySecurityResource` reaches the table only through
`SecretStore::statusFor()`. **The invariant holds.**

`ExpireProxySecrets` takes scalar arguments only, so ADR-021 Decision 8's `SerializesModels`
hazard is structurally avoided, and both it and the sweeper are `DELETE`-only with an
`expires_at <= now()` predicate — neither can extend a window.

T22's surviving portion is intact exactly as its status note requires: `ProxySecurityResource`,
the sibling `security` prop on `show()`/`edit()` only, `SecretStore::statusFor()` and
`#[PreserveKeys]` all stand; only the `verification` key and its lookup are gone. `index()` gains
no key and `ProxyResource` is unchanged in this respect.

### Finding 3 (Nit) — the secrets sweeper drops a guard its own comment says it reused

`routes/console.php:46-48` registers `Schedule::command('secrets:purge-expired')->daily()`, with a
comment stating the shape was "reused from the retention sweeper above". The retention sweeper it
points at carries `->at('02:00')->withoutOverlapping()`; this one carries neither. No AC is
breached — the command is a single idempotent `DELETE`, so a concurrent second run affects zero
rows, and the sweep is a liveness net whose timing is not load-bearing. Recorded only because the
comment asserts a symmetry the code does not have, and a future reader will trust the comment.

### M7 — Outbound credential (T26–T33)

`OutboundHeaders` is genuinely the only place an outbound header set is built — `DeliverToDestination::send()`
is its sole caller and no second copy of the strip list exists. The credential value is passed
through verbatim with no scheme prefix (AC30), collision removal is case-insensitive against the
lowercased added-name set (AC38, R9), and a destination with no credential on a proxy with no
signing secret produces a result byte-identical to `forwardHeaders()` alone (AC37, AC63) —
structurally, because both `$added` branches are guarded and `withoutNames()` on an empty name list
is the identity.

`DeliveryUnitResolver` loads the proxy `withTrashed()`, so a retry against a soft-deleted proxy
still resolves (R3). `ProxySecurityResource`'s `destinations` map is built `withTrashed()` too, so
its id coverage is the superset the Show table needs, and `has_credential` is derived from
`credential_set_at` rather than by reading — and so decrypting — `credential_secret`. The
`#[PreserveKeys]` attribute is load-bearing and correctly scoped; without it Laravel's
`removeMissingValues()` would `array_values()` the all-numeric id keys away.

`DestinationResource` exposing `credential_header_name`/`has_credential`/`credential_changed_at`
is correct — none is a value or a length — and `sensitive_fields` riding on `ProxyResource` (and
therefore reaching `index()`) is likewise fine: it is plain per-proxy configuration, not security
status, so Technical ruling 3's sibling-prop rule does not reach it, and T11's "index gains no new
key" bullet is scoped to `defaultSensitiveFieldNames`, which its own test names explicitly.

### Finding 4 (Major) — an edited credential header name is silently discarded unless the secret is also replaced

**Where.** `app/Http/Controllers/ProxyController.php:410-434`, `destinationCredentialAttributes()`.

**What.** For a destination that already has a credential, the method returns `[]` — a total no-op
— whenever `credential_secret === ''`:

```php
if ($row['credential_secret'] === '') {
    return [];
}
```

`credential_header_name` is only ever written in the branch below it, alongside a new secret. But
`DestinationRows.vue` renders the **Header name** input outside the `credentialIsSet(row)` guard,
so it is visible, enabled and editable in the credential-set state, and `ProxyForm.vue` submits the
edited value. A member who changes a destination's credential header from `Authorization` to
`X-Api-Key` without also clicking **Replace** gets the standard `Changes saved.` toast and a
redirect to the Show page, and the column is unchanged. Reloading Edit re-renders the old name, and
every subsequent dispatch keeps sending the old header.

**Criterion violated.** `design-10` Screen 3 specifies the field as `Label "Header name" / Input
(default "Authorization", **visible + editable always**)`, and its per-row states table gives the
**Existing, credential set** row as `Header name (editable)`. The screen is approved as amended and
neither line is touched by any amendment. The write path does not implement it.

**Verified at runtime, not inferred.** Reflecting into the private method and invoking it with
`['credential_header_name' => 'X-Api-Key', 'credential_secret' => '', 'remove_credential' => false]`
returns `array(0) {}` — no key written.

**No test covers it.** `CredentialValidationTest::test_an_empty_credential_secret_on_a_destination_that_already_has_one_leaves_it_stored_unchanged`
resubmits the *same* header name (`X-Api-Key` → `X-Api-Key`, lines 79 and 96), so it asserts the
preservation half of binding constraint 8 without ever exercising a changed name. The gap is
genuine, not merely untested-and-correct.

**Why Major rather than Minor.** This is the review-07 Finding 1 shape: a surface that offers an
affordance the same save silently falsifies, on a path the approved design names, with a success
confirmation. It is short of a Blocker because no stored data is destroyed and a workaround exists
(Replace, or Remove credential then re-add) — but the workaround is undiscoverable, and the
failure is silent in both directions. Note the security-adjacent consequence: a member moving a
destination off `Authorization` precisely to stop colliding with AC38's now-ordinary forwarded
`Authorization` will believe they have done so and will not have.

**Route to.** Senior Developer. No plan, design or requirement defect is involved — the design
spec is unambiguous and the implementation simply does not honour it.

### M8a — Outbound signing, backend (T34–T40)

The signing half is the strongest-built part of this feature. `WebhookProxy-Id` is derived
(`msg_{dispatchUuid}_{destination.id}`) rather than stored, which makes AC60's three properties
structural rather than maintained: identical across every retry of a delivery (same `Delivery` row,
same `dispatch_uuid`), new on a replay (a fresh `Delivery`), and different per destination of one
dispatch even though the key is shared. `WebhookProxy-Timestamp` is taken at the call inside
`send()`, so it is this attempt's time, not the original dispatch's — the property AC60 spells out
because getting it wrong silently pushes every retry outside its receiver's replay window.
The signature is computed over `$unit->payload`, the identical value handed to
`Http::send(..., ['body' => $unit->payload])`, so AC59's "exact bytes dispatched" is an identity
rather than a coincidence, and AC17 composes with it for free.

AC11's all-or-none rule is correctly implemented as a *deferred* exception rather than a throw out
of `resolve()`: throwing at resolution would kill the job before the `DeliveryAttempt` row exists,
leaving no per-destination Failed record and no `error_summary` — which is the outcome AC11's own
"fails visibly" clause forbids. Carrying `signingSecretsUnavailable` onto the unit and rethrowing
as the first statement inside `send()`'s `try` gets a recorded, value-free failure on every
destination, before any header is built and before any HTTP request is made. T39's completion notes
record that nothing read the deferred field before that task and every destination would have
dispatched **unsigned** — a real gap, found and closed rather than papered over.

AC63's regression is a genuinely independent guard, in its own file
(`tests/Unit/Support/OutboundHeadersSigningRegressionTest.php`), with both required cases —
never-enabled and enabled-then-disabled — asserted against T26's own AC37 fixture rather than a
fresh one.

### Finding 5 (Minor) — a credential header named after a signing header produces two headers of the same name, or silently loses the credential

**Where.** `app/Support/OutboundHeaders.php:47-56`.

`build()` assembles `$added` as the credential first, then spreads the signing headers over it:

```php
$added[$credentialHeaderName] = $credentialValue;
$added = [...$added, ...self::signingHeaders($unit, $signingSecrets)];
$headers = self::withoutNames($unit->forwardHeaders(), array_keys($added));
```

`withoutNames()` resolves collisions case-insensitively between `$added` and the **forwarded** set —
which is R9's stated hazard and AC38's rule — but nothing resolves a collision *within* `$added`.
Two outcomes, both wrong, depending only on casing:

- **Different casing** (credential header `webhookproxy-signature`): both survive as distinct PHP
  array keys and `Http::withHeaders()` emits **two** `WebhookProxy-Signature` headers. Verified at
  runtime — the built set contains `webhookproxy-signature => Bearer creds` alongside
  `WebhookProxy-Signature => v1,…`.
- **Identical casing** (credential header `WebhookProxy-Signature`): the spread overwrites, the
  member's credential is dropped from the request entirely, and nothing says so.

**Criterion.** No criterion is literally breached — AC38 and AC64 are both written over *forwarded
inbound* headers, and a credential is neither. But R9 is the plan's own named risk ("`Http::withHeaders()`
takes a PHP array and would otherwise happily emit `authorization` and `Authorization` as two
separate headers"), the class docblock claims to discharge it, and the duplicate-header outcome is
precisely the ill-defined outbound set AC64 says the precedence rule exists to prevent. The second
outcome also loses a configured credential silently, which is the shape AC11 rules against
elsewhere.

**Why Minor.** It requires a member to deliberately name a credential header `WebhookProxy-*` —
`credential_header_name`'s HTTP field-name regex permits it, but nothing invites it, and no
provider integration would ask for it. Reachable and wrong, but not on any ordinary path.

**Route to.** Senior Developer.

### M8b — Outbound signing, surface (T41–T43)

Screen 6's five states are all present and correctly driven. Design-gate ruling 4's three bound
conditions on the one-time reveal are each implemented separately rather than approximated:
`handleOpenChange()` refuses the close outright, `suppressDismissalDuringReveal()` `preventDefault`s
both `@escape-key-down` and `@pointer-down-outside` so nothing animates before being refused,
`:show-close-button="state !== 'reveal'"` removes the corner `X`, and a `watch` moves focus to
**Done** when the sub-state mounts — so it is a deliberate exit, not a WCAG 2.1.2 keyboard trap.
The footer renders **Done** alone in that sub-state, with no Cancel-style affordance, exactly as
ruled.

The two AC29 ruling-2a disclosures are both present and both **verbatim** against their amendments:
state 3's ordinary-branch copy (`## Amendment — Screen 6 state 3's ordinary-branch disclosure`) and
state 4's overlap-running copy (correction B2). Both render as part of their state, so both are in
front of the member *before* Regenerate is clicked, which is what ruling 2a actually requires.
No confirmation step was added in front of either, per those same amendments.

**The known DialogDescription correction is implemented verbatim** — the string in
`ProxySigningDialog.vue` matches the Designer's `## Amendment — Screen 6 DialogDescription
inbound-verification claim withdrawn (2026-08-28)` replacement copy word for word, with the
inbound-verification clause gone. Recorded here as history, not as a finding.

`everDisabledThisSession` survives a disable: `router.delete()` defaults `preserveState: true`
(`@inertiajs/core/dist/index.js:3068`), and `ProxySigningDialog` is mounted unconditionally on
`Show.vue` rather than behind a `v-if`, so the component is not recreated and state 5 genuinely
renders on re-open. Checked in the adapter source rather than assumed, because the opposite default
would have made state 5 unreachable.

### Finding 6 (Minor) — the Signing card is placed after the Destinations table, not before it

`design-10` Screen 4b fixes placement as "alongside the Verification card (Screen 4), in the same
card-stack position (pipeline/security cards, grouped together, **before the destination-facing
Destinations table**)". The shipped order on `proxies/Show.vue` is Ingest URL → Response →
**Destinations** → **Signing** → Retry policy.

The spec is no longer univocal here, and that is worth stating before grading it. Screen 4b's
primary instruction is *positional relative to the Verification card* — and the Signing card does
sit exactly where the Verification card sat (verified against `ffd2bd5~1`, where the order was
Destinations → Verification → Signing). Screen 4 is withdrawn, so the anchor the clause was written
against no longer exists, and only the parenthetical survives as a live constraint. The
pre-existing Verification card did not honour that parenthetical either, so this is inherited
rather than introduced at T41.

**Why Minor, not a Nit.** Screen 4b is explicitly listed as *unaffected* by both 2026-08-28
amendments, so its text stands as approved and one half of it is not implemented. Nothing
functional turns on it and no AC touches card order.

**Route to.** **Designer**, not the Senior Developer — the question is which half of a now-ambiguous
placement clause governs once its anchor is withdrawn, and that is a design call rather than an
implementation defect.

### Finding 7 (Nit) — Screen 6 state 5 is reachable only within one page session

State 5's extra line ("Enabling again generates a new secret — your previous one is never shown or
reused.") is driven by `everDisabledThisSession`, a component-local ref. `SecretStore::disable()`
deletes every row (ADR-021 Decision 5), so the server genuinely cannot distinguish "never enabled"
from "disabled after being enabled" — a member who disables, navigates away and returns sees state 1
without the line. This is a consequence of ADR-021 Decision 5 meeting a design state that assumes
durable knowledge, not an implementation shortcut, and T38's completion notes call it out. Recorded
so the Designer sees it; closing it would need a column the data model deliberately does not have.

### M11 — Inbound verification removal, ADR-026 Decision B (T52–T54)

**No findings.** This milestone is clean, and I checked it as a removal rather than as an addition —
the failure mode for a removal is a survivor, not a defect.

Swept `app/`, `resources/js/`, `routes/`, `config/` and `database/factories/` for
`verificat|verifier|standard.?webhooks|shared.?secret`. Every live hit is either the retained
`StandardWebhooks` primitive (correctly kept — ADR-026 § *What stays, and why* names it as the
class most at risk of over-deletion), Laravel Fortify's unrelated email-verification and 2FA code,
or a historical comment that explicitly states the capability is gone. **No executable inbound
verification path survives anywhere.** Specifically: `app/Verification/` does not exist,
`App\Enums\VerificationScheme` does not exist, `SecretPurpose` has exactly one case, no
verification route or controller remains, `ProxySecurityResource` emits no `verification` key,
`StoreProxyRequest`/`UpdateProxyRequest` carry no verification rule, and `IngestController` has no
gate of any kind between the token check and capture.

`2026_08_28_000001_remove_inbound_verification.php` deletes every `verification`-purpose
`proxy_secrets` row and drops exactly the two columns, leaving `sensitive_fields` and all three
`destinations` credential columns alone. T1's migration is genuinely unedited —
`git log main..HEAD -- <T1 migration>` returns exactly one commit, `396f39c`, its own. `down()`'s
docblock states plainly what it cannot restore (the column values, and the provider-issued secrets,
named as unrecoverable) rather than letting a rollback imply reversibility.

The two class docblocks that named the deleted `InboundVerifier` / `StandardWebhooksScheme`, found
at T49, are corrected in the tree (`SecretStore`, `StandardWebhooks`) — recorded as history, not as
a finding.

### M10 — Outbound header policy, ADR-025 D2 and ADR-026 D A (T50, T55; T51 superseded unbuilt)

**T50's rename is correctly narrow.** Binding constraint 9 forbids a global `webhook-`
find-and-replace, and the rename genuinely touches one production file — `OutboundHeaders.php`'s
`signingHeaders()` — emitting `WebhookProxy-Id` / `-Timestamp` / `-Signature` while leaving
`StandardWebhooks`'s value-format parsing (`v1,<base64>`, space-delimited) untouched, which is
exactly AC55's "value formats are unchanged".

**T55's reduction is exact.** `DeliveryUnit::STRIPPED_HEADERS` holds **exactly ten** entries —
`host`, `content-length`, and the eight RFC 7230 §6.1 hop-by-hop fields — which is precisely what
AC43 and ADR-026 Decision A name, no more and no less. Counted entry by entry against RFC 7230
§6.1 rather than against the task's own claim. `proxy-authorization` correctly stays, and the
docblock says why in a way that pre-empts a future "correction": it is retained on hop-by-hop
grounds alone, independent of its value, which is the only kind of reason Decision A permits an
entry to exist for.

The constant's docblock now states the transport-only character explicitly, which is what makes the
list checkable by someone who has never seen a given header — Decision A's own stated test.

**Swept for survivors of the seven removed names** across `app/` and `tests/`. Every remaining
occurrence asserts them as **forwarded**, not stripped: `DeliveryUnitTest.php:60-62` lists
`cookie`, `authorization` and all five provider-signature names in the expected forwarded set, and
`IngestFanOutTest` asserts `Cookie` and `Stripe-Signature` arrive at the destination verbatim. No
test anywhere still asserts one of them as stripped.

The ADR-026 § Impact test is present, dedicated and correct —
`DeliverToDestinationTest::test_the_destinations_own_credential_wins_over_a_same_named_forwarded_header_while_cookie_and_provider_signature_forward_unchanged`
asserts the destination's credential wins, `assertCount(1, $request->header('Authorization'))`
proves exactly one header of that name is emitted (the R9 duplicate hazard, pinned), and `Cookie`
and `Stripe-Signature` both arrive unchanged. That is AC38's now-ordinary case tested as the
ordinary case, which is what Amendment C ruling 1 said a Reviewer would otherwise be entitled to
doubt.

T51 is correctly superseded before being built; nothing in the tree implements a five-name
reduction.

### Finding 8 (Minor) — two docblocks still name the emitted header `webhook-id` after T50's rename

`app/Pipeline/DeliveryUnit.php:57` and `app/Services/DeliveryUnitResolver.php:38` both describe
`$dispatchUuid` as the ingredient from which `OutboundHeaders` "derives `webhook-id`". No header of
that name is emitted anywhere any more — T50 renamed it to `WebhookProxy-Id`, and AC60's
pre-amendment names are explicitly retained in the PRD only as history.

This is comment-only; behaviour is correct and `OutboundHeaders`' own docblock uses the new names
throughout. It is a Minor rather than a Nit because these two comments are precisely where a
developer tracing the header's origin lands, and they name a contract that no longer exists —
`docs/standards/documentation.md`'s accuracy requirement, and the same class of defect T49's own
sweep was chartered to catch (it caught the inbound-verification ones and not these).

`StandardWebhooks.php:51`'s reference to a `webhook-signature` header value is **not** part of this
finding: it documents `verify()`, the receiver-side oracle, where `webhook-signature` is the
specification's own inbound name and is correct as written.

### M9 — Cross-cutting hardening and final regression sweep (T45–T49)

**T45 (R4, Technical ruling 7) — old-input scrub.** Both `StoreProxyRequest` and
`UpdateProxyRequest` override `failedValidation()` and `unset()` every
`destinations.*.credential_secret` before `parent::failedValidation()` flashes the input. The
override also re-merges into the container-bound request instance when it differs from `$this`,
which is the case that would otherwise leave the secret in the session despite the local scrub.
Covered by `OldInputScrubTest` for both Store and Update. Correct.

**T46 (R5, Technical ruling 8) — capture-failure report wrap.** `IngestController::reportCaptureFailure()`
reports a **fresh** `RuntimeException` carrying `ingest_id`, the proxy id and the SQLSTATE, and
deliberately does **not** set the original as `previous` — so nothing about
`QueryException::formatMessage()`'s interpolated bindings can resurface through exception-chain
formatting either. That second half is the part a naive fix misses, and it is present. It is
table-agnostic: whichever write inside the wrapped transaction fails, the same sanitized shape is
reported, so a failed `proxy_secrets` or `destinations.credential_secret` write is covered by the
same path rather than by a parallel one. This strengthens AC8 beyond what it literally asks.

**T47 (Q-10-02 finding B) — prune/trim/retention ordering.** `RetentionOrderingTest` reads all
three values from their actual sources rather than restating them, so it cannot drift from the
config it guards.

**T48 (R6) — secret-absence sweep.** Seven tests over the five named surfaces (`index`, `show`,
`edit`, events index, events show) plus the payload endpoint, **plus** a case that eager-loads the
`secrets` relation before serialization and asserts nothing leaks anyway — which is the case that
actually exercises `ProxySecret::$hidden` rather than merely relying on nothing having loaded the
relation. That is the right test to have written.

**AC36 and AC62 re-derived rather than assumed.** Grepping every write site of `credential_secret`
and every query against `proxy_secrets` shows no retention, GC, expiry or cleaned-event path
touches either. Retention erases `webhook_events.body` and `dispatched_payloads.body` and nothing
else, so "retention does not erase it, expiry does not clear it, and a cleaned event has no bearing
on it" holds structurally, not by intent.

**T49's browser walk** is taken as recorded, per the Project Owner's instruction. Nothing in the
tree contradicts it: the header names it certified on the wire are the names `OutboundHeaders`
emits, the `v1,` list construction it observed during and after an overlap is what
`signingHeaders()` builds from `liveFor()`, and the "no added headers at all when signing is off
and no credential is set" claim is the structural identity Finding-free M8a already establishes.

### Finding 9 (Minor) — `docs/status.md` records a design amendment as approved that the artifact says is still awaiting its gate

`design-10`'s `## Amendment — inbound verification withdrawn (2026-08-28)` carries, in its own
status line: **"Status of this amendment: WRITTEN, awaiting Product Manager re-approval"** — the
delegated design gate `CLAUDE.md` routes as "design → product-manager". `docs/status.md:67` records
design-10 as "Approved as amended — two dated gates 2026-08-27, plus the inbound-verification
withdrawal and the Screen 6 `DialogDescription` correction, both 2026-08-28", i.e. as though that
gate had closed.

`CLAUDE.md` is explicit that artifacts under `docs/` are the source of truth and `status.md` carries
only what routing needs, so the artifact governs and the gate is open.

**Why this is Minor and not a Blocker.** The Reviewer skill treats a *missing* approved design spec
as a Blocker because it means the Designer phase was skipped. That is not the case here: design-10
has two closed gates, and the outstanding amendment **only withdraws** surfaces — it specifies
nothing new to build. Its substance is already Owner-approved through ADR-026 Decision B, whose own
Owner-approval-flags section records none outstanding. So nothing in the shipped code rests on an
unapproved requirement. What is genuinely open is the paperwork on a delegated gate, plus a
status.md line that would let a later reader conclude it had closed.

**Route to.** **Product Manager** for the re-approval; the status.md line is the Orchestrator's to
correct once it closes. The Project Owner can reasonably close both at the same sitting as the
decision on this review.

## Surviving acceptance criteria — coverage

Amendment C withdrew AC23–AC28, AC46 and AC50–AC53. Those eleven are **not** checked and their
absence is **not** a gap. The remaining fifty-three are below; the nine narrowed criteria are
checked against what Amendment C says survives, never against their pre-amendment text.

| AC | How verified | Verdict |
|---|---|---|
| AC1–AC3 at-rest floor, closed store set | `EncryptedColumnSurfaceTest` pins the six encrypted columns by reflection; no third payload store added anywhere in the diff | ✅ |
| AC4–AC5 no payload in job args or failure records | Scalar-only job arguments on `ExpireProxySecrets` (ADR-021 Decision 8); T46's report wrap removes the interpolated-SQL vector | ✅ |
| AC6–AC7 diagnosability, at-least-once unweakened | No dispatch guarantee touched; `settleDelivery()` unchanged in substance | ✅ |
| AC8 payload never in logs | `payload.revealed` logs identifiers only; T46 removes the one remaining path by which ciphertext bindings could reach a log | ✅ |
| AC9 short-term store out of scope | No such store introduced | ✅ |
| AC10 (narrowed) key-lifecycle over credential + signing secret | Both columns carry `encrypted` casts and appear in the pinned six-column list; the verification secret is correctly absent | ✅ |
| AC11 (narrowed) fail loudly — signing + credential clauses | `SecretUnavailableException` from `liveFor()`, deferred onto the unit, rethrown first inside `send()`; `SigningAllOrNoneFailureTest` proves no destination dispatches signed **or** unsigned. Credential decrypt failure throws through the model cast into the same `catch` | ✅ |
| AC12 default list visible, fixed | 23 names, rendered literally as one badge each; the list is code, never per-proxy data, so removal is structurally impossible | ✅ |
| AC13 per-proxy additions | `sensitive_fields` per proxy, add/remove free, dedup by normalised form; per-proxy isolation tested | ✅ |
| AC14 name matching only, no value detection | `matchFor()` takes a name and never a value; grep confirms no checksum/entropy/heuristic anywhere | ✅ |
| AC15 values obfuscated, names preserved | `PayloadObfuscator` replaces values only; keys and array lengths preserved | ✅ |
| AC16 discloses nothing | Every replaced value is literal `null` — no character, no length, no equality signal | ✅ |
| AC17 display-only, dispatched bytes unchanged | Obfuscation lives solely in `ProxyEventPayloadController`; no write, dispatch, retry or replay path calls it | ✅ |
| AC18 applied before content leaves the server | Applied server-side in the one content-bearing endpoint, by any route | ✅ |
| AC19 on by default, retroactive | Nothing is rewritten, so retroactivity is free; no toggle exists | ✅ |
| AC20 no per-field reveal, no new permission | No reveal affordance; `authorize('view', $proxy)` reused, no new `TeamPermission` case | ✅ |
| AC21 distinguishable from retention states | 410 for cleaned vs `[Hidden]` token for obfuscated, decided before either branch | ✅ |
| AC22 JSON only | Non-JSON body returns unchanged raw bytes with no envelope and no claim | ✅ |
| AC29 (narrowed) cap of two, 24 h, end early, both presented, credential excluded | `replace()`'s single transaction; `RotationOverlap::HOURS = 24` as a `final const`; `endOverlap()`; `liveFor()` current-first; no rotation state anywhere on the credential surface | ✅ |
| AC30 header name + verbatim value, default `Authorization` | Verbatim, no prefix added; default supplied by the form with a defensive server fallback | ⚠️ **Finding 4** — the header-name half is not editable in effect |
| AC31 per destination | Columns on `destinations`; no proxy-level credential exists | ✅ |
| AC32 presented on every dispatch | Built in `send()`, the single funnel for attempt 1, retries and replays | ✅ |
| AC33 write-only after saving | Never redisplayed; surface shows set/not-set + changed date, derived from `credential_set_at` without decrypting | ✅ |
| AC34 encrypted at rest | `encrypted` cast, pinned by `EncryptedColumnSurfaceTest` | ✅ |
| AC35 appears nowhere but the outbound request | `SecretAbsenceSweepTest` across six surfaces incl. the eager-loaded path | ✅ |
| AC36 configuration, not payload content | Re-derived: no retention/GC/expiry path writes either credential column | ✅ |
| AC37 existing destinations byte-identical | `OutboundHeadersTest`'s named AC37 regression, run first, asserting against `forwardHeaders()` | ✅ |
| AC38 (narrowed) credential precedence, grounds corrected | `withoutNames()` case-insensitive; `DeliverToDestinationTest`'s new case proves exactly one `Authorization` emitted in the now-ordinary collision | ✅ |
| AC39 URL-embedded secrets untouched | No detection, migration, warning or rewrite anywhere in the diff | ✅ |
| AC40–AC42 header policy | No header storage shape changed; no header display surface introduced | ✅ |
| AC43 (narrowed) strip list reduces to the technical minimum | `STRIPPED_HEADERS` is exactly ten — `host`, `content-length`, the eight RFC 7230 §6.1 fields — counted against the RFC, not against the claim | ✅ |
| AC44 (narrowed) no application-key rotation tooling | None added; the deferral stands | ✅ |
| AC45 no per-team key policy | Unchanged | ✅ |
| AC47 no numeric targets | None asserted | ✅ |
| AC48 no dependency on #8/#9 | None | ✅ |
| AC49 nothing but payload content obfuscated | Obfuscation reachable only from the payload endpoint | ✅ |
| AC54 proxy-level signing, off by default | One `signing` secret per proxy; no per-destination column, toggle or rotation state exists; `test_ac54_…` covers a destination added afterwards | ✅ |
| AC55 (narrowed) Standard Webhooks under `WebhookProxy-*` names | `sign()` is base64 HMAC-SHA256 over `<id>.<timestamp>.<body>`; header set emitted under the three renamed names; `v1,<base64>` space-delimited, at most two | ✅ |
| AC56 product generates, member cannot supply | `generate()` is the only creation path; no route or rule accepts a member-supplied signing secret | ✅ |
| AC57 displayed once, write-only after | The one JSON endpoint, `no-store, private`, never in a prop or the session; the dialog clears `revealedSecret` on close | ✅ |
| AC58 regeneration rotates under AC29, both presented | `generate()` routes through `replace()`; `test_ac58_…` asserts one entry per live secret during the overlap, exactly one after expiry | ✅ |
| AC59 signature over exact dispatched bytes | `$unit->payload` is the identical value handed to `Http::send()` — an identity, not a coincidence | ✅ |
| AC60 (narrowed) per-attempt identity, renamed headers | `msg_{dispatchUuid}_{destinationId}`, timestamp taken in `send()`; identical on retry, new on replay, distinct per destination — all three tested | ✅ |
| AC61 signing secret nowhere but reveal + computation | `SecretAbsenceSweepTest` + `test_ac61_…`; `ProxySecret::$hidden` as the second guard | ✅ |
| AC62 configuration, not payload content | Re-derived alongside AC36 | ✅ |
| AC63 unsigned proxies byte-identical | Dedicated `OutboundHeadersSigningRegressionTest`, both never-enabled and enabled-then-disabled | ✅ |
| AC64 (narrowed) signing headers take precedence over forwarded | `test_ac64_…` proves the proxy's own headers win over inbound ones of the same name | ✅ |

**Out of Scope re-derived, not assumed.** Swept the diff for each named exclusion: no third
verification scheme, no IP allow-list, no mTLS, no value-pattern detection, no partial disclosure,
no per-field reveal, no new permission, no team-level list, no non-JSON field treatment, no header
display surface, no key-rotation tooling, no `destinations.url` cleanup, no per-destination signing
secret or toggle, no rejection analytics, no change to retention/GC/holds/retry/replay/processing
mode/FIFO, and no second payload read surface, export, download, share path, cache or archive.
**Amendment C's added exclusion holds too** — nothing reintroduces any part of inbound verification.

## Standards checklist

| Standard | Result |
|---|---|
| `coding.md` — never log a secret or payload value | **Pass.** Verified beyond the obvious sites: T46 closes the interpolated-SQL binding vector, and `SecretUnavailableException`'s message is fixed and value-free |
| `coding.md` — permission-based authorization, never role literals | **Pass.** Every new controller calls `authorize('update'\|'view', $proxy)`; no `TeamRole` literal anywhere in the diff |
| `coding.md` — one build point for outbound headers | **Pass.** `OutboundHeaders` has exactly one caller; no second copy of the strip list exists |
| `testing.md` — tests assert behaviour, not implementation | **Pass, and above the bar in places.** AC63's regression asserts identity against a real baseline rather than absence of a substring; T48's eager-load case exercises the guard rather than assuming the relation is unloaded |
| `testing.md` — a named regression gets its own identifiable test | **Pass.** AC37, AC63 and AC11's all-or-none each have a dedicated, separately-named file |
| `planning.md` — every task carries completion notes | **Pass.** All 44 live tasks; superseded and withdrawn tasks each carry a status note explaining what replaced them |
| `documentation.md` — retain history, never rewrite a ruling | **Pass.** Withdrawn ACs, screens and flows are all marked in place with their pre-amendment text quoted; superseded tasks are retained rather than deleted |
| `documentation.md` — comments accurate | **Two exceptions** — Findings 3 and 8 |
| Conventional Commits | **Pass.** 89 commits, all conforming, scoped `(item-10)` / `(adr-0NN)` / `(status)` |

## Summary

This is a large, disciplined feature and most of it is built to a higher standard than the criteria
require. Three things stand out as genuinely good engineering rather than merely correct
implementation. **AC29's cap of two is a write-path property enforced in one transaction**, so it
cannot be exceeded even briefly, rather than a schema constraint that would have to be reasoned
about. **AC11's all-or-none signing rule is implemented as a deferred exception** rather than a
throw at resolution — a throw would have killed the job before the `DeliveryAttempt` row existed
and left no visible failure at all, which is the outcome AC11 exists to forbid; T39 found that
nothing read the deferred field and that every destination would have dispatched silently
**unsigned**, and closed it. And **AC59/AC60's properties are structural**: the signature is
computed over the same variable that is dispatched, and the message id is derived from the
delivery's own natural key, so neither can drift.

The removal half (M11) is equally clean. I checked it as a removal — where the failure mode is a
survivor, not a defect — and swept for one. There is no executable inbound verification path left
anywhere, and the two classes most at risk of over-deletion (`StandardWebhooks`, retained as the
receiver-side test oracle; `ProxySecurityResource`, retained minus one key) both survived correctly.
`STRIPPED_HEADERS` is exactly the ten transport-scoped entries ADR-026 Decision A names, counted
against RFC 7230 §6.1 rather than against the completion note's claim.

**One Major.** `destinationCredentialAttributes()` returns a total no-op whenever
`credential_secret === ''`, so an edited credential **header name** is silently discarded unless the
member also replaces the secret — while `DestinationRows.vue` renders that field editable in exactly
that state, as design-10 Screen 3 requires ("visible + editable always"), and the save returns
`Changes saved.` The existing test resubmits the *same* header name, so nothing covers it. Verified
at runtime by invoking the method, not inferred from reading. It matters more after Amendment C
than it would have before: a member moving a destination off `Authorization` precisely to stop
colliding with the now-ordinary forwarded `Authorization` will believe they have done so and will
not have.

**Findings by severity: 0 Blockers · 1 Major · 4 Minors · 3 Nits.**

| # | Severity | Subject | Route to |
|---|---|---|---|
| 4 | **Major** | Edited credential header name silently discarded unless the secret is replaced | Senior Developer |
| 5 | Minor | Credential header named `WebhookProxy-*` emits a duplicate header, or loses the credential | Senior Developer |
| 6 | Minor | Signing card placed after the Destinations table, not before it | **Designer** |
| 8 | Minor | Two docblocks still name the emitted header `webhook-id` after T50's rename | Senior Developer |
| 9 | Minor | `status.md` records a design amendment as approved that the artifact says awaits its gate | **Product Manager** |
| 1 | Nit | JSON object with sequential numeric keys is walked as an array | — |
| 2 | Nit | Undecodable signing secret would sign under an empty key (unreachable today) | — |
| 3 | Nit | Secrets sweeper drops a scheduler guard its own comment says it reused | — |
| 7 | Nit | Screen 6 state 5 reachable only within one page session | — |

### Ruling on T49's open observation — the soft-deleted destination's retained ciphertext

**I agree it is acceptable as shipped, and I would go further: a purge on removal would be wrong,
not merely unrequired.** No follow-up is warranted.

T49 records the fact correctly — a soft-deleted `destinations` row retains its `credential_secret`
ciphertext exactly as it retains its URL and method, and no criterion or ruling asks for a purge.
The stronger point is that purging it would **conflict with AC32**: "the credential is presented on
every dispatch to that destination — the original attempt, every retry, and every replay."
`DeliveryUnitResolver` loads the destination `withTrashed()` precisely so an in-flight delivery
against a since-removed destination still completes, and `ProcessIngestedWebhook` selects only live
destinations for new work. Clearing the secret on soft-delete would therefore leave exactly one
class of request broken — a retry or replay of work that was already in flight — which is the
narrow case AC32 names explicitly.

The exposure is also correctly bounded on every axis this feature governs: the value stays
encrypted at rest under AC34, `SecretAbsenceSweepTest` covers the read surfaces, and
`ProxySecurityResource`'s deliberately `withTrashed()` map exposes only `has_credential` and a
timestamp, never a value or a length. The row is soft-deleted, not deleted; retaining its
configuration is the same decision already made for its URL and method, and #10 is not the item that
changes what soft-delete means.

If a hard-delete or destination-retention policy is ever introduced, the credential should be erased
by that pass — but there is no such pass today and therefore no seam to hang a follow-up on.
**Recorded as ruled, so it is not re-raised at the next review.**

## Recommendation

- **Recommendation:** **Request changes.** One Major (Finding 4) — a member-facing affordance the
  approved design spec requires, which the save silently discards while confirming success. Nothing
  else about this feature is in doubt: the gates reproduce exactly, every surviving acceptance
  criterion holds, the ADR-026 removal is complete, and the four Minors are all small and
  independent of one another.
- **Suggested disposition of the Minors, for the Owner's convenience rather than as a condition:**
  Findings 5 and 8 are cheap and sit in files Finding 4 already reopens, so bundling all three into
  one rework pass costs little. Finding 6 needs a Designer ruling before any code moves. Finding 9
  is paperwork the Owner can close alongside this review.
- **Project Owner decision / date:** _pending_

## Handoff

- **Inputs:** PRD-10 (Approved, Owner 2026-08-27, incl. Amendments A, B and C — Amendment C by the
  Product Manager, 2026-08-28); `design-10` (Approved as amended; the inbound-verification
  withdrawal amendment awaits Product Manager re-approval, see Finding 9); `plan-10` (fully
  approved, incl. `## Revision A`); `docs/tasks/sensitive-data-handling/` (index + `m01`–`m12`);
  ADR-021 … ADR-026, ADR-026 governing; `docs/standards/`.
- **Outputs:** this review.
- **Dependencies:** none new. No new Composer or npm package, no stack change. **Two data-model
  changes, both already Owner-approved**: T1's change set under `plan-10` Owner-approval flag 1, and
  T54's column drop plus secret deletion under ADR-026 Decision 4, whose own approval-flags section
  records none outstanding. Three new routes, all under the existing team-prefixed group and all
  gated on the existing proxy `update` permission — **no new `TeamPermission` case**, per AC20/AC28.
- **Outstanding questions:** Finding 6 → **Designer** (which half of Screen 4b's now-ambiguous
  placement clause governs, once Screen 4 is withdrawn). Finding 9 → **Product Manager** (close the
  delegated design gate on the withdrawal amendment).
- **Next agent:** **Senior Developer** for Finding 4, then re-review; the Project Owner decides
  whether the Minors are bundled into the same pass. On the Owner electing to accept Finding 4 as
  shipped instead, this becomes *Approve with follow-ups* and routes straight to approval — that
  call is the Owner's, not mine.
