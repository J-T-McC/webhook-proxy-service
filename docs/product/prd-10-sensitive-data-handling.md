# PRD: Sensitive data handling

- **Status:** **Draft — awaiting Project Owner approval.** Not approved, and not approvable by
  the Product Manager. Three things in this document need the Owner specifically rather than
  riding along with an ordinary requirements sign-off, and each is written to be approved or
  struck on its own:
  1. **§ Outbound destination authentication (AC30–AC39)** — a **severable** section carrying a
     **roadmap change**, escalated by `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`.
     Striking it leaves every other criterion in this PRD standing and unmodified.
  2. **§ Roadmap Open Question V2 — settled here** — the inbound verification scheme, ruled by
     the Product Manager as the Owner's proxy. Approving this PRD ratifies it.
  3. **§ Consequences for approved documents** — this PRD **narrows PRD-06 AC25** and **narrows a
     stated property of ADR-008**. Both are named there rather than applied silently.
- **Author:** Product Manager
- **Date:** 2026-08-27
- **Approved by / date:** —
- **Backlog item:** Roadmap #10 (`docs/product/roadmap.md`). Depends on **#5 (Done)**.
- **Build-ahead status:** written against shipped code only. #1, #3, #4, #5, #6, #7 and #11 are
  merged; **#8 is Owner-deferred with zero implementation** and **#9 has not started**, so no
  criterion here depends on either. Where their absence bounds what #10 can promise, the bound is
  stated as a criterion (AC22, AC48) rather than left to be discovered.
- **Next gate: the Designer.** `## UX Direction` is present, so a PM-approved `design-10` is a
  prerequisite for Technical Design.

## Feature
Three protections over the data this service already holds and receives, plus — subject to the
Owner ruling at Q-10-01 — one new outbound capability:

1. **Encryption at rest is guaranteed as a property of the system**, not as a property of three
   named columns: no durable at-rest copy of payload content exists anywhere in plaintext,
   wherever the dispatch mechanism puts it.
2. **Known and user-defined sensitive fields are visually obfuscated** wherever payload content is
   shown, without altering what is stored or what is delivered.
3. **An incoming webhook can be verified with a shared secret** before it is accepted, at an MVP
   level.
4. *(Severable, Owner-gated.)* **A destination can carry a credential** this service presents when
   it dispatches, so a secret no longer has to be smuggled through `destinations.url`.

## Definitions
Fixed vocabulary. Every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Payload content** | PRD-05 AC6's definition, unchanged: an event's raw body, its captured inbound headers, and any stored dispatched output body for the same event. Not the non-content descriptors AC6 permits to survive erasure (method, content type, byte size, times, identifiers, ownership). |
| **Durable at-rest copy** | A copy of payload content that survives the process that wrote it **and** is retained under a policy rather than for the life of one unit of work. ADR-020 § Impact fixed this distinction and the Project Owner refined it on 2026-08-26: duration is part of the definition, so a store whose entries expire in minutes to an hour is short-term. **The threshold in between is unset and is the Owner's** — see AC9. |
| **The at-rest floor** | PRD-05 AC15's protection floor: at least the encryption the raw body has carried since #3 (ADR-010 Amendment B), extended to captured headers by PRD-05 AC22 and implemented across three columns by ADR-014. |
| **Obfuscated** | Rendered so that no part of the value and no information about its length is disclosed. A display property only — see AC17. Distinct from **redacted**, which would alter stored or delivered data and which #10 does not do anywhere. |
| **Sensitive field** | A field of a stored payload whose **name** appears either in the product's default list (AC12) or in the proxy's member-maintained additions (AC13). Matching is by name, never by value (AC14). |
| **Verification secret** | The per-proxy shared secret an incoming request must present to be accepted (AC23). **Not** the ingest URL's own path token, which is ADR-006's and is untouched by this PRD. |
| **Destination credential** | The per-destination secret this service presents when dispatching (AC30). Configuration, not payload content — so retention never touches it (AC36). |

