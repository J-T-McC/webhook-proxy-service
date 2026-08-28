# PRD: Sensitive data handling

- **Status:** **APPROVED by the Project Owner on 2026-08-27**, as amended — **`## Amendment A` and
  `## Amendment B` both approved**, Amendment B separately and later the same day. The Owner read the
  document and approved it whole, which ratifies Amendment A and everything enumerated below; the
  three items are retained as a record of what the approval covered, not as open questions. The
  three Project Owner rulings that Amendment A carries were each ratified by that approval:
  1. **§ Roadmap Open Question V2 — settled here.** The earlier shared-secret-only ruling was
     **overturned by the Project Owner on 2026-08-27** and replaced with a closed, named
     two-scheme list. Approving this PRD ratifies the replacement.
  2. **§ Outbound request signing (AC54–AC64)** — a new capability added on the Owner's direct
     ruling of 2026-08-27, and a **roadmap widening** of the same class Q-10-01 carried.
     Approving this PRD ratifies it.
  3. **§ Consequences for approved documents** — this PRD **narrows PRD-06 AC25** and **narrows a
     stated property of ADR-008**. Both are named there rather than applied silently. Both were
     **accepted as recorded** by the Owner on 2026-08-27.
- **Settled, no longer at issue:** **§ Outbound destination authentication (AC30–AC39) is
  APPROVED** (Project Owner, 2026-08-27). It is **no longer severable**, and
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md` is **RESOLVED**.
- **`## Amendment B` — AWAITING PROJECT OWNER APPROVAL (written 2026-08-27). It is not approved and
  must not be treated as approved.** It carries the Project Owner's ruling of 2026-08-27 that
  **outbound signing is per proxy, not per destination**, and the Product Manager's ruling that
  **AC29's cap of two live secrets stands**. It revises § Definitions, § Feature item 5,
  § UX Direction point 5, **AC10, AC11, AC29, AC44, AC54, AC58, AC60 and AC63**, and adds one
  § Out of Scope bullet. **Nothing is renumbered.** Every criterion it touches is marked
  `(Amendment B — …)` in place and its pre-amendment text is quoted in that section, so the record
  survives whichever way the Owner rules. **The rest of this document remains approved exactly as it
  stood on 2026-08-27**, including `## Amendment A`, which Amendment B does not reopen.
- **Author:** Product Manager
- **Date:** 2026-08-27 (Amendment A, 2026-08-27; Amendment B, 2026-08-27 — **not yet approved**)
- **Approved by / date:** —
- **Backlog item:** Roadmap #10 (`docs/product/roadmap.md`). Depends on **#5 (Done)**.
- **Build-ahead status:** written against shipped code only. #1, #3, #4, #5, #6, #7 and #11 are
  merged; **#8 is Owner-deferred with zero implementation** and **#9 has not started**, so no
  criterion here depends on either. Where their absence bounds what #10 can promise, the bound is
  stated as a criterion (AC22, AC48) rather than left to be discovered.
- **Next gate: the Designer.** `## UX Direction` is present, so a PM-approved `design-10` is a
  prerequisite for Technical Design.

## Feature
Three protections over the data this service already holds and receives, plus two outbound
capabilities the Project Owner ruled into this item on 2026-08-27:

1. **Encryption at rest is guaranteed as a property of the system**, not as a property of three
   named columns: no durable at-rest copy of payload content exists anywhere in plaintext,
   wherever the dispatch mechanism puts it.
2. **Known and user-defined sensitive fields are visually obfuscated** wherever payload content is
   shown, without altering what is stored or what is delivered.
3. **An incoming webhook can be verified before it is accepted**, under one of **two named
   schemes** — the published **Standard Webhooks** specification, or a plain **shared secret** in a
   member-named header.
4. **A destination can carry a credential** this service presents when it dispatches, so a secret
   no longer has to be smuggled through `destinations.url`. *(Q-10-01, approved.)*
5. **A destination can verify that a dispatch came from us**, because this service signs it under
   the same Standard Webhooks scheme it can verify inbound. This is the reverse direction of (4)
   and independent of it. **(Amendment B.)** The secret it verifies against is the **proxy's**: one
   outgoing signing secret, used for every destination that proxy dispatches to.

## Definitions
Fixed vocabulary. Every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Payload content** | PRD-05 AC6's definition, unchanged: an event's raw body, its captured inbound headers, and any stored dispatched output body for the same event. Not the non-content descriptors AC6 permits to survive erasure (method, content type, byte size, times, identifiers, ownership). |
| **Durable at-rest copy** | A copy of payload content that survives the process that wrote it **and** is retained under a policy rather than for the life of one unit of work. ADR-020 § Impact fixed this distinction and the Project Owner refined it on 2026-08-26: duration is part of the definition, so a store whose entries expire in minutes to an hour is short-term. **The threshold in between is unset and is the Owner's** — see AC9. |
| **The at-rest floor** | PRD-05 AC15's protection floor: at least the encryption the raw body has carried since #3 (ADR-010 Amendment B), extended to captured headers by PRD-05 AC22 and implemented across three columns by ADR-014. |
| **Obfuscated** | Rendered so that no part of the value and no information about its length is disclosed. A display property only — see AC17. Distinct from **redacted**, which would alter stored or delivered data and which #10 does not do anywhere. |
| **Sensitive field** | A field of a stored payload whose **name** appears either in the product's default list (AC12) or in the proxy's member-maintained additions (AC13). Matching is by name, never by value (AC14). |
| **Verification scheme** | The named rule by which an incoming request is verified. The list is **closed**: `standard-webhooks` or `shared-secret` (AC23). Not free-form configuration. |
| **Verification secret** | The per-proxy secret an incoming request is verified against (AC23). **Issued by the upstream provider and stored by us — never generated by us** (AC26). **Not** the ingest URL's own path token, which is ADR-006's and is untouched by this PRD. |
| **Destination credential** | The per-destination secret this service presents when dispatching (AC30). Configuration, not payload content — so retention never touches it (AC36). |
| **Proxy signing secret** *(Amendment B — renamed from **Destination signing secret** and re-grained. Project Owner ruling, 2026-08-27.)* | The **per-proxy** secret this service **generates** and signs its dispatches with (AC56), so a destination can verify us. **One secret per proxy, used for every destination that proxy dispatches to** (AC54). The only secret in this feature the product owns, and therefore the only one that can be regenerated. Distinct from the destination credential, which stays **per destination** (AC31): one authenticates us to them, the other lets them verify us, and a destination may have neither, either or both (AC54). **The old term is retained nowhere in the criteria; a reader who finds "destination signing secret" in an older document — `design-10`, ADR-021's early text, or `Q-10-04` — is reading the pre-amendment name for this.** |
| **Rotation overlap** | The bounded period during which a replaced secret is still honoured alongside its replacement (AC29). Applies in both directions. |

## Problem
Five gaps, each traceable to a document rather than asserted.

1. **"Stored payloads are encrypted" is true of three columns, not of the system.** ADR-014 put
   `webhook_events.body`, `webhook_events.headers` and `dispatched_payloads.body` behind the
   at-rest floor. Nothing states the property in a form that a later change could be tested
   against. Deferred concern **D2** (PRD-05 Amendment B) exists precisely because that gap once
   held a real plaintext copy, and it gates this PRD. **ADR-020 has since closed the instance —
   see § D2 — which changes this from a defect to fix into a property to pin.**
2. **Payload content is now viewable, and nothing distinguishes a password from a line item.**
   #6 shipped the first user-facing egress of payload content (ADR-017): one endpoint, masked by
   default, with a whole-payload reveal that exposes everything. PRD-06 AC22 records that the
   whole of field-level handling was left to #10.
3. **Anyone who learns an ingest URL can post to it.** ADR-006 makes the URL unguessable, and
   that is the entire inbound authentication story today. The roadmap's "incoming webhooks can be
   verified with a token" has been gated on **V2** since the vision, and V2 has never been
   answered.
4. **A secret can only reach a destination inside its URL.** `destinations`
   (`database/migrations/2026_07_30_000002_create_destinations_table.php`) carries `proxy_id`,
   `team_id`, `url`, `http_method` and timestamps, and no later migration adds a credential
   column. `docs/plans/plan-08-payload-mapping.md` line 419 notes the consequence in passing:
   `destinations.url` "may carry a token in its query string". So the capability is not absent in
   practice — it is absent as a *designed* capability, and the workaround puts the secret in
   plaintext in the database and in every surface that renders a destination URL. **This is a
   current fact about the system, not something #10 introduces**, and #10 does not clean it up
   (AC39).
5. **A destination has no way to tell that a dispatch came from us.** Gap 4 is about us proving
   ourselves *to* a destination's own authentication; this is the reverse row and it is empty in
   every document. `DeliveryUnit::outboundHeaders()` adds no header of any kind, so a destination
   operator receiving one of our dispatches has nothing to verify against — the request is
   indistinguishable from any other POST to a URL that anyone who learns it can call. The product
   asks its own upstream senders to authenticate themselves (gap 3) while offering its own
   receivers nothing. **Identified by the Project Owner, 2026-08-27.**

## What earlier items already delivered vs. what #10 adds
Recorded so scope is unambiguous. Three items moved this boundary after #10's roadmap line was
written, and two of them moved it substantially.

| Concern | Owner | State |
|---|---|---|
| Raw body encrypted at rest | #3 (ADR-010 Amendment B) | **Done.** #10 preserves it and adds no second store |
| Captured headers encrypted at rest, and erased on expiry | **#5** (PRD-05 Amendment A, AC22; ADR-014) | **Done — this slice of #10 was pulled forward.** #10 keeps the header *policy* (AC40–AC42) |
| Dispatched output encrypted at rest | #5 (ADR-013, ADR-014) | **Done** |
| `APP_PREVIOUS_KEYS` guard binding across the encrypted columns | #3 (ADR-010 Amendment B), widened by ADR-014 | **Done.** #10 widens it again if it adds secrets (AC10) |
| Payload content in queue and failed-job stores | carried to #10 as **D2** | **Instance closed by ADR-020 (`bd0e67d`, 2026-08-27).** #10 discharges D2 as a **stated property** — see § D2 |
| Whole-payload mask + explicit reveal on the one content endpoint | #6 (AC25, ADR-017) | **Done.** #10 layers field obfuscation onto it and **narrows** what "reveal" exposes (AC18) |
| Payload-free attempt records | #1 (ADR-003) | **Done.** #10 must not put secrets into them (AC35) |
| Ingest URL unguessability | #1 (ADR-006) | **Done and untouched.** The verification secret is a second, independent factor |
| **Field-level obfuscation** | **#10** | **This PRD** |
| **Inbound verification, two named schemes (V2)** | **#10** | **This PRD**, as replaced by Amendment A |
| **System-wide at-rest guarantee (D2)** | **#10** | **This PRD** |
| **Outbound destination authentication** | **#10** | **This PRD.** Q-10-01 **approved** by the Owner, 2026-08-27 — no longer severable |
| **Outbound request signing** | **#10** | **This PRD**, added by Amendment A on the Owner's ruling |
| Key rotation / re-encryption tooling; per-team key policy | assigned to #10 by PRD-05 § Out of Scope | **Deferred out of #10 with a named cost** — AC44, AC45 |

## Goals
- The at-rest guarantee is expressed so that a **future** change cannot silently break it: stated
  over "any durable at-rest copy, wherever the dispatch mechanism places it", never over a table
  name (roadmap **V3** may change the queue backend).
- A member can see a stored payload for debugging **without** the secrets inside it being
  displayed, and can extend the hidden set for their own proxy.
- Obfuscation never changes what a destination receives. A protection that alters delivered data
  would be a defect, not a feature.
