# ADR-018: One mode selector, two evaluation points — composed steps and resolved configuration (partially supersedes ADR-015 Decision 3)

- **Status:** **Accepted — Project Owner, 2026-08-25.** Gate carried and cleared: the **partial
  supersession of one named position of ADR-015** (Accepted, Project Owner 2026-08-12).
  **No data-model change**, no new column/table/index, no new dependency, no stack change.
- **Author:** Principal Engineer
- **Date:** 2026-08-25
- **Feature:** prd-07-enhanced-mode-toggle (AC6, AC9, AC11, AC12, AC14, AC18; the mechanism
  behind the answers to `docs/questions/prd-07-q-07-02-mode-step-composition.md` items 1 and 4)
- **Relationship to ADR-002:** extends it, never widens it — `mode` stays a pure selector.
- **Relationship to ADR-015:** **partially supersedes** — Decision 3's persistence invariant
  only (see § Positions superseded). Everything else in ADR-015 stands, Accepted and operative:
  the `deliveries` state machine, the CAS transitions, the backoff curve, the delayed-job +
  sweeper scheduling, the terminal state and its event, and **`RetryPolicy` as the single
  resolver** — which this ADR makes more load-bearing, not less.

## Question
ADR-002 made `mode` the single gate the `PipelineFactory` reads at pipeline-build time, and
enhanced-only behaviour has so far always been a **pipeline step** — gated structurally, because
an uncomposed step cannot run. #6 introduced the first enhanced-only behaviour that is **not a
step**: per-proxy retry-policy configurability (PRD-06 AC2). ADR-015 gated it in **persistence**
instead — "simple-mode proxies always hold NULL/NULL (validation rejects, and the controller
clears…)" — which was correct and sufficient while that invariant held.

Q-07-01(b) (Project Owner, 2026-08-21) removed it. A simple-mode proxy may now hold a persisted
retry policy, kept dormant and restored on a return to Enhanced (PRD-07 AC14). The reviewer's
Ruling 2 in `docs/reviews/review-06-retry-replay.md` states the consequence precisely:
*"preserving the columns without mode-gating the resolver lets a dormant value govern a simple
proxy"* — a direct PRD-06 AC2 / PRD-07 AC14(a) violation. `RetryPolicy::attemptLimitFor()` reads
the column unconditionally today (`app/Services/RetryPolicy.php:41`), and that is safe **only**
because of the invariant Q-07-01(b) retired.

So: where does the mode gate live for enhanced-only behaviour that is not a pipeline step, and
what is the general rule #8/#9/#12 must follow so PRD-07 AC18's extensibility holds without a
second gate, a per-capability toggle, or an inferred mode?

## Decision

**(1) One selector, exactly two evaluation points — and no third mechanism.**
`mode` remains the single ADR-002 attribute. It is evaluated in exactly two kinds of place, by
the kind of thing being gated:

| Kind of enhanced-only behaviour | Gate | Where | Serves |
|---|---|---|---|
| A pipeline **step** | **Composition-time, structural** — the step is not in the composed list, so it cannot run | `PipelineFactory::stepsFor()` (`app/Pipeline/PipelineFactory.php:28`) | PRD-07 AC6(a) — `CaptureDispatchedStep`; later #8/#9/#12 |
| Per-proxy **configuration** consulted outside the pipeline | **Resolution-time** — that configuration's **single resolver service** establishes `mode === Enhanced` before reading any per-proxy column, and otherwise returns the system default | `App\Services\RetryPolicy` | PRD-07 AC6(b), AC14(a) |

Two evaluation points, **one gate**: both read the same `mode` enum directly. This is not the
"second gate" PRD-07 AC6/AC18 and Q-07-02(1) forbid — a second gate would be a different
attribute, a per-capability sub-toggle (AC23), or an **inference**. Inference stays forbidden in
both directions and is now explicitly enumerated: nothing may conclude a proxy's mode from the
presence of a `dispatched_payloads` row, a non-NULL retry column, a delivery's history, or any
other by-product of an earlier enhanced period. Those artefacts legitimately outlive the mode
that produced them (PRD-07 AC13/AC14) — reading them as mode is exactly the ambiguity ADR-002
rejected as its first alternative.

