# Technical Plan: Foundational architecture — ingest→delivery seams

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Scope:** Cross-cutting architectural foundation for Roadmap #1's walking
  skeleton, locking the four seams the roadmap flagged as architecture calls plus
  R5. Traces to `docs/product/vision.md` and the **Approved**
  `docs/product/roadmap.md` (build-ahead notes on items #1, #3, #4, #5, #6, #7,
  #8, #11, #13) and the R5 open question.
- **Approved by / date:** Project Owner — 2026-07-30

> **What this document is and is not.** This is the *foundational* technical plan:
> it fixes the architectural seams so backlog items #4–#14 slot into item #1's
> foundation without reworking it (the Project Owner's overriding no-refactor
> goal). It is **not** the per-PRD implementation plan for item #1 — PRD-01 is
> still Draft/pending approval, so the item #1 build plan (tied to PRD-01
> acceptance criteria and a Designer-approved UI spec) is deferred until PRD-01 is
> approved. This plan invents no requirements; every seam cites the approved
> roadmap/vision, and open points are surfaced, not decided.

## Overview
The system is shaped as a **pipeline-oriented ingest→delivery spine**: a public,
token-authenticated ingest endpoint resolves a team-scoped proxy, produces an
upstream response independent of delivery outcome, and dispatches an ordered
pipeline of code-defined steps over a single in-memory envelope. At item #1 the
pipeline is a single fire-and-forget fan-out `DeliverStep`, and each per-destination
attempt writes a payload-free `DeliveryAttempt` record and emits a domain event.
Every later capability (queued dispatch, retry/replay, storage, mapping,
multi-format ingest, sensitive-data handling, change detection, analytics,
notifications, test payloads) attaches as an added step, a queued/`::dispatch`ed
Action behind the dispatch-timing seam, an event listener, or an aggregate over
attempt records — never as a rewrite of the spine. Six ADRs (ADR-001…006) lock the
load-bearing seams; ADR-007 (**Accepted**) records the `lorisleiva/laravel-actions`
dependency that realizes the step and dispatch shape idiomatically.

## Architecture

### Request lifecycle (item #1)
1. **Ingest endpoint** — public route `POST|PUT /ingest/{ingest_token}` (external
   senders; no session auth; CSRF-exempt; TLS-only). Resolves the proxy by hashed
   token (ADR-006). Unknown token ⇒ `404`.
2. **Envelope capture** — builds an in-memory `PipelineContext` (received headers,
   raw body, method, resolved proxy, `ingest_id` UUID). **Nothing is persisted
   here at #1** — payload storage is #5/enhanced-only (R2).
3. **Upstream response** — `ResponseResolver` returns the response from proxy
   config, independent of delivery (ADR-004). #1 default: `202 Accepted`.
4. **Pipeline dispatch** — the `ProcessIngestedWebhook` Action runs the pipeline
   (ADR-005/ADR-007), asking `PipelineFactory` for the ordered `PipelineStep[]`
   (ADR-001, ADR-002) and driving them through the first-party
   `Illuminate\Pipeline\Pipeline`. At #1 it is invoked with `::run` (sync);
   `[DeliverStep]`, fire-and-forget.
5. **DeliverStep** — iterates the proxy's destinations; per destination the
   `DeliverToDestination` Action performs HTTP POST/PUT and writes a
   `DeliveryAttempt` + emits `DeliveryAttempted`/`DeliverySucceeded`/`DeliveryFailed`
   (ADR-003). One destination failing does not block others (PRD AC9).

### The four flagged seams (+ R5) and where each later item attaches
| Seam | Locked by | Later items attach as |
|------|-----------|-----------------------|
| 1. Pipeline spine | ADR-001 | #5 CaptureRaw/CaptureDispatched steps · #8 MapStep · #9 NormalizeStep · #10 VerifyStep · #12 ChangeDetectStep · #14 reuses the same pipeline |
| 2. Attempt records + events | ADR-003 | #11 aggregates records · #6 listens to failure events · #13 keys off event severity |
| 3. Simple/enhanced mode | ADR-002 | #5 gates storage on `mode` · #7 flips `mode` (state change) · #8 gates MapStep |
| 4. Response decoupling | ADR-004 | #3 reads `response_status`/`response_body` · #4 makes dispatch async, response untouched |
| Dispatch-timing seam (V3) | ADR-005 (+ADR-007) | #4 flips the two Actions from `::run` to `::dispatch`+`onQueue` (FIFO via job middleware) · #6 job retry/backoff on the Action · V3 = thin first-party publisher for a non-Laravel transport at the same two Actions |
| R5 ingest URL | ADR-006 | #10 VerifyStep prepends verification without changing URL generation |

