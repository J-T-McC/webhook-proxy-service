# PRD: Payload storage & retention

- **Status:** **Approved — amended** (Amendment A, Project Owner ruling 2026-08-05).
  Not reopened, not downgraded; the original approval stands and is unchanged.
- **Author:** Product Manager
- **Date:** 2026-08-05 · **Amended:** 2026-08-05 (Amendment A)
- **Approved by / date:** **Project Owner, 2026-08-05** — original approval, intact.
- **Amendment A / date:** **Project Owner ruling, 2026-08-05** — retention **erases
  payload content in place** instead of deleting the captured event record. The
  ruling is the Owner's; the acceptance-criteria wording below is the Product
  Manager's rendering of it as the Owner's proxy and is open to Owner correction.
  See **§ Amendment A** for the ruling, its scope, and what it invalidates
  downstream. Affects AC5–AC12, AC15; adds AC21, AC22; adds deferred concern D1.
- **Backlog item:** Roadmap #5 (`docs/product/roadmap.md`)

## Feature
Every payload the service stores has a bounded life: a **team-level 30-day
retention window** measured from capture, and a **garbage collector** that
permanently **erases the payload content** once that window elapses — while an
**enhanced-mode** proxy additionally stores the payload it **dispatched**,
separately from and without altering the raw input it received. *(Amendment A:
erasure happens **in place** — the payload content is destroyed, the captured
event record is retained and carries an explicit cleaned state, AC21.)*

## Problem
Three gaps sit on top of #1/#3/#4:

1. **Storage grows unbounded.** #3 pulled the *capture* half of #5 forward
   (ADR-010): every incoming payload is now durably persisted in both modes, and
   **nothing ever removes it**. PRD-03 AC11 records this as an accepted interim
   trade-off explicitly deferred to #5 (Q-03-02). Payload content is the most
   sensitive data we hold; keeping it forever is both a cost and a
   confidentiality problem. *(Amendment A scopes the fix precisely: #5 bounds
   **payload content**; growth in retained event records is deferred concern D1.)*
2. **No defined retrievability guarantee.** #6 manual replay re-dispatches a
   stored payload, and #7 sells storage as an enhanced-mode benefit. Neither can
   promise anything without a stated window over which a stored payload is
   guaranteed to still exist — nor a stated point after which it is guaranteed
   gone.
3. **Input and output are not separable.** Roadmap #5 requires the raw input to
   be captured and never mutated **and** the dispatched output to be saved
   separately. Today only the raw side exists (ADR-010 is raw-only by
   construction); there is no home for what was actually sent, which #8 mapping
   makes materially different from the input.

Item #5 closes all three without changing the ingest, response, or dispatch
**behaviour** #1/#3/#4 already established. *(Amendment A qualifier: AC22 changes how
captured headers are **protected at rest**, which may touch the capture path's
storage of them; it changes nothing an upstream sender or a destination observes, and
#3's capture-before-response guarantee is untouched. Feasibility is Q-05-04.)*

## What #3 already delivered vs. what #5 adds
Recorded so scope is unambiguous — #3's Owner scope change moved the boundary.

| Concern | Owner | State |
|---|---|---|
| Durable raw capture, before the upstream response, **both modes** | #3 (ADR-010, R2 capture-dimension override) | Done |
| Raw body encrypted at rest; headers plaintext | #3 (ADR-010 Amendment B) | Done — **superseded for headers by Amendment A**: header at-rest encryption + expiry clearing move to **#5** (AC22). The rest of #10 is untouched |
| Raw/dispatched separation as a principle | #3 AC8 (R2 build-ahead) | Principle set; output store does not exist |
| **Retention window + garbage collection** | **#5** | **This PRD** |
| **Dispatched-output storage (enhanced mode)** | **#5** | **This PRD** |
| Manual replay of a stored payload | #6 | Not here |
| Enhanced-mode toggle UI | #7 | Not here |
| Field obfuscation, header/sensitive-data policy, verification tokens | #10 | Not here |

## Goals
*(Amendment A aligns the verbs below: "removed/deleted" means the payload **content**
is erased, in place, not that the captured event record is deleted.)*

- Every stored payload's content is erased automatically once its retention window
  elapses; **payload** storage no longer grows unbounded (closes the PRD-03 AC11
  interim gap). Growth in retained **event records** is explicitly out of scope —
  see deferred concern **D1**.
- The retention window is **30 days from capture**, applied **per team**, in both
  simple and enhanced mode (Owner decision Q-03-02, 2026-08-03).
- Within its window a stored raw payload is **guaranteed retrievable**, so #6
  replay has a defined guarantee to build on; after the window it is guaranteed
  **gone**, everywhere.
- An enhanced-mode proxy stores the **dispatched output** separately from the raw
  input; the raw input is never altered while retained (roadmap #5 line; R2's
  non-overridden half).
