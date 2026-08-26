# Technical Plan: Enhanced-mode toggle — item #7

- **Status:** **Approved (Principal-Engineer self-certified) — in full.** There are **no**
  outstanding Owner-approval flags. The one Owner gate #7 ever carried — **ADR-018**, the
  partial supersession of ADR-015 Decision 3 — was **Accepted by the Project Owner on
  2026-08-25**, before this plan was written, so nothing here is contingent. See
  **Handoff → Owner-approval flags (✋)**, which records the cleared gate rather than omitting it.
- **Author:** Principal Engineer
- **Date:** 2026-08-25
- **PRD:** `docs/product/prd-07-enhanced-mode-toggle.md` — **Approved** (Project Owner,
  2026-08-21), **amended** 2026-08-25 (**Amendment A** — AC14(b) scoped to read surfaces, AC12's
  closing sentence split by surface kind; **Amendment B** — the Show page presents Mode in its
  header, not a "Details card"). 25 acceptance criteria, numbering frozen.
- **Design spec:** `docs/design/design-07-enhanced-mode-toggle.md` — **Approved** (Product
  Manager, 2026-08-25; design gate delegated per `CLAUDE.md`), with two required corrections the
  Designer is landing in parallel. **The spec's approval note governs where it conflicts with the
  spec body**, and this plan is written against the note. This plan builds the two surfaces the
  spec specifies and changes neither.
- **ADRs:** **ADR-018** — *one mode selector, two evaluation points* (**Accepted**, Project Owner,
  2026-08-25; partially supersedes **ADR-015 Decision 3**, which now carries the inline
  supersession annotation). **This plan proposes no new ADR** — see § *Why no new ADR*.
- **Approved by / date:** Principal Engineer, 2026-08-25.

## Overview

#7 makes an attribute that has existed since #1 (ADR-002) into a user-meaningful, truthful,
reversible choice. It adds **no page, no route, no permission, no column, no table, no
dependency**. Four concerns, all on existing surfaces:

**(a) The gate moves from persistence to resolution (AC6(b), AC14(a); ADR-018 Decision 2).**
`RetryPolicy::attemptLimitFor()` / `strategyFor()` establish `mode === Enhanced` **before** reading
either `proxies` retry column; a Simple proxy resolves the fixed system default whatever the
columns hold. Because ADR-015 already makes `RetryPolicy` the single resolver, every consumer —
`DeliverToDestination::settleDelivery()` and `DeliveryResource.attempt_limit` — inherits the
correct behaviour **with no branch of its own**. `PipelineFactory` is **unchanged**: AC6(a)'s
dispatched-output store is a pipeline step and is already gated structurally, at composition.

**(b) A Simple-mode save preserves rather than clears (AC14; ADR-018 Decision 3).**
`ProxyController` **omits** the two retry columns from the write when the submitted mode is
Simple, instead of writing NULL. Preservation is achieved by *not writing*.
`prohibited_if:mode,simple` is **kept** in both Form Requests — review-06 Minor 8(a) proposed
relaxing it and this plan rules against, on the record (§ *Technical rulings* 2).

**(c) Dormant values leave every read surface and reach exactly one write surface (AC12, AC14(b),
AC16; Amendment A; ADR-018 Decision 4).** `ProxyResource` stops emitting the raw columns: it emits
the per-proxy override **in force** — the column value on an Enhanced proxy, `null` on a Simple
one — so Show and Index render `5 (default)` / `Exponential (default)` for a Simple proxy whether
or not it holds a dormant policy. The raw persisted values reach the **Edit page's form prop
only**, through a new, single-purpose `ProxyFormResource`. That is the whole carve-out.

**(d) The two surfaces become true (AC12, AC16; design-07).** The Mode field reaches the form's
first-class field pattern, gains help text naming **both** AC6 capabilities in present tense and
restating the mode-independent guarantees, and — on an Enhanced → Simple edit only — renders an
inline, non-dismissible disclosure of the three AC13/AC14 points. The Show page gains a one-line
present-tense caption under the header that **references** the Retry policy card rather than
restating it.

Two riders ride along because #7 is the next feature to touch these seams, both scheduled below:
a guarded **`RetryPolicy::sweepGraceSeconds()`** (closing the last unguarded `config('retry.*')`
read, which today sits outside the very invariant ADR-018 leans on), and **review-06 Minor 5**
(`DeliveryResource.created_at`, serialization only).

Nothing changes in the ingest/response contract, capture, retention, erasure semantics, the FIFO
machinery, the replay path, or the delivery state machine. **No data-model change** — the
assessment Q-07-02(5) asked for, confirmed again against the schema on this branch.

## What is already settled, and by whom

This plan invents nothing. Every requirement question #7 raised is closed, and the closures are
load-bearing here.

| Settled | By | What it fixes for this plan |
|---|---|---|
| **Q-07-01(a)** retain — a downgrade erases nothing; retention expiry stays the only eraser | Project Owner, 2026-08-21 | AC13 needs **no code**: the expiry pass is mode-independent already (verified — § *Architecture E*) |
| **Q-07-01(b)** preserved dormant and restored | Project Owner, 2026-08-21 | The whole of (a)+(b)+(c) above exists to deliver this |
| **Q-07-01(c)** unrestricted switching | Project Owner, 2026-08-21 | AC17 needs **no code**: nothing anywhere gates on in-flight state |
| **Q-07-02** (1)–(5): step wiring, in-flight resolution, switch safety, resolution across a switch, no data-model change | Principal Engineer, 2026-08-25 | The AC9/AC10/AC11/AC18 arguments below are that answer's, restated, not re-derived |
| **ADR-018** — one selector, two evaluation points | **Accepted, Project Owner, 2026-08-25** | Decisions 1–6 are binding on every section of this plan |
| **Q-07-03 — Option A**; folded in as **PRD-07 Amendment A** | Product Manager, 2026-08-25 | Unblocks the Edit-form prop shape and the upgrade write rule, which Q-07-02 had to leave in draft |
| **Amendment B** — Mode is a header badge, not a "Details card" | Product Manager, 2026-08-25 | The Show addition is a caption. **No new card.** Reading "Details card" as requiring one would also breach "no new surface" |
| **design-07 Approved**, both flagged calls accepted, two corrections in flight | Product Manager, 2026-08-25 | Inline `Alert` (not `AlertDialog`); header caption (not a card); Show's dormant-value enforcement is **server-side** (correction C1) |

**Where design-07's body and its approval note disagree, the note governs** (the note says so).
Two consequences this plan implements: a Simple proxy's dormant retry values **must not reach the
Show payload at all**, and the displayed outcome on Show is `5 (default)` / `Exponential
(default)` plus the existing simple-mode note, **identical** whether or not a dormant policy
exists. Screen 2(b)'s client-side computed is therefore not the enforcement point; whether it
survives as defence in depth was delegated to me and is ruled in § *Technical rulings* 5.

## Architecture

Five concerns, mapped to ACs. **No change to the ingest handler, the response contract, capture
placement, erasure semantics, the delivery state machine, the FIFO machinery, or the replay path.**

### A. The resolution-time gate (AC6(b), AC14(a), AC21; ADR-018 Decision 2)

`App\Services\RetryPolicy` gains the mode gate, expressed **once**, in two new private-facing
public readers that every other method routes through:

- **`configuredAttemptLimitFor(Proxy): ?int`** — `mode === ProxyMode::Enhanced ?
  $proxy->retry_attempt_limit : null`. This is the **only** place `retry_attempt_limit` is read
  for the purpose of deciding what governs a proxy.
- **`configuredStrategyFor(Proxy): ?RetryBackoffStrategy`** — the same shape over
  `retry_backoff_strategy`.
