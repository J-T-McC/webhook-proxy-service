# ADR-019: Payload mapping — composed step, resolved configuration, and the recorded outcome

- **Status:** **Proposed — pending Project Owner approval.** Carries a **data-model gate**
  (four new tables, enumerated in § Impact) and one interpretive point about ADR-018
  Decision 6 the Owner should rule on explicitly (§ Relationship to ADR-018).
- **Author:** Principal Engineer
- **Date:** 2026-08-26
- **Feature:** prd-08-payload-mapping (AC1–AC8, AC11–AC22, AC24, AC25, AC27, AC28; the
  mechanism behind the answers to
  `docs/questions/prd-08-q-08-03-mapping-composition-and-expected-structure.md` items 1–6)
- **Relationship to ADR-002:** extends it, never widens it — `mode` stays a pure selector,
  and mapping configuration attaches "as its own config", in its own tables.
- **Relationship to ADR-013:** **no change.** Routine divergence is the outcome ADR-013
  Decision 7 and its Revision A already anticipated and named (§ Decision 9).
- **Relationship to ADR-018:** **no position superseded** — see § Relationship to ADR-018.

## Question

ADR-018 Decision 1 fixes exactly two kinds of place where `mode` may be evaluated:
**composition-time** for a pipeline step, and **resolution-time**, inside a single resolver,
for per-proxy configuration consulted outside the pipeline. Every enhanced capability so far
has been one or the other. **Mapping is the first that is plainly both:** reshaping is a
pipeline step (`PipelineFactory` has carried the reserved `// $steps[] = MapStep::make();
// #8 — reshape` line since #1), while a proxy's maps and its expected incoming structure are
per-proxy configuration read outside the pipeline — by the authoring surface, by the events
surface (PRD-08 AC16), and later by #9 and #12.

ADR-018 Decision 6 prescribes a recipe for that shape ("its own columns/tables **plus its own
single resolver repeating the Decision-2 gate**"), but Decision 1 reserves the composition
call itself to the Principal Engineer, and the recipe leaves four things undecided that #8
cannot be built without:

1. Is the answer *both* gates — and if so, is that still **two evaluation points behind one
   gate**, rather than the second gate PRD-07 AC6/AC18 and ADR-018 Decision 1 forbid?
2. **What is the persisted shape** of a map, of its condition set (AC17's extensibility is a
   criterion the Reviewer checks against the shipped model), and of the proxy's expected
   incoming structure — which AC8 requires to be first-class, map-independent, and the single
   representation #9 writes into and #12 compares against?
3. **Where is the per-event mapping outcome recorded** (AC16's three attributable outcomes and
   AC22's failure), given that ADR-018 Decision 5 forbids snapshotting configuration onto an
   event, delivery or job?
4. **Does a scheduled retry re-apply mapping**, or re-send the payload already reshaped for
   that delivery (PRD-08 AC24 states the user-visible consequence and routes the mechanism
   here)? And **what must happen to a dispatch whose map cannot be applied**, so that AC22's
   "loses nothing, ships nothing partial" holds without a branching step?

## Decision

**(1) Mapping sits on both ADR-018 evaluation points. That is two evaluation points behind
one gate — not a second gate, and not a third kind of place.**

| Concern | Gate | Where |
|---|---|---|
| **Reshaping** — the behaviour that changes what is delivered | **Composition-time, structural** — the step is not in the composed list, so it cannot run | `PipelineFactory::stepsFor()`, at the reserved `#8` position: after `// #9 NormalizeStep`, before `CaptureDispatchedStep` |
| **Map configuration** — read outside the pipeline (authoring, events surface, later #9/#12) | **Resolution-time** — the resolver establishes `mode === Enhanced` before treating any map as in force | `App\Services\MappingPolicy` |

Both read the same `proxies.mode` enum directly. No new attribute, no per-capability
sub-toggle (PRD-07 AC23), no inference from the presence of a map, a `dispatched_payloads`
row, or an `event_mappings` row. ADR-018's constraint is on the **kinds** of place `mode` may
be read ("exactly the two kinds of place named in Decision 1; a third requires superseding this
ADR") — mapping adds no third kind, so ADR-018 needs no supersession. After #8 there are three
named evaluation points of two kinds: `PipelineFactory::stepsFor()`, `RetryPolicy`, and
`MappingPolicy`.

**(2) `App\Services\MappingPolicy` is the single resolver, and the only reader of the mapping
configuration tables.** It mirrors `RetryPolicy`'s shape exactly (ADR-018 Decision 2), keeping
"a Simple proxy's preserved maps never present as in force" a property **one class owns**
rather than a discipline every consumer remembers:

- `configuredMapsFor(Proxy): MapSet` — the gated reader. Enhanced ⇒ the proxy's conditional
  maps in explicit order plus its default map; **Simple ⇒ an empty set, whatever the rows
  hold** (PRD-08 AC4/AC5/AC6). The only place `proxy_maps` / `proxy_map_conditions` are read
  to decide what governs a proxy.
- `selectFor(Proxy, ?array $payload): MapSelection` — routes through `configuredMapsFor()`, so
  it inherits the gate with no branch of its own. Walks the order, first match wins, else the
  default, else none (AC13/AC14/AC15).
- `expectedStructureFor(Proxy): ?ProxyExpectedStructure` — **ungated, deliberately** (Decision
  3).

**One sanctioned bypass, named here so it is auditable, and confined to one class.** The
authoring surface must show a Simple proxy's preserved maps — design-08 flagged call 1,
accepted by the Product Manager, and PRD-08 AC6's own "the authoring surface is a **write**
surface". `ProxyMapController` therefore reads maps from the proxy relation directly rather
than through the gate. That is the exact shape of #7's single carve-out (`ProxyFormResource`
for the Edit form), and it is bounded the same way: **no read surface anywhere emits map
configuration.** Show, Index, the events surface and the delivery surfaces emit none.

**(3) The expected incoming structure carries no mode gate, and that is a deliberate narrowing
of ADR-018 Decision 6's recipe.** The gate exists to stop dormant configuration governing a
Simple proxy's events. The expected structure **governs no event in either mode**: it drives
the editor's autocomplete and validation (AC9) and is what #12 will later compare against. There
is nothing for a gate to suppress, and gating it would make a Simple proxy's authoring surface
lie about what the member has established — the inverse of AC6. Later capabilities that *do*
read it behaviourally (#12's change detection) bring their own gate for their own behaviour,
under Decision 6 unchanged.

**(4) Maps are per-proxy configuration in their own tables — additive, with no change to any
existing table.** ADR-002's "enhanced config attaches as its own config", applied literally.
Four new tables (§ Impact); **zero columns added to, removed from, or altered on any existing
table; no index change on any existing table; no value added to any existing enum column; no
backfill and no data migration.**

**(5) A map's selection condition is a set of explicitly-operatored, typed conditions
(AC12/AC17).** `proxy_map_conditions` is a separate table so a map's conditions are a **set at
the persistence layer**, at count one exactly as they would be at count two. Each row carries
`path`, `operator` and `value`/`value_type` as **separate named columns**. The matching contract
is *"does this condition match this payload"*, with the operator selecting the comparison:

```
ProxyMapCondition::matches(array $payload): bool
    -> resolve $this->path into PathLookup{found: bool, value: mixed}   // object keys only
    -> $this->operator->matches($lookup, $this->typedValue())           // enum-dispatched
```

`operator` is **`string(32)` cast to a backed PHP enum, not a MySQL `enum(...)` column** — a
deliberate departure from the house column precedent (`deliveries.kind`, `fifo_dispatches.status`)
whose whole purpose is AC17(d): adding `one-of`, `not-equals` or `exists` later is then one enum
case, one comparator arm, one `SelectItem` and one validation rule — **no migration, no change to
the selection contract, no change to how a condition is rendered**. `value_type` is stored the
same way for the same reason. **A map with no condition is not a conditional map** (AC12's last
bullet) — it is the proxy's default, or it is never selected; the model expresses exactly those
three states and no fourth.

**At most one default map per proxy is enforced by the database, natively**:
`proxy_maps.is_default` is `boolean NULL` under `UNIQUE(proxy_id, is_default)`. Only `true` or
`NULL` is ever stored; MySQL and SQLite both ignore NULLs in unique indexes, so this is a partial
unique index without needing one. AC13's "at most one" and AC16's determinism therefore cannot be
violated by a race between two members.

**Explicit order, with a total tie-break.** `proxy_maps.position` is a nullable
`unsignedInteger`, non-null exactly for conditional maps, indexed `(proxy_id, position)` —
**not unique**. Evaluation orders by `position ASC, id ASC`. The `id` tie-break makes the walk
**total** and therefore deterministic (AC16) even if two rows ever share a position, and it makes
an up/down swap a plain two-row update rather than a dance around a unique index.

**(6) A map's output structure is persisted as a structured JSON document in which a reference
to an incoming field is an object with the single reserved key `$from`.** For example:

```json
{ "customer": { "$from": "data.object.customer" }, "source": "webhook-proxy" }
```

`{{path}}` survives only as **what the Raw JSON textarea renders and parses** — a presentation
encoding, never the stored form. This is the call design-08 flagged call 6 delegated here, and
it honours that call's binding UX contract in full (Builder and Raw JSON describe the same map;
Raw JSON carries a literal-vs-reference distinction; the editor validates before Save). A
structured document needs no template engine and no escaping rule, and it cannot be confused with
a literal string that happens to contain braces. **A value is either a literal or a whole-value
reference; there is no interpolation** — string interpolation is the first step toward the
expression language AC2/AC30 forbid, and no criterion or user story asks for it. It stays additive
later.

**(7) The per-event mapping outcome is recorded as a historical fact, in `event_mappings`, and is
never read back to decide anything.** One row per received event (`UNIQUE(webhook_event_id)`),
written by `MapStep` for every enhanced-mode event before it hands on, carrying `outcome`
(`conditional` | `default` | `none` | `failed`), the applied map's id (`nullOnDelete`) and its
**name at application time**, so AC16's "a conditional map, **named**" survives the map's later
deletion. A replay overwrites it — **last dispatch wins**, exactly as `dispatched_payloads` (also
`UNIQUE(webhook_event_id)`) already behaves for the bytes this row describes.

This does **not** breach ADR-018 Decision 5. Decision 5 forbids snapshotting **configuration**
onto an event, job, delivery or attempt so that a stale copy can govern later behaviour. An
outcome record is the opposite: it says what *did* happen, in the same class as
`delivery_attempts.http_status` or `dispatched_payloads.byte_size`. The binding invariant that
keeps the distinction real:

> **`event_mappings` is write-only from the pipeline's perspective.** Nothing in selection,
> application, delivery, retry, replay, GC or the FIFO machinery reads it to decide anything.
> It is read only by read surfaces, to report.

**Absence is meaningful and needs no backfill.** No row means no map was applied — truthful for
a Simple proxy, for an event processed before #8 shipped, and for a proxy that has no maps. That
is design-08 correction **C8**'s preferred resolution, delivered without touching a single
historical row.

**(8) A scheduled retry re-sends the recorded dispatched output. It does not re-select and does
not re-map. A replay does both.** This is the shipped mechanism, unchanged: `RetryDelivery`
resolves its bytes through `StoredPayloadLookup::dispatchedBytesFor()` (ADR-013 Decision 3,
ADR-015 Decision 1), while a replay mints a new `dispatch_uuid` and re-runs
`ProcessIngestedWebhook` over the whole pipeline from the raw capture, under the proxy's current
configuration (PRD-06 AC9–AC11, ADR-017).

It is not merely the incumbent option — **the alternative is excluded by PRD-08's own criteria.**
If a retry re-mapped, the bytes sent on attempt 3 would differ from `dispatched_payloads.body`,
falsifying **AC20** ("what is stored as dispatched is what was actually sent") and ADR-013
Decision 5 ("nothing is sent that was not first recorded"). Restoring truth would mean rewriting
the dispatched row mid-retention — a mutation inside PRD-05's contract that **AC19** forbids.
The user-visible consequence, stated rather than discovered: **a retry after a map edit re-sends
the old shape; a replay after a map edit sends the new shape.** That is coherent — a retry
continues one dispatch, a replay creates a new one — and PRD-08 AC24 already rules mixed
treatment across an event's history a normal outcome, not a fault.

Mapping runs **once per event per dispatch, never once per destination** (AC18/R3): `MapStep`
mutates `$ctx->payload` once inside the pipeline run and `DeliverStep` builds every
`DeliveryUnit` from that same value. `UNIQUE(webhook_event_id)` on `event_mappings` makes it a
checkable invariant rather than a convention.

The AC15 no-map path needs **no special handling** anywhere. On retry, `$ctx->payload` equalled
`$ctx->rawBody`, so `dispatched_payloads.body` is NULL and `dispatchedBytesFor()` returns the raw
bytes — the same bytes attempt 1 sent. On replay, selection simply runs again against current
configuration. Zero branches.

**(9) A mapping failure short-circuits before `CaptureDispatchedStep` and `DeliverStep`, and
terminalizes the dispatch's non-terminal deliveries.** On failure `MapStep` records
`outcome = failed` with a reason summary, compare-and-sets every `deliveries` row of this
`dispatch_uuid` from `pending`/`retrying` to `failed` with `next_attempt_at = null`, emits
`DeliveryExhausted` once per affected row, and returns `$ctx` **without** calling `$next`. No
HTTP send occurs, no `dispatched_payloads` row claims something was sent, and **no
`delivery_attempts` row is written — nothing was attempted**. `$ctx->payload` is assigned only on
complete success, so a partial reshape can never reach the context, let alone a destination
(AC22).

Both halves reuse shapes already shipped: the short-circuit-without-`$next` is
`CaptureDispatchedStep`'s cleaned-parent path; the terminalize-with-no-attempt-row is
`RetryDelivery::terminalizeCleaned()`. No branching step, no parallel path, no new domain event
(ADR-003 unchanged).

**The terminalization is mandatory, not tidy.** A bare short-circuit strands the dispatch's
`pending` deliveries, and on a FIFO proxy `AdvanceProxyFifoQueue::settleOrHold()` then moves the
`fifo_dispatches` row to `awaiting_retry` — **with no lease and no retry schedule, so nothing
will ever settle it**: `SweepStalledFifoDispatches` pass (b) skips the proxy because a held row
exists, and pass (c) will not release it because the deliveries are non-terminal. The proxy's
FIFO line stalls permanently, and GC hold **H2** (`no fifo_dispatches row with a status other
than settled`) **has no age escape**, so every one of that proxy's expired payloads becomes
immortal — the unbounded hold rejected by name three times (ADR-012 § Alternatives, ADR-015
Decision 7, ADR-016 Decision 2) and a direct PRD-05 AC6 violation. Terminalizing closes it:
`settleOrHold` finds every delivery terminal, settles the row and advances the line; H2 and H5
release; the event collects on its ordinary schedule.

**(10) ADR-013 needs no change under routine divergence.** Its divergence test is a **content**
gate, not a retention gate, and #8 is the case its own Decision 7 and Revision A already named
("divergence begins at #9 … and again at #8 … the moment any transform seam is filled it starts
recording real divergence with no schema or code change here"). The volume consequence is the
trade-off the Project Owner **already accepted** at Q-05-02(b)/PRD-05; Revision A deferred its
materialisation, and #8 lands it. Retention mechanics are untouched and cannot be reached by
divergence: GC selects by event age under holds H0–H5, and `PurgeExpiredPayloads::eraseOne()`
already nulls `dispatched_payloads.body` unconditionally, with no `body IS NOT NULL` predicate.
Per-event GC cost is identical; only the bytes reclaimed change. AC19 and AC20 both hold as
written.

## Relationship to ADR-018 — no position superseded, one point for the Owner

Nothing in ADR-018 is reversed. Decisions 1, 2, 4 and 5 are applied here verbatim, and Decision
6's recipe is followed for the thing it was written for: mapping adds a step at its reserved
position **and** per-proxy configuration in its own tables **with** its own single resolver
carrying the Decision-2 gate.

**The one interpretive point, surfaced rather than buried.** Decision 6 says per-proxy
configuration gets "its own single resolver **repeating the Decision-2 gate**". Decision 3 above
exempts the **expected incoming structure** from that gate, on the ground that the gate exists to
stop dormant configuration **governing events**, and the expected structure governs none in either
mode — it is authoring metadata, not behaviour. I read that as applying Decision 6 to what it was
about, not narrowing it; the Owner may read it as a narrowing. **If the Owner prefers the literal
reading, gating `expectedStructureFor()` is a one-line change** and the only visible consequence
is that a Simple proxy's authoring surface would stop showing the structure the member
established — which I believe AC6's write-surface rule and design-08's accepted flagged call 1
argue against. Either way this is the Owner's to settle, and it is listed as an Owner-flag item
in `plan-08`.

## Alternatives

- **Gate mapping structurally only (no resolver).** Sufficient for delivery — an uncomposed
  `MapStep` cannot run — but it leaves AC6's "a Simple proxy's preserved maps never present as in
  force" to every consumer's discipline, which is the exact failure ADR-018 was written to
  prevent (`ProxyResource:49-50`). Rejected.
- **Gate at each call site.** Reproduces the gate per consumer; correctness degrades with every
  new consumer, and #9/#12 inherit a pattern that scales badly. ADR-018 rejected this for retry;
  rejected here for the same reason.
- **A per-capability "mapping enabled" toggle.** Forbidden by PRD-07 AC23 and PRD-08 AC4.
  Rejected.
- **Store the map's output as `{{path}}` template text.** Needs a parser and an escaping rule for
  literals containing braces, and invites interpolation and then expressions — the slope AC2/AC30
  fence off. Rejected; `{{path}}` is kept where it belongs, in the textarea.
- **A MySQL `json` column for `output` / `fields`.** Rejected: a MySQL `json` column can never
  carry Laravel's `encrypted*` cast (MySQL validates JSON on write; the cast writes a base64
  envelope ⇒ error 3140 on every write — the `webhook_events.headers` lesson). If #10 ever rules
  that member-typed literals must be encrypted, a `json` column forces a type change plus a
  drop-and-re-add of live rows. `longText` with an `array` cast costs nothing today and keeps that
  door open. Validation, not the column type, enforces well-formedness.
- **A MySQL `enum(...)` column for `operator`.** The house precedent, but it makes adding an
  operator a schema change, which is precisely what AC17(d)/(e) ask to avoid. Rejected.
- **`UNIQUE(proxy_id, position)` on the conditional order.** Makes an up/down swap need a
  temporary value, for a guarantee the `position ASC, id ASC` tie-break already delivers.
  Rejected.
- **Record the mapping outcome on `dispatched_payloads`.** Tempting — it is already one row per
  enhanced event, written by the adjacent step. But the **failure** case never reaches that step,
  so `MapStep` would have to write into a table `CaptureDispatchedStep` owns, and the resulting
  row (`body IS NULL`, parent not cleaned) would mean "output == input" under ADR-013 Decision 3's
  **binding** invariant while nothing was dispatched at all. Rejected — a separate table keeps
  ADR-013 Decision 3 true and undivided.
- **Record the outcome on `deliveries`.** N identical rows per dispatch, on a per-destination
  record — denormalised, and it hints at per-destination mapping, which AC29 forbids product-wide.
  Rejected.
- **Record the outcome per dispatch (`UNIQUE(dispatch_uuid)`) rather than per event.** Would let
  the event detail page show a different outcome per delivery group. Rejected as more than the
  criteria ask for: AC16 asks which outcome occurred for **a received event**, design-08 Screen 7
  specifies **one** Mapping row in the event's Details card, and `dispatched_payloads` — the store
  this row describes — is already one-per-event, last-dispatch-wins. Matching it keeps the two
  records from disagreeing. Per-group attribution stays a pure presentation addition later:
  `dispatch_uuid` is carried on the row for exactly that upgrade path.
- **Persist the sample payload that established the expected structure**, so the editor's Preview
  can render real values on a later visit. Rejected: a sample taken from a received event is
  payload content, and storing it in a configuration table would create an at-rest copy that
  **never expires**, outside the PRD-05 retention contract AC19 forbids changing and against
  ADR-012's whole rationale. Only the derived path/type list and the provenance descriptors are
  persisted. Design-08 Screen 6 already specifies the no-sample rendering; restoring real-value
  preview later, by fetching a retained event through the existing permission-gated
  fetch-on-reveal endpoint, is additive.
- **Snapshot the selected map (or its output document) onto the event, delivery or job.** Would
  make "which map governed this dispatch" self-contained and survive a map edit, but it is
  ADR-018 Decision 5's rejected shape, it duplicates configuration into the retention contract,
  and no criterion requires it — AC24 rules mixed treatment normal instead. Rejected.
- **Re-apply mapping on every retry attempt.** Excluded by AC19 + AC20 together (Decision 8).
  Rejected.
- **Let a mapping failure leave its deliveries `pending`.** Rejected — it stalls a FIFO line
  permanently and immortalizes payloads under hold H2 (Decision 9).
- **Treat an undecodable body as a mapping failure.** Rejected: it would make every non-JSON
  event a fault, contradicting AC21 and AC31, and it would fight #9 rather than leaving its seam
  clean. An undecodable body selects no map (`outcome = none`) and is delivered unreshaped —
  AC15's ruled behaviour. The day `NormalizeStep` lands, those bodies decode and start matching,
  with no change to `MapStep`.
- **Emit `null` for a referenced-but-absent input field.** Rejected in favour of **omitting** the
  output field: `null` is a value a destination may read as an explicit clear, whereas omission is
  the JSON-native "no value". Either satisfies AC21's "defined and stable"; omission is the safer
  default.
- **Ship operators beyond `equals` in the first pass.** AC17(e) delegates this. Rejected for the
  first pass — each additional operator must be *completely* specified (absent-key, case
  sensitivity, type semantics), presented in the UI and tested, for a capability no user story
  asks for; and Decisions 5's model makes adding one later genuinely cheap, which is the condition
  AC17(e) attaches.

## Reasoning

- **"Both gates" is the honest answer, and it costs nothing.** The two gates protect different
  things: composition protects **delivery** (a Simple proxy cannot reshape), resolution protects
  **presentation and every non-pipeline reader** (a Simple proxy's maps never read as in force).
  Neither substitutes for the other, and both read the one `mode` enum, so PRD-07 AC18's "an
  addition to the governed set, not a change to the model" stays true by construction.
- **The model *is* the criterion, wherever it can be.** AC12's last bullet ("a map with no
  condition is either the default or is never selected") is the three states the schema expresses.
  AC13's "at most one default" is a unique index. AC17(b)'s "a set even at count one" is a table.
  AC18's "once per event, not per destination" is `UNIQUE(webhook_event_id)`. AC16's determinism
  is a total sort key. Each is then something the Reviewer checks against the shipped model rather
  than against intention, which is what AC17 explicitly asks for.
- **Additive-only is worth engineering for.** #8 adds four tables and changes none — no altered
  column, no widened enum, no backfill, nothing to roll back but a `dropIfExists`. That is what
  makes the Owner's data-model gate a single, reversible decision rather than a migration risk on
  live payload tables.
- **The failure path is thin by construction, and that is the point.** With no expressions, no
  lookups and no loops, almost nothing can fail at apply time — a referenced-but-absent field is a
  defined outcome (AC21), an undecodable body is `none` (AC15), unexpected extra properties are
  ignored. What remains is a structurally invalid persisted map, or an output-path collision the
  Builder's validation missed. AC22 must still hold and be tested, but a narrow, well-defined
  failure surface is a feature, not a gap.
- **The FIFO/H2 finding is why this ADR exists rather than a note in the plan.** The obvious
  implementation of "don't deliver" is a silent short-circuit, and it would have stalled a FIFO
  line forever and made a proxy's payloads immortal — an outcome three prior ADRs each rejected by
  name. Fixing it needs a rule, not a habit.
- **Retry-vs-replay had to be ruled, not left implicit.** Both are defensible in the abstract; only
  one survives AC19 and AC20 together. Stating it means the events surface, the docs and the
  member's expectations agree.

## Impact

### Data-model change / Owner flag (✋) — four new tables, **no change to any existing table**

**1. `proxy_maps`** — one row per map (AC1, AC2, AC13, AC14).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → `teams`, `constrained()` (restrict) | team-scoped like every sibling |
| `proxy_id` | FK → `proxies`, `constrained()` (restrict) | a map is reachable only through its proxy (AC7) |
| `name` | `string(100)` | member-supplied, unique within the proxy (a design-08 decision, not an AC1 mandate) |
| `is_default` | `boolean NULL` | **only `true` or `NULL` is ever stored** — see `UNIQUE(proxy_id, is_default)` |
| `position` | `unsignedInteger NULL` | non-null exactly for conditional maps; explicit member-controlled order (AC14) |
| `output` | `longText`, cast `array` | the structured output document (Decision 6). **Not `json`** — see § Alternatives |
| `created_at` / `updated_at` | timestamps | |
| indexes | `UNIQUE(proxy_id, name)`, `UNIQUE(proxy_id, is_default)`, `(proxy_id, position)` | |

No `created_by`: AC3 gates on the **proxy's** update permission, ownership axis included, which
is evaluated against `proxies.created_by` by the existing `ProxyPolicy`. No new permission, no
new policy class, no soft delete, no version history (AC33).

**2. `proxy_map_conditions`** — the condition set (AC12, AC17).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `proxy_map_id` | FK → `proxy_maps`, `constrained()->cascadeOnDelete()` | no life without its map |
| `path` | `string(255)` | dot notation, **object keys only** (AC12) |
| `operator` | `string(32)`, cast `MapConditionOperator` | explicit, never implied (AC17a). Not a MySQL `enum` — see § Alternatives |
| `value` | `longText` | the comparison literal, as text |
| `value_type` | `string(16)`, cast `MapValueType` | `string` \| `number` \| `boolean` — AC12's typed comparison |
| `created_at` / `updated_at` | timestamps | |
| indexes | the FK index | **no** `UNIQUE(proxy_map_id, path)` — two conditions on one path stay legitimate once AC17(f) is decided |

**3. `proxy_expected_structures`** — one row per proxy (AC8, AC9, AC10).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → `teams`, `constrained()` | |
| `proxy_id` | FK → `proxies`, `constrained()`, **UNIQUE** | one per proxy; first-class, map-independent (AC8) |
| `fields` | `longText`, cast `array` | the derived flattened list: `[{path, type}, …]`. **Structure only — never sample payload bytes** |
| `source` | `string(16)`, cast `ExpectedStructureSource` | `received_event` \| `sample` — the Screen 3 provenance caption |
| `established_at` | timestamp | |
| `created_at` / `updated_at` | timestamps | |

A separate table rather than columns on `proxies`, deliberately: `proxies` is loaded on **every
ingest** (`ProcessIngestedWebhook`'s `Proxy::withTrashed()->findOrFail`), and a large text column
there would ride the hot path for a value the pipeline never reads.

**4. `event_mappings`** — the recorded outcome (AC16, AC22; Decision 7).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → `teams`, `constrained()` | set explicitly on the worker path (no current team) |
| `proxy_id` | FK → `proxies`, `constrained()` | |
| `webhook_event_id` | FK → `webhook_events`, `constrained()->cascadeOnDelete()`, **UNIQUE** | one per event (AC18); `updateOrCreate` makes the write idempotent under redelivery |
| `dispatch_uuid` | `uuid` | which dispatch produced this outcome; descriptor only |
| `proxy_map_id` | FK → `proxy_maps` `NULL`, `nullOnDelete()` | design-08 Flow G: a since-deleted map renders as plain text |
| `map_name` | `string(100) NULL` | the name **at application time**, so AC16's "named" survives deletion |
| `outcome` | `string(16)`, cast `MappingOutcome` | `conditional` \| `default` \| `none` \| `failed` |
| `failure_reason` | `string(250) NULL` | summary only — **never payload content** (`delivery_attempts.error_summary` precedent) |
| `applied_at` | timestamp | |
| `created_at` / `updated_at` | timestamps | |
| indexes | `UNIQUE(webhook_event_id)`, `(team_id, created_at)` | sibling parity |

Carries **no** payload content and **no** retention state: it is a descriptor, in the same class
as `dispatched_payloads.byte_size`, and survives erasure untouched (PRD-05 AC6). `cascadeOnDelete`
is orphan prevention only — nothing is deleted on expiry (ADR-012).

**No backfill.** Events processed before #8 simply have no row, which renders as "No map
applied — delivered unreshaped" (design-08 **C8**) and is the correct historical fact.

### Security

- **New at-rest surface: none for payload content.** No new payload copy is created. The expected
  structure stores **paths and types**, never sample bytes (§ Alternatives), and `event_mappings`
  stores identifiers and a bounded reason summary.
- **One assessment the Owner should see, attached to the data-model gate.** `proxy_maps.output`
  and `proxy_map_conditions.value` hold **member-typed literals in plaintext**. A member could put
  a shared secret in a fixed output value (e.g. a token a destination expects). That is the same
  class of exposure `destinations.url` (which may carry a token in its query string) and
  `proxies.response_body` already carry today, and it is configuration the member typed, not
  captured traffic — so this ADR treats it as within the existing envelope rather than as a new
  encryption obligation. It is an explicit **input to #10**, whose sensitive-data policy may rule
  otherwise. Both columns are `longText` precisely so that ruling costs a cast, not a type change.
- No new route class, permission, role or egress path. Authoring is gated by the existing
  `ProxyPolicy::update` (ownership axis included) and viewing by `ProxyPolicy::view` (AC3).
- `MapStep` logs identifiers only — never payload content, never a map's literals
  (`docs/standards/coding.md`'s never-log list, binding).

### Easier

- **#9 attaches with no change here.** `NormalizeStep` sits before `MapStep` in the same reserved
  front stage and mutates the same `$ctx->payload`; the "undecodable ⇒ `none`, delivered
  unreshaped" rule means non-JSON traffic passes through harmlessly today and starts matching the
  same maps, in the same JSON representation, the day #9 lands (AC27).
- **#12 has one representation to compare against** — `proxy_expected_structures`, read through
  the same `MappingPolicy` (AC28).
- **#14 runs the real path** — a test payload driving `ProcessIngestedWebhook` over a real context
  exercises the same selection and application code as live traffic. It inherits
  `CaptureDispatchedStep`'s existing need for a real `webhook_events` row; `MapStep` adds no new
  constraint.
- **Attribution is queryable** without joining payload tables, and `#11` gets a per-map outcome
  count for free should it want one.

### Constrained

- `mode` may still be read behaviourally only in the two **kinds** of place ADR-018 Decision 1
  names. After #8 the named points are `PipelineFactory::stepsFor()`, `RetryPolicy` and
  `MappingPolicy`; a fourth requires an ADR.
- **No consumer may read `proxy_maps`, `proxy_map_conditions` or `proxy_expected_structures`
  outside `MappingPolicy`**, with the single named exception of `ProxyMapController`, the
  authoring surface (Decision 2). No **read** surface may emit map configuration at all.
- **`event_mappings` is never read to decide behaviour** (Decision 7's binding invariant).
- `MapStep` must stay **after** the `#9 NormalizeStep` seam and **before** `CaptureDispatchedStep`,
  must assign `$ctx->payload` only on complete success, and must never mutate `$ctx->rawBody`
  (AC19/R2).
- A map's output document must remain declarative: no expression, no interpolation, no lookup, no
  combination operator (AC2, AC30, AC17(f)).
- `MapStep` resolves its `webhook_events` row by the UNIQUE-indexed `ingest_id`, following
  ADR-013's rejection of widening `PipelineContext` with a `webhookEventId`. `PipelineContext` is
  **unchanged**.

### Within stack

No new Composer or pnpm dependency and no stack change: Eloquent, Laravel migrations, the native
`Illuminate\Pipeline\Pipeline`, `lorisleiva/laravel-actions` `AsObject` (ADR-007), and PHP's own
`json_decode`/`json_encode`. The frontend adds no npm package and no generated `ui/*` primitive
(design-08 Handoff), only two `@lucide/vue` icons from a library already installed.
