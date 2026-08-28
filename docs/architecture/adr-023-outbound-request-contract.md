# ADR-023: The outbound request contract — added headers, precedence, per-proxy strips, and proxy-level Standard Webhooks signing (amends ADR-008)

> **Outbound signing here is PROXY-level, on a Project Owner ruling given directly on 2026-08-27,
> after PRD-10 was approved and after the design gate closed:** "*A proxy has one outgoing secret
> that can be used for all destinations. We can rotate so the header contains multiple secrets
> until one or more expires, but that is proxy level.*"
> **PRD-10 AC54–AC64 and `design-10` Screens 5 and 6 and Flows G–I are written as per-destination
> signing.** This ADR is written to the Owner's ruling, not to the stale text. Neither document is
> edited here; the conflict is routed to the Product Manager at
> **`docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`**.
> **The destination *credential* (AC30–AC39) is unaffected and stays per destination** — AC31 rules
> it per destination on grounds the signing ruling does not touch.

- **Status:** **Accepted — ratified by the Project Owner's approval of PRD-10 on 2026-08-27**, and
  written under the Owner's explicit delegation of the recording to the Principal Engineer. **Not
  an Owner-approval flag**, and the plan does not carry one for it. PRD-10 § *Consequences for
  approved documents* records the ratification and the delegation verbatim:
  > **ADR-008's "No header is added" ceases to be true — ACKNOWLEDGED (Project Owner,
  > 2026-08-27).** … **AC30 adds one, and Amendment A's AC55 adds more** — the signing headers — so
  > the reversal is now larger than it was when this section was first written, and it is no longer
  > conditional on anything: AC30–AC39 are approved. **Recording the reversal on the ADR remains
  > the Principal Engineer's**, per `docs/standards/documentation.md` — amend or supersede is their
  > call, not mine, and the Owner left it there.

  - **The three emitted signing header names are proposed for supersession by ADR-025**
    (`Proposed`, 2026-08-28, pending Project Owner approval): `webhook-id`, `webhook-timestamp` and
    `webhook-signature` become `WebhookProxy-Id`, `WebhookProxy-Timestamp` and `WebhookProxy-Signature`, under a
    branded, non-`X-` prefix, hard-coded at the single build point.
    **Only the names change.** The derivation, the per-attempt timestamp, the one-entry-per-live-secret
    signature list, the signed content, the algorithm, the encoding, the precedence rule and the
    single build point are all unchanged and remain operative. See the inline pointers at Decisions
    3, 4 and 5 below and ADR-025 § *Positions superseded*.

  **What the Owner ratified:** that this service now adds headers to an outbound dispatch — a
  credential (AC30–AC39) and the Standard Webhooks signing headers (AC54–AC64) — and that the
  verification headers are stripped (AC27). **What is Principal Engineer mechanism, self-certified
  under `CLAUDE.md`'s delegated plan gate:** the composition order, how precedence is implemented,
  how `webhook-id` is derived, and where in the code the single build point sits. That split is
  stated so a later reader does not treat the whole document as Owner-ruled.
- **Author:** Principal Engineer
- **Date:** 2026-08-27
- **Feature:** prd-10-sensitive-data-handling (AC17, AC27, AC30–AC39, AC43, AC54–AC64)
- **Relationship to ADR-008:** **amends** it — two named properties, neither of them the policy
  itself. See § *Positions amended*. **This is an amendment, not a supersession**: ADR-008's
  decision (a safe allowlist: forward everything except a stripped sensitive set) stands whole,
  Accepted and operative, and ADR-008's own Impact section forecast the larger half of what this
  ADR does.
- **Companions:** ADR-021 (how the credential and signing secrets are stored and rotated) ·
  ADR-022 (inbound verification, whose headers AC27 strips and whose signature primitive this
  reuses) · ADR-020 (by-reference delivery — the reason no secret reaches a queued job) ·
  ADR-015 (retry: why signing must sit in the send path, not the dispatch path) · ADR-017 (replay)
- **Normative source:** the **Standard Webhooks specification**, `standardwebhooks.com` (AC55 fixes
  outbound signing to the same scheme AC52 defines for inbound; the construction is stated once, in
  ADR-022 Decision 4, and is not restated here).

## Question

Since #1 the outbound request has been "the inbound headers minus a fixed strip list, and nothing
added" — `DeliveryUnit::forwardHeaders()`, whose docblock states plainly *"No header is added."*
PRD-10 makes three changes to that at once:

- **AC30–AC39** — a per-destination credential, a member-named header sent verbatim, on every
  dispatch to that destination and no other.