- **`attemptLimitFor(Proxy): int`** becomes `configuredAttemptLimitFor($proxy) ??
  positiveConfigInt('default_attempt_limit')`, then the existing hard clamp into
  `[1, max_attempt_limit]`. **The clamp and the `positiveConfigInt()` config-sanity guards are
  unchanged and apply after the gate** (ADR-018 Decision 2, verbatim).
- **`strategyFor(Proxy): RetryBackoffStrategy`** becomes `configuredStrategyFor($proxy) ??
  RetryBackoffStrategy::Exponential`.
- **`delayBefore(Proxy, int)`** inherits the gate through `strategyFor()`. **No separate branch.**
- **`worstCaseSpan()`** is proxy-free and **unchanged**. The ADR-015/PRD-06 AC18 structural bound
  (≈32.6 h worst case, orders of magnitude inside the 30-day retention window) stays true in both
  modes, because the gate can only ever resolve *to* the default — it can never raise a limit.

**Why `DeliverToDestination` and `DeliveryResource` inherit the gate without branching.** Neither
reads a retry column or `config('retry.*')`; both ask the resolver. `DeliverToDestination::settleDelivery()`
calls `attemptLimitFor($proxy)` (`app/Actions/DeliverToDestination.php:198`) and `delayBefore($proxy, $n)`
(`:216`); `DeliveryResource` calls `attemptLimitFor($this->proxy)` (`app/Http/Resources/DeliveryResource.php:40`).
Putting the gate inside the resolver therefore changes their behaviour correctly and changes their
code not at all. Adding `mode === Enhanced` at either call site would be strictly worse three ways:
it would **reproduce the gate per consumer**, so correctness would degrade with every new consumer
(#8/#9/#12 inherit whatever pattern #7 sets); it would move policy logic **outside** the single
resolver, breaking the ADR-015 invariant ADR-018 now leans on; and it would create the third
behavioural mode read ADR-018 Decision 1 forbids without a superseding ADR. This is ADR-018's
second rejected alternative, and the reason the gate lives where it lives. Two evaluation points
of the same enum — composition and resolution — is **one gate**, not the second gate AC6/AC18
forbid; a second gate would be a different attribute, a per-capability sub-toggle (AC23), or an
inference.

**Inference stays forbidden, in both directions** (ADR-018 Decision 1). Nothing may conclude a
proxy's mode from a `dispatched_payloads` row, a non-NULL retry column, a delivery's history, or
any other by-product of an earlier Enhanced period — those artefacts legitimately outlive the mode
that produced them (AC13/AC14).

**Mode is read live, never snapshotted** (ADR-018 Decision 5). No mode value is serialized into a
queued job, stored on an event/delivery/ordering/attempt row, or carried across a request.
Consumers load the proxy trashed-inclusive (the `ProcessIngestedWebhook:50` precedent). Within one
pipeline run every step reads the single `$ctx->proxy` instance loaded at entry, so no two steps
of one event can observe different modes; across separate runs, attempts, retries, sweeps and
replays, the **current** mode governs each independently (AC9, with AC11's mixed treatment as the
accepted consequence).

### B. Persistence — preservation by not writing (AC14, AC14(b)(iv); ADR-018 Decision 3)

One rule, applied identically in `ProxyController::store()` and `::update()`:

> **A save whose submitted mode is Simple never writes either retry column. A save whose submitted
> mode is Enhanced writes exactly what was submitted** — a value sets it, `null` means "use the
> system default", which is PRD-06 AC2's existing unconfigured meaning.

Mechanically, `update()` builds its attribute array without the two retry keys and adds them only
when `$data['mode'] === ProxyMode::Enhanced->value`; `$proxy->update()` then writes only what is
present. `store()` takes the same shape — on create the outcome is identical either way (the
columns default NULL, and there is nothing to preserve), but the code reads as one rule rather
than two. This is the smallest possible change to the controller and needs no new state, no
sentinel, and no read-before-write.

**`prohibited_if:mode,simple` is KEPT** on both fields in `StoreProxyRequest` and
`UpdateProxyRequest` — see § *Technical rulings* 2 for why, and why review-06 Minor 8(a)'s
proposed relaxation is declined.

**The client must null both fields on a Simple submission** (§ *Technical rulings* 3) — otherwise
the Edit form of a Simple proxy carrying dormant values would submit them and hit `prohibited_if`,
producing a 422 on a field the form does not render.

### C. Presentation — the shape of the two resources (AC12, AC14(b), AC16; Amendment A; ADR-018 Decision 4)

The rule this plan adopts, stated once so the Task Planner, Senior Developer and Reviewer can all
check it mechanically:

> **A retry column may be read for two different purposes, and only two.**
> **Resolution** — deciding or displaying what *governs* a proxy — goes through `RetryPolicy`, and
> only through `RetryPolicy`. **Persistence echo** — reflecting stored values back to the write
> surface so they can be edited — happens in `ProxyFormResource` and nowhere else.

**`ProxyResource` (read surfaces: Index, Show, and anything shaped from them).** The two keys stay
in the payload — the frontend's `(default)` rendering depends on them — but their **meaning
changes** from *the raw column* to *the per-proxy override in force*:

```
'retry_attempt_limit'    => app(RetryPolicy::class)->configuredAttemptLimitFor($this->resource),
'retry_backoff_strategy' => app(RetryPolicy::class)->configuredStrategyFor($this->resource)?->value,
```

An Enhanced proxy emits its column values; a **Simple proxy emits `null` for both, always**. The
dormant values do not reach the Show payload at all — which is exactly design-07 correction C1,
and exactly ADR-018 Decision 4's second admissible shape ("emit `null` and let the existing
display helper render `(default)`"). Note the by-product: routing this through the resolver
**repairs** the ADR-015 single-reader invariant, which `ProxyResource:49-50` breaches today by
reading the columns directly.

Chosen over the first admissible shape (resolve and emit the *effective* value, e.g. `5`) because
that one loses the design's `(default)` annotation: `proxyRetryAttemptLimitDisplay()` renders
`5 (default)` precisely for `null` and a bare `5` for a configured 5. Emitting the effective value
would silently change the Show card's rendering for every unconfigured proxy — a design change I
have no authority to make.

**`App\Http\Resources\ProxyFormResource` (new; the write surface).** Extends `ProxyResource` and
adds exactly two keys — the **raw** persisted columns, irrespective of mode:

```
'retry_attempt_limit'    => $this->retry_attempt_limit,
'retry_backoff_strategy' => $this->retry_backoff_strategy?->value,
```

Used by **`ProxyController::edit()` only**. `create()` renders `proxies/Create` with no proxy
resource at all (Create.vue hard-codes the blank initial state), so the create path needs nothing.
A dedicated class rather than a flag on `ProxyResource` because it makes the carve-out a **type**:
one `grep ProxyFormResource` enumerates every place a dormant value can travel, and a future
`show()`/`index()` cannot opt in by forgetting a boolean. Amendment A's "the carve-out is exactly
one payload and no more" then holds structurally.

**Frontend types.** `ProxyDetail` and `ProxyListItem` keep the two fields with a corrected
docblock — *"the per-proxy override in force; `null` whenever the system default governs, including
a Simple proxy holding a dormant policy"*. A new `ProxyFormProxy` interface (Edit.vue's prop)
carries the raw values with its own docblock naming Amendment A. `Show.vue`'s
`retryAttemptsDisplay`/`retryBackoffDisplay` computeds are **unchanged in code** — their stale
rationale comment (`Show.vue:101-105`, "a simple-mode proxy's columns are always NULL", retired
with ADR-015 Decision 3) is rewritten to cite the server-side gate. See § *Technical rulings* 5
for the ruling against a client-side guard.

### D. Composition — unchanged, and that is the finding (AC6(a), AC9, AC18; ADR-018 Decisions 1 and 6)

`PipelineFactory::stepsFor()` needs **no change**. AC6(a)'s dispatched-output store is a pipeline
step (`CaptureDispatchedStep`, composed inside the `ProxyMode::Enhanced` branch at
`app/Pipeline/PipelineFactory.php:35`), so it is gated **structurally, at composition** — an
uncomposed step cannot run, in either direction, with no runtime check anywhere. That gate has
been correct since #5 and #7 neither extends nor touches it.

It also already satisfies AC9 under queued dispatch, because composition happens **on the worker,
from a live row**: `ProcessIngestedWebhook::handle()` loads the proxy at processing time
(`:50`, trashed-inclusive) and composes from that instance (`:96`), while the queued job carries
`(ingestId, dispatchUuid)` only (ADR-011 dispatch-by-reference — no mode, no proxy, no payload).
An event captured under one mode and dispatched under another therefore composes from the mode in
force **when it is processed**, with no reconciliation step and no stale read.

**AC18 extensibility is an addition only.** A later enhanced-only step is one uncommented line at
its already-reserved position — `// #9 NormalizeStep` (`:30`), `// #8 MapStep` (`:34`), both
before `CaptureDispatchedStep` per ADR-013's placement constraint, `// #12 ChangeDetectStep`
(`:43`) in the tail stage. No change to the attribute, the toggle, the gate, `PipelineContext`, or
`DeliverStep`. Where a later capability also brings per-proxy **configuration**, it adds its own
columns/tables **plus its own single resolver repeating the Decision-2 gate** (ADR-018 Decision 6);
shipping such configuration with its gate only in validation is the defect ADR-018 exists to
prevent. **#7 builds none of it and leaves the comments exactly as they are** (`docs/standards/planning.md`
§ Scope discipline — named seams stay commented stubs).

