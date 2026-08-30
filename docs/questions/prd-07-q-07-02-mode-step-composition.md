# Question Q-07-02: Mode-gated step composition, in-flight mode resolution, and extensibility (technical)

- **Status:** **RESOLVED** *(Principal Engineer, 2026-08-25 — see § Answer. One ADR follows:
  **ADR-018**, Accepted by the Project Owner 2026-08-25. One clause of PRD-07 AC14 proved
  self-conflicting on one path and was returned to the Product Manager as
  `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md`.)*
- **Raised by:** Product Manager
- **Owner (must answer):** Principal Engineer *(technical feasibility/design — the
  Product Manager does not resolve these; if any PRD-07 requirement proves
  infeasible as stated, it returns to the Product Manager as a requirement
  question, not a silent design change)*
- **Raised:** 2026-08-21
- **Gates:** **Non-blocking** for PRD-07 requirement approval; gates **technical
  design** for feature #7.
- **Source:** `docs/product/prd-07-enhanced-mode-toggle.md` (AC6, AC9, AC10, AC11,
  AC15); roadmap #7 build-ahead ("Principal Engineer owns step composition");
  ADR-001 (pipeline spine); ADR-002 (`mode` as the pure selector the
  `PipelineFactory` reads); ADR-011/016 (dispatch-by-reference; FIFO claim state);
  ADR-012/014 (retention holds; cleaned-signal guard); ADR-015 (retry policy
  resolution).

## Context
Step composition is explicitly the Principal Engineer's per the roadmap #7
build-ahead. PRD-07 therefore asserts only **observable** requirements and leaves
mechanism open. This question asks the Principal Engineer to confirm, at #7
technical design, that those requirements land on the seams ADR-001/ADR-002
already reserved — and to surface any Owner-gated change early.

## Question
Confirm at technical design:

