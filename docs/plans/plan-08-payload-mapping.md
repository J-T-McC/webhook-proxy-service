# Technical Plan: Payload mapping / reshaping — item #8

- **Status:** **Approved (Principal-Engineer self-certified) — except the two items at
  *Handoff → Owner-approval flags (✋)*, which are the Project Owner's to rule on.** Unlike #7,
  this plan **cannot** be self-certified in full: #8 plainly adds persisted entities, and
  `CLAUDE.md` makes a data-model change an Owner-approval gate that no delegated plan gate
  covers. Everything else in this plan is self-certified and needs no further sign-off. The
  complete change set is enumerated once, in one place, so the Owner can rule on all of it at
  once — see **§ Data Model** and **§ Owner-approval flags (✋)**.
- **Author:** Principal Engineer
- **Date:** 2026-08-26
- **PRD:** `docs/product/prd-08-payload-mapping.md` — **Approved** (Project Owner, 2026-08-26),
  34 acceptance criteria, approved *as written*, which ratified **D-08-1..4**. Numbering frozen.
- **Design spec:** `docs/design/design-08-payload-mapping.md` — **Approved** (Product Manager,
  2026-08-26; design gate delegated per `CLAUDE.md`) **with nine required corrections, all
  landed by the Designer the same day**. The spec says its **approval note governs over the
  spec body**; this plan is written against the note first and the corrected body second. This
  plan builds the surfaces the spec specifies and redesigns none of them.
- **ADRs:** **ADR-019** — *payload mapping: composed step, resolved configuration, and the
  recorded outcome* — **Proposed, pending Project Owner approval**
  (`docs/architecture/adr-019-payload-mapping-composition-and-configuration.md`). It carries the
  data-model gate and one interpretive point about ADR-018 Decision 6. **ADR-018** (Accepted),
  **ADR-013** (Accepted, **unchanged** by this item), ADR-002/010/012/014/015/016/017 all
  binding and unamended.
- **Question resolved here:** `docs/questions/prd-08-q-08-03-mapping-composition-and-expected-structure.md`
  — **RESOLVED** (Principal Engineer, 2026-08-26), all six items; ADR-019 is the mechanism
  record, exactly as Q-07-02 → ADR-018.
- **Approved by / date:** Principal Engineer, 2026-08-26 — **partial**, see Status.

## Overview

#8 fills the seam `PipelineFactory` has reserved since #1. It adds **one pipeline step**, **one
resolver service**, **four tables**, **one nested route group**, and **one new page plus one
editor**; it changes **no existing table**, no retention behaviour, no retry or replay
semantics, no processing mode, and no mode semantics.

**(a) Reshaping is a composed step (AC4, AC18; ADR-019 Decision 1).** `MapStep` is uncommented
at its reserved position in `PipelineFactory::stepsFor()`'s `ProxyMode::Enhanced` branch —
after `// #9 NormalizeStep`, before `CaptureDispatchedStep`. A Simple proxy has no `MapStep` in
its composed list, so nothing can reshape. That is PRD-07 AC18's extensibility discharged
structurally: the governed set becomes three, the `mode` attribute is untouched, and no second
gate, sub-toggle or new toggle surface appears.

**(b) Map configuration is gated at resolution (AC5, AC6; ADR-019 Decision 2).**
`App\Services\MappingPolicy` mirrors `RetryPolicy` exactly: `configuredMapsFor()` establishes
`mode === Enhanced` before treating any map as in force, so a Simple proxy resolves an **empty**
map set whatever its rows hold, and every consumer inherits the gate with no branch of its own.
Preservation across a downgrade (AC5/D-08-1) is achieved, as at #7, by **not writing** — a mode
save never touches the mapping tables at all, because mode and mapping live on different forms
and different endpoints.

**(c) Selection is a walk over an explicit order (AC11–AC17).** `MappingPolicy::selectFor()`
walks the proxy's conditional maps by `position ASC, id ASC`, stops at the first condition that
matches, else applies the default map, else selects none. Conditions are a **set** in their own
table, each carrying `path`, `operator` and `value`/`value_type` as separate named columns —
AC17(b) as a schema fact rather than an intention.

**(d) The proxy's expected incoming structure is its own first-class record (AC8–AC10).**
`proxy_expected_structures`, one row per proxy, holding the **derived path/type list and its
provenance — never sample payload bytes**. That constraint is not a preference: a sample taken
from a received event is payload content, and persisting it in a configuration table would
create an at-rest copy that never expires, outside the PRD-05 retention contract AC19 forbids
changing (§ *Technical rulings* 4).

**(e) The outcome is recorded as a historical fact (AC16, AC22; ADR-019 Decision 7).**
`event_mappings`, one row per received event, written by `MapStep`, carrying which of four
outcomes occurred and which map ran. It is **write-only from the pipeline's perspective** —
nothing reads it to decide anything — which is what keeps ADR-018 Decision 5's no-snapshot rule
true. Absence of a row means "no map applied", which is truthful for Simple proxies and for every
event processed before #8, so design-08 correction **C8** is satisfied with **no backfill**.

**(f) A mapping failure delivers nothing and strands nothing (AC22; ADR-019 Decision 9).**
`MapStep` records the failure, terminalizes the dispatch's non-terminal deliveries by
compare-and-set, and returns without calling `$next` — so `CaptureDispatchedStep` never records a
dispatch that did not happen and `DeliverStep` never sends. **The terminalization is mandatory,
not tidy**, and finding out why is this plan's most consequential result — see § *Risks* R1.

Nothing changes in `PipelineContext`, `DeliverStep`, `DeliverToDestination`, `RetryDelivery`,
`StoredPayloadLookup`, `PurgeExpiredPayloads`, `RetentionPolicy`, `RetryPolicy`, the ingest
handler, the response contract, capture, erasure, the delivery state machine, the FIFO machinery
or the replay path. `PipelineFactory` changes by **one uncommented line**.

## What is already settled, and by whom

This plan invents nothing. Every requirement question #8 raised is closed.

| Settled | By | What it fixes for this plan |
|---|---|---|
| **Q-08-01 (M1)** — default is a pure fallback; member-controlled explicit order, first match wins, order visible; no match + no default ⇒ unreshaped, recorded as no map applied | Project Owner, 2026-08-26 | AC13/AC14/AC15 are concrete: § *Architecture C* implements the walk and § *Data Model* the order |
| **Q-08-02 (M2)** — one dot-notation path, object keys only; operator explicit and named; case-sensitive typed scalar comparison; absent key never matches, never an error; condition modelled as a set at count one | Project Owner, 2026-08-26 | AC12/AC17: the condition table and `MapConditionOperator` are that ruling as a schema |
| **AC17(e) delegated latitude** — whether any operator beyond `equals` ships is the implementor's | Project Owner, 2026-08-26 | Ruled: `equals` only in the first pass (§ *Technical rulings* 6) |
| **D-08-1..4** ratified by PRD approval | Project Owner, 2026-08-26 | Preservation, truthful presentation, member-established structure, loss-free failure |
| **design-08 flagged call 1** — the mapping surface is reachable and fully editable in **both** Modes, under three binding conditions | Product Manager, 2026-08-26 | § *Architecture F* and § *Validation*: **no `prohibited_if:mode,simple` analogue here**, deliberately |
| **design-08 flagged call 5 + C9** — the Builder/Raw-JSON split is the faithful reading of the vision; **no** code-editor dependency gate travels to the Owner | Product Manager, 2026-08-26 | Recorded as binding "so the Principal Engineer does not re-litigate it". I do not. No new dependency (§ *Dependencies*) |
| **design-08 flagged call 6** — the `{{path}}` token is illustrative UX; the persisted shape is Technical Design's | Product Manager, 2026-08-26 | Ruled: structured `$from` document persisted, `{{path}}` is textarea encoding only (§ *Technical rulings* 3) |
| **design-08 flagged call 8** — no reshaped-payload viewer; AC20's "compare received against sent" is discharged as a property of what is **stored**, not a shipped affordance | Product Manager, 2026-08-26 (Owner **awareness**, blocking nothing) | This plan builds no second viewer and adds no payload read surface |
| **ADR-018** Decisions 1–6 | Accepted, Project Owner, 2026-08-25 | Binding on every section; ADR-019 applies them and supersedes none |
| **ADR-013** Decisions 2/3/5/7 and Revision A | Accepted, Project Owner, 2026-08-05 | **Unchanged by #8** — § *Technical rulings* 5 |