- **AC54–AC64, as re-grained by the Owner's ruling** — Standard Webhooks signing headers, off by
  default, on the original attempt, every retry and every replay, under **one signing secret per
  proxy shared by every destination that proxy dispatches to**.
- **AC27** — the proxy's *inbound* verification headers must be stripped, and under `shared-secret`
  the header name is **member-chosen**, so it cannot live in a constant.

Three questions follow that PRD-10 states as properties but does not answer as mechanism:

1. **Where is the outbound header set built**, given there are two delivery entry points
   (attempt 1's by-reference job and `RetryDelivery`'s attempts 2..N) and three dispatch origins
   (original, retry, replay), and AC32/AC60 require the additions on all of them?
2. **How is precedence implemented** (AC38, AC64) so that no combination of a forwarded inbound
   header and an added one can produce two headers of the same name, or let an inbound signature
   reach a destination as though it were ours?
3. **What is `webhook-id`**, given AC60 requires it to identify **the delivery** — stable across
   retries of that delivery, new on a replay?

## Positions amended

Exactly two properties of ADR-008, both stated in its Decision section, neither of them the
allowlist policy. **Both are amended, not superseded**: ADR-008 keeps its file, its Accepted status
and its full text, and gains an inline pointer at each.

| ADR-008 property | Verbatim | Now |
|---|---|---|
| **P1 — nothing is added** | "No signature or verification header is **added** by the proxy at #1 (that is #10 / V2)." | **Discharged as ADR-008 itself forecast, and widened once.** ADR-008's Impact already says "**Easier:** #10 attaches outbound signing/verification by *adding* headers after `forwardHeaders()`" — so the signing headers (AC55) are the anticipated extension arriving on schedule, not a reversal. What goes **beyond** the forecast is **AC30's credential header**: it is neither a signature nor a verification header, it is a member-named credential presented to the destination, and ADR-008 contemplated no such thing. |
| **P2 — the strip list is a constant** | "The stripped set is defined as a **maintained constant list** so #10 and later items can extend it without touching the fan-out logic." | **Narrowed.** The constant remains and is unchanged (see Decision 5). But AC27's strip is a function of *this proxy's* verification configuration — under `shared-secret` the name is member-chosen — so the effective strip set is now **the constant plus a per-request set resolved from the proxy**. ADR-008 anticipated the constant growing; it did not anticipate a dynamic component, and that is the property this ADR changes. |

One further property is not amended and is called out because it looks as though it should be:
ADR-008's "**Forwards everything else, including `Content-Type`**" stands verbatim. Precedence
(Decision 2) removes a forwarded header only when an added header of the same name exists, which is
a collision rule rather than a change to what is forwarded; a destination with no credential, on a
proxy with no verification and no signing secret, receives a **byte-identical** request to today's
(AC37, AC63).

## Decision

### (1) One build point: `App\Support\OutboundHeaders`, called from `DeliverToDestination::send()`.

The outbound header set is composed in exactly one place, in this order:

```
1. forwarded  = inbound headers  −  DeliveryUnit::STRIPPED_HEADERS   (ADR-008, unchanged)
2. forwarded −= the proxy's verification headers                     (AC27,  per PROXY)
3. added      = credential header (AC30,  per DESTINATION)
              + signing headers   (AC55,  per PROXY — one entry per live signing secret)
4. forwarded −= every name in `added`, matched case-insensitively    (AC38, AC64)
5. outbound   = forwarded + added
```

**Two of the three added-header inputs are proxy-level and one is destination-level**, which is why
`DeliveryUnitResolver` must load the proxy at all (Decision 8). Under the Owner's ruling the signing
secret is a property of the proxy, so every destination of a signing-enabled proxy is signed and
none of a proxy without one is — there is no per-destination signing state anywhere in this
contract.

Steps 1–2 are a property of the `DeliveryUnit` (the resolver knows the proxy); steps 3–5 happen in
the send path, because the signature must be computed over the **exact bytes about to be dispatched**
(AC59) and must carry **this attempt's** timestamp (AC60).

`DeliverToDestination::send()` is the single point every delivery reaches: attempt 1 arrives through
`asJob(deliveryId, attemptNumber)` and attempts 2..N through `RetryDelivery` → `run($unit)`, and both
resolve their unit through `DeliveryUnitResolver` (ADR-020 Decision 7). Building here therefore makes
AC32 ("every retry, and every replay") and AC60 ("the original, every retry, and every replay")
structural — there is no second path that could miss them. Building any earlier (in `DeliverStep`,
or in the resolver) would either put secrets into a queued job or compute a signature over a
timestamp that is not the attempt's.