## Problem
Four gaps, each traceable to a document rather than asserted.

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
| **Inbound verification secret (V2)** | **#10** | **This PRD** |
| **System-wide at-rest guarantee (D2)** | **#10** | **This PRD** |
| **Outbound destination authentication** | **#10, if the Owner ratifies** | **This PRD, severable** |
| Key rotation / re-encryption tooling; per-team key policy | assigned to #10 by PRD-05 § Out of Scope | **Deferred out of #10 with a named cost** — AC44, AC45 |

## Goals
- The at-rest guarantee is expressed so that a **future** change cannot silently break it: stated
  over "any durable at-rest copy, wherever the dispatch mechanism places it", never over a table
  name (roadmap **V3** may change the queue backend).
- A member can see a stored payload for debugging **without** the secrets inside it being
  displayed, and can extend the hidden set for their own proxy.
- Obfuscation never changes what a destination receives. A protection that alters delivered data
  would be a defect, not a feature.
- A proxy owner can require that an incoming webhook prove it is from the expected sender, with a
  mechanism small enough to be complete at MVP rather than a per-vendor programme of work.
- Every secret this feature introduces carries the same at-rest floor as payload content and is
  never displayed after it is saved.
- Nothing here depends on #8 or #9, and nothing here pre-empts them.

## Users
- **Team member** — configures obfuscation and verification for their proxies; sees obfuscated
  payloads; is the one debugging when a sender is rejected.
- **Team Owner / Admin** — same, without the Member ownership limit on configuration changes
  (Q-02-01 / ADR-009 Amendment A2.2).
- **Upstream sender** — a third-party system that must now present the verification secret if the
  proxy requires one, and is rejected if it does not.
- **Destination** *(severable section only)* — receives an additional header it can authenticate
  the request with.
- **The product (system)** — holds the secrets, applies obfuscation at the point content leaves
  the server, and must keep both out of jobs, attempt records, analytics and logs.

## User Stories
- As a team member, I want passwords, tokens and card numbers inside a stored payload hidden when
  I look at it, so debugging a delivery does not put a customer's secret on my screen.
- As a team member, I want to add my own field names to that hidden set for my proxy, because the
  product cannot know that my vendor calls it `ssn_last4`.
- As a team member, I want obfuscation to be a display decision only, so my destinations keep
  receiving the payload exactly as they do today.
- As a team member, I want to require a shared secret on my ingest URL, so knowing the URL is not
  by itself enough to post to my proxy.
- As a team member, I want a rejected request to be rejected *before* anything is stored, so a
  request that failed authentication never becomes an event in my history.
- As a team member, I want every secret I save to be unreadable afterwards — by me included — so
  a compromised session cannot harvest them.
- *(Severable.)* As a team member, I want to give a destination a credential, so I stop having to
  paste it into the destination URL where it sits in plaintext.
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
   Every secret field in this feature — the verification secret, and the destination credential if
   merged — behaves the same way: enter once, see *set* and when it changed, never see the value
   again, replace at will. A member who expects to be able to check what they typed will be wrong,
   so the interface has to say so **before** they save, not after.
5. **A rejected inbound request is the hardest thing to debug in this feature, and the interface
   should not pretend otherwise.** #10 ships no analytics or notification surface for rejections
   (AC46), which is a real cost. The configuration surface should therefore be explicit about what
   the sender must send — header name and the fact of a secret — so a member can check their
   sender's configuration against it without needing a log.

**Not the Designer's to decide, because they are ruled here:** that obfuscation survives the
whole-payload reveal (AC18); that there is no per-field reveal for anyone (AC20); that no secret
is ever redisplayed (AC26, AC33); and that obfuscation shows nothing about a value's length
(AC16).

## Acceptance Criteria

