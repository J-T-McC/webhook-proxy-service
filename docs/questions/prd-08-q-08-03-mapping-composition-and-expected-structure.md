# Question Q-08-03: Mapping's gate under ADR-018, the expected-structure model, and the mapping / storage / retry interactions

- **Status:** **RESOLVED — Principal Engineer, 2026-08-26**, at #8 Technical Design, exactly
  on the Q-07-02 → ADR-018 precedent. All six items answered below. The mechanism is recorded
  as **ADR-019** (`docs/architecture/adr-019-payload-mapping-composition-and-configuration.md`,
  **Proposed — pending Project Owner approval**) and built into
  `docs/plans/plan-08-payload-mapping.md`. Item (2)'s expected **`CLAUDE.md` data-model Owner
  gate is confirmed and declared**: four new tables, enumerated in plan-08 § *Data Model*.
- **Raised by:** Product Manager
- **Owner (must answer):** **Principal Engineer** *(technical. Step composition is the
  Principal Engineer's per the roadmap #7 and #8 build-aheads; ADR-018 Decision 1 reserves
  the choice of gate kind to the Principal Engineer explicitly. The Product Manager states
  requirements and will not resolve any of this technically.)*
- **Raised:** 2026-08-25
- **Gates:** nothing in PRD-08's approval. Confirm at **#8 Technical Design**. Item (2)
  is expected to raise a **`CLAUDE.md` Owner gate** (data-model change) at plan time.
- **Unaffected by the M1/M2 rulings** (Project Owner, 2026-08-26): items (1)–(5) stand
  exactly as raised. Item (6) is now *answerable* — the selection rules it asks the PE to
  confirm determinism against are concrete. **AC references below were renumbered on
  2026-08-26** when PRD-08 gained AC17; no question content changed.
- **Source:** `docs/product/prd-08-payload-mapping.md` (AC4, AC8, AC11, AC12, AC14, AC16,
  AC17, AC18, AC19, AC20, AC22, AC24, AC25, AC27); `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md`
  (Decisions 1, 4, 5, **6**); `docs/architecture/adr-002-simple-enhanced-mode-attribute.md`;
  `docs/architecture/adr-013-dispatched-output-store.md`; `docs/product/roadmap.md` #8
  build-ahead ("Principal Engineer to fix the approach").

## Context
ADR-018 (Accepted, Project Owner, 2026-08-25) draws a line the Product Manager must not
cross on its own:

| Kind of enhanced-only behaviour | Gate | Where |
|---|---|---|
| A pipeline **step** | composition-time, **structural** | `PipelineFactory::stepsFor()` |
| Per-proxy **configuration** consulted outside the pipeline | **resolution-time**, inside that configuration's single resolver | e.g. `App\Services\RetryPolicy` |

**Mapping is the first capability that plainly has both.** Reshaping is a pipeline step —
`PipelineFactory` already carries the reserved `// $steps[] = MapStep::make(); // #8 —
reshape` line inside the `ProxyMode::Enhanced` branch, immediately before
`CaptureDispatchedStep`. But a proxy's **maps** and its **expected incoming structure** are
per-proxy configuration that is also read *outside* the pipeline — by the authoring
surface, by the events surface (PRD-08 AC16), and later by #9 and #12. ADR-018 Decision 6
prescribes a recipe for exactly this shape ("its own columns/tables **plus its own single
resolver repeating the Decision-2 gate**), but whether mapping needs the resolution-time
gate at all — and where the map-**selection** logic lives relative to the step — is a
composition call, not a requirement.

## Question

1. **Which side of ADR-018 does mapping sit on — and is the answer "both"?** Confirm:
   (i) reshaping is gated **structurally** by composing `MapStep` only in the Enhanced
   branch, at its reserved position before `CaptureDispatchedStep`; and (ii) whether map
   configuration read *outside* the pipeline needs its **own single resolver carrying the
   Decision-2 gate**, per ADR-018 Decision 6, so PRD-08 AC6's "a Simple proxy's preserved
   maps never present as in force" is a property one class owns rather than a discipline
   every consumer remembers. If it is both, name the resolver and confirm this is still
   *two evaluation points, one gate* — **not** the second gate PRD-07 AC6/AC18 and
   ADR-018 Decision 1 forbid. If the answer is that no resolver is needed, say why the
   structural gate is sufficient for the read surfaces.
   *Also confirm ADR-018 Decision 5 extends to mapping: map configuration is read live from
   the proxy at the moment of use, never snapshotted onto an event, job, delivery, or
   attempt (this is what PRD-08 AC25 asserts as user-visible behaviour).*