### (2) Precedence is implemented by removal-then-addition, case-insensitively. Added headers always win.

Step 4 above is the whole of AC38 and AC64. HTTP header names are case-insensitive but PHP array
keys are not, so "add and hope" would emit *two* headers where a forwarded `authorization` meets an
added `Authorization`. The rule is therefore stated as a removal: every forwarded header whose
lowercased name matches a lowercased added name is dropped before the added set is merged.

This is generic and covers both criteria with one mechanism — AC38's credential-versus-forwarded
collision (which cannot arise in the default case, since ADR-008 already strips `authorization`, but
can the moment a member names their credential header something else) and AC64's rule that no
combination of settings lets an inbound `webhook-signature` reach a destination as though it were
ours.

### (3) `webhook-id` is derived, not stored: `msg_{delivery.dispatch_uuid}_{delivery.destination_id}`.

> **[Decision 3 — PROPOSED supersession by ADR-025 (`Proposed`, 2026-08-28, pending Owner approval),
> in the header name only.]** The header is emitted as **`WebhookProxy-Id`**, under a branded, non-`X-`
> prefix, hard-coded at the single build point. The derivation, its
> stability across retries, its newness on replay, its per-destination uniqueness and the reasoning
> for all three are unchanged, as is `webhook-timestamp` → **`WebhookProxy-Timestamp`** and its
> per-attempt semantics. The rename exists because a Svix-family sender puts the unprefixed names on
> an **inbound** request, and Decision 2's precedence rule would then silently destroy the sender's
> headers on the way out.

AC60 requires the id to **identify the delivery**: stable across every retry of that delivery, so a
deduplicating receiver treats a retry as the same message; and **new on a replay**, because a replay
is new work under PRD-06 and mints a fresh `dispatch_uuid` (ADR-017 Decision 1).

The pair `(dispatch_uuid, destination_id)` is exactly the `deliveries` row's natural key — it already
carries `UNIQUE(dispatch_uuid, destination_id)` — so the derivation is stable, unique per delivery,
and changes on replay for free. It needs **no new column**, which keeps #10's data-model gate to the
14 columns ADR-021 defines. The `msg_` prefix follows the specification's own convention; the
separator is an underscore rather than a dot so the id never contains the character the signed
string uses between its three parts.

`webhook-timestamp` is **the moment of this attempt**, taken in `send()` — not the original
dispatch's time. AC60 states the reason: a retry arriving hours later under the original timestamp
would fall outside its receiver's replay window and be correctly rejected.

**The message id stays per delivery even though the secret is per proxy**, and the two must not be
conflated: the secret answers "who is this from", the id answers "which message is this". Two
destinations of one dispatch receive different `webhook-id`s signed with the same key, which is
correct — they are different messages to different receivers, and AC60's deduplication guarantee is
about a *delivery*, not about a dispatch.

### (4) Signing composes with rotation by carrying one signature entry per live secret (AC58).

> **[Decision 4 — PROPOSED supersession by ADR-025 (`Proposed`, 2026-08-28, pending Owner approval),
> in the header name only.]** The list is emitted as **`WebhookProxy-Signature`**. Its value format is
> unchanged — space-delimited `v1,<base64>` entries, one per live signing secret, current first — as
> are the signed content, the shared primitive, the secret generation and the one-time display. A
> recipient using a Standard Webhooks library with configurable header names verifies exactly as
> this decision describes.

`webhook-signature` carries **one space-delimited `v1,<base64>` entry for each member of the
proxy's live signing set** — `expires_at IS NULL OR expires_at > NOW()`, current first (ADR-021
Decision 2) — each computed over the same signed content with that member's key. Outside a rotation
that is one entry; during one it is two under AC29's current cap, and the loop assumes no number, so
if the Product Manager ever raises the cap (`Q-10-04` item 2) nothing here changes. This is the
Owner's "the header contains multiple secrets until one or more expires", and the specification's
signature value is a list for exactly this purpose, so it asks nothing of the receiver beyond the
specification it already implements.

The construction, encoding, `whsec_` key handling and constant-time comparison are ADR-022 Decision
4's and are shared with inbound through one primitive (`App\Support\StandardWebhooks`) — AC55's
"one implementation serves both directions" is structural, not a convention.