> **Numbering is append-only** and follows the house rule set by PRD-05 and PRD-11. **AC30–AC39
> are severable**: if the Project Owner declines the Q-10-01 merge, they are struck as a block and
> nothing else here changes. Their numbers are not reused.

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
10. **The key-lifecycle rule extends to every secret this feature introduces.** ADR-010
    Amendment B's binding `APP_PREVIOUS_KEYS` rule — a prior key is never dropped until every
    encrypted value has been re-encrypted under the current one — covers the verification secret
    (AC26) and the destination credential (AC33) exactly as it covers payload content. **This
    widens the key-lifecycle surface, and AC44 states the cost of widening it without shipping
    rotation tooling.**
11. **Losing a secret fails loudly, never silently.** If a secret cannot be decrypted, the
    affected operation fails visibly rather than proceeding as though no secret were configured.
    A proxy with an undecryptable verification secret must not fall back to accepting unverified
    requests, and a destination with an undecryptable credential must not fall back to dispatching
    without it. *(This is the class of failure ADR-014 Decision 7 guards for payload content; it is
    asserted here for secrets because the failure mode is silent authentication bypass.)*

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

23. **A proxy may require a verification secret on incoming requests.** The proxy owner configures
    a **header name** and a **secret value**; a request is verified when the named header is
    present and its value matches exactly. Comparison is exact and constant-time. **This is the
    whole of the MVP scheme** — see § V2 for the ruling and its grounds.
24. **Verification is optional and off by default. Existing proxies are unaffected.** A proxy with
    no verification secret configured behaves **exactly as it does today**: same acceptance, same
    capture, same response, same dispatch. Nothing is migrated and no proxy is opted in.
25. **A failed verification is rejected before capture, and nothing else happens.** The request is
    rejected with **HTTP 401** and a fixed, non-configurable body; **no `webhook_events` row is
    created**, no delivery is created, no dispatch occurs, and **the proxy's user-defined #3
    response is not used**. *(The last clause is the load-bearing one: returning the configured
    success response to an unverified sender would make verification decorative.)*
26. **The verification secret is write-only and encrypted at rest.** After saving, the value is
    never redisplayed to any role. The surface shows that a secret is **set** and when it last
    changed; the header name remains visible, because the sender has to be configured to match it.
    The secret carries the at-rest floor (AC1) and the key rule (AC10).
27. **The verification header is never forwarded to destinations.** Whatever header name the member
    chooses is stripped from the outbound header set, in addition to ADR-008's existing fixed strip
    list. *(ADR-008's list is a constant and cannot anticipate a member-chosen name. Without this
    criterion, a proxy owner who enables verification would broadcast their own secret to every
    destination on every event — which is worse than not having the feature.)*
28. **Configuring verification is gated by the existing proxy update permission**, including the
    Member ownership rule (Q-02-01, ADR-009 Amendment A2.2). No new permission. *(Same treatment
    PRD-06 gave retry-policy configuration: this is proxy configuration.)*
29. **Rotation is immediate and single-valued.** Replacing the secret takes effect at once and the
    previous value stops being accepted. **#10 supports no overlap window and no second accepted
    secret**, so a rotation requires the sender to be updated at the same time or requests will be
    rejected in between. **This cost is stated rather than discovered** — see § Out of Scope.

### Outbound destination authentication — **SEVERABLE, Owner-gated (Q-10-01)**

> **These ten criteria stand or fall together.** They rest on the Project Owner ratifying the
> roadmap change recorded in `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`.
> If the Owner declines, AC30–AC39 are struck as a block, the capability becomes its own roadmap
> item, and **no other criterion in this PRD is affected** — nothing above or below depends on
> them.

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
43. **Inbound header forwarding is unchanged except for the two rules this PRD adds** — AC27's
    strip of the verification header, and AC38's credential precedence. ADR-008's existing policy
    and its fixed strip list are otherwise untouched.

### Scope boundaries

44. **No key rotation or re-encryption tooling.** PRD-05 § Out of Scope assigns it to #10; #10
    **defers it**, and states the cost rather than hiding it: AC10 widens the key-lifecycle surface
    from three columns to five, and the binding `APP_PREVIOUS_KEYS` guard is what prevents data
    loss in the absence of tooling. **No key rotation may be performed until that tooling exists.**
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
50. **No verification standard beyond AC23** — see § V2 for what was ruled out and why.