**Where design-08's body and its approval note disagree, the note governs.** Two consequences
this plan implements without re-deciding: the Simple-mode banner plus mode-conditioned evaluation
copy (**C3**) is the entire truthful-presentation mechanism for the authoring page — nothing on
it is disabled by Mode; and **every** mutating control on that page carries the AC3 update
permission gate (**C6**), which is the one gate any action there is conditioned on.

## Architecture

Seven concerns, mapped to ACs. **No change to the ingest handler, the response contract, capture
placement, retention or erasure semantics, the delivery state machine, the FIFO machinery, or the
replay path.**

### A. Composition — the reserved seam, filled (AC4, AC18, AC24; ADR-019 Decision 1)

`PipelineFactory::stepsFor()` gains one line, at `PipelineFactory.php:34`:

```php
if ($proxy->mode === ProxyMode::Enhanced) {
    // $steps[] = VerifyStep::make();            // #10 — verification token (front)
    // $steps[] = NormalizeStep::make();         // #9  — any format -> JSON
    $steps[] = MapStep::make();               // #8  — reshape
    $steps[] = CaptureDispatchedStep::make(); // #5 — persist dispatched output
}
```

The `#9` and `#10` seams stay commented and stay **above** `MapStep`; `CaptureDispatchedStep`
stays **below** it, which is what makes ADR-013's stored output the reshaped payload with no
change to ADR-013 (AC20). The tail-stage `#12 ChangeDetectStep` seam is untouched.

This is the **structural** half of the gate: a Simple proxy's composed list is unchanged
(`[DeliverStep]`), so no map can run for it regardless of what its tables hold (AC4, AC5).

### B. Resolution — `MappingPolicy`, the single resolver and the second half of the gate (AC4, AC6; ADR-019 Decision 2)

```php
final class MappingPolicy
{
    /** Gated. Enhanced ⇒ ordered conditional maps + the default; Simple ⇒ empty, always. */
    public function configuredMapsFor(Proxy $proxy): MapSet;

    /** Routes through configuredMapsFor(), so it inherits the gate. */
    public function selectFor(Proxy $proxy, ?array $payload): MapSelection;

    /** UNGATED, deliberately — authoring metadata that governs no event in either mode. */
    public function expectedStructureFor(Proxy $proxy): ?ProxyExpectedStructure;
}
```

**Binding invariant, mirroring ADR-015/ADR-018's for `RetryPolicy`:** `MappingPolicy` is the
**only** reader of `proxy_maps`, `proxy_map_conditions` and `proxy_expected_structures` — with
exactly one named exception, `ProxyMapController`, the authoring surface, which must show a
Simple proxy's preserved maps (AC6's "the authoring surface is a **write** surface";
design-08 flagged call 1). That is the same single-carve-out shape #7 used for `ProxyFormResource`,
and it is bounded the same way: **no read surface anywhere emits map configuration.** `ProxyResource`
gains **nothing** — Show gets a header button and a caption, not map data — so #7's freshly
repaired read surface stays repaired.

`MapSelection` is a readonly value object `{outcome: MappingOutcome, map: ?ProxyMap}`.

### C. Selection — the walk (AC11–AC16)

Given the decoded payload (or `null` when the body is not decodable JSON):

1. `null` payload ⇒ `MappingOutcome::None`. An undecodable body is **not** a failure — AC21 and
   AC31, and it is what leaves #9's seam clean (§ *Technical rulings* 2).
2. Walk `configuredMapsFor()->conditional()` in `position ASC, id ASC` order. For each map, every
   condition in its set must match (at MVP a set holds exactly one, so this is not an AND ruling —
   AC17(f)/AC30 stay undecided and unbuilt; a single-element `all()` is the smallest expression of
   "the set matched" and precludes nothing).
3. First match wins; **stop**. Later matching maps are not applied (AC11, AC14). Deliberate
   overlap is therefore supported with no save-time rejection and **no runtime multi-match state
   to report**.
4. No conditional match ⇒ the default map if one is designated ⇒ `MappingOutcome::Default`.
5. Otherwise `MappingOutcome::None` — deliver unreshaped (AC15). Not a failure, never reported as
   one.

Condition matching:

```php
ProxyMapCondition::matches(array $payload): bool
    → PathLookup{found, value} = resolve($this->path, $payload)   // object keys only, no indexing
    → $this->operator->matches($lookup, $this->typedValue())      // enum-dispatched comparison
```

`equals` ⇒ `found && $lookup->value === $typedValue` — PHP `===` gives AC12's exact,
case-sensitive, **type-and-value** comparison for free (`42 !== "42"`, a scalar never equals an
array or object). An absent path yields `found = false` ⇒ no match, no error, ever (AC12, AC21).
Passing a `PathLookup` rather than a coerced value is what lets a future `exists` operator ship
with **no change to the selection contract** (AC17(c)/(d)).

**Determinism (AC16)** is a property of the configuration, not of the run: `position ASC, id ASC`
is a **total** order, so the walk is deterministic even if two rows ever shared a position;
nothing in selection reads the clock, the attempt number, the destination, the queue, or the FIFO
claim; and the maps are read live from `$ctx->proxy`, never snapshotted (ADR-018 Decision 5,
extended by ADR-019 Decision 1).

### D. Application — `MapStep` (AC18–AC22)

`MapStep` is an `AsObject` `PipelineStep`, in the shape `CaptureDispatchedStep` established:

1. Decode `$ctx->payload` (`json_decode(..., true)`); `null`/non-array ⇒ treat as undecodable.
2. `$selection = $this->mapping->selectFor($ctx->proxy, $decoded)`.
3. `None` ⇒ record the outcome, `return $next($ctx)` with `$ctx->payload` **untouched** (AC15).
4. Otherwise apply the map with `PayloadMapper` (§ *Services*) into a **new** string. Assign
   `$ctx->payload` **only on complete success** — a partial reshape can never reach the context,
   let alone a destination (AC22).
5. Record the outcome (`conditional` | `default`, with `proxy_map_id` and `map_name`), then
   `return $next($ctx)`.
6. On failure: record `outcome = failed` with a bounded reason summary, **terminalize** the
   dispatch's non-terminal deliveries (§ E), and `return $ctx` — **without** `$next`.

Mapping runs **once per event per dispatch, never per destination** (AC18/R3): `$ctx->payload` is
assigned once and `DeliverStep` builds every `DeliveryUnit` from that same value
(`DeliverStep.php:56`). `UNIQUE(webhook_event_id)` on `event_mappings` makes it checkable.

`$ctx->rawBody` is never touched (AC19/R2). The `webhook_events` row is resolved by the
UNIQUE-indexed `ingest_id`, following ADR-013's rejection of widening `PipelineContext`;
**`PipelineContext` is unchanged**.

No `payload_cleaned_at` guard is added in `MapStep`: `ProcessIngestedWebhook` guards at entry
(`:42`) and `CaptureDispatchedStep` re-checks under a row lock immediately after
(`CaptureDispatchedStep:44-52`), so the only uncovered window is the GC winning a race *between*
the two steps — which `CaptureDispatchedStep` then catches, aborting before any send. Adding a
third check would duplicate a guard without closing anything.