- A proxy owner can require that an incoming webhook prove it is from the expected sender, under a
  **closed, named list of schemes** — bounded work that is complete at MVP, rather than the
  per-vendor programme that free-form configuration would become.
- A destination operator can verify that a dispatch came from this service, using the same
  published specification the product verifies inbound, so one implementation serves both
  directions.
- Every secret this feature introduces carries the same at-rest floor as payload content and is
  never displayed after it is saved — with exactly one deliberate exception, the one-time display
  of a secret the product itself generates (AC57).
- A secret can be rotated without a synchronised cutover, in both directions, because a bounded
  overlap is honoured (AC29).
- Nothing here depends on #8 or #9, and nothing here pre-empts them.

## Users
- **Team member** — configures obfuscation and verification for their proxies; sees obfuscated
  payloads; is the one debugging when a sender is rejected.
- **Team Owner / Admin** — same, without the Member ownership limit on configuration changes
  (Q-02-01 / ADR-009 Amendment A2.2).
- **Upstream sender** — a third-party system that must now present the verification secret if the
  proxy requires one, and is rejected if it does not.
- **Destination** — receives an additional header it can authenticate the request with (AC30), and
  can verify the request's signature to establish that this service sent it (AC54).
- **The product (system)** — holds the secrets, applies obfuscation at the point content leaves
  the server, and must keep both out of jobs, attempt records, analytics and logs.

## User Stories
- As a team member, I want passwords, tokens and card numbers inside a stored payload hidden when
  I look at it, so debugging a delivery does not put a customer's secret on my screen.
- As a team member, I want to add my own field names to that hidden set for my proxy, because the
  product cannot know that my vendor calls it `ssn_last4`.
- As a team member, I want obfuscation to be a display decision only, so my destinations keep
  receiving the payload exactly as they do today.
- As a team member, I want to require verification on my ingest URL, so knowing the URL is not by
  itself enough to post to my proxy.
- As a team member whose sender already speaks Standard Webhooks, I want to point the product at
  the secret that sender issued me and have it verified as that specification says, rather than
  weakening my sender to a plain shared secret to fit this product.
- As a team member, I want to rotate a secret without coordinating the exact moment with the other
  side, because the old one keeps working for a stated period.
- As a team member, I want a rejected request to be rejected *before* anything is stored, so a
  request that failed authentication never becomes an event in my history.
- As a team member, I want every secret I save to be unreadable afterwards — by me included — so
  a compromised session cannot harvest them.
- As a team member, I want to give a destination a credential, so I stop having to paste it into
  the destination URL where it sits in plaintext.
- As a team member, I want my destination to be able to prove that a webhook it received came from
  my proxy and not from anyone who guessed its URL, so my receiver can reject everything else.
- As a team member, I want to see the signing secret **once**, when the product generates it, so I
  can configure my receiver with it — and never again afterwards.
- As the product, I want the at-rest guarantee stated as a testable property, so a future queue
  backend or a future pipeline step cannot reintroduce a plaintext copy without a criterion
  failing.

## UX Direction
Direction only. Screens, states, components and copy belong to the Designer (`design-10`).

**The primary flow is unchanged and must stay unchanged.** A member opens a proxy's received
events, opens one, and reveals its payload. #6 built that path and #10 does not rebuild it — it
changes what the reveal shows. Obfuscation must feel like a property of the data, not a mode the
member has to enter.

**What the experience optimises for, in priority order.**

1. **A secret is never on screen by accident.** Obfuscation is on by default for every proxy from
   the moment the feature ships, including proxies and payloads that already exist (AC19). There
   is no "enable obfuscation" toggle to forget.
2. **The member can always tell that something was hidden, and why.** An obfuscated value must
   read as *deliberately hidden by a rule you can inspect*, never as empty, missing, corrupt, or
   cleaned. Three states already have to be distinguishable on this surface — retained, cleaned,
   never captured (PRD-05 AC21, PRD-06 AC16) — and obfuscated is a fourth thing that must not be
   confusable with any of them, particularly **cleaned**.
3. **Editing the sensitive-field list is where the member spends thought, so it belongs with the
   proxy's configuration**, not buried in the viewer. The member must be able to see the product's
   default list and their own additions together, because the question they are actually asking is
   "is `card_number` already covered, or do I need to add it?"
4. **Secrets are write-only, and the interface has to make that legible rather than surprising.**
   Every secret a member *supplies* — the inbound verification secret, the destination credential —
   behaves the same way: enter once, see *set* and when it changed, never see the value again,
   replace at will. A member who expects to be able to check what they typed will be wrong, so the
   interface has to say so **before** they save, not after.
5. **(Amendment B — revised: grain.) The one secret the product generates is the one exception, and
   it gets exactly one chance to be read.** The **proxy's** signing secret (AC56) is displayed
   **once**, at generation, because the member has to configure their receivers with it and nobody
   else can tell them what it is. The experience must optimise for *the member actually captures it
   before leaving the screen* — this is the one place in the feature where a member losing a value
   costs them a regeneration and **the reconfiguration of every receiver that proxy dispatches to**,
   which the proxy grain makes a larger loss than the per-destination model would have. After that
   screen it behaves like every other secret here.
6. **Choosing a verification scheme is a two-option decision and must not read as a protocol
   menu.** The list is closed (AC23) and stays closed. The member's real question is "does my sender
   already speak Standard Webhooks, or do I just need a shared secret", so the choice should be
   framed by *what the sender does*, not by algorithm names.
7. **A rejected inbound request is the hardest thing to debug in this feature, and the interface
   should not pretend otherwise.** #10 ships no analytics or notification surface for rejections
   (AC46), which is a real cost, and the `standard-webhooks` scheme has more ways to fail than a
   shared secret does — wrong secret, stale timestamp, malformed header set. The configuration
   surface should therefore be explicit about exactly what the sender must send under the chosen
   scheme, so a member can check their sender against it without needing a log.
8. **Rotation has to be legible as a period, not an event.** While an overlap is running (AC29) the
   member must be able to see that two secrets are currently honoured and when the older one stops
   being honoured. A rotation that looks instantaneous when it is not will produce members who
   update their sender late and never learn that they were covered.

**Not the Designer's to decide, because they are ruled here:** that obfuscation survives the
whole-payload reveal (AC18); that there is no per-field reveal for anyone (AC20); that no
member-supplied secret is ever redisplayed (AC26, AC33); that the generated signing secret is
displayed exactly once and never again (AC57); that the verification scheme list is closed to two
values (AC23); that the rotation overlap is a fixed 24 hours and not a member setting (AC29); and
that obfuscation shows nothing about a value's length (AC16). **Added by Amendment B:** that
signing is **per proxy** — one secret for every destination that proxy dispatches to, with no
per-destination enable, secret or rotation state (AC54); and that **at most two secrets are live
for one purpose on one proxy** (AC29), so a rotation surface renders one current secret and at most
one expiring one, never a list of unknown length.

## Acceptance Criteria

> **Numbering is append-only** and follows the house rule set by PRD-05 and PRD-11 — a number
> identifies a criterion, it does not mark a position, so **AC51–AC64 (added by Amendment A) sit in
> the section they belong to rather than at the end**. This is PRD-05's idiom, where AC21 and AC22
> were added in place. **Nothing is renumbered**, because Q-10-01, `docs/status.md` and this
> document's own cross-references cite these numbers.
>
> **AC30–AC39 are no longer severable.** The Project Owner approved the Q-10-01 merge on
> 2026-08-27. **AC23–AC29 were revised in place** and **AC29 was replaced outright** by Amendment
> A; the pre-amendment text of both is quoted in that section rather than lost.
>
> **`## Amendment B` (awaiting Owner approval) revises AC10, AC11, AC29, AC44, AC54, AC58, AC60 and
> AC63 in place and adds no criterion — the count is still 64 and nothing is renumbered.** Each is
> marked `(Amendment B — …)` where it starts, and the pre-amendment text of **AC54** and **AC63**,
> the two whose meaning changes rather than whose wording does, is quoted beneath them.

### Encryption at rest — discharging D2

1. **The at-rest guarantee is stated over copies, not over tables.** Every **durable at-rest copy**
   of **payload content** the system creates carries at least **the at-rest floor** — wherever the
   dispatch mechanism, the queue backend, the framework or any scheduled process places it. No
   criterion in this PRD may be satisfied by naming a table; a change of queue backend (roadmap
   **V3**) must leave this criterion meaning exactly what it means today.