**The product generates the signing secret** (AC56) as 32 random bytes, base64-encoded, prefixed
`whsec_` — the specification's own secret format, so a receiver using any conforming library works
without instructions. It is displayed exactly once (AC57, ADR-021 Decision 6.3). **One secret per
proxy**: enabling signing generates it once for the proxy, and every destination of that proxy is
signed with it from that moment, including destinations added later.

**A member configuring receivers therefore configures them all with the same secret.** That is the
Owner's ruling and it is worth naming as a consequence rather than leaving it implicit: it makes a
proxy's fan-out one trust domain, so a destination operator who holds the secret can verify — and
forge — traffic addressed to any of that proxy's other destinations. The per-destination model
PRD-10 currently describes would not have that property. Recorded in `Q-10-04` so the Product
Manager rules on it with the trade-off visible rather than inheriting it.

### (5) `DeliveryUnit::STRIPPED_HEADERS` is **not** extended with the three `webhook-*` names.

> **[Decision 5 — PROPOSED amendment by ADR-025 (`Proposed`, 2026-08-28, pending Owner approval).
> Not a supersession.]** This decision's reasoning stands unchanged and now applies to the renamed
> `WebhookProxy-*` trio: this service's own outbound header names never go into the constant. Two things
> ADR-025 adds that this decision did not contemplate. First, ADR-025 Decision 1 **removes** five
> names from that constant — the provider signature headers — so the constant becomes a
> transport-scoped and credential-to-us deny-list rather than one that also covers verification
> artefacts. Second, the renamed trio makes the collision this decision reasons about impossible
> rather than merely resolved: no sender sends `WebhookProxy-*`.

This looks like the obvious tidy and it is wrong. Adding them to the constant would strip
`webhook-id`/`webhook-timestamp`/`webhook-signature` from the outbound set of **every** destination,
including every destination of a proxy that does not sign at all — which changes what an untouched
destination receives and breaks AC63's byte-identical guarantee. The three names are handled by precedence (Decision 2) and
only when we are actually adding them. The constant's five inbound-signature entries
(`stripe-signature`, `x-hub-signature`, `x-hub-signature-256`, `x-signature`,
`x-webhook-signature`) are unchanged; AC43 confirms inbound forwarding is otherwise untouched.

### (6) Nothing here changes the dispatched bytes.

AC17 and AC59 both bind it from different directions: obfuscation is a display property and never
alters what is delivered, and signing "adds headers and alters nothing else". `OutboundHeaders`
returns headers; the body passed to `Http::send()` is `$unit->payload` verbatim, unchanged from
today. A dispatch whose bytes differ because a field was marked sensitive, or because signing was
enabled, is a defect against AC17 and AC59 respectively — and, for signing, would be
self-defeating, since the signature is computed over those same bytes.

### (7) Credentials and signing secrets never reach a queued job, a failure record, an attempt row or a log line.

Structural, and only because ADR-020 got there first: the delivery job carries
`(deliveryId, attemptNumber)`, the unit is resolved on the worker, and the header set is built
inside `send()`. AC35 and AC61 therefore need no mechanism of their own. A `DeliveryAttempt` row
stays payload-free and now also credential-free and signature-secret-free (ADR-003 unchanged); the
**signature itself is not a secret and may be recorded** (AC61), though #10 records none. See
ADR-021 Decision 8 for what re-checks are owed if the delivery job's arguments ever change again.

### (8) A destination soft-deleted after its delivery row was created still carries its credential, and a soft-deleted proxy still signs.

`DeliveryUnitResolver` already loads `$delivery->destination()->withTrashed()` (plan-06 ruling 2), so
the credential columns arrive with the row. The resolver must **also** load the **proxy** — now for
two reasons, AC27's verification-header strip and the proxy's live signing set — and must do so
`withTrashed()`: `Delivery::proxy()` is a plain `belongsTo` while `Proxy` uses `SoftDeletes`, so a
soft-deleted proxy returns `null` and blows up at runtime, and PHPStan cannot see it
(`@property-read Proxy $proxy`). `ProcessIngestedWebhook` and
`DeliverToDestination::settleDelivery()` are the existing precedents.

## Alternatives

- **Supersede ADR-008 with a new policy ADR.** Rejected: ADR-008's decision — the safe allowlist —
  is unchanged and is still the right policy. `docs/standards/documentation.md` reserves a
  superseding ADR for a change that *reverses or replaces* a ratified decision; two named properties
  moving is an amendment, and calling it a supersession would orphan a decision every subsequent
  plan cites.
- **Add the three `webhook-*` names to `STRIPPED_HEADERS`.** Rejected: it changes what unsigned
  destinations receive and breaks AC63. See Decision 5.