### Pipeline step order (target, for forward-compatibility only — item #1 builds only DeliverStep)
`VerifyStep(#10)` → `NormalizeStep(#9)` → `CaptureRawStep(#5)` → `MapStep(#8)` →
`CaptureDispatchedStep(#5)` → **`DeliverStep(#1)`** → `ChangeDetectStep(#12)`.
Steps are registered by the factory only when the proxy's mode/config enables
them; the order above is the insertion contract so later items add at a fixed
position.

## Data Model
Only the **item #1** structures below are created now. Columns/tables annotated
"(later)" are named so the shape is forward-compatible; they are **not** built at
#1 and are owned by the cited item's own PRD/plan. All entities are **team-scoped
from the first commit (R1)** via a `team_id` FK and a team global scope on the
models; the starter kit provides `users`/`teams`.

**`proxies`**
- `id`, `team_id` (FK, indexed)
- `name`
- `mode` enum(`simple`,`enhanced`) not null default `simple` — ADR-002
- `ingest_token_hash` **UNIQUE** (SHA-256 lookup) — `BINARY(32)` preferred, or
  `char(64)` hex with a **binary/`ascii_bin` collation** (never a case-insensitive
  `utf8mb4_*_ci` collation) — ADR-006 (see Performance & scaling)
- `ingest_token` (`encrypted` cast, for display) — ADR-006
- `response_status`, `response_body` — (later, #3)
- `retention_days` — (later, #5)
- a **set of maps** per proxy, each with its selection condition, plus a
  global/default map, and `expected_structure` — (later, #8/#12; a proxy owns
  multiple maps, one selected per event — see ADR-001 note dated 2026-07-30. Shape
  hint only; selection precedence/syntax are M1/M2, settled at #8's PRD)
- `retry_strategy` — (later, #6)
- timestamps

**`destinations`** (many per proxy from the first commit — no single-destination assumption, PRD AC2)
- `id`, `proxy_id` (FK, indexed), `team_id` (FK, indexed for direct team scoping)
- `url`
- `http_method` enum(`POST`,`PUT`) not null — V1
- `is_active` — (later)
- timestamps

**`delivery_attempts`** (payload-free; source of truth for #11 — ADR-003)
- `id`, `team_id` (FK), `proxy_id` (FK), `destination_id` (FK)
- `ingest_id` (uuid, indexed) — correlates one received webhook's fan-out set
- `status` enum(`dispatched`,`succeeded`,`failed`)
- `http_status` (nullable), `error_summary` (nullable, no payload)
- `attempt_number` int default 1
- `started_at`, `duration_ms`
- `replay_of_id` — (later, #6)
- indexes: `(team_id, created_at)`, `(proxy_id, status)`, `(ingest_id)`
- timestamps

**Payload storage entity — (later, #5) — shape reserved, not built at #1.** Per
R2 it must keep **raw captured input** separate from and immutable relative to the
**dispatched output**: e.g. a `webhook_payloads` table (or two rows/columns)
holding `raw_body` (immutable, encrypt-at-rest ready for #10) and `dispatched_body`
separately, `team_id`-scoped, `ingest_id`-correlated, with a retention/`expires_at`
column for the #5 GC. Named here only so #5 does not re-model; **no payload is
stored at #1**.

## API
Item #1 exposes two surfaces (final contracts belong to the item #1 PRD plan /
Designer spec; shapes below are the seam-level contract only):

- **Ingest (public, external senders):** `POST|PUT /ingest/{ingest_token}` — TLS
  only, CSRF-exempt, no session auth, token-authenticated (ADR-006). Success ⇒
  `202 Accepted` (ADR-004 default; #3 makes it user-defined). Invalid token ⇒
  `404`. Body-size cap enforced.
- **Management (authenticated, session + team):** team-scoped CRUD for proxies and
  their destinations, and read views listing a team's proxies with ingest URL and
  destinations (PRD AC1–AC6). Governed by team membership at #1; #2 layers
  view/add/modify roles — so permission checks should be expressed against proxy
  *actions* (not hard-wired to #1's set) to leave the #2 seam open.

## Services
- **`IngestController`** — resolves proxy, builds envelope, calls `ResponseResolver`, hands off to `ProcessIngestedWebhook`. Never reads delivery outcome.
- **`ResponseResolver`** — proxy-config → upstream response (ADR-004).
- **`PipelineFactory`** — proxy `mode`/config → ordered `PipelineStep[]` fed to the runner (ADR-001/002).
- **Runner** — Laravel's first-party `Illuminate\Pipeline\Pipeline` (`send`/`through`/`thenReturn`); no bespoke runner class.
- **`ProcessIngestedWebhook`** (Action, ADR-007) — runs the whole pipeline over one `PipelineContext`; the pipeline-level dispatch-timing seam, run sync at #1 (`::run`) / queued at #4 (`::dispatch`) (ADR-005).
- **`DeliverStep`** (Action) — fan-out over destinations; hands each to `DeliverToDestination` (ADR-001/003).
- **`DeliverToDestination`** (Action, ADR-007) — per-destination HTTP POST/PUT; writes `DeliveryAttempt`; emits events (ADR-003); the delivery-level dispatch-timing seam, sync at #1 / queued per destination at #4 (ADR-005).
- **`IngestTokenService`** — generate/hash/encrypt/rotate tokens (ADR-006).

The bespoke `Dispatcher` interface is **not** built: for every Laravel-queue
transport the two Actions' `AsJob` run-sync-or-queue behaviour is the seam
(ADR-005 correction / ADR-007). A thin first-party publisher is reserved only for a
V3 non-Laravel transport, introduced at those two Actions' dispatch call if V3 lands.

## Validation
- Proxy creation requires ≥1 destination (PRD AC2); zero destinations rejected.
- `http_method` constrained to `POST`|`PUT` (V1); any other value rejected.
- Destination `url` must be a valid absolute HTTP(S) URL.
- Ingest requests: reject non-TLS; enforce body-size cap; `404` on token miss.
- Management endpoints require authenticated team member; cross-team access denied (PRD AC5/AC6) via team scope.

## Risks
- **Attempt-record vs PRD-01 wording** — persisting `DeliveryAttempt` metadata at
  #1 is mandated by the approved roadmap build-ahead note but PRD-01 AC11 reads
  "without being stored … without analytics." Raised to PM
  (`docs/questions/prd-01-attempt-records-vs-storage.md`); plan follows the
  approved roadmap. **Outstanding.**
- **FIFO under Redis** — strict per-proxy ordering with multiple workers needs an
  ordering strategy; deferred to #4 design, flagged in ADR-005.
- **Missing stack/standards docs** — `docs/stack/stack.md` and `docs/standards/`
  do not exist though CLAUDE.md references them; stack facts here are taken only
  from the vision's Known Constraints and the task constraints. Recommend the Owner
  establish `docs/stack/stack.md` so future plans have an authoritative source.
- **Token at rest** — encrypted-column + hash-lookup adds a little complexity vs a
  plaintext unique token; plaintext is offered as an acceptable fallback in
  ADR-006 if the Owner prefers simplicity.

## Dependencies
- Laravel + Vue via Inertia; Laravel starter-kit auth + **teams** (registration/teams reused, not rebuilt).
- MySQL (MVP), Redis (MVP queue — behind the ADR-005 dispatch-timing seam), AWS via Forge.
- CSPRNG (`random_bytes`) and Laravel `encrypted` cast for ADR-006.
- **First-party** `Illuminate\Pipeline\Pipeline` as the pipeline runner (no new dependency).
- **New dependency (approved):** `lorisleiva/laravel-actions` for the pipeline steps and the two dispatchable Actions (ADR-007, **Accepted 2026-07-30**). The recorded fallback, had it been rejected, was plain invokable steps + native `ShouldQueue` jobs.

## Implementation Notes
- Team-scope every entity and query from the first commit (R1); no global (cross-team) reads.
- `DeliveryAttempt` must stay **payload-free** (ADR-003); payload capture is #5 only (R2).
- Raw captured input is **never mutated**; dispatched output stored separately — honour this in #5's entity even though #1 stores nothing (R2).
- Steps are code-defined and composed by config — **no workflow builder** (vision out-of-scope).
- The ingest response must never depend on delivery outcome (ADR-004).
- Keep execution timing on the dispatchable Actions (`ProcessIngestedWebhook`, `DeliverToDestination`), never in the steps; **do not** implement Kafka — the V3 non-Laravel transport is an Owner decision gated by V8, realized as a thin first-party publisher at those Actions if it lands (ADR-005/ADR-007).

## Handoff
- **Inputs:** `docs/product/vision.md`; Approved `docs/product/roadmap.md` (items #1, #3–#8, #11, #13 build-ahead notes; R1–R4, V1); PRD-01 (Draft, for context); R5 question doc.
- **Outputs:** this plan; ADR-001…006; **ADR-007 (Accepted — laravel-actions dependency)**; R5 resolution (ADR-006, annotated in the R5 question doc).
- **Dependencies:** Laravel starter-kit auth/teams boilerplate.
- **Approval status:** This foundational plan and ADR-001…007 are **approved by the
  Project Owner (2026-07-30)**. The items below are the *remaining* deferred /
  non-blocking questions — none of them gate this plan's approval.
- **Outstanding Questions (deferred — do not block this plan):**
  1. **PM** — reconcile PRD-01 AC11 wording with the roadmap's attempt-record mandate (`docs/questions/prd-01-attempt-records-vs-storage.md`).
  2. **Project Owner (non-blocking preferences)** — confirm ADR-006 residual preferences (TLS-enforcement layer; plaintext-token fallback y/n); note V3/V8 remain open by design. These do not affect this plan's approval.
  3. **Project Owner** — approve PRD-01 (still Draft) before the item #1 per-PRD implementation plan can be written; the Designer's UI spec for #1 is also required before that plan.
  4. **Resolved (2026-07-30)** — ADR-007 (adopt `lorisleiva/laravel-actions` as a new dependency) is **approved**; the plain-invokable-steps + native `ShouldQueue` fallback is therefore not taken.
- **Next Agent:** None gated on this plan — it and ADR-001…007 are **approved (Project Owner, 2026-07-30)**. The item #1 per-PRD implementation plan (and the Task Planner/Senior Developer work after it) waits on PRD-01 approval + the Designer's #1 UI spec.

## Appendix A — Illustrative pipeline walkthrough (NON-NORMATIVE)

> **Status: illustrative only.** This appendix is a proof-of-understanding sketch
> requested by the Project Owner. It is **pseudo-code** — not production code, not
> run, not a contract. It decides nothing beyond ADR-001…007; where it and an ADR
> ever disagree, the ADR wins. Class/method names are indicative. Its purpose is to
> make the ADR seams tangible as *code shape* and to mark, unambiguously, what is
> built at **item #1** versus **later-item scaffolding** shown only to prove a seam.
> The commented-out `LATER` classes are **not** to be built at item #1.
>
> **Laravel-native shape (revised 2026-07-30).** This revision drops the earlier
> hand-rolled `Pipeline` runner and bespoke `Dispatcher` interface in favour of
> Laravel primitives: the runner is the **first-party** `Illuminate\Pipeline\Pipeline`
> (`->send($ctx)->through($steps)->thenReturn()`, pipes with the
> `handle($passable, Closure $next)` middleware signature — one passable through
> in-process, matching ADR-001's single mutable `PipelineContext`), and
> execution-timing is realized by **run-sync-or-queue Action classes**
> (`lorisleiva/laravel-actions`, **proposed** in ADR-007 — not yet approved). The
> pipe contract stays a **first-party** `PipelineStep` interface we own; steps merely
> *also* use the Action traits. If ADR-007 is not approved, the same shape holds with
> plain invokable steps + native `ShouldQueue` jobs.

```php
// ============================================================================
// 1. THE SPINE CONTRACT — ADR-001, driven by native Illuminate\Pipeline\Pipeline
//    One in-memory mutable envelope + a first-party PipelineStep over it, run as a
//    pipe by Laravel's own Pipeline. Code-defined, composed by config, never
//    user-authored (ADR-001 rejects a workflow builder).
// ============================================================================

// The single mutable envelope every step reads/writes IN-PROCESS. Laravel's
// Pipeline passes THIS ONE passable through every pipe (exactly like middleware),
// so the ordered steps share this object in memory. It is serialised at most ONCE
// — as the input to the ProcessIngestedWebhook Action when that whole run is
// queued at #4 (§4) — never between steps (ADR-005 correction; ADR-001 preserved).
final class PipelineContext
{
    public function __construct(
        // ---- captured at ingest (item #1); raw inputs are never mutated ----
        public readonly string $ingestId,   // UUID correlating one webhook's fan-out set (ADR-003)
        public readonly Proxy  $proxy,       // resolved by token-hash (ADR-006)
        public readonly string $method,      // POST|PUT as received
        public readonly array  $headers,     // raw received headers
        public readonly string $rawBody,     // raw received bytes — immutable (R2)

        // ---- accumulated state; later steps write here ----
        // At item #1 the delivered payload IS the raw body. NormalizeStep(#9) and
        // MapStep(#8) overwrite $payload; DeliverStep only ever reads $payload, so
        // it never needs to know which upstream steps ran.
        public string $payload = '',         // set == rawBody at capture (see §6)
    ) {}
}

// FIRST-PARTY pipe contract (ADR-007: the contract is OURS, not the package's).
// Its signature is Laravel's middleware/pipe shape so native Pipeline drives it:
// a step mutates the shared context in place and MUST call $next to continue the
// chain. Steps additionally use the laravel-actions AsObject trait (see §3) for
// ::make()/::run() testability — but they are typed against THIS interface.
interface PipelineStep
{
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext;
}

// NO hand-rolled runner. The runner is Laravel's FIRST-PARTY pipeline, invoked by
// the ProcessIngestedWebhook Action (§4):
//
//     app(Illuminate\Pipeline\Pipeline::class)
//         ->send($ctx)                              // ONE mutable passable (ADR-001)
//         ->through($factory->stepsFor($ctx->proxy))// ordered pipes (ADR-002, §2)
//         ->thenReturn();                           // destination = the mutated $ctx
//
// WHERE/WHEN this whole run executes (inline at #1, queued at #4) is NOT decided
// here — it is the Action's run-sync-or-queue seam (ADR-005 / §4), not the runner.

// ============================================================================
// 2. THE COMPOSITION SEAM — ADR-001 (spine) + ADR-002 (mode gate)
//    PipelineFactory builds the ordered pipe list for a proxy from mode + config
//    and hands it straight to native Pipeline's ->through(). `mode` is a PURE
//    SELECTOR (ADR-002); enhanced config attaches as its own tables, never by
//    widening mode. At item #1 the list is exactly [DeliverStep]; the commented
//    lines are the fixed insertion contract.
// ============================================================================

final class PipelineFactory
{
    // No Dispatcher is injected here anymore. Steps are stateless pipes resolved
    // from the container via ::make(); each reads what it needs (proxy, maps) FROM
    // the PipelineContext at run time, so the factory just orders the pipe list.
    /** @return PipelineStep[] */
    public function stepsFor(Proxy $proxy): array
    {
        $steps = [];

        // ---- ENHANCED-ONLY front stages (LATER ITEMS — NOT built at #1) ----
        // Registered ONLY when mode === enhanced. The order below is the forward-
        // compat "insertion contract" from this plan's Architecture section, so
        // each later item drops in at a fixed position with no spine change.
        if ($proxy->mode === Mode::Enhanced) {
            // $steps[] = VerifyStep::make();       // #10 — verification token (front)
            // $steps[] = NormalizeStep::make();    // #9  — any format -> JSON
            // $steps[] = CaptureRawStep::make();   // #5  — StoreStep: persist raw input
            // $steps[] = MapStep::make();          // #8  — reshape (see §3 stub)
            // $steps[] = CaptureDispatchedStep::make(); // #5 — StoreStep: dispatched output
        }

        // ---- TERMINAL STEP — ITEM #1. Always present, BOTH modes. ----
        $steps[] = DeliverStep::make();  // fan-out terminal pipe (ADR-001)

        // ---- ENHANCED-ONLY tail stage (LATER — NOT built at #1) ----
        if ($proxy->mode === Mode::Enhanced) {
            // $steps[] = ChangeDetectStep::make(); // #12 — post-delivery structure diff
        }

        return $steps;  // fed directly to Pipeline->through() by the Action (§4)
    }
}

// ============================================================================
// 3. CONCRETE STEPS — each is an Action (AsObject) implementing PipelineStep, run
//    IN-PROCESS as a native Pipeline pipe. Steps are NEVER queued individually
//    (ADR-005 correction / ADR-007). Being Actions, each is unit-testable in
//    isolation: DeliverStep::make()->handle($ctx, fn ($c) => $c).
//    (a) DeliverStep — ITEM #1, the terminal fan-out.
//    (b) MapStep     — LATER (#8) stub, proving conditional map selection is an
//        in-step DATA choice, not pipeline branching.
// ============================================================================

// ===== ITEM #1 — at item #1 the ENTIRE pipeline is just this step. =====
final class DeliverStep implements PipelineStep
{
    use AsObject;   // ::make()/::run() convenience — NOT AsJob (steps aren't queued)

    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        // R3: ONE payload structure ($ctx->payload) to ALL destinations. Fan-out
        // is a SINGLE terminal step iterating destinations (ADR-001) — never
        // per-destination pipelines.
        foreach ($ctx->proxy->activeDestinations() as $destination) {

            // Build ONE per-destination unit of work, distinct from the in-memory
            // PipelineContext. This unit is the INPUT to the DeliverToDestination
            // Action — the DELIVERY-level run-sync-or-queue seam (ADR-005 / §4).
            $unit = new DeliveryUnit(
                ingestId:      $ctx->ingestId,      // correlate the fan-out set (ADR-003)
                teamId:        $ctx->proxy->teamId,
                proxyId:       $ctx->proxy->id,
                destination:   $destination,
                method:        $destination->httpMethod,
                headers:       $ctx->headers,
                payload:       $ctx->payload,
                attemptNumber: 1,                   // #6 retry increments this later
            );

            // ADR-005 timing seam, DELIVERY level. #1: ::run() delivers INLINE (one
            // class, sync). #4: swap to DeliverToDestination::dispatch($unit)
            //   ->onQueue('deliveries') per destination — per-proxy FIFO attaches as
            // a WithoutOverlapping("proxy:{id}") job middleware ON THAT ACTION (§4),
            // no ordering-key plumbing here. Only a non-Laravel transport (V3) needs
            // the residual first-party seam. Steps stay transport-unaware.
            DeliverToDestination::run($unit);
        }

        return $next($ctx);   // terminal step still returns $next to close the chain
    }
}

// ===== LATER-ITEM (#8) — NOT built at item #1. Stub proves the seam only. =====
// Conditional map selection is INTERNAL to this step (ADR-001 note 2026-07-30):
// one context in, one payload out. The branch is over DATA (which map to apply),
// never over topology/destinations — so it is NOT the excluded conditional routing.
final class MapStep implements PipelineStep
{
    use AsObject;

    public function handle(PipelineContext $ctx, Closure $next): PipelineContext
    {
        // Reads the already-captured, NormalizeStep(#9)-to-JSON payload from context.
        $incoming = json_decode($ctx->payload, true);

        // Select EXACTLY ONE map by matching a key's value against each map's
        // condition; fall back to the proxy's global/default map. The proxy (and its
        // enhanced-only maps) is read FROM the context — no constructor injection.
        // Precedence (M1) and match syntax (M2) are #8-PRD decisions — stubbed here.
        $map = $this->selectMap($ctx->proxy, $incoming) ?? $ctx->proxy->defaultMap();

        // Apply the single chosen map; write ONE reshaped payload back to context.
        $ctx->payload = $map->apply($incoming);   // R3: same structure to all dests

        return $next($ctx);
    }

    private function selectMap(Proxy $proxy, array $incoming): ?Map
    {
        foreach ($proxy->maps as $map) {              // enhanced-only config (#8)
            // e.g. match $incoming['type'] === 'invoice.paid' -> the invoice map
            if ($map->condition->matches($incoming)) {
                return $map;
            }
        }
        return null;                                   // -> global/default fallback above
    }
}

// ============================================================================
// 4. THE DISPATCH-TIMING SEAM — ADR-005, realized as run-sync-or-queue Actions
//    (ADR-007). All execution TIMING lives on these Action classes, never in the
//    steps. NO bespoke Dispatcher interface for the common case: ONE class runs
//    SYNC now (::run) or QUEUED at #4 (::dispatch). Two levels:
//      (a) PIPELINE level  — ProcessIngestedWebhook  (decouples the response; §6)
//      (b) DELIVERY level  — DeliverToDestination     (per-destination; §5 below)
// ============================================================================

// ---- (a) PIPELINE-LEVEL Action. ONE class runs the WHOLE pipeline over one
//         PipelineContext, in-process, via native Pipeline. ----
final class ProcessIngestedWebhook
{
    use AsAction;   // AsJob (::dispatch) + AsObject (::run) from ONE class

    public function __construct(private PipelineFactory $factory) {}

    public function handle(PipelineContext $ctx): void
    {
        // The single mutable context flows through all pipes IN-PROCESS (ADR-001).
        app(Illuminate\Pipeline\Pipeline::class)
            ->send($ctx)
            ->through($this->factory->stepsFor($ctx->proxy))   // ADR-002 mode gate
            ->thenReturn();
    }

    // #1: IngestController calls ::run($ctx) — synchronous, no job persisted (§6).
    // #4: IngestController calls ::dispatch($ctx) — the ENTIRE run is queued; the
    //     PipelineContext is serialized ONCE here (the job boundary), NEVER between
    //     steps. Per-proxy FIFO / queue selection attach on THIS Action's job:
    // public function configureJob(JobDecorator $job): void { $job->onQueue('pipelines'); }
    // public function getJobMiddleware(): array {
    //     return [new WithoutOverlapping("proxy:{$this->ctx->proxy->id}")]; // #4 FIFO
    // }
}

// ---- V3 SEAM (NOT built now) — the ONE thing AsJob CANNOT do: a NON-Laravel
//      transport (Kafka/streaming; partition/offset ordering-key). AsJob is bound
//      to Laravel's bus (ShouldQueue/SerializesModels), and Laravel's queue has no
//      native ordering-key primitive. IF V3 lands, a thin first-party publisher is
//      introduced at the ::dispatch call of these two Actions ONLY — pipeline,
//      steps, PipelineContext, and controller untouched (ADR-005 residual seam).

// A plain per-destination DTO — the dispatchable INPUT to DeliverToDestination,
// kept separate from the in-memory PipelineContext.
final class DeliveryUnit
{
    public function __construct(
        public readonly string      $ingestId,
        public readonly int         $teamId,
        public readonly int         $proxyId,
        public readonly Destination $destination,
        public readonly string      $method,
        public readonly array       $headers,
        public readonly string      $payload,
        public readonly int         $attemptNumber,
    ) {}

    public function forwardHeaders(): array { /* select/rewrite headers to forward */ return []; }
}

// ============================================================================
// 5. (b) DELIVERY-LEVEL Action + ATTEMPT RECORD + DOMAIN EVENTS — ADR-003 / ADR-005
//    DeliverToDestination is the per-destination run-sync-or-queue Action. Runs for
//    BOTH modes ("regardless of mode"). Records only OUTCOME METADATA — NEVER the
//    payload (payload storage is #5, a different entity). #1: DeliverStep calls
//    ::run() (inline). #4: ::dispatch()->onQueue() PER DESTINATION for independent
//    retry (#6); per-proxy FIFO via job middleware on THIS Action. Being one class,
//    the #14 test-payload path reuses it synchronously — no mock path.
// ============================================================================

final class DeliverToDestination
{
    use AsAction;   // AsJob + AsObject: ::run (sync #1) or ::dispatch (queued #4)

    public function handle(DeliveryUnit $unit): void
    {
        $startedAt = now();

        // Durable source of truth for #11 analytics — written BEFORE the outcome is
        // known, so a crash still leaves a 'dispatched' row (no lost data). This
        // table is PAYLOAD-FREE by construction (ADR-003 invariant).
        $attempt = DeliveryAttempt::create([
            'team_id'        => $unit->teamId,
            'proxy_id'       => $unit->proxyId,
            'destination_id' => $unit->destination->id,
            'ingest_id'      => $unit->ingestId,       // correlates the fan-out set
            'status'         => AttemptStatus::Dispatched,
            'attempt_number' => $unit->attemptNumber,
            'started_at'     => $startedAt,
            // NOTE: no body/payload column — payload lives in #5's storage entity only.
        ]);

        event(new DeliveryAttempted($attempt));        // transient event; #6/#13 subscribe

        try {
            $response = Http::withHeaders($unit->forwardHeaders())
                ->send($unit->method, $unit->destination->url, ['body' => $unit->payload]);

            $attempt->update([
                'status'      => $response->successful() ? AttemptStatus::Succeeded
                                                         : AttemptStatus::Failed,
                'http_status' => $response->status(),
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            // Terminal domain events (ADR-003). A destination failing does NOT abort
            // DeliverStep's loop (PRD AC9) and NEVER affects the upstream response
            // (ADR-004) — that was already returned at ingest (§6).
            $response->successful()
                ? event(new DeliverySucceeded($attempt))
                : event(new DeliveryFailed($attempt));  // #6 -> retry, #13 -> notify

        } catch (\Throwable $e) {
            $attempt->update([
                'status'        => AttemptStatus::Failed,
                'error_summary' => Str::limit($e->getMessage(), 250),  // summary only, no payload
                'duration_ms'   => $startedAt->diffInMilliseconds(now()),
            ]);
            event(new DeliveryFailed($attempt));
        }
    }

    // LATER (#4/#6) — retry/backoff/FIFO live on THIS Action's job, ZERO step change:
    // public int $jobTries = 5;                                        // #6 retry count
    // public function getJobBackoff(): array { return [10, 30, 60]; }  // #6 backoff
    // public function configureJob(JobDecorator $job): void { $job->onQueue('deliveries'); }
    // public function getJobMiddleware(): array {
    //     return [new WithoutOverlapping("proxy:{$this->unit->proxyId}")]; // #4 per-proxy FIFO
    // }
}

// ============================================================================
// 6. INGEST ENTRY POINT — ADR-006 (token-hash lookup) + ADR-004 (decoupled response)
//    Public route (external senders): POST|PUT /ingest/{ingestToken}
//    No session auth, CSRF-exempt, TLS-only.
// ============================================================================

final class IngestController
{
    // No PipelineFactory here anymore — composing + running the pipeline is the
    // ProcessIngestedWebhook Action's job (§4). The controller only builds the
    // envelope, resolves the response, and hands off.
    public function __construct(private ResponseResolver $responseResolver) {}

    public function __invoke(Request $request, string $ingestToken): Response
    {
        // Resolve proxy by HASHED token (ADR-006): deterministic SHA-256 over the
        // presented token, point lookup on a BINARY(32) UNIQUE index. No id embedded
        // in the URL. Unknown token -> 404 with no existence disclosure.
        $proxy = Proxy::query()
            ->where('ingest_token_hash', hash('sha256', $ingestToken, binary: true))
            ->first();

        abort_if($proxy === null, 404);

        // Build the in-memory envelope (ADR-001). NOTHING is persisted here at #1 —
        // payload storage is #5/enhanced-only (R2). $payload starts == rawBody;
        // NormalizeStep(#9)/MapStep(#8) overwrite it later, DeliverStep just reads it.
        $ctx = new PipelineContext(
            ingestId: (string) Str::uuid(),
            proxy:    $proxy,
            method:   $request->method(),
            headers:  $request->headers->all(),
            rawBody:  $request->getContent(),
            payload:  $request->getContent(),
        );

        // Resolve the upstream response BEFORE and INDEPENDENT of dispatch (ADR-004).
        // Never derived from a delivery outcome. #1 default: 202 Accepted. #3 later
        // reads $proxy->response_status/body inside the resolver — no handler change.
        $response = $this->responseResolver->resolve($proxy);

        // ADR-005 timing seam, PIPELINE level (§4). At #1 ::run() executes the whole
        // pipeline ([DeliverStep]) INLINE via native Pipeline (one class, sync). At #4
        // this becomes ProcessIngestedWebhook::dispatch($ctx) — the SAME class, now
        // queued after the response returns. Because the response above already does
        // not wait on delivery, going async is a ::run -> ::dispatch change on ONE
        // line, not a handler rewrite (and no step/context change).
        ProcessIngestedWebhook::run($ctx);

        return $response;   // returned regardless of any delivery result (ADR-004)
    }
}

// #1 default resolver. #3 replaces the body of resolve() to read proxy columns —
// the handler above is untouched.
final class ResponseResolver
{
    public function resolve(Proxy $proxy): Response
    {
        // LATER (#3): return response($proxy->response_body, $proxy->response_status);
        return response('', 202);    // ADR-004 default — NOT a committed contract for #1
    }
}
```

**How to read the item-#1 boundary in the sketch above.** Built at item #1:
`PipelineContext`, the first-party `PipelineStep` interface, `PipelineFactory`
(composing exactly `[DeliverStep]`), `DeliverStep`, the two run-sync-or-queue
Actions `ProcessIngestedWebhook` and `DeliverToDestination` (each invoked with
`::run()` only), `DeliveryUnit`, `DeliveryAttempt` + the three events,
`IngestController`, `ResponseResolver` (202 default). The **runner** is Laravel's
first-party `Illuminate\Pipeline\Pipeline` — nothing to build. `lorisleiva/laravel-actions`
(the `AsAction`/`AsObject`/`AsJob` traits) is a **proposed** dependency, ADR-007 —
build against it only after Owner approval; if rejected, the same shape holds with
plain invokable steps + native `ShouldQueue` jobs. Everything commented `LATER` —
`MapStep`, `VerifyStep`, `NormalizeStep`, the `CaptureRaw/CaptureDispatched`
StoreSteps, `ChangeDetectStep`, every `::dispatch()`/`onQueue`/`WithoutOverlapping`/
`getJobBackoff` job-config line, the V3 non-Laravel transport publisher, and the #3
resolver body — is scaffolding shown only to prove the seam holds; none of it is
built at item #1.