### E. Failure — terminalize, don't strand (AC22; ADR-019 Decision 9)

`App\Actions\TerminalizePendingDeliveries` (`AsObject`), given a `dispatch_uuid`:

- compare-and-set every `deliveries` row of that dispatch from `pending`/`retrying` to `failed`
  with `next_attempt_at = null`, keyed on the prior status — never a blind `save()` (plan-06's
  binding invariant);
- emit `DeliveryExhausted` **once per row the CAS actually affected** (the once-guard shape
  `DeliverToDestination::settleDelivery()` and `RetryDelivery::terminalizeCleaned()` both use);
- write **no** `delivery_attempts` row — nothing was attempted, matching PRD-06 AC17's posture
  literally and `terminalizeCleaned()`'s precedent exactly.

It touches `fifo_dispatches` **not at all**, and does not need to: on a FIFO proxy,
`AdvanceProxyFifoQueue::settleOrHold()` runs after `ProcessIngestedWebhook::run()` returns, finds
every delivery terminal, settles the row and advances the line. On Async there is no such row and
nothing further is required.

Why it is mandatory rather than tidy is § *Risks* R1.

AC22 then holds end to end: the event was captured **before** the pipeline ran (ADR-010, in the
ingest handler, mode-independent) and is unaffected; it stays retained (GC untouched), visible,
and **replayable** — replay pre-creates its **own** delivery rows under a new `dispatch_uuid`, so
terminalizing the original dispatch's rows blocks nothing; the failure is attributable
(`event_mappings`); and nothing partial or incorrect was delivered, because no HTTP send happened
at all.

### F. Authoring surfaces — mode-independent by ruling, permission-gated throughout (AC3, AC6)