## Roadmap Open Question V2 — settled here

**V2, verbatim from `docs/product/roadmap.md`:**

> V2. **Webhook verification-token standards** — which standards at MVP? Gates #10. *(Vision Open
> Question 2.)*

**Vision Open Question 2, verbatim:** "Webhook verification-token standards — which standards to
support at MVP (existing standards to be reviewed)."

### Ruling — one scheme: a shared secret in a member-named header. No third-party signature standards at MVP.

Settled by the Product Manager as the Owner's proxy, rendered into **AC23–AC29**. **Approving this
PRD ratifies it**, in the same way Owner approval of PRD-05 ratified V4, V5 and V6 and Owner
approval of PRD-11 ratified D-11-4..7. It is called out in the Status block because it is a
security-shaped decision and should be approved deliberately rather than absorbed.

**The grounds, in order of weight.**

1. **Both approved documents say "token", and both say "MVP".** The roadmap: "incoming webhooks
   can be verified with a **token** at an **MVP level**." The vision: "support incoming webhook
   verification **tokens** at an **MVP level**." A shared secret in a header is a token. An HMAC
   signature is not — it is a computation over the body with its own canonicalisation, timestamp
   tolerance and replay window. Reading "which standards" as licence to implement signature
   standards reads past the word both documents actually chose.
2. **"Which standards" has no bounded answer, and that is the trap.** Stripe's `Stripe-Signature`,
   GitHub's `X-Hub-Signature-256`, Slack's `v0=` scheme and Standard Webhooks are four different
   verification algorithms with four different header formats and four different tolerance rules.
   Supporting "standards" plurally is a per-vendor programme that grows for as long as the product
   has customers, and **nothing in the vision or the roadmap commits to any specific vendor** —
   Stripe appears only as an example of multi-event-type ingestion, never as an integration
   requirement. Picking a subset now would be inventing a requirement the Owner did not state.
3. **The unguessable ingest URL is already the first factor, so the second factor does not have to
   carry everything.** ADR-006 settled ingest-URL generation and security. AC23 adds an
   independent secret that an attacker who has learned a URL still does not have. That is a real
   increase in assurance, and it is the increase the roadmap line asks for.
4. **The composition that would make signatures specifiable does not exist yet.** A body signature
   must be computed over exact bytes. #9 (normalisation) has not started and #8 (mapping) is
   deferred with no implementation, so there is no canonical representation to sign against and no
   way to test one. Specifying signature verification now would be specifying against a system
   that is not there.

**What is explicitly ruled OUT at MVP, by name, so it is not re-argued:** vendor signature
standards (Stripe, GitHub, Slack, Standard Webhooks/Svix, or any other); HMAC verification of any
kind; timestamp or replay-window enforcement; IP allow-listing; mutual TLS; and multiple
simultaneously-accepted secrets (AC29). **None of these is rejected on merit** — each is a
reasonable later item, and AC23's shape forecloses none of them, because a scheme selector can be
added to a proxy that today has exactly one scheme.

**If the Owner wants a signature standard at MVP, this is the moment to say so.** It is a
materially larger feature, it would need its own question document naming which standard and for
which senders, and it would need #9's canonical representation to be settled first. I am not
recommending it.

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
document is edited by this PRD.** Both changes take effect only if the Owner approves it.

- **PRD-06 AC25 is narrowed.** AC25 says activating the reveal "exposes the **full raw payload**".
  Under **AC18**, it exposes the full payload **with sensitive values obfuscated**. This is the
  intended reading — PRD-06 AC22 explicitly reserved all field-level handling to #10, and PRD-06
  § Out of Scope calls its own mask "presentation, not policy" — but the words "full raw payload"
  will be read literally by a Reviewer, so the narrowing is named here. **PRD-06 stays Approved and
  is not reopened**; if the Owner prefers this recorded as a PRD-06 amendment instead, that is the
  Owner's call at this gate.
