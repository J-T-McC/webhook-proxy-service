# Q-10-01: Outbound destination authentication has no home on the roadmap — does it belong in #10?

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Project Owner
- **Directed To:** Product Manager
- **Required By:** Before #10's PRD is written. The answer changes that PRD's shape rather than adding a detail to it, so it cannot be settled during drafting.
- **Priority:** Medium
- **Status:** Open

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

*(Unanswered.)*