The Mapping page and the map editor are **write surfaces**. They render for a Simple proxy, fully
editable, under the non-dismissible Simple-mode `Alert` and the mode-conditioned evaluation copy
(design-08 Flow B step 3, Screen 3 **C3**). Every mutating control — Add / Edit / Delete / reorder,
the editor's Save and Delete, and the expected-structure Update — is gated by
`ProxyPolicy::update` (which already composes the #2 Member ownership rule); viewing is gated by
`ProxyPolicy::view` (**C6**). **No new permission, no new policy class, no new `TeamPermission`
case** (AC3).

**There is deliberately no `prohibited_if:mode,simple` analogue here**, and a reviewer expecting
the ADR-018 Decision 3 shape should read this paragraph. The retry precedent needs a boundary half
because a dormant retry value could otherwise be confused with the **system default that is in
force** for a Simple proxy. Mapping has **no system default**: a Simple proxy reshapes nothing,
full stop. There is therefore nothing a boundary rule would protect, the dormancy guarantee is
carried entirely by composition (§ A) and resolution (§ B), and adding a mode condition to the
authoring endpoints would introduce a gate AC3 does not draw and ADR-018 Decision 6 forbids. This
is the *mechanism* departure the Product Manager's approval note recorded when accepting flagged
call 1; § B's carve-out is its bounded cost.

### G. Read surfaces — one new key, and it is an outcome, not configuration (AC16, AC26)

`WebhookEventResource` gains a single `mapping` key: `null`, or
`{outcome, map_id, map_name}` from `event_mappings`. It is a **historical fact**, so no mode gate
applies — an event that really was reshaped while the proxy was Enhanced still reports so after a
downgrade, which is PRD-07 AC11's mixed treatment, not an AC6 breach. `null` renders design-08
Flow G's "No map applied — delivered unreshaped" (C8).

Two copy strings are superseded, per AC26 and design-08 Screen 1: the create/edit form's
`#mode-help` (**C1**) and the two Show mode-summary captions (**C2**). Both are verbatim from
the spec; this plan changes neither and adds no other user-facing copy about mapping.

## Technical rulings (named, recorded — not silent design)

The house convention: where the PRD or the design left something to Technical Design, the ruling
is stated with its basis, so it is reviewable and so it is clear nothing routed back upstream.

**1. Screen 7's single Mapping row is normative; Flow G step 2 is a non-flagging rule, not a
placement requirement.** design-08 Screen 7 specifies **one** Mapping row in the event's Details
card, "exactly one of four states". Flow G step 2 says a replayed or retried event "may show
different Mapping rows on different delivery groups". Read as a placement requirement the two
conflict; read as what it says — *if* outcomes differ across an event's history, that is normal
and nothing may flag it as inconsistent — they do not, and under this plan's Ruling 5 a **retry
never changes the outcome at all**, so variance can arise only across replays, where
last-dispatch-wins is already `dispatched_payloads`' shipped shape. This plan therefore builds
Screen 7 as written and stores one outcome per event. **No Designer round-trip is needed**, and
per-group attribution stays a pure presentation addition later — `event_mappings.dispatch_uuid` is
carried for exactly that upgrade path.

**2. An undecodable body selects no map; it is never a mapping failure.** AC22 defines a mapping
failure as "a selected map that cannot be applied". A body that is not JSON has no paths, so no
condition matches and no default can be applied to it. Ruling: `outcome = none`, delivered
unreshaped (AC15). Basis: AC21 forbids reporting routine input variation as a fault, AC31 keeps
non-JSON ingestion unsupported until #9, and this is precisely what makes #9 a pure addition —
the day `NormalizeStep` lands ahead of `MapStep`, the same bodies decode and start matching the
same maps with **no change to `MapStep`** (AC27).

**3. The persisted output shape is a structured `$from` document; `{{path}}` is textarea
encoding only.** design-08 flagged call 6 delegated this explicitly. A reference is
`{"$from": "data.object.id"}`; everything else is a literal. Rationale: no template engine, no
escaping rule, and a literal string containing braces can never be misread as a reference. The
spec's binding UX contract is met in full — Builder and Raw JSON describe the same map, Raw JSON
carries a literal-vs-reference distinction, and the editor validates before Save. **No
interpolation**: a value is either a literal or a whole-value reference, because string
interpolation is the first step toward the expression language AC2/AC30 forbid and no criterion
or user story asks for it. One consequence, named rather than left implicit: Screen 5's
toggle-disabled trigger *"a string value mixing literal text and a token"* becomes unreachable;
the disabled state itself remains reachable through nesting, so no specified state disappears.

**4. The expected structure persists the derived field list and its provenance — never the
sample bytes.** A sample chosen from a received event **is payload content**. Persisting it in a
configuration table would create a third at-rest copy that **never expires**, outside PRD-05's
retention contract, against ADR-012's whole rationale and PRD-05 AC6, and AC19 forbids #8
changing any of it. AC8 requires the **structure**, AC9 needs only paths and types for
autocomplete and validation, #9 normalises *into* the structure and #12 compares *against* it —
none of them needs a stored payload. **Consequence, recorded for the Designer and the Product
Manager (no ruling required, blocking nothing):** design-08 Screen 6's *"has a sample"* Preview
state is not reachable after a page reload, so Preview renders the spec's own *"no sample"* state
— incoming-field references shown as their paths, with the copy Screen 6 already specifies. No
state is invented and no copy is written here. If real-value preview is wanted later, the
additive route is to fetch a **retained** event through the existing permission-gated
fetch-on-reveal endpoint (ADR-017) at preview time — which stores nothing and is a design
question then, on its own merits.

**5. A retry re-sends the recorded dispatched output; a replay re-selects and re-maps.** The
shipped mechanism, unchanged, so AC21 ("no retry/replay semantic change") holds literally:
`RetryDelivery` resolves bytes through `StoredPayloadLookup::dispatchedBytesFor()`
(`RetryDelivery.php:73`), while replay mints a new `dispatch_uuid` and re-runs
`ProcessIngestedWebhook` over the whole pipeline. **The alternative is not merely rejected, it is
excluded**: re-mapping on retry would make attempt 3's bytes differ from
`dispatched_payloads.body`, falsifying AC20 and ADR-013 Decision 5, and repairing that would mean
rewriting the dispatched row mid-retention — which AC19 forbids. The user-visible consequence,
stated so nobody discovers it: **a retry after a map edit re-sends the old shape; a replay after
a map edit sends the new shape.** AC24 already rules mixed treatment across an event's history a
normal outcome. The AC15 no-map path needs **no special handling** on either: on retry the
dispatched body is NULL so the raw bytes are re-sent — the same bytes attempt 1 sent; on replay
selection simply runs again. Zero branches.

**6. `equals` is the only operator shipped in the first pass (AC17(e)'s delegated call).** The
condition is that (a)–(d) make additions genuinely cheap; ADR-019 Decision 5 makes them so — one
enum case, one comparator arm, one `SelectItem`, one validation rule, **no migration** (the
column is `string(32)`, not a MySQL `enum`), no contract change, no rendering change. Shipping
more would mean *completely* specifying, presenting and testing each (AC12's absent-key, case
sensitivity and type semantics) for a capability no user story asks for; a half-shipped operator
is a defect by AC17(e)'s own words. `not-equals` in particular would raise overlap frequency,
which is defined behaviour but needless churn now.

**7. A referenced-but-absent input field omits its output field; it never emits `null`.** AC21
requires the outcome be "defined and stable" and leaves the choice open. Ruling: omit. `null` is a
value many destinations read as an explicit clear, whereas omission is the JSON-native "no value";
and omission is trivially stable — the same input always yields the same output. Unexpected
**extra** incoming properties are simply not referenced and are therefore ignored, which is AC21's
other half satisfied by construction.

**8. At most one default per proxy is a database guarantee, not a service convention.**
`proxy_maps.is_default` is `boolean NULL` under `UNIQUE(proxy_id, is_default)`; only `true` or
`NULL` is ever stored, and both MySQL and SQLite ignore NULLs in unique indexes. Two members
racing to designate different defaults cannot both win, so AC13's "at most one" and AC16's
determinism cannot be broken by a race. Chosen over a `proxies.default_proxy_map_id` pointer
specifically to keep **#8 additive-only** — no existing table is touched and no circular FK is
introduced.

## Data Model

> **✋ This whole section is the Project Owner's data-model gate.** It is stated once, in full,
> so the Owner can rule on the complete set at once. Full column-by-column definitions, with the
> reasoning for each choice and every rejected alternative, are in **ADR-019 § Impact**; this is
> the summary the gate is taken against.

### The complete change set — four new tables, and nothing else

| # | Change | Kind |
|---|---|---|
| 1 | **New table `proxy_maps`** — `id`, `team_id` FK, `proxy_id` FK, `name` `string(100)`, `is_default` `boolean NULL`, `position` `unsignedInteger NULL`, `output` `longText` (cast `array`), timestamps. Indexes: `UNIQUE(proxy_id, name)`, `UNIQUE(proxy_id, is_default)`, `(proxy_id, position)` | Additive |
| 2 | **New table `proxy_map_conditions`** — `id`, `proxy_map_id` FK `cascadeOnDelete`, `path` `string(255)`, `operator` `string(32)`, `value` `longText`, `value_type` `string(16)`, timestamps. Index: the FK index only | Additive |
| 3 | **New table `proxy_expected_structures`** — `id`, `team_id` FK, `proxy_id` FK **UNIQUE**, `fields` `longText` (cast `array`), `source` `string(16)`, `established_at` timestamp, timestamps | Additive |
| 4 | **New table `event_mappings`** — `id`, `team_id` FK, `proxy_id` FK, `webhook_event_id` FK `cascadeOnDelete` **UNIQUE**, `dispatch_uuid` uuid, `proxy_map_id` FK `NULL` `nullOnDelete`, `map_name` `string(100) NULL`, `outcome` `string(16)`, `failure_reason` `string(250) NULL`, `applied_at` timestamp, timestamps. Indexes: `UNIQUE(webhook_event_id)`, `(team_id, created_at)` | Additive |

**Explicitly *not* in the change set, verified item by item:**

- **No column added to, removed from, or altered on any existing table** — `proxies`,
  `destinations`, `webhook_events`, `dispatched_payloads`, `deliveries`, `delivery_attempts`,
  `fifo_dispatches`, `teams`, `team_members`, `users` are all untouched.
- **No index added to, changed on, or dropped from any existing table.**
- **No value added to any existing enum column** — `proxies.mode` (AC23: no third mode),
  `proxies.processing_mode` (AC22: no processing-mode change), `deliveries.kind`,
  `deliveries.status`, `delivery_attempts.status`, `fifo_dispatches.status` are all unchanged.
  The four new PHP enums (`MapConditionOperator`, `MapValueType`, `MappingOutcome`,
  `ExpectedStructureSource`) back **new** `string` columns; none extends an existing enum.
- **No backfill, no data migration, no default applied to existing rows.** Events processed
  before #8 have no `event_mappings` row, which is the correct historical fact and renders as
  design-08 **C8** specifies.
- **No `TeamPermission` case, no policy class, no route-model-binding change** (AC3).
- **No retention, GC, window, hold or erasure change** (AC19). `PurgeExpiredPayloads` and
  `RetentionPolicy` are not edited. The four new tables carry **no retention state**: three are
  configuration with the proxy's lifecycle, and `event_mappings` is a descriptor in the same class
  as `dispatched_payloads.byte_size`, which survives erasure by design (PRD-05 AC6).
- **Migrations are MySQL-and-SQLite-clean.** Unlike the payload tables, none of these columns
  needs a raw `ALTER … LONGBLOB` (no `encrypted` cast, so no envelope-size cliff), so all four are
  plain Blueprint migrations that run on the SQLite default as well as on CI's MySQL 8.0.

### Security assessment attached to this gate

- **No new at-rest copy of payload content.** The expected structure stores **paths and types**,
  never sample bytes (§ *Technical rulings* 4); `event_mappings` stores identifiers and a bounded
  reason summary and never payload content.
- **One thing the Owner should see:** `proxy_maps.output` and `proxy_map_conditions.value` hold
  **member-typed literals in plaintext**. A member could put a shared secret in a fixed output
  value. That is the same class of exposure `destinations.url` (which may carry a token in its
  query string) and `proxies.response_body` already carry, and it is configuration the member
  typed rather than captured traffic — so this plan treats it as inside the existing envelope, not
  as a new encryption obligation, and records it as an explicit input to **#10**. Both columns are
  `longText` rather than `json` precisely so that #10 can add an `encrypted` cast later without a
  type change and a drop-and-re-add of live rows (the `webhook_events.headers` lesson).
- **The `APP_PREVIOUS_KEYS` obligation materialises here.** ADR-013's binding three-column rule
  (`webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`) is unchanged, but
  the third column starts holding bytes for the first time once mapping ships (§ *Technical
  rulings* 5 / ADR-019 Decision 10). Awareness, not a new gate — the obligation was accepted at
  #5.

## API

One nested route group, `scopeBindings()` throughout, mirroring `proxies/{proxy}/events` exactly
(`routes/web.php:27-38`). All Inertia, no JSON API.

| Method | Path | Controller | Gate |
|---|---|---|---|
| GET | `proxies/{proxy}/maps` | `ProxyMapController@index` | `view` |
| GET | `proxies/{proxy}/maps/create` | `ProxyMapController@create` | `update` |
| POST | `proxies/{proxy}/maps` | `ProxyMapController@store` | `update` |
| GET | `proxies/{proxy}/maps/{map}/edit` | `ProxyMapController@edit` | `update` |
| PUT | `proxies/{proxy}/maps/{map}` | `ProxyMapController@update` | `update` |
| DELETE | `proxies/{proxy}/maps/{map}` | `ProxyMapController@destroy` | `update` |
| POST | `proxies/{proxy}/maps/{map}/move` | `ProxyMapOrderController@store` (`direction: up\|down`) | `update` |
| PUT | `proxies/{proxy}/expected-structure` | `ProxyExpectedStructureController@update` | `update` |

- `index` returns the proxy (existing `ProxyResource`), the expected structure, the ordered
  conditional maps, the default map, and the "not currently selected" group (design-08 **C7**),
  plus a bounded list of recent **retained** events for Screen 2's picker (identifiers, timestamp
  and byte size only — **never payload content**).
- Screen 2's "choose a received event" derives its field list client-side from the payload fetched
  through the **existing** `proxies/{proxy}/events/{event}/payload` endpoint (ADR-017
  fetch-on-reveal, already permission-gated). **No new payload read surface is added**, and the
  #6/Q-06-02 mask/reveal settlement is untouched (AC33).
- `move` persists immediately and returns `back()` (design-08 Flow C); a boundary move is a 422
  the disabled buttons should make unreachable.
- Every mutating endpoint authorizes through the existing `ProxyPolicy`; a read-permission member
  reaching `index` sees the maps and no mutating affordance (**C6**).
- **Nothing here accepts, returns, or implies a destination** — no per-destination map, condition
  or filter exists anywhere in the surface (AC29).

## Services & Actions

| Component | Kind | Responsibility |
|---|---|---|
| `App\Services\MappingPolicy` | service | The single resolver + the resolution-time gate (§ B). The only reader of the three configuration tables outside `ProxyMapController`. |
| `App\Support\PayloadMapper` | pure class, no DB | Applies one map's output document to one decoded payload and returns the encoded result, or throws. Deterministic, no clock, no I/O, no `config()` — trivially unit-testable, which is what makes AC16/AC21/AC22 cheap to pin. |
| `App\Actions\MapStep` | `AsObject`, `PipelineStep` | Decode → select → apply → record → hand on, or record-fail → terminalize → stop (§ D). |
| `App\Actions\TerminalizePendingDeliveries` | `AsObject` | The CAS + `DeliveryExhausted` once-guard (§ E). |
| `App\Models\ProxyMap`, `ProxyMapCondition`, `ProxyExpectedStructure`, `EventMapping` | Eloquent | Team-scoped like their siblings. `ProxyMap` has `conditions()`; `Proxy` gains `maps()`, `expectedStructure()`. |
| `App\Enums\MapConditionOperator` | backed enum | `equals`; carries `matches(PathLookup, mixed): bool`. AC17(c)'s contract lives here. |
| `App\Enums\MapValueType`, `MappingOutcome`, `ExpectedStructureSource` | backed enums | Typed comparison, the four outcomes, provenance. |
| `App\Http\Resources\ProxyMapResource`, `ProxyExpectedStructureResource` | resources | **Authoring surfaces only.** Every condition is emitted with its `operator` named (AC17a) and the set emitted as an array even at count one (AC17b). |
| `App\Http\Controllers\ProxyMapController`, `ProxyMapOrderController`, `ProxyExpectedStructureController` | controllers | The § *API* table. |

Frontend, all from design-08 and all hand-written compositions over existing primitives (no new
npm package, no new generated `ui/*`): `pages/proxies/maps/{Index,Create,Edit}.vue`,
`components/PathAutocomplete.vue`, the Builder row group (the `DestinationRows.vue` pattern), the
Preview panel, `data/proxyMapConditionOperators.ts` and `data/proxyMapValueTypes.ts` following the
existing `DataOption` convention (design-08 non-blocking note (ii)), and one new row on
`pages/proxies/events/Show.vue`.

## Validation

Form Requests, in the app's existing style; every rule below is an **input bound**, never a
product performance target (AC34).

**Map (store/update):**
- `name` — required, string, `max:100`, unique within the proxy (design decision, server enforced).
- `is_default` — boolean. If true: `conditions` must be **empty** (AC12's last bullet), and any
  existing default for the proxy is cleared in the **same transaction** (design-08 Flow D step 8).
  The `UNIQUE(proxy_id, is_default)` index is the backstop, and its violation is surfaced as a
  field error, never a 500.
- `conditions` — array; at MVP `size:1` when the map is conditional and `size:0` when it is
  default. **The field is always an array**, never a flattened path/value pair (AC17b).
  - `conditions.*.path` — required, string, `max:255`, matching `^[^.\[\]]+(\.[^.\[\]]+)*$` —
    dot-separated object keys, **rejecting `[`/`]`** so `items[0].sku` fails with AC12(b)'s
    message (design-08 Screen 4 **C5**: no "yet").
  - `conditions.*.operator` — required, `Rule::enum(MapConditionOperator::class)`.
  - `conditions.*.value_type` — required, `Rule::enum(MapValueType::class)`.
  - `conditions.*.value` — required, string; `numeric` when the type is `number`, `in:true,false`
    when `boolean`.
- `output` — required; must decode to a JSON **object or array** at its root; every `$from` value
  must satisfy the same path rule; bounded node count and nesting depth; no duplicate output paths
  when submitted from the Builder. A `$from` path **not present in the expected structure is a
  soft, non-blocking note**, never an error (design-08 Flow D step 7, AC9/AC21).
- **No mode condition of any kind** — see § *Architecture F*.

**Reorder:** `direction` in `up|down`; a boundary move is rejected 422.

**Expected structure (update):** `source` — `Rule::enum(ExpectedStructureSource::class)`;
`fields` — array, bounded count, each `{path, type}` under the same path rule and a type enum. The
server does not re-derive from traffic and never infers (AC10/D-08-3).

**Ordering on create:** a new conditional map appends at `max(position) + 1` for its proxy
(design-08 Flow D step 9 — AC14 implies no other insertion point). Deleting a conditional map
renumbers the remainder with no gap, in one transaction (Flow E step 2).

## Explicitly out of scope for this plan

Named so the Task Planner does not schedule them and the Reviewer does not expect them.

- **A pre-existing narrow defect, for the Senior Developer on a separate branch off `main` —
  not #8's.** `CaptureDispatchedStep`'s cleaned-parent short-circuit (`:70-74`) returns without
  `$next` and **leaves the dispatch's `pending` deliveries stranded**. On a FIFO proxy that holds
  the `fifo_dispatches` row at `awaiting_retry` forever (nothing will settle it), stalling the
  proxy's line permanently. It is reachable only when the GC erases an event *during* its own
  dispatch — an event must be past its retention window **and** being delivered — which is why it
  has not bitten. #8 must not fix it: the fix belongs to the retry/FIFO semantics AC21/AC22 fence
  off, and `TerminalizePendingDeliveries` (§ E) is exactly the tool for it whenever it is
  scheduled. **Reported, not repaired here.**
- **Any change to `dispatched_payloads`, retention, GC, holds, or the erasure transaction**
  (AC19) — ADR-013 is unchanged (§ *Technical rulings* 5, ADR-019 Decision 10).
- **Any change to retry or replay** (AC21) — `RetryDelivery`, `SweepDueRetries`,
  `DeliverToDestination` and the replay controller are not edited.
- **Any change to processing mode or the FIFO machinery** (AC22) and **no third proxy mode**
  (AC23).
- **A reshaped-payload viewer** (design-08 flagged call 8; AC20's comparison clause is discharged
  as a property of what is stored), **a second payload read surface**, or any change to the
  #6/Q-06-02 mask/reveal behaviour (AC33).
- **#9** normalisation, **#12** drift detection or its state, **#13** notifications, **#14**
  test-send, **#11** analytics, **#10** obfuscation/encryption policy. Seams left, nothing built
  (AC27, AC28, AC32, AC33).
- **AND/OR condition combination and a second condition per map** (AC17(f)/AC30) — the model does
  not preclude them; no UI, no column and no rule is built for them.
- **Operators beyond `equals`** — § *Technical rulings* 6.
- **A shared map library, templates, import/export, or map version history** (AC7, AC33).
- **Drag-and-drop reordering** — design-08 flagged call 3, accepted: it would be a new dependency
  and an Owner gate AC14 does not require.

## Risks

**R1 — A bare short-circuit on mapping failure stalls a FIFO line forever and immortalizes
payloads. (High; closed by design, § E.)** The obvious implementation of "do not deliver" is to
return without `$next`. Doing so leaves the dispatch's `pending` deliveries non-terminal; on FIFO,
`AdvanceProxyFifoQueue::settleOrHold()` then parks the `fifo_dispatches` row at `awaiting_retry`
**with no lease and no retry schedule**, and nothing will ever settle it —
`SweepStalledFifoDispatches` pass (b) skips the proxy because a held row exists, and pass (c) will
not release it because the deliveries are non-terminal. The proxy's line stops permanently, and GC
hold **H2** (`no fifo_dispatches row with a status other than settled`) **has no age escape**, so
every one of that proxy's expired payloads becomes immortal — the unbounded hold rejected by name
three times (ADR-012 § Alternatives, ADR-015 Decision 7, ADR-016 Decision 2) and a direct PRD-05
AC6 violation. **Mitigation:** `TerminalizePendingDeliveries` (§ E), which is therefore not
optional. **Pinned by test**, not by inspection — see § *Test strategy* AC22.

**R2 — Stranded Async deliveries would misreport the event. (Medium; same mitigation.)** On
Async, nothing terminalizes a `pending` delivery (`SweepDueRetries` touches only `retrying`), so a
mapping failure would leave the event's aggregate delivery badge reading pending forever. H5
releases the payload once the row is older than the dispatch horizon, so no payload is immortal
here — but the surface would be untruthful and AC22's attributability would be half-delivered.
Closed by the same CAS.

**R3 — Output size amplification. (Low; bounded by construction.)** The ADR-006 body cap bounds
the *input*, not the reshaped output, and a map may reference one large subtree into several
output fields. The model has **no loops and no repetition**, so output size is bounded by
(the authored map's field count) × (the largest referenced input value) — finite and
member-visible. `dispatched_payloads.body` is `LONGBLOB` and `byte_size` `unsignedInteger`, both
comfortably sufficient. No numeric limit is asserted (AC34); the validation bounds on the map
document are the practical control.

**R4 — Two extra queries per enhanced-mode event.** `MapStep` resolves the `webhook_events` row by
`ingest_id` (UNIQUE index) and writes one `event_mappings` row, alongside
`CaptureDispatchedStep`'s existing pair. Accepted: both are O(1) on indexed keys, and the
alternative — widening `PipelineContext` with a `webhookEventId` — is the ripple ADR-013
deliberately rejected.

**R5 — Concurrent reordering.** Two members reordering simultaneously produce last-write-wins
positions, possibly colliding. Determinism is preserved regardless by the `position ASC, id ASC`
total order (§ C), and design-08 Flow C's snap-back covers request failure. No lock is added.

**R6 — A map referencing paths the expected structure no longer describes.** Deliberately **not**
flagged retroactively: that is #12's job (AC10's parenthetical, AC32). Authoring shows a soft,
non-blocking note only (Flow D step 7). No code.

**R7 — `Delivery::proxy()` is a plain `belongsTo` while `Proxy` uses `SoftDeletes`**, so it
returns `null` for a soft-deleted proxy. `MapStep` reads `$ctx->proxy` — already loaded
trashed-inclusive by `ProcessIngestedWebhook:50` — and `TerminalizePendingDeliveries` works from
`dispatch_uuid` without touching the relation, so #8 adds no new exposure. Noted so the breakage
is not reintroduced.

**R8 — The mapping-failure path is thin.** With no expressions, lookups or loops, almost nothing
can fail at apply time: an absent field is a defined outcome (Ruling 7), an undecodable body is
`none` (Ruling 2), extra properties are ignored. What remains is a structurally invalid persisted
map document and an output-path collision the Builder's validation missed (e.g. `a` as a scalar
and `a.b` as an object). That narrowness is a feature, but AC22 must still be reachable and tested
— § *Test strategy* drives it through a deliberately invalid persisted document.

## Dependencies

- **None new.** No Composer package, no pnpm package, no stack change (`docs/stack/stack.md`
  untouched). Eloquent, Laravel migrations, the native `Illuminate\Pipeline\Pipeline`,
  `lorisleiva/laravel-actions` `AsObject` (ADR-007), and PHP's `json_decode`/`json_encode`.
- **Frontend adds no dependency** — every new control is a hand-written composition over existing
  tokens and primitives (design-08 Handoff, confirmed against the current tree: no `Textarea`,
  `RadioGroup`, `Tabs`, `Popover`, `Command` primitive and no code-editor package exists). Two new
  `@lucide/vue` icons from a library already installed. **The Product Manager ruled at the design
  gate that no CodeMirror/Monaco dependency gate travels to the Owner**; this plan does not
  re-open it and introduces no editor package.
- **Feature dependencies:** #5 (Done — the store AC20 fills), #6 (Done — the events surface AC16
  extends, the replay path AC22 relies on, the retry path AC15's accepted consequence surfaces
  through), **#7 (code-complete on `feat/item-07-enhanced-mode-toggle`, in Review)**. Every #7
  decision this plan rests on is frozen: ADR-018 Accepted, the `RetryPolicy` mode-gate shape,
  the `ProxyResource`/`ProxyFormResource` split, and `PipelineFactory` left deliberately
  unchanged. **#8 must not be merged before #7.** If review-07 forces a change to the resolver
  shape or the resource split, re-check § *Architecture B* and § *G*.

## Implementation Notes

Binding constraints for the Senior Developer, beyond `docs/standards/coding.md`.

1. **`MapStep` must stay after the `#9` seam and before `CaptureDispatchedStep`.** Moving it above
   `NormalizeStep` would map pre-normalisation bytes; moving it below `CaptureDispatchedStep`
   would store the unmapped payload and falsify AC20.
2. **`$ctx->payload` is assigned only on complete success; `$ctx->rawBody` is never assigned.**
   Build the reshaped string fully, then assign. A partial reshape must not exist in the context
   (AC22, AC19/R2).
3. **`MappingPolicy` is the only reader of the three configuration tables**, with the single named
   exception of `ProxyMapController`. No controller, resource, view, command or step may read
   `proxy_maps`, `proxy_map_conditions` or `proxy_expected_structures` directly. A grep for those
   table/model names outside those two classes should return nothing.
4. **`event_mappings` is never read to decide behaviour** — only to report. Nothing in selection,
   application, delivery, retry, replay, GC or FIFO may branch on it.
5. **No read surface may emit map configuration.** `ProxyResource` gains nothing.
   `WebhookEventResource` gains an **outcome**, not configuration.
6. **Delivery status is changed only by compare-and-set on the query builder, keyed on the prior
   status** — never a blind `save()` (plan-06's binding invariant). `DeliveryExhausted` fires only
   when the CAS affected a row.
7. **Never log payload content, a map's literals, or a reshaped body.** `MapStep` logs identifiers
   only; `failure_reason` is a bounded summary (`Str::limit(..., 247)`, the
   `delivery_attempts.error_summary` precedent) and must never carry a value from the payload.
8. **`PipelineContext` is not modified.** Resolve the event by `ingest_id`, as
   `CaptureDispatchedStep` does.
9. **No `config()` read is introduced.** If one ever is, it must go through a
   `positiveConfigInt()`-style fail-loud guard in the owning resolver (the `RetryPolicy` /
   `RetentionPolicy` precedent).
10. **Copy is verbatim from design-08 Screen 1 (C1/C2), Screen 3 (C3/C4), Screen 4 (C5) and Flow G.**
    In particular: render `{operator}` from the condition, never the literal word `equals`
    (**C4**); no "yet" in the array-indexing message (**C5**); the Maps card's evaluation copy is
    mode-conditioned (**C3**). No internal roadmap numbers, and no implication of #9's
    XML/form-encoded ingestion or #12's change detection (AC26).
11. **There is no frontend test harness** (stack gap, `docs/stack/stack.md`). design-08's Flows
    A–G are **manual-verification steps**, and `pnpm run build` must precede any live check — the
    checked-in bundle is otherwise stale and proves nothing (review-06 M-3's lesson).
12. **Migrations run on SQLite and MySQL.** No raw `ALTER`, no `LONGBLOB`, no `json` column.

## Test strategy

House format: grouped by acceptance criterion, named per criterion. Backend only (no frontend
harness); the criteria that rest on rendering are listed as manual-verification steps.

**AC1/AC7 — maps as first-class, proxy- and team-scoped.** CRUD leaves the proxy's ingest URL,
destinations, events and history untouched. A map is unreachable from another team's proxy (404
through the team scope). No cross-proxy listing exists.

**AC3 — permission gating.** For each of store/update/destroy/move and expected-structure update:
a member with view-only permission is forbidden; a Member without the `-any` bypass is forbidden
on a proxy they did not create; a creator Member and an Admin succeed. `index` succeeds with view
permission alone.

**AC4/AC5/AC6 — the mode gate, both halves.** `PipelineFactory::stepsFor()` includes `MapStep`
for Enhanced and excludes it for Simple. An **end-to-end** test: a Simple proxy holding a matching
map delivers the payload **byte-identical** to what it received, and writes no `event_mappings`
row. `MappingPolicy::configuredMapsFor()` returns an empty set for a Simple proxy holding maps.
Saving a proxy as Simple and back to Enhanced leaves every map, condition, order position and the
expected structure **byte-identical**, and the map applies again with nothing re-authored. No read
surface emits map configuration (assert `ProxyResource` keys).

**AC8/AC9/AC10 — the expected structure.** It survives deleting every map. It can be established
from a supplied sample and updated. Establishing it alters no existing map. Maps can be authored
with no structure established. The server never derives it from traffic.

**AC11/AC13/AC14/AC15/AC16 — selection.** A table-driven suite over one proxy: first-match-wins
across three overlapping conditional maps; a later matching map is **not** applied; the default is
applied only when nothing matches and never pre-empts a conditional match; no match and no default
delivers unreshaped and records `none`; reordering changes which map wins; two maps sharing a
position still select deterministically (the `id` tie-break); the same payload against the same
configuration selects the same map on 100 repeated runs. Saving two maps whose conditions overlap
is **accepted**, and no multi-match state is emitted anywhere.

**AC12/AC17 — the condition model.** `"CHARGE"` does not match `"charge"`; `42` does not match
`"42"`; `true` does not match `"true"`; a scalar never matches an object or array; a nested path
matches; an absent path does not match **and raises nothing**; `items[0].sku` is rejected at
validation. **AC17 is checked against the shipped model, as the criterion demands:** every
persisted condition row has a non-null `operator`; every emitted condition carries its operator;
the resource emits `conditions` as an **array** for a one-condition map; a test adds a temporary
second `MapConditionOperator` case in-test and shows selection, persistence and serialization work
with **no schema change and no contract change**.

**AC18/AC19 — application.** One reshaped payload reaches every destination **identically** (assert
the same bytes on N `DeliveryUnit`s); exactly one `event_mappings` row exists per event; the
`webhook_events` row is byte-identical before and after (raw immutability).

**AC20 — the dispatched store.** An Enhanced mapped event stores the **reshaped** bytes in
`dispatched_payloads.body`; an identity map (output byte-identical to input) stores `NULL` and
`StoredPayloadLookup::dispatchedBytesFor()` still returns the raw bytes. **No retention test
changes** — the existing #5 suite must pass untouched, which is the AC19 guard.

**AC21 — graceful tolerance.** Extra incoming properties the structure does not describe are
ignored and delivery succeeds; a map referencing an absent field **omits** that output field and
the result is stable across repeated runs; an undecodable body with maps configured is delivered
unreshaped and records `none`, raising nothing.

**AC22 — failure semantics (the R1 guard).** With a deliberately invalid persisted map document:
**no HTTP send occurs** (`Http::fake()` asserts nothing was sent); **no `delivery_attempts` row is
written**; **no `dispatched_payloads` row is created**; the `webhook_events` row is intact and its
payload state is retained; `event_mappings.outcome === failed` with the map named; **every
`deliveries` row of the dispatch is `failed` with `next_attempt_at = null`** and `DeliveryExhausted`
fired **once** per row. **On a FIFO proxy specifically:** the `fifo_dispatches` row reaches
`settled` and the next pending dispatch is advanced — the regression that pins R1. Then: fixing the
map and replaying delivers the reshaped payload through the existing replay path.

**AC24/AC25 — retry and replay (Ruling 5).** A retry after the map is edited re-sends the
**recorded dispatched bytes**, not a re-mapped payload (assert byte equality with attempt 1 and
that `MapStep` did not run). A replay after the map is edited sends the **new** shape and
overwrites `event_mappings` and `dispatched_payloads`. An event that selected no map retries with
the raw bytes and needs no special path. Nothing anywhere reports mixed treatment as an error.

**AC26 — copy.** The `#mode-help` string and both Show captions contain no roadmap number and no
XML/form-encoded or change-detection claim (string assertions in the existing proxy page tests).

**AC29 — no routing.** No endpoint accepts a destination parameter for any mapping operation;
`DeliverStep` still iterates the dispatch's deliveries and passes the same payload to each.

**Manual verification (no frontend harness; `pnpm run build` first):** design-08 Flows A–G,
specifically the Simple-mode banner and the mode-conditioned evaluation copy (**C3**); the
condition line rendering `{operator}` rather than a hardcoded `equals` (**C4**); the absence of
every mutating control for a read-permission member (**C6**); the "Not currently selected"
grouping on both sides of a Conditional⇄Default change (**C7**); and the Mapping row's rendering
for a pre-#8 event (**C8**).

## Milestones (task-breakdown-ready)

- **M1 — Data model.** Four migrations, four models, four enums, the `Proxy` relations, factories.
  **Blocked on the Owner's data-model approval.**
- **M2 — Selection and application, headless.** `MappingPolicy`, `PayloadMapper`, `MapSelection`,
  the path resolver, the operator contract. Fully unit-tested with no pipeline and no HTTP.
- **M3 — The pipeline step.** `MapStep`, `TerminalizePendingDeliveries`, the `PipelineFactory`
  line, `event_mappings` writes, the AC22 and FIFO regressions. **Depends on M2.**
- **M4 — Authoring API.** Routes, three controllers, Form Requests, resources, policy wiring.
  **Depends on M1.**
- **M5 — Authoring UI.** Mapping page, map editor, `PathAutocomplete`, Builder/Raw JSON, Preview,
  the two `data/` option consts. **Depends on M4.**
- **M6 — Attribution and copy.** `WebhookEventResource.mapping`, the event-detail Mapping row, the
  `#mode-help` and Show caption updates (C1/C2). **Depends on M3.**

M2 is the only milestone with no dependency on the Owner gate and is the natural first task if the
gate is still open.

## Handoff

- **Inputs:** Approved **PRD-08** (34 ACs, D-08-1..4 ratified); PM-approved **design-08** with its
  **approval note governing** and all nine corrections landed; **Q-08-01** and **Q-08-02**
  (RESOLVED, Project Owner, 2026-08-26); **Q-08-03** (RESOLVED here); **ADR-018** (Accepted) and
  the annotated ADR-015; ADR-001/002/003/005/006/007/009/010/011/012/013/014/016/017; plans 03–07;
  `docs/reviews/review-06-retry-replay.md`; current code on `feat/item-07-enhanced-mode-toggle`
  (`PipelineFactory`, `PipelineContext`, `ProcessIngestedWebhook`, `CaptureDispatchedStep`,
  `DeliverStep`, `DeliverToDestination`, `RetryDelivery`, `AdvanceProxyFifoQueue`,
  `SweepStalledFifoDispatches`, `PurgeExpiredPayloads`, `RetryPolicy`, `StoredPayloadLookup`,
  `ProxyResource`, `ProxyFormResource`, `WebhookEventResource`, `ProxyPolicy`, `routes/web.php`,
  the migration set); `docs/standards/` (architecture, coding, testing, planning, documentation);
  `docs/stack/stack.md`.
- **Outputs:** this plan; **ADR-019** (Proposed, pending Project Owner approval); the completed
  Answer block in `docs/questions/prd-08-q-08-03-mapping-composition-and-expected-structure.md`
  (RESOLVED). **No new question document** — see *Outstanding Questions*.
- **Dependencies:** none new; within stack. Sequenced after #7 (§ *Dependencies*).
- **Outstanding Questions:** **none.** Q-08-01, Q-08-02 and Q-08-03 are all resolved, and this
  plan needed nothing further from the Product Manager or the Designer: every PRD-08 criterion is
  feasible as stated, and design-08 as approved is buildable as specified. The three calls the
  design gate delegated to me — flagged call 6's persistence shape, AC17(e)'s operator latitude,
  and non-blocking note (i)'s failure-copy dependency — are ruled at § *Technical rulings* 3, 6
  and § *Architecture E* respectively. Two items are **recorded for awareness, requiring no
  ruling and blocking nothing**: design-08 Screen 6's real-value Preview state is not reachable at
  #8 (§ *Technical rulings* 4, with the additive route named), and the Screen 7 ⇄ Flow G step 2
  reading is settled at § *Technical rulings* 1 without a Designer round-trip.

### Owner-approval flags (✋) — **two outstanding**

Stated in full, as the house format requires, because this is the single place the Owner reads it.
**This plan is self-certified except for these two items.**

1. **✋ Data-model change — four new tables.** `proxy_maps`, `proxy_map_conditions`,
   `proxy_expected_structures`, `event_mappings`, with the exact columns, types and indexes in
   § *Data Model* (full definitions and rejected alternatives in ADR-019 § Impact). **Additive
   only: no column, index, enum value or default is added to, changed on, or removed from any
   existing table; no backfill and no data migration; rollback is four `dropIfExists`.** The
   security assessment attached to this gate is in § *Data Model* — no new at-rest copy of payload
   content, and one named item (member-typed literals stored plaintext in `proxy_maps.output` /
   `proxy_map_conditions.value`, the same class as `destinations.url`, recorded as an input to
   #10).
2. **✋ ADR-019 — *payload mapping: composed step, resolved configuration, and the recorded
   outcome*.** **Proposed, pending Project Owner approval.** It decides: mapping sits on **both**
   ADR-018 evaluation points and that is two evaluation points behind one gate, not a second gate
   and not a third kind of place; `MappingPolicy` as the single resolver with one named
   authoring-surface carve-out; the condition, order, output and outcome models; that a **retry
   re-sends the recorded dispatched output while a replay re-maps**; that a mapping failure
   terminalizes its dispatch's deliveries; and that **ADR-013 needs no change** under routine
   divergence. **It supersedes no position of any Accepted ADR.** One interpretive point is
   surfaced for the Owner rather than buried: ADR-019 Decision 3 exempts the **expected incoming
   structure** from ADR-018 Decision 6's resolver gate, because it governs no event in either
   mode. If the Owner prefers Decision 6's literal reading, gating it is a **one-line change**,
   and the only consequence is that a Simple proxy's authoring surface would stop showing the
   structure its member established.

**Not tripped, verified item by item against `CLAUDE.md`'s major-decision list:** no new Composer
or pnpm dependency (§ *Dependencies*; the design gate already ruled that no code-editor dependency
question travels to the Owner); no stack change (`docs/stack/stack.md` untouched); no new
permission, role or policy class (AC3); nothing irreversible or destructive — the only destructive
action is a member deleting their own map, behind the standard `AlertDialog`; no change to
retention, GC, erasure, retry, replay, processing mode, or the mode attribute (AC19/AC21/AC22/AC23/AC24);
no new payload read surface and no change to the #6 mask/reveal settlement. V3, V5 and V8 are
**not** reopened.

**Why an ADR was warranted here when #7 needed none.** #7's candidates were serialization classes
and config guards implementing decisions the Owner had already ratified. #8's are not: the
composition call is reserved to the Principal Engineer by ADR-018 Decision 1 and had never been
made; the persisted model of maps, conditions, the expected structure and the outcome is
hard-to-reverse once data exists; the retry-versus-replay boundary is user-visible and had to be
fixed rather than left implicit; and the failure/terminalization rule exists to prevent an
outcome three prior ADRs each rejected by name. Each of those clears the bar on its own.

### Certification (Principal Engineer, 2026-08-26)

I have verified that **PRD-08 is Owner-approved** (2026-08-26, as written, ratifying D-08-1..4)
and that **design-08 is PM-approved** (2026-08-26) — the mandatory design gate for the PRD's UX
Direction — and I have written this plan against design-08's **approval note**, which governs over
the spec body, with all nine required corrections confirmed landed in the body. I have read
ADR-001–018, plans 03–07, and the affected code on `feat/item-07-enhanced-mode-toggle`. Every
section above traces to PRD-08 acceptance criteria and to the approved design; the eight named
technical rulings stay inside the upstream artifacts' assumptions, and none reinterprets a
requirement, a design decision, or an Owner ruling. Nothing here changes a requirement or reopens
Q-08-01, Q-08-02, PRD-05's retention lifecycle, PRD-06's retry/replay semantics, or PRD-07's mode
model.

**I self-certify this plan under the delegated plan gate in `CLAUDE.md` — except for the two items
above, which I do not and cannot certify.** The carve-out is stated plainly: **#8 adds persisted
entities, and a data-model change is a Project Owner gate that no delegated gate covers.** The
Owner must approve (1) the four-table data-model change set exactly as enumerated in § *Data
Model*, and (2) **ADR-019**, including its Decision 3 reading of ADR-018 Decision 6. Everything
else — the composition placement, the resolver shape, the selection walk, the failure and
terminalization rule, the API surface, validation, the risk mitigations, and the test strategy —
is self-certified and needs no further sign-off.

- **Next Agent:** **Task Planner — after Owner approval of items 1 and 2.** Four standing
  constraints to carry into the breakdown: **M1 is blocked on the data-model gate** and M2 is the
  correct first task while it is open; **the pre-existing `CaptureDispatchedStep` stranding defect
  (§ *Explicitly out of scope*) must not appear in #8's task list** — it is the Senior Developer's,
  on a separate branch off `main`; **the AC22 FIFO regression (§ *Test strategy*) must be its own
  named task**, because it is the only automated guard on R1 and a partial landing is a shipped
  defect; and **design-08's Flows A–G are manual-verification steps** with `pnpm run build`
  required before any live check, since no frontend test harness exists.
