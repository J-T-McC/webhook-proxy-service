# PRD: Payload storage & retention

- **Status:** Approved
- **Author:** Product Manager
- **Date:** 2026-08-05
- **Approved by / date:** Project Owner, 2026-08-05
- **Backlog item:** Roadmap #5 (`docs/product/roadmap.md`)

## Feature
Every payload the service stores has a bounded life: a **team-level 30-day
retention window** measured from capture, and a **garbage collector** that
permanently removes payloads once that window elapses — while an **enhanced-mode**
proxy additionally stores the payload it **dispatched**, separately from and
without mutating the raw input it received.

## Problem
Three gaps sit on top of #1/#3/#4:

1. **Storage grows unbounded.** #3 pulled the *capture* half of #5 forward
   (ADR-010): every incoming payload is now durably persisted in both modes, and
   **nothing ever removes it**. PRD-03 AC11 records this as an accepted interim
   trade-off explicitly deferred to #5 (Q-03-02). Payload content is the most
   sensitive data we hold; keeping it forever is both a cost and a
   confidentiality problem.
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

Item #5 closes all three without touching the ingest, response, or dispatch paths
#1/#3/#4 already established.

## What #3 already delivered vs. what #5 adds
Recorded so scope is unambiguous — #3's Owner scope change moved the boundary.

| Concern | Owner | State |
|---|---|---|
| Durable raw capture, before the upstream response, **both modes** | #3 (ADR-010, R2 capture-dimension override) | Done |
| Raw body encrypted at rest; headers plaintext | #3 (ADR-010 Amendment B) | Done — headers stay plaintext until #10 |
| Raw/dispatched separation as a principle | #3 AC8 (R2 build-ahead) | Principle set; output store does not exist |
| **Retention window + garbage collection** | **#5** | **This PRD** |
| **Dispatched-output storage (enhanced mode)** | **#5** | **This PRD** |
| Manual replay of a stored payload | #6 | Not here |
| Enhanced-mode toggle UI | #7 | Not here |
| Field obfuscation, header/sensitive-data policy, verification tokens | #10 | Not here |

## Goals
- Every stored payload is removed automatically once its retention window elapses;
  storage no longer grows unbounded (closes the PRD-03 AC11 interim gap).
- The retention window is **30 days from capture**, applied **per team**, in both
  simple and enhanced mode (Owner decision Q-03-02, 2026-08-03).
- Within its window a stored raw payload is **guaranteed retrievable**, so #6
  replay has a defined guarantee to build on; after the window it is guaranteed
  **gone**, everywhere.