**(2) `RetryPolicy` becomes the first resolution-time gate.** Both public policy readers
establish Enhanced before touching a column; a Simple proxy resolves the fixed system default
whatever the columns hold:

- `attemptLimitFor(Proxy)` — Enhanced ⇒ `retry_attempt_limit ?? config default`; Simple ⇒ the
  config default. The existing `[1, max_attempt_limit]` clamp and the `positiveConfigInt()`
  config-sanity guards apply unchanged, **after** the gate.
- `strategyFor(Proxy)` — Enhanced ⇒ `retry_backoff_strategy ?? Exponential`; Simple ⇒
  `Exponential`.
- `delayBefore(Proxy, int)` inherits the gate through `strategyFor()`; no separate branch.
- `worstCaseSpan()` is proxy-free and unchanged — the AC18 structural bound is unaffected,
  and remains true in both modes because the gate can only ever resolve *to* the default.

`RetryPolicy` remains the **only** reader of the two `proxies` retry columns and of
`config('retry.*')` (ADR-015 Decision 3, plan-06's binding invariant — unchanged and reaffirmed).
One gate, one choke point: every existing consumer — `DeliverToDestination::settleDelivery()`
and `DeliveryResource.attempt_limit` — inherits the correct behaviour with no branch of its own.

**(3) The gate moves from persistence to resolution; validation keeps its half.**
"Simple ⇒ NULL columns" is retired (§ Positions superseded). In its place:

- **`prohibited_if:mode,simple` is KEPT** on `retry_attempt_limit` / `retry_backoff_strategy` in
  both proxy Form Requests. It now serves a second, sharper purpose: a submission that saves a
  proxy as Simple can never *change* the dormant values, so dormancy is enforced at the boundary
  as well as at resolution. *(This departs from review-06 Minor 8(a)'s sketch, which proposed
  relaxing the rule; relaxing it would let a Simple-mode save silently overwrite the very values
  AC14 preserves. Nothing else in Minor 8 changes.)*
- **A `mode = simple` save does not write the retry columns at all** — the controller omits them
  from the update rather than writing NULL. Preservation is achieved by *not writing*, which is
  the smallest possible change to `ProxyController::update()` and needs no new state.
- **A `mode = enhanced` save writes exactly what was submitted** — a value sets it, NULL means
  "use the system default", which is PRD-06 AC2's existing unconfigured meaning.
- **Open, routed, and not decided here:** how the *previous* values reach an upgrade save so
  PRD-07 AC14's "applies again with its previous values, without the member re-entering
  anything" holds, given that AC14(b) also forbids a dormant value on the form. That is a PRD-07
  requirement-scope question, raised as
  `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md` to the Product Manager.
  **Neither candidate answer changes any decision in this ADR** — both keep the resolution gate,
  the retained `prohibited_if`, and the don't-write-on-simple rule; they differ only in whether
  the Edit form pre-fills the preserved values or the upgrade save restores them server-side.

**(4) No read surface may carry a dormant enhanced-configuration value.** While a proxy is
Simple, every response shaped for a read surface presents the configuration **actually in
force** — for retry, the system default. Two admissible shapes, both already precedented:
resolve through the single resolver and emit the effective value (`DeliveryResource:40`), or
emit `null` and let the existing display helper render "(default)". What is **not** admissible
is today's `ProxyResource:49-50`, which emits the raw columns unconditionally — correct only
under the retired invariant, and an AC12/AC14(b) breach the moment preservation lands. The
Edit-form pre-fill path is the single point Q-07-03 may carve out; every other surface (Show,
Index, the events/delivery surfaces) is bound by this decision.

**(5) Mode is read live, from the proxy row, at the moment of use — never snapshotted.**
No mode value is serialized into a queued job argument, stored on an event, delivery, ordering
row, or attempt, or carried across a request. Consumers load the proxy **trashed-inclusive**
(the `ProcessIngestedWebhook:50` precedent — an event captured before a later soft-delete must
still deliver and must still resolve a policy). Within one pipeline run every step reads the one
`$ctx->proxy` instance loaded at entry, so no two steps of the same event can observe different
modes; across separate runs, attempts, retries, sweeps and replays of the same event, the
**current** mode governs each independently (PRD-07 AC9, with AC11's mixed treatment as the
accepted and expected consequence).

**(6) The recipe every later enhanced capability follows (PRD-07 AC18).**
A new enhanced-only capability adds *at most* two things and re-models nothing:
- if it is processing behaviour → a `PipelineStep` appended in the existing
  `ProxyMode::Enhanced` branch at its reserved position (`#9 NormalizeStep`, `#8 MapStep`
  before `CaptureDispatchedStep`; `#12 ChangeDetectStep` in the tail stage);
- if it carries per-proxy configuration → its own columns/tables (ADR-002: "enhanced config
  attaches as its own config") **plus its own single resolver repeating the Decision-2 gate**.

No change to the `mode` attribute, the toggle, the composition gate, or this ADR is required by
any of them.

## Positions superseded — exactly one, ADR-015, forced by the Owner's Q-07-01(b) ruling

| ADR-015 position (verbatim) | Superseded to |
|---|---|
| **Decision 3:** "Simple-mode proxies always hold NULL/NULL (validation rejects, and the controller clears, values when `mode = simple`) — the system default applies to them fixed and silently (AC1/AC2)." | Simple-mode proxies **may** hold non-NULL dormant values (PRD-07 AC14; Owner ruling Q-07-01(b), 2026-08-21). The controller no longer clears. **The system default still applies to them fixed and silently** — the guarantee is unchanged; only its mechanism moves, from persistence to resolution: `RetryPolicy` establishes `mode === Enhanced` before reading either column (Decision 2). `prohibited_if:mode,simple` is retained (Decision 3). |

Not superseded, and relied on here: ADR-015's "`App\Services\RetryPolicy` is the **single
resolver** … no other consumer reads the columns or `config('retry.*')`" — this ADR makes that
sentence the load-bearing one. ADR-015 keeps its file, status and full text; Decision 3 gains an
inline pointer annotation (the ADR-010/ADR-011 precedent), effective only if this ADR is
Accepted.

## Alternatives
- **Keep clearing on downgrade (today's `ProxyController::update()` + T30).** Correct against
  PRD-06 and shipped deliberately in #6 (review-06 Ruling 2), but directly contradicts the
  Owner's Q-07-01(b) ruling and PRD-07 AC14. Not ours to reopen. Rejected.
- **Gate at each call site** — `DeliverToDestination` and `DeliveryResource` check
  `mode === Enhanced` before calling `RetryPolicy`. Reproduces the gate once per consumer, so
  correctness degrades with every new consumer and #8/#9/#12 inherit a pattern that scales
  badly; it also breaks ADR-015's single-resolver invariant by moving policy logic outside it.
  Rejected — the resolver *is* the choke point.
- **A second resolver (`SimpleRetryPolicy` / null-object) selected by mode at the container.**
  Two implementations to keep in step for a two-line branch, and the selection point becomes a
  new place mode is read. Rejected.
- **Snapshot the effective mode (or the resolved policy) onto the event, dispatch, or delivery
  row at ingest.** Stable mid-flight and it would make "which mode governed this event"
  queryable — but it contradicts PRD-07 AC9 verbatim ("not from a mode captured at ingest"),
  duplicates ADR-015's already-rejected policy snapshot, and costs a data-model change and an
  Owner gate for a fact no acceptance criterion requires. Rejected.
- **Infer dormancy — treat a non-NULL column as "this proxy is enhanced".** ADR-002's first
  rejected alternative, and now provably wrong: PRD-07 AC14 makes a non-NULL column on a Simple
  proxy the *normal* state. Rejected.
- **Per-capability sub-toggles (storage on, retry config off).** Forbidden by PRD-07 AC23.
  Rejected.
- **Move the whole retry policy behind a pipeline step so the structural gate covers it.**
  Retry is not a step: it runs in `DeliverToDestination` at settle time and in `RetryDelivery`
  hours later, both outside any pipeline. Forcing it into the spine would mean a step that
  schedules its own future — precisely the branching ADR-001 excludes. Rejected.
- **Let the UI carry the invariant (never show, never submit, done).** Client-side enforcement of
  a server-side guarantee; a policy resolution on the retry path never passes through the UI at
  all. Rejected.

## Reasoning
- **The guarantee is unchanged; only its mechanism moves.** PRD-06 AC2's "a Simple proxy is
  governed by exactly the system default" was true by persistence and is now true by resolution.
  PRD-07 AC21 says the same thing from the other side: a Simple proxy behaves exactly as if no
  policy had ever been saved. One `if` in one class buys that, versus a clearing rule that
  destroys user data on a reversible setting.
- **One choke point makes the guarantee auditable.** `mode` is read behaviourally in exactly one
  place in `app/` today (`PipelineFactory:28`/`:42`, verified 2026-08-25 — every other
  occurrence is CRUD, validation, cast, or serialization). After this ADR there are two, both
  named here. "Nothing may resolve retry behaviour from persisted values without first
  establishing that the proxy is Enhanced" (PRD-07 AC14(a)) becomes a property one class owns and
  one test pins, rather than a discipline every future consumer must remember.
- **It generalises to exactly the shape #8/#9/#12 need.** Mapping and normalisation add steps
  (structural gate, free); their per-proxy configuration adds tables plus a resolver that repeats
  Decision 2. AC18's "an addition to the governed set, not a change to the model" is then true by
  construction, not by intention.
- **Live-read is what makes switching safe rather than merely permitted.** Because nothing
  snapshots mode, a switch has no in-flight state to reconcile: there is no stale copy to
  invalidate, no migration of queued work, and no window in which two components disagree. AC10's
  "never loses, errors, or duplicates" is inherited from machinery that never consults mode at
  all (CAS transitions, the attempts unique key, the FIFO claim, the GC holds), and AC11's mixed
  treatment falls out as the honest description of what live-read does.
- **Retaining `prohibited_if` costs nothing and closes the one write path that could corrupt
  dormancy.** A Simple-mode save that carried values would otherwise be able to edit a
  configuration the user cannot see — the inverse of AC14(b).

## Impact
- **Data-model:** **none.** No column, table, index, enum value, or migration. `mode` already
  exists, is non-nullable with a `simple` default, and is already settable through the existing
  create/edit endpoints (ADR-002; PRD-07 AC4). This is the assessment Q-07-02(5) asked for.
- **Supersedes a position of an Accepted ADR (Owner-gated ✋):** ADR-015 Decision 3's persistence
  invariant, as enumerated above. This is the **only** Owner gate #7 carries from this ADR.
- **Behavioural change, Owner-visible — but already ruled:** a Simple proxy may carry stored
  retry values, and a downgrade no longer destroys them. The Owner decided this in Q-07-01(b);
  what is being ratified here is the mechanism, not the outcome.
- **Code (indicative, for plan-07 to specify):** `RetryPolicy` (+ the mode gate in two methods),
  `ProxyController::update()` (omit the retry columns on a Simple save instead of clearing),
  `ProxyResource` (stop emitting raw dormant values), `Show.vue`'s Retry policy card comment
  (its "a simple-mode proxy's columns are always NULL" rationale is retired by this ADR), and the
  inversion of `RetryPolicyFormTest::test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null`
  (review-06 Minor 8(c) — a rename, not just a re-assert). `PipelineFactory` is **unchanged**.
- **Security:** neutral. Keeping two clamped scalars on a row that no longer consults them
  widens no surface: the values are inert by Decision 2, bounded by validation *and* by the
  resolver's clamp, and never presented (Decision 4). No secret, payload, or header is involved.
- **Constrained:**
  - `mode` may be read behaviourally in exactly the two kinds of place named in Decision 1;
    a third requires superseding this ADR.
  - No consumer may read `proxies.retry_attempt_limit` / `retry_backoff_strategy` or
    `config('retry.*')` outside `RetryPolicy` (ADR-015, reaffirmed) — which now also means no
    consumer can bypass the mode gate.
  - Mode may never be snapshotted, serialized into a job, or inferred from an artefact.
  - Every future enhanced-only configuration must ship its resolver **with** the gate in the
    same change; configuration whose gate lives only in validation is the defect this ADR exists
    to prevent.