2. **The expected incoming structure — model and Owner gate.** PRD-08 AC8 requires it to be
   a **first-class, persisted property of the proxy**, independent of any map, surviving
   the deletion of every map, and the single representation #9 writes into and #12 compares
   against (roadmap #8 build-ahead, verbatim). Fix the approach, and state plainly:
   **does #8 carry a `CLAUDE.md` data-model Owner gate at plan time?** The Product Manager
   **expects yes** — unlike #7, this item plainly adds persisted entities (maps, and the
   expected structure) — and would rather the gate be declared early than discovered at
   plan time. Include whether an ADR is warranted (the PM's read: yes, on the ADR-002
   "enhanced config attaches as its own config" seam).

3. **ADR-013's divergence gate under routine divergence.** ADR-013 stores the dispatched
   output's body only when it diverges from the captured raw input. For a mapped proxy,
   divergence becomes the **normal** case rather than the exception, so PRD-08 AC20's
   "what is stored as dispatched is what was actually sent" holds by construction — but the
   storage-volume consequence lands inside PRD-05's retention contract, which PRD-08 AC20
   forbids changing. Confirm ADR-013 needs no change, or name what does.

4. **Does a retry re-apply mapping?** PRD-06 retries an already-failed **delivery**;
   PRD-06 AC11 and ADR-018 Decision 5 establish that replay and mode resolution use the
   proxy's **current** configuration. PRD-08 AC25 states the user-visible consequence
   (an event replayed after a map edit is reshaped by the new map, and mixed treatment is a
   normal outcome, not a fault) but deliberately leaves the mechanism open: does a
   scheduled retry hours later re-run selection and mapping, or re-send the payload already
   reshaped for that delivery? Both are defensible; the PM needs the ruling to be **stated
   and consistent**, because it is user-visible. Also confirm mapping runs **once per
   event**, not once per destination (PRD-08 AC18 / R3). *A further case the M1 ruling
   creates: an event that selected **no** map (PRD-08 AC15) is delivered unreshaped —
   confirm that path needs no special handling on retry or replay beyond "select again
   against current configuration".*

5. **Feasibility of PRD-08 AC22's failure semantics.** AC22 requires that a map which
   cannot be applied loses nothing (capture precedes mapping — vision success signal), does
   **not** deliver a partial or incorrect reshape, is attributable to the event (AC16), and
   is recoverable through the existing #6 replay path once the map is fixed. Confirm this
   is achievable within the ADR-001 spine without a branching step, and name where the
   failure is recorded — reusing the existing delivery/attempt records and domain events
   (ADR-003), **not** a parallel path.

6. **Determinism under the settled M1/M2 rules, and the #9/#12/#14 seams.** *(Now
   answerable — Owner ruled 2026-08-26.)* Confirm PRD-08 AC16's determinism holds under
   queued dispatch, FIFO claim state, scheduled retries and replays, given AC14's
   member-controlled evaluation order and AC12's typed, case-sensitive comparison — in
   particular that the **order is read live from the proxy** like every other piece of
   configuration (ADR-018 Decision 5) and is never snapshotted onto an event or job. Confirm
   also that the condition model AC17 requires — explicit operator, condition set at count
   one, operator additions with no migration and no contract change — is achievable in the
   shape you propose, since AC17 is the criterion the Reviewer checks the shipped model
   against. And confirm the seams PRD-08
   AC27/AC28 require are left in place — #9 normalises into the **same** JSON
   representation ahead of `MapStep` (`#9 NormalizeStep` is already reserved before it),
   #12 compares against the **same** expected structure (`#12 ChangeDetectStep` reserved in
   the tail stage), #14 runs the **same** path as real traffic.

## Impact if unresolved
PRD-08 can still be approved and can still clear the Designer gate — none of the above
changes a requirement or a user-visible outcome the Owner rules on. Unresolved at
**Technical Design**, it blocks `design-08`'s successor: `plan-08` cannot place the gate,
cannot model the maps or the expected structure, and cannot declare the data-model Owner
gate item (2) is expected to raise.