- Payload expiry never costs us delivery history: payload-free delivery-attempt
  records (ADR-003) and anything #11 derives from them survive independently and
  indefinitely.
- Erasing expired payloads never loses an event that is still being processed
  under #4's queued/FIFO dispatch (ADR-011).
- The at-rest protection floor set at #3 is preserved by anything #5 stores, and is
  **raised to cover captured headers** (AC22, Amendment A); #10 still owns the full
  sensitive-data **policy**.

## Users
- **Team member** — owns proxies whose payloads are stored; gains a stated,
  bounded retention guarantee (and, in enhanced mode, a stored record of what was
  actually dispatched).
- **Team** — retention is a team-level property; the window applies to all of a
  team's stored payloads.
- **The product (system)** — runs the garbage collector; keeps analytics and
  delivery history decoupled from expiring payloads.
- **Upstream sender** — unaffected. Ingest, capture, and the #3 response contract
  are unchanged by this item.

## User Stories
- As a team member, I want every payload my proxy received to stay retrievable for
  30 days, so recent events can be inspected and replayed (#6) while they still
  matter.
- As a team member, I want payload content to be erased automatically once that
  window passes, so we do not hold sensitive payload content indefinitely and
  payload storage does not grow without bound.
- As a team member, I want an event whose payload has been cleaned to *say so*, so
  a missing payload reads as "expired on schedule" rather than "never captured" or
  "lost" (AC21).
- As a team member running an enhanced-mode proxy, I want what was **dispatched**
  stored separately from what was **received**, so that once mapping (#8) exists I
  can tell input from output when debugging.
- As a team member, I want my delivery success/failure history to outlive the
  payloads themselves, so stats stay long-lived and trendable (#11) even after the
  payloads expire.
- As the product (system), I want the garbage collector to never remove a payload
  for an event still awaiting or undergoing dispatch, so expiry can never turn into
  a lost event under #4's queued/FIFO processing.

## Acceptance Criteria

> **Numbering is append-only.** Amendment A edits criteria **in place** and appends
> two new ones — **AC21** and **AC22** — carrying their labels explicitly, sitting in
> their thematic group, so existing cross-references from ADR-012, ADR-013, plan-05
> and Q-05-03 (which cite AC1–AC20) all stay valid. Nothing is renumbered. Criteria
> amended by Amendment A are tagged **(A)**.

**Retention**

1. **Every stored payload has an expiry.** Each stored payload record is subject to
   a retention window measured from **when the raw payload was captured** (#3 /
   ADR-010 capture time), not from dispatch, delivery, or last access.
2. **The window is 30 days.** The retention window is **30 days** (Owner
   Q-03-02, 2026-08-03; vision "Retention is team-level, starting at 30 days").
3. **Retention is team-level.** The window is expressed as a property of the
   **team** that owns the proxy, and is currently the same 30 days for every team.
   #5 introduces no per-proxy, per-plan, or per-user retention value and no
   user-facing control that changes it (see V5 below).
4. **Retention applies in both proxy modes.** Raw payloads captured for
   **simple-mode** proxies and **enhanced-mode** proxies are subject to the same
   window. Retention/GC is **not** gated on enhanced mode (Q-03-02; #3 AC7 made
   capture mode-independent).

**Garbage collection**

5. **(A) Expired payload content is erased automatically.** A garbage collector
   **erases the payload content** of every event whose retention window has elapsed,
   with **no user action** and no per-team opt-in. It runs recurrently, not once.
   *(Amendment A: "erases" replaces "removes payloads" — the content is destroyed,
   the captured event record is retained per AC11.)* Scheduling and mechanism are the
   Principal Engineer's — see Q-05-03.
6. **(A) Erasure is complete — payload content, not the record.** After the expiry
   pass, none of that event's **payload content** is retrievable through **any**
   user-facing or system path, including a #6 replay. Payload content means, at
   minimum: the raw body; the captured inbound **headers** (AC22); and any stored
   dispatched output body for the same event (AC12). No partial, truncated,
   prefixed, previewed, summarised, hashed, or otherwise reduced copy of payload
   content is retained anywhere as a side effect of the pass, and nothing retained
   may permit reconstruction of any part of it. **Retaining the captured event
   record itself satisfies this criterion** provided it holds only non-content
   **descriptors** of the transaction — e.g. HTTP method, content type, byte size,
   capture/received time, identifiers and correlators, ownership — which describe
   that an event occurred and its shape, never what it contained. *(Amendment A
   ruling: "removed automatically and completely" is a guarantee about **payload
   content**, not about the record. It is the same class of survivor AC9 already
   requires — payload-free records about an event outlive the payload by design.
   The single field that sits on the line, **content type**, is retained as a
   format descriptor even though the captured header **collection** is erased: it
   names the encoding, carries no credential, and the Owner named it explicitly as
   retained metadata in the 2026-08-05 ruling.)*
7. **(A) Unexpired payload content is never erased.** A payload inside its window is
   never erased, cleared, or altered by GC. Within the window, retrieval of a stored
   raw payload for a proxy's event is guaranteed — this is the guarantee #6 replay
   builds on.
8. **(A) In-flight events are not eligible for erasure.** The payload for an event
   whose dispatch has not completed — including one queued, pending, or claimed under
   #4's per-proxy FIFO ordering, or in flight under Async — is **not** erased while
   that dispatch is outstanding, even if its window has elapsed. Rationale: queued
   dispatch rebuilds its input from the stored raw event (ADR-011), so erasing it
   mid-flight would lose the event. (Bounding a permanently stuck event is #6's
   dead-letter concern, not asserted here.)
9. **(A) Delivery history survives expiry.** Erasing an expired payload never deletes
   or alters the payload-free delivery-attempt records for that event (ADR-003).
   Success/failure history and anything #11 aggregates from it remain intact and
   are **not** subject to payload retention.
10. **(A) Expiry is a normal state, not an error.** Where an expired payload is
    referenced, the system reports it as expired / no longer available and the
    event's surviving records stay readable; it does not error, 500, or present as
    data corruption. The cleaned-state signal of **AC21** is what makes this state
    representable. (What a #6 replay does when the payload is expired is #6's to
    specify; this criterion only requires the expired state be represented, not
    fail.)

- **AC21 (added — Amendment A). A cleaned payload is an explicitly signalled state.**
  For any event the system can distinguish three states without ambiguity:
  **(a) retained** — payload content is present and retrievable within its window;
  **(b) cleaned** — the event *was* captured and its payload content has since been
  erased on expiry; **(c) never captured** — no payload content was ever held for
  that reference. The **cleaned** state is signalled explicitly by the event's own
  record; it is **not** inferred from the absence of a record, a failed lookup, or an
  empty value that is indistinguishable from "nothing was captured". #6 replay and
  any later read path (AC16) build on this distinction. *Requirement only — how the
  state is represented, stored, or named is the Principal Engineer's.*

**Storage shape**

11. **(A) Captured payload content is immutable while retained; erasing it on expiry
    is permitted.** Two halves, and the line between them is exact:
    - **Capture fidelity is absolute for as long as the content is retained.** While
      an event's payload is within its retention window, nothing in #5 may alter it —
      not storing the dispatched output, not recording retention or dispatch state,
      not the expiry pass, not any read path. What was captured is byte-for-byte what
      is served. No rewrite, redaction, normalisation, truncation, re-encoding, or
      partial clearing of retained payload content is permitted at #5 (field-level
      obfuscation is #10's and is **not** authorised here).
    - **Destruction on expiry is not alteration, and is permitted.** Once the window
      has elapsed, the retention system **may erase the payload content in place** —
      destroying the content while the captured event record is retained and marked
      cleaned (AC21). This does **not** violate the immutability constraint.
    *(Amendment A, Owner ruling 2026-08-05, recorded as reasoned: ADR-010's
    immutability constraint exists to prevent **alteration** of captured payload
    data, not to prevent its **cleanup**. Deleting the record and erasing the payload
    content reach the same security outcome — the payload no longer exists past the
    point it is needed — and treating the delete as compliant while treating the
    erasure as a violation is incoherent, since the delete is the larger mutation.
    Erasure also targets payload content **specifically**, which is the right
    granularity now that payload content lives in two stores — the raw store and the
    dispatched-output store. This reading supersedes ADR-010's "never mutate a
    captured row" constraint as applied to expiry only; see § Amendment A.)*
    Permitted lifecycle for a captured payload is therefore exactly:
    **captured-and-unaltered → erased**. There is no third state and no intermediate
    edit. (ADR-010; PRD-03 AC8 — whose "capture never mutates the raw input" is about
    the capture and dispatch paths and is unaffected; R2 build-ahead.)
12. **(A) Dispatched output stored in enhanced mode, and cleaned by the same pass.**
    When a proxy is in **enhanced mode**, the payload actually dispatched to
    destinations for a received event is stored **separately from** the raw input and
    is associated with the **same received event**, so input and output are
    independently identifiable. In **simple mode** no dispatched-output record is
    created. **The dispatched output's payload content is subject to the same
    retention window as the raw payload for that event and is erased by the *same
    expiry pass*, with no window in which one survives the other.** A dispatched
    output may never outlive its received event's payload. *(Amendment A: previously
    this was a consequence of removing the raw record; now that the record is
    retained, clearing the dispatched output is an explicit requirement of the expiry
    pass in its own right. The cleaned state of AC21 covers the event as a whole,
    including its output.)*
13. **One dispatched output per received event.** Per resolved decision R3, all of
    a proxy's destinations receive the same payload structure for a given event, so
    the stored dispatched output is per received event — #5 introduces no
    per-destination payload variance.
14. **Storage is not separately toggleable.** "Storage enabled" means the proxy is
    in **enhanced mode** (vision: enhanced mode "adds payload mapping, payload
    storage, and retry strategy"; roadmap #7). #5 adds **no** separate per-proxy
    storage on/off switch and **no** UI for changing the mode — the toggle is #7.
    Unconditional raw capture (#3 AC7) is unchanged by mode.

**Protection & access**

15. **(A) At-rest protection floor preserved and raised.** Any payload content #5
    stores carries **at least** the at-rest protection established for the raw body
    at #3 (encrypted at rest — ADR-010 Amendment B). #5 does not create a
    less-protected copy of payload content, does not decrypt payload content into a
    new store, and its expiry pass creates no cleartext intermediate. **Inbound
    headers no longer remain plaintext at rest:** the previous sentence deferring
    header encryption to #10 is **superseded by AC22** (Amendment A). The floor now
    covers captured bodies **and** captured headers; #10 still owns all header and
    sensitive-data *policy*.
16. **Access is team-scoped and permission-gated.** Any read path that exposes
    stored payload content is restricted to members of the owning team and gated by
    the existing proxy **read** permission (PRD-02 / ADR-009). #5 adds no sharing,
    export, download, or cross-team access path.

- **AC22 (added — Amendment A). Captured headers are encrypted at rest and cleared on
  expiry.** Inbound headers captured alongside a raw payload (#3 / ADR-010) must be:
  **(a) encrypted at rest**, to at least the same protection floor the raw body
  already carries (AC15); and **(b) erased by the same expiry pass** that erases that
  event's payload content (AC5, AC6, AC11, AC12) — captured headers do not outlive
  their event's retention window.
  *Rationale (Owner ruling, 2026-08-05):* plaintext-at-rest headers are the exposure
  PRD-03 / ADR-010 Amendment B knowingly deferred to #10; this ruling both **reduces
  it now** (encrypted while retained) and **clears it on expiry** (erased with the
  payload), the latter available at no extra cost because headers are already in the
  cleanup.
  **Scope — this is the only slice of #10 that moves.** #10 keeps its full scope
  unchanged: field-level obfuscation/redaction, sensitive-**header policy** (which
  headers are sensitive, what is redacted, what is displayed), verification-token
  standards (V2), per-team/per-plan key policy, key rotation and re-encryption
  tooling. #5 adds **no** header policy, no redaction, no classification of which
  headers matter, and no header read or export surface — only at-rest protection and
  expiry. Whether this is achievable without disturbing #3's capture path or ADR-008
  header forwarding is the Principal Engineer's to determine (Q-05-04).

**Scope boundaries**

17. **No retry or replay.** #5 guarantees a payload is retrievable within its
    window; it does **not** introduce manual replay, retry, or backoff — roadmap #6.
18. **No mode toggle.** #5 gates dispatched-output storage on the existing mode
    attribute (ADR-002); surfacing the simple/enhanced toggle is roadmap #7.
19. **No mapping.** Until #8, the dispatched output is expected to be identical in
    content to the raw input; #5 introduces no transformation of any kind. Storing
    it separately is what makes #8's reshaping observable without re-modelling. The
    Owner **confirmed the output store lands at #5 on 2026-08-05**, accepting the
    interim duplication of the raw payload until #8 exists (Q-05-02(b), RESOLVED;
    `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`).
20. **No numeric storage, throughput, or performance target.** #5 asserts **no**
    storage quota, per-team volume cap, GC-latency target, or throughput number.
    The only size constraint remains the existing ingest body cap already in force
    (V8 stays deferred — Owner, 2026-08-04).

## Roadmap Open Questions V4, V5, V6 — settled here
All three roadmap Open Questions gated to #5 are **settled as scope decisions in
this PRD** (none is escalated). Each is settled by deferral that the roadmap and
vision already label as future/post-MVP, plus a stated requirement that keeps the
extension point open per #5's build-ahead note. Owner approval of this PRD ratifies
all three.

- **V4 — SETTLED (deferred; out of scope for #5).** Roadmap wording, verbatim:
  > "V4. **Capture-even-if-API-offline architecture** — likely post-MVP; may reshape
  > #5. *(Vision Open Question 4.)*"

  **Settlement:** #5 asserts **no** capture-while-our-API-is-offline guarantee and
  changes nothing about the capture path, which stays exactly as #3/ADR-010 defined
  it (synchronous, in-request, before the upstream response). Basis: the roadmap
  itself says "likely post-MVP", and the vision lists it as "Desired but likely
  post-MVP" under success signals — a deferral already stated by the Owner-approved
  documents, so no new business decision is required. **Requirement kept open:**
  retention and GC in AC1–AC9 key off the **captured event** and the **owning team**,
  not off the ingest request that produced it, so a future offline-capture path that
  still yields captured events inherits retention without re-modelling. Whether such
  a path is feasible or how it would work is a Principal Engineer concern if and
  when the Owner schedules it.

- **V5 — SETTLED (deferred; extension point required, lever not built).** Roadmap
  wording, verbatim:
  > "V5. **Retention as a subscription-tier lever** — future; may extend #5.
  > *(Vision Open Question 5.)*"

  **Settlement:** #5 builds **no** subscription tiers, plan types, or per-plan
  retention values, and **no** user-facing control over the window. Every team gets
  30 days (AC2, AC3). Basis: the vision says retention is "team-level, starting at
  30 days ... and **possibly** tied to **future** subscription tiers"; the roadmap
  labels V5 "future"; the Owner's Q-03-02 answer (2026-08-03) states the 30-day
  default is "later made adjustable by plan type" — later, and by plan, not by user
  today. Billing/payment is explicitly out of scope in the vision, so a tier lever
  has nothing to attach to yet. **Requirement kept open:** because AC3 makes the
  window a **team-level** property rather than a hard-coded constant applied at the
  payload, a later tier lever changes only the **value's source**, not the storage
  or GC model — exactly the extension point #5's build-ahead note asks for. The
  Owner **confirmed this default on 2026-08-05** — fixed 30 days, no user-facing
  control at #5 (Q-05-02(a), RESOLVED;
  `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`).

- **V6 — SETTLED (deferred; product dimension out of scope, technical dimension not
  reopened).** Roadmap wording, verbatim:
  > "V6. **Storage regions and possible Postgres for ingestion** — future; touches
  > #5. *(Vision Open Question 6.)*"

  **Settlement:** two dimensions, settled separately.
  - *Storage regions (product):* #5 offers **no** region/locality selection, makes
    **no** data-residency or jurisdiction guarantee, and adds no per-team storage
    location. Basis: roadmap labels it "future"; the vision records "No additional
    compliance requirements today" under Known Constraints, so there is no stated
    requirement to place data anywhere in particular. **Requirement kept open:** as
    with V5, retention/GC hangs off the team-level property (AC3), so a future
    region dimension attaches to the same per-team property rather than re-modelling
    storage.
  - *Postgres for ingestion (technical):* **not a Product Manager decision and not
    reopened here.** The datastore is the stack's existing engine (vision Known
    Constraints: "MySQL (fine for the MVP)"; `docs/stack/stack.md`). #5 asserts no
    datastore change; if the Principal Engineer judges that #5's retention/GC volume
    warrants revisiting the engine, that is an ADR, and any new dependency or
    data-store change carries the Owner approval gate in `CLAUDE.md`.

## Deferred concerns (Amendment A)
Recorded in the same manner as V4/V5 above — a **deferred concern, not a
requirement**. Nothing below asserts anything #5 must build.

- **D1 — Growth in retained event records — DEFERRED (explicitly accepted; out of
  scope for #5).** Because retention now erases payload content **in place** rather
  than deleting the captured event record (AC11), event records accumulate for the
  life of the account: one per received event, retained after its payload is cleaned,
  holding non-content descriptors and the AC21 cleaned-state signal.

  **Settlement:** explicitly accepted by the **Project Owner, 2026-08-05**, as out of
  scope for #5. Basis, as ruled: record growth is a **scalability concern that
  materialises with user volume, not an MVP concern**. #5's mandate is bounding
  **payload content** — the sensitive, bulky part — which erasure-in-place achieves in
  full; a cleaned record carries none of the confidentiality weight that motivated
  retention, and none of the volume.

  **Not a requirement:** #5 asserts **no** cap, quota, archival, roll-up,
  partitioning, or pruning of retained event records, and **no** numeric growth,
  volume, or storage target for them (consistent with AC20). No acceptance criterion
  depends on record count.

  **What is kept open:** nothing in #5 requires event records to be kept *forever* —
  it requires only that a cleaned payload stay distinguishable from a never-captured
  one (AC21). A future record-lifecycle policy therefore attaches to the same
  team-level property retention already hangs off (AC3), the same seam V5 and V6 use,
  without re-modelling storage or GC. Whether and when record volume warrants action
  is a judgement for a future item — and if the Principal Engineer judges it a #5-time
  risk rather than a future one, that is an Open Question to raise, not a requirement
  to infer.

## Out of Scope
Each points to the item that owns it.

- **Manual replay, retry, configurable backoff, dead-letter** — roadmap #6. #5 only
  guarantees the payload is there to replay while unexpired.
- **Enhanced-mode toggle UI / making enhanced mode reachable in the UI** — roadmap
  #7. #5 uses the existing mode attribute (ADR-002) as a gate only.
- **Payload mapping / reshaping** — roadmap #8. #5 stores the dispatched output; it
  never transforms it.
- **Encryption key policy, rotation/re-encryption tooling, field-level obfuscation,
  sensitive-header *policy*, verification-token standards (V2)** — roadmap #10. #5
  preserves the #3 floor (AC15) and adds no policy of its own. The APP_PREVIOUS_KEYS
  operational guard (ADR-010 Amendment B) remains binding and is unchanged.
  **Amendment A moves exactly one slice of #10 forward:** header **at-rest
  encryption** and **clearing headers on expiry** (AC22). Everything else in #10 —
  including deciding *which* headers are sensitive and what is redacted or displayed
  — stays at #10, untouched and not descoped.
- **Any cap, archival, roll-up, or pruning of retained event records; any numeric
  record-count or record-storage target** — deferred concern **D1** (Owner,
  2026-08-05). #5 bounds payload content, not record count.
- **Analytics / stats surfaces over stored payloads** — roadmap #11. AC9 guarantees
  stats never depend on expiring payloads; #5 adds no analytics surface.
- **Notifications about retention, expiry, or storage volume** — roadmap #13.
- **Test payloads** — roadmap #14 (a test payload would flow through the same
  storage path, but #14 is not built here).
- **Subscription tiers / billing / plan types** — V5 and vision "Explicitly Out of
  Scope"; see V5 above.
- **Storage regions, data residency, datastore change** — V6; see V6 above.
- **Capture while our own API is offline** — V4; see V4 above.
- **Storage quotas, volume caps, numeric GC/performance targets** — none asserted
  (AC20); V8 deferred by the Owner (2026-08-04).
- **A user-facing payload inspection/browse surface** — **resolved: out of scope for
  #5** (Owner decision Q-05-01, Option B, 2026-08-05;
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md`). #5 adds **no UI**.
  The viewing surface lands with **#6**, after or alongside #10's obfuscation —
  #10 depends on #5, so a viewer at #5 would render unobfuscated payload content.
  AC16 remains the standing constraint on any read path whenever one is introduced.

## Amendment A — retention erases payload content in place (Project Owner ruling, 2026-08-05)
Amends the Approved PRD; does **not** reopen it. The PRD stays **Approved**. Recorded
per `docs/standards/documentation.md` (amend in place, retain history, never rewrite
ratified content silently).

### The ruling
Retention cleanup **no longer hard-deletes the captured event record**. The expiry
pass **nulls the payload content in place** and **keeps the record**, which carries an
explicit signal that its payload has been cleaned.

### The Owner's reasoning, as given
1. **ADR-010's immutability constraint exists to prevent *alteration* of captured
   payload data, not to prevent its *cleanup*.** Deleting the record and nulling the
   payload content reach the same security outcome — the payload no longer exists past
   the point it is needed. Treating the delete as compliant while treating the null as
   a violation is incoherent, since **the delete is the larger mutation**.
2. **Nulling targets the payload specifically**, which is the right granularity now
   that payload content lives in **two places** — the raw store and the ADR-013
   dispatched-output store.
3. **The migration is trivial: there is no production data to protect.**
4. **Headers get encryption**, and headers are included in the cleanup — so the
   plaintext-header exposure currently deferred to #10 is **both reduced now and
   cleared on expiry**.
5. **An explicit state/status signalling that an event's payload has been cleaned**
   replaces deriving "expired" from the absence of a record.
6. **Growth from retaining records indefinitely is explicitly accepted as out of
   scope** — a scalability concern that materialises with user volume, not an MVP
   concern; recorded as a deferred concern (**D1**), not a requirement.

### What changed in this PRD
| Item | Change |
|---|---|
| AC11 | Rewritten. Immutability now binds **while content is retained**; erasure on expiry is explicitly permitted. Lifecycle: captured-and-unaltered → erased, nothing between. |
| AC6 | **Ruling + rewrite.** A retained record holding only non-content **descriptors** satisfies "removed completely"; the guarantee is about payload **content**, not the record. Content now explicitly includes captured headers. Reconstruction and any reduced copy forbidden. Content-type explicitly ruled retained. |
| AC12 | Clearing the dispatched output is now an explicit requirement of the **same expiry pass**, not a by-product of deleting the raw record. |
| AC15 | Header-plaintext deferral to #10 **superseded**; floor now covers captured headers. |
| AC5, AC7, AC8, AC9, AC10 | Verb alignment: "remove/delete a payload" → "erase payload content". AC10 additionally cross-references AC21. No change of substance. |
| AC21 (new) | Cleaned state must be **explicitly signalled** and distinguishable from *retained* and from *never captured*. Mechanism not specified. |
| AC22 (new) | Captured headers **encrypted at rest** and **cleared by the same expiry pass**. Explicitly the only slice of #10 that moves. |
| D1 (new) | Record growth from retained records — deferred, accepted, not a requirement. |
| AC13, AC14, AC16–AC20 | **Unchanged.** AC13 (one output per received event, no per-destination variance) is unaffected by erasure-vs-delete. |

### Consequences downstream — Principal Engineer's to action, not edited here
Recorded so nothing is papered over. Each artifact below rests on a premise this
ruling changes; none is touched by this PRD.

- **ADR-010 (Accepted, Owner 2026-08-04)** — its Impact constraint "never … mutate a
  captured row here" and Amendment B's "**inbound headers remain plaintext at rest
  until #10**, the Owner accepts this explicitly" are both **overridden by this
  ruling**. An Owner-level decision supersedes an Owner-accepted ADR, but the ADR must
  be amended or superseded on the record (documentation.md: reversing an Accepted
  decision is a new/amending ADR, never a silent rewrite). PE's call which.
- **ADR-012 (Proposed)** — built on "expiry is derived, never stored", "no retention
  column, row, flag or tombstone", "removal is a **hard delete**", and "*expired* is
  derived from the **absence** of a record". AC21 and AC11 now require the opposite of
  the last two. Needs revision before Owner sign-off.
- **ADR-013 (Proposed)** — its AC6 guarantee is structural via `cascadeOnDelete` from
  the raw record. With the record retained, AC12 now requires the output to be cleared
  by the expiry pass directly. Its rejection of storing dispatched headers also cites
  AC15's "plaintext surface not widened", a premise AC22 changes — though the
  per-destination-variance argument for that rejection (AC13/R3) stands independently
  and is **not** disturbed by this ruling.
- **plan-05 / Q-05-03** — Q-05-03 is RESOLVED; its answer (ii) ("there is no
  bookkeeping … GC's only write is the DELETE") and (iii)'s header note are overtaken.
  The PE decides whether to amend the resolved answer or record the change in the
  revised ADRs.
- **Owner approval gates** — this ruling likely **adds** to the CLAUDE.md gate list at
  plan time (schema change to an existing table; a change to at-rest encryption
  coverage). Assessing and presenting that is the PE's, per Q-05-03(iv).

### What this ruling does **not** change
V4, V5, V6, Q-05-01 (no inspection surface at #5), Q-05-02 (30 days fixed; output
store at #5) — all untouched and not reopened. No UX Direction is added; #5 still adds
**no UI** and still routes to the Principal Engineer with no Designer gate. The 30-day
window, team-level scoping, both-modes coverage, in-flight holds, and the survival of
delivery history are all unchanged.

## Open Questions
Question IDs Q-05-0x. **Q-05-01 and Q-05-02 are both RESOLVED by the Project Owner
(2026-08-05)** and no longer stand against this PRD; they are recorded below with
their outcomes and remain readable in `docs/questions/`. **Q-05-03 is the only open
question** — technical, for the Principal Engineer, and it gates technical design
only, not requirement approval.

- **Q-05-01 (Owner) — Does #5 include a user-facing payload-inspection surface?
  RESOLVED 2026-08-05 — Option B; no longer blocking.** Doc:
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md`. **#5 ships no
  user-facing payload-inspection surface**: storage, retention, GC and the
  retrievability guarantee only. Owner rationale as recommended — **#10 field
  obfuscation depends on #5 and therefore lands after it**, so a viewer at #5 would
  render unobfuscated payload content; and **#6 needs a stored-payload selection
  surface regardless**. The viewing surface lands with #6, after or alongside #10's
  obfuscation. **Consequences carried into this PRD:** no UX Direction section, no
  Designer gate, routes to the **Principal Engineer** (see Handoff); AC16 remains
  the standing constraint on any future read path.
- **Q-05-02 (Owner) — Two scope confirmations. RESOLVED 2026-08-05 — both defaults
  confirmed as drafted.** Doc:
  `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`.
  **(a)** Retention stays a **fixed 30 days for every team with no user-facing
  control** at #5 (AC2, AC3); V5 stays deferred.
  **(b)** The **dispatched-output store lands at #5** as the approved roadmap line
  states (AC12, AC13), with the trade-off **acknowledged and accepted** by the
  Owner: until #8 mapping exists the output duplicates the raw input, roughly
  doubling stored payload volume for no immediate user-visible difference; the
  30-day GC bounds it and AC15's at-rest floor covers the duplicate copy.
  No acceptance criterion changed as a result.
- **Q-05-03 (Principal Engineer, technical) — RESOLVED 2026-08-05** in
  `docs/questions/prd-05-q-05-03-gc-composition-and-output-store.md`, ADR-012,
  ADR-013 and plan-05. **Amendment A overtakes parts of that answer** — see §
  Amendment A → *Consequences downstream*; specifically its (ii) (no bookkeeping /
  derived expiry / delete-only write) and its (iii) header note. Re-answering is the
  Principal Engineer's, and still gates technical design only, never requirement
  approval. Original wording retained below.
  Confirm, at #5 technical design, that: (i) an expiry-driven delete pass composes with
  **ADR-011**'s FIFO ordering/claim state (rows referencing a captured event) and
  with Async in-flight jobs such that AC8 holds and no in-flight event is lost;
  (ii) retention state and GC bookkeeping can exist **without mutating** the
  raw-only immutable `webhook_events` entity (ADR-010) — AC11; (iii) where the
  dispatched-output store lives so that #8 mapping and #10's obfuscation/encryption
  policy attach additively (AC12, AC15) and the #3 `encrypted` at-rest floor is
  preserved with no plaintext copy; (iv) whether the resulting change is a
  **data-model change** requiring the Owner approval gate in `CLAUDE.md` at plan
  time. Mechanism, scheduling, batching, and delete strategy are the Principal
  Engineer's, not resolved here.
- **Q-05-04 (Principal Engineer, technical) — OPEN, raised by Amendment A.
  Feasibility of AC22 header encryption and the AC21 cleaned-state signal.
  Non-blocking for requirement approval; gates technical design only.** Doc:
  `docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md`. Two
  feasibility items the Product Manager will not resolve technically: (i) whether
  encrypting captured headers at rest (AC22a) disturbs any existing consumer of
  captured headers — #3's capture path, ADR-008 header forwarding, a future #6
  replay — or any need to filter/match on them; (ii) whether the AC21 cleaned-state
  signal and in-place erasure (AC11) create any interaction with #4's dispatch state
  or the AC8 in-flight holds that the previous delete-based design did not have. If
  either is infeasible as stated, that returns to the Product Manager as a
  requirement question, not a silent design change.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#5 line + build-ahead note; Open Questions
  V4/V5/V6; Resolved Decisions R2, R3, R4; #6/#7/#10/#11 boundaries),
  `docs/product/vision.md` ("Payload storage"; "Analytics / stats" decoupled from
  retained payloads; Known Constraints — MySQL, no compliance requirements; Open
  Questions 4, 5, 6),
  `docs/product/prd-03-decoupled-upstream-response.md` (capture-before-response,
  AC7/AC8/AC11, Owner Q-03-02 retention answer, R2 capture-dimension override),
  `docs/product/prd-04-queued-processing.md` (queued dispatch #5 must not fight),
  `docs/product/prd-02-role-based-collaboration.md` (permission-gated, team-scoped
  proxy access — the basis of this PRD's AC16),
  `docs/architecture/adr-010-raw-payload-capture.md` (raw-only immutable capture;
  Amendment B at-rest body encryption + APP_PREVIOUS_KEYS guard),
  `docs/architecture/adr-003-delivery-attempt-records-and-events.md` (payload-free
  attempt records on their own lifecycle — AC9),
  `docs/architecture/adr-002-simple-enhanced-mode-attribute.md` (the mode gate —
  AC12/AC14),
  `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md` (dispatch-by-
  reference from the stored raw event — AC8),
  `docs/architecture/adr-009-proxy-permission-mechanism.md` (permission gate),
  `docs/standards/documentation.md`.
- **Outputs:** this PRD (incl. **Amendment A**, Owner ruling 2026-08-05);
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md` (RESOLVED, Owner
  2026-08-05 — Option B);
  `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`
  (RESOLVED, Owner 2026-08-05 — both defaults confirmed);
  `docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md` (OPEN,
  Principal Engineer — raised by Amendment A).
- **Dependencies:** Roadmap #1 (Done) — team-scoped proxies and payload-free attempt
  records. #3 (Done) — durable raw capture is the thing #5 puts a lifecycle on;
  without it there is nothing to retain. #4 (Done, merged) — queued/FIFO dispatch is
  what AC8 must not break. #5 does **not** depend on #6, #7, #8, or #10, and must not
  pre-empt them.
- **Outstanding Questions:** **Q-05-03 (Principal Engineer) only** — non-blocking for
  requirement approval, gates technical design. **Q-05-01 — RESOLVED** (Owner,
  2026-08-05, Option B: no inspection surface at #5;
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md`); its approval block
  on this PRD is cleared. **Q-05-02 — RESOLVED** (Owner, 2026-08-05, both defaults
  confirmed; `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`).
  Roadmap V4, V5, V6 — **settled in this PRD** (see the dedicated section), not
  escalated; Owner approval ratifies them.
- **Next Agent:** **Principal Engineer** — now carrying **Amendment A** and
  **Q-05-04** (Q-05-03 already answered, partly overtaken). Routing is unchanged by
  the amendment: this PRD still contains **no UX Direction section** and adds no UI,
  so under the mechanical routing rule it goes to the Principal Engineer and **no
  Designer gate applies** to Feature #5. The PRD is **Approved** (Owner 2026-08-05)
  and **amended** (Owner ruling, same date); no further requirement gate stands.
  The Principal Engineer's next step is revising **ADR-012** and **ADR-013** (and
  ADR-010's superseded constraints) against AC5–AC12, AC15, AC21, AC22 and D1 before
  the Owner sign-off those ADRs already require.
