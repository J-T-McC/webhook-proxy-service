# Technical Plan: Foundational architecture — ingest→delivery seams

- **Status:** Draft
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Scope:** Cross-cutting architectural foundation for Roadmap #1's walking
  skeleton, locking the four seams the roadmap flagged as architecture calls plus
  R5. Traces to `docs/product/vision.md` and the **Approved**
  `docs/product/roadmap.md` (build-ahead notes on items #1, #3, #4, #5, #6, #7,
  #8, #11, #13) and the R5 open question.
- **Approved by / date:** _Pending — Project Owner_

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
notifications, test payloads) attaches as an added step, an added driver behind
the dispatch seam, an event listener, or an aggregate over attempt records — never
as a rewrite of the spine. Six ADRs (ADR-001…006) lock the load-bearing
decisions.

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
4. **Pipeline dispatch** — `PipelineFactory` composes the ordered step list for the
   proxy from its `mode`/config (ADR-001, ADR-002); the runner executes it via the
   `Dispatcher` seam (ADR-005). At #1: `[DeliverStep]`, fire-and-forget.
5. **DeliverStep** — iterates the proxy's destinations; per destination performs
   HTTP POST/PUT and writes a `DeliveryAttempt` + emits `DeliveryAttempted`
   /`DeliverySucceeded`/`DeliveryFailed` (ADR-003). One destination failing does
   not block others (PRD AC9).

### The four flagged seams (+ R5) and where each later item attaches
| Seam | Locked by | Later items attach as |
|------|-----------|-----------------------|
| 1. Pipeline spine | ADR-001 | #5 CaptureRaw/CaptureDispatched steps · #8 MapStep · #9 NormalizeStep · #10 VerifyStep · #12 ChangeDetectStep · #14 reuses the same pipeline |
| 2. Attempt records + events | ADR-003 | #11 aggregates records · #6 listens to failure events · #13 keys off event severity |
| 3. Simple/enhanced mode | ADR-002 | #5 gates storage on `mode` · #7 flips `mode` (state change) · #8 gates MapStep |
| 4. Response decoupling | ADR-004 | #3 reads `response_status`/`response_body` · #4 makes dispatch async, response untouched |
| Queue seam (V3) | ADR-005 | #4 Redis driver + Job classes (FIFO/Async) · #6 job retry/backoff · V3 = new driver behind same seam |
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
- **`IngestController`** — resolves proxy, builds envelope, calls `ResponseResolver`, hands off to the pipeline dispatch. Never reads delivery outcome.
- **`ResponseResolver`** — proxy-config → upstream response (ADR-004).
- **`PipelineFactory`** — proxy `mode`/config → ordered `Step[]` (ADR-001/002).
- **`Pipeline` runner** — executes steps over `PipelineContext` via the `Dispatcher`.
- **`Dispatcher`** — driver-agnostic execution seam; `sync` at #1, Redis at #4 (ADR-005).
- **`DeliverStep`** — fan-out over destinations; HTTP POST/PUT; writes `DeliveryAttempt`; emits events (ADR-003).
- **`IngestTokenService`** — generate/hash/encrypt/rotate tokens (ADR-006).

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
- MySQL (MVP), Redis (MVP queue — driver behind ADR-005 seam), AWS via Forge.
- CSPRNG (`random_bytes`) and Laravel `encrypted` cast for ADR-006.

## Implementation Notes
- Team-scope every entity and query from the first commit (R1); no global (cross-team) reads.
- `DeliveryAttempt` must stay **payload-free** (ADR-003); payload capture is #5 only (R2).
- Raw captured input is **never mutated**; dispatched output stored separately — honour this in #5's entity even though #1 stores nothing (R2).
- Steps are code-defined and composed by config — **no workflow builder** (vision out-of-scope).
- The ingest response must never depend on delivery outcome (ADR-004).
- Keep the `Dispatcher` interface transport-agnostic; **do not** implement Kafka — V3 is an Owner decision gated by V8 (ADR-005).

## Handoff
- **Inputs:** `docs/product/vision.md`; Approved `docs/product/roadmap.md` (items #1, #3–#8, #11, #13 build-ahead notes; R1–R4, V1); PRD-01 (Draft, for context); R5 question doc.
- **Outputs:** this plan; ADR-001…006; R5 resolution (ADR-006, annotated in the R5 question doc).
- **Dependencies:** Laravel starter-kit auth/teams boilerplate.
- **Outstanding Questions:**
  1. **PM** — reconcile PRD-01 AC11 wording with the roadmap's attempt-record mandate (`docs/questions/prd-01-attempt-records-vs-storage.md`).
  2. **Project Owner** — approve this foundational plan + ADR-001…006; confirm ADR-006 residual preferences (TLS-enforcement layer; plaintext-token fallback y/n); note V3/V8 remain open by design.
  3. **Project Owner** — approve PRD-01 (still Draft) before the item #1 per-PRD implementation plan can be written; the Designer's UI spec for #1 is also required before that plan.
- **Next Agent:** Project Owner (approval of this foundational plan and its ADRs). The item #1 Task Planner/Senior Developer work waits on PRD-01 approval + the item #1 per-PRD plan.