- **A new `deliveries.webhook_message_id` UUID column** as `webhook-id`. Rejected: it adds a column
  to a data-model gate that should stay minimal, and a value the row's existing natural key already
  determines. Revisit only if a later item needs a message id that is *not* derivable — e.g. one
  that must survive a change to how deliveries are keyed.
- **`deliveries.id` as `webhook-id`.** Rejected: a globally sequential integer handed to every
  third-party receiver discloses total delivery volume and its rate.
- **`dispatch_uuid` alone as `webhook-id`.** Rejected: two destinations of the same dispatch would
  receive the same message id, so a party operating two of a proxy's destinations would dedupe one
  delivery away — the exact behaviour AC60 relies on the id to get right.
- **Build the header set in `DeliveryUnitResolver` and carry it on the `DeliveryUnit`.** Rejected:
  the signature must carry this attempt's timestamp, and a unit built once and reused for a resumed
  attempt would carry a stale one. It would also put the secret on a longer-lived object for no gain.
- **Sign in `DeliverStep` and pass the headers through the job.** Rejected outright: it puts a
  signature — and the timestamp it is bound to — into the queue, and reopens the exposure ADR-020
  Decision 7 closed.
- **Let a forwarded inbound header win over an added one** (the inverse precedence). Rejected by
  AC38 and AC64, and it would let a sender control what we present to a destination.
- **Sign only the original attempt, not retries.** Rejected by AC60; a receiver that requires
  signatures would reject every retry, turning the retry engine into a guaranteed-failure machine
  for exactly the destinations that care most.
- **A signing secret per destination**, as PRD-10 AC54–AC64 and `design-10` currently describe.
  **Not rejected on merit here — displaced by the Project Owner's direct ruling of 2026-08-27**, and
  routed to the Product Manager at `Q-10-04` rather than settled by this ADR. Its one substantive
  advantage is recorded at Decision 4 so the amendment is ruled with it visible: per destination,
  one destination operator's secret cannot verify or forge traffic addressed to another of the same
  proxy's destinations.

## Reasoning

- **The "reversal" is smaller than it reads, and saying so is the honest record.** ADR-008 named #10
  as the item that would add headers and told it where to do it. Writing this ADR as though a
  ratified position had been overturned would misdescribe the history; writing it as though nothing
  changed would hide the two properties that genuinely did. Enumerating both is what makes the
  amendment checkable.
- **One build point is what makes six criteria structural instead of six things to remember**
  (AC27, AC32, AC38, AC59, AC60, AC64). The alternative — adding headers at each call site — is
  precisely the shape that produces "signed on the original but not on the retry".
- **Precedence-by-removal is stated as a rule about names because the bug it prevents is about
  case.** `Http::withHeaders()` takes a PHP array; nothing in the framework will notice that
  `authorization` and `Authorization` are the same header.
- **Deriving `webhook-id` from the delivery's natural key is the cheapest thing that satisfies AC60
  exactly**, and it keeps the Owner's data-model gate to the columns that genuinely have nowhere
  else to live.

## Impact

- **Easier:** a later outbound header — a per-destination custom header, a trace id, a second
  credential — has one place to go and one precedence rule to inherit. #12/#13 gain nothing to
  re-decide.
- **Data-model:** none of its own. `destinations.credential_*` and the `proxy_secrets` rows of
  purpose `signing` are ADR-021's and are gated in `plan-10` § *Data Model*. **No new column for
  `webhook-id`**, and **no signing column on `destinations`** — the Owner's ruling removes the need
  for one entirely.
- **Constrained:**
  - **`OutboundHeaders` is the only place an outbound header set is built.** A second build site is
    a review finding.
  - **`DeliveryUnit::STRIPPED_HEADERS` stays a constant of inbound-signature and transport names
    only** — a per-proxy name never goes in it.
  - **The delivery job's arguments must stay scalar** (ADR-020 Decision 9, extended by ADR-021
    Decision 7 to secrets).
  - **The proxy must be loaded `withTrashed()`** wherever the resolver reaches for it (Decision 8).
  - **Nothing may alter the dispatched body** (Decision 6).
- **Carried forward from ADR-008 unchanged:** the strip list remains a security control that must be
  kept current as new providers and verification schemes are onboarded, and header matching stays
  case-insensitive everywhere.
- **Within stack:** Laravel's HTTP client, PHP's `hash_hmac`/`base64_encode`/`random_bytes`.
  **No new dependency, no stack change.**
