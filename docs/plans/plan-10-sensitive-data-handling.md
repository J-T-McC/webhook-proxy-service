# Technical Plan: Sensitive data handling — item #10

> **Written to two Project Owner rulings given directly on 2026-08-27, after PRD-10 was approved and
> after the design gate closed.**
>
> - **Ruling A — how a rotating secret is stored.** "*We can have 1, 2, 3.. relations. There can be
>   an expiration timestamp that is set on an existing token when a new token is created. When we
>   retrieve the tokens, we can retrieve non expired tokens … we can expand tokens vertically since
>   we know the header can hold multiple. They will naturally expire and be excluded.*" Carried into
>   **ADR-021 Decision 2** as the **recommended** storage model, with the fixed-column alternative
>   fully enumerated below so the Owner rules on two specified options rather than on a direction.
> - **Ruling B — the grain of outbound signing.** "*A proxy has one outgoing secret that can be used
>   for all destinations. We can rotate so the header contains multiple secrets until one or more
>   expires, but that is proxy level.*" **PRD-10 AC54–AC64 and `design-10` Screens 5 and 6 and Flows
>   G–I are written as per-destination signing, and this plan is written to the ruling rather than to
>   that text.** Neither document is edited. The conflict is routed to the Product Manager at
>   **`Q-10-04`**, and § *Milestones* isolates the one milestone it blocks — the signing **surface**.
>   The signing **backend**, the destination credential, inbound verification and field obfuscation
>   are all unblocked by it.

- **Status:** **Fully approved.** Principal-Engineer self-certified everywhere except the four items
  at § *Owner-approval flags (✋)*, which were not mine to certify — and **all four were approved by
  the Project Owner on 2026-08-27**. #10 adds a table and six columns, stores three kinds of secret,
  changes the response shape of the system's only payload-content egress, and adds the product's
  first authentication mechanism for inbound traffic. Each is on `CLAUDE.md`'s major-decision list,
  which is why each was flagged rather than assumed.
- **Author:** Principal Engineer
- **Date:** 2026-08-27
- **PRD:** `docs/product/prd-10-sensitive-data-handling.md` — **APPROVED by the Project Owner,
  2026-08-27, as amended.** 64 acceptance criteria. **`## Amendment A` is ratified whole and governs
  over the pre-amendment text of every criterion it revised** — AC23, AC25, AC26, AC27, AC29
  (replaced outright), AC43, AC44, AC50 — and adds AC51–AC64. This plan is written against the
  amended text throughout, **and against Owner ruling B where that ruling and AC54/AC63 disagree**
  (`Q-10-04`).
- **Design spec:** `docs/design/design-10-sensitive-data-handling.md` — **Approved at the design gate
  (Product Manager, 2026-08-27) with ten required corrections C1–C10.** As with plan-08 and plan-11,
  the spec's **approval record governs over the spec body**, and the rulings there are binding —
  including flagged call 4's **overturn** (the one-time reveal suppresses `Esc` and overlay
  dismissal) and corrections **C2**, **C3**, **C6**, **C8** and **C9**, each of which has a technical
  consequence this plan carries. This plan builds the surfaces the spec specifies and redesigns none
  of them; where Owner ruling B displaces a surface, it **stops** rather than redesigning it
  (§ *Technical rulings* 13).
- **ADRs:** **four new.** **ADR-021** secret handling and rotation (**Proposed — flag 2**) ·
  **ADR-022** inbound verification at the ingest boundary (**Proposed — flag 3**) · **ADR-023** the
  outbound request contract, amending ADR-008 (**Accepted — ratified by the Owner's approval of
  PRD-10; deliberately not a flag**) · **ADR-024** field obfuscation and the revealed-payload
  envelope, partially superseding ADR-017 Decision 6 (**Proposed — flag 4**). Every candidate and
  every touched ADR is walked one by one at § *Why four ADRs were warranted here, when the previous
  item needed none*.
- **Questions resolved here:** `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md`
  — **RESOLVED** (Principal Engineer, 2026-08-27), both items, with two findings and one item
  recorded for the Product Manager's awareness that blocks nothing.
- **Questions raised here:** **`Q-10-04`** to the **Product Manager** — the signing grain, and
  whether AC29's two-secret cap still holds (blocks **M8b only**). **`Q-10-03`** to the **Designer**
  — a missing credential-removal affordance and a factual correction to note N3 (blocks nothing).
- **Approved by / date:** Principal Engineer, 2026-08-27 — **partial**, see Status.
- **Revised:** 2026-08-27 — **`## Revision A`** adds technical ruling 15, the transport for Screen 3's
  **Remove credential** signal, answering `Q-10-05`. Purely additive: no existing ruling, gate,
  milestone, ADR or approval is altered or reopened.
- **Pointer, 2026-08-28 — `ADR-025` is Proposed and would rename the outbound signing headers.**
  See the pointer section immediately below. **Nothing in this plan is revised by it**, and nothing
  in this plan becomes false unless and until the Project Owner accepts that ADR.

## Pointer to ADR-025 (Proposed, 2026-08-28) — nothing in this plan is revised

**This is a pointer, not a revision.** No ruling, Owner-approval flag, milestone, data-model entry,
approval or certification in this plan is altered, reopened or superseded here. It exists so that a
reader of this plan is not misled by three passages that would become stale if
`docs/architecture/adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md`
is accepted.

**What ADR-025 would change, in the two places it touches this plan's subject matter.**

- **The outbound signing headers are renamed** from `webhook-id`, `webhook-timestamp` and
  `webhook-signature` to a **branded, non-`X-` prefix** — `WebhookProxy-Id`, `WebhookProxy-Timestamp`,
  `WebhookProxy-Signature`, hard-coded at the single build point (ADR-025 Decision 2,
  superseding the emitted names in ADR-023 Decisions 3 and 4). Only the names change: the
  `msg_{dispatch_uuid}_{destination_id}` derivation, the per-attempt timestamp, the space-delimited
  `v1,<base64>` entry per live signing secret, the signed content, the algorithm, the encoding and
  the single build point are all unchanged. **Inbound verification's header names are not renamed.**
- **Five provider signature header names are removed from `DeliveryUnit::STRIPPED_HEADERS`** and
  forwarded to destinations (ADR-025 Decision 1, superseding an ADR-008 position). The per-proxy
  AC27 verification-header strip this plan specifies is unchanged and is what keeps a member's
  `shared-secret` value from leaving.

