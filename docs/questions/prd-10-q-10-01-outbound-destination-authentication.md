# Q-10-01: Outbound destination authentication has no home on the roadmap — does it belong in #10?

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Project Owner
- **Directed To:** Product Manager
- **Required By:** Before #10's PRD is written. The answer changes that PRD's shape rather than adding a detail to it, so it cannot be settled during drafting.
- **Priority:** Medium
- **Status:** **RESOLVED — 2026-08-27. The Project Owner APPROVED the merge.** Outbound
  destination authentication belongs in roadmap item #10. `docs/product/prd-10-sensitive-data-handling.md`
  § *Outbound destination authentication* (**AC30–AC39**) **stands as written, is no longer
  severable**, and all four sub-questions are settled as the Product Manager answered them below.
  See § Project Owner ruling.
- **History:** Answered by the Product Manager and **escalated** to the Project Owner on
  2026-08-27 — the scoping call was made (merging **changes the roadmap rather than interpreting
  it**, so under `CLAUDE.md` it was not the Product Manager's to decide), the merge was
  **recommended**, and the four sub-questions were settled **conditionally** so the Owner had a
  concrete thing to approve or strike. The § Answer below is that escalation, retained unedited.

## Question

Item #10 covers authenticating webhooks **coming in**. Nothing on the roadmap covers authenticating the webhooks this service **sends out**.

Should optional outbound authentication credentials on destinations — a token, or a signing secret, that this service presents when it dispatches to a destination URL — be folded into #10's scope, or become a separate roadmap item?

The Project Owner's stated leaning is **to merge it into #10** (2026-08-27). That is recorded here as a preference, not as the ruling; the scoping call is the Product Manager's, subject to the escalation noted at the end of this document.

If the answer is "merge into #10", the PRD needs to say at minimum:

1. **What the credential is.** A static bearer token presented as a header is the smallest thing that works. An HMAC signature over the request body — the pattern this service is itself on the receiving end of, and which V2 is about for the inbound direction — is a different and larger feature. These are not the same requirement and the PRD should not leave the choice to implementation.
2. **Whether it is per-destination or per-proxy.** Destinations are the rows that carry `url` and `http_method` today, and a proxy fans out to several of them, so per-destination is the shape the data model suggests; that is an observation about the schema, not a requirements ruling.
3. **What the user sees after saving one.** #10 is already the item that owns field obfuscation, so a credential written once and never redisplayed is consistent with the rest of the item — but "write-only, never redisplayed" versus "revealable by a permitted role" is a requirements decision with a permissions dimension (item #2's roles), not a UI detail.
4. **Whether existing destinations are affected.** Presumed no — the feature is optional and additive, and destinations that need no authentication continue to work untouched. Worth stating explicitly so it is not re-litigated at review.

## Context

**The inbound half is already covered, and is not what this question is about.** The roadmap's item #10 reads, verbatim: "Stored payloads are encrypted, known and user-defined sensitive fields are visually obfuscated, and incoming webhooks **can be verified with a token** at an MVP level." Its build-ahead note places the mechanism precisely — "Incoming verification tokens sit on the #1 ingest path as a pre-processing step and are gated by the token-standards question (V2)" — and V2 ("Webhook verification-token standards — which standards at MVP?") remains open and gates #10. All of that is about traffic arriving at this service.

**The outbound half appears nowhere.** No roadmap item, PRD, design, plan or ADR describes attaching credentials to a dispatched webhook. The schema agrees: `database/migrations/2026_07_30_000002_create_destinations_table.php` creates `destinations` with `proxy_id`, `team_id`, `url`, `http_method` and timestamps, and no later migration adds a credential column. The gap was found by searching for it, not by reading a document that flags it.

**Today the only way to authenticate to a destination is to put the secret in the URL.** `docs/plans/plan-08-payload-mapping.md` line 419 acknowledges this in passing, describing an exposure as "the same class of exposure `destinations.url` (which may carry a token in its query string)". So the capability is not absent in practice — users can already smuggle a credential through the URL. It is absent as a *designed* capability, which means the credential currently lives in plaintext in `destinations.url`, in the database, and anywhere the destination URL is displayed. Whatever is decided here, that existing exposure is a fact about the system now, not a consequence of adding the feature.

**Why #10 specifically, rather than a new item.** #10 already owns encryption at rest and field-level obfuscation, and it already inherits two security obligations from upstream items: #5's deferred concern D2 gates its PRD, and #3 left inbound headers plaintext explicitly "until #10". An outbound credential needs exactly that same machinery — encrypted storage, obfuscated display, and a rule about which roles can reveal it. Splitting it into its own item would either duplicate that machinery or serialise the two items behind each other. The counter-argument the Product Manager should weigh: #10 is already a large item carrying an open question (V2) and an inherited gate (D2), and widening "sensitive data handling" to include an outbound *feature* changes it from a data-protection item into a data-protection-plus-capability item. That is a real scope question and is the reason this is being asked rather than assumed.

**Sequencing note.** #10 is currently Backlog with no PRD started, so this question is being raised at the cheapest possible moment. The Product Manager is not being asked to reopen anything.

## Escalation

If the Product Manager judges that merging this into #10 changes the roadmap rather than interpreting it, `CLAUDE.md` puts that decision with the Project Owner ("Owner approval only for PRDs, releases, and major decisions"). The Owner has already stated a leaning toward merging, but has not ruled, and a stated leaning is not an approval. Record the ruling here and reflect it in `docs/status.md`'s #10 row either way.

## Answer

**Product Manager, 2026-08-27.**

### Ruling — the scoping half, which is mine

**Merging outbound destination authentication into #10 is a change to the roadmap, not an
interpretation of it. I therefore do not rule it merged. I recommend the merge, and mark it for
the Project Owner at PRD-10's approval gate.**

The reasoning, in order of weight.

**1. It fails the test the project has already used for this exact judgement.** Deferred concern
D2 was carried onto #10 on an explicit test, stated in `docs/product/prd-05-payload-storage-retention.md`
and in `docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md`: "a durable plaintext copy of
a stored payload is **within the plain meaning** of that line, so this is a scoping of #10, not an
addition to it." Applying the same test here gives the opposite answer. Item #10's approved line
has three clauses — stored payloads are encrypted; known and user-defined sensitive fields are
visually obfuscated; incoming webhooks can be verified with a token. An outbound credential is not
a stored payload, is not a sensitive field inside a payload, and is not incoming-webhook
verification. It is outside the plain meaning of all three. The build-ahead note does not reach it
either: it places encryption and obfuscation on "the raw+dispatched payloads defined at #5" and
places verification tokens "on the #1 ingest path". Both are inbound or at-rest. Nothing in the
approved text points outward.

**2. The distinction that matters is capability versus protection, and it is not a fine one.**
Everything else in #10 changes how data the system **already holds or already receives** is
protected or displayed. Outbound authentication makes the service **do something it cannot do
today**: hold a new class of secret, and add a header to an outbound request that ADR-008's
`DeliveryUnit::outboundHeaders()` docblock currently states plainly it never does ("No header is
added"). That is a new capability with a new data-model surface, and the roadmap is the document
that says which capabilities the product gets and in what order.

**3. The roadmap's own precedent puts this class of change with the Owner.** Two post-approval
scope changes have been made to `docs/product/roadmap.md`, and both are recorded as the Owner's:
item #1 broadened to absorb fan-out "per Project Owner decision", and item #2 trimmed and reframed
"per Project Owner direction". In each case the Product Manager wrote the change down; the Owner
made it. There is no precedent for the Product Manager widening a backlog item into new capability
on their own authority, and I am not creating one.

**4. Two independent `CLAUDE.md` gates land on it anyway.** Storing a credential the service
presents to a third party is a **security** decision, and it requires a new column on
`destinations`, which is a **data-model change**. Both are named in `CLAUDE.md`'s reserved list.
Even if the scoping question were mine, these would still be the Owner's, so ruling "merged"
myself would settle the cheap half and leave the expensive half unruled — the worst of both.

**5. The Owner's stated leaning is not weakened by any of this, and I share it.** Everything the
question document argues for the merge is correct: #10 already owns encrypted-at-rest storage,
obfuscated display, and the role dimension that a credential needs, and splitting the work would
either duplicate that machinery or serialise two items behind each other. The counter-argument it
raises — that #10 is already large, carrying V2 and D2 — is real but weaker than it looks after
2026-08-27, because **ADR-020 has since removed most of D2's substance** (the delivery job now
carries two integers, so no payload reaches any queue or failed-job store; see PRD-10 § *D2*). #10
is therefore lighter than it was when this question was raised, not heavier. **My recommendation
is to approve the merge.**

**What escalation costs, and why it is small.** Not much, and less than the alternative. The
question was raised at the cheapest possible moment, and the ruling point is a gate the Owner has
to pass through regardless — a PRD needs Owner approval either way. So the merge does not add a
gate, it adds a section to one that already exists. If the Owner strikes the section, it becomes
its own roadmap item and every other criterion in PRD-10 stands untouched; that severability is a
property I have built into the PRD rather than a promise made here.

### The four sub-questions — settled conditionally, so there is something to approve

All four are settled in `docs/product/prd-10-sensitive-data-handling.md` § *Outbound destination
authentication* and are stated here in summary. **Each is conditional on the Owner ratifying the
merge**, and none of them is left to implementation. Where a sub-question had a genuinely open
choice I have made it as the Owner's requirements proxy and given the ground; the Owner may
overturn any of them at the same gate without disturbing the others.

**(1) What the credential is — a static secret presented as a request header. Not an HMAC
signature over the body.** Grounds: it is the smallest thing that works, which is the posture the
roadmap sets for #10's other credential-shaped clause ("verified with a token **at an MVP
level**"). An HMAC scheme is not one decision but a family of them — canonical string, header
format, timestamp tolerance, replay window, and a different answer per vendor — and it would have
to be computed over the exact dispatched bytes, which is a composition with payload mapping (#8,
Owner-deferred, zero implementation) that cannot be specified or tested today. The credential is
configured as **a header name plus a secret value**, both member-supplied, with the value sent
verbatim so a member can enter either `Bearer abc123` or a bare key without the product inventing
a scheme-prefixing rule. Header name defaults to `Authorization`. Outbound signing is named as an
explicit deferral, not silence.

**(2) Per-destination or per-proxy — per-destination.** Grounds, and this is a requirements ruling
rather than the schema observation the question correctly declined to treat as one: a proxy fans
out to destinations that are typically **different systems belonging to different parties**, so one
credential per proxy would present every destination's operator with a secret that also opens the
others. R3 fixes payload *structure* as a per-proxy property; a credential is not payload
structure, it is a property of the endpoint being called. Per-destination is the only grain that
does not create a new exposure while closing one.

**(3) What the user sees after saving — write-only. Never redisplayed, to any role, including the
team Owner.** This is the sub-question with the permissions dimension, and it is settled by
**declining to introduce a role rather than by naming one**. Grounds: PRD-06 AC25 and ADR-017
record the Project Owner explicitly ruling out a distinct reveal permission for the payload
viewer, so "revealable by a permitted role" would mean inventing the first such permission in the
product for the one field that least needs reading; `TeamPermission` has no case that would carry
it and this project has no superadmin role at all (recorded against the Horizon work, 2026-08-27).
A member can **replace** a credential but never read one. The surface shows **set / not set** and
when it was last changed, never the value or its length. Who may set or replace it is the
**existing `proxy:update` permission, including the Member ownership rule from Q-02-01** —
destination configuration is proxy configuration, which is exactly how PRD-06 gated retry-policy
configuration. So the role is named, and it is one that already exists.

**(4) Whether existing destinations are affected — no, and it is stated as a criterion rather than
left to review.** A destination with no credential is dispatched with a **byte-identical** request
to today's. No migration, no backfill, no forced move. Stated with it, because the two get
confused: adding the column does **not** clean up the `destinations.url` exposure. Secrets already
embedded in a query string stay exactly where they are, in plaintext, and #10 neither detects,
migrates, warns about, nor removes them. That is carried into the PRD as a current fact about the
system with an explicit non-requirement attached.

### What I am NOT doing here

Not reopening #10's other scope, not touching the roadmap file, and not ruling V2 in this document
— V2 is answered in PRD-10 where it belongs. And I am not treating the Owner's leaning as an
approval: `CLAUDE.md` is explicit that no agent message is consent, and the question document
itself already records the leaning as a preference. If the Owner ratifies the merge at PRD-10's
approval gate, this document's Status becomes RESOLVED with that date and `docs/status.md`'s #10
row records the outcome. If the Owner declines, the same happens with the opposite outcome and the
capability becomes its own backlog line.

## Project Owner ruling — 2026-08-27

**APPROVED. Outbound destination authentication is merged into roadmap item #10.**

The Owner reviewed the escalation and ratified the merge as recommended. What follows from it:

1. **AC30–AC39 stand as written**, and **all four sub-questions are settled as answered above** —
   a static secret presented as a header rather than an HMAC signature (1); per-destination rather
   than per-proxy (2); write-only, never redisplayed to any role, gated by the existing
   `proxy:update` permission (3); existing destinations byte-identical and untouched, with the
   `destinations.url` exposure explicitly left as found (4). The Owner overturned none of them.
2. **The severability is withdrawn.** AC30–AC39 are now ordinary criteria of PRD-10. Every trace of
   the severable framing has been removed from that document — Status block, acceptance-criteria
   preamble, section heading and preamble, and the supporting sections.
3. **This question is RESOLVED and is not a gate on anything.** PRD-10 still needs Owner approval
   as a whole, but no longer on this account.

**One thing this ruling does not settle, recorded so it is not mistaken for settled.** In the same
session the Owner **added outbound request *signing*** to #10 — the reverse capability, where a
destination verifies that a dispatch came from this service. That is **not** what this question
asked about and is **not** covered by this ruling: it is a separate roadmap widening, carried by
**PRD-10 `## Amendment A`** as **AC54–AC64**, and it is ratified at PRD-10's approval gate. The two
are independent by design — a destination may have neither, either or both. Sub-question 1's answer
here (the **credential** is a static secret, not a signature) is unaffected and still stands;
signing is the other capability, not a reversal of that one.