2. **No durable plaintext copy of payload content exists.** This is the stronger of the two
   readings D2 offered ("or that no durable plaintext copy exists at all — whichever the Owner
   rules at #10") and it is the one the system already satisfies as of ADR-020, so #10 ratifies
   the state reached rather than requiring new work. Consequently **no durable plaintext copy can
   outlive its event's retention window**, because none exists to outlive it.
3. **The set of stores holding payload content is closed and enumerated.** Payload content exists
   at rest in exactly the two stores the retention system governs — the captured event and the
   stored dispatched output — and nowhere else. **#10 adds no third payload store, no cache of
   payload content, no export, no archive and no telemetry copy.**
4. **A queued or executing unit of work carries no payload content in its own arguments**, in
   either processing mode, for an original dispatch, a retry or a replay. Stated as an observable
   requirement rather than as a restatement of ADR-020 Decision 7: the mechanism is the Principal
   Engineer's, the property is #10's.
5. **A failure record carries no payload content.** Whatever the dispatch mechanism durably
   records when a unit of work fails — its arguments, its exception, and any operator-facing
   rendering of either — contains no payload content. **Failed-job infrastructure is a separate
   surface from the queue body and is asserted separately here for that reason.**
6. **Failure diagnosability is preserved** (D2 item 4). An operator can still see that a delivery
   failed, which delivery it was, and why. No criterion in this PRD may be satisfied by removing
   or degrading failure information.
7. **The at-least-once and idempotency behaviour established at #4 and #6 is not weakened**
   (D2 item 4; ADR-011 Decision 4, ADR-015 Decision 2). #10 changes no dispatch guarantee.
8. **Payload content never reaches logs** (D2 item 4; `docs/standards/coding.md` → *Never log*).
   This includes any diagnostic added in service of this feature. A log line may name identifiers
   and rule names; it may never name a field's value, and it may never name a value that was
   obfuscated.
9. **A short-term store is out of scope, and the boundary is the Owner's to set.** AC1–AC3 bind
   **durable** copies as defined above. Nothing in #10 requires at-rest encryption of a store whose
   entries live for the duration of one unit of work, and **#10 sets no duration threshold** — the
   Project Owner deferred that explicitly on 2026-08-26 and reserved the number for the point at
   which a concrete short-lived store is actually proposed. **What #10 does require:** if such a
   store is ever proposed, whether it falls inside AC1 is a **requirements** question, not a design
   choice made at build time.
10. **(Amendment B — revised: one term renamed, no substance changed.) The key-lifecycle rule
    extends to every secret this feature introduces.** ADR-010
    Amendment B's binding `APP_PREVIOUS_KEYS` rule — a prior key is never dropped until every
    encrypted value has been re-encrypted under the current one — covers the verification secret
    (AC26), the destination credential (AC33) and the **proxy signing secret** (AC57) exactly as
    it covers payload content, **including any secret currently held only as a rotation overlap
    (AC29)**. **This widens the key-lifecycle surface, and AC44 states the cost of widening it
    without shipping rotation tooling.** *(Two distinct rotations are in play and must not be
    conflated: the application encryption key, which #10 does not rotate at all, and a member's or
    the product's secret under AC29, which #10 does.)*
11. **(Amendment B — revised: the signing clause is re-grained.) Losing a secret fails loudly, never
    silently.** If a secret cannot be decrypted, the
    affected operation fails visibly rather than proceeding as though no secret were configured.
    A proxy with an undecryptable verification secret must not fall back to accepting unverified
    requests; a destination with an undecryptable credential must not fall back to dispatching
    without it; and **a proxy whose signing secret cannot be decrypted must not fall back to
    dispatching unsigned to any of its destinations**. *(This is the class of failure ADR-014
    Decision 7 guards for payload
    content; it is asserted here for secrets because the failure mode is silent authentication
    bypass — and, for signing, a receiver that correctly rejects unsigned traffic seeing it arrive
    unsigned.)*

### Field obfuscation

12. **A product-defined default list of sensitive field names ships with the feature and is
    visible to the member.** It contains at minimum the three properties the vision names —
    **password**, **token**, **credit card** — and their common spellings and separators. The list
    is **fixed at MVP**: a member may add to it (AC13) but may not remove from it or edit it. It
    must be **displayed** to the member, because a hidden default list makes AC13 unusable — the
    member cannot know what is already covered.
13. **A member can add field names for a proxy.** Additions are **per proxy**, not per team, and
    apply to every payload that proxy stores. *(Grounds: the names come from that proxy's incoming
    payload structure, and a team-level list would apply one vendor's field names to another
    vendor's payloads. This is the same grain #8 uses for the expected incoming structure, without
    depending on #8.)* Additions can be added and removed freely; removing an addition never
    removes a default (AC12).
14. **Matching is by field name, case-insensitive, at any depth.** A field is sensitive because of
    what it is called, not what it contains. **#10 performs no value-pattern detection** — no
    card-number checksum, no key-shaped-string heuristic, no entropy test. *(PRD-06 AC22 pointed
    "secret detection" at #10; this criterion answers it by ruling name-matching only and deferring
    value detection — see § Out of Scope.)*
15. **Obfuscation applies to values, never to names.** The member must still be able to see the
    payload's **structure** — that a `password` field is present, and where — because structure is
    what makes a payload debuggable. Only the value is hidden.
16. **An obfuscated value discloses nothing about itself.** Not any character of it, not its
    length, not whether two obfuscated fields hold the same value, and not whether it is empty.
    *(A fixed-width rendering satisfies this; a per-character mask does not. Partial disclosure —
    "last four digits" — was considered and is not at MVP; see § Out of Scope.)*
17. **Obfuscation is a display property with no side effects.** The stored payload is unchanged
    (PRD-05 AC11's immutability is untouched), and **the payload delivered to destinations is
    unchanged** — destinations receive the real values, in original order and structure, on the
    original dispatch, on every retry and on every replay. **A dispatch whose bytes differ because
    a field was marked sensitive is a defect against this criterion.**
18. **Obfuscation is applied before content leaves the server, and it survives the whole-payload
    reveal.** A permitted member who requests payload content directly — by any route, not only
    through the interface — receives it with sensitive values already obfuscated. #6's reveal
    (PRD-06 AC25) continues to lift the **whole-payload mask**; it does not lift field
    obfuscation. **This narrows AC25's "exposes the full raw payload" and is recorded in
    § Consequences for approved documents rather than applied silently.**
19. **Obfuscation is on by default and retroactive.** It applies to every proxy without
    configuration, and to payloads captured before the feature shipped. Adding a name under AC13
    obfuscates it in payloads that are **already stored**, on the next view; removing one reveals
    it again on the next view. *(This falls out of AC17 — because nothing is rewritten, nothing
    needs migrating — and is asserted because it is the behaviour a member will assume.)*
20. **There is no per-field reveal, for any role.** An obfuscated value is not displayed to a
    Member, an Admin or the team Owner. **No new permission is introduced for it.** *(Grounds:
    PRD-06 AC25 and ADR-017 record the Owner ruling out a distinct reveal permission for payload
    content, and this project has no superadmin role. The member's remedy is to remove the name
    from their own list under AC13, which is entirely in their hands.)*
21. **Obfuscation states are distinguishable from retention states.** An obfuscated field must be
    visibly distinct from an empty value, from a missing field, and above all from a **cleaned**
    payload (PRD-05 AC21, PRD-06 AC16). A member must never read "your data was erased on
    schedule" as "this field is hidden", or the reverse.
22. **Obfuscation is claimed only where the payload parses as structured JSON.** Where a stored
    payload is not parseable JSON, **#10 makes no field-level claim**: the payload stays behind
    #6's existing whole-payload mask and #10 neither obfuscates nor pretends to. *(Bounded by
    today's system, not by a future one: #9 — multi-format ingestion — has not started, and the
    canonical JSON representation it owes does not exist yet. #10 must not invent a second one.)*

### Inbound verification (roadmap Open Question V2)

23. **(Amendment A — revised.) A proxy may require verification on incoming requests, under one of
    exactly two named schemes.** The proxy owner selects **`standard-webhooks`** (AC52) or
    **`shared-secret`** (AC51) and supplies the secret that scheme needs. **The list is closed and
    the scheme is selected, never described**: there is no free-form configuration of header names
    beyond `shared-secret`'s one name, no member-composed signed string, no member-chosen digest or
    encoding. Adding a third scheme is an Owner decision at the time a real integration needs it
    (AC50). See § V2 for the ruling and its grounds.
24. **Verification is optional and off by default. Existing proxies are unaffected.** A proxy with
    no verification configured behaves **exactly as it does today**: same acceptance, same capture,
    same response, same dispatch. Nothing is migrated and no proxy is opted in.
25. **A failed verification is rejected before capture, and nothing else happens.** The request is
    rejected with **HTTP 401** and a fixed, non-configurable body; **no `webhook_events` row is
    created**, no delivery is created, no dispatch occurs, and **the proxy's user-defined #3
    response is not used**. *(The last clause is the load-bearing one: returning the configured
    success response to an unverified sender would make verification decorative.)* This covers
    every way verification can fail under either scheme, including a `standard-webhooks` request
    whose timestamp falls outside the replay window (AC53) and one whose required headers are
    absent or malformed.
26. **(Amendment A — revised.) Verification secrets are stored, never generated by us, and are
    write-only once saved.** The **upstream provider issues the secret**; the product holds it in
    order to verify against it, and **#10 generates no inbound secret and offers no
    generate-a-token affordance**. After saving, the value is never redisplayed to any role. The
    surface shows that a secret is **set**, when it last changed, and whether a rotation overlap is
    running (AC29); under `shared-secret` the header **name** remains visible, because the sender
    has to be configured to match it. The secret carries the at-rest floor (AC1) and the key rule
    (AC10).
27. **(Amendment A — revised.) Verification headers are never forwarded to destinations.** Under
    `shared-secret`, whatever header name the member chooses is stripped from the outbound header
    set. Under `standard-webhooks`, the specification's three request headers are stripped. Both
    are in addition to ADR-008's existing fixed strip list. *(ADR-008's list is a constant and
    cannot anticipate a member-chosen name. Without this criterion, a proxy owner who enables
    verification would broadcast their own secret — or an inbound signature that a destination
    would then try and fail to verify against **our** signing secret — to every destination on
    every event.)*
28. **Configuring verification is gated by the existing proxy update permission**, including the
    Member ownership rule (Q-02-01, ADR-009 Amendment A2.2). No new permission. *(Same treatment
    PRD-06 gave retry-policy configuration: this is proxy configuration.)*
29. **(Amendment A — replaced AC29 outright. Amendment B — revised: the signing secret is re-grained
    to the proxy, the cap of two is re-affirmed and re-worded, and one bullet is added.) A
    verification secret may be rotated with a bounded
    overlap: two secrets are honoured, the older for a fixed 24 hours.** This governs **both
    directions** — the inbound verification secret under either scheme, and the **proxy signing
    secret** (AC58), rotate under this one rule, **both at proxy grain**. **The destination
    credential (AC33) is deliberately
    not covered**: it is presented rather than verified or computed, a request carries exactly one
    credential value, and there is nothing on the wire for an overlap to mean. Replacing a
    destination credential remains immediate and single-valued. **Amendment B does not touch that
    exclusion in any way** — the credential is still per destination (AC31), still outside this
    criterion, and still replaced immediately.
    - **(Amendment B — revised wording, unchanged policy.) At most two secrets exist for one purpose
      on one proxy at any instant: one current, and at most one previous.** Saving a replacement
      makes it the current secret and demotes the existing one to the **previous** secret. A further
      replacement inside the overlap demotes the new one in turn and **the oldest is discarded
      immediately** — there is no third slot. *(This is also the remedy for a compromised secret:
      replacing twice removes it at once.)* **"Exist", not merely "are honoured"** — a superseded
      secret past its overlap is gone, and a third is never held even briefly. This is a requirement
      about **behaviour**; it dictates no storage shape, and a storage model that could hold more is
      not in conflict with it as long as the behaviour above is what a member gets. **Amendment B
      considered raising this cap and did not** — grounds in `## Amendment B` ruling 2.
    - **(Amendment B — added. Product Manager's ruling, not the Owner's.) Starting a rotation while
      an overlap is already running must say what it costs, before it is saved.** The surface that
      begins a replacement or a regeneration while a previous secret is still honoured states that
      **the oldest secret stops being honoured immediately**. Without it, the 24-hour promise a
      member was given at the first rotation is broken by their own second rotation with nothing
      said, and under AC54's proxy grain that breaks every destination of the proxy at once. The
      wording and placement are the Designer's; that it is said before the save is not.
    - **The overlap is 24 hours from the moment the replacement is saved, fixed and not
      configurable.** Ruled by the Product Manager on the Owner's instruction that the expiry be
      stated; grounds in `## Amendment A` § *The rotation-expiry ruling*. At expiry the previous
      secret **stops being honoured and is erased**, not merely ignored.
    - **A member may end an overlap early** with an explicit action that stops the previous secret
      being honoured immediately. Without it, the only way to kill a leaked secret before 24 hours
      is a second rotation, which is a correct but non-obvious remedy.
    - **Inbound, either secret verifies.** A request that verifies against the current **or** the
      previous secret is accepted while the overlap runs; which one it matched has no other effect.
    - **Outbound, both are presented.** A signed dispatch carries a signature under the current
      secret **and** one under the previous secret while the overlap runs (AC58). The Standard
      Webhooks specification carries a space-delimited list of signatures for exactly this reason,
      so this asks nothing of the receiver beyond the specification it already implements.
    - **The previous secret is a secret.** It carries the at-rest floor (AC1), the key rule (AC10),
      AC11's fail-loudly rule, and is never redisplayed.

**The two schemes, defined. (AC51–AC53 added — Amendment A. Placed here rather than at the end of
the document; see the numbering note above.)**

51. **`shared-secret` — a secret in a member-named header.** The member configures a **header
    name** and the **secret value** their sender will send. A request is verified when the named
    header is present and its value matches the current or previous secret (AC29) exactly.
    Comparison is exact and constant-time. Nothing is computed over the body. *(This is the whole of
    what AC23 said before Amendment A, kept as one of the two schemes because a sender that signs
    nothing still needs a way to authenticate, and because the product cannot require every upstream
    provider to implement a specification.)*
52. **`standard-webhooks` — the published Standard Webhooks specification, implemented as
    specified.** The specification at `standardwebhooks.com` is the **normative source**; #10
    defines no variant of it and the product invents no part of it. Binding properties, stated so a
    Reviewer can verify them against the specification rather than against an implementation:
    - The request carries the specification's three headers — **`webhook-id`**,
      **`webhook-timestamp`** and **`webhook-signature`**. A request missing or malforming any of
      them fails verification (AC25).
    - **`webhook-signature` carries a space-delimited list of signatures**, and verification
      **succeeds if any entry in the list verifies**. The product loops the list; it never reads
      only the first entry.
    - Each signature is **HMAC-SHA256**, **base64-encoded** — not hex — over the signed content the
      specification defines, which concatenates the message id, the timestamp and the body as
      **`<id>.<timestamp>.<body>`**.
    - **Verification runs over the raw request body exactly as received**, before capture, parsing,
      normalisation or any pipeline step. **This is why #8 and #9 have no bearing on it** — see
      § V2, where a previous ruling's reasoning to the contrary is corrected.
    - Signature comparison is constant-time.
53. **`standard-webhooks` enforces the specification's timestamp and replay window.** A request
    whose `webhook-timestamp` falls outside the tolerance the specification sets is rejected under
    AC25. **The tolerance is the specification's, not the product's**: it is **not member-
    configurable**, and #10 does not invent a value of its own. At the time of writing the
    specification's reference verifiers use **five minutes** either side; if the specification
    states a different or a non-normative value at implementation time, **the specification wins and
    the product adopts what it states**. Replay-window enforcement was ruled out at MVP before
    Amendment A and is now required, because it arrives with the scheme rather than as separate
    work.

### Outbound destination authentication — **APPROVED (Q-10-01, Project Owner, 2026-08-27)**

> **These ten criteria were written as a severable block** pending the Owner's ruling on the
> roadmap change recorded in `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`.
> **The Owner approved the merge on 2026-08-27, the question document is RESOLVED, and the
> severability is withdrawn.** AC30–AC39 stand as written and are now ordinary criteria of this
> PRD. They remain **independent of § Outbound request signing (AC54–AC64)**: one authenticates
> this service to a destination, the other lets a destination verify this service, and a destination
> may have neither, either or both (AC54).

30. **A destination may carry an optional credential.** It is configured as a **header name** plus
    a **secret value**, both member-supplied. The header name defaults to `Authorization`. The
    value is sent **verbatim**, so a member may enter `Bearer abc123` or a bare key and the product
    adds no scheme prefix of its own.
31. **The credential is per destination, never per proxy.** *(Q-10-01 sub-question 2. A proxy fans
    out to destinations belonging to different parties; one credential shared across them would
    hand each destination's operator a secret that also opens the others.)*
32. **The credential is presented on every dispatch to that destination** — the original attempt,
    every retry, and every replay — and on no request to any other destination.
33. **The credential is write-only after saving.** It is never redisplayed to any role, including
    the team Owner. The surface shows **set / not set** and when it last changed, never the value
    and never its length. A member may replace it at any time. *(Q-10-01 sub-question 3, settled by
    declining to introduce a role rather than by naming one — see that document's Answer.)*
34. **The credential is encrypted at rest** to the at-rest floor (AC1), under the key rule (AC10).
35. **The credential appears nowhere but the outbound request.** Not in a queued job's arguments,
    not in a delivery-attempt record (ADR-003 records are payload-free and this does not change
    that), not in analytics, not in a failure record, not in a log line, and not in any payload
    view. **A destination URL displayed in the interface is unchanged by this feature.**
36. **The credential is configuration, not payload content.** Retention does not erase it, expiry
    does not clear it, and a cleaned event has no bearing on it. *(Asserted because AC1–AC3 are
    written over payload content, and a reader could otherwise apply the 30-day pass to a
    destination's credential and break every subsequent delivery.)*
37. **Existing destinations are unaffected.** A destination with no credential produces a
    **byte-identical** outbound request to today's. No migration, no backfill, no default
    credential, no forced move. *(Q-10-01 sub-question 4, stated as a criterion so it is not
    re-litigated at review.)*
38. **The credential header takes precedence over any forwarded inbound header of the same name**,
    and the collision is resolved in favour of the credential. *(ADR-008 forwards inbound headers
    minus a fixed strip list. `authorization` is already stripped, so the default case cannot
    collide — but a member-chosen name can, and the resolution must not be left to chance.)*
39. **Secrets already embedded in `destinations.url` are untouched.** #10 does not detect, migrate,
    warn about, rewrite or remove them, and adding a credential to a destination does not change
    its URL. **The plaintext exposure of a URL-embedded secret is a current fact about the system
    and remains one after this feature ships** — the feature gives members somewhere better to put
    a secret; it does not move the ones already there.

### Outbound request signing — **AC54–AC64, added by Amendment A (Project Owner ruling, 2026-08-27); re-grained to the proxy by Amendment B (Project Owner ruling, 2026-08-27)**

> **The reverse direction of AC30–AC39, and the row the Owner identified as missing.** AC30–AC39
> let this service authenticate itself against a destination's own scheme. These criteria let a
> destination verify that a dispatch came from this service. The two are independent throughout —
> **and they now sit at different grains: the credential is per destination (AC31), the signing
> secret is per proxy (AC54).** Amendment B moved only the grain; the scheme, the ownership of the
> secret, the one-time display, the dispatched bytes and the message identity are all untouched.

54. **(Amendment B — revised. Project Owner ruling, 2026-08-27.) A proxy may be configured so that
    this service signs its dispatches, under one signing secret used for every destination that
    proxy dispatches to.** Signing is **per proxy**, optional, and **off by default**. Enabling it
    signs dispatches to **every** destination of that proxy — including a destination added
    afterwards — under the **same** secret. **There is no per-destination signing secret, no
    per-destination enable and no per-destination rotation state.** It remains **independent of
    AC30–AC39**, which stay per destination: a destination may have neither a credential nor a
    signed dispatch, either one, or both, and enabling one says nothing about the other.
    **One consequence of the grain, stated as part of the criterion rather than left to be
    discovered: a proxy's destinations become one trust domain.** Every destination operator holds
    the same secret, so any one of them can verify — and forge — traffic addressed to any other
    destination of that proxy. The per-destination model did not have that property. This is the
    Project Owner's ruling and this criterion records it rather than re-arguing it; see
    `## Amendment B`, which puts the trade-off in front of the Owner at the amendment's own approval
    gate.
    > **Pre-amendment text, retained so the record is complete:** "A **destination** may be
    > configured so that this service signs its dispatches. Signing is **per destination**, optional,
    > and **off by default**. It is **independent of AC30–AC39**: a destination may have neither a
    > credential nor signing, either one, or both, and enabling one says nothing about the other."
55. **Signing uses the `standard-webhooks` scheme, the same one AC52 defines for inbound.** Same
    specification, same three headers, same signed content, same HMAC-SHA256 and base64 encoding.
    **One implementation serves both directions** — that is the reason this scheme was chosen for
    inbound, and #10 must not grow a second, outbound-only construction.
56. **The product generates the signing secret. A member cannot supply one.** This is the **only
    secret in this feature the product owns**, and therefore the only one that can be
    **regenerated** — every other secret in #10 is issued by somebody else and can only be replaced
    with what they issued (AC26, AC30). Generation is the only way a signing secret comes into
    existence.
57. **The signing secret is displayed exactly once, at generation, and is write-only thereafter.**
    The one-time display exists because the member must configure their receiver with the value and
    no one else can tell them what it is. After that screen the value is **never redisplayed to any
    role**, including the team Owner; the surface shows set / not set, when it was generated, and
    whether a rotation overlap is running. The secret is **encrypted at rest** to the at-rest floor
    (AC1), under the key rule (AC10), and AC11's fail-loudly rule applies. *(This is the single
    deliberate exception to "no secret in this feature is ever displayed after it is saved", and it
    is stated as an exception rather than left to be discovered in the design.)*
58. **(Amendment B — revised: grain, and "both" confirmed literally.) Regeneration rotates under
    AC29, for the proxy.** The previous signing secret is held for the same fixed
    24-hour overlap, and **every dispatch to every destination of that proxy in the interim carries
    a signature under both secrets** in
    the specification's signature list. A member may end the overlap early (AC29), and the newly
    generated secret is subject to AC57's one-time display exactly as the first one was.
    **"Both" is exact, not loose:** AC29's cap of two stands after Amendment B, so the signature
    list carries **at most two** entries and a receiver never sees a list of unknown length.
    Rotation is a property of the proxy — one rotation, one overlap, one expiry, however many
    destinations that proxy has.
59. **The signature is computed over the exact bytes dispatched, and signing changes nothing but
    the headers.** The request body a destination receives is **byte-identical** to what it would
    have received unsigned — signing adds headers and alters nothing else. This composes with AC17:
    obfuscation never changes dispatched bytes, so it never changes a signature either.
60. **A dispatch is signed on every attempt — the original, every retry, and every replay** — and
    the identity headers say which is which. **`webhook-id` identifies the delivery**, so a receiver
    that deduplicates on it sees a retry of the same delivery as the same message, and the
    at-least-once behaviour #4 and #6 guarantee (AC7) becomes something a receiver can actually
    handle. **`webhook-timestamp` is the time of that attempt**, not of the original — otherwise a
    retry that arrives hours later would fall outside its receiver's replay window and be correctly
    rejected. A **replay** is new work under PRD-06 and therefore carries a **new** `webhook-id`.
    **(Amendment B — confirmed, not changed, because the two are easy to conflate.) The *secret* is
    the proxy's; the *message identity* stays per delivery.** Two destinations of one dispatch still
    receive two different `webhook-id`s, signed with the same key. Nothing about the grain ruling
    makes one dispatch one message.
61. **The signing secret appears nowhere but its one-time display and the signature computation.**
    Not in a queued job's arguments, not in a delivery-attempt record, not in analytics, not in a
    failure record, not in a log line, and not in any payload view. **The signature itself is not a
    secret and may be recorded**; the secret that produced it may not.
62. **The signing secret is configuration, not payload content.** Retention does not erase it,
    expiry does not clear it, and a cleaned event has no bearing on it. *(Same reasoning as AC36:
    applying the 30-day pass here would silently stop a destination trusting us.)*
63. **(Amendment B — revised: the subject moves, the guarantee does not.) Existing destinations are
    unaffected.** A destination **of a proxy without signing** produces a
    **byte-identical** outbound request to today's. No migration, no backfill, no secret generated
    for anyone who did not ask for one, no proxy opted in.
    > **Pre-amendment text, retained:** "Existing **destinations** are unaffected. A **destination
    > without signing** produces a **byte-identical** outbound request to today's."
64. **The signing headers take precedence over any forwarded inbound header of the same name.** A
    proxy whose own ingest is verified under `standard-webhooks` receives those three header names
    inbound; AC27 already strips them, and this criterion states the resolution so that no
    combination of settings can let an inbound signature reach a destination as though it were ours.

### Header policy — discharging #3's acknowledgement

40. **The header at-rest exposure #3 deferred to #10 is already closed, and #10 re-states rather
    than re-does it.** PRD-05 AC22 and ADR-014 encrypt captured headers at rest and erase them by
    the same expiry pass. **#10 changes no header storage shape.**
41. **No surface displays captured headers, and #10 introduces none.** Verified as the current
    state: ADR-017 records that the Inertia pages never receive `headers`, and the single
    content-bearing endpoint serves the raw payload only. **#10 asserts no header viewer.**
42. **Any future header display surface is bound by this PRD's obfuscation rules.** If a later item
    surfaces captured headers, the sensitive-field rules (AC12–AC21) bind it, and credential-shaped
    headers — `Authorization` foremost — are obfuscated. *(This is what remains of "sensitive-header
    policy" once storage is already solved and nothing is displayed: a standing constraint on the
    next item, not a screen built now.)*
43. **(Amendment A — revised.) Inbound header forwarding is unchanged except for the three rules
    this PRD adds** — AC27's strip of the verification headers under either scheme, AC38's
    credential precedence, and AC64's signing-header precedence. ADR-008's existing policy and its
    fixed strip list are otherwise untouched.

### Scope boundaries

44. **(Amendment A — revised.) No **application-key** rotation or re-encryption tooling.** PRD-05
    § Out of Scope assigns it to #10; #10 **defers it**, and states the cost rather than hiding it:
    AC10 widens the key-lifecycle surface from three columns to every secret this feature
    introduces — the inbound verification secret, the destination credential, the **proxy signing
    secret** *(Amendment B — renamed only)*, and any of those currently held as a rotation overlap — and the binding
    `APP_PREVIOUS_KEYS` guard is what prevents data loss in the absence of tooling. **No
    application-key rotation may be performed until that tooling exists.** *(AC29's secret rotation
    is a different thing entirely and **is** in scope; this criterion defers only the encryption
    key.)*
    *(Grounds: the tooling is operational, has no user-facing requirement behind it, and no
    rotation is scheduled. It remains ADR-010's accepted FUTURE task.)*
45. **No per-team or per-plan key policy.** One application key, as today. *(Grounds: V5
    subscription tiers are deferred, billing is out of scope in the vision, and the vision's Known
    Constraints record "No additional compliance requirements today". Extension point kept: #10
    assumes nothing about key granularity beyond what ADR-010 already does.)*
46. **No analytics, counter or notification for rejected requests.** A verification rejection
    produces no event record (AC25), so there is nothing for #11 to count and nothing for #13 to
    notify on. **This is a real cost and it is stated, not glossed:** a member whose sender is
    misconfigured sees silence. The remedy belongs to #11 or #13 and is not designed here.
47. **No numeric targets.** No throughput, latency or verification-overhead number is asserted
    (V8 remains deferred).
48. **Nothing here depends on #8 or #9, and nothing here pre-empts them.** #8 is Owner-deferred
    with zero implementation and #9 has not started. **The obligation runs the other way:** #8's
    carried-forward note already names #10 as an explicit input for re-validation on resumption,
    because `proxy_maps.output` and `proxy_map_conditions.value` would hold member-typed plaintext
    literals. **#10 asserts nothing about tables that do not exist**; #8 inherits AC1 and AC12–AC21
    when it resumes.
49. **No obfuscation of anything but stored payload content.** Not the ingest URL, not destination
    URLs, not delivery-attempt error summaries, not analytics figures, not team or member data.
50. **(Amendment A — revised.) The verification scheme list is closed at two, and stays closed
    until an Owner decision opens it.** `standard-webhooks` (AC52) and `shared-secret` (AC51) are
    the whole of MVP. **Vendor-specific schemes are explicitly not in MVP and are named so they are
    not re-argued: `github`, `stripe`, `slack`, and any other per-vendor construction.** Each is
    added only when a real integration needs it, and **each is a Project Owner decision at that
    time** — not a design choice, not a Product Manager call, and not something a later item may
    absorb quietly. *(Grounds in § V2: the header name is the easy part; the variable that makes
    each vendor a separate piece of work is how the signed string is constructed.)*

## Roadmap Open Question V2 — settled here

**V2, verbatim from `docs/product/roadmap.md`:**

> V2. **Webhook verification-token standards** — which standards at MVP? Gates #10. *(Vision Open
> Question 2.)*

**Vision Open Question 2, verbatim:** "Webhook verification-token standards — which standards to
support at MVP (existing standards to be reviewed)."

### Ruling — two named schemes: `standard-webhooks` and `shared-secret`. A closed list, not free-form configuration.

**Ruled by the Project Owner, 2026-08-27, overturning and replacing the Product Manager's earlier
ruling.** Rendered into **AC23–AC29** and **AC51–AC53**. Approving this PRD ratifies the
replacement. It stays called out in the Status block because it is a security-shaped decision and
should be approved deliberately rather than absorbed.

**The superseded ruling is recorded below rather than deleted**, per
`docs/standards/documentation.md` (retain history; never rewrite a ruling silently), because one of
its grounds was **factually wrong** and a later reader who found only the conclusion would not know
which parts to distrust.

#### The Owner's grounds

1. **Standard Webhooks is a published specification, not a per-vendor programme.**
   `standardwebhooks.com` defines `Webhook-Id`, `Webhook-Timestamp` and `Webhook-Signature`, the
   last carrying a space-delimited list of HMAC-SHA256 signatures. Implementing it is **one** piece
   of bounded work against a written specification, which is a different kind of commitment from
   "support signature standards" open-endedly.
2. **The unbounded-work objection was accepted, and answered by closing the list rather than by
   refusing signatures.** The superseded ruling's ground 2 was correct that supporting each
   vendor's construction has no end. The Owner's resolution is a **closed, named scheme list**
   (AC23, AC50): the member selects a scheme, they do not describe one. **The header *name* is the
   easy part; the variable that matters is how the signed string is constructed**, and those
   genuinely differ — GitHub signs the raw body, Stripe signs `<timestamp>.<body>`, Slack signs
   `v0:<timestamp>:<body>`, and Standard Webhooks signs `<id>.<timestamp>.<body>` in base64 rather
   than hex. That is why each vendor scheme is separate work and an Owner decision each time
   (AC50), and why two named schemes are not the thin end of four.
3. **A shared secret is still needed, so it stays.** Not every upstream provider implements a
   specification, and the product cannot require them to. `shared-secret` (AC51) is what the
   superseded ruling delivered, retained as one of the two.
4. **Timestamp and replay-window handling arrive with the scheme.** They were previously ruled out
   as separate work; under `standard-webhooks` they are part of what is being implemented, not an
   addition to it (AC53).

#### Correction — the superseded ruling's ground 4 was wrong

**It should not have carried weight, and it is corrected here rather than left standing.**

That ground argued that signature verification could not be specified because #9 has not defined a
canonical JSON representation and #8 is deferred, so "there is no canonical representation to sign
against". **That is not how inbound signature verification works.** It runs on the **raw request
body exactly as received, at ingest, before any mapping or normalisation** — which is what every
provider that signs its webhooks does, and what **ADR-010's raw capture already makes available**.
#8 and #9 operate downstream of that point and are **irrelevant to it**. AC52 states the property
directly, and **AC48 is unaffected and now holds more strongly**: #10 still depends on neither item.

The other three superseded grounds are not withdrawn on their facts — the roadmap and vision do say
"token" and "MVP", supporting standards plurally is genuinely unbounded, and the ingest URL is
genuinely the first factor. They were **outweighed**, which is the Owner's call to make: a published
specification satisfies "MVP level" without becoming a programme, and ground 2's real concern is
answered by closing the list.

#### The superseded ruling, quoted so the record is complete

> **Ruling — one scheme: a shared secret in a member-named header. No third-party signature
> standards at MVP.** *(Product Manager as the Owner's proxy, 2026-08-27 — **OVERTURNED by the
> Project Owner the same day**.)* Its ruled-out list named vendor signature standards, HMAC
> verification of any kind, timestamp or replay-window enforcement, IP allow-listing, mutual TLS,
> and multiple simultaneously-accepted secrets. **Four of those six are now in scope**:
> Standard Webhooks (AC52), HMAC verification under it, replay-window enforcement (AC53), and two
> simultaneously-honoured secrets (AC29).

#### What remains ruled OUT at MVP, by name, so it is not re-argued

**Vendor-specific schemes — `github`, `stripe`, `slack`, and any other per-vendor construction**
(AC50); **IP allow-listing**; **mutual TLS**; **member-composed or free-form verification
configuration** of any kind — no member-defined signed string, digest, encoding or header set
beyond `shared-secret`'s one header name (AC23). **None is rejected on merit**, and AC23's scheme
selector is exactly the seam a third scheme would use — which is why adding one is cheap in
structure and still an Owner decision in scope.

## D2 — Payload content in queue and failed-job infrastructure

D2 (PRD-05 § Deferred concerns, added by Amendment B and ratified by the Owner on 2026-08-25)
**gates this PRD**: "#10's PRD must discharge it explicitly", and "it does not pass requirement
approval without these". Discharged here, item by item.

### What changed underneath D2, and it is most of it

**D2's factual premise is obsolete as of `bd0e67d` (2026-08-27).** D2 was written on 2026-08-25
and describes a mechanism that no longer exists:

> "the per-destination unit of work carries the event's body and headers **verbatim** and is
> serialized into the queue backend; a unit whose job throws is written durably to the failed-job
> store in **plaintext**"

**ADR-020 Decision 7** removed that, and the change is merged. Verified against the code rather
than taken from the ADR: `app/Actions/DeliverStep.php` now dispatches
`DeliverToDestination::dispatch($deliveryId, 1)` — two integers, no `DeliveryUnit`, no headers, no
destination model — for **both** processing modes, and the unit is resolved on the worker. The
Project Owner rejected encrypting the payload in the queue and **required it removed instead**, so
the exposure was closed rather than mitigated. `RetryDelivery` and the replay entry points have
carried references since #6.

**So D2 is discharged as a property to pin, not as a defect to fix**, and that changes its
character rather than dismissing it. AC1–AC8 exist because ADR-020 is a technical decision that a
later change could undo without any criterion failing — and because ADR-020 itself records two
binding constraints whose violation would be silent. The requirement is what makes the property
survive the next item.

**What #10 does not assume.** The task of discharging D2 warns correctly that **failed-job
infrastructure is a separate surface from the queue body**, and #10 treats it that way: **AC5 is
asserted separately from AC4** and covers a failure record's exception and its operator-facing
rendering, not only its arguments. Whether anything in that surface can still carry payload content
now that the unit is resolved on the worker is a technical question, and it is routed to the
Principal Engineer at **Q-10-02** rather than assumed either way.

### D2's five required items, discharged

| D2 requires | Discharged by |
|---|---|
| **1.** An AC that every durable at-rest copy carries the AC15 floor, stated **backend-agnostically**, never against a named table, because **V3** may change the backend | **AC1**, with the definition of *durable at-rest copy* fixed in § Definitions. **AC3** closes the set of stores. No criterion here names a table |
| **2.** An AC that no durable plaintext copy outlives its event's retention window, **or** that none exists at all — "whichever the Owner rules at #10" | **AC2** takes the **stronger** option: none exists. This is the option the system already satisfies, so it ratifies a state reached rather than commissioning work. The Owner rules it by approving this PRD |
| **3.** An **inventory** of every at-rest location holding payload content at the time #10 is written | **Partially.** The snapshot below is stated. **The authoritative version is not mine to produce** — D2 item 3 says so explicitly ("a **Principal Engineer** task, follow-up F1 in Q-05-06, not the Product Manager's") and **no F1 artifact exists**. Raised as **Q-10-02** |
| **4.** A statement that any mitigation preserves failure diagnosability, does not weaken at-least-once/idempotency, and does not reintroduce payload content into logs | **AC6, AC7, AC8** |
| **5.** A cross-reference to the origin, so it is not lost | `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md` (RESOLVED, PM ruling Option B, Owner acceptance E1) and `docs/reviews/review-05-payload-storage-retention.md` **finding 3**. Both are the origin of this section |

### Inventory snapshot — 2026-08-27

**Stated as a snapshot, explicitly not as the authoritative inventory.** Compiled from ADR-020
§ Impact, which enumerated these stores to answer its own question, and re-checked against the
merged code. **Q-10-02 asks the Principal Engineer for the complete one.**

| Location | Durable? | Payload content today |
|---|---|---|
| `webhook_events.body` / `.headers` | Yes — 30-day retention | **Present, encrypted at rest** (ADR-010 Amendment B, ADR-014). Erased in place by GC |
| `dispatched_payloads.body` | Yes — same lifecycle | **Present when the output diverged, encrypted at rest.** Erased by the same pass |
| Failed-job store | Yes — pruned at 7 days by the daily `queue:prune-failed --hours 168` scheduled under E1 | **Absent** from the job arguments. Its exception and rendering are AC5's subject and Q-10-02's question |
| Horizon's own job records (`recent`/`completed` 60 min; `failed`/`monitored` 7 days) | Yes — a **second**, independent retention that `queue:prune-failed` does not touch | **Absent.** Added 2026-08-27 by operational work outside the pipeline |
| Queue list / reserved entry for a job about to run | No — held for the life of one unit of work | **Absent** |
| Worker memory; the outbound HTTPS request | No | Present, necessarily. Outside AC1 |

**One ordering worth carrying rather than losing.** ADR-020 records that the failed-job prune
window (168 hours, a hard-coded literal in `routes/console.php`) must stay **below** the resolved
retention window (`retention.days`, default 30 days, env-overridable), or a failure record could
outlive the erase meant to destroy the content it once held. It does today, by 23 days. ADR-020
named it rather than testing it, on the ground that after Decision 7 there is no payload in that
store for an inversion to expose. **AC2 makes that ordering a requirement rather than a
coincidence**; whether it warrants a test is the Principal Engineer's call at Q-10-02.

## Consequences for approved documents

Recorded so nothing is narrowed silently — the rule PRD-05 Amendment A was written under. **Neither
document is edited by this PRD.** **Both were put to the Project Owner on 2026-08-27 and
ACCEPTED AS RECORDED** — that is, accepted in this form, here, without a formal amendment to the
document being narrowed.

- **PRD-06 AC25 is narrowed — ACCEPTED as a recorded narrowing (Project Owner, 2026-08-27).**
  AC25 says activating the reveal "exposes the **full raw payload**". Under **AC18**, it exposes the
  full payload **with sensitive values obfuscated**. This is the intended reading — PRD-06 AC22
  explicitly reserved all field-level handling to #10, and PRD-06 § Out of Scope calls its own mask
  "presentation, not policy" — but the words "full raw payload" will be read literally by a
  Reviewer, so the narrowing is named here. **The Owner ruled that this record is sufficient and
  that a formal PRD-06 amendment is not wanted. PRD-06 stays Approved, is not reopened, and is not
  edited.** A Reviewer reading PRD-06 AC25 literally against the built surface should be directed
  here.
- **ADR-008's "No header is added" ceases to be true — ACKNOWLEDGED (Project Owner, 2026-08-27).**
  The `DeliveryUnit::outboundHeaders()` docblock states plainly that the outbound set is the inbound
  set minus a strip list, with no header added. **AC30 adds one, and Amendment A's AC55 adds
  more** — the signing headers — so the reversal is now larger than it was when this section was
  first written, and it is no longer conditional on anything: AC30–AC39 are approved. **Recording
  the reversal on the ADR remains the Principal Engineer's**, per
  `docs/standards/documentation.md` — amend or supersede is their call, not mine, and the Owner
  left it there. AC27's strip of the verification headers and AC64's precedence rule extend the same
  list and are the same kind of change.
- **(Amendment B.) `design-10`'s per-destination signing surface is displaced — the Designer's to
  revise, and nothing is edited here.** `design-10` was approved at the design gate on 2026-08-27
  with signing designed per destination throughout: its § Scope note items (2) and (3), § Overview,
  Flows G, H and I, Screen 5's per-row `Signed` badge and `Manage signing` action, and the whole of
  Screen 6. AC54 as amended displaces all of it. **The design amendment is the Designer's**, is
  downstream of the Owner approving Amendment B, and is enumerated in
  `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md` § *What the
  Designer needs, afterwards* plus the two further sections named in that document's Answer. **Two
  things do not move**: Screen 5's `Credential` badge, because the credential is still per
  destination (AC31); and flagged design call 4's ruling that the one-time reveal suppresses `Esc`
  and overlay dismissal with **Done** the sole keyboard-reachable exit, which binds wherever the
  reveal lands. `design-10`'s historical approval record is not to be rewritten — it records what
  that gate considered, which was the pre-amendment text.
- **Nothing else is disturbed.** ADR-006 (ingest URL), ADR-014 (payload columns and the cleaned
  signal), ADR-017 (the read surface and fetch-on-reveal), ADR-020 (by-reference delivery),
  PRD-05's retention contract and PRD-02's permission model are all relied on unchanged. #10 adds
  **no** new permission (AC20, AC28) and **no** new payload store (AC3).

## Out of Scope
Each names where it goes, or why nothing owns it yet.

- **Vendor-specific inbound schemes — `github`, `stripe`, `slack`, any other per-vendor
  construction — plus IP allow-listing, mutual TLS, and any free-form or member-composed
  verification configuration** — AC50, § V2. Each vendor scheme is added only when a real
  integration needs it, and each is an Owner decision at that time.
  *(**Changed by Amendment A.** Signature-based inbound verification, HMAC, and timestamp /
  replay-window enforcement were previously listed here and are now **in scope** under AC52 and
  AC53.)*
- **A rotation overlap longer or shorter than 24 hours, or a member-configurable one** — AC29
  fixes it. **The overlap itself is in scope**, replacing the earlier no-overlap ruling whose cost
  was a synchronised cutover with the sender.
  *(**Changed by Amendment A.**)*
- **Outbound request signing under any scheme other than `standard-webhooks`** — AC55. The scheme
  is fixed to the one AC52 already implements, so one implementation serves both directions.
  *(**Changed by Amendment A.** Outbound signing as such was previously out of scope entirely and
  is now AC54–AC64. Q-10-01 sub-question 1 settled the **credential** as a static secret and that
  still stands — signing is the other, independent capability.)*
- **A member-supplied signing secret** — AC56. The product generates it, and generation is the only
  way one exists.
- **A per-destination signing secret, a per-destination signing toggle, or per-destination rotation
  state** — AC54, on the Project Owner's ruling of 2026-08-27 (`## Amendment B`). Signing is a
  property of the proxy. A member who needs two destinations to hold different signing keys needs
  two proxies. *(Named here so it is not re-argued, and so a later item does not reintroduce it
  quietly: #13 and #14 both touch dispatch.)*
- **More than two live secrets for one purpose on one proxy** — AC29, ruled by the Product Manager
  in `## Amendment B` ruling 2. The cap is behavioural and is not a claim about storage; raising it
  later is a requirements change, not a schema change.
- **Value-pattern secret detection** (card checksums, entropy tests, key-shaped strings) — AC14.
  A different feature with a false-positive problem #10 does not take on.
- **Partial disclosure of an obfuscated value** ("last four digits") — AC16. Considered; it leaks,
  and no stated requirement asks for it.
- **A team-level sensitive-field list** — AC13 rules per-proxy. If the Owner wants team-level
  defaults later, AC13's per-proxy list is additive to it, not in conflict.
- **Obfuscation of non-JSON payload content** — AC22. Bounded by #9 not having started.
- **Key rotation / re-encryption tooling** — AC44, with its cost stated. Still ADR-010's accepted
  FUTURE task.
- **Per-team or per-plan encryption keys** — AC45; V5 deferred, no compliance requirement.
- **Cleaning up secrets already embedded in `destinations.url`** — AC39. A current fact, left as
  found.
- **Any analytics, counter or notification for rejected inbound requests** — AC46; #11 / #13.
- **A payload export, download or share path** — none exists (PRD-05 AC16) and #10 adds none.
- **Anything depending on payload mapping (#8) or multi-format ingestion (#9)** — AC48.
- **Throughput, latency or overhead targets** — AC47; V8 deferred.

## Open Questions
Question IDs Q-10-0x. **One is RESOLVED; one is technical and gates technical design only, not
requirement approval.**

- **~~Q-10-01 (Project Owner — scope)~~ — RESOLVED, 2026-08-27. The Owner APPROVED the merge.**
  Doc: `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`. Outbound destination
  authentication belongs in #10; **AC30–AC39 stand as written and are no longer severable**; all
  four sub-questions are settled as the Product Manager answered them. Nothing about this question
  remains open.
- **Q-10-02 (Principal Engineer — technical) — OPEN, raised by this PRD. Non-blocking for
  requirement approval; gates technical design.** Doc:
  `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md`. Two items: **(i)** the
  authoritative inventory of durable at-rest payload-content copies that D2 item 3 assigns to the
  Principal Engineer as follow-up **F1**, which has never been produced — the § D2 snapshot above
  is a Product Manager's compilation and is explicitly not it; **(ii)** whether **AC5** holds for
  the failed-job surface now that the delivery unit is resolved on the worker rather than carried
  in the job — a failure record's exception and its operator-facing rendering are a different
  surface from the job body, and #10 does not assume ADR-020 closed it. If either finding
  contradicts a criterion here, that returns to the Product Manager as a requirement question, not
  a silent design change.
- **V2 — SETTLED in this PRD** (§ Roadmap Open Question V2), **by the Project Owner directly**,
  2026-08-27, overturning the Product Manager's earlier proxy ruling. Owner approval of this PRD
  ratifies the replacement.
- **No question document is raised for outbound request signing.** The Owner ruled it in directly,
  including its scheme, its ownership of the secret, its one-time display and its rotation, so
  there is nothing left to ask. It is a roadmap widening of the same class Q-10-01 carried, and it
  is ratified at this PRD's approval gate rather than by a separate document. **(Amendment B.) The
  one thing that was left to ask — the signing *grain* — was raised by the Principal Engineer and is
  answered by `## Amendment B`.**
- **~~Q-10-04 (Product Manager — requirements)~~ — RESOLVED, 2026-08-27, by `## Amendment B`.** Doc:
  `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`. **Item 1**: the
  Project Owner's ruling of 2026-08-27 makes outbound signing per proxy; AC54, AC58, AC60 and AC63
  and § Definitions are amended to say so. **Item 2**: AC29's cap of two **stands**, ruled by the
  Product Manager as requirements author, with the wording clarified for a storage model that could
  hold more and one bullet added. **`## Amendment B` itself is not approved** — Q-10-04 is answered,
  and the amendment answering it goes to the Owner.
- **D2 — DISCHARGED in this PRD** (§ D2), except item 3, which is routed to Q-10-02 because it is
  a Principal Engineer deliverable by D2's own words.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#10 line + build-ahead note; V2; V3; the #8/#9/#11/#13
  boundaries) · `docs/product/vision.md` ("Sensitive data handling"; Open Question 2; Known
  Constraints — HTTPS-only, MySQL, no compliance requirements) ·
  `docs/product/prd-05-payload-storage-retention.md` (AC6, AC11, AC15, AC16, AC21, AC22;
  Amendments A and B; **deferred concern D2**, the gate on this PRD) ·
  `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md` (RESOLVED; D2's origin, E1, F1/F2) ·
  `docs/product/prd-06-retry-replay.md` (AC15–AC18, **AC25**, AC22 — the surface #10 layers onto) ·
  `docs/product/prd-03-decoupled-upstream-response.md` (the user-defined response that **this PRD's
  AC25** forbids serving to an unverified sender; the headers-plaintext acknowledgement discharged
  at AC40–AC42) ·
  `docs/product/prd-02-role-based-collaboration.md` + `docs/architecture/adr-009-proxy-permission-mechanism.md`
  (the permission model AC28 and AC33 use, and the one #10 does not extend) ·
  `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md` (the three encrypted
  columns, the cleaned signal, Decision 7's guard) ·
  `docs/architecture/adr-017-replay-dispatch-and-payload-read-surface.md` (the single
  content-bearing endpoint and fetch-on-reveal) ·
  `docs/architecture/adr-020-fifo-advancer-job-duration-and-claim-lease-safety.md` (Decision 7 and
  § Impact — what closed D2's instance) · `docs/architecture/adr-008-inbound-header-forwarding-policy.md`
  (the strip list AC27 and AC38 touch) · `docs/architecture/adr-006-ingest-url-generation-security.md`
  (the first factor AC23's secret is second to) · `docs/architecture/adr-010-raw-payload-capture.md`
  (raw capture — **the raw bytes AC52's inbound signature verification runs over**) and its
  Amendment B (the binding `APP_PREVIOUS_KEYS` rule AC10 widens) ·
  **the Standard Webhooks specification, `standardwebhooks.com`** — the normative source for AC52,
  AC53 and AC55; #10 defines no variant of it ·
  `database/migrations/2026_07_30_000002_create_destinations_table.php` and
  `docs/plans/plan-08-payload-mapping.md` line 419 (the `destinations.url` exposure, AC39) ·
  `docs/standards/documentation.md`.
- **Outputs:** this PRD, including **`## Amendment A`** and **`## Amendment B`** (the latter
  **awaiting Owner approval**) ·
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md` (**RESOLVED**, Owner
  approved, 2026-08-27) · `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md`
  (**RESOLVED** by the Principal Engineer, 2026-08-27) ·
  `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md` (**RESOLVED** by
  `## Amendment B`, 2026-08-27).
- **Dependencies:** **#5 (Done)** — the stored payloads #10 protects, and the retention contract
  AC2 and AC36 compose with. **#3 (Done)** — capture, and the user-defined response **this PRD's
  AC25** forbids serving to an unverified sender. **#6 (Done)** — the read surface AC18 narrows. **#2 (Done)** — the permission model AC28
  and AC33 reuse. **#4 (Done) as amended by ADR-020** — the by-reference dispatch that closed D2's
  instance. **#10 does not depend on #8, #9, #12, #13 or #14, and must not pre-empt them.**
- **Outstanding Questions: none of this PRD's own.** **Q-10-01** RESOLVED (Owner approved,
  2026-08-27) · **Q-10-02** RESOLVED (Principal Engineer, 2026-08-27) · **Q-10-04** RESOLVED by
  `## Amendment B` (2026-08-27). **Q-10-03** is open to the Designer and is not this document's.
- **Next Agent (as first written, retained):** **Designer.** `## UX Direction` is present, so under
  the mechanical routing rule
  a PM-approved `design-10` is a prerequisite for Technical Design — no exceptions. **The Designer
  must not start before the Project Owner has approved this PRD as amended**, because Amendment A
  changes the surfaces materially: a scheme selector (AC23), a rotation overlap that has to read as
  a period rather than an event (AC29), and a one-time secret display that is the single exception
  to the write-only rule (AC57).
- **Next Agent after Amendment B: the Designer again, and only after the Project Owner approves
  `## Amendment B`.** The revision is scoped, not a redesign: the signing surface moves from the
  destination row to the proxy (AC54), and AC29 gains one statement about a rotation started while
  an overlap runs — which touches the **inbound** verification surface as well as the signing one.
  Everything else in `design-10` stands. **Technical design and the signing backend are not waiting
  on this**: `plan-10` is fully approved, is already written to the Owner's ruling, and isolates the
  signing **surface** as milestone **M8b**.

## Amendment A — Project Owner rulings, 2026-08-27

Three rulings the Project Owner made directly in a review session, recorded here rather than
re-derived. The PRD was **Draft** when this amendment was written and remains Draft: **the document
as amended is what goes to the approval gate**, as one approval. Recorded per
`docs/standards/documentation.md` — amend in place, retain history, never rewrite a ruling silently.

### Ruling 1 — Q-10-01 is APPROVED. The outbound block is no longer severable.

AC30–AC39 stand exactly as written. `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`
is **RESOLVED** with the Owner's ratification recorded in it. Every trace of the severability
framing is removed from this document: the Status block, the acceptance-criteria preamble, the
AC30 section heading and its preamble, the boundary table, the Users and User Stories lists, the
UX Direction, and the Handoff. **No criterion changed** — only the condition on them.

### Ruling 2 — the two narrowings are ACCEPTED as recorded.

- **PRD-06 AC25.** The narrowing stands **as a recorded narrowing in § Consequences for approved
  documents**. The Owner does not want a formal PRD-06 amendment. PRD-06 stays Approved and is not
  edited.
- **ADR-008.** The reversal is acknowledged. **Recording it on the ADR remains the Principal
  Engineer's call** — the Owner left that where this PRD already put it.

### Ruling 3 — the V2 ruling is OVERTURNED and replaced.

The Owner rejected the shared-secret-only scheme. § *Roadmap Open Question V2* now carries the
replacement, the Owner's grounds, the **correction of the superseded ruling's factually wrong
ground 4**, and the superseded ruling quoted so the record stays complete. In summary:

| | Before Amendment A | After |
|---|---|---|
| Inbound schemes | One: a shared secret in a member-named header | **Two, closed and named: `standard-webhooks`, `shared-secret`** (AC23, AC51, AC52) |
| Signature verification | Ruled out at MVP | **In, under the published specification** (AC52) |
| Timestamp / replay window | Ruled out at MVP | **Required — it arrives with the scheme** (AC53) |
| Vendor schemes (`github`, `stripe`, `slack`) | Ruled out with everything else | **Explicitly not in MVP, added only when a real integration needs one, an Owner decision each time** (AC50) |
| Who issues an inbound secret | Not stated; an earlier suggestion that the product could generate a token for a custom sender | **Stored, never generated by us. The suggestion was withdrawn by the Owner as not a real pattern** (AC26) |
| Secret rotation | AC29: immediate, single-valued, no overlap, synchronised cutover with the sender | **AC29 replaced: two secrets, the older honoured for a bounded overlap, both directions** |
| Outbound signing | Out of scope | **In: AC54–AC64** |

**Why a closed list rather than free-form configuration.** The Owner accepted the superseded
ruling's ground 2 — that supporting each vendor's construction is unbounded — and answered it by
closing the list rather than by refusing signatures. **The header *name* is the easy part; the
variable that matters is how the signed string is constructed**, and those differ per vendor:
GitHub signs the raw body, Stripe signs `<timestamp>.<body>`, Slack signs `v0:<timestamp>:<body>`,
Standard Webhooks signs `<id>.<timestamp>.<body>` and encodes base64 rather than hex.

### The rotation-expiry ruling — Product Manager, on the Owner's instruction that an expiry be stated

The Owner ruled that two secrets may be held with the older expiring, and left the **form** of the
expiry to the Product Manager with one requirement: it must be stated. **Ruled: a fixed 24-hour
overlap, not member-configurable, with an explicit end-it-now action** (AC29). The three candidates
and why this one:

- **Fixed window — chosen.** One rule, one testable number, no new configuration field, no new
  validation range, and identical in both directions. A member rotating a secret needs a period long
  enough to update the other side without a scheduled cutover; a day is that, and it is short enough
  that a replaced secret does not linger.
- **Member-set — rejected.** It buys flexibility nobody asked for and costs a field, a range, a
  design surface, and a class of member error (a 90-day overlap is a secret that was never really
  replaced). No stated requirement wants it. AC29's shape does not foreclose it if one ever appears.
- **Until-first-use — rejected on correctness, not on cost.** Inbound it is actively wrong: an
  upstream provider rolling a secret across its own fleet will send under the new secret from one
  sender while others still use the old one, so first use of the new secret does not mean the old
  one is finished. Outbound it means nothing at all, because a signed dispatch carries both
  signatures and we cannot see which one the receiver used.
- **The end-it-now action is included** because without it the only way to kill a leaked secret
  inside the window is to rotate a second time and let the two-slot rule discard the oldest — a
  correct remedy, but one no member will find under pressure.

### What changed in this PRD

| Section | Change |
|---|---|
| Status block | Severability framing removed; Q-10-01 recorded APPROVED; the three remaining Owner-gated items restated |
| § Feature | Item 3 rewritten to two schemes; item 4 de-severed; **item 5 added** (outbound signing) |
| § Definitions | **Verification scheme**, **Destination signing secret** and **Rotation overlap** added; **Verification secret** rewritten (stored, never generated by us) |
| § Problem | **Gap 5 added** — a destination has no way to tell a dispatch came from us |
| § What earlier items delivered | Outbound-authentication row de-severed; **outbound-signing row added**; V2 row updated |
| § Goals, § Users, § User Stories | Severable qualifiers removed; closed-list, signing, one-time-display and rotation goals and stories added |
| § UX Direction | Two priorities added (**the one-time display**, **scheme choice framed by what the sender does**); the rejection-debugging priority extended for the new failure modes; rotation added as a period to be made legible; the "not the Designer's to decide" list extended |
| AC preamble | Severability paragraph replaced by the placement-and-renumbering note |
| **AC10, AC11** | Extended to the signing secret and to overlap-held secrets |
| **AC23** | **Revised** — scheme selector over a closed two-value list |
| **AC25** | **Extended** — the reject-before-capture rule is stated to cover every way either scheme can fail, including a stale timestamp and a malformed header set. Its substance is unchanged |
| **AC26** | **Revised** — secrets are stored, never generated by us |
| **AC27** | **Revised** — covers the specification's three headers as well as a member-named one |
| **AC29** | **Replaced outright** — bounded rotation overlap, both directions |
| **AC43** | **Revised** — three header rules, not two |
| **AC44** | **Revised** — scoped to the *application key*, so it is not confused with AC29's secret rotation; the "three columns to five" count replaced by naming the secrets |
| **AC50** | **Revised** — the closed list, with the vendor schemes named as out and as an Owner decision each time |
| **AC51–AC53 (new)** | The two schemes defined, and the replay window |
| **AC54–AC64 (new)** | Outbound request signing |
| § V2 | Rewritten: the Owner's ruling, the grounds, the ground-4 correction, the superseded ruling quoted |
| § Consequences | Both items recorded as Owner-accepted; the ADR-008 item no longer conditional and now larger |
| § Out of Scope | Four bullets rewritten, each flagging what Amendment A moved into scope |
| § Open Questions, § Handoff | Q-10-01 struck through as RESOLVED; the specification added as an input; the Designer's blocking reason restated |
| **AC count** | **50 → 64** |

**Not changed, and deliberately so:** AC1–AC9, AC12–AC22, AC24, AC28, AC30–AC42, AC45–AC49, § D2 in
full including its inventory snapshot, and Q-10-02, which remains open to the Principal Engineer and
non-blocking for requirement approval.

## Amendment B — the signing grain, 2026-08-27

**Status: APPROVED by the Project Owner on 2026-08-27.** This PRD was approved whole earlier the
same day; Amendment B changes approved criteria and so needed the Owner's approval in its own right,
exactly as Amendment A did at the original gate. **All four rulings below are approved as written**,
including the three that are the Product Manager's rather than the Owner's — the Owner read the
distinction in the table and approved them knowing which was whose. The pre-amendment text of AC54
and AC63 stays quoted in place as the record of what was superseded.

**Two rulings, and they have different authors. The distinction matters and is stated once here so
the Owner knows what they are approving:**

| | Ruling | Whose | What happens if the Owner strikes it |
|---|---|---|---|
| **1** | Outbound signing is **per proxy**, not per destination | **The Project Owner's**, given directly on 2026-08-27. This amendment records it; it does not propose it | The plan, three ADRs and this amendment all revert to per-destination signing, and `plan-10` needs re-work. Recorded because a Reviewer will ask who decided the grain |
| **2** | **AC29's cap of two live secrets stands** | **The Product Manager's**, as requirements author. The Owner's words do not settle it | The cap is raised to N, bounded only by expiry. `plan-10` says this costs one line of the write path and no schema, no consumer and no read-path test |
| **2a** | A rotation started **while an overlap is running** must state that the oldest secret stops being honoured immediately | **The Product Manager's**, and it follows from ruling 2 rather than standing alone | The cap still stands; the discard is simply silent, as it was before this amendment |
| **2b** | **No member-facing warning is required** about the shared trust domain that ruling 1 creates | **The Product Manager's** | A warning becomes a criterion and the Designer specifies it |

**Raised by the Principal Engineer** at
`docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`, which is RESOLVED
by this amendment. **Nothing else moves**: `plan-10` (fully approved, and already written to
ruling 1), ADR-021, ADR-022, ADR-023 and ADR-024 are not edited here, and `design-10` is the
Designer's to revise, not this document's.

### Ruling 1 — outbound signing is per proxy. The Project Owner's, verbatim.

> I want to ensure that the PE doesn't think each destination can have its own signing secret. A
> proxy has one outgoing secret that can be used for all destinations. We can rotate so the header
> contains multiple secrets until one or more expires, but that is proxy level.

Rendered into **AC54** (rewritten), **AC58**, **AC60** (confirmed, not changed), **AC63**,
§ Definitions, § Feature item 5, § UX Direction point 5 and its "not the Designer's to decide" list,
and one § Out of Scope bullet. **AC10 and AC44 change only because the defined term changed.**
**AC11 changes by more than a noun and it is called out rather than folded in:** per destination it
read as one destination failing; at proxy grain, a signing secret that cannot be decrypted means the
proxy dispatches to **none** of its destinations. That is the correct reading of fail-loudly under
the new grain — a partial fan-out where some receivers get signed traffic and some get none is the
silent state AC11 exists to prevent — and it matches what `plan-10` § *Architecture H* already
builds.

**What the ruling moves, and what it leaves exactly where it was.** Only the grain moves. The
`standard-webhooks` scheme (AC55), the product's ownership of the secret (AC56), the one-time
display (AC57), the byte-identical body (AC59), the message identity (AC60), the appears-nowhere-else
rule (AC61), the configuration-not-payload rule (AC62) and the header-precedence rule (AC64) are all
untouched. **AC30–AC39 are untouched and stay per destination** (AC31) — the credential and the
signing secret now sit at different grains on purpose, and a reader who conflates them will get both
wrong.

**The term is renamed, and that is mine rather than the Owner's.** "Destination signing secret" is
now actively misleading, so § Definitions renames it **proxy signing secret** and the three criteria
that used the old name (AC10, AC11, AC44) use the new one. No substance changes with the name.
`design-10`, ADR-021's early text and `Q-10-04` still carry the old name; they are older documents
and are not rewritten by this one.

### The trade-off in ruling 1, put in front of the Owner rather than inherited

**Under the ruling, a proxy's fan-out becomes one trust domain.** Every destination operator holds
the same secret, so any one of them can verify — and forge — traffic addressed to any other
destination of that proxy. The per-destination model did not have that property, and this is the one
part of the ruling that is a trade-off rather than a simplification. It is recorded in **ADR-023
Decision 4** and now in **AC54** itself.

**This amendment does not claim the Owner weighed it, because the Owner did not say so**, and
inventing that record would be worse than leaving the question visible. It is stated here so that
approving Amendment B is a decision made with it in view. **If per-destination isolation is wanted,
that reverses ruling 1** and this amendment should be struck rather than edited — the grain cannot be
half-moved.

**Related, and smaller: the cost of losing the one-time secret grows.** AC57's display is the
member's only chance to read the value; under the proxy grain, losing it means reconfiguring every
receiver that proxy dispatches to, not one. UX Direction point 5 is amended to say so. Regeneration
is still available and still one action, so this is a cost, not a trap.

### Ruling 2 — AC29's cap of two stands. This one is the Product Manager's.

**The question** (`Q-10-04` item 2): AC29 as approved caps live secrets at two and says "there is no
third slot"; the Owner's separate storage direction of the same day contemplates "1, 2, 3.."
relations and says the header carries multiple secrets "until **one or more** expires". A member who
rotates twice inside the 24-hour overlap produces three live secrets under an uncapped model.

**Ruled: the cap of two stands, at proxy grain, for both purposes. AC29 is re-worded, not
re-policied.** Grounds, in order of weight.

1. **The Owner's words are already satisfied by two, so they do not reach this.** With two live
   secrets the signature header does carry *multiple* signatures, and "until one or more expires" is
   true of a two-member set. The "1, 2, 3.." sentence is about **how a rotating secret is stored** —
   it was the Owner's answer to a storage question, and ADR-021 records it as ruling A on exactly
   that basis. A capability of the store is not a policy about how many secrets a member gets, and
   reading it as one would be inferring a requirement the Owner did not state.
2. **The approved requirement set is written to two in a place that is not AC29, and it was approved
   whole.** UX Direction point 8: "the member must be able to see that **two secrets** are currently
   honoured and when the **older** one stops being honoured." Uncapping makes that surface a list of
   unknown length with a per-member expiry each, which is a materially different screen with no
   stated requirement asking for it. **Amendment A's rotation-expiry ruling** rests on the same
   shape: one fixed window, one testable number, no new configuration.
3. **Uncapping removes a stated remedy.** AC29 names "replacing twice removes it at once" as the
   remedy for a compromised secret. Under an uncapped model a second rotation does **not** remove
   the compromised secret — it lives out its own 24 hours alongside the others, and the only remedy
   left is **End overlap now**. Going from two remedies to one, for the failure this criterion
   exists to handle, is a real loss and nothing in the Owner's words asks for it.
4. **Ruling 1 strengthens the case rather than weakening it.** At proxy grain the signature header
   is shared by every destination of the proxy, so an unbounded live set is an unbounded signature
   list sent to every receiver on every dispatch, and each additional live secret is another key that
   can forge traffic to the whole fan-out for a full 24 hours. Two is a bounded header and a bounded
   exposure.
5. **The cost of being wrong is asymmetric, and the cheap direction is the capped one.** `plan-10`
   Technical ruling 14 and ADR-021 Decision 4 both record that the storage model is general and the
   behaviour is narrow: raising the cap later changes one line of the write path, and no schema, no
   consumer, no read-path test and no member-facing state. Lowering it later, after members have
   relied on three-deep overlaps, changes behaviour they have come to depend on.

**Weighed and not taken — the real argument for uncapping, stated so it is not lost.** A member who
rotates at T0 is told the previous secret keeps working until T0+24. If they rotate again at T1
inside that window, the cap discards the oldest **immediately**, so the promise made at T0 is broken
early — and under ruling 1 it breaks for every destination of the proxy at once. That is a genuine
defect of the capped model, not a quibble, and it is the reason ruling 2a exists rather than the
reason to uncap: making the discard visible before the member commits to it costs one conditional
line of copy, where uncapping costs a screen, a wider exposure window and a lost remedy.

**Ruling 2a, in full (added as a bullet of AC29).** The surface that begins a replacement or a
regeneration **while a previous secret is still honoured** must state that the oldest secret stops
being honoured immediately. Wording and placement are the Designer's; that it is said **before the
save** is not. Note what this touches: it applies to the **inbound verification** rotation surface
(`design-10` Flow B step 2 / Screen 1, which today states the 24-hour promise but not the discard) as
well as to the signing surface that Amendment B displaces. **The Owner can strike 2a without
disturbing the cap** — the behaviour would simply stay silent, as it is today.

**AC29's scope is not touched anywhere by this amendment.** The **destination credential (AC33)
remains excluded by name**, remains per destination (AC31), and remains replaced immediately and
single-valued. Every ground for that exclusion — it is presented rather than verified or computed, a
request carries exactly one credential value, and there is nothing on the wire for an overlap to
mean — is untouched by the signing grain moving. **Said explicitly because `Q-10-04` asked for it to
be said explicitly.**

### Ruling 2b — no member-facing warning is required for the shared trust domain. Also mine.

A member enabling signing on a proxy hands the same key to every destination operator of that proxy.
**Ruled: #10 requires no warning, banner or confirmation step for it.** Grounds: no stated
requirement asks for one; the control now lives on the proxy rather than on a destination row, so
its scope is legible from where it is (the same way AC13's per-proxy sensitive-field list needs no
warning that it applies to every payload the proxy stores); and copy that explains a deliberate
design as though it were a limitation is the shape PRD-11 `## Amendment B` (ii) already ruled
against on its own surface. **This is a Product Manager call on a
security-shaped consequence, so it is flagged rather than absorbed** — if the Owner wants the surface
to say the secret is shared across destinations, say so at this gate and it becomes a criterion the
Designer specifies. Nothing else in Amendment B changes if it does.

### What changed in this PRD

| Section | Change |
|---|---|
| Status block | Amendment B recorded as **awaiting Owner approval**, with the list of what it revises and the statement that nothing is renumbered |
| § Feature | Item 5 gains one sentence: the secret a destination verifies against is the proxy's |
| § Definitions | **Destination signing secret** renamed **Proxy signing secret** and re-grained; the old name flagged as pre-amendment |
| § UX Direction | Point 5 re-grained, with the widened cost of losing the one-time secret; the "not the Designer's to decide" list gains the proxy grain (AC54) and the two-secret cap (AC29) |
| **AC10** | Term renamed. No substance |
| **AC11** | The signing clause re-grained — a proxy whose signing secret cannot be decrypted dispatches to **none** of its destinations rather than failing one |
| **AC29** | Opening re-grained; the cap bullet re-worded for a storage model that could hold more, with "exist" distinguished from "are honoured"; **one bullet added** (ruling 2a); the AC33 exclusion restated as untouched |
| **AC44** | Term renamed. No substance |
| **AC54** | **Rewritten** — per proxy, one secret for every destination, no per-destination enable/secret/rotation state, and the trust-domain consequence stated in the criterion. Pre-amendment text quoted in place |
| **AC58** | Re-grained; **"both" confirmed as exact**, since the cap of two stands |
| **AC60** | **Confirmed, not changed** — the secret is the proxy's, the message identity stays per delivery |
| **AC63** | The subject moves to "a destination **of a proxy without** signing". Guarantee unchanged. Pre-amendment text quoted in place |
| § Outbound-signing block preamble | States that the credential and the signing secret now sit at different grains |
| § Consequences for approved documents | A bullet added recording that `design-10`'s signing surface is displaced and that the revision is the Designer's |
| § Out of Scope | Two bullets added — per-destination signing secrets/toggles/rotation state, and more than two live secrets |
| § Open Questions, § Handoff | `Q-10-04` recorded RESOLVED; outputs and outstanding-questions lists updated; a second Next Agent note for the post-Amendment-B Designer revision |
| **AC count** | **64 → 64. Nothing was added and nothing was renumbered** — `Q-10-01`, `Q-10-04`, `docs/status.md`, `plan-10` and four ADRs all cite these numbers |

**Not changed, and deliberately so:** AC1–AC9, AC12–AC28, AC30–AC43, AC45–AC53, **AC55, AC56, AC57,
AC59, AC61, AC62, AC64**, § Problem, § Goals, § Users, § User Stories, § V2 in full, § D2 in full,
§ Amendment A in full — including its history, its superseded-ruling quotations and its own "what
changed" table, which record what was true at that gate and must not be rewritten — and the whole of
`plan-10`, ADR-021, ADR-022, ADR-023, ADR-024 and `design-10`, none of which this amendment edits.

### What approving Amendment B does, and what it does not

1. **It makes the approved requirements say what the Owner already ruled.** The grain stops being a
   contradiction recorded only in a plan and three ADRs, and a Reviewer reading AC54 against a
   proxy-level implementation stops finding a Major that is not one.
2. **It unblocks the Designer**, and only the Designer. `plan-10` remains fully approved and is
   already written to the ruling; **M8b — the signing surface — is the only milestone waiting**, and
   it waits on the Designer's revision, which waits on this approval. Ruling 2a adds one line of copy
   to the inbound rotation surface as well; sequencing that against M6 is the Principal Engineer's.
3. **It settles AC29's cap as a requirement rather than an artefact**, so the test that pins two
   rows is testing a criterion and can say which one.
4. **It does not re-open anything else in #10.** Not `## Amendment A`, not V2, not D2, not
   AC30–AC39, and not AC33's exclusion from the rotation overlap.
5. **What to strike if you disagree**, so the amendment is not edited into an inconsistent state:
   striking **ruling 1** reverts the grain and this whole amendment with it; striking **ruling 2**
   raises the cap and takes AC29's added bullet with it; striking **2a** or **2b** alone leaves
   everything else standing.