## Answer
- **Answered By:** Principal Engineer
- **Answered:** 2026-08-26
- **Recorded as:** **ADR-019** — *payload mapping: composed step, resolved configuration, and
  the recorded outcome* (**Proposed, pending Project Owner approval**). Built into
  `docs/plans/plan-08-payload-mapping.md`. **ADR-019 supersedes no position of any Accepted
  ADR**, and **ADR-013 is not changed** (item 3).

### (1) Which side of ADR-018 — and yes, the answer is **both**

**(i) Reshaping is gated structurally**, by composing `MapStep` only in the
`ProxyMode::Enhanced` branch of `PipelineFactory::stepsFor()`, at its reserved position —
after the `// #9 NormalizeStep` seam, before `CaptureDispatchedStep` (`PipelineFactory.php:34`).
A Simple proxy's composed list stays `[DeliverStep]`, so nothing can reshape for it whatever
its tables hold.

**(ii) Yes, map configuration also needs its own single resolver carrying the Decision-2
gate.** It is **`App\Services\MappingPolicy`**, mirroring `RetryPolicy` exactly:
`configuredMapsFor(Proxy)` establishes `mode === Enhanced` before treating any map as in
force and returns an **empty** set for a Simple proxy whatever the rows hold;
`selectFor(Proxy, ?array)` routes through it and so inherits the gate with no branch of its
own. PRD-08 AC6 therefore becomes a property **one class owns and one test pins**, not a
discipline every consumer remembers — which is the whole point of ADR-018 Decision 2 and the
lesson of `ProxyResource:49-50`.

**And it is still two evaluation points behind one gate — not the second gate PRD-07 AC6/AC18
and ADR-018 Decision 1 forbid.** Both points read the same `proxies.mode` enum **directly**.
There is no new attribute, no per-capability sub-toggle, and no inference — nothing concludes
a proxy's mode from the presence of a map, a `dispatched_payloads` row or an `event_mappings`
row. ADR-018's constraint is on the **kinds** of place `mode` may be read ("exactly the two
kinds … a third requires superseding this ADR"), and mapping adds no third kind. After #8 there
are three *named* points of those two kinds: `PipelineFactory::stepsFor()`, `RetryPolicy`,
`MappingPolicy`. **No supersession of ADR-018 is needed.**

**One bounded carve-out, named so it stays auditable.** The authoring surface must show a
Simple proxy's preserved maps — PRD-08 AC6's own "the authoring surface is a **write** surface",
and design-08's flagged call 1 as accepted by the Product Manager. `ProxyMapController`
therefore reads maps from the proxy relation directly, bypassing the gate. That is exactly #7's
single-carve-out shape (`ProxyFormResource` for the Edit form) and is bounded the same way:
**no read surface anywhere emits map configuration.** `ProxyResource` gains nothing.

**ADR-018 Decision 5 extends to mapping, confirmed.** Maps and their order are read live from
`$ctx->proxy` — the one trashed-inclusive instance `ProcessIngestedWebhook:50` loads at pipeline
entry — at the moment of use. No map, map id, order or output document is serialized into a job
argument or stored on an event, delivery, dispatch or attempt. A replay re-loads the proxy and
re-selects; PRD-08 AC25's user-visible behaviour follows structurally.

*One distinction stated so it is not mistaken for a Decision-5 breach:* the per-event **outcome**
record (item 5) is a historical fact about what happened, in the same class as
`delivery_attempts.http_status`, not a snapshot of configuration. The invariant that keeps that
real is binding: **`event_mappings` is write-only from the pipeline's perspective — nothing in
selection, application, delivery, retry, replay, GC or FIFO ever reads it to decide anything.**

### (2) The expected structure, and the data-model Owner gate

**Yes. #8 carries a `CLAUDE.md` data-model Owner gate, and it is declared up front** in
plan-08's Status line, § *Data Model*, and § *Owner-approval flags (✋)* — the Product Manager's
expectation was correct. **Four new tables**, with **no change to any existing table**:

| Table | Purpose |
|---|---|
| `proxy_maps` | one row per map — `name`, `is_default` (`boolean NULL` under `UNIQUE(proxy_id, is_default)`, so AC13's "at most one" is a database guarantee), `position` (nullable; evaluation orders `position ASC, id ASC`), `output` (`longText`, cast `array`) |
| `proxy_map_conditions` | the condition **set** — `path`, `operator` (`string(32)`, backed PHP enum), `value` + `value_type`, as separate named columns |
| `proxy_expected_structures` | one row per proxy — `fields` (derived path/type list), `source`, `established_at` |
| `event_mappings` | the recorded outcome — one row per event (`UNIQUE(webhook_event_id)`), `outcome`, `proxy_map_id` (`nullOnDelete`), `map_name`, `failure_reason`, `dispatch_uuid` |

No column, index, enum value or default is added to, changed on, or removed from any existing
table; **no backfill and no data migration**; rollback is four `dropIfExists`. Full definitions
and every rejected alternative are in ADR-019 § Impact.

**The expected structure's model.** It is its own 1:1 table rather than columns on `proxies`,
because `proxies` is loaded on **every ingest** and a large text column there would ride the hot
path for a value the pipeline never reads. It holds the **derived path/type list and its
provenance — never the sample payload bytes**. That is not a preference: a sample chosen from a
received event *is* payload content, and persisting it in a configuration table would create an
at-rest copy that **never expires**, outside the PRD-05 retention contract PRD-08 AC19 forbids
changing and against ADR-012's whole rationale. AC8 requires the structure; AC9 needs only paths
and types; #9 normalises *into* it and #12 compares *against* it — none needs a stored payload.
**Consequence, recorded for the Designer and PM, blocking nothing and requiring no ruling:**
design-08 Screen 6's *"has a sample"* Preview state is not reachable after a page reload, so
Preview renders the spec's own *"no sample"* state, with the copy Screen 6 already specifies. If
real-value preview is wanted later, the additive route is fetching a **retained** event through
the existing permission-gated fetch-on-reveal endpoint at preview time — storing nothing.

**It carries no mode gate, deliberately** (ADR-019 Decision 3, and the one interpretive point
surfaced to the Owner). ADR-018 Decision 6's gate exists to stop dormant configuration
**governing events**; the expected structure governs none in either mode — it drives autocomplete
and validation. Gating it would make a Simple proxy's authoring surface lie about what its member
established, the inverse of AC6. If the Owner prefers Decision 6's literal reading, gating it is a
one-line change.

**An ADR is warranted — the PM's read was right, and on the seam they named.** ADR-019 records the
ADR-002 "enhanced config attaches as its own config" seam, plus five other things #7 had no
analogue for: the composition call ADR-018 Decision 1 reserves to the Principal Engineer; a
persisted model that is hard to reverse once data exists; the user-visible retry-versus-replay
boundary; and the failure rule at item (5).

### (3) ADR-013 under routine divergence — **no change to ADR-013, and nothing else changes either**

The divergence test is a **content** gate, not a retention gate, and #8 is precisely the case
ADR-013's own **Decision 7 and Revision A already named**: *"divergence begins at #9 … and again
at #8 … the moment any transform seam is filled it starts recording real divergence with no
schema or code change here."* Mapping fills the seam; the store starts holding bytes; nothing in
ADR-013 needs restating.

**The volume lands inside PRD-05's retention contract without touching it, and AC19 holds.** GC
selects by **event age** under holds H0–H5 and is blind to whether a body diverged;
`PurgeExpiredPayloads::eraseOne()` already nulls `dispatched_payloads.body` **unconditionally**,
with no `body IS NOT NULL` predicate, inside the same transaction as the parent erasure. Per-event
GC cost is therefore identical; only the bytes reclaimed change. No window, no hold, no pass, no
schema and no code in the retention path is edited.

**No new Owner decision is created — an accepted one materialises.** "Roughly doubling stored
payload volume" is the trade-off the Project Owner **accepted at Q-05-02(b)/PRD-05 on 2026-08-05**;
Revision A's divergence gate deferred it, and #8 lands it, for mapped Enhanced proxies only.
Recorded for **awareness**, not as a gate. One related obligation does become live for the first
time: ADR-013/ADR-014's binding `APP_PREVIOUS_KEYS` rule spans three columns, and the third
(`dispatched_payloads.body`) starts holding data once mapping ships.

*Also confirmed:* ADR-013 Decision 3's "NULL means output == input" stays true and stays useful —
a map whose output happens to be byte-identical still stores NULL, and
`StoredPayloadLookup::dispatchedBytesFor()` returns the raw bytes, correctly, with no
special-casing.

### (4) Does a retry re-apply mapping? **No. It re-sends the payload already reshaped for that delivery. A replay re-maps.**

This is the **shipped** mechanism, unchanged, so PRD-08 AC21 ("no retry/replay semantic change")
holds literally: `RetryDelivery` resolves its bytes through
`StoredPayloadLookup::dispatchedBytesFor()` (`RetryDelivery.php:73`; ADR-013 Decision 3, ADR-015
Decision 1), while a replay mints its own `dispatch_uuid` and re-runs `ProcessIngestedWebhook`
over the whole pipeline from the raw capture, under the proxy's **current** configuration (PRD-06
AC9–AC11, ADR-017).