### E. Switch safety and the downgrade lifecycle — no code, and why (AC10, AC11, AC13, AC17)

Confirmed in Q-07-02(3) against `main` as merged, restated here because the Task Planner must know
these ACs are satisfied by **existing structure**, not by work to schedule:

- **Every mechanism that provides AC10's guarantees is mode-independent**, and a switch mutates one
  enum column on `proxies` and nothing else: exactly-once settlement (`UNIQUE(delivery_id,
  attempt_number)` create-or-resume + the status CAS), delivery-row creation at pipeline entry
  before composition, the FIFO claim/lease/reaper/`awaiting_retry` machinery (which reads
  `processing_mode` and delivery status only — `mode` appears nowhere in it), and the ADR-012 holds
  H0–H5 with the ADR-014 cleaned guard.
- **A downgrade mid-retry cannot orphan a delivery.** The resolved limit drops to the default;
  `DeliverToDestination:200` compares `attemptNumber >= $limit`, not equality, so a delivery
  already past the new limit **terminalizes immediately** rather than looping. An already-scheduled
  retry still runs and terminalizes at settle — at most one extra attempt to a destination already
  receiving at-least-once delivery, which is the correct trade, since cancelling in flight is the
  only way this path could *drop* work (AC10 forbids that).
- **An upgrade mid-flight cannot make an event inconsistent.** It can only add a step to subsequent
  runs and raise the resolved limit, which is clamped inside `RetryPolicy` regardless of mode, so
  the AC18 span bound survives any number of switches. An event captured under Simple and
  dispatched under Enhanced simply gains a `dispatched_payloads` row;
  `StoredPayloadLookup::dispatchedBytesFor()` already handles the no-row case.
- **A queue redelivery straddling a switch produces no duplicate.** `CaptureDispatchedStep`'s
  `updateOrCreate` is keyed on `webhook_event_id` (UNIQUE) — one row per event, no error. The row's
  existence is therefore **not** an invariant of the proxy's current mode, which is precisely why
  inferring mode from it is forbidden.
- **AC13 needs no code.** The expiry pass erases `dispatched_payloads.body` in the same transaction
  as its parent regardless of the proxy's current mode, so a downgrade introduces no second eraser
  and Enhanced-made outputs expire normally on their 30-day schedule. PRD-05's
  single-erasure-trigger lifecycle is untouched, and **no PRD-05 amendment is required** (AC20).
- **AC17 needs no code.** Nothing in the product gates a proxy save on outstanding deliveries, and
  design-07 adds nothing that does (Flow D). The compliance is the absence.
- **AC11 needs no code.** No screen displays a per-event or per-delivery mode, so there is nothing
  to mis-flag as an inconsistency (design-07 Flow D — accepted as the correct reading at the design
  gate). **No suppression logic is to be built**; building some would be inventing a surface.

### F. The two surfaces (AC12, AC15, AC16; design-07 Screens 1 and 2)

Built exactly as specified; this plan changes nothing about them and adds nothing to them.

- **Mode field → first-class field pattern.** `SelectTrigger id="mode"` gains
  `aria-describedby="mode-help mode-error"` and `:aria-invalid="form.errors.mode ? 'true' : undefined"`;
  the help paragraph gains `id="mode-help"`, the error is wrapped in `span#mode-error`. Parity with
  `processing_mode`/`response_status`. **The `Select` itself is untouched in shape — still exactly
  two items** (AC23).
- **Corrected help text (AC12).** The spec's replacement copy verbatim: it names **both** AC6
  capabilities in present tense and restates the mode-independent guarantees, with no roadmap
  numbers and no implication that mapping exists (the copy constraint carried forward from the
  `design-06` gate). Supersedes the shipped `ProxyForm.vue:197-201` text, which names only retry
  configurability and omits the dispatched-output store.
- **Downgrade disclosure (AC13, AC14(c)).** An inline `Alert` + `AlertTitle` + `AlertDescription`
  (`Info` icon) in an `aria-live="polite"` wrapper, rendered iff
  `props.initial.mode === 'enhanced' && form.mode === 'simple'`. Sits **between the Mode control
  and the form's submit action**, is **never dismissible** and never collapsed behind a "learn
  more" — the two binding conditions of the PM's ruling on flagged call 1. Never true at Create
  (Create.vue always passes `initial.mode: 'simple'`), never true for an already-Simple proxy,
  and true regardless of whether a retry policy exists (the copy is written to hold either way, so
  no extra prop is needed). **Not a gate**: no confirm click, no checkbox, no modal.
- **Show caption (AC16, Amendment B).** One muted `<p>` directly below the existing header row
  (name + Mode badge + Processing badge). **No new card.** Simple and Enhanced copy per the spec,
  both referencing the Retry policy card rather than repeating a value.
- **Retry policy card.** Layout, placement, `dl`/`dt`/`dd` shape and simple-mode note all
  unchanged. Its two computeds are unchanged in code; correctness now comes from the server
  (§ *Architecture C*, § *Technical rulings* 5).
- **Index — no change** (design-07 Screen 2(c)). The Mode column's bare `Badge` states a fact, not
  a claim; no retry value is shown there today and none is added.

### Technical rulings (named, recorded — not silent design)

Each stays inside the approved artifacts' assumptions; none reinterprets a requirement.

1. **The gate lives in the resolver, not at the call sites.** Recorded in full in § *Architecture A*.
   ADR-018 Decision 2 fixes this; the ruling here is that no consumer gains a mode branch, and a
   Reviewer finding one should treat it as a defect rather than defence in depth.

2. **`prohibited_if:mode,simple` is KEPT — review-06 Minor 8(a)'s relaxation is declined.**
   The reviewer's sketch paired "stop clearing" with "relax `prohibited_if`". The first half is
   adopted (§ *Architecture B*); the second is not. Relaxing the rule would make a Simple-mode
   submission carrying retry values *valid*, which is the one write path that could silently
   overwrite the very values AC14 preserves — a member editing a Simple proxy could change a
   configuration they cannot see, the exact inverse of AC14(b). Keeping it costs nothing (a
   well-behaved form never triggers it, per ruling 3) and buys a second, independent guard on the
   same invariant: dormancy is enforced **at the boundary** by validation and **at the write** by
   the controller's omission, and neither depends on the other. Recorded in ADR-018 Decision 3 so
   the divergence from the reviewer's sketch is deliberate and traceable, and restated here because
   this plan is where it becomes an instruction.