- **ADR-008's "No header is added" ceases to be true**, but only if AC30–AC39 survive the Owner
  gate. The `DeliveryUnit::outboundHeaders()` docblock states plainly that the outbound set is the
  inbound set minus a strip list, with no header added. AC30 adds one. **Recording the reversal on
  the ADR is the Principal Engineer's**, per `docs/standards/documentation.md` — amend or supersede
  is their call, not mine. AC27's strip of the verification header extends the same list and is the
  same kind of change.
- **Nothing else is disturbed.** ADR-006 (ingest URL), ADR-014 (payload columns and the cleaned
  signal), ADR-017 (the read surface and fetch-on-reveal), ADR-020 (by-reference delivery),
  PRD-05's retention contract and PRD-02's permission model are all relied on unchanged. #10 adds
  **no** new permission (AC20, AC28) and **no** new payload store (AC3).

## Out of Scope
Each names where it goes, or why nothing owns it yet.

- **HMAC / signature-based inbound verification, vendor standards, timestamp and replay-window
  enforcement, IP allow-listing, mutual TLS** — ruled out at MVP by § V2. A later item, needing
  #9's canonical representation first.
- **Multiple simultaneously-accepted verification secrets / a rotation overlap window** — AC29.
  The cost is stated there: a rotation is a synchronised change with the sender.
- **Outbound request signing (HMAC over the dispatched body)** — not at MVP even if AC30–AC39
  survive; Q-10-01 sub-question 1 settles the credential as a static secret. Its natural home is
  after #8, so a signature can be computed over the mapped bytes.
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
Question IDs Q-10-0x. **One is escalated to the Project Owner and gates approval of one section;
one is technical and gates technical design only, not requirement approval.**

- **Q-10-01 (Project Owner — scope) — ANSWERED by the Product Manager and ESCALATED, 2026-08-27.
  Gates § Outbound destination authentication (AC30–AC39) only.** Doc:
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`. Does outbound
  destination authentication belong in #10? **Ruling: merging it changes the roadmap rather than
  interpreting it, so it is the Owner's call, not the Product Manager's.** The merge is
  **recommended**, and all four sub-questions are settled conditionally (AC30–AC39) so the Owner
  approves or strikes something concrete. **The ruling point is this PRD's approval gate.** If
  struck, AC30–AC39 go as a block and nothing else in this PRD changes.
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
- **V2 — SETTLED in this PRD** (§ Roadmap Open Question V2), not escalated. Owner approval
  ratifies it.
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
  Amendment B (the binding `APP_PREVIOUS_KEYS` rule AC10 widens) ·
  `database/migrations/2026_07_30_000002_create_destinations_table.php` and
  `docs/plans/plan-08-payload-mapping.md` line 419 (the `destinations.url` exposure, AC39) ·
  `docs/standards/documentation.md`.
- **Outputs:** this PRD ·
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md` (**Answered and
  ESCALATED**, 2026-08-27) · `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md`
  (**OPEN**, Principal Engineer).
- **Dependencies:** **#5 (Done)** — the stored payloads #10 protects, and the retention contract
  AC2 and AC36 compose with. **#3 (Done)** — capture, and the user-defined response **this PRD's
  AC25** forbids serving to an unverified sender. **#6 (Done)** — the read surface AC18 narrows. **#2 (Done)** — the permission model AC28
  and AC33 reuse. **#4 (Done) as amended by ADR-020** — the by-reference dispatch that closed D2's
  instance. **#10 does not depend on #8, #9, #12, #13 or #14, and must not pre-empt them.**
- **Outstanding Questions:** **Q-10-01** — Owner, gates AC30–AC39 only, ruled at this approval
  gate. **Q-10-02** — Principal Engineer, technical, non-blocking for requirement approval.
- **Next Agent:** **Designer.** `## UX Direction` is present, so under the mechanical routing rule
  a PM-approved `design-10` is a prerequisite for Technical Design — no exceptions. **The Designer
  must not start before the Project Owner has approved this PRD**, because Q-10-01's outcome
  decides whether there are two secret-entry surfaces or one.