- An enhanced-mode proxy stores the **dispatched output** separately from the raw
  input; the raw input is never mutated (roadmap #5 line; R2's non-overridden half).
- Payload expiry never costs us delivery history: payload-free delivery-attempt
  records (ADR-003) and anything #11 derives from them survive independently and
  indefinitely.
- Deleting expired payloads never loses an event that is still being processed
  under #4's queued/FIFO dispatch (ADR-011).
- The at-rest protection floor set at #3 is preserved by anything #5 stores; #10
  still owns the full sensitive-data policy.

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
- As a team member, I want payloads to be deleted automatically once that window
  passes, so we do not hold sensitive payload content indefinitely and storage
  does not grow without bound.
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

5. **Expired payloads are removed automatically.** A garbage collector removes
   payloads whose retention window has elapsed, with **no user action** and no
   per-team opt-in. It runs recurrently, not once. (Scheduling and mechanism are
   the Principal Engineer's — see Q-05-03.)
6. **Removal is complete.** After removal, that event's payload content — the raw
   body, its captured request metadata that constitutes payload content, and any
   stored dispatched output for the same event — is no longer retrievable through
   **any** user-facing or system path, including a #6 replay. No partial or
   truncated copy of the body is retained anywhere as a side effect of GC.
7. **Unexpired payloads are never removed.** A payload inside its window is never
   deleted by GC. Within the window, retrieval of a stored raw payload for a
   proxy's event is guaranteed — this is the guarantee #6 replay builds on.
8. **In-flight events are not eligible for removal.** A payload for an event whose
   dispatch has not completed — including one queued, pending, or claimed under
   #4's per-proxy FIFO ordering, or in flight under Async — is **not** removed while
   that dispatch is outstanding, even if its window has elapsed. Rationale: queued
   dispatch rebuilds its input from the stored raw event (ADR-011), so removing it
   mid-flight would lose the event. (Bounding a permanently stuck event is #6's
   dead-letter concern, not asserted here.)
9. **Delivery history survives expiry.** Removing an expired payload never deletes
   or alters the payload-free delivery-attempt records for that event (ADR-003).
   Success/failure history and anything #11 aggregates from it remain intact and
   are **not** subject to payload retention.
10. **Expiry is a normal state, not an error.** Where an expired payload is
    referenced, the system reports it as expired / no longer available and the
    event's surviving outcome records stay readable; it does not error, 500, or
    present as data corruption. (What a #6 replay does when the payload is expired
    is #6's to specify; this criterion only requires the expired state be
    represented, not fail.)

**Storage shape**

11. **Raw stays immutable.** Nothing in #5 mutates a captured raw payload — not
    storing the dispatched output, not marking retention state, not the GC pass
    short of deleting the record (ADR-010; PRD-03 AC8; R2 build-ahead).
12. **Dispatched output stored in enhanced mode.** When a proxy is in **enhanced
    mode**, the payload actually dispatched to destinations for a received event is
    stored **separately from** the raw input and is associated with the **same
    received event**, so input and output are independently identifiable. In
    **simple mode** no dispatched-output record is created.
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

15. **At-rest protection floor preserved.** Any payload content #5 stores carries
    **at least** the at-rest protection established for the raw body at #3
    (encrypted at rest — ADR-010 Amendment B). #5 does not create a
    less-protected copy of payload content and does not decrypt payload content
    into a new store. Inbound headers remain plaintext at rest until #10 —
    unchanged and not widened by this item.
16. **Access is team-scoped and permission-gated.** Any read path that exposes
    stored payload content is restricted to members of the owning team and gated by
    the existing proxy **read** permission (PRD-02 / ADR-009). #5 adds no sharing,
    export, download, or cross-team access path.

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

## Out of Scope
Each points to the item that owns it.

- **Manual replay, retry, configurable backoff, dead-letter** — roadmap #6. #5 only
  guarantees the payload is there to replay while unexpired.
- **Enhanced-mode toggle UI / making enhanced mode reachable in the UI** — roadmap
  #7. #5 uses the existing mode attribute (ADR-002) as a gate only.
- **Payload mapping / reshaping** — roadmap #8. #5 stores the dispatched output; it
  never transforms it.
- **Encryption key policy, rotation/re-encryption tooling, field-level obfuscation,
  sensitive-header handling, verification-token standards (V2)** — roadmap #10. #5
  preserves the #3 floor (AC15) and adds no policy of its own. The APP_PREVIOUS_KEYS
  operational guard (ADR-010 Amendment B) remains binding and is unchanged.
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
- **Q-05-03 (Principal Engineer, technical) — OPEN. GC composition and the output
  store's home. Non-blocking for requirement approval; gates technical design.**
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
- **Outputs:** this PRD;
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md` (RESOLVED, Owner
  2026-08-05 — Option B);
  `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md`
  (RESOLVED, Owner 2026-08-05 — both defaults confirmed).
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
- **Next Agent:** **Principal Engineer** (carrying Q-05-03). Settled by the Q-05-01
  resolution: this PRD contains **no UX Direction section** and adds no UI, so under
  the mechanical routing rule it goes to the Principal Engineer and **no Designer
  gate applies** to Feature #5. This handoff is contingent only on the outstanding
  Project Owner approval of this PRD itself, which is still pending.