**The three passages in this plan that state the old header names**, listed so a reader can check
them against ADR-025 rather than discover the divergence: § *Architecture* (the signing composition
and the `webhook-id` derivation), § *Risks* **R9** (a member naming a header one of the three
`webhook-*` names), and § *Validation* (the AC60 and AC43 expectations, including "a proxy with
signing off still forwards a `webhook-signature` a sender happened to send"). Each remains an
accurate statement of the plan as certified; each would be restated in the renamed vocabulary if
ADR-025 is accepted.

**Sequencing, because it bears on this plan's own delivery.** ADR-025 Decision 2 must be applied on
this item's branch **before** it merges, since after the merge the header names are a contract held
by members' receivers and a rename becomes a breaking change with no notification surface. ADR-025
§ *Sequencing* carries the reasoning; the ADR's Owner gate is where it is decided.

## Revision A — the destination credential removal signal (2026-08-27)

**What this settles.** `design-10`'s amendment gate (correction **B3**, 2026-08-27) requires that
Screen 3's new **Remove credential** control reach the server as a signal distinguishable, end to
end, from an ordinary blank Replace field, and it assigns the wire shape to the Principal Engineer
rather than specifying one. The Task Planner raised that gap as
`docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`, where it blocks
task **T31** and nothing else. This revision answers it.

**Why it was not in the original plan.** The control did not exist when this plan was certified.
§ *Explicitly out of scope for this plan* recorded that `design-10` designed no removal affordance,
raised it as `Q-10-03` to the Designer, and said that whatever `Q-10-03` answered would be additive.
The Designer answered `Q-10-03` in the same amendment pass that produced correction B3 — after all
four Owner-approval flags on this plan had already been ruled. This section is the additive change
that bullet anticipated, landing under the delegated plan gate.

| | Prior position | Now |
|---|---|---|
| **Removing a destination credential** | Out of scope for this plan; `design-10` designed no affordance and shipping an undesigned control would be worse than the gap (§ *Explicitly out of scope*, annotated inline) | Designed by `design-10` Screen 3 as amended, and its transport ruled at **technical ruling 15** below |
| **The wire shape of the removal signal** | Not addressed anywhere — the control postdated certification | A sibling boolean `destinations.*.remove_credential` per destination row, ruling 15 |
| **`destinations.*.credential_secret`'s meaning** | "A new value, or absent; absent means leave unchanged" (§ *Validation*) | **Unchanged**, and deliberately so — ruling 15 exists to keep it single-meaning |
| **`Q-10-05`** | OPEN, blocking T31 | **RESOLVED** (Principal Engineer, 2026-08-27) |

**Nothing else moves.** No existing technical ruling is amended, no Owner-approval flag reopens, no
milestone is added or renumbered, no ADR is written, amended or superseded, and § *Data Model* is
untouched — ruling 15 adds no column, index, migration, cast or default. Ruling 15 lands inside
**M7**, which already carries Screen 3.

### 15. The Remove credential signal is a sibling boolean per destination row, never a sentinel on the secret field. *(Added 2026-08-27 — Revision A; `Q-10-05`, design-10 correction B3)*

**The shape.** `destinations.*.remove_credential`, a real JSON boolean, submitted on the existing
proxy create and update routes alongside the row's `id`, `url`, `http_method`,
`credential_header_name` and `credential_secret`. Absent or `false` means no removal; `true` means
"clear this destination's credential on save". **`credential_secret` keeps exactly one meaning and
gains no second one** — a new value, or absent, where absent means "leave unchanged". That is the
write-only contract § *Validation* already states and `design-10` § *Interactions* requires, and
keeping it single-meaning is the whole purpose of this ruling.

**Additions to § *Validation*, in both `StoreProxyRequest` and `UpdateProxyRequest`** (the section
above is unchanged; these are additional rules, not replacements):

- `destinations.*.remove_credential` — `sometimes`, `boolean`.
- `destinations.*.credential_secret` — gains `prohibited_if:destinations.*.remove_credential,true`.

The wildcard-sibling reference is the same mechanism the already-certified
`required_with:destinations.*.credential_secret` rule on `credential_header_name` uses, so no new
validation capability is introduced. The guard is there so a malformed client receives a
deterministic 422 rather than an outcome decided by a precedence rule — under either precedence one
of the member's two explicit acts would disappear with no feedback, which is the class of silence B3
exists to prevent.

**Persistence, in `ProxyController`'s existing reconciliation step.** Where a submitted row carries
`remove_credential: true` and reconciles to an existing destination by `id`, the update writes NULL to
**all three** credential columns — `credential_header_name`, `credential_secret`, `credential_set_at`
— and ignores any submitted `credential_header_name` for that row. The three move together so a row
can never come to rest holding a header name with no secret. The result is byte-identical to a
destination that never had a credential, which is exactly the post-save state B3 added to Screen 3's
states table. A row with no `id` has nothing to remove, so the flag is a no-op on the create path and
for newly-created rows on the update path; the rule is nevertheless declared on **both** requests,
because `ProxyForm.vue` is one component serving Create and Edit and submits one row shape, and a rule
present on one request and absent from the other is discovered by a 422 in production rather than by
reading.

**`ProxyController::destinationRows()` must read the flag positively.** The existing normaliser keys
every field off `isset()`, which is `false` for an explicit `null`; the new key is read as a boolean
(`($row['remove_credential'] ?? false) === true`) so nothing about presence-versus-absence is
load-bearing anywhere in this path.

**One client-side normalisation, which is the approved states table read forwards.** After the member
clicks Remove credential, `design-10` puts the row into the unconfigured presentation with a blank
Secret input. A member may then type a new secret into it, and typing a value into an unconfigured row
has always meant "set this secret" — so the row's later act supersedes the staged removal:
`form.transform()` sends `remove_credential: false` whenever that row's `credential_secret` is
non-empty, following the same `transform()` normalisation discipline Implementation Note 14 and the
response/retry sentinels already establish. This authors no copy, adds no control and invents no
state. Its effect is that the 422 above is unreachable from this application's own UI and stands
purely as a guard against a malformed request.

**Why not a reserved sentinel on `credential_secret` — the alternative `Q-10-05` offered.** Rejected
on **failure direction**, not on ergonomics. A sentinel encodes a deliberate member action in the
*absence* of data, and absence is the default state of every layer a request crosses: a middleware, a
serializer, a normaliser, a `??` coalesce, a `$request->only()`. Each can turn "present and null" into
"absent" or the reverse, and none of them errors when it does. **One of those layers already conflates
the two in code this milestone touches** — `ProxyController::destinationRows()`'s `isset()`
normalisation, above. And the distinction *does* survive validation, which is what makes it a trap
rather than an obvious mistake: read in vendor, `Validator::validated()` skips any key whose
`data_get($this->getData(), $key, $missingValue)` returns the sentinel object, and
`ValidationData::initializeAndGatherData()` expands the wildcard into concrete per-row keys against
the submitted rows, so an explicitly-null `credential_secret` reaches `validated()` and an absent one
does not. A property that holds at three layers out of four is worse than one that holds nowhere,
because it tests green in isolation. **Decisively, the two shapes fail in opposite directions.** A lost
boolean keeps the credential and costs the member a second click. A collapsed absent-versus-empty
distinction produces the outcome `design-10` names in the sentence B3 quotes — every abandoned Replace
becomes an unintended removal, silently, on a value we never hold in the clear outside the request
that set it. Full reasoning, including why the serialization argument is stated as a reason not to
depend on the distinction rather than as a verified vendor claim, is in `Q-10-05` § *Answer*.

**Why this needs no ADR and no Owner gate**, walked against `CLAUDE.md`'s major-decision list item by
item: **no new dependency**; **no stack change** (`docs/stack/stack.md` untouched); **no data-model
change** — no column, index, migration, cast or default, and `remove_credential` is never persisted
and never read back; **not irreversible** — changing the field later costs one form, two requests and
one controller branch, with no stored data in its shape; **security-sensitive only in the protective
direction** — it adds no store, no egress, no reveal, no permission and no plaintext surface, and the
security-relevant property it establishes is precisely that a blank field can never destroy a secret.
What remains is a request-interface choice of the same kind as the `required_with` and `prohibited_if`
rules § *Validation* already carries without a gate. The precedent is `plan-11` § *Revision A*: a
post-certification Principal Engineer ruling on a downstream question document, landed as a plan
revision under the delegated plan gate, with no Owner ruling sought and none required.

**Additions to § *Test strategy*** (grouped with the existing destination-credential items; the
section above is unchanged):

**Destination credential removal (AC30, AC33; design-10 correction B3)**
- **The distinguishability pair, asserted in one test so the two cases can never be collapsed
  independently:** an update carrying a present-but-empty `credential_secret` and no
  `remove_credential` leaves the stored credential byte-identical, while an update carrying
  `remove_credential: true` for the same row nulls it — same route, same row, different outcome.
- A saved removal leaves `credential_header_name`, `credential_secret` and `credential_set_at` all
  NULL, asserted with a raw query, so the row is indistinguishable from one that never had a
  credential.
- An update carrying both `remove_credential: true` and a non-empty `credential_secret` is a 422 and
  changes nothing on the row.
- `remove_credential: true` on a row with no `id` is a no-op: the row is created with NULL credential
  columns and no error.
- After a saved removal, the `security` prop's `destinations[id].has_credential` is `false` and
  `credential_changed_at` is null — the round-trip B3's added states-table row describes.

**Addition to § *Implementation Notes*:** **24. Removal is a positive boolean, and the normaliser must
read it as one.** Never infer removal from an absent or empty `credential_secret`, in the form, in the
request or in the controller — ruling 15. Note 14's rule ("a present-but-empty secret field must not
submit as 'clear the secret'") is what ruling 15 protects, not something it qualifies.

### Re-certification at Revision A (Principal Engineer, 2026-08-27)

I certify ruling 15 and the § *Validation*, § *Test strategy* and § *Implementation Notes* additions
above under the **delegated plan gate** in `CLAUDE.md`. **No Project Owner ruling authorises this
revision and none was sought**, because none is required: the walk immediately above tests it against
every item on the major-decision list and it clears each. **No already-ruled Owner flag reopens** —
flags 1 through 4 were approved on 2026-08-27 and this revision changes nothing any of them covers, in
particular nothing in § *Data Model*. ADR-021, ADR-022, ADR-023 and ADR-024 are each unaffected: the
destination credential is not in `proxy_secrets` and does not rotate, so ADR-021's rotation model is
untouched; a destination with no credential contributes no credential header, which is the contract
ADR-023 already describes for AC30's optional case; ADR-022 and ADR-024 are on different paths
entirely. Considered one by one, not overlooked.

I have not changed a requirement and have not redesigned a surface. Every user-visible property of the
Remove credential control — that it exists, where it sits, its label, its accessible name, that it is
save-time rather than immediate, that it takes no confirmation, and what the row shows before and
after the save — is the Designer's, taken from `design-10` Screen 3 as amended. What this revision
decides is only how the signal crosses the wire, which is the call correction B3 explicitly assigns to
me. **No approved copy needs changing**, and if that turns out to be wrong it is the Product Manager's
call and routes to them rather than being rewritten here.

**Task material.** `Q-10-05` blocked **T31 only**, and T31 is now unblocked. The task's own
description already anticipates wiring "the answered transport" through
`StoreProxyRequest`/`UpdateProxyRequest` and `ProxyController`, so the shape above drops into it
without a new task; the Task Planner may wish to name `resources/js/pages/proxies/ProxyForm.vue` and
`resources/js/types/proxies.ts` in its **Files** list, since the row model and the submit
`transform()` both carry the flag, and to fold the five test items above into its **Testing** line.
No milestone changes and no other task is affected.

## Overview

#10 is five capabilities over one existing spine, and it adds no new page, no new navigation entry,
no new permission, no new payload store and no new dependency.

**Inbound**, `IngestController` gains one gate between resolving the proxy and capturing the event:
a closed two-scheme verifier that rejects with 401 before anything is written (ADR-022). **Outbound**,
`DeliverToDestination::send()` gains one build point that strips the proxy's verification headers,
adds a destination credential and the proxy's Standard Webhooks signature headers, and resolves
collisions in favour of what we added (ADR-023). **At rest**, the two rotating secrets — the inbound
verification secret and the proxy's outbound signing secret — live as rows in one new
`proxy_secrets` relation, encrypted, expiring by data; the destination credential, which does not
rotate, stays as three columns on `destinations` (ADR-021). **On the way out to a member**, the one
content-bearing endpoint parses a JSON payload, replaces every sensitive value with `null`, and
returns the document plus a JSON-Pointer index of what was hidden and which list matched, so the
client can render `[Hidden]` tokens without a secret ever leaving the server (ADR-024).

Five properties do the structural work and are worth naming once, because most of this feature's
criteria are satisfied by them rather than by rules anybody has to keep:

- **Verification runs before capture**, so AC25's four negatives (no event row, no delivery, no
  dispatch, not the proxy's own response) are consequences of ordering.
- **Expiry is data, not a branch.** A secret is in the live set or it is not
  (`expires_at IS NULL OR expires_at > NOW()`), so "still honoured" and "still stored" are one fact
  rather than two that a sweeper has to keep in step.
- **Delivery is already by reference** (ADR-020 Decision 7), so no secret can enter a queued job, a
  failed-job record or Horizon's second copy of both — AC35 and AC61 need no mechanism. If the
  delivery job's arguments ever stop being scalars, that argument inverts.
- **Obfuscation is a read-path transformation in one endpoint**, so AC17's "the stored payload is
  unchanged and destinations receive the real values" and AC19's retroactivity are both free — there
  is nothing to migrate because nothing is rewritten.
- **Both rotating secrets are proxy-level** (Owner ruling B), so one table with one concrete
  `proxy_id` foreign key holds them, and the only destination-level secret is the one with no
  rotation to model.

The four things this plan needs the Project Owner for are the schema, the secret-storage posture,
the verification seam, and the payload endpoint's response shape.

## What is already settled, and by whom

Restated once so every section below reads as a consequence rather than a choice.

- **The Project Owner (2026-08-27, roadmap V2 and PRD-10 `## Amendment A`, ratified by approving the
  PRD):** two named inbound schemes and no more, the list closed until an Owner decision opens it;
  signature verification and replay-window enforcement in scope; secrets **stored, never generated
  by us**, except the signing secret which is **only** generated by us; a bounded rotation overlap in
  both directions; outbound destination authentication (AC30–AC39, no longer severable); outbound
  request signing (AC54–AC64).
- **The Project Owner (2026-08-27, PRD-10 § Consequences):** PRD-06 AC25's narrowing accepted **as a
  recorded narrowing**, with no PRD-06 amendment; ADR-008's reversal **acknowledged**, with the
  recording left to the Principal Engineer. ADR-023 is that recording.
- **The Project Owner (2026-08-27, mid-flight, ruling A):** a rotating secret is held as **rows in a
  relation with an expiry timestamp**, retrieved as the non-expired set, rather than as a fixed pair
  of columns. Carried at ADR-021 Decision 2 as the recommendation, with the alternative enumerated so
  the Owner rules on two options.
- **The Project Owner (2026-08-27, mid-flight, ruling B):** outbound signing is **proxy-level** — one
  outgoing secret for all of a proxy's destinations. Displaces PRD-10 AC54/AC63 and `design-10`
  Screens 5–6 and Flows G–I; routed to the Product Manager at `Q-10-04`.
- **The Project Owner (2026-08-26, ADR-020 Revision A/B):** payload content is not encrypted into the
  queue, it is **removed** from it; long-term storage turns on duration, and the threshold is
  deliberately unset. PRD-10 AC9 carries the deferral forward unchanged.
- **The Product Manager (2026-08-27, design gate):** the four flagged design calls — three accepted
  with binding conditions, flagged call 4 **overturned**; the ten corrections C1–C10; and two rulings
  made *as requirements author*, **C6** (a sensitive object or array is replaced whole) and **C9**
  (pretty-printing is a consequence of parsing, not something AC15 requires).
- **The Designer (2026-08-27, approved):** every screen, state, flow, label, empty state, placement
  and accessibility rule in `design-10`, including the two `[Hidden]` descriptions and the token
  string `[Hidden]` itself (C8).
- **The Principal Engineer (this plan and ADR-021/022/023/024):** where verification runs, how
  secrets are stored and rotated, the outbound header contract, the revealed-payload transport, the
  default sensitive-field list, and the matching rule.

Nothing in this plan reopens any of the above. Where it rules on something the upstream artifacts
left silent, it says so by name in § *Technical rulings* and states why the ruling stays inside their
assumptions.

## Architecture

### A. Inbound — one gate, before capture (AC23–AC29, AC51–AC53; ADR-022)

`IngestController` gains exactly one step, between the token lookup and the capture transaction:

```
resolve proxy by SHA-256 token hash        (ADR-006, unchanged)
read $method / $headers / $rawBody once    (unchanged)
→ InboundVerifier::verify($proxy, $request, $rawBody)     ← NEW
    not required        → continue           (scheme column is NULL — no secrets query at all)
    verified            → continue
    failed              → 401, fixed body, log a reason code, return
    secret unreadable   → 500, report, return                 (AC11)
capture + fifo_dispatches row              (unchanged, one transaction)
ResponseResolver::resolve($proxy)          (unchanged — NOT reached on a rejection)
dispatch by reference                      (unchanged)
return the configured response
```

`InboundVerifier` is the single consumer of the verification live set and the **resolution-time
gate** in ADR-018 Decision 1's sense: it establishes `verification_scheme !== null` before asking
`SecretStore` for anything, so a proxy with verification off never queries `proxy_secrets`.
`SharedSecretScheme` and `StandardWebhooksScheme` implement one interface each; the scheme registry
is closed in the enum and in validation, and adding a case is a Project Owner decision (AC50). Every
member of the live set is tried and which one matched leaves no trace (ADR-022 Decision 3).

**The raw body is read exactly once** and passed to both the verifier and `WebhookEventCapture`, so
the bytes verified and the bytes stored are the same object. This is why #8 and #9 have no bearing on
verification (PRD-10 § V2's correction, AC52's fourth bullet).

### B. At rest — a relation for what rotates, columns for what does not (AC1, AC10, AC11, AC26, AC29, AC33, AC34, AC57; ADR-021)

| Secret | Grain | Rotates | Lives in |
|---|---|---|---|
| Verification secret (AC26) | proxy | yes (AC29) | `proxy_secrets`, `purpose = verification` |
| Signing secret (AC56, **ruling B**) | proxy | yes (AC58) | `proxy_secrets`, `purpose = signing` |
| Destination credential (AC30) | destination | **no** — AC29 excludes it | three columns on `destinations` |

`proxy_secrets` holds one row per live or superseded secret, with `value` cast `encrypted`,
`is_current` (nullable boolean) under `UNIQUE(proxy_id, purpose, is_current)` giving a portable
"at most one current per purpose", and `expires_at` set at the moment a row is superseded. **The
live set is `expires_at IS NULL OR expires_at > NOW()`, current first** — the Owner's sentence
verbatim. Inbound, a request verifies against any member; outbound, the `webhook-signature` header
carries one `v1,<sig>` entry per member (AC58).

Nothing is hashed: two of the three secrets are HMAC key material and the third is presented
verbatim (ADR-021 Decision 1). `proxies.ingest_token` is the existing `text` + `encrypted`
precedent.

**Two states are expressed by presence, and they differ on purpose** (ADR-021 Decision 5): a proxy
has signing on when it has a live `signing` row, and **disabling deletes** every `signing` row;
`proxies.verification_scheme IS NULL` means verification is not required and turning it off
**retains** the dormant secrets, because they came from a third party and destroying them would send
the member back to their provider. The second follows PRD-07 AC14's dormant-configuration precedent
and review-07's Major; the first follows design-10 Flow I step 3, which already rules that
re-enabling always generates afresh.

### C. Outbound — one build point, added headers win (AC17, AC27, AC30–AC39, AC54–AC64; ADR-023)

`DeliverToDestination::send()` composes the outbound header set through `App\Support\OutboundHeaders`
in five steps: forward inbound minus ADR-008's constant, minus this **proxy's** verification headers
(AC27), then add the **destination's** credential and the **proxy's** signing headers, having first
removed any forwarded header whose lowercased name collides with one of them (AC38, AC64), then
merge.

Building **in the send path** rather than in `DeliverStep` or in the resolver is what makes AC32 and
AC60 structural: attempt 1 arrives through `asJob()` and attempts 2..N through `RetryDelivery`, and
both funnel into `send()`. It is also the only place where the signature can be computed over the
exact bytes about to go out (AC59) and carry **this attempt's** timestamp (AC60).

`webhook-id` is derived — `msg_{dispatch_uuid}_{destination_id}` — so it is stable across retries of
a delivery and new on a replay, with **no new column** (ADR-023 Decision 3). **The secret is
proxy-level; the message id stays per delivery**, and the two must not be conflated.

A destination with no credential, on a proxy with no verification and no signing secret, produces a
byte-identical request to today's. That is AC37 and AC63, and it is why
`DeliveryUnit::STRIPPED_HEADERS` is **not** extended with the three `webhook-*` names (ADR-023
Decision 5).

### D. The read path — obfuscation in the one egress (AC12–AC22, AC49; ADR-024)

`ProxyEventPayloadController` is the only content-bearing response in the system, and it stays that
way. It now branches on whether the stored body parses as JSON:

| Stored body | Response | Field-level claim |
|---|---|---|
| Parses as JSON | `application/json` envelope: `{format, document, obfuscated}` — `document` is the payload re-encoded with each sensitive value replaced by `null`; `obfuscated` maps RFC 6901 JSON Pointers to `"default"` or `"addition"` | Yes |
| Does not parse | unchanged raw bytes, `text/plain; charset=utf-8` | **None** (AC22) |
| Cleaned | unchanged **410 Gone** | — |

`nosniff`, `no-store, private`, never-logged, never-cached, never-a-prop and text-interpolation-only
are unchanged on both paths (ADR-017 Decision 6, narrowed only in its `Content-Type` half).

`SensitiveFieldMatcher` holds the 23-name default list and the normalisation rule (lowercase, strip
non-alphanumerics, compare for **exact** equality — never a substring); `PayloadObfuscator` walks the
decoded document, replaces a matched field's **entire** value whatever its type (C6), and returns the
pointer index. Neither ever inspects a value (AC14).

### E. The configuration surfaces

`design-10`'s Screens 1, 2 and 3 extend `ProxyForm.vue` and `DestinationRows.vue`; Screen 4 extends
`proxies/Show.vue`; Screen 7 extends `PayloadViewer.vue`. The proxies Index and the events list are
untouched, exactly as the spec's scope note states.

**Screens 5 and 6 — the per-destination `Signed` badge, the per-row `Manage signing` action and the
`Manage destination signing` dialog — are displaced by Owner ruling B and are not built to the spec
as written.** The signing backend is built; its surface waits on the PRD amendment and the
Designer's revision (§ *Technical rulings* 13, § *Milestones* M8b, `Q-10-04`). **Screen 5's
`Credential` badge is unaffected** — the credential is still per destination.

Two prop-shape rulings keep these additions off surfaces that must not change — § *Technical
rulings* 3 and 4.

### F. Permission — reuse, on both sides of the wire (AC20, AC28; design-10 C2)

Server-side, every new mutating endpoint authorizes `update` on the proxy through the existing
`ProxyPolicy::update()`, which already composes `TeamPermission::UpdateProxy` with the Q-02-01 /
ADR-009 Amendment A2.2 ownership axis. Client-side, correction C2's gate is the computed that already
exists in `resources/js/pages/proxies/Show.vue`:

```
canUpdate = permissions.canUpdateProxy && (proxy.is_creator || permissions.canUpdateAnyProxy)
```

**No new `TeamPermission` case, no new policy class, no new policy method, no new middleware.**
AC28's "no new permission" holds because this is a reuse, and AC20's holds because the obfuscated
token is inert and no role gates it. **Ruling B simplifies this**: the signing endpoints are
proxy-scoped, so there is no destination-scoped route to authorize on a proxy at all.

### G. Composition with retention, retry, replay and FIFO — nothing changes

- **Retention/GC:** no secret is payload content, so `PurgeExpiredPayloads`, `RetentionPolicy` and the
  H0–H5 hold set are untouched. AC36 and AC62 are structural.
- **Retry:** re-sends `StoredPayloadLookup::dispatchedBytesFor()`'s recorded bytes and re-runs no
  pipeline — so obfuscation, which is read-path only, cannot reach it, and signing, which is
  send-path, applies to every attempt with that attempt's own timestamp.
- **Replay:** re-runs the pipeline from the raw capture under a new `dispatch_uuid`, so it gets a new
  `webhook-id` (AC60) and current configuration, and it never re-verifies (ADR-022 Decision 6).
- **FIFO:** untouched. Verification runs before the `fifo_dispatches` row is written, so a rejected
  request never joins the line; no new pipeline step is composed, so nothing can short-circuit and
  strand a dispatch.
- **Analytics (#11):** untouched. No new figure, no new index, no change to `DeliveryStatistics`.

### H. Failure — loud, and in the right direction (AC11)

One exception type, `SecretUnavailableException`, with a fixed value-free message. Inbound it
produces a **500**, never a 401 and never the proxy's configured 2xx; outbound it fails the attempt
**before** any request is made, so nothing is dispatched uncredentialed or unsigned. **One live
secret failing to decrypt fails the operation** rather than being dropped from the set — a partial
signature list is indistinguishable from a completed rotation. The failure surfaces through
design-06's existing attempt-history treatment (design-10 C7) and #10 adds no new surface for it.

## Technical rulings (named, recorded — not silent design)

**1. The revealed-payload transport is a structured envelope, not pre-rendered markup.**
*(design-10 § Open Questions' folded note; § Carried forward item 2.)* Ruled in **ADR-024 Decision
2**: `{format, document, obfuscated}` with obfuscated values replaced by `null` in the document and
an RFC 6901 pointer index carrying the C3 flag. The deciding argument is ownership of copy:
`design-10` fixes the token string, both accessible descriptions, the styling family and the
inertness, and a pre-rendered response would move all four into a PHP string builder where the
Designer cannot see them. The precedent the design points at — design-06's folded reveal note
resolving into ADR-017 Decision 6 — is followed literally. **User-visible consequence, stated rather
than inferred:** on the JSON path the revealed view is a re-serialisation, so insignificant
whitespace is normalised and duplicate keys collapse. That is C9's accepted consequence of
parsing-to-obfuscate; the stored and delivered bytes are untouched.

**2. Which list matched is a two-valued flag per obfuscated value, and a default beats an addition.**
*(design-10 correction C3; § Carried forward item 1.)* Ruled in **ADR-024 Decisions 2 and 4**: the
pointer index's value is `"default"` or `"addition"`, and when a name is in both lists the answer is
`"default"` — because removing the addition would not unhide the value, so calling it an addition
would offer a remedy that does not work, which is the exact defect C3 exists to prevent. The two
description strings are the Designer's and are taken verbatim; this plan authors no copy.

**3. Verification and signing status are a sibling page prop, never keys on `ProxyResource`.**
`ProxyResource` is one class serving `index()`, `show()` **and** `edit()`, so anything added lands on
all three at once — and `design-10`'s scope note states the proxies Index is unchanged.
`ProxyController::show()` and `edit()` emit a separate **`security`** prop; `index()` emits nothing
new. This follows #11's precedent of adding sibling props (`statistics`, `destinations`) rather than
growing the resource. **`create()` renders no proxy resource at all** — `Create.vue` hard-codes the
blank initial state — so the default sensitive-field list and the Standard Webhooks tolerance are
page props on **both** `create()` and `edit()`, not resource keys.

**4. Destination credential presence rides on the same `security` prop, not on
`DestinationBreakdownRow`.** `design-10` Screen 5 extends the Destinations table that #11 renders
from `DeliveryStatistics::destinationBreakdown()`. Putting security flags on that DTO would make the
analytics service read secret columns and reopen a shape plan-11 certified. The `security` prop
carries `destinations: { [id]: { has_credential, credential_changed_at } }` and `Show.vue` looks up
by the row's existing `id`. `DeliveryStatistics` is not edited. **Under ruling B there is no
per-destination signing flag to carry** — signing status is one object on the same prop.

**5. The one-time signing secret is delivered by a JSON XHR, never an Inertia prop and never a
flash.** Ruled in **ADR-021 Decision 6.3**. Inertia keeps page props in browser history state, and
`Inertia::flash` writes through the session store — which is the `database` driver here, so a flashed
secret would land in the `sessions` table. Both are durable copies of a secret that nothing erases.
The generate/regenerate endpoint returns `application/json` with `Cache-Control: no-store, private`;
the caller reads the response directly and the surrounding page refreshes with
`router.reload({ only: [...] })`. This is ADR-017 Decision 6's own posture applied to a different
secret, for the same reason.

**6. No new production dependency. The Standard Webhooks primitive is implemented in-house against
the specification.** Assessed in **ADR-022 § Alternatives** and § *Dependencies*. The surface is
`hash_hmac` + `base64_decode` + `hash_equals` + a timestamp comparison + a space-split; the wrapper
this feature would need around a package (an N-member live set, our failure-reason codes, our
fail-loud semantics) is comparable in size to the thing wrapped; and this repository has **no
Composer dependency scanning at all**, so a package on the authentication path would receive no
vulnerability signal. AC52 makes the *specification* normative rather than any implementation of it.
**Not rejected on merit** — if the Owner prefers the package, that is a new-dependency gate and no
other section changes.

**7. A submitted secret must never be flashed as old input, and the nested case needs more than
`dontFlash`.** Laravel's validation handler redirects with
`Arr::except($request->input(), $this->dontFlash)`, and `Arr::forget()` supports dot notation but
**not wildcards** — verified in vendor. So `verification_secret` can be excluded by a `dontFlash`
entry in `bootstrap/app.php`, and `destinations.*.credential_secret` **cannot**. The two proxy
FormRequests therefore override `failedValidation()` to scrub the nested secret values from the
request input before the exception propagates. Inertia's client form keeps its own state and never
reads `old()`, so nothing is lost.

**8. A capture-failure report must not carry the interpolated SQL.** Laravel's
`QueryException::formatMessage()` interpolates bindings into the exception message
(`Str::replaceArray('?', $bindings, $sql)` — verified in vendor), and `IngestController` calls
`report($e)` on a capture failure. Today the bound values are **ciphertext**, because the `encrypted`
casts run at attribute-set time and `performInsert()` binds `$this->getAttributes()` — so no
plaintext payload has ever reached the log. But an encrypted copy of payload content in a log file is
still a copy in a store AC3's enumeration does not include and no retention pass touches. The ruling:
wrap the capture failure so what is reported names the `ingest_id`, the proxy and the SQLSTATE and
**not** the interpolated statement. This serves AC3 and AC8 rather than making a requirement; it is
recorded for the Product Manager's awareness in `Q-10-02`'s answer in case they read AC3 differently.
The same property is what keeps a **secret** out of that message, which is why ADR-021 Decision 1
makes the `encrypted` cast binding rather than optional.

**9. A JSON payload is never served raw, whatever its size — and no byte ceiling ships at MVP.** The
tempting mitigation is "above N bytes, skip parsing and return the raw bytes as the non-JSON path
does". Forbidden: the payload is still JSON, it may still contain sensitive fields, and serving it
raw is a direct AC18 breach. A ceiling therefore needs a distinct member-facing state, which is the
Designer's, and inventing one here would be redesigning UI in the plan. At MVP there is no ceiling: a
payload too large to decode produces a 500, never a disclosure. § *Risks* R1.

**10. The default sensitive-field list is confined to the three families AC12 names.** Ruled in
**ADR-024 Decision 5** — 23 entries, fixed here because `design-10` C4 states the content is fixed at
technical design. AC12's "at minimum" permits more; the restraint is deliberate, because AC12 also
forbids a member removing a default, so a wrong entry is permanent and invisible to them while a
missing one is a two-second AC13 addition on a screen built for exactly that question. `secret`,
`api_key`, `private_key` and `client_secret` are deliberately **out**; `cvv` and `pwd` are **in**.

**11. Nothing re-verifies on retry or replay, and nothing re-obfuscates on dispatch.** Two directions
of the same rule, stated because both are plausible-sounding changes that would break something
silently. Re-verifying a replay would fail every replay after a rotation; obfuscating on dispatch
would change the delivered bytes, which AC17 calls a defect by name and which would also invalidate
every signature (AC59).

**12. Verification rejections are logged with a reason code; nothing else records them.** Ruled in
**ADR-022 Decision 5**: `ingest.verification_failed` with `team_id`, `proxy_id`, `scheme` and one of
five value-free reason codes. Never a header value, a body, a secret or a computed signature (AC8).
An operator diagnostic; it does not touch AC46 — no event row, no counter, no analytics figure, no
notification. It is the only debugging affordance the feature has, and UX Direction point 7 names the
resulting silence as a real cost.

**13. Outbound signing is built to Owner ruling B. The backend proceeds; the surface stops.**
The Owner ruled signing proxy-level after PRD-10 and `design-10` were approved as per-destination.
Building the backend to the ruling is right — designing against text the Owner has overruled would be
worse — but the **surface** displaced by the ruling is the Designer's, downstream of a PRD amendment,
and this plan does not redesign it. Concretely: **built now** — one `signing` secret per proxy, one
signature entry per live member, signing applied to every destination of a signing-enabled proxy on
every attempt, and the proxy-scoped generate / disable / end-overlap endpoints. **Not built, and not
designed here** — `design-10` Screen 5's per-row `Signed` badge and `Manage signing` action, Screen 6's
`Manage destination signing` dialog, and Flows G, H and I. `Q-10-04` asks the Product Manager to amend
PRD-10; the Designer revises afterwards; **M8b** is the only milestone that waits. Flagged call 4's
ruling (the one-time reveal suppresses `Esc` and overlay dismissal, **Done** the sole keyboard-reachable
exit) survives the re-grain intact and binds wherever the reveal lands.

**14. `SecretStore` is the single reader and writer of `proxy_secrets`, and AC29's cap of two is a
property of its write path, pinned by test.** The schema permits three or more live secrets with no
migration — one of the things ruling A buys — while **PRD-10 AC29 caps it at two** ("there is no
third slot"). `SecretStore::replace()` deletes already-superseded rows before demoting the current
one, so at most two rows exist for a purpose at any instant and AC29 is literally true, including its
"held" and not merely its "honoured". **Both read paths loop the live set and assume no number**, so
raising the cap later changes one line of `SecretStore` and no consumer, no schema and no read-path
test. Whether it should be raised is `Q-10-04` item 2, to the Product Manager; until then #10 ships
two. This is the single-resolver discipline `RetryPolicy`, `StoredPayloadLookup` and `RetentionPolicy`
already hold — a second reader or writer of the table is a review finding, not a refactor.

## Data Model

> **✋ This whole section was the Project Owner's data-model gate — flag 1, APPROVED 2026-08-27.**
> It is stated once, in
> full, so the Owner rules on the complete set at once. The column-by-column reasoning and every
> rejected alternative are in **ADR-021**; this is the summary the gate is taken against. **Flag 1
> and flag 2 are coupled**: the change set below is the *recommended* storage model, and a `no` on
> flag 2 replaces it with the fully-enumerated alternative at the end of this section.

### The recommended change set — one new table and six columns, and nothing else

One migration, `database/migrations/2026_08_27_000001_add_sensitive_data_handling_schema.php`, plain
Blueprint, no raw DDL.

#### 1. New table `proxy_secrets`

| Column | Type | Null | Default | Cast | Serves |
|---|---|---|---|---|---|
| `id` | `bigint unsigned` auto-increment (`$table->id()`) | no | — | — | PK |
| `team_id` | `bigint unsigned`, `foreignId('team_id')->constrained()` | no | — | — | House convention (every domain table carries it). **RESTRICT** on delete — Laravel's default, and the shape `dispatched_payloads`/`destinations` already use |
| `proxy_id` | `bigint unsigned`, `foreignId('proxy_id')->constrained()->cascadeOnDelete()` | no | — | — | The relation. **CASCADE** on delete because the table has no independent lifecycle of its own — the `dispatched_payloads.webhook_event_id` precedent. Proxies are soft-deleted and never hard-deleted (`2026_07_30_000002`'s own comment), so the cascade never fires today |
| `purpose` | `string(32)` | no | — | `SecretPurpose` (new backed enum) | `verification` \| `signing`. `string`, not an `enum` column, so a third proxy-level secret later costs no migration |
| `value` | `text` | no | — | **`encrypted`** | AC1, AC26, AC34, AC56, AC57. `text` (64 KiB) against a ≤1024-character secret's ~1.4 KiB envelope; `proxies.ingest_token` is the existing `text` + `encrypted` precedent |
| `is_current` | `boolean` | **YES** | NULL | `boolean` | `true` = the live secret, `NULL` = superseded. Nullable **so the unique index below is a partial-unique constraint** |
| `expires_at` | `timestamp` | **YES** | NULL | `datetime` | NULL while current; `now() + 24h` at the moment the row is superseded (AC29, AC58) |
| `created_at` | `timestamp` | YES | NULL | `datetime` | The "changed {date}" / "generated {date}" every surface shows (AC26, AC57) |
| `updated_at` | `timestamp` | YES | NULL | `datetime` | Laravel default |

**Indexes — exactly one, and it is a constraint:**

| Index | Columns | Name | Serves |
|---|---|---|---|
| **UNIQUE** | `(proxy_id, purpose, is_current)` | `proxy_secrets_proxy_id_purpose_is_current_unique` | **At most one current secret per purpose per proxy**, enforced by the database. MySQL and SQLite both ignore NULLs in a unique index, so superseded rows (NULL `is_current`) are unconstrained. Its `(proxy_id, purpose)` prefix serves **every read the feature makes** |

No second index. The `team_id` and `proxy_id` foreign keys carry their own automatic indexes as
InnoDB requires; nothing else is added. The daily expiry sweeper scans a table holding at most two
rows per purpose per proxy and is on no hot path.

**Invariant, pinned by test:** `is_current IS NOT NULL` ⟺ `expires_at IS NULL`.

#### 2. `proxies` — three columns

| Column | Type | Null | Default | Cast | Serves |
|---|---|---|---|---|---|
| `verification_scheme` | `string(32)` | YES | NULL | `VerificationScheme` (new backed enum) | AC23, AC24. NULL = not required. **The resolution-time gate** — keeping it a column is what lets the ingest hot path skip the secrets query entirely (ADR-022 Decision 2) |
| `verification_header_name` | `string(128)` | YES | NULL | — | AC26, AC51. Plaintext and **deliberately visible** — the sender must be configured to match it. Single-valued across a rotation, which is why it is not on the secret row |
| `sensitive_fields` | `longText` | YES | NULL | `array` | AC13's per-proxy additions. `longText`, not `json`, so a future item can add an `encrypted` cast without a type change and a drop-and-re-add (the `webhook_events.headers` lesson) |

No index added to `proxies`. `ingest_token_hash`'s UNIQUE and the team foreign-key index are
untouched.

#### 3. `destinations` — three columns

| Column | Type | Null | Default | Cast | Serves |
|---|---|---|---|---|---|
| `credential_header_name` | `string(128)` | YES | NULL | — | AC30, AC33. Plaintext and visible. The `Authorization` default is supplied by the form, not by the schema |
| `credential_secret` | `text` | YES | NULL | **`encrypted`** | AC30, AC34 |
| `credential_set_at` | `timestamp` | YES | NULL | `datetime` | AC33's "changed {date}" |

No index added to `destinations`.

**Portability.** Plain Blueprint throughout; no raw DDL, no `LONGBLOB`, no `AFTER` clause. **This
migration is engine-portable**; the migration *set* remains MySQL-only for the reasons
`docs/stack/stack.md` records, and this adds no new engine constraint.

**Rollback** is one `dropIfExists('proxy_secrets')` plus two `dropColumn` calls, and `down()` is
exactly that. Stated plainly rather than left in the migration: **rollback is destructive to every
stored secret** — they are encrypted values in a table and columns being dropped, and cannot be
recovered afterwards.

### Explicitly *not* in the change set — verified item by item

- **No table other than `proxy_secrets`.** Not for rotation history, not for sensitive-field names,
  not for verification attempts or rejections, and **not `destination_secrets`** — the credential
  does not rotate, so a table would stand in for three columns (ADR-021 § Alternatives B).
- **No column added to, removed from, or altered on any other table** — `webhook_events`,
  `dispatched_payloads`, `deliveries`, `delivery_attempts`, `fifo_dispatches`, `teams`,
  `team_members`, `users`, `jobs`, `job_batches`, `failed_jobs` are all untouched. In particular
  **no `webhook-id` column on `deliveries`** (derived from the row's existing natural key, ADR-023
  Decision 3) and **no signing column on `destinations`** (Owner ruling B removes the need entirely).
- **No index added, altered or dropped on any existing table.** #11's four analytics indexes,
  `proxies.ingest_token_hash` UNIQUE, `webhook_events (team_id, payload_cleaned_at, created_at)`,
  `UNIQUE(delivery_id, attempt_number)`, `UNIQUE(dispatch_uuid, destination_id)` and
  `UNIQUE(webhook_event_id)` are all untouched and all still used.
- **No value added to any existing enum column** — `proxies.mode`, `proxies.processing_mode`,
  `deliveries.kind`, `deliveries.status`, `delivery_attempts.status`, `fifo_dispatches.status`,
  `destinations.http_method`. `verification_scheme` and `proxy_secrets.purpose` are **new**
  `string(32)` columns backed by new PHP enums; they extend nothing.
- **No change to any existing FK, `onDelete` behaviour or soft-delete flag.**
- **No backfill, no data migration, no default written to any existing row.** `proxy_secrets` starts
  empty and every new column is NULL on every existing row — which is exactly "no verification, no
  credential, no signing, no additions", so AC24, AC37 and AC63 hold by construction rather than by a
  migration step.
- **No `TeamPermission` case, no policy class, no policy method, no route middleware** (AC20, AC28).
- **No `TeamScope` registration for `ProxySecret`** — deliberately. The ingest path is team-unscoped
  and has no current team, so a global scope would break the read; every query reaches the table
  through an already-scoped proxy. `team_id` exists for consistency and for a future audit query, not
  as an enforced scope. Same posture `Delivery` and `WebhookEvent` already have.
- **No retention, GC, hold, window or erasure change** (AC36, AC62). `PurgeExpiredPayloads`,
  `RetentionPolicy`, the H0–H5 hold set, `retention.days`, `retention.dispatch_horizon_minutes` and
  the `queue:prune-failed --hours 168` literal are all untouched. No secret is payload content or
  carries retention state.
- **No new payload store, cache, export, archive or telemetry copy** (AC3). The revealed-payload
  envelope is a response, not a store, and it is `no-store` on the wire.

### Security assessment attached to this gate

- **Three kinds of secret at rest, in two encrypted columns, all at the at-rest floor** (AC1, AC34,
  AC57): `proxy_secrets.value` and `destinations.credential_secret`. Nothing is hashed and nothing is
  stored in the clear — forced by what the schemes compute (ADR-021 Decision 1), not chosen.
- **The `APP_PREVIOUS_KEYS` surface grows from four columns across three tables to six across five.**
  ADR-010 Amendment B's binding rule — never drop a prior key until every encrypted value has been
  re-encrypted under the current one — today covers `webhook_events.body`, `.headers`,
  `dispatched_payloads.body` and `proxies.ingest_token`; it gains `proxy_secrets.value` and
  `destinations.credential_secret`. **AC44 defers the re-encryption tooling and states the cost**, so
  this is where the widened list lives. **No application-key rotation may be performed until that
  tooling exists.** The recommended model is materially cheaper here than the alternative, which
  would take it to nine columns.
- **Two new plaintext columns, both deliberate.** `verification_header_name` and
  `credential_header_name` hold header **names**, not values; AC26 and AC33 keep them visible so the
  sender and the destination can be configured to match.
- **`sensitive_fields` holds member-typed field names in plaintext, by design.** AC15 requires names
  to stay visible. Same class as `destinations.url` (which may carry a token in its query string,
  AC39) and `proxies.response_body`, and `longText` so a future item can encrypt it without a type
  change.
- **No new at-rest copy of payload content.** AC3's closed set of two stores is untouched, verified
  against the full inventory in `Q-10-02`.
- **One new egress carrying a secret to a browser** — the AC57 one-time display — deliberately
  outside Inertia props and outside the session store (Technical ruling 5), `no-store`, logged by
  identifiers only.
- **A relation carries one exposure a column set does not**, and it is guarded twice: an accidental
  `->with('secrets')` on a resource-bound query would ship every live secret to the browser, so
  `ProxySecret` is never serialized into a resource **and** declares `$hidden = ['value']` (ADR-021
  Decision 6.1).
- **Fail-closed everywhere:** NULL scheme means not required, an empty live set means not configured,
  and an undecryptable secret fails the operation loudly rather than proceeding as though no secret
  were configured (AC11).
- **Reversibility:** total at the schema level; destructive at the data level. Both halves stated.

### The alternative change set — fixed columns, if the Owner declines flag 2

Enumerated so a `no` on the storage model is immediately buildable rather than sending the plan back.
**No new table.** Three columns per rotating purpose on the owning row, plus the same configuration
columns:

- **`proxies`, eleven columns:** `verification_scheme` `string(32)` NULL · `verification_header_name`
  `string(128)` NULL · `verification_secret` `text` NULL `encrypted` · `verification_previous_secret`
  `text` NULL `encrypted` · `verification_previous_expires_at` `timestamp` NULL ·
  `verification_secret_set_at` `timestamp` NULL · `signing_secret` `text` NULL `encrypted` ·
  `signing_previous_secret` `text` NULL `encrypted` · `signing_previous_expires_at` `timestamp` NULL ·
  `signing_generated_at` `timestamp` NULL · `sensitive_fields` `longText` NULL `array`.
- **`destinations`, three columns:** exactly as recommended above.
- **No index of any kind**, no FK, no backfill. Rollback is two `dropColumn` calls.

**Fourteen columns, no table**, against the recommendation's **one table plus six columns**. It costs
**zero extra queries** on the ingest and delivery paths and makes "at most two" structural rather
than write-path-enforced; it costs five encrypted columns instead of two for AC44's deferred tooling,
answers "what about three" only with another migration, and makes expiry a branch re-evaluated at
every read site rather than a property of the row set. Trade-off in full at ADR-021 § *Alternatives*
A. **Nothing else in this plan changes** under it except § *Services & Actions*' `SecretStore`
internals and the § *Test strategy* items that name rows.

## API

**No new page and no navigation entry.** Two existing GET routes gain props; the create/edit form
gains fields on routes that already exist; and four new mutating routes are added inside the existing
team-scoped group, each authorizing `update` on the proxy through `ProxyPolicy`.

| Method | Path | Controller | Gate | Returns |
|---|---|---|---|---|
| GET | `proxies/create`, `proxies/{proxy}/edit` | `ProxyController` | `create` / `update` | **existing**, plus `defaultSensitiveFields` and `standardWebhooksTolerance` page props, plus (edit only) `security` |
| POST/PUT | `proxies`, `proxies/{proxy}` | `ProxyController` | `create` / `update` | **existing**, extended validation (§ *Validation*) |
| GET | `proxies/{proxy}` | `ProxyController@show` | `view` | **existing**, plus the `security` prop |
| DELETE | `proxies/{proxy}/verification/overlap` | `ProxyVerificationOverlapController@destroy` | `update` | Inertia redirect (`back()`); ends the inbound overlap now (AC29) |
| POST | `proxies/{proxy}/signing` | `ProxySigningController@store` | `update` | **`application/json`** — `{ "secret": "whsec_…", "generated_at": … }`, `no-store, private`. Enable **and** regenerate, one action (AC56, AC58) |
| DELETE | `proxies/{proxy}/signing` | `ProxySigningController@destroy` | `update` | Inertia redirect; disables and **deletes every `signing` row** (ADR-021 Decision 5) |
| DELETE | `proxies/{proxy}/signing/overlap` | `ProxySigningOverlapController@destroy` | `update` | Inertia redirect; ends the outbound overlap now (AC29, AC58) |
| GET | `proxies/{proxy}/events/{event}/payload` | `ProxyEventPayloadController` | `view` | **changed shape** — § *Architecture D*, ADR-024. Route, gate, 410 and 404 unchanged |

- **The three signing routes are proxy-scoped, not destination-scoped** — Owner ruling B. There is no
  `proxies/{proxy}/destinations/{destination}/signing` route in this design.
- **The signing `store` endpoint is the only one returning JSON**, and only because it carries a
  secret that must not enter Inertia props or the session (Technical ruling 5).
- **No endpoint anywhere reads a stored secret back.** The `security` prop carries set/not-set, a
  changed/generated timestamp, an overlap expiry and header **names** — never a value, never a length.
- **The `security` prop's shape:** `{ verification: { scheme, header_name, secret_set,
  secret_changed_at, overlap_expires_at } | null, signing: { enabled, generated_at,
  overlap_expires_at } | null, destinations: { [id]: { has_credential, credential_changed_at } } }`.
- **Nothing here is gated on delivery state** — no endpoint checks whether a delivery is queued,
  retrying or mid-replay, mirroring design-07's AC17 treatment and design-10 § *Interactions*.

## Services & Actions

| Component | Kind | Responsibility |
|---|---|---|
| `App\Models\ProxySecret` | Eloquent | `proxy_secrets`. `value` cast `encrypted`; **`$hidden = ['value']`**; a `live()` scope. Never serialized into a resource. |
| `App\Enums\SecretPurpose` | backed enum | `verification`, `signing`. |
| `App\Services\SecretStore` | service | **The single reader and writer of `proxy_secrets`** (Technical ruling 14): `liveFor(Proxy, SecretPurpose): list<string>`, `replace()`, `generate()`, `endOverlap()`, `disable()`. Owns AC29's two-row cap. |
| `App\Support\RotationOverlap` | pure class | `HOURS = 24` (AC29, not configurable). |
| `App\Actions\ExpireProxySecrets` | `AsJob` | The delayed delete, **scalar arguments only** (`proxyId`, `purpose`), guarded on `expires_at <= now()`. |
| `App\Console` — `secrets:purge-expired` | scheduled daily | Liveness net for a lost delayed job (ADR-015 Decision 5's shape). Neither this nor the job can extend a window. |
| `App\Enums\VerificationScheme` | backed enum | The closed two-case list (AC23, AC50). |
| `App\Services\InboundVerifier` | service | The resolution-time gate and the only consumer of the `verification` live set. |
| `App\Verification\SharedSecretScheme`, `StandardWebhooksScheme` | plain classes | One per case (AC51, AC52, AC53). |
| `App\Support\StandardWebhooks` | pure class, no DB | Sign, verify, parse the signature list, `whsec_` prefix and base64 key, `hash_equals`, `TOLERANCE_SECONDS = 300`. **Shared by inbound and outbound** — AC55's "one implementation serves both directions". |
| `App\Support\SensitiveFields` | pure class | `DEFAULTS` (23 names, ADR-024 Decision 5) and `normalise()`. |
| `App\Services\SensitiveFieldMatcher` | service | Effective list = defaults ∪ proxy additions; `matchFor(string $name): ?MatchSource`. |
| `App\Support\PayloadObfuscator` | pure class, no DB | Walks a decoded document, replaces matched values whole (C6), returns `[document, pointerIndex]`. Deterministic, no clock, no I/O. |
| `App\Support\OutboundHeaders` | pure class | The five-step composition of § *Architecture C*. **The only place an outbound header set is built.** |
| `App\Exceptions\SecretUnavailableException` | exception | AC11's fixed, value-free failure. |
| `App\Http\Controllers\ProxyVerificationOverlapController`, `ProxySigningController`, `ProxySigningOverlapController` | controllers | The § *API* table. |
| `App\Http\Resources\ProxySecurityResource` | resource | The `security` prop. Status only — never a value, never a length. |

**Changed, not new:** `IngestController` (one gate, and Technical ruling 8's report wrap) ·
`DeliveryUnit` (carries the proxy's verification header names for AC27 and the proxy's live signing
secrets) · `DeliveryUnitResolver` (loads the proxy **`withTrashed()`** and asks `SecretStore` for the
signing set) · `DeliverToDestination::send()` (calls `OutboundHeaders`) ·
`ProxyEventPayloadController` (the envelope) · `ProxyController` (props and persistence) ·
`StoreProxyRequest`/`UpdateProxyRequest` (rules and the old-input scrub) · `Proxy` (a `secrets()`
`hasMany`, casts, `#[Fillable]`, docblocks) · `Destination` (casts, `#[Fillable]`, docblocks).

**Frontend**, all from `design-10`, all compositions over existing primitives — no new npm package
and no new `ui/*` primitive: the Verification and Sensitive fields sections plus the write-only
secret pattern in `ProxyForm.vue`; the Credential `Collapsible` in `DestinationRows.vue`; the
Verification card in `proxies/Show.vue`; the `[Hidden]` token rendering in `PayloadViewer.vue`; and
`resources/js/data/sensitiveFields.ts` / `verificationSchemes.ts` following the existing `DataOption`
convention. **The signing surface is not listed** — see Technical ruling 13.

## Validation

Form Requests, in the app's existing style. Every rule is an **input bound**, never a product
performance target (AC47).

**Proxy store/update — verification**

- `verification_scheme` — `nullable`, `Rule::enum(VerificationScheme::class)`. Absent/null = not
  required (AC24).
- `verification_header_name` — `required_if:verification_scheme,shared-secret`,
  `prohibited_unless:verification_scheme,shared-secret`, `string`, `max:128`, and a valid HTTP field
  name (`/^[A-Za-z0-9!#$%&'*+\-.^_`|~]+$/`). The `prohibited_unless` mirrors the
  `retry_*`/`prohibited_if:mode,simple` idiom already on this form.
- `verification_secret` — `nullable`, `string`, `min:8`, `max:1024`. **Required only when a scheme is
  selected and the proxy has no live `verification` secret** — an absent field on an
  already-configured proxy means "leave unchanged", which is the write-only contract (design-10
  § *Interactions*: a present-but-empty field must never submit as "clear the secret").
- `sensitive_fields` — `nullable`, `array`, `max:100`; `sensitive_fields.*` — `string`, `max:128`,
  non-blank after trim. Server-side the list is trimmed and de-duplicated by normalised form.

**Proxy store/update — destination credential**

- `destinations.*.credential_header_name` — `required_with:destinations.*.credential_secret`,
  `string`, `max:128`, same HTTP-field-name rule.
- `destinations.*.credential_secret` — `nullable`, `string`, `max:1024`. Absent means "leave
  unchanged"; the row's reconciliation by `id` (existing behaviour) is what makes that possible.

**Signing endpoints** take no body. `store` always generates a new secret — it is both Enable and
Regenerate.

**Failure behaviour.** All of the above are ordinary 422s rendered through the existing `InputError`
pattern. **On any validation failure of the proxy form, no submitted secret is flashed as old input**
— Technical ruling 7.

## Risks

| # | Risk | Mitigation |
|---|---|---|
| **R1** | **A very large valid JSON payload exhausts memory while being decoded to obfuscate it.** `INGEST_MAX_BODY_BYTES` defaults to 50 MiB and decoding expands that several-fold. | The failure is a 500, **never a disclosure** — the raw-bytes fallback is forbidden (Technical ruling 9). No ceiling ships at MVP. The follow-up, if the Owner wants one, is a byte ceiling plus a **distinct member-facing state**, which is the Designer's and is deliberately not invented here. The 50 MiB cap is itself flagged in `config/ingest.php` as a placeholder, and choosing that number is the Product Manager's or the Owner's. |
| **R2** | **The revealed JSON view is no longer byte-faithful** — whitespace normalised, duplicate keys collapsed, integer-like object keys possibly reordered by the JavaScript engine. | Accepted and named. C9's ruled consequence of parsing-to-obfuscate, confined to the JSON path; the stored bytes, the delivered bytes and every signature are untouched. Stated so it is not discovered at review, exactly as C9 asks. |
| **R3** | **`Delivery::proxy()` is a plain `belongsTo` while `Proxy` uses `SoftDeletes`**, so the resolver's new proxy load returns `null` for a soft-deleted proxy and blows up at runtime — and PHPStan cannot see it (`@property-read Proxy $proxy`). **This risk grew**: the resolver now needs the proxy for two things, AC27's strip and the signing set. | `DeliveryUnitResolver` must load `$delivery->proxy()->withTrashed()->firstOrFail()`. `ProcessIngestedWebhook` and `DeliverToDestination::settleDelivery()` are the existing precedents. Pinned by a test that soft-deletes the proxy and drives a retry. |
| **R4** | **A submitted secret reaches the `sessions` table** through Laravel's old-input flashing, which `dontFlash` cannot reach for the nested destination keys. | Technical ruling 7, with a test asserting the flashed old input contains no submitted secret. |
| **R5** | **A failing query writes its bindings into `failed_jobs.exception`, Horizon's 7-day copy and the log**, because `QueryException` interpolates them. | Every payload and secret column carries an `encrypted` cast, so bindings are ciphertext (ADR-021 Decision 1) — and Technical ruling 8 removes the interpolated statement from the capture-failure report. Pinned by tests. |
| **R6** | **A resource or query accidentally serializes a secret row.** New with the relation: an `->with('secrets')` on a resource-bound query would ship every live secret to the browser, where a column model would have needed an explicit key. | Two independent guards: `ProxySecret` is never serialized into a resource, and it declares `$hidden = ['value']` (ADR-021 Decision 6.1). Pinned by a test sweeping every proxy-bearing response for the absence of a stored secret's value. |
| **R7** | **AC29's cap of two is enforced by the write path, not by the schema.** A future writer that bypasses `SecretStore` could leave three live rows. | `SecretStore` is the single writer (Technical ruling 14), and a test asserts that three consecutive rotations leave exactly two rows. The read paths assume no number, so the failure mode is a longer-lived secret, not a broken verification or signature. |
| **R8** | **Key loss now breaks more.** The `APP_PREVIOUS_KEYS` surface grows to six columns and no rotation tooling exists (AC44). | ADR-010 Amendment B's binding rule is restated in ADR-021 § Impact with the full column list. **No application-key rotation until the tooling exists.** Not mitigated further — AC44 defers it deliberately and states the cost. |
| **R9** | **A member names their verification or credential header something that collides** with a hop-by-hop header, with `host`, or with one of the three `webhook-*` names. | The HTTP-field-name rule keeps it syntactically valid; ADR-023 Decision 2's precedence keeps the outcome deterministic (added headers always win, forwarded duplicates dropped case-insensitively). A member naming their verification header `webhook-signature` gets it stripped by AC27 and, if the proxy signs, replaced by ours — the correct outcome. |
| **R10** | **The delayed `ExpireProxySecrets` job is lost** (Redis flush, queue migration), leaving a superseded row past its expiry. | Honouring is by data — the live-set predicate excludes it at its expiry instant regardless. The daily sweeper is the liveness net. Pinned by a test that runs the sweeper with the job never dispatched. |
| **R11** | **Clock skew** makes `standard-webhooks` reject a legitimate sender near the tolerance boundary, with no member-facing diagnosis (AC46). | The tolerance is the specification's and is not ours to widen (AC53). Technical ruling 12's `timestamp_out_of_tolerance` reason code is what makes it diagnosable operator-side. Named rather than mitigated. |
| **R12** | **Two new queries on hot paths.** One indexed read on the ingest path (only when a scheme is configured) and one per delivery attempt (unconditional, for the signing set). | Named, not measured — AC47 asserts no numeric target. `verification_scheme` staying a column is what makes the ingest cost conditional, so AC24's "behaves exactly as it does today" holds at the query-count level. The delivery-path read precedes an outbound HTTPS request with a 15-second timeout. Full accounting at ADR-021 § Impact. |
| **R13** | **Verification adds a synchronous HMAC over the raw body to the ingest hot path**, inside the sender's latency budget, worst case 50 MiB. | Named, not measured. A proxy with verification off pays a single NULL check on a row already in memory. Recorded in ADR-022 § Impact. |
| **R14** | **A member loses the one-time signing secret** and their receivers stop trusting us. **Larger under ruling B**: one lost secret means reconfiguring *every* destination of that proxy. | `design-10`'s reveal is `Done`-gated with `Esc` and overlay dismissal suppressed (flagged call 4, overturned), and regeneration is one click. The widened blast radius is recorded in `Q-10-04` so the Product Manager amends with it visible. |
| **R15** | **PRD-10 and `design-10` describe per-destination signing, and a Reviewer reading them literally will open a Major against a correct implementation.** | `Q-10-04` to the Product Manager, with the exhaustive list of what goes stale; banners on this plan, ADR-021 and ADR-023; and **M8b withheld** so nothing user-facing ships against a spec that has not caught up. |

## Dependencies

**None new.** No Composer package, no pnpm package, no stack change, no infrastructure, no external
service. `docs/stack/stack.md` is untouched — no row changes.

Assessed and declined, with the assessment recorded so it is not re-argued: the official
`standard-webhooks` PHP package (Technical ruling 6, ADR-022 § Alternatives). **Not rejected on
merit.** If the Owner prefers it, that is a new-dependency gate and no other section of this plan
changes.

Feature dependencies, all Done: **#5** (the stored payloads #10 protects and the retention contract
AC36/AC62 compose with), **#3** (capture, and the user-defined response AC25 forbids serving to an
unverified sender), **#6** (the read surface AC18 narrows and the retry path AC32/AC60 bind), **#2**
(the permission model AC28 and AC33 reuse), **#4 as amended by ADR-020** (by-reference delivery,
which is what makes AC35 and AC61 structural). **#10 depends on #8, #9, #12, #13 and #14 not at all,
and pre-empts none of them** (AC48).

## Implementation Notes

1. **Read the ingest request body exactly once** and pass the same string to the verifier and to
   `WebhookEventCapture` (ADR-022 Decision 4).
2. **`InboundVerifier` is the only reader of `proxies.verification_scheme`** and the only consumer of
   `SecretStore`'s `verification` live set. **`SecretStore` is the only reader and writer of
   `proxy_secrets`.** A second of either is a review finding, not a refactor.
3. **`OutboundHeaders` is the only place an outbound header set is built.** Precedence is
   removal-then-addition, matched on the **lowercased** name; `Http::withHeaders()` takes a PHP array
   and will happily emit `authorization` and `Authorization` as two headers.
4. **Do not add the three `webhook-*` names to `DeliveryUnit::STRIPPED_HEADERS`.** It would change
   what every destination of a non-signing proxy receives and breach AC63 (ADR-023 Decision 5).
5. **`DeliveryUnitResolver` must load the proxy `withTrashed()`** — R3. PHPStan will not catch it.
6. **The delivery job's arguments stay scalar.** `JobDecorator` auto-applies `SerializesModels` to
   top-level parameters, so a model argument would silently opt back in; and a non-scalar argument is
   how a secret or a payload re-enters Redis, `failed_jobs` and Horizon's 7-day store (ADR-020
   Decision 9, ADR-021 Decision 8). The same applies to `ExpireProxySecrets`.
7. **Every secret goes through the `encrypted` cast**, never a hand-rolled `Crypt::encryptString()`
   assignment. It is what keeps plaintext out of query bindings and therefore out of exception
   messages (R5).
8. **`ProxySecret` is never serialized into a resource, prop, DTO or log line, and declares
   `$hidden = ['value']`** as a second guard (R6). No resource, prop, event or exception message may
   carry a secret value or its length. The one exception is the AC57 JSON response, scoped to
   generation.
9. **Scrub submitted secrets from flashed old input** — Technical ruling 7. `dontFlash` covers
   `verification_secret`; the nested `destinations.*.credential_secret` needs the
   `failedValidation()` override because `Arr::forget()` has no wildcard support.
10. **Wrap the capture-failure report** so the interpolated SQL never reaches the log — Technical
    ruling 8.
11. **`ProxyResource` serves index, show *and* edit.** Security status goes on the sibling `security`
    prop, not on the resource (Technical ruling 3). `create()` renders no proxy resource at all, so
    the default list and the tolerance are page props on both `create()` and `edit()`.
12. **Do not add fields to `DestinationBreakdownRow` or edit `DeliveryStatistics`** — Technical
    ruling 4.
13. **`ProxyForm.vue`'s mount-seed vs in-session-typed distinction is load-bearing and this feature
    reuses it.** `props.initial` is never mutated and is the restore source; `watch()` fires only on
    an in-session change, never on mount. Switching the verification scheme clears the **in-session,
    unsaved** secret field only, and never touches what is persisted until the form is saved.
    review-07's Major came from getting exactly this wrong on the retry fieldset — read `plan-07`
    § *Technical ruling 4* before touching it.
14. **A present-but-empty secret field must not submit as "clear the secret."** Follow the
    `form.transform()` normalisation the response and retry sentinels already use.
15. **The `{tolerance}` and default-list copy interpolate single-sourced values**, never hand-typed
    ones — `StandardWebhooks::TOLERANCE_SECONDS` and `SensitiveFields::DEFAULTS`, emitted as props.
    This is the `defaultAttemptLimit` precedent design-10 names, and AC53 requires it.
16. **Screen 2 renders every default literally**, one badge per entry, wrapped in the existing
    `flex flex-wrap` row — never truncated, never behind "show more", never summarised back down to
    three category words (correction C4).
17. **The `[Hidden]` token is inert** — no click handler, no `tabindex`, no role that announces as
    actionable (AC20) — and carries **both** a native `title` and an `sr-only` text node, not a bare
    `aria-label` (note N1).
18. **`PayloadViewer` keeps rendering through text interpolation, never `v-html`** (ADR-017,
    unchanged), and branches on the response `Content-Type` rather than guessing.
19. **Do not build `design-10` Screens 5 (the `Signed` badge and `Manage signing` action) or 6, or
    Flows G–I.** Owner ruling B displaced them; the surface is the Designer's after the PRD amendment
    (Technical ruling 13, `Q-10-04`). **Screen 5's `Credential` badge is unaffected and is built.**
    When the reveal surface does land, flagged call 4's ruling binds it: `Esc` and overlay dismissal
    suppressed for that sub-state only, **Done** the sole keyboard-reachable exit, no confirmation
    step added in front of it.
20. **`SelectItem value=""` does not work** with the underlying Select primitive; use a sentinel for
    "Not required" and normalise it on submit (note N2).
21. **Write-only secret inputs suppress password-manager autofill** (`autocomplete="off"`, note N3)
    and are the plain `Input type="password"` Screen 1 specifies — **not** `PasswordInput.vue`,
    pending `Q-10-03`.
22. **No manual verification claim is valid unless `public/hot` is absent and the check ran against
    `pnpm run build`.** review-07 Finding 8 is the standing trap and there is still no frontend test
    framework (backlog T31).
23. **The suite runs only through `./vendor/bin/sail test`.** The migration set is MySQL-only; any
    dual-engine claim must be verified at the query-builder level, never by running the suite on
    SQLite.

## Test strategy

Grouped by criterion, named per acceptance criterion.

**Encryption at rest and the closed store set (AC1–AC3, AC10, AC34)**
- `proxy_secrets.value` and `destinations.credential_secret` round-trip through their cast and the
  **raw database value is not the plaintext** — asserted with a raw query, not through the model.
- A secret write produces **no plaintext secret in the query log**.
- The `APP_PREVIOUS_KEYS` column list in ADR-021 § Impact matches the casts actually declared — a
  reflection test over `ProxySecret`, `Proxy`, `Destination`, `WebhookEvent` and `DispatchedPayload`,
  so the list cannot silently drift from the code.

**Failure records and logs (AC5, AC8; `Q-10-02`)**
- A `QueryException` on a payload-bearing insert carries **ciphertext, not plaintext**, in its
  message; likewise for a failed secret write.
- The capture-failure report carries identifiers and a SQLSTATE and **no SQL statement** (Technical
  ruling 8).
- `queue:prune-failed --hours 168` **and** Horizon's `failed`/`monitored` trim (10080 minutes) are
  both strictly below the resolved `retention.days` window — one test asserting all three, because
  two are literals in different files and one is env-overridable (`Q-10-02` finding B).
- No log line emitted by this feature contains a secret, a header value, a body or a signature.

**Field obfuscation (AC12–AC22, C3, C6, C8)**
- The default list has **23 entries**, no two of which collide after normalisation, each already in
  normalised-equal form to its displayed spelling.
- Matching is case- and separator-insensitive (`Password`, `pass_word`, `PASS-WORD` all match) and
  **exact, never substring** — `tokenizer_version` and `token_count` do not match; `tokens` does not
  match `token`.
- Nesting: a sensitive field at depth 4, and inside an array element, is obfuscated.
- **C6:** a sensitive field whose value is an object or array is replaced whole — the response
  contains none of its sub-keys, at any depth.
- **AC16:** no character of the value, no length signal, and two fields holding the same real value
  produce indistinguishable output.
- **C3:** a `password` match returns `"default"`; an `ssn_last4` addition returns `"addition"`; a name
  in **both** returns `"default"`.
- **AC22:** a non-JSON body returns `text/plain` with the bytes unchanged and **no** envelope.
- **AC19:** adding a name hides it in an event captured **before** the change, on the next request,
  with no migration; removing it reveals it again.
- **AC17:** the stored `webhook_events.body` is byte-identical before and after a reveal, and a
  dispatch carries the **real** values — asserted on the outbound request, not on the stored row.
- **AC18:** a direct request to the payload endpoint returns the obfuscated envelope.
- **AC21:** a cleaned event still returns 410 and never an envelope.

**Inbound verification (AC23–AC29, AC51–AC53)**
- **AC24:** a proxy with no verification behaves identically to today — same status, same body, same
  capture, same dispatch — asserted against a proxy with `verification_scheme` NULL and no secret
  rows, **and asserted to issue no `proxy_secrets` query**.
- **AC25, the four negatives, one test each:** a failed verification returns **401**, creates **no
  `webhook_events` row**, creates **no delivery and no `fifo_dispatches` row**, and does **not**
  return the proxy's configured response (asserted against a proxy configured with a 200 and a body).
- `shared-secret`: correct value in the named header verifies; wrong value, missing header and wrong
  header name all fail.
- `standard-webhooks`: a specification-computed signature verifies; **a multi-entry space-delimited
  list verifies when only the second entry matches**; a non-`v1` entry is skipped rather than
  failing; a missing or malformed one of the three headers fails; hex instead of base64 fails; a
  `whsec_`-prefixed secret and a bare base64 secret both work.
- **AC53:** a timestamp `TOLERANCE_SECONDS + 1` either side is rejected; one second inside is
  accepted.
- **AC29:** during an overlap both live secrets verify; after the expiry only the current does,
  **with the sweeper never run**; a second rotation makes the oldest stop verifying immediately;
  **End overlap now** makes the previous stop verifying immediately.
- **R7:** three consecutive rotations leave **exactly two rows** for that purpose.
- **The unique index holds:** two current rows for one `(proxy_id, purpose)` cannot be inserted.
- **AC28:** a Member without update rights on a teammate's proxy is 403 on every new mutating
  endpoint.
- **AC11:** a proxy whose verification secret cannot be decrypted returns **500**, not 401 and not the
  configured 2xx, and captures nothing.
- **ADR-022 Decision 6:** a replay of a verified proxy's event dispatches without re-verifying.

**Outbound credential and signing (AC27, AC30–AC39, AC54–AC64, as re-grained by ruling B)**
- **AC37/AC63, the regression that matters most:** a destination with no credential, on a proxy with
  no verification and no signing secret, produces a **byte-identical** outbound request to the
  pre-#10 baseline — header set and body both asserted.
- **AC30/AC32:** the credential is present on attempt 1, on a retry and on a replay, and absent on
  every other destination of the same proxy.
- **AC30:** the value is sent **verbatim** — `Bearer abc123` arrives unchanged, with no prefix added.
- **AC38:** a forwarded inbound header of the same name as the credential header is displaced, in
  either letter case.
- **AC27:** under `shared-secret` the member-named header is stripped outbound; under
  `standard-webhooks` all three `webhook-*` headers are stripped — and **a proxy with verification
  off still forwards a `webhook-signature` a sender happened to send** (AC43).
- **AC64:** with signing on, the outbound `webhook-*` headers are ours and there is exactly one of
  each, even when the inbound request carried them.
- **Ruling B:** enabling signing on a proxy signs dispatches to **every** destination of that proxy,
  including one added afterwards, with the **same** secret; a proxy without a signing secret signs
  none of them.
- **AC55/AC59:** the signature verifies against the specification, computed over the **exact
  dispatched bytes**, and the body is byte-identical to the unsigned case.
- **AC60:** `webhook-id` is identical on attempt 1 and its retry, **different** on a replay of the
  same event, and **different per destination of one dispatch** even though the key is shared;
  `webhook-timestamp` moves between attempts.
- **AC58:** during a signing overlap the header carries **one entry per live secret** and each
  verifies; after expiry, one.
- **AC56/AC57:** the secret is generated, returned **once** as JSON with `no-store`, and is absent
  from every subsequent page prop and every subsequent response; the value is `whsec_`-prefixed
  base64.
- **ADR-021 Decision 5:** disabling signing deletes every `signing` row; re-enabling generates a
  **different** secret.
- **AC11:** an undecryptable credential or signing secret fails the attempt **without sending**, and
  the recorded `error_summary` contains no part of the secret.
- **AC35/AC61:** the queued delivery job's payload contains neither secret — asserted on the
  serialized job, positionally, as `AdvanceProxyFifoQueueTest` does for its own scalars.
- **R3:** a retry whose proxy has been soft-deleted still resolves, still applies AC27's strip and
  still signs.

**Configuration surfaces and persistence (AC13, AC26, AC28, AC33, AC36, AC62)**
- A write-only field left untouched on save leaves the stored secret **unchanged**; an empty string
  does not clear it.
- Turning the scheme back to **Not required** leaves the secret rows in place, dormant, and
  verification stops (ADR-021 Decision 5).
- **R6:** **no response from any endpoint contains a stored secret** — a sweep across `show`, `edit`,
  `index`, the events pages and the payload endpoint asserting the absence of every secret's value,
  including a case where the proxy's `secrets` relation has been eager-loaded.
- **R4:** a 422 on the proxy form leaves **no submitted secret** in the flashed old input, including
  the nested `destinations.*.credential_secret`.
- **AC36/AC62:** running `PurgeExpiredPayloads` over an expired event leaves every `proxy_secrets`
  row and every credential column untouched.
- **AC13:** additions are per proxy — a second proxy in the same team is unaffected.

**Rotation lifecycle (AC29, AC58; ADR-021 Decision 3)**
- `ExpireProxySecrets` deletes only rows whose window has passed, and is a **no-op** when a further
  rotation has restarted it.
- **R10:** the daily sweeper deletes an expired row when the delayed job never ran.
- End-overlap-now is idempotent.
- The `is_current` ⟺ `expires_at IS NULL` invariant holds after every `SecretStore` operation.

**Manual verification (no frontend test harness exists — backlog T31)**
`design-10` Flows A–F, run against `pnpm run build` with `public/hot` confirmed absent
(Implementation Note 22), in both themes and at 360px. **Flows G, H and I are excluded** — the
surface they describe is not built (Technical ruling 13).

## Explicitly out of scope for this plan

- **Any third verification scheme** — `github`, `stripe`, `slack`, or any other per-vendor
  construction. AC50 closes the list and makes each addition a Project Owner decision.
- **IP allow-listing, mutual TLS, or any free-form/member-composed verification configuration**
  (AC23, AC50).
- **Any analytics, counter, badge, placeholder or notification for a rejected inbound request**
  (AC46). Technical ruling 12's operator log line is not one of these.
- **Value-pattern secret detection** — card checksums, entropy tests, key-shaped strings (AC14).
- **Partial disclosure of an obfuscated value** ("last four digits") — AC16.
- **A per-field reveal, for any role, and any new permission for one** (AC20, AC28).
- **A team-level sensitive-field list** (AC13). Additive later.
- **Any field-level treatment of a non-JSON payload** (AC22).
- **Any header display surface.** AC41 records none exists; AC42 binds whichever later item adds one.
- **Application-key rotation or re-encryption tooling** (AC44) and **per-team or per-plan keys**
  (AC45).
- **Cleaning up secrets already embedded in `destinations.url`** (AC39) — a current fact, left as
  found, with no detection, warning, migration prompt or rewrite.
- **A byte ceiling on payload parsing, and any new member-facing state for one** — Technical ruling 9,
  R1.
- **An affordance to remove (rather than replace) a destination credential.** `design-10` designs
  none, and shipping an undesigned control is worse than the gap. Raised as `Q-10-03`; whatever it
  answers is additive.
  *(No longer out of scope — superseded by § Revision A, technical ruling 15, 2026-08-27. `Q-10-03`
  was answered by the Designer, `design-10` Screen 3 now designs the control, and correction B3's
  transport is ruled. This bullet's own words are left as written because they still describe why the
  plan withheld the control at certification, and the addition they anticipated is the one that
  landed.)*
- **The outbound-signing surface** — Technical ruling 13. The backend ships; the screens wait on the
  PRD amendment (`Q-10-04`) and the Designer's revision.
- **Raising AC29's cap above two live secrets.** The schema permits it; the write path does not, and
  whether it should is `Q-10-04` item 2.
- **Any change to retention, GC, holds, erasure, retry, replay, processing mode, the mode attribute,
  FIFO ordering, or #11's figures and indexes.**
- **Any second payload read surface, export, download, share path, cache or archive** (AC3).

## Milestones (task-breakdown-ready)

| # | Milestone | Blocked by |
|---|---|---|
| **M1** | The migration (§ *Data Model*), `ProxySecret`, `SecretPurpose`, model casts, `#[Fillable]` entries, the `secrets()` relation and docblocks, with a test that every existing index and column survives | **✋ flag 1** |
| **M2** | **Obfuscation engine, no surface:** `SensitiveFields`, `SensitiveFieldMatcher`, `PayloadObfuscator`, the pointer index, the 23-name list and its collision test | — |
| **M3** | **Standard Webhooks primitive, no surface:** `App\Support\StandardWebhooks` — sign, verify, list parsing, `whsec_` handling, constant-time compare, tolerance — with specification-derived fixtures | — |
| **M4** | **Revealed-payload envelope:** `ProxyEventPayloadController`'s dual shape, `PayloadViewer.vue`'s branch and `[Hidden]` rendering with both C3 descriptions, the non-JSON path unchanged | **✋ flag 4**, M1, M2 |
| **M5** | **Sensitive fields configuration:** validation, persistence, Screen 2, the default-list page prop on create and edit | **✋ flag 1**, M2 |
| **M6** | **`SecretStore` and inbound verification:** `SecretStore`, `RotationOverlap`, `ExpireProxySecrets`, the daily sweeper, `VerificationScheme`, `InboundVerifier`, the two schemes, the `IngestController` gate, the 401 shape and reason-code log, Screen 1, Screen 4, the verification end-overlap endpoint | **✋ flags 1, 2, 3**, M3 |
| **M7** | **Outbound credential:** Screen 3, the resolver's `withTrashed()` proxy load, `OutboundHeaders` with AC27's strip and AC38's precedence | **✋ flags 1, 2**, M1 |
| **M8a** | **Outbound signing — backend only:** the three proxy-scoped endpoints, signing on every attempt to every destination of a signing-enabled proxy, AC58's multi-entry signature, `webhook-id` derivation | **✋ flags 1, 2**, M3, M6, M7 |
| **M8b** | **Outbound signing — surface.** The generate/regenerate one-time reveal, the enabled/disabled/overlap states and any status indicator, **as the Designer specifies them after the PRD amendment** | **`Q-10-04` → PM amendment → Designer revision**, then flags 1 and 2 |
| **M9** | **Cross-cutting hardening and the verification sweep:** the old-input scrub, the capture-report wrap, the prune/trim/retention ordering test, the secret-absence sweep, and design-10 Flows A–F against a production build with `public/hot` removed | M4–M8a |

**Two of the ten are not gate-blocked** — M2 and M3 are pure, surface-free and dependency-free, and
between them they carry the two hardest pieces of logic in the feature. A `no` on any flag costs the
feature a capability, not a milestone. M7 precedes M8a because both write through `OutboundHeaders`
and landing them together would make an AC37/AC63 regression hard to attribute. **M8b is the only
milestone blocked by something other than an Owner gate**, and it is deliberately last: nothing
user-facing for signing ships against a spec that has not caught up. M9 runs after M8a; if `Q-10-04`
is answered in time, M8b precedes it, and if not, M9 runs without the signing surface and M8b brings
its own verification pass.

## Handoff

- **Inputs:** Approved **PRD-10** (64 ACs, `## Amendment A` ratified whole) · **design-10**, approved
  at the design gate with C1–C10, its **approval record governing** over the spec body · the Project
  Owner's two mid-flight rulings of 2026-08-27 (quoted at the head of this plan) ·
  `docs/questions/prd-10-q-10-01-…` (RESOLVED) · `prd-10-q-10-02-…` (**RESOLVED here**) · ADR-003,
  ADR-004, ADR-005, ADR-006, ADR-008, ADR-009, ADR-010 (+ Amendment B), ADR-011, ADR-012, ADR-013,
  ADR-014, ADR-015, ADR-016, ADR-017, ADR-018, ADR-020 · `plan-05`, `plan-06`, `plan-07` (Technical
  ruling 4 and Revision A), `plan-08`, `plan-11` · `docs/reviews/review-06`, `review-07` (Finding 8) ·
  `docs/standards/` (architecture, coding, testing, planning, documentation, design) ·
  `docs/stack/stack.md` · **the Standard Webhooks specification, `standardwebhooks.com`** · and the
  code on `main` at `6bfb782`: `IngestController`, `WebhookEventCapture`, `ProcessIngestedWebhook`,
  `DeliverStep`, `DeliverToDestination`, `DeliveryUnit`, `DeliveryUnitResolver`, `RetryDelivery`,
  `StoredPayloadLookup`, `PurgeExpiredPayloads`, `ProxyController`, `ProxyEventPayloadController`,
  `ProxyPolicy`, `ProxyResource`, `ProxyFormResource`, `DestinationResource`,
  `Store/UpdateProxyRequest`, `DeliveryStatistics`, the migration set, `routes/web.php`,
  `routes/ingest.php`, `routes/console.php`, `bootstrap/app.php`,
  `config/{ingest,retention,horizon,session,cache,logging}.php`, `ProxyForm.vue`, `proxies/Show.vue`,
  `DestinationRows.vue`, `PayloadViewer.vue`, `ReplayDialog.vue`, `CopyField.vue`,
  `PasswordInput.vue`, `resources/js/types/proxies.ts`; and vendor source for
  `QueryException::formatMessage()`, `Arr::forget()` and `Handler::invalid()`.
- **Outputs:** this plan · **ADR-021** (Proposed) · **ADR-022** (Proposed) · **ADR-023** (Accepted by
  ratification) · **ADR-024** (Proposed) · inline amendment/supersession annotations on **ADR-008**
  and **ADR-017** · the completed Answer block in **`Q-10-02`** (RESOLVED) · **`Q-10-03`** (OPEN,
  Designer) · **`Q-10-04`** (OPEN, Product Manager).
- **Dependencies:** none new. Within stack.
- **Outstanding Questions: two.**
  - **`Q-10-04` — Product Manager, blocking M8b only.** Owner ruling B makes outbound signing
    proxy-level; PRD-10 AC54/AC63 and `design-10` Screens 5–6 and Flows G–I say per destination. Asks
    for a PRD amendment, lists exhaustively what goes stale in both documents, and names the one
    substantive trade-off the amendment should be ruled with in view — a proxy's fan-out becomes one
    trust domain, so any destination operator can verify and forge traffic addressed to the proxy's
    other destinations. **Item 2** asks whether AC29's "at most two … there is no third slot" stands,
    given the Owner's storage direction contemplates more; until ruled, #10 ships two.
  - **`Q-10-03` — Designer, blocking nothing.** **(i)** `design-10` gives no affordance to *remove* a
    destination credential, only to replace it, which is asymmetric with signing's explicit Disable
    and leaves an optional capability a member can turn on but not off. **(ii)** note **N3** states
    there is no `type="password"` precedent in this app; `resources/js/components/PasswordInput.vue`
    exists. The plan builds to the spec as written in both cases and both answers are additive.
  - **One item recorded for the Product Manager's awareness, blocking nothing:** Technical ruling 8
    removes an encrypted-at-rest copy of payload content from the application log, on the reading that
    AC3's enumeration of stores does not admit it. If the Product Manager reads AC3 as already
    excluding an encrypted log copy, nothing changes; if they read it as needing an amendment, that is
    theirs. Recorded in `Q-10-02`'s answer rather than raised separately, because the change is
    protective either way.

### Owner-approval flags (✋) — **all four APPROVED (Project Owner, 2026-08-27)**

Stated in full, as the house format requires, because this is the single place the Owner reads it.
This plan was self-certified except for these four items, and the Project Owner approved all four
together on 2026-08-27.

**Flags 1 and 2 were coupled**, and the coupling resolved the recommended way: flag 2 was approved,
so flag 1's change set stands as enumerated. The alternative fixed-column change set at the end of
§ *Data Model* is **not** taken; it is retained as the record of what was decided against.

**ADR-021, ADR-022 and ADR-024 are Accepted** as of the same date. Nothing below is outstanding.

1. **✋ Data-model change — one new table and six columns.**
   **New table `proxy_secrets`:** `id` bigint unsigned PK · `team_id` bigint unsigned NOT NULL,
   `foreignId->constrained()` to `teams.id`, **RESTRICT** on delete · `proxy_id` bigint unsigned NOT
   NULL, `foreignId->constrained()->cascadeOnDelete()` to `proxies.id`, **CASCADE** on delete ·
   `purpose` `string(32)` NOT NULL, cast to the new `SecretPurpose` enum (`verification`, `signing`) ·
   `value` `text` NOT NULL, cast **`encrypted`** · `is_current` `boolean` **NULL** default NULL ·
   `expires_at` `timestamp` NULL default NULL · `created_at` and `updated_at` `timestamp` NULL.
   **One index, and it is a constraint: `UNIQUE(proxy_id, purpose, is_current)`**, name
   `proxy_secrets_proxy_id_purpose_is_current_unique` — a portable partial-unique giving "at most one
   current secret per purpose per proxy", whose `(proxy_id, purpose)` prefix serves every read.
   **`proxies` gains three columns:** `verification_scheme` `string(32)` NULL (enum cast),
   `verification_header_name` `string(128)` NULL, `sensitive_fields` `longText` NULL (`array` cast).
   **`destinations` gains three columns:** `credential_header_name` `string(128)` NULL,
   `credential_secret` `text` NULL (**`encrypted`** cast), `credential_set_at` `timestamp` NULL.
   **No index is added to `proxies` or `destinations`.**
   **Additive only:** no other table, no column added to or altered on any other table, no index
   added/altered/dropped on any existing table, no enum value added to any existing enum column, no
   change to any existing FK or `onDelete` behaviour, no backfill and no data migration. Every new
   column is NULL on every existing row and `proxy_secrets` starts empty, which is exactly
   AC24/AC37/AC63. **Rollback is one `dropIfExists` plus two `dropColumn` calls — and is destructive
   to every stored secret**, which cannot be recovered afterwards. The security assessment attached to
   this gate is in § *Data Model*: three kinds of secret at rest in two encrypted columns; the
   `APP_PREVIOUS_KEYS` surface growing from four columns across three tables to **six across five**,
   with **no application-key rotation permitted until AC44's tooling exists**; two deliberately
   plaintext header-name columns; and **no new at-rest copy of payload content**.
2. **✋ ADR-021 — secret handling, storage and rotation. A choice between two specified models.**
   **Proposed.** It decides that every secret is stored **recoverably encrypted rather than hashed**
   (forced by what the schemes compute: two are HMAC key material, the third is presented verbatim);
   that a rotating secret is held as **rows in a relation with an expiry timestamp**, retrieved as the
   non-expired set — **the Owner's ruling A, presented as the recommendation and not treated as
   pre-approved**; that the **destination credential stays as columns because it does not rotate**,
   which is the one place the model does not fit; that the overlap is a **fixed 24-hour class
   constant** and expiry is data, with a delayed job and a daily sweeper for hygiene only; that
   **disabling signing deletes** while **turning verification off retains dormant secrets**, and why
   the two differ; that a secret that cannot be decrypted fails the operation loudly — 500 inbound, a
   failed attempt outbound, never a silent bypass; and that the one-time signing-secret display
   travels as a JSON XHR rather than an Inertia prop or a session flash.
   **The alternative ruling available to the Owner:** the fixed-column model, enumerated in full at
   § *Data Model* — fourteen columns and no table, zero extra queries on the ingest and delivery
   paths, "at most two" structural rather than write-path-enforced, at the cost of five encrypted
   columns for AC44's deferred tooling and a migration to answer "what about three". Either ruling is
   buildable with no change to any other section of this plan.
3. **✋ ADR-022 — inbound verification at the ingest boundary.** **Proposed.** The *behaviour* is
   already ratified — the Owner settled V2 directly and PRD-10's approval ratified AC23–AC29 and
   AC51–AC53 — so what this asks for is the **seam**: that verification runs inside `IngestController`
   after proxy resolution and before capture (not middleware, not a pipeline step, for reasons
   ADR-010 already paid for once), and that the closed scheme list is a two-case enum with one handler
   each. **AC50 makes adding a scheme a Project Owner decision every time**, so the Owner should
   ratify the structure those future decisions will be taken against — and the structure is
   deliberately cheap (one enum case, one handler, one select option) precisely so the conversation
   each time is only about whether to open the list.
4. **✋ ADR-024 — field obfuscation and the revealed-payload envelope.** **Proposed.** It changes the
   response shape of the system's **only** payload-content egress — a surface ADR-017 § Impact marks
   security-sensitive and Owner-gated — from raw `text/plain` to an `application/json` envelope **for
   JSON payloads only**, and in doing so **adopts an alternative ADR-017 rejected by name**. Every
   other ADR-017 hardening property is unchanged: `nosniff`, `no-store, private`, never logged, never
   cached, never a prop, text interpolation and never `v-html`, 410 on cleaned, 404 on never captured.
   It also fixes the **product default sensitive-field list at 23 names in the three families AC12
   states** — deliberately excluding `secret`, `api_key` and `private_key`, because AC12 forbids a
   member removing a default, so a wrong entry is permanent and invisible while a missing one is a
   two-second addition.

**Not tripped, verified item by item against `CLAUDE.md`'s major-decision list:** **no new Composer or
pnpm dependency** (§ *Dependencies*; the `standard-webhooks` package was assessed and declined, and is
named so a `yes` is available without re-analysis); **no stack change** — `docs/stack/stack.md`
untouched, no row changes; **no new permission, role, policy class or policy method** (AC20, AC28 —
correction C2 is a *reuse* of `Show.vue`'s existing `canUpdate` and of `ProxyPolicy::update`); **no
new route middleware**; **nothing irreversible** — the schema change rolls back, and no destructive
operation exists beyond a member's own explicit disable/end-overlap actions, both of which design-10
rules non-destructive by construction; **no change to retention, GC, holds, erasure, retry, replay,
processing mode, the mode attribute or FIFO ordering**; **no new payload store, export or egress path
beyond the one whose shape flag 4 covers**. **V3, V5 and V8 are not reopened**, and ADR-020's
payload-in-the-queue ruling is relied on unchanged rather than revisited.

### Why four ADRs were warranted here, when the previous item needed none

`plan-11` carried Owner gates and **no** ADR, on the ground that the gates *were* the decision record
and an ADR would restate them. That reasoning does not transfer, and each of #10's four clears the bar
on its own:

- **ADR-021** decides a **persisted shape that is hard to reverse once data exists** and a **security
  posture** a later reader will otherwise re-litigate. "Why is this secret not hashed like a
  password?" is the first question anyone will ask, and without the record the likely outcome is
  somebody hashing one of them and breaking `standard-webhooks` when a real integration is onboarded.
  The Owner's gate is on the posture; the ADR carries the reasoning that outlives the gate — including
  the honest comparison of the two storage models, which a flag alone could not hold.
- **ADR-022** decides **where an authentication check lives** — a placement this project has already
  got wrong once in the adjacent case (ADR-010 had to move raw capture out of the pipeline for the
  same reason), and one that determines whether AC25's four negatives are guaranteed or merely
  intended. It also defines the seam **AC50 will be tested against** every time a vendor scheme is
  proposed.
- **ADR-023** **amends two named properties of an Accepted, Owner-ratified ADR.**
  `docs/standards/documentation.md` requires that to be recorded on the ADR rather than absorbed into
  a plan, and PRD-10 § Consequences assigns the recording to the Principal Engineer by name. A plan
  section would not have given ADR-008 an inline pointer, and a later reader of ADR-008 alone would
  still believe no header is ever added.
- **ADR-024** **partially supersedes an Accepted ADR's binding response hardening and adopts an
  alternative it rejected by name.** Same rule, same reason — and the adoption specifically needs the
  record, because the honest justification is that the *premise* changed (a body that parses as JSON
  is by definition valid UTF-8), not that ADR-017's judgement was wrong.

**And the ADRs this feature touches, walked one by one so the answer is "considered", not
"overlooked":**

- **ADR-008** — two properties **amended** by ADR-023, annotated inline; the safe-allowlist decision
  itself stands whole and operative. Not superseded.
- **ADR-017** — Decision 6's `Content-Type` half and one § Alternatives bullet under a **proposed
  partial supersession** by ADR-024, annotated inline as `PROPOSED … (pending Owner approval)` per the
  plan-05/plan-06 precedent. Decisions 1–5, the routes, the gates, the 410/404 mapping and
  fetch-on-reveal are untouched.
- **ADR-010 (+ Amendment B)** — the binding `APP_PREVIOUS_KEYS` rule is **widened by AC10, which the
  PRD already ratifies**; the rule itself is unchanged and ADR-021 § Impact enumerates the new column
  list. **No amendment, no superseding ADR** — a wider surface for an unchanged rule is not a changed
  rule.
- **ADR-014** — the three payload columns, the cleaned signal and Decision 7's guard are untouched;
  #10 adds no fourth payload column and changes no erasure behaviour. Its `json`-cannot-carry-an-
  `encrypted`-cast lesson is what makes `sensitive_fields` a `longText`. **No amendment.**
- **ADR-020** — Decisions 7 and 9 are **depended on, not changed**: by-reference delivery is what
  makes AC35 and AC61 structural, and the scalar-arguments hazard now carries a secret consequence as
  well as a payload one. ADR-021 Decision 8 and ADR-023 Decision 7 record the dependency so a future
  change to the job's arguments re-checks both. **No amendment.**
- **ADR-018** — its two-evaluation-points rule is **applied**, not extended:
  `proxies.verification_scheme` is per-proxy configuration consulted outside the pipeline, so its gate
  lives in its single resolver, which is Decision 1's second row exactly. No third mechanism, no
  per-capability toggle, no inference. **No amendment.**
- **ADR-006** — the ingest URL and its token are untouched; the verification secret is a second,
  independent factor. **No amendment.**
- **ADR-003** — attempt records stay payload-free and now also secret-free. **No amendment.**
- **ADR-012/ADR-015/ADR-016/ADR-019** — retention, retry, FIFO composition and the parked mapping ADR
  are untouched. ADR-019 remains **Proposed** and parked with #8; PRD-10 AC48 and #8's carried-forward
  note already record that `proxy_maps.output` and `proxy_map_conditions.value` inherit AC1 and
  AC12–AC21 **when #8 resumes** — this plan asserts nothing about tables that do not exist.
- **ADR-009** — the permission model is **reused** and not extended: no new `TeamPermission` case, no
  new policy method, and correction C2's `canUpdate` is the computed `Show.vue` already uses.
  **No amendment.**

### Certification (Principal Engineer, 2026-08-27)

I have verified that **PRD-10 is Owner-approved** (2026-08-27, 64 criteria, `## Amendment A` ratified
whole) and that **design-10 is Product-Manager-approved at the delegated design gate** (2026-08-27) —
the mandatory gate for the PRD's UX Direction. I have written this plan against the design spec's
**approval record** first and its body second: flagged call 4 is read as overturned, and corrections
C2, C3, C6, C8 and C9 are each carried with their technical consequence stated rather than assumed. I
have pulled the three items the design gate carried forward to me rather than leaving them open — the
transport (Technical ruling 1 / ADR-024), the which-list-matched data point (Technical ruling 2 /
ADR-024, with the default-beats-addition tie-break named because the alternative promises a remedy
that does not work), and the `canUpdate` reuse (§ *Architecture F*, which keeps AC28's "no new
permission" true by keeping it a reuse). I have read ADR-003 through ADR-020, the relevant prior plans
and reviews, and the affected code on `main` at `6bfb782`, and every claim about that code in this
plan and in the four ADRs — including the `QueryException` binding interpolation, `Arr::forget()`'s
lack of wildcard support, `Delivery::proxy()`'s soft-delete hazard, `ProxyResource` serving three
surfaces, `Create.vue` rendering no resource, and the absence of any `Cache::` call in `app/` — is a
reading of it or of vendor source, not an inference.

**Two Project Owner rulings arrived after both upstream gates closed, and I have carried them rather
than designed around them.** Ruling A is presented as a choice at flag 2, with the alternative
enumerated, because presenting an Owner's direction as already-approved would defeat the gate. Ruling
B contradicts PRD-10 AC54/AC63 and `design-10` Screens 5–6 and Flows G–I; I have built the backend to
the ruling, **stopped at the surface**, and routed the contradiction to the Product Manager at
`Q-10-04` with an exhaustive list of what goes stale in both documents. **I have not edited PRD-10 or
`design-10`, and I have not made the requirement change myself.**

Every section above traces to PRD-10 acceptance criteria, to the approved design, or to a named Owner
ruling. The fourteen technical rulings stay inside the upstream artifacts' assumptions; none
reinterprets a requirement, a design decision or an Owner ruling, and where one has a user-visible
consequence — the re-serialised JSON view, the dormant verification secret surviving a switch to Not
required, the absence of a payload-size ceiling, the withheld signing surface — the consequence is
stated for the Designer and the Reviewer rather than left to be inferred. Nothing here changes a
requirement or reopens PRD-05's retention lifecycle, PRD-06's retry/replay semantics, PRD-07's mode
model, or #11's figures.

**I self-certify this plan under the delegated plan gate in `CLAUDE.md` — except for the four items
above, which I do not and cannot certify.** The carve-out is stated plainly: **#10 changes the data
model, and a data-model change is a Project Owner gate that no delegated gate covers; and it takes
three security decisions beyond what PRD-10 already ratified — how secrets are stored, where the
inbound authentication seam sits, and the response shape of the one endpoint that carries payload
content out of the system.** The Owner must rule on (1) the change set exactly as enumerated in
§ *Data Model*, (2) **ADR-021** including the choice between its two storage models, (3) **ADR-022**,
and (4) **ADR-024**. Everything else — the outbound contract (ADR-023, whose substance the Owner
ratified on 2026-08-27 and whose recording the Owner left to me), the API surface, the services, the
validation, the risk mitigations, the milestone shape and the test strategy — is self-certified and
needs no further sign-off.

**Following `plan-08`'s standard deliberately:** these gates are **not** presented as pre-approved by
anything upstream, including by the Owner's own mid-flight direction, and they must not be treated as
settled. A schema carrying three kinds of secret must be approved against the codebase it will
actually be built on, and this plan is written against `main` at `6bfb782` — if the Owner rules later,
the change set is re-presented rather than assumed.

- **Next Agent:** **Task Planner — after Owner approval of items 1–4.** Five standing constraints to
  carry into the breakdown. **M2 and M3 are the correct first tasks while the gates are open** — both
  are pure, dependency-free, and between them carry the feature's two hardest pieces of logic. **The
  AC37/AC63 byte-identical regression must be its own named task**, because it is the only automated
  guard that an untouched destination still receives what it received before, and a partial landing is
  a shipped defect. **M8b must not be broken down until `Q-10-04` is answered and `design-10` is
  revised** — it is the one milestone whose blocker is not an Owner gate. **`Q-10-03` blocks nothing
  and must not be turned into a task**; if the Designer answers before M7 lands, the answer is
  additive. **design-10's Flows A–F are manual-verification steps** requiring `pnpm run build` with
  `public/hot` removed first, since no frontend test harness exists (backlog T31) and review-07
  Finding 8 is the standing trap; **Flows G–I are excluded** until M8b exists.