3. **The client nulls both retry fields on any Simple submission.** New, and required by
   Amendment A. Under Option A the Edit form's initial state is seeded from the **persisted**
   values whatever the proxy's mode, while `design-06` Flow F's `watch(isEnhanced, …)` clears the
   fields only on an in-session Enhanced → Simple **change**, never on mount. So a member who opens
   Edit on a Simple proxy holding a dormant policy and saves *without touching Mode* would submit
   `retry_attempt_limit: 8` alongside `mode: simple` and be 422'd by `prohibited_if` — on a field
   the form does not render, with no way to fix it. Fix: `ProxyForm.vue`'s existing
   `form.transform()` sends `null` for both retry fields whenever `data.mode === 'simple'`,
   regardless of field state. This keeps `prohibited_if` satisfiable, keeps the server's omit rule
   authoritative, and is the mechanism Q-07-03 Option A named ("the client nulls both fields on
   submit whenever `mode === 'simple'`, so `prohibited_if:mode,simple` still holds"). It is a
   normalisation, not a gate: the server never trusts it (§ *Architecture B* preserves the values
   regardless of what a Simple submission carries).

4. **`design-06` Flow F is unchanged and is not in conflict.** Flow F governs **in-form,
   in-session** behaviour — hidden retry fields still clear to their default sentinel; values typed
   in the current session are still not restored when the member toggles back before saving. AC14
   governs **persistence** — what an already-saved proxy carries across a saved mode change. Both
   hold simultaneously (PRD-07 AC14, closing paragraph). Concretely: mount-seeded values survive a
   Simple → Enhanced toggle (the watcher does not fire on mount), and in-session typed values do
   not survive an Enhanced → Simple → Enhanced round trip. That asymmetry is intended by both
   artifacts and must not be "fixed".

5. **No client-side mode guard on the Show page's Retry policy card.** design-07's approval note
   (correction C1) makes the enforcement server-side and delegates the defence-in-depth question to
   me. Ruled **against** adding the `props.proxy.mode === 'simple' ? …(null) : …` computeds the
   spec body sketches. Reasons: with `ProxyResource` emitting `null` for every Simple proxy, the
   branch can never observe a non-null value for a Simple proxy, so it is untestable dead code that
   will rot into a false statement about where the rule lives; a second copy of a server-owned rule
   in the client is the "let the UI carry the invariant" posture ADR-018 explicitly rejects; and
   the displayed outcome is identical either way, so nothing user-visible turns on it. What
   replaces it is a **server-side test** pinning that a Simple proxy holding a dormant policy emits
   `null` on Show *and* Index (§ *Test strategy*), which is a stronger guarantee than a client
   branch could give. The stale rationale comment at `Show.vue:101-105` is rewritten rather than
   deleted, so the next reader learns where the gate is.

6. **`ProxyResource`'s mode-conditioned emission is not a third mode gate.** ADR-018 Decision 1
   constrains where `mode` may be read **behaviourally**; the ADR itself enumerates CRUD,
   validation, casts and **serialization** as non-behavioural (and `ProxyResource:36` already
   serializes `mode`). Routing the emission through `RetryPolicy::configured*For()` means
   `ProxyResource` performs **no mode test at all** — it asks the resolver, exactly as
   `DeliveryResource` does. Flagged here explicitly so a Reviewer counting mode reads does not
   score this as a Decision-1 breach.

7. **A blank `RETRY_SWEEP_GRACE_SECONDS` becomes a loud failure, not a silent zero.** Rider 1
   below adopts the `positiveConfigInt()` posture for the seventh `config('retry.*')` key, which
   means an explicit `0` — previously legal and meaning "no grace" — now throws. That is the
   deliberate, consistent choice (review-05 M-1 posture, applied to the six sibling keys already):
   the failure mode being closed is that a *blank* env makes the cutoff `now()` and sweeps every
   `retrying` delivery every minute, and "blank" and "explicitly zero" are indistinguishable after
   `(int)` coercion. No environment sets the key today (`.env.example` does not carry it; every
   test reads the resolved value rather than pinning zero).

## Data Model

**No change. None.** No column, table, index, enum value, migration, backfill, or default. `mode`
has been non-nullable with a `simple` default, cast, validated and settable through the existing
create/edit endpoints since #1 (ADR-002), which is what makes AC4's "no migration question arises"
true from the schema side as well as the requirement side. This is the assessment Q-07-02(5) asked
for, re-verified against the branch, and it is why #7 carries **no `CLAUDE.md` data-model Owner
gate**.

| Table | Touched by #7? |
|---|---|
| `proxies` | **Reads and writes the existing `mode`, `retry_attempt_limit`, `retry_backoff_strategy` columns differently. No schema change.** |
| `webhook_events`, `dispatched_payloads`, `deliveries`, `delivery_attempts`, `fifo_dispatches`, `destinations`, `teams` | **Untouched** — not read differently, not written differently, not migrated. |

## API

**No new route, no removed route, no changed method, path, name, gate or status code.** AC5 is
satisfied by the **existing** `ProxyPolicy::update` gate on `ProxyController::update()` (permission
`proxy:update` composed with the Q-02-01 ownership rule) and `ProxyPolicy::view` for reading a
proxy's mode — **#7 introduces no permission** (AC5, AC8).

| Endpoint | Change |
|---|---|
| `GET /{team}/proxies` (`proxies.index`) | Prop shape only: `retry_*` now mean *the override in force* — `null` for a Simple proxy |
| `GET /{team}/proxies/{proxy}` (`proxies.show`) | Same. Dormant values no longer reach this payload |
| `GET /{team}/proxies/{proxy}/edit` (`proxies.edit`) | Serializes through **`ProxyFormResource`** — the one payload that carries raw persisted values (Amendment A) |
| `PUT /{team}/proxies/{proxy}` (`proxies.update`) | Same rules, same validation; a Simple-mode save **omits** the two retry columns from the write |
| `POST /{team}/proxies` (`proxies.store`) | Same omission shape, no behavioural difference on create |
| `GET …/events`, `…/events/{event}` | `DeliveryResource` gains `created_at` (rider 2). No other change |

**Resources after #7:**
- **`ProxyResource`** (`$wrap = null`) — unchanged except `retry_attempt_limit` /
  `retry_backoff_strategy`, which now resolve through `RetryPolicy::configured*For()`.
- **`ProxyFormResource`** (new, extends `ProxyResource`) — the same shape plus the two **raw**
  columns. Consumed by `ProxyController::edit()` only.
- **`DeliveryResource`** — gains `created_at` (rider 2). `attempt_limit` keeps resolving through
  `RetryPolicy::attemptLimitFor()` and therefore now inherits the mode gate with no code change.

## Services & Actions

- **`App\Services\RetryPolicy`** (modified — the core of #7):
  `configuredAttemptLimitFor(Proxy): ?int` and `configuredStrategyFor(Proxy): ?RetryBackoffStrategy`
  (new; the mode gate, expressed once each); `attemptLimitFor()` / `strategyFor()` rewritten to
  route through them, clamp and config guards unchanged and applied **after** the gate;
  `delayBefore()` and `worstCaseSpan()` **untouched**; `sweepGraceSeconds(): int` (new, rider 1).
  Class docblock updated: it is now the single **mode-gated** resolver, and the invariant is
  restated as *"no consumer may resolve retry behaviour — or present what governs a proxy — from
  the columns or `config('retry.*')` outside this class; `ProxyFormResource`'s persistence echo is
  the single, named exception and resolves nothing."*
- **`App\Http\Controllers\ProxyController`** (modified) — `store()`/`update()` omit the two retry
  columns on a Simple-mode save (§ *Architecture B*); `edit()` returns `ProxyFormResource`. The
  T30-era comment at `:150-156` (which explains the clearing as intended) is replaced.
- **`App\Http\Resources\ProxyResource`** (modified) — emission via the resolver (§ *Architecture C*).
- **`App\Http\Resources\ProxyFormResource`** (new) — the Amendment-A carve-out, with a docblock
  naming Amendment A, AC14(b)'s four binding conditions, and the rule that no other caller may use
  it.
- **`App\Http\Resources\DeliveryResource`** (modified) — `created_at` (rider 2).
- **`App\Actions\SweepDueRetries`** (modified) — reads `RetryPolicy::sweepGraceSeconds()` instead of
  `config('retry.sweep_grace_seconds')` (rider 1). No other change; the unbounded `->get()` stays
  as review-06 Nit 2 left it (backlog, not #7's).
- **`App\Pipeline\PipelineFactory`** — **unchanged**, deliberately (§ *Architecture D*).
- **`App\Enums\ProxyMode`**, `App\Models\Proxy`, `config/retry.php`, routes, policies, permissions,
  migrations — **all unchanged**.
- **Frontend** (per design-07, no new primitive or dependency; `AlertTitle` is an
  already-generated, currently-unused primitive — its first application use, not an addition):
  `ProxyForm.vue` (Mode field a11y wiring + corrected help text + `isDowngrading` disclosure +
  the ruling-3 submit normalisation); `proxies/Show.vue` (mode-summary caption; rewritten Retry
  policy card comment; no computed change); `Edit.vue` (`ProxyFormProxy` prop type);
  `types/proxies.ts` (`ProxyDetail`/`ProxyListItem` docblocks, new `ProxyFormProxy`, `Delivery.created_at`);
  `proxies/events/Show.vue` (replay-group label/order from `created_at` — rider 2). The
  disclosure's third bullet composes the default from the existing consts
  (`RETRY_DEFAULT_ATTEMPT_LIMIT`, `proxyRetryBackoffStrategyLabel(null)`) rather than a second
  hand-written literal — the rendered string is identical to the approved copy, and it satisfies
  the Designer's non-blocking note (ii) that AC12 makes it the copy's job to stay true.

## Validation

- **`StoreProxyRequest` / `UpdateProxyRequest` — rules unchanged, verbatim:**
  `retry_attempt_limit` → `['nullable', 'integer', 'min:1', 'max:10', 'prohibited_if:mode,simple']`;
  `retry_backoff_strategy` → `['nullable', Rule::enum(RetryBackoffStrategy::class), 'prohibited_if:mode,simple']`.
  Only their **docblocks** change: the comment that presently explains `prohibited_if` as *how an
  enhanced→simple switch clears stored values (T30)* is now false and is replaced with its ADR-018
  Decision 3 purpose — *a Simple-mode save can never change a dormant policy*.
- **`mode`** → `['required', Rule::enum(ProxyMode::class)]`, unchanged. #7 adds **no new validation
  rule on `mode`** (AC17: no cooldown, no drain-before-switch, no in-flight predicate — a rule of
  that kind would be a direct AC17 breach).
- **System invariants (binding):**
  - **Nothing resolves retry behaviour from a persisted value without first establishing that the
    proxy is Enhanced** (AC14(a)). After #7 the two columns are read in exactly three methods:
    `RetryPolicy::configuredAttemptLimitFor()`, `RetryPolicy::configuredStrategyFor()`, and
    `ProxyFormResource::toArray()`. A fourth reader is a defect.
  - **A Simple-mode save never writes either retry column** — not a value, not NULL.
  - **No response other than the Edit page's may carry a dormant value** (AC14(b), Amendment A).
  - **`mode` is never snapshotted, serialized into a job, or inferred from an artefact** (ADR-018
    Decisions 1 and 5).
  - **`RetryPolicy` is the only reader of `config('retry.*')`** — all seven keys after rider 1,
    each guarded by `positiveConfigInt()`.
  - The clamp into `[1, max_attempt_limit]` applies regardless of column content and **after** the
    mode gate.

## Riders — scheduled here, and why they belong here

Both were identified while resolving Q-07-02 and routed to me. Neither is mode-related; both sit
on seams #7 is already opening, and leaving them would mean touching the same two files twice.

**Rider 1 — a guarded `RetryPolicy::sweepGraceSeconds()` (review-06 Minor 9).**
`app/Actions/SweepDueRetries.php:33` reads `config('retry.sweep_grace_seconds')` directly. Two
faults, one of which is live: it is the only `retry.*` key read **outside** `RetryPolicy`,
breaching the ADR-015 single-reader invariant that **ADR-018 Decision 2 now leans on for the mode
gate** — an invariant with a hole in it is a weaker foundation than one without; and it is the only
one of the seven keys with **no `positiveConfigInt()` guard**, so a blank
`RETRY_SWEEP_GRACE_SECONDS` coerces to `0`, making the cutoff `now()` and re-dispatching
`RetryDelivery` for **every** `retrying` delivery on every minute's schedule tick. The unique
attempt key would arbitrate the duplicates, so it is not a correctness bug — it is a
fail-quiet load multiplier that looks like normal operation. Fix: `sweepGraceSeconds(): int`
returning `positiveConfigInt('sweep_grace_seconds')`, and `SweepDueRetries` calls it. Behaviour
change on explicit `0` is ruled at § *Technical rulings* 7.

**Rider 2 — `DeliveryResource` gains a real `created_at` (review-06 Minor 5, Reviewer-routed to
the Principal Engineer).** Accepted as the Reviewer ruled. `deliveries.created_at` already exists,
so this is **serialization only** — no data-model change, no Owner gate. Scope: the resource field,
the `Delivery` TS type, and the event-detail page's replay-group label/ordering, which today take
the label from the earliest attempt's `started_at` and the order from the group's highest
`Delivery.id` (`resources/js/pages/proxies/events/Show.vue:150-176`). That derivation degrades to a
bare `Replay` with no time exactly when a FIFO replay is queued behind a held line with zero
attempts — the scenario the feature exists to make visible (design-06 Screen 3: *"Replay — {time}
(one group per replay, newest first)"*). The pinning assertion
`->missing('event.deliveries.0.created_at')` (`tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php:95`)
is inverted in the same task. Landing the field without switching the consumer would leave the
defect and the dead field, so they are one task.

**Rider 3 — review-06 Minor 8, all three obligations.** (a) stop clearing in
`ProxyController::update()` and (b) mode-gate `attemptLimitFor()`/`strategyFor()` **must land in a
single task** — the Reviewer's "(a) without (b) is a defect" is binding, because preserving the
columns without gating the resolver lets a dormant value govern a Simple proxy (a direct PRD-06
AC2 / PRD-07 AC14(a) violation, and the reason #6 shipped the clearing deliberately). (a) is
adopted **as omission, not as relaxation** — Minor 8(a)'s second half is declined at
§ *Technical rulings* 2. (c) invert **and rename**
`RetryPolicyFormAcceptanceTest::test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null`
→ `…_preserves_stored_values` (its method name asserts the old outcome, so a rename is required,
not just a re-assert), plus the Show-page suppression coverage Q-07-01(b) consequence (1) requires
(§ *Test strategy*).

## Explicitly out of scope for this plan

Both are already routed to the **Senior Developer on a separate branch off `main`** (flat path per
`CLAUDE.md`). **The Task Planner must not schedule either**, and the Reviewer should expect neither
in #7's diff:

- **The `DeliverToDestination.php:197` soft-delete `TypeError`.** `Delivery::proxy()` is a plain
  `belongsTo` while `Proxy` uses `SoftDeletes`, so `$delivery->proxy` is `null` for a soft-deleted
  proxy and the failure branch raises a `TypeError` in the worker (PHPStan cannot see it —
  `@property-read Proxy $proxy`). The fix is the `ProcessIngestedWebhook:50` precedent (load
  trashed-inclusive), and ADR-018 Decision 5 already states the rule. **This plan does not depend
  on that fix and does not worsen the exposure:** `attemptLimitFor(Proxy $proxy)` is already
  typed non-nullable, so the new mode gate reads `$proxy->mode` only where a non-null `Proxy`
  was already required. The two changes touch different lines of different files and will not
  conflict.
- **The missing `trustProxies()` configuration in `bootstrap/app.php`.**

Also out of scope, per the PRD: mapping (#8), any storage/retention change (AC20), any retry or
replay semantic change (AC21), any processing-mode change (AC22), a third mode or sub-toggles
(AC23), notification/analytics/audit surfaces for mode changes (AC24), and any numeric target
(AC25). Review-06's other follow-ups (Minors 1–4, 6, 9's sibling items, Nits 1–4) stay where the
Reviewer routed them.

## Risks

1. **A Simple proxy now carries editable-but-invisible state.** A member can hold a dormant policy
   for months and be surprised when an upgrade re-activates it. This is the **Owner's decided
   trade** (Q-07-01(b): "an accidental downgrade must not silently destroy tuned configuration"),
   and it is disclosed at the moment of the downgrade (AC14(c)) and made visible again the moment
   Enhanced is selected in the form (Amendment A condition iii). **Accepted by requirement.**
2. **The Edit payload carries a value that is never rendered.** Named and accepted in Amendment A
   and assessed security-neutral at Q-07-02(5): two clamped, inert scalars, bounded by validation
   *and* by the resolver's clamp, reaching only a member who already holds the AC5 update
   permission — the one person who could set them anyway. No secret, payload, or header is
   involved. The exposure is confined to one resource class by construction (§ *Architecture C*).
   **Non-blocking.**
3. **`ProxyResource`'s two fields change meaning without changing name.** A future reader could
   assume `retry_attempt_limit` is the raw column and reintroduce a leak. Mitigations: the emission
   goes through the resolver (so the columns are simply not in reach), the TS docblocks say what
   the field means, and a test pins `null` on Index and Show for a dormant-carrying Simple proxy.
   **Closed by test.**
4. **Ruling 3's client normalisation is the only thing standing between a dormant-carrying Simple
   proxy and an unfixable 422.** If a later change to `ProxyForm.vue` drops it, the Edit form of
   every Simple proxy holding a dormant policy breaks on save, on a field the form does not render.
   Mitigation: a server-side acceptance test that submits a Simple-mode save **with** retry values
   present asserts the 422 explicitly (so the coupling is documented as intentional), plus one that
   submits the well-behaved shape and asserts preservation. There is **no frontend test harness**
   (deferred backlog item) so the client half is manual-verification-only — stated here rather than
   discovered at review.
5. **Mixed treatment across a switch will look like a bug to someone.** A single proxy's history
   may contain events treated under either mode, and one delivery may have attempt 1 under one mode
   and a retry under the other. AC11 declares this normal and forbids reporting it as a fault;
   nothing in the product displays a per-event mode, so there is nothing to reconcile. **Recorded
   so nobody "fixes" it.**
6. **An already-scheduled retry survives a downgrade and makes one more attempt.** Bounded (it
   terminalizes at the next settle because the comparison is `>=`), and the alternative — cancelling
   scheduled work mid-flight — is the only way this path could drop a delivery, which AC10 forbids.
   **Accepted; the correct trade.**
7. **Rider 1 turns a previously-legal `0` into a startup-time exception on the sweep path.** No
   environment sets the key; every test reads the resolved value. Consistent with the six sibling
   keys and with the review-05 M-1 posture. **Non-blocking; ruled at § *Technical rulings* 7.**
8. **AC19/AC22/AC23/AC24/AC25 rest on inspection, not on an automated gate** — they assert the
   absence of things (no mapping, no processing change, no third mode, no audit surface, no
   numeric target). The same posture review-06 recorded for #6's AC19/21/23/24. **Stated so the
   Reviewer's AC-coverage verdict is accurate rather than optimistic.**

## Dependencies

- **No new package; no stack change.** `docs/stack/stack.md` is untouched. No new npm dependency,
  icon, or `ui/*` primitive (design-07 Handoff — `AlertTitle` is already generated).
- **ADR-018 — Accepted** (Project Owner, 2026-08-25). Decisions 1–6 are binding on every section
  above. **ADR-015 Decision 3's persistence invariant is superseded by it**; the rest of ADR-015 is
  Accepted, operative and relied on here — especially the single-resolver rule, which this plan
  makes stronger (rider 1) rather than weaker.
- **Accepted and relied on, unchanged:** ADR-001 (spine), ADR-002 (the selector — extended, never
  widened), ADR-003, ADR-005, ADR-009 incl. Amendments (the AC5 permission gate and the
  display/enforcement split), ADR-010/012/013/014 (capture, holds, the output store, the cleaned
  signal — all mode-independent and untouched), ADR-011/016 (the orthogonal processing axis),
  ADR-017 (replay).
- **PRD-07** Approved + Amendments A and B · **design-07** PM-approved (approval note governing) ·
  **Q-07-01** (Owner-resolved) · **Q-07-02** (PE-resolved) · **Q-07-03** (PM-resolved).
- **Features #5 and #6 — Done and merged** (PR #6 `ed421f1`; PR #8 `e1c2894`). #7's governed-step
  set (AC6) is exactly what those two built.

## Implementation Notes

- **(a) and (b) of review-06 Minor 8 land in one task, never in sequence.** Stopping the clearing
  without the resolver gate ships a dormant value governing a Simple proxy. The Task Planner must
  not split them, and a partial commit is not a green tree.
- **The mode gate is written once per column, inside `RetryPolicy`.** `attemptLimitFor()` and
  `strategyFor()` must not each carry their own `if`; they compose the `configured*For()` readers.
  One `if` per column, in one class, is the property one test pins.
- **The clamp and the config guards run after the gate**, not before, and neither changes.
- **`PipelineFactory` is not edited.** Not the enum branches, not the reserved comments. If a diff
  touches it, something has gone wrong.
- **Preservation is achieved by omission**, never by reading the current values and writing them
  back — a read-modify-write would introduce a race a save has no business having.
- **`ProxyFormResource` has exactly one caller.** A second caller is a review finding, not a
  refactor.
- **Never infer mode** from a `dispatched_payloads` row, a non-NULL retry column, a delivery's
  history, or anything else (ADR-018 Decision 1).
- **Never snapshot mode** into a job argument, an event row, a delivery row, or a request-scoped
  cache (ADR-018 Decision 5).
- **Load the proxy trashed-inclusive wherever a policy is resolved off a delivery** — the
  `ProcessIngestedWebhook:50` precedent. The `DeliverToDestination:197` instance of this is the
  Senior Developer's separate fix; do not duplicate it here, and do not "fix it while I'm in the
  file".
- **Copy is the Designer's.** Use design-07's help text and disclosure copy verbatim; derive the
  "(5 attempts, exponential)" words from the existing data consts rather than retyping them. No
  roadmap numbers, no implication that mapping exists (the binding PM copy constraint).
- **The disclosure is not a gate** — no confirm click, no checkbox, no modal, never dismissible,
  and it sits between the Mode control and the submit action.
- **No new rule on `mode`, no new gate on saving, no warning keyed to outstanding deliveries**
  (AC17).
- Pint + PHPStan L7 green per task; Conventional Commits with context list items (`CLAUDE.md`);
  tests use `createQuietly()` and no per-class `RefreshDatabase` (`docs/standards/testing.md`).

## Test strategy

Backend PHPUnit (`./vendor/bin/sail test`), `Http::fake()` for delivery, `Queue::fake()` for
dispatch/delay assertions, `travel()` for schedules. There is **no frontend test harness** (deferred
backlog item), so design-07's Flows A–E are manual-verification steps, recorded as such on their
tasks. Mapped to acceptance criteria:

**The resolution gate (AC6(b), AC14(a), AC21) — unit, `RetryPolicyTest`:**
- **The headline invariant:** a Simple proxy with `retry_attempt_limit = 8`,
  `retry_backoff_strategy = fixed` resolves **5 / exponential**, and its `delayBefore()` follows the
  exponential curve — *behaving identically to a Simple proxy that never had a policy* (AC14(a),
  AC21). Asserted as one test over both proxies, so "identical" is the assertion, not an inference.
- An Enhanced proxy with the same columns resolves **8 / fixed** (the gate does not over-fire).
- An Enhanced proxy with NULL columns resolves 5 / exponential (the pre-existing unconfigured
  meaning survives).
- The clamp still applies after the gate: an Enhanced proxy with a column above
  `max_attempt_limit` clamps to the cap; a Simple proxy with the same column still resolves the
  default.
- `configuredAttemptLimitFor()` / `configuredStrategyFor()` return `null` for a Simple proxy
  whatever the columns hold, and the column value for an Enhanced one.
- `worstCaseSpan()` is unchanged and still well inside `RetentionPolicy::windowFor()` — the AC18
  guard test continues to pass untouched (proof the gate cannot widen the bound).
- The config-sanity `RuntimeException`s still fire (the existing blank-env regression tests from
  review-06 M-2 must keep passing after the rewrite).

**Consumers inherit without branching (AC6(b), AC14(a)) — feature:**
- A **Simple** proxy holding `retry_attempt_limit = 2` whose delivery fails: attempt 2 is scheduled
  (not terminalized), and the delivery terminalizes at the **system default 5**, proving
  `DeliverToDestination` resolved through the gate. This is the test that would have caught the
  defect ADR-018 exists to prevent.
- The same proxy's `DeliveryResource.attempt_limit` renders **5**, not 2 (the resource inherits the
  gate with no code of its own).
- Mid-flight downgrade: an Enhanced proxy with limit 8, three attempts made, downgraded to Simple —
  the next failure terminalizes immediately (`>=`, not `==`), emits `DeliveryExhausted` exactly
  once, and releases a FIFO line if held.
- Mid-flight upgrade: a Simple proxy at attempt 4 upgraded to Enhanced with limit 8 continues
  retrying to 8; the span stays clamped.

**Persistence (AC14, AC14(b)(iv)) — feature, `RetryPolicyFormAcceptanceTest`:**
- **Inverted + renamed** (Minor 8(c)): `test_switching_enhanced_to_simple_on_update_preserves_stored_values`
  — an Enhanced proxy with 4 / fixed, saved as Simple, still holds **4 / fixed** afterwards.
- A Simple proxy holding a dormant policy, saved again as Simple (no mode change), still holds it —
  the save neither overwrites nor clears (AC14(b)(iv)).
- **The upgrade round trip, end to end** (AC14 lead sentence, Q-07-03's pinning suggestion): a
  Simple proxy holding 4 / fixed → the Edit page's payload carries 4 / fixed → a save with
  `mode = enhanced` and those values persists them, with nothing re-entered.
- An upgrade save that *tunes in the same save* (`mode = enhanced`, limit 9) persists 9
  (AC14(b)(iii)).
- An Enhanced save with NULL fields still clears to the system-default sentinel (PRD-06 AC2's
  unconfigured meaning is not collateral damage).
- **`prohibited_if` still bites:** a `mode = simple` submission carrying `retry_attempt_limit`
  returns 422 on that field (store and update) — pinning ruling 2's decision **and** documenting
  ruling 3's client coupling as intentional (Risk 4).
- A `mode = simple` update still freely sets unrelated fields (`response_status`/`response_body`) —
  the existing AC20-parity test, unchanged and still green.

**Presentation (AC12, AC14(b), AC16) — feature:**
- **A Simple proxy holding 8 / fixed emits `null` for both fields on Index *and* Show** — the
  AC14(b) read-surface guarantee, and the server-side half of Minor 8(c)'s "Show-page suppression".
- **The same proxy's Edit payload emits 8 / fixed** — the Amendment A carve-out, asserted as
  deliberate rather than accidental.
- An **Enhanced** proxy with 6 / fixed emits 6 / fixed on Index, Show **and** Edit — the existing
  `test_proxy_resource_emits_both_fields_on_index_show_and_edit` continues to pass unmodified
  (it uses an Enhanced proxy), and is kept as the companion to the Simple case.
- No **non-Edit** response carries a dormant value: the events list, event detail and delivery
  payloads are asserted to contain no proxy retry-column key at all (AC14(b)'s "any response shaped
  for one of them").

**Switch safety and lifecycle (AC1, AC9, AC10, AC11, AC13, AC17) — feature:**
- A proxy switched Simple → Enhanced composes `CaptureDispatchedStep` for its **next** event and
  not for one already dispatched; Enhanced → Simple stops producing new rows and **deletes none**
  (AC6(a), AC9, AC13).
- A queue redelivery straddling a switch produces exactly one `dispatched_payloads` row and no
  error (the `updateOrCreate` idempotency).
- A downgrade with events queued / claimed under FIFO / awaiting a scheduled retry / mid-replay
  loses, errors, duplicates and strands **nothing**; the FIFO line advances (AC10, AC17).
- An expired event's dispatched output produced under Enhanced is erased by the normal expiry pass
  **after** the proxy was downgraded — retention expiry is still the only eraser, and the downgrade
  added none (AC13, AC20).
- A replay on a now-Simple proxy neither writes nor deletes the event's existing dispatched-output
  row (AC13, AC11).
- Switching mode does not recreate the proxy, its destinations, its ingest URL, or its history
  (AC1); it is reachable at create and at edit through the same single attribute, with no separate
  workflow (AC2); `simple` remains the default for a proxy created without a choice (AC3).
- **Permissions (AC5):** a member without `proxy:update` cannot change a proxy's mode (403/404 per
  the existing scope rules); a member who can update can; **no new permission exists** — asserted
  by pinning the `TeamPermission` case list unchanged.

**Riders:**
- `SweepDueRetries` uses the guarded grace: a blank/zero `retry.sweep_grace_seconds` raises the
  named `RuntimeException` instead of sweeping every `retrying` delivery; the existing overdue /
  not-yet-due / terminal cases keep passing through the new accessor (rider 1).
- `DeliveryResource` emits a real `created_at`; the pinning `->missing('event.deliveries.0.created_at')`
  assertion is inverted to a `where`/present assertion (rider 2).

**Inspection-only (no automated gate, recorded honestly):** AC4, AC7, AC8, AC15, AC18, AC19,
AC22–AC25 — each asserts the absence of a thing (no backfill, no new mode gate, no entitlement, no
conflation of the two axes, no new step, no mapping, no processing change, no third mode, no audit
surface, no numeric target). AC4 additionally has a cheap positive proof worth taking: the #7 diff
contains **no migration file**.

## Milestones (task-breakdown-ready)

Ordered; each independently verifiable and green. **Nothing is blocked on an approval** — the one
Owner gate cleared on 2026-08-25.

- **M1 — The resolution gate + the persistence rule (review-06 Minor 8(a)+(b), together).**
  `RetryPolicy::configured*For()` + the rewritten `attemptLimitFor()`/`strategyFor()`;
  `ProxyController::store()`/`update()` omission on a Simple save; the two Form Request docblocks;
  the inverted-and-renamed `RetryPolicyFormAcceptanceTest` case. *Verify:* the resolution-gate and
  persistence test groups; the full #6 suite still green (`DeliverToDestination`, `DeliveryResource`
  and the retry engine must pass **unmodified** — that is the proof they inherit the gate without
  branching).
- **M2 — Read-surface suppression and the Edit carve-out.** `ProxyResource` emission via the
  resolver; new `ProxyFormResource`; `ProxyController::edit()`; TS types (`ProxyDetail`,
  `ProxyListItem`, new `ProxyFormProxy`); `Show.vue`'s rewritten Retry-policy-card comment.
  *Verify:* the presentation test group, including "no non-Edit response carries a dormant value".
- **M3 — The form surface.** Mode field a11y wiring, corrected help text, the `isDowngrading`
  disclosure (`Alert`/`AlertTitle`/`AlertDescription` in an `aria-live` region), and the ruling-3
  submit normalisation. *Verify:* `pnpm` lint/types/format + `pnpm run build`; manual Flows A–C and
  the dormant-carrying-Simple-proxy save (Risk 4).
- **M4 — The Show surface.** The one-line present-tense mode caption under the header. *Verify:*
  frontend triad; manual Flow E, both mode states, with and without a dormant policy (the card must
  read identically).
- **M5 — Riders.** `RetryPolicy::sweepGraceSeconds()` + `SweepDueRetries`;
  `DeliveryResource.created_at` + the events-detail group label/ordering + the inverted pinning
  assertion. *Verify:* the rider test group; the #6 read-surface suite green.
- **M6 — Quality sweep.** Full suite parallel, Pint, PHPStan L7, frontend triad, `pnpm run build`
  (a stale checked-in bundle proved nothing at review-06 M-3 — rebuild before any live check), and
  a docs cross-check: every docblock citing "a simple-mode proxy's columns are always NULL" or
  ADR-015 Decision 3's persistence invariant now points at ADR-018.

## Handoff

- **Inputs:** Approved **PRD-07** incl. **Amendments A and B**; PM-approved **design-07** (with its
  approval note governing); **Q-07-01** (Owner), **Q-07-02** (PE), **Q-07-03** (PM) — all RESOLVED;
  **ADR-018** (Accepted) and the annotated **ADR-015**; ADR-001/002/003/005/009/010/011/012/013/014/016/017;
  plans 03–06; `docs/reviews/review-06-retry-replay.md` (Minors 5, 8, 9 and Ruling 2); current code
  on `feat/item-07-enhanced-mode-toggle` (`RetryPolicy`, `ProxyController`, `ProxyResource`,
  `DeliveryResource`, `Store/UpdateProxyRequest`, `PipelineFactory`, `DeliverToDestination`,
  `SweepDueRetries`, `Proxy`, `config/retry.php`, `ProxyForm.vue`, `Create.vue`, `Edit.vue`,
  `proxies/Show.vue`, `proxies/events/Show.vue`, `types/proxies.ts`,
  `data/proxyRetryBackoffStrategies.ts`, `components/ui/alert/*`, and the retry/proxy test suites);
  `docs/standards/` (architecture, coding, testing, planning, documentation); `docs/stack/stack.md`.
- **Outputs:** this plan. **No new ADR** and no new question document.
- **Dependencies:** none new; within stack.
- **Outstanding Questions:** **none.** Q-07-01, Q-07-02 and Q-07-03 are all resolved, and this plan
  needed nothing further from the Product Manager or the Designer: every PRD-07 acceptance criterion
  is feasible as stated, and design-07 as approved is buildable as specified. No requirement is
  reinterpreted and no design decision is re-made — the two calls delegated to me (design-07's
  correction C1 enforcement point, and whether a client guard survives) are ruled at
  § *Technical rulings* 5 and 6.

### Owner-approval flags (✋) — **none outstanding**

The list is stated in full, as the house format requires, because it is the single place the Owner
reads it. #7 carried **one** flag and it is **already cleared**:

1. ~~**ADR-018 — one mode selector, two evaluation points**, carrying the partial supersession of
   Accepted **ADR-015 Decision 3**'s persistence invariant.~~ **ACCEPTED — Project Owner,
   2026-08-25**, exactly as drafted. ADR-015 keeps its file, status and full text and now carries
   the inline supersession annotation at Decision 3, marked superseded rather than pending. **No
   work in this plan is contingent on anything.**

**Not tripped, verified item by item against `CLAUDE.md`'s major-decision list:** no new Composer or
pnpm dependency; no stack change (`docs/stack/stack.md` untouched); **no data-model change** — no
column, table, index, enum value, migration, backfill or default (§ *Data Model*); no new route,
permission or gate; no irreversible or destructive operation — #7's whole point is that a downgrade
now destroys **less** than it did; and no security-relevant surface — Amendment A's cost is two
clamped, inert scalars travelling in a payload already restricted to a member holding the AC5
update permission, assessed security-neutral at Q-07-02(5) and re-confirmed here (no secret,
payload, header, egress path, route or permission is added or widened). V3, V5 and V8 are **not**
reopened.

**Why no new ADR.** I checked each candidate against the ADR bar and none clears it: the new
`ProxyFormResource` is a serialization class implementing a decision the Owner already ratified
(ADR-018 Decision 4 + Amendment A), not a decision of its own; the resolver-mediated `ProxyResource`
emission is one of the two shapes ADR-018 Decision 4 pre-authorised; the client submit
normalisation is the mechanism Q-07-03 Option A named; keeping `prohibited_if` is ADR-018 Decision
3, restated; and the two riders are a config guard and a serialization field, both reversible in a
line. Each is recorded as a **named technical ruling** above instead, per the house convention for
rulings on matters the upstream artifacts left silent.

### Certification (Principal Engineer, 2026-08-25)

I have verified that **PRD-07 is Owner-approved** (2026-08-21, amended in place by the Product
Manager on 2026-08-25 without reopening) and that **design-07 is PM-approved** (2026-08-25) — the
mandatory design gate for the PRD's UX Direction — and I have written this plan against design-07's
**approval note**, which governs over the spec body until the Designer lands the two required
corrections. I have read ADR-001–018, plans 03–06, review-06's Minors 5/8/9 and Ruling 2, and the
affected code on this branch. Every section above traces to PRD-07 acceptance criteria and to the
approved design; the seven named technical rulings stay inside the upstream artifacts' assumptions
and none reinterprets a requirement, a design decision, or an Owner ruling.

**I self-certify this plan in full** under the delegated plan gate in `CLAUDE.md`. There is no
carve-out: the single Owner gate #7 ever carried (ADR-018) was Accepted before this plan was
written, this plan introduces no data-model change, no dependency, no stack change, no
security-relevant surface and nothing irreversible, and it therefore surfaces **no new major
decision** to the Project Owner. `docs/stack/stack.md` is unchanged. Nothing here changes a
requirement or reopens Q-07-01, Q-07-02, Q-07-03, PRD-05's retention lifecycle, Q-06-01's retry
values, or the #4 processing axis.

- **Next Agent:** **Task Planner** — unblocked immediately; no approval is pending. Two standing
  constraints to carry into the breakdown: review-06 Minor 8's **(a) and (b) must be one task**
  (a partial landing is a shipped defect), and the two items under § *Explicitly out of scope for
  this plan* — the `DeliverToDestination.php:197` soft-delete `TypeError` and the missing
  `trustProxies()` configuration — belong to the Senior Developer on a separate branch off `main`
  and must **not** appear in #7's task list.