**The alternative is not merely rejected — it is excluded by PRD-08's own criteria.** If a retry
re-mapped, the bytes sent on attempt 3 would differ from `dispatched_payloads.body`, falsifying
**AC20** ("what is stored as dispatched is what was actually sent") and ADR-013 Decision 5
("nothing is sent that was not first recorded"). Restoring truth would mean rewriting the
dispatched row mid-retention — a mutation inside PRD-05's contract that **AC19** forbids.

**The user-visible consequence, stated so it is consistent everywhere:** *a retry after a map edit
re-sends the old shape; a replay after a map edit sends the new shape.* That is coherent — a retry
continues one dispatch, a replay creates a new one — and PRD-08 AC24 already rules mixed treatment
across an event's history a normal outcome, not a fault.

**Mapping runs once per event per dispatch, never once per destination** (AC18/R3): `MapStep`
assigns `$ctx->payload` once and `DeliverStep` builds every `DeliveryUnit` from that same value
(`DeliverStep.php:56`). `UNIQUE(webhook_event_id)` on `event_mappings` makes it a checkable
invariant rather than a convention.

**The AC15 no-map path needs no special handling on retry or replay, confirmed.** On retry,
`$ctx->payload` equalled `$ctx->rawBody`, so `dispatched_payloads.body` is NULL and
`dispatchedBytesFor()` returns the raw bytes — the same bytes attempt 1 sent. On replay, selection
simply runs again against current configuration and may now match. Zero branches, zero new code.

### (5) AC22's failure semantics — achievable within the ADR-001 spine, with no branching step, **and one finding that makes it more than a formality**

`MapStep` applies the selected map into a **new** string and assigns `$ctx->payload` only on
complete success, so a partial reshape can never reach the context. On failure it records the
outcome, terminalizes the dispatch's deliveries, and **returns `$ctx` without calling `$next`** —
so `CaptureDispatchedStep` never records a dispatch that did not happen and `DeliverStep` never
sends. That short-circuit shape is already shipped (`CaptureDispatchedStep:70-74`, the
cleaned-parent path); no branching step, no parallel path, no new domain event.

**Where the failure is recorded:** in `event_mappings` (`outcome = failed`, the map named, a
bounded `failure_reason` that never carries payload content), plus the **existing** delivery
records and domain events — the dispatch's `deliveries` rows compare-and-set to `failed` with
`next_attempt_at = null`, emitting `DeliveryExhausted` once per affected row. **No
`delivery_attempts` row is written, because nothing was attempted** — matching PRD-06 AC17's
posture and `RetryDelivery::terminalizeCleaned()`'s precedent exactly. ADR-003 is unchanged.