1. **Governed-step wiring (PRD-07 AC6, AC15).** The complete set of enhanced-only
   behaviour as of #7 — dispatched-output capture (PRD-05 AC12) and per-proxy
   retry-policy configurability (PRD-06 AC2) — is gated on the single ADR-002
   `mode` selector, with no second gate, no inferred mode, and no widening of the
   attribute. Confirm that adding a later enhanced-only step (#8 mapping, #9
   normalisation, #12 change detection) is an addition to the composed step list
   only, requiring no change to the mode attribute, the toggle, or the gate — the
   extensibility PRD-07 AC15 asserts and the roadmap build-ahead requires.

2. **Which mode value governs an in-flight event (AC9).** PRD-07 requires the mode
   **in force when the event is processed** to govern that event's pipeline —
   derived from ADR-002 (the `PipelineFactory` composes from `mode` at pipeline
   build time), PRD-05 AC12 ("when a proxy **is** in enhanced mode") and PRD-06
   AC11 (replay runs through the proxy's **current** configuration). Confirm this
   holds under queued dispatch, where composition happens after ingest: that an
   event captured under one mode and dispatched under another composes cleanly,
   and that no code path snapshots a stale mode or re-reads it inconsistently
   between steps of the same event.

3. **Switch safety across #4/#6 state (AC10, AC11).** A mode change while work is
   outstanding must not lose, error, duplicate, or strand an event: confirm it
   composes with ADR-011/016 FIFO claim state and dispatch-by-reference, with
   Async in-flight jobs, with scheduled retries and in-flight replays, and with
   the ADR-012 retention holds and the ADR-014 cleaned-signal guard. Specifically:
   a downgrade mid-retry must not orphan a delivery mid-policy, and an upgrade
   must not make a partially-processed event inconsistent in a way that surfaces
   as an error rather than as the acceptable per-event variance AC11 permits.

4. **Retry-policy resolution across a switch (AC6, AC7).** Confirm that a
   simple-mode proxy resolves the fixed system default (PRD-06 AC2) regardless of
   any persisted policy value, so the answer to **Q-07-01(b)** — whether a saved
   policy is discarded or preserved dormant — changes only persistence, never the
   resolved behaviour of a simple-mode proxy.

5. **Owner gates and data-model impact.** Whether any of the above is a data-model
   change or otherwise carries a `CLAUDE.md` Owner approval gate at plan time. The
   Product Manager's expectation is that #7 needs **none** — `mode` already exists
   (ADR-002) and is already settable through the existing create/edit endpoints —
   but that assessment is the Principal Engineer's. If Q-07-01 resolves to erasing
   stored dispatched output on downgrade (Option B), assess and surface the
   additional gate that introduces against PRD-05's lifecycle.

Mechanism, composition strategy, and where the gate is evaluated are the Principal
Engineer's, not resolved here.

## Impact if unresolved
None for requirement approval — PRD-07 asserts observable outcomes only. Technical
design for #7 should not begin without these confirmations, since every one of
them concerns a seam an earlier ADR reserved rather than built.

## Answer
- **Answered By:** Principal Engineer
- **Answered:** 2026-08-25

**Verified against `main` as it stands after PR #8 (`e1c2894`, feature #6 merged 2026-08-25),
not against the code as it was when this question was raised.** Where an answer names a
file:line, it was read on that commit.

**Headline.** All five confirmations hold, with **one mechanism change** that is ADR-worthy and
Owner-gated, and **one PRD clause** that could not be satisfied as written and has gone back to
the Product Manager:

- The AC6 set is gated on the single ADR-002 selector, and AC18's extensibility holds — but
  **AC6(b) is not gated at all today**: `RetryPolicy` reads the retry columns unconditionally
  (`app/Services/RetryPolicy.php:41,52`), which was correct under #6's persistence invariant and
  becomes a defect the moment PRD-07 AC14 preserves a dormant value. Closing it is #7's job, as
  review-06 Ruling 2 stated. The mechanism is
  **`docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md` (Proposed —
  ✋ Project Owner approval required)**, which partially supersedes one position of Accepted
  ADR-015 (Decision 3's persistence invariant). ADR-015 has been annotated inline, pending.
- **No data-model change.** #7 adds no column, table, index, enum value, migration or
  dependency. The Product Manager's expectation in (5) is confirmed.
- **AC14's restoration promise vs. AC14(b)'s no-dormant-values rule cannot both hold literally on
  the upgrade save.** Raised as **Q-07-03** to the Product Manager. It does not block this
  answer, ADR-018, or most of technical design; it blocks one section of `plan-07` and the Mode
  control's Edit-form behaviour in `design-07`.

---

### (1) Governed-step wiring — **confirmed, with one correction to the premise**

**One selector, two evaluation points, no second gate, no inferred mode, no widening.**

Evidence for "single selector" as the code stands: `mode` is read **behaviourally in exactly one
place** in `app/` — `PipelineFactory::stepsFor()` (`app/Pipeline/PipelineFactory.php:28` and
`:42`, the same `$proxy` instance). Every other occurrence is CRUD, validation, a cast, or
serialization (`ProxyController:159`, `Store/UpdateProxyRequest:36`, `Proxy:46,143`,
`ProxyResource:36`). There is no second attribute and no inference anywhere.

The premise that needs correcting is that **AC6's two members are gated the same way. They are
not, and cannot be**:

| AC6 member | What it is | How it is gated |
|---|---|---|
| **(a)** dispatched-output store (PRD-05 AC12) | a **pipeline step** (`CaptureDispatchedStep`) | **Composition-time, structural** — a step that is not composed cannot run. Already true and unchanged by #7. |
| **(b)** per-proxy retry-policy configurability (PRD-06 AC2) | **configuration** consulted outside the pipeline, at settle time (`DeliverToDestination::settleDelivery`) and hours later (`RetryDelivery`) | **Resolution-time** — `RetryPolicy` must establish Enhanced before reading a column. **This is what #7 adds** (ADR-018 Decision 2). |

Retry cannot be gated structurally: it is not a step and could not become one without a step that
schedules its own future, which is the branching ADR-001 excludes. Two evaluation points of the
**same** enum is not the "second gate" AC6/AC18 forbid — a second gate would be a different
attribute, a per-capability sub-toggle (AC23), or an inference. **Inference stays forbidden and
is now enumerated:** nothing may read mode off a `dispatched_payloads` row, a non-NULL retry
column, or any other by-product of an earlier Enhanced period. Those artefacts legitimately
outlive the mode that made them (AC13/AC14) — treating them as mode is exactly the ambiguity
ADR-002 rejected.

**Extensibility (AC18) — confirmed, and it is genuinely an addition only.** A later enhanced-only
step is one uncommented line in the existing `ProxyMode::Enhanced` branch at its already-reserved
position (`// #9 NormalizeStep` `:30`, `// #8 MapStep` `:34`, both **before**
`CaptureDispatchedStep` per ADR-013's placement constraint; `// #12 ChangeDetectStep` `:43` in
the tail stage). No change to the attribute, the toggle, the gate, the `PipelineContext`, or
`DeliverStep`. Where a later capability also brings **per-proxy configuration** (e.g. #8's maps),
it adds its own columns/tables plus **its own single resolver repeating the resolution-time
gate** — the recipe is ADR-018 Decision 6, and shipping such configuration with its gate only in
validation is the defect ADR-018 exists to prevent.

### (2) Which mode governs an in-flight event — **confirmed; AC9 holds under queued dispatch**

The current mode governs, because **nothing anywhere snapshots it**.

- **Composition happens on the worker, from a live row.** `ProcessIngestedWebhook::handle()`
  loads the proxy at processing time (`app/Actions/ProcessIngestedWebhook.php:50`,
  trashed-inclusive) and composes from that instance (`:96`). The queued job carries
  `(ingestId, dispatchUuid)` only (ADR-011 dispatch-by-reference) — no mode, no proxy, no
  payload. An event captured under one mode and dispatched under another therefore composes from
  the mode in force **when it is processed**, with no reconciliation step. AC9 verbatim.
- **No intra-event inconsistency is possible.** Every step reads the single `$ctx->proxy`
  instance loaded at entry, so two steps of the same run cannot observe different modes. Rule
  recorded as binding (ADR-018 Decision 5): a step reads mode only via `$ctx->proxy`, never by
  re-querying.
- **Across runs it is deliberately re-read, which is the point.** Attempt-1 composition, each
  retry's policy resolution (`DeliverToDestination:197-198`, `$delivery->proxy` — a fresh read
  per settle), the sweeper's re-dispatch, and a replay (`ProcessIngestedWebhook` again, ADR-017)
  each resolve the mode current at that moment. This is the same live-read posture plan-06 already
  ruled for mid-flight retry-policy changes, and PRD-06 AC11 already requires it for replay.
  AC11's mixed treatment is the honest description of the consequence, not a defect to engineer
  around.
- **A queue redelivery that straddles a switch is safe and produces no duplicate.**
  `CaptureDispatchedStep`'s `updateOrCreate` is keyed on `webhook_event_id` (UNIQUE), so a run
  under Enhanced followed by a redelivered run under Simple leaves the earlier row in place,
  untouched — one row per event, no error. The row's existence is therefore **not** an invariant
  of the proxy's current mode, which is precisely why (1) forbids inferring mode from it.

### (3) Switch safety across #4/#6 state — **confirmed; no path can lose, error, duplicate or strand**

The general reason is structural: **every mechanism that provides AC10's guarantees is
mode-independent**, and a switch mutates one enum column on `proxies` and nothing else.

- **Exactly-once settlement** — `UNIQUE(delivery_id, attempt_number)` create-or-resume
  (`DeliverToDestination:110-116`) and the status CAS (`:270-276`). Neither reads mode.
- **Delivery rows** are created at pipeline entry, before composition and independently of mode
  (`ProcessIngestedWebhook:62-75`), so the destination set of an in-flight dispatch cannot change
  under a switch.
- **FIFO claim state (ADR-011/016)** — `fifo_dispatches`, the advancer, the lease/reaper, the
  `awaiting_retry` hold and the stuck-hold release read `processing_mode` and delivery status
  only; `mode` appears nowhere in that machinery. The two axes are orthogonal in code, not just
  in the PRD.
- **ADR-012 holds and the ADR-014 cleaned guard** — H0–H5 are computed from event age,
  `fifo_dispatches` status and `deliveries` status; the guards key on `payload_cleaned_at`. All
  mode-independent, so a switch changes no hold and can neither immortalize a payload nor expose
  a cleaned one. **AC13 falls out of this:** the expiry pass erases `dispatched_payloads.body`
  in the same transaction as its parent regardless of the proxy's current mode, so a downgrade
  introduces no second eraser and outputs made under Enhanced expire normally.

The two named worries, answered specifically:

- **Downgrade mid-retry does not orphan a delivery.** At the next settle the resolved limit drops
  to the system default (say 8 → 5). The comparison is `$unit->attemptNumber >= $limit`
  (`DeliverToDestination:200`), not equality, so a delivery already past the new limit
  **terminalizes immediately** — `failed`, `DeliveryExhausted` once (CAS-guarded), and on a FIFO
  proxy `settleFifoLineIfComplete()` releases the line. It cannot loop, stall, or sit
  `retrying` forever. A retry **already scheduled** when the switch happens still runs
  (`RetryDelivery` does not re-check the limit before sending) and then terminalizes at settle:
  at most one extra attempt to a destination that is already receiving at-least-once delivery.
  That is the correct trade — cancelling a scheduled attempt mid-flight is the only way this path
  could *drop* work, which AC10 forbids.
- **Upgrade mid-flight cannot make an event inconsistent or extend a hold without bound.** An
  upgrade can only *add* a step to subsequent runs and *raise* the resolved attempt limit — and
  the limit is clamped to `max_attempt_limit` inside `RetryPolicy` regardless of mode, so
  ADR-015's structural AC18 bound (≈32.6 h worst case, orders of magnitude inside the 30-day
  window) still holds after any number of switches. An event captured under Simple and dispatched
  under Enhanced simply gains a `dispatched_payloads` row; nothing requires a row to exist for an
  event that was Enhanced at ingest, and `StoredPayloadLookup::dispatchedBytesFor()` already
  handles the no-row case as "the raw capture is the dispatched output"
  (`app/Services/StoredPayloadLookup.php:57-64`).
- **Replay across a switch** re-processes raw through the pipeline composed from the **current**
  mode (ADR-017 Decision 1 + AC11). A replay on a now-Simple proxy neither writes nor deletes the
  event's existing dispatched-output row (the step is not composed) — AC13 preserved; a replay
  after a re-upgrade refreshes it via the same `updateOrCreate`, which is ADR-017's already-recorded
  semantic and is content-free pre-#9.
- **One forward note, not a #7 problem:** once #9/#8 make divergence possible, a Simple-mode
  replay of an event that has a stored diverged output will dispatch bytes it does not record,
  leaving the older row in place with a stale `dispatched_at`. Correct under AC6 (Simple records
  nothing) and structurally impossible today (`$ctx->payload !== $ctx->rawBody` can never be true
  before a transform seam is filled), but #9 should state it deliberately rather than discover it.

### (4) Retry-policy resolution across a switch — **confirmed as a requirement; it is the one thing #7 must build**

**Yes: a Simple proxy must resolve the fixed system default regardless of any persisted value, so
Q-07-01(b) changes persistence only and never resolved behaviour.** As of PR #8 that is *not*
what the code does — it is what #7 makes true:

- Today `attemptLimitFor()` reads `$proxy->retry_attempt_limit` unconditionally
  (`RetryPolicy.php:41`) and `strategyFor()` reads `$proxy->retry_backoff_strategy`
  unconditionally (`:52`). Both are safe **only** because ADR-015 Decision 3 guaranteed those
  columns are NULL for a Simple proxy — the invariant Q-07-01(b) retired.
- #7 puts the gate in `RetryPolicy` and nowhere else: Enhanced ⇒ column `??` default; Simple ⇒
  the default, whatever the columns hold. `delayBefore()` inherits it via `strategyFor()`; the
  clamp and the `positiveConfigInt()` config-sanity guards are unchanged and apply after the
  gate. Because ADR-015 already makes `RetryPolicy` the **single** reader of both columns and of
  `config('retry.*')`, one gate covers every consumer — `DeliverToDestination::settleDelivery()`
  and `DeliveryResource.attempt_limit` (`app/Http/Resources/DeliveryResource.php:40`, which
  already resolves rather than reading raw) inherit it with no branch of their own. AC14(a)'s
  "nothing may resolve retry behaviour from persisted values without first establishing that the
  proxy is Enhanced" becomes a property one class owns and one test pins.
- **Persistence:** a `mode = simple` save **omits** the two columns from the update rather than
  writing NULL — preservation by not writing. `prohibited_if:mode,simple` is **kept** in both
  Form Requests (this departs from review-06 Minor 8(a)'s sketch, deliberately: relaxing it would
  let a Simple-mode save silently overwrite the very values AC14 preserves; keeping it means a
  Simple save can never *change* a dormant policy).
- **Presentation:** `ProxyResource:49-50` emits the raw columns unconditionally today and must
  stop — for a Simple proxy every read surface presents the policy **in force** (the system
  default), either by resolving through `RetryPolicy` or by emitting `null` and letting the
  existing display helper render "(default)". `Show.vue:101-105`'s stated rationale ("a
  simple-mode proxy's columns are always NULL") is retired with the invariant.
- **Returned upstream — Q-07-03.** AC14's "applies again with its previous values, without the
  member re-entering anything" and AC14(b)'s "no … **form** … or any response shaped for one"
  cannot both hold literally on the one save that performs an upgrade: if the Edit form does not
  receive the preserved values it submits `null`, and `null` means "use the system default", so
  the upgrade save destroys what the downgrade preserved — unless the server ignores what the
  form submitted, in which case the form claimed something untrue (AC12). Two workable options
  are written up with their costs in
  `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md`; the Principal Engineer
  recommends Option A but will not choose which PRD clause yields. **Nothing above depends on the
  outcome** — both options keep the resolution gate, the retained `prohibited_if`, and the
  don't-write-on-simple rule.

### (5) Owner gates and data-model impact — **no data-model change; exactly one Owner gate**

- **Data model: none.** No column, table, index, enum value, migration, backfill or dependency.
  `mode` is already non-nullable, defaulted `simple`, cast, validated and settable through the
  existing create/edit endpoints (ADR-002; AC4's "no migration question arises" is confirmed from
  the schema side). The Product Manager's expectation is correct.
- **Owner gate (✋), one:** **ADR-018** — it partially supersedes one named position of Accepted
  ADR-015 (Decision 3's persistence invariant). Per `CLAUDE.md` a major decision of this kind is
  the Project Owner's, never PE-self-certified; `plan-07` will restate the flag and will be
  certified "except" that item until it is approved. ADR-015 carries the pending-supersession
  annotation in the meantime.
- **Behavioural change, Owner-visible but already ruled:** a Simple proxy may carry stored retry
  values and a downgrade no longer destroys them (Q-07-01(b)). What ADR-018 asks the Owner to
  ratify is the *mechanism*, not the outcome.
- **The conditional gate in the question does not arise.** Q-07-01(a) resolved to **retain**
  (Option A), so #7 adds no erasure trigger and PRD-05's single-eraser lifecycle is untouched —
  no additional gate against PRD-05.
- **Security: neutral.** Two clamped, inert scalars remain on a row that no longer consults them
  while Simple; bounded by validation *and* by the resolver's clamp, never presented. No secret,
  payload or header is involved, and no new surface, route, permission or egress path is added by
  #7.

### Carried-in obligations from review-06 — dispositions

| Item | Disposition |
|---|---|
| **Minor 8(a)** — stop clearing in `ProxyController::update()`; relax `prohibited_if` in both requests | **Accepted in part.** Stop clearing: yes, by *omitting* the columns on a Simple save. Relax `prohibited_if`: **no** — reasons above; recorded in ADR-018 Decision 3 so the divergence from the reviewer's sketch is deliberate and traceable. |
| **Minor 8(b)** — simultaneously mode-gate `attemptLimitFor()`/`strategyFor()` | **Accepted, and it is the core of ADR-018 Decision 2.** The reviewer's "(a) without (b) is a defect" is binding on `plan-07`: the two land in one task, never in sequence. |
| **Minor 8(c)** — invert (and rename) `RetryPolicyFormTest::test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null`; add the Show-page suppression | **Accepted**; both go on `plan-07`'s test strategy, with the Show-page suppression traced to AC14(b)/AC12 and a resolver test pinning "Simple with a stored policy behaves identically to Simple that never had one" (AC14(a)/AC21). |
| **Minor 5** (routed to the Principal Engineer) — `DeliveryResource` should gain a real `created_at` | **Accepted as the Reviewer ruled**, and scheduled onto `plan-07` as an additive resource field. `deliveries.created_at` already exists, so this is serialization only — no data-model change, no Owner gate. It is not mode-related; it rides along because #7 is the next feature to touch this surface. |
| `SweepDueRetries` reads `config('retry.sweep_grace_seconds')` directly (`app/Actions/SweepDueRetries.php:33`), outside `RetryPolicy` | **Accepted as a rider on `plan-07`**, non-blocking, no ADR: it violates ADR-015/plan-06's "`RetryPolicy` is the only reader of `config('retry.*')`" invariant — the same invariant ADR-018 now leans on for the mode gate — and it is the one `retry.*` key with no `positiveConfigInt()` guard (a blank env would make the cutoff `now()`, sweeping every retrying delivery every minute). Fix: a guarded `RetryPolicy::sweepGraceSeconds()`. |
| **Latent defect found while verifying (2026-08-25), not #7's and not mode-related** | `DeliverToDestination::settleDelivery()` uses `$delivery->proxy` (`:197`) and `DeliveryResource:40` uses `$this->proxy`; `Delivery::proxy()` is a plain `belongsTo` and `Proxy` uses `SoftDeletes`, so a **soft-deleted proxy yields `null`** and the failure branch raises a `TypeError` in the worker. Reachable: soft-delete a proxy with an outstanding delivery, then let an attempt fail. PHPStan cannot see it (`@property-read Proxy $proxy`). The fix is the `ProcessIngestedWebhook:50` precedent — load trashed-inclusive; ADR-018 Decision 5 states the rule. **Routed as a bug to the Senior Developer** (flat path per `CLAUDE.md`), not folded into #7's scope. |

### Consequences for technical design

`plan-07` may now be written. It carries **one** Owner-approval flag (ADR-018) and must leave the
Edit-form prop shape and the upgrade write rule in draft until Q-07-03 is answered. The
governed-step wiring, the extensibility recipe, the AC9/AC10/AC11 switch-safety argument, the
resolution gate, the presentation rule and the "no data-model change" assessment are settled
here and need no further upstream input.