**The finding: terminalizing is mandatory, not tidy.** A bare short-circuit leaves the dispatch's
`pending` deliveries non-terminal. On a **FIFO** proxy, `AdvanceProxyFifoQueue::settleOrHold()`
then parks the `fifo_dispatches` row at `awaiting_retry` **with no lease and no retry schedule**,
and nothing will ever settle it — `SweepStalledFifoDispatches` pass (b) skips the proxy because a
held row exists, and pass (c) will not release it because the deliveries are non-terminal. The
proxy's FIFO line stops permanently, and GC hold **H2** (`no fifo_dispatches row with a status
other than settled`) **has no age escape**, so every one of that proxy's expired payloads becomes
**immortal** — the unbounded hold rejected by name three times (ADR-012 § Alternatives, ADR-015
Decision 7, ADR-016 Decision 2) and a direct PRD-05 AC6 violation. On **Async** the same
stranding leaves the event's delivery badge reading pending forever. Terminalizing closes both:
`settleOrHold` finds every delivery terminal, settles the row and advances the line. plan-08
carries this as **Risk R1** with a dedicated FIFO regression test.

AC22 then holds end to end: capture precedes mapping and is unaffected (ADR-010, in the ingest
handler, mode-independent); the event stays retained, visible and **replayable** — replay
pre-creates its **own** delivery rows under a new `dispatch_uuid`, so terminalizing the original
dispatch blocks nothing; the failure is attributable (AC16); and nothing partial or incorrect is
delivered, because no HTTP send occurs at all.

**What counts as a failure is deliberately narrow**, so AC21 is not violated by over-reporting:
a **selected map that cannot be applied**, and nothing else. An **undecodable body is not a
failure** — it selects no map (`outcome = none`) and is delivered unreshaped, per AC15/AC21/AC31,
which is also what keeps #9's seam clean. A **referenced-but-absent field omits its output field**
(never emits `null`) — AC21's "defined and stable", chosen because `null` is a value many
destinations read as an explicit clear. Unexpected extra incoming properties are simply not
referenced. With no expressions, lookups or loops, what remains is a structurally invalid persisted
map document or an output-path collision — narrow by construction, and driven in test through a
deliberately invalid persisted document.

### (6) Determinism, AC17, and the #9/#12/#14 seams

**Determinism (AC16) holds under queued dispatch, FIFO claim state, scheduled retries and
replays.** The outcome is a function of the payload and the proxy's **current** configuration,
never of the run: nothing in selection reads the clock, the attempt number, the destination, the
queue or the FIFO claim; the order is read **live** from the proxy (`position ASC, id ASC`) and is
**never snapshotted onto an event or job** (ADR-018 Decision 5, confirmed at item 1); and the
`id` tie-break makes the walk a **total** order, so it stays deterministic even if two rows ever
shared a position. Comparison is exact, case-sensitive and typed (`===` on decoded scalars, so
`42 !== "42"` and a scalar never equals an object or array), and an absent path never matches and
never errors. A retry cannot change the outcome at all (item 4), so within one event's history
variance can arise only across replays — which AC24 already rules normal.

**AC17 is achievable in the shape proposed, checked against the actual design rather than in
principle:**
- **(a)** `proxy_map_conditions.operator` is a **non-nullable column**, emitted by the resource
  and rendered in the UI from its own `data/` const — never implied, never hardcoded (design-08
  **C4** covers the one rendering that assumed equality).
- **(b)** conditions are a **separate table**, emitted as an **array** and edited as rows — a
  one-condition map has the same shape a two-condition map would, at persistence, API and UI.
- **(c)** the contract is `ProxyMapCondition::matches(array $payload): bool`, resolving the path
  into a `PathLookup{found, value}` and delegating to `$this->operator->matches(...)`. Passing the
  lookup rather than a coerced value is what lets a future `exists` ship with no contract change.
  Nothing in selection, storage, validation or presentation assumes `equals`.
- **(d)** adding `one-of`, `not-equals` or `exists` is one enum case, one comparator arm, one
  `SelectItem` and one validation rule — **no migration** (the column is `string(32)`, a
  deliberate departure from the house `enum(...)` precedent chosen for exactly this), no change to
  the selection contract, no change to how a condition is presented. A test adds a temporary
  operator case in-test to prove it.
- **(e)** exercising the delegated latitude: **`equals` only in the first pass.** (a)–(d) make
  later additions genuinely cheap, which is the condition AC17(e) attaches; every extra operator
  must be *completely* specified, presented and tested for a capability no user story asks for,
  and a half-shipped operator is a defect by AC17(e)'s own words.
- **(f)** AND/OR is not precluded: the set already exists, and a combinator would be one new
  nullable column with no backfill. None is built (AC30).

**The seams AC27/AC28 require are left in place.** **#9**: `NormalizeStep` stays commented
**above** `MapStep` in the same reserved front stage and will mutate the same `$ctx->payload`; the
"undecodable ⇒ `none`, delivered unreshaped" rule means non-JSON traffic passes through harmlessly
today and starts matching the **same** maps, in the **same** JSON representation, the day #9 lands
— **no second mapping path, no second editor, no format-specific map, and no change to `MapStep`**.
**#12**: `ChangeDetectStep`'s tail-stage seam is untouched and will read the **same**
`proxy_expected_structures` through the **same** `MappingPolicy` — there is no second structure.
**#14**: a test payload driving `ProcessIngestedWebhook` over a real `PipelineContext` runs the
identical selection and application path as live traffic, not a mock; it inherits
`CaptureDispatchedStep`'s existing need for a real `webhook_events` row and `MapStep` adds no new
constraint. `PipelineContext` is **unchanged** by #8.
