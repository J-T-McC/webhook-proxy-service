# Technical Plan: Payload storage & retention — item #5

- **Status:** Approved (Principal-Engineer self-certified) — **except** the **seven** items under
  **Handoff → Owner-approval flags (✋)**, which are **not** self-certified: **ADR-012**,
  **ADR-013**, **ADR-014**, the new **`dispatched_payloads`** table, the **schema change to the
  existing `webhook_events` table**, the **scheduled irreversible erasure of customer payload
  content**, and the **extension of at-rest encryption coverage to captured headers** (which
  reverses two Owner-accepted positions of ADR-010). Sections depending on those are contingent on
  Owner approval and do not proceed to Task Planning until it is given.
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-05-payload-storage-retention.md` — **Approved** (Project Owner,
  2026-08-05) and **amended** by **Amendment A** (Project Owner ruling, same date). This plan is
  written against the amended PRD: AC5–AC12 and AC15 as amended, plus **AC21**, **AC22**, and
  deferred concern **D1**.
- **Design spec:** none — PRD-05 has **no UX Direction section** and adds no UI
  (Q-05-01 RESOLVED Option B, Owner 2026-08-05). **No Designer gate applies to #5.**
- **ADRs:** **ADR-012** (revised for Amendment A), **ADR-013** (revised for Amendment A),
  **ADR-014** (new — captured-entity changes; partially supersedes **ADR-010** on two named
  positions). All three **Proposed**, all three Owner-gated.
- **Approved by / date:** Principal Engineer, **2026-08-05 — re-certified after Amendment A**
  (see *Re-certification* under Handoff). The seven flagged items remain pending Owner approval.

## Revision A — what Amendment A changed in this plan
Recorded so nothing is papered over. The plan was first written against a **hard-delete** design;
the Owner's 2026-08-05 ruling replaced it with **erase-in-place**.

| Prior plan position | Now |
|---|---|
| GC **hard-deletes** the `webhook_events` row (cascading `dispatched_payloads`) | **Erase-in-place.** A conditional `UPDATE` nulls `body` + `headers`, stamps `payload_cleaned_at`, and nulls the output body in the **same transaction**. Nothing is deleted (ADR-012 Decisions 1, 6). |
| "**No retention column, flag, or tombstone** is written anywhere; expiry is derived" | **Dropped.** AC21 requires an explicit signal: **`payload_cleaned_at`** on `webhook_events` (ADR-014). *Eligibility* is still derived (`created_at + windowFor(team)`); the *cleaned fact* is stored. |
| AC10's expired state derived from a **missing row** + surviving `delivery_attempts` | **Dropped.** Three explicit states read from `payload_cleaned_at` — `Retained \| Cleaned \| NeverCaptured` (AC21). |
| AC6/AC12 completeness **structural** via `cascadeOnDelete` | **Explicit.** The cascade never fires (nothing is deleted); atomicity of the two `UPDATE`s is the guarantee. FK downgraded to orphan prevention. |
| Conditional `settled`-only `fifo_dispatches` **DELETE**, the disjoint-index-range argument, and the **restrict FK as GC's fail-loud net** | **All three dropped as deletion-era apparatus.** GC never writes `fifo_dispatches` at all. Their role passes to the **compare-and-set** erase (holds re-asserted inside the `UPDATE`). |
| `dispatched_payloads.body` **`LONGBLOB NOT NULL`**, written on every enhanced-mode event | **Owner's Option B: `LONGBLOB NULL`, written only on divergence.** NULL means "output == input", disambiguated only by the parent's `payload_cleaned_at`. |
| Inbound headers stay **plaintext at rest** until #10 | **Reversed (AC22a).** `headers` becomes `MEDIUMTEXT NULL` with cast **`'encrypted:array'`**, and is erased by the same pass (AC22b). |
| `ProcessIngestedWebhook` drops `firstOrFail`; `AdvanceProxyFifoQueue` gains a settle-and-advance patch | **Both dropped.** `firstOrFail` stays (an absent row is now genuinely a bug); the entry gains one **cleaned-state guard**. `AdvanceProxyFifoQueue` needs **no change at all**. |
| Owner-approval flags: **four** | **Seven** — see Handoff. |
| Team-keyed `RetentionPolicy::windowFor()`; no `teams.retention_days`; H1–H4; batched per-team scheduled pass; `Team::withTrashed()`; `delivery_attempts` never touched; no payload content in logs; `CaptureDispatchedStep` placement and mode gate | **Retained unchanged.** |

## Overview
#5 puts a **lifecycle** on the payload capture #3 already delivered, and adds the
**dispatched-output** half of the raw/dispatched separation. Three additions, all outside the
ingest hot path:

**(a)** a **30-day** retention window resolved **per team**, plus a scheduled garbage collector
that **erases payload content in place** — nulling the raw body, the captured headers and the
dispatched output, and stamping an explicit **cleaned** state on the retained captured record
(ADR-012 + ADR-014).
**(b)** a new **`dispatched_payloads`** store written by an **enhanced-only pipeline step** placed
immediately before `DeliverStep`, whose body column is **written only when the dispatched bytes
differ from the captured raw bytes** (ADR-013, Owner's Option B).
**(c)** **at-rest encryption of captured inbound headers** (AC22a), a column-type change on
`webhook_events` that is transparent to every existing consumer (ADR-014, Q-05-04(i)).

Nothing in the ingest, response, or dispatch **behaviour** established by #1/#3/#4 changes: capture
stays a synchronous pre-response write (ADR-010 Decision 2, not superseded), the response stays
config-resolved (ADR-004), and the FIFO/Async dispatch machinery (ADR-011) is untouched — GC
composes with it by **reading** its state and **writing none of it**. `webhook_events` stops being
literally immutable and becomes **immutable while payload content is retained** (AC11 as amended);
the expiry pass is the **only** authorised mutator and writes exactly three columns
(ADR-014 Decision 2, P1). #5 adds **no UI, no route, no read path, and no user-facing control**
(Q-05-01, AC3, AC14, AC16–AC18). Growth in **retained records** — cleaned `webhook_events` rows and
never-pruned `fifo_dispatches` rows — is **deferred concern D1**, explicitly accepted out of scope
by the Owner; this plan asserts no cap, prune, archival, roll-up, partitioning, or numeric target.

## Q-05-03 and Q-05-04 — answered (the PRD routed both to technical design)

Full records: `docs/questions/prd-05-q-05-03-gc-composition-and-output-store.md` (RESOLVED, and
**amended** — its (ii) in full, its (iii) header/`cascadeOnDelete`/`NOT NULL` details, and named
parts of (i) and (iv) are superseded) and
`docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md` (RESOLVED — **both items
feasible as stated; no requirement returns to the Product Manager**). Current answers:

**Q-05-03 (iv) — answered first because it gates everything: YES, and the gate list is now SEVEN
items**, each with its exact ask under **Handoff → Owner-approval flags (✋)**. Three ADRs; two
data-model changes (one **new table** *and* — new since Amendment A — a **schema change to an
existing table**); one **irreversible** destructive operation; one security-coverage extension that
**reverses an Owner-accepted position**. **Not** tripped: no new Composer/pnpm dependency, no stack
change, no backfill. V6/Postgres is **not** reopened.

**Q-05-03 (i) — GC vs. ADR-011 FIFO claim state and Async in-flight jobs (AC8).** Eligibility is a
conjunction of five named **holds** (ADR-012 Decision 4): **H0** not already cleaned;
**H1** expired; **H2** no `fifo_dispatches` row for the event in a status other than `settled`;
**H3** no `delivery_attempts` row for the event's `ingest_id` in status `dispatched`; **H4** if the
event has *zero* attempt rows, it must be older than a short pre-dispatch horizon. Exactly one hop
re-reads a captured row after capture — the pipeline entry (ADR-011 Decision 3) — and H2/H4 cover
it. H3 is necessary **by criterion, not by loss risk**: per-destination Async jobs carry their bytes
in the `DeliveryUnit` and never re-read the event, but AC8 states the requirement in terms of
outstanding dispatch and marking an event cleaned mid-delivery would publish a false AC21 state.
**GC and #4 now share zero written tables** — GC writes only `webhook_events` and
`dispatched_payloads`; it reads `fifo_dispatches` and `delivery_attempts` and writes neither. The
select→act gap is closed by a **compare-and-set** (holds re-asserted inside the erase `UPDATE`), not
by a constraint.

**Q-05-03 (ii) — retention bookkeeping vs. the captured entity (AC11).** *Superseded.* AC21 requires
a stored cleaned fact, so one column is added: **`payload_cleaned_at TIMESTAMP NULL`** (ADR-014
Decision 4). *Eligibility* remains derived — `created_at + windowFor(team)`, no `expires_at`, no
sidecar row, no soft delete. AC11 holds on its **amended** reading: immutability binds absolutely
while content is retained, and this pass is the only authorised mutator, writing only `body`,
`headers`, `payload_cleaned_at`. The window stays a **team-keyed resolver**
(`RetentionPolicy::windowFor(Team)`), not a column and not a constant.

**Q-05-03 (iii) — where the dispatched-output store lives (AC12/AC15).** A new
**`dispatched_payloads`** table — one row per received event, body only, `encrypted` cast,
`UNIQUE(webhook_event_id)` — written by **`CaptureDispatchedStep`** in `PipelineFactory`'s existing
enhanced-only front stage, immediately before `DeliverStep` and **after both** reserved transform
seams (`// #9 NormalizeStep` at `PipelineFactory.php:27`, `// #8 MapStep` at `:31`). The body is
written **only on divergence**. #9 and #8 attach by filling their seams ahead of it; #10 attaches by
layering policy on the same cast. Headers and method are still **not** stored — but on
**re-derived** grounds (redundancy, AC13/R3 biting the useful version, and #10's ownership of header
policy); the old "AC15 forbids widening the plaintext header surface" ground is **void** under AC22
and must not be cited.

**Q-05-04 (i) — header encryption at rest.** Feasible; **no consumer disturbed**. Every read of
`webhook_events.headers` goes through the Eloquent attribute; ADR-008's forwarding filter is an
in-memory `array_filter` over a constant list and touches no SQL; no `whereJson*`, `JSON_EXTRACT`,
`where('headers', …)`, header index, or header aggregate exists anywhere in `app/`, `database/`, or
`tests/`. **ADR-008 is undisturbed and is not amended.** One **mandatory** column-type change:
MySQL validates `json` columns on write and the `encrypted` envelope is not valid JSON, so
`json NOT NULL` → **`MEDIUMTEXT NULL`**. `content_type` is already denormalised at capture, before
the cast, so AC6's content-type ruling costs no new code and needs no exception carved into the
erasure.

**Q-05-04 (ii) — cleaned state + in-place erasure vs. #4 dispatch state.** Feasible; net simpler.
Four interactions change (compare-and-set replaces the FK net; GC shares zero written tables with
#4; two planned code patches become unnecessary; AC12 atomicity moves from structural to explicit),
and **one new hazard** dominates the design: a reader that guards on `body === null` instead of
`payload_cleaned_at` would **silently dispatch an empty payload to every destination**. Binding
ruling (ADR-014 Decision 7): **guard on the signal, never on the value.**

## Architecture

Three isolated additions. **No change to the ingest handler's behaviour, the response contract, the
capture placement, the pipeline shape for simple mode, the attempt-record shape, or the FIFO/Async
dispatch machinery.**

**A. Retention & erasure (AC1–AC10, AC21, AC22b) — a scheduled action, entirely outside the request
path.** `PurgeExpiredPayloads` runs daily from `routes/console.php` (the existing `Schedule::`
pattern, alongside the invitation cleanup and the #4 FIFO sweeper). Per run:
1. Chunk `Team::withTrashed()` — a soft-deleted team's payloads must still expire, and retention
   keys off the owning team, never a live session.
2. Per team: `cutoff = now() - RetentionPolicy::windowFor($team)`.
3. Select up to `config('retention.purge_batch')` collectable `webhook_events` ids for that team
   under holds **H0–H4**, seeking on the new `(team_id, payload_cleaned_at, created_at)` index
   (ADR-014) so a run's cost is proportional to what it will actually erase rather than to every row
   it has ever cleaned.
4. Per id, in one short transaction (ADR-012 Decision 1):
   - conditional `UPDATE webhook_events SET body = NULL, headers = NULL, payload_cleaned_at = NOW()`
     **carrying H0–H4 in its own `WHERE`** — the compare-and-set;
   - **only if one row was affected**, `UPDATE dispatched_payloads SET body = NULL WHERE
     webhook_event_id = ?`;
   - commit. Zero rows affected ⇒ a hold reappeared between selection and write ⇒ skip the event;
     the next run collects it.
5. Loop per team until a batch comes back short. Log counts and identifiers only — never payload
   content (coding.md never-log list, binding).

`delivery_attempts` and `fifo_dispatches` are **read** for holds and **never written** (AC9;
ADR-012 Decision 5). Nothing is deleted anywhere.

**B. Dispatched-output capture (AC12–AC14, AC19) — one new enhanced-only pipeline step.**
`PipelineFactory::stepsFor()` gains `CaptureDispatchedStep::make()` inside the existing
`ProxyMode::Enhanced` front stage, replacing the reserved comment at `PipelineFactory.php:32`,
immediately before the always-present `DeliverStep` and **after both** still-commented transform
seams (`// #9 NormalizeStep` `:27`, `// #8 MapStep` `:31`). Simple-mode pipelines are unchanged and
structurally cannot produce an output row (AC12, AC14). The step compares the bytes it is about to
hand `DeliverStep` against the captured raw bytes — `$ctx->payload !== $ctx->rawBody`, both already
on `PipelineContext` (`:18`, `:33`, seeded equal at `:36`) — and writes one idempotent
`dispatched_payloads` row per event, storing the body **only when they differ**. Pre-#9 every row
carries `body = NULL` and the store holds **zero payload bytes**. It runs on the worker under both
Async and FIFO, after the upstream response; raw capture's before-response guarantee is ADR-010's
and is untouched.

**C. The cleaned state as a first-class, explicitly signalled state (AC10, AC21).** Three surfaces,
all system-internal (no UI at #5):
- **`App\Enums\StoredPayloadState { Retained, Cleaned, NeverCaptured }`** names AC21's three states
  once.
- **`App\Services\StoredPayloadLookup`** (new) resolves an `ingest_id` to one of them by reading
  `payload_cleaned_at` — **never** by inferring from `body IS NULL`, a failed lookup, or the
  presence of `delivery_attempts`. It is the **only** resolver, and the only place
  `dispatched_payloads.body IS NULL` may be interpreted (ADR-013 Decision 3). Nothing consumes it at
  #5; it exists so AC10's "represented, not failing" state has one named home and #6 inherits an
  exact contract instead of inventing one.
- **`ProcessIngestedWebhook`** keeps `firstOrFail()` (`:29`) — an absent row is now genuinely a bug,
  never expiry — and gains **one guard**: if `payload_cleaned_at !== null`, log `payload.expired`
  (identifiers only) and return cleanly **before** building the `PipelineContext`, so nothing is
  delivered. **`AdvanceProxyFifoQueue` needs no change at all**: its
  `$claimed->webhookEvent->ingest_id` dereference (`:42`) can no longer meet a missing event, and the
  early return still lets it settle the row (`:44`) and self-dispatch (`:50`), so a FIFO line cannot
  stall. Holds H2–H4 make the guard unreachable at #5; it is required because AC10 forbids erroring
  where a cleaned payload is referenced, because it becomes reachable at #6 (replay of an aged
  event), and above all because the alternative failure mode is silently dispatching an empty body.

**D. Header encryption at rest (AC15, AC22a) — a cast and a column type, no path change.**
`webhook_events.headers` becomes `MEDIUMTEXT NULL` with the cast `'encrypted:array'`. The capture
write gains one in-process `encrypt()` alongside the body encryption already there — no extra I/O,
no extra query, no change to `IngestController`, `WebhookEventCapture`, or #3's
capture-before-response guarantee. `WebhookEventCapture::contentTypeFrom()` reads the **in-memory**
header array before any cast, so `content_type` keeps working and survives erasure (AC6). ADR-008
forwarding is unchanged: `DeliverStep.php:46` passes the decrypted in-memory array to
`DeliveryUnit::forwardHeaders()`, which filters against the constant `STRIPPED_HEADERS`.

### Post-clean dispatched-output write — technical ruling (recorded, not silently designed)
The revised ADRs are silent on one interaction the switch from delete to erase opens up, so this
plan names it rather than leaving it implicit.

Under the delete design, a `dispatched_payloads` insert for an already-collected event **failed on
the FK** (the parent row was gone) — fail-loud. Under erase-in-place the parent row survives, so
`CaptureDispatchedStep` could create or update a row for an event already marked cleaned if an erase
commits **between** the pipeline-entry guard (Architecture C) and the step. **Ruling:**
`CaptureDispatchedStep`'s write is conditioned on the parent's `payload_cleaned_at IS NULL`
(a compare-and-set on the parent, evaluated in the same statement/transaction as the write); if the
parent is cleaned the step logs `payload.expired` and aborts before `DeliverStep`, exactly as the
entry guard does.

This is a **writer-side application of ADR-014 Decision 7's binding invariant** ("guard on the
signal, never on the value"), not a new architectural decision — which is why it is recorded here
rather than being added to a finished ADR. Its exposure at #5 is **nil**: pre-#9 the divergence gate
always yields `body = NULL`, so the only thing that could land after a clean is a
descriptor-only row (`byte_size`, `dispatched_at`) — AC6-permitted, with the parent still reading
Cleaned. It becomes a genuine AC6 exposure the moment #9, #8 or #10 fills a transform seam. **Asked
of the Owner as part of the ADR-013 sign-off round** (flag 2): fold this condition into ADR-013
Decision 4, or rule it an implementation detail of this plan.

## Data Model

**Two changes: one new table, and a schema change to one existing table.** MySQL 8.0 / InnoDB.
No backfill. No change to `teams`, `proxies`, `fifo_dispatches`, or `delivery_attempts`.
**Both require Owner approval** (flags 4 and 5).

### `webhook_events` — captured-entity changes (AC6, AC11, AC21, AC22; ADR-014)
New migration `alter_webhook_events_for_payload_erasure`. MySQL-specific, following the #3 precedent
(the create migration already uses a raw `ALTER … LONGBLOB`, `:45`).

| Change | From | To | Why |
|---|---|---|---|
| `body` | `LONGBLOB NOT NULL` | `LONGBLOB NULL` (raw `ALTER … MODIFY`) | The erasure target (AC6, AC11). Value-preserving. |
| `headers` | `json NOT NULL`, cast `'array'` | **`MEDIUMTEXT NULL`**, cast **`'encrypted:array'`** | AC22a + AC22b. **Mandatory, not cosmetic** — MySQL validates `json` on write and the `encrypted` envelope is not valid JSON (error 3140). `MEDIUMTEXT` over `TEXT` to keep a silent-truncation cliff off a column that now carries the confidentiality floor. |
| `payload_cleaned_at` | — | `TIMESTAMP NULL`, added `AFTER byte_size` | The AC21 cleaned-state signal (ADR-014 Decision 4). A timestamp, not a boolean: same storage, records *when*, self-evidencing. |
| index | — | `(team_id, payload_cleaned_at, created_at)` | Turns the GC selection into a seek over **uncleaned** rows only. A property of the pass, **not** a growth measure — D1 is not re-raised. |

- **The `headers` step drops and re-adds the column** rather than `MODIFY`-ing it: existing rows hold
  plaintext JSON the new cast cannot decrypt. **Destructive to captured headers in any existing
  local/CI database** — acceptable exactly because the Owner ruled there is no production data to
  protect. Re-add with `AFTER method` so column order is preserved.
- **Retained through erasure** (AC6's permitted non-content descriptors): `method`, `content_type`,
  `byte_size`, `received_at`, `ingest_id`, `team_id`, `proxy_id`, `created_at`, `updated_at`.
  `content_type` is retained by explicit Owner ruling and **must stay denormalised at capture** —
  deriving it from `headers` at read time would break after erasure.
- **`down()` is best-effort** (re-adds `headers` as `json NOT NULL`, drops `payload_cleaned_at` and
  the index, restores `body NOT NULL`) and will fail on rows already cleaned. Acceptable on the same
  no-production-data basis; state it in the migration docblock rather than pretending it round-trips.
- **Model.** `WebhookEvent` casts become `'body' => 'encrypted'`, **`'headers' => 'encrypted:array'`**,
  `'payload_cleaned_at' => 'datetime'`, `'received_at' => 'datetime'`, `'byte_size' => 'integer'`.
  `payload_cleaned_at` is **not** added to `#[Fillable]` — the expiry pass writes it through the
  query builder, never through mass assignment. The class docblock's "`headers` stay plaintext until
  #10" line must be corrected to point at ADR-014.

### `dispatched_payloads` — enhanced-mode dispatched output (AC12, AC13, AC15; ADR-013)
New migration `create_dispatched_payloads_table` + `DispatchedPayload` model. **One row per received
event, enhanced mode only.** Holds the dispatched **body** — not headers, not method, not outcome,
not retention state.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | `foreignId()->constrained()` (restrict) | Team-scoped like every sibling; set explicitly on the worker path (team-unscoped, mirrors `WebhookEventCapture`/`DeliverToDestination`). |
| `proxy_id` | `foreignId()->constrained()` (restrict) | |
| `webhook_event_id` | `foreignId()->constrained()->cascadeOnDelete()`, **UNIQUE** | Association to the received event (AC12). UNIQUE = exactly one output per event (AC13) **and** makes the write idempotent under at-least-once redelivery. **The cascade is orphan prevention for a future delete path only — it is no longer the AC6/AC12 mechanism** (nothing is deleted on expiry) and must not be cited as one. |
| `body` | **`LONGBLOB NULL`** via raw `ALTER` (same treatment as `webhook_events.body`; Blueprint `binary()` maps to 64 KiB `BLOB`) | Cast `'body' => 'encrypted'` (AC15). **NULL is meaningful and ambiguous by design** — "output == input" *or* "erased" — and is resolved **only** by the parent's `payload_cleaned_at` (ADR-013 Decision 3). |
| `byte_size` | `unsignedInteger` | **Plaintext** dispatched size, recorded before the cast, **always** — including when `body` is NULL, where it equals the raw `byte_size`. A non-content descriptor; survives erasure (AC6). Serves #11. |
| `dispatched_at` | `timestamp` | When the pipeline produced the output. |
| `created_at` / `updated_at` | timestamps | |
| indexes | `UNIQUE(webhook_event_id)`, `(team_id, created_at)` | UNIQUE is the AC13 enforcement; the composite mirrors siblings and serves a future team-scoped read (AC16). |

- **Exact shape proposed for Owner approval:** the table above, verbatim.
- **No `diverged` flag and no cleaned column of its own** — deliberately (ADR-013 Decision 3 and
  Alternatives). Consequence, stated rather than papered over: **after erasure, whether the output
  had diverged is not recoverable.** No AC requires it and no consumer exists.
- **Model.** `DispatchedPayload` uses `BelongsToCurrentTeam` (so any future read path is team-scoped
  by default — AC16), `belongsTo(WebhookEvent)`, `belongsTo(Proxy)`; casts `'body' => 'encrypted'`,
  `'byte_size' => 'integer'`, `'dispatched_at' => 'datetime'`. `#[Fillable]` per house convention.
  No `SoftDeletes`.

### Explicitly **not** changed
| Table | Why it is untouched |
|---|---|
| `teams` | No `retention_days` column at #5 — AC3's team-level property is expressed as `RetentionPolicy::windowFor(Team)` (ADR-012 Decision 2). Q-05-02(a): a later lever "changes only where the value comes from". |
| `fifo_dispatches` | **GC never touches it at all** (ADR-012 Decision 5). No status change, no delete, no new column, no index. Its restrict FK is retained for referential integrity only and is **no longer** an AC6/AC8 guarantee. Consequence: settled rows are never pruned — **D1**, out of scope. |
| `delivery_attempts` | AC9 / ADR-003 — never deleted, never altered. Read-only, as the H3/H4 in-flight signal. It is **no longer** the AC10 expired-state signal; `payload_cleaned_at` is. |
| `proxies` | AC14/AC18 — no storage toggle. The gate is the existing `mode` attribute (ADR-002) applied structurally in `PipelineFactory`. |

## API

**No route changes. No controller changes. No Inertia prop changes. No frontend changes.**
#5 ships **no user-facing surface** (Q-05-01 Option B; AC16–AC18 add no read, sharing, export, or
download path). The only new interface is a console command.

- **`payloads:purge-expired`** (`PurgeExpiredPayloads` as `AsCommand`) — no arguments; scheduled
  daily. Idempotent by construction (hold **H0**) and safe to re-run; a second concurrent run is
  prevented by `withoutOverlapping()` on the schedule entry.
- **`ProxyResource` and every existing endpoint are unchanged.** Nothing exposes stored payload
  content at #5; AC16 remains the standing constraint on whoever adds the first read path (#6),
  which must be team-scoped and gated on the existing proxy read permission (PRD-02 / ADR-009).

## Services

- **`App\Services\RetentionPolicy`** (new) — the single source of the retention window (AC1–AC3):
  - `windowFor(Team $team): CarbonInterval` — today `CarbonInterval::days(config('retention.days'))`
    for **every** team. The `Team` parameter is the V5/V6 extension point; **the method body is the
    only thing a later tier/region lever changes.**
  - `cutoffFor(Team $team): CarbonImmutable` — `now()->sub(windowFor($team))`; the GC scan bound.
  - `expiresAt(WebhookEvent $event): CarbonImmutable` — `created_at + windowFor(team)`; the
    per-event answer for #6 and any future read path. Nothing persists this value.
- **`App\Actions\PurgeExpiredPayloads`** (new, `AsCommand` + `AsAction`) — the garbage collector
  (AC5, AC6, AC9, AC12, AC22b). Iterates `Team::withTrashed()` in chunks; per team resolves the
  cutoff, selects collectable event ids under holds H0–H4 with a `LIMIT`, and erases each per
  Architecture A.4 in a short transaction. Loops per team until a short batch. Returns/logs counts
  and identifiers only.
- **`App\Actions\CaptureDispatchedStep`** (new, `AsObject`, implements `App\Pipeline\PipelineStep`) —
  the enhanced-only dispatched-output capture (AC12, AC13, AC19). Resolves the `WebhookEvent` by the
  UNIQUE-indexed `$ctx->ingestId`; aborts if the parent is cleaned (see the ruling above); computes
  `$diverged = $ctx->payload !== $ctx->rawBody`; then `updateOrCreate` on `webhook_event_id` with
  `body = $diverged ? $ctx->payload : null`, `byte_size = strlen($ctx->payload)` (plaintext,
  pre-cast, always), `dispatched_at = now()`, `team_id`/`proxy_id` set explicitly from `$ctx->proxy`.
  Calls `$next($ctx)` unchanged — it **never mutates** `$ctx->payload` or `$ctx->rawBody`
  (AC11, AC19).
- **`App\Enums\StoredPayloadState`** (new) — `Retained | Cleaned | NeverCaptured`, AC21's three
  states named once.
- **`App\Services\StoredPayloadLookup`** (new) — the **only** resolver of payload state (AC10, AC21):
  `for(string $ingestId): StoredPayloadState` reads `payload_cleaned_at` on the captured row —
  NULL ⇒ `Retained`, non-NULL ⇒ `Cleaned`, no row ⇒ `NeverCaptured`. It is also the only place
  `dispatched_payloads.body IS NULL` may be interpreted (ADR-013 Decision 3). No storage, no new
  column, not consumed at #5; it is the named home #6 builds on.
- **`App\Pipeline\PipelineFactory`** (existing) — one line: replace the reserved
  `// $steps[] = CaptureDispatchedStep::make(); // #5` comment (`:32`) with the real step, keeping
  its position (enhanced-only front stage, **after** the `// #9 NormalizeStep` and `// #8 MapStep`
  seams, **before** `DeliverStep`).
- **`App\Actions\ProcessIngestedWebhook`** (existing) — AC10/AC21 only: **keep `firstOrFail()`**
  (`:29`) and add the cleaned-state guard before constructing the `PipelineContext` (`:35`). **No
  other change** — dispatch-by-reference, the trashed-inclusive proxy load, and the pipeline run are
  untouched (ADR-011 Decision 3).
- **`App\Actions\AdvanceProxyFifoQueue`** (existing) — **unchanged.** The settle-and-advance patch the
  delete design required is dropped.
- **`App\Models\WebhookEvent`** (existing) — casts + docblock only (see Data Model). `#[Fillable]`
  unchanged.
- **`routes/console.php`** (existing) — one `Schedule::` entry: daily, off-peak,
  `->withoutOverlapping()`, `->description('Erase expired stored payloads')`. Fixed cadence, not a
  tunable — same posture as the #4 FIFO sweeper.
- **`config/retention.php`** (new) — `days` (default **30**, AC2), `purge_batch`,
  `dispatch_horizon_minutes` (hold H4). Env-overridable for dev/test only; **not** a per-team or
  user-facing lever (AC3) and not a per-deployment product decision — 30 days is the product value.
- **`IngestController`, `WebhookEventCapture`, `ResponseResolver`, `DeliverStep`, `DeliveryUnit`,
  `DeliverToDestination`, `SweepStalledFifoDispatches`** — **unchanged.** (`WebhookEventCapture`
  writes an encrypted header value implicitly via the model cast; its code does not change, and
  `contentTypeFrom()` keeps reading the in-memory array.)

## Validation

**No user input is added by #5** — no form, no request, no route, no Form Request, and no
user-facing control over retention (AC3, AC14, AC16–AC18). Validation reduces to **system-side
invariants** the implementation must uphold:

- **Config sanity.** `retention.days` must be a positive integer; `purge_batch` a positive integer;
  `dispatch_horizon_minutes` a non-negative integer. A non-positive window would make every payload
  instantly collectable — read via `(int)` casts with the documented defaults and never allow a
  resolved window of zero or less.
- **Hold conjunction is mandatory, and asserted twice.** A payload is erased only when **all** of
  H0–H4 are clear, evaluated **in the selection query** (`whereNotExists` / `whereExists`) **and
  again inside the erase `UPDATE`'s own `WHERE`**. The second assertion is not belt-and-braces: it
  is the compare-and-set that replaces the deletion design's restrict-FK net, and omitting it
  reopens the select→act gap with nothing left to close it.
- **Zero rows affected means skip, never proceed.** The `dispatched_payloads` erase runs only when
  the event `UPDATE` affected exactly one row, inside the same transaction (AC12: no window in
  which one survives the other).
- **Never guard on `body === null`.** The cleaned signal is `payload_cleaned_at`, everywhere, for
  every consumer. The failure mode of getting this wrong is **silently dispatching an empty payload
  to every destination** (ADR-014 Decision 7, binding).
- **The expiry pass writes exactly three columns** of a captured row — `body`, `headers`,
  `payload_cleaned_at` — via the **query builder**, not a model `save()`, so `updated_at` is not
  touched and no other attribute can be dragged along (ADR-014 Decision 2 / ADR-010 P1 as narrowed).
- **Dispatched-output write is idempotent.** Keyed on `UNIQUE(webhook_event_id)`; a queue redelivery
  re-running the pipeline must produce exactly one row (AC13).
- **`byte_size` is the plaintext length**, computed before the `encrypted` cast, in both stores, and
  recorded on every `dispatched_payloads` row including NULL-body ones.
- **The divergence test is a byte comparison of `$ctx->payload` against `$ctx->rawBody`** — never a
  re-read of the database, never a normalised or trimmed comparison.

## Risks
1. **Irreversible data loss is the feature.** GC permanently erases customer payload content — raw
   bodies, captured headers, dispatched outputs — with no soft delete, archive, or recovery path
   (AC6). Erase-in-place is **not** less destructive than the delete it replaces: the content is
   unrecoverable either way; only the record survives. A bug in the hold predicates or the cutoff
   arithmetic destroys data that cannot be restored. **Mitigations:** holds expressed once, in one
   query builder, asserted twice, with a test per hold; an explicit unexpired-never-erased test
   (AC7); batch limits so a bad run is bounded; counts-only logging. **Owner-flagged (✋) — this is
   the sign-off, not a code-level risk.** The first production run erases **nothing**: the oldest
   captures date from #3 (2026-08-04), so nothing reaches 30 days until ~2026-09-03.
2. **Guarding on the wrong thing dispatches an empty payload.** The single most important consequence
   of moving from delete to erase: under delete a racing reader failed loudly
   (`ModelNotFoundException`); under erase the row is still there with `body = NULL`. **Mitigation:**
   ADR-014 Decision 7 makes `payload_cleaned_at` the only permitted guard, `StoredPayloadLookup` the
   only resolver, and a dedicated test asserts nothing is sent for a cleaned event.
   **Non-blocking; design-level, not a residual risk.**
3. **H4 rests on a stated assumption, not a record.** An Async event captured but whose
   `ProcessIngestedWebhook` job has not yet started leaves no durable trace; H4 bounds that window by
   age. Inert while the window (30 d) vastly exceeds the horizon (~60 min), and only a total queue
   outage lasting longer than the retention window could defeat it. **Improved in consequence by
   erase-in-place:** the residual case now logs, returns cleanly, and leaves an auditable record
   marked cleaned instead of an event that vanished. **Non-blocking**; the alternative (a
   dispatch-completion marker row per event) was rejected in ADR-012 as disproportionate.
4. **A dispatched-output write could land after its parent was cleaned.** Reachable only in the H4
   residual window. **Exposure at #5 is nil** — pre-#9 the divergence gate stores no bytes, so the
   worst case is a descriptor-only row — and it becomes a genuine AC6 exposure once #9/#8/#10 fills a
   transform seam. **Mitigation carried in this plan:** the *Post-clean dispatched-output write*
   ruling above. **Raised with the Owner as part of flag 2**, not folded silently into a finished ADR.
5. **Interim duplication of payload volume — eliminated at #5, not merely accepted.** Q-05-02(b)
   accepted a byte-identical second copy of every enhanced-mode payload. Under the Owner's Option B
   the store holds **no payload bytes at all** until a transform seam is filled: a strict improvement
   on what was accepted, achieved without changing the requirement. **Non-blocking.**
6. **Key-lifecycle surface triples.** ADR-010 Amendment B's binding rule (never drop a prior
   `APP_KEY` from `APP_PREVIOUS_KEYS` until every row is rehashed) now spans **three columns across
   two tables** — `webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`. Losing
   a key now breaks **header forwarding** as well as the body: no new failure *class* (the body is
   already undeliverable in that scenario), but wider scope for the future re-encryption command.
   **Owner-flagged (✋) acknowledgement; no code at #5** — the command remains ADR-010's accepted
   FUTURE task.
7. **The `headers` migration is destructive to existing local/CI databases.** Dropping and re-adding
   the column discards captured headers wherever a database already exists; `down()` does not round
   trip. Acceptable **only** on the Owner's stated basis that there is no production data to protect,
   which is precisely what makes this migration trivial. **Owner-flagged (✋) as part of flag 5.**
8. **A failed dispatched-output write blocks that event's delivery.** By design (ADR-013 Decision 5),
   the step precedes `DeliverStep`, so a write failure aborts before any send: loss-free and
   duplicate-free on redelivery, but a persistently failing write means an enhanced-mode event is not
   delivered. Consistent with ADR-010's precedent (capture failure → 500, dispatch nothing) and only
   reachable when the database is already failing. Under FIFO it delays the line by one claim lease
   (~90 s) per retry via the sweeper, never losing the event. **Non-blocking; a deliberate ruling,
   reversible in one line if the Owner prefers deliver-then-store.**
9. **Lock contention between GC and the FIFO advancer — structurally eliminated, not merely
   minimised.** GC writes only `webhook_events` and `dispatched_payloads`, tables the advancer never
   writes; it reads `fifo_dispatches` and `delivery_attempts` and writes neither. The deletion
   design's disjoint-index-range argument and re-claim-race analysis are gone because the writes they
   analysed are gone. **Non-blocking.**
10. **Cleaned rows stay in the scan range forever.** Under delete, each run's scan set was
    self-limiting; under erase, a cleaned row remains inside `(team_id, created_at <= cutoff)`
    permanently. Closed by the new `(team_id, payload_cleaned_at, created_at)` index plus hold H0,
    which together make a run's cost proportional to what it will actually erase. This is a
    **liveness property of the pass**, not a growth measure — **D1 is not re-raised and no cap,
    prune, or numeric target is asserted.**
11. **Physical space is not returned to the OS.** Nulling a `LONGBLOB` frees its off-page overflow
    pages inside the tablespace only; reclaiming space needs a table rebuild. The logical unbounded
    growth of **payload content** — the PRD's actual concern — is closed. **Ops note, not a code
    blocker; no numeric target asserted (AC20).**
12. **Soft-deleted teams must be iterated.** Omitting `withTrashed()` would silently immortalise a
    deleted team's payload content — the exact failure #5 exists to prevent. Called out in
    Implementation Notes and covered by a test.
13. **Record growth is accepted, and this plan does not design against it.** Cleaned `webhook_events`
    rows accumulate for the life of an account and `fifo_dispatches` rows are now never pruned.
    **Deferred concern D1** (Owner, 2026-08-05) — out of scope, no requirement, no design here. A
    future record-lifecycle policy attaches to the same team-keyed pass at zero re-modelling cost.

## Dependencies
- **No new packages.** Eloquent, migrations, the Laravel scheduler (already required by #4's FIFO
  sweeper), the native `Pipeline`, `lorisleiva/laravel-actions` (ADR-007). **Stays within
  `docs/stack/stack.md` — no stack change.** V6/Postgres **not reopened**: #5's payload volume is
  bounded by the erasure it introduces, and record growth is D1.
- **ADR-012** (Proposed, revised for Amendment A) — retention model, team-keyed window, hold-based
  eligibility, compare-and-set erase-in-place, single-transaction erasure of both stores. Gates all
  retention/GC work.
- **ADR-013** (Proposed, revised for Amendment A) — `dispatched_payloads` + `CaptureDispatchedStep`,
  divergence-gated nullable body. Gates the output-store work.
- **ADR-014** (Proposed, **new**) — `webhook_events` schema changes, header encryption at rest, the
  `payload_cleaned_at` signal, the reader guard, and the enumerated **partial supersession of
  ADR-010** (P1, P2). Gates the captured-entity work and the header-encryption work.
- **ADR-010** (Accepted, incl. Amendment B) — the raw capture #5 puts a lifecycle on, the `encrypted`
  floor #5 preserves and extends, and the `APP_PREVIOUS_KEYS` guard #5 widens to three columns.
  **Two positions superseded by ADR-014**; everything else stands and is relied on here.
- **ADR-011** (Accepted) — FIFO claim state GC reads and must not disturb; dispatch-by-reference is
  why AC8 matters at all. **Unchanged by #5, and `AdvanceProxyFifoQueue` is not modified.**
- **ADR-008** (Accepted) — inbound header forwarding. **Verified undisturbed by header encryption
  (Q-05-04(i)); not amended.**
- **ADR-003** (Accepted) — payload-free attempt records: GC's read-only in-flight signal and the
  records AC9 requires survive erasure.
- **ADR-002** (Accepted) — the mode attribute that gates the output store (AC12, AC14).
- **ADR-009** (Accepted) — the permission model AC16 binds any future read path to.
- PRD-05 (Approved 2026-08-05, amended by Amendment A same date); Q-05-01, Q-05-02 (Owner-resolved),
  Q-05-03 (resolved + amended), Q-05-04 (resolved).
- Features #1, #3, #4 — all Done and merged.

## Implementation Notes
- **The expiry pass is the ONLY writer allowed to mutate a captured row**, and only `body`,
  `headers`, `payload_cleaned_at`. Any other mutation of `webhook_events` remains forbidden by
  ADR-010 P1 as narrowed by ADR-014. Use the **query builder**, not a model `save()`, so `updated_at`
  is untouched.
- **Never guard on `body === null` or `headers === null`. Always on `payload_cleaned_at`.** Every
  consumer, every path, no exceptions. `StoredPayloadLookup` is the only resolver, and the only place
  `dispatched_payloads.body IS NULL` may be interpreted.
- **Re-assert H0–H4 inside the erase `UPDATE`.** The selection query is an optimisation; the
  `WHERE` clause on the mutating statement is the correctness guarantee. Zero rows affected ⇒ skip
  the event and continue the batch.
- **Erase both stores in one transaction**, event first (it is the compare-and-set that decides
  eligibility), then the output. Never in two transactions (AC12).
- **GC never writes `fifo_dispatches` or `delivery_attempts`.** No delete, no status change, no
  reset. It reads both for holds only. If anything ever seems to need a write there, it is a plan
  change, not an implementation choice.
- **Never delete anything.** No hard delete, no soft delete, no truncated/previewed/hashed copy, no
  archive table (AC6).
- **Iterate `Team::withTrashed()`.** A soft-deleted team's payloads still expire; retention keys off
  the captured event and its owning team, never a live session or the ingest request (this is also
  what makes a future V4 offline-capture path inherit retention unchanged).
- **The retention anchor is `webhook_events.created_at`** — durable-custody time, indexed, monotonic,
  and not influenceable by a sender or a future edge buffer. Do not switch to `received_at` without
  an ADR amendment (see ADR-012 Reasoning).
- **`RetentionPolicy` is the only place the window is resolved.** No `config('retention.days')` read
  anywhere else, no hard-coded 30 in a query, no `days(30)` in a test helper that bypasses it — V5
  must be a one-method change (AC3).
- **`content_type` must stay denormalised at capture.** Deriving it from `headers` at read time would
  break after erasure (ADR-014 Decision 6).
- **`headers` is no longer queryable in SQL.** Any future need to filter, match, index, or aggregate
  on header values is a **requirement conversation with the Product Manager** — never a design
  workaround (it would mean reversing AC22a or adding a #10-owned classified projection).
- **`CaptureDispatchedStep` reads `$ctx->payload`, compares it to `$ctx->rawBody`, and never mutates
  the context.** Keep it **before** `DeliverStep` and **after** both the `// #9 NormalizeStep` and
  `// #8 MapStep` seams; do not move it and do not add it outside the `ProxyMode::Enhanced` branch
  (AC12, AC14, AC19). Placing it before the #9 seam would make the divergence test permanently false.
- **`byte_size` is the plaintext length**, computed before the cast, in both stores, and always
  recorded on `dispatched_payloads` even when `body` is NULL.
- **Never log payload content** — not the raw body, not the headers, not the dispatched body, not any
  `encrypted`-cast value, on the GC path, the capture path, the step, or the cleaned-state path. Log
  stable identifiers and counts (coding.md never-log list, binding).
- **Do not add a read path, resource, route, prop, or policy for stored payloads at #5**
  (Q-05-01 Option B). `BelongsToCurrentTeam` on the new model is defence for the future path, not an
  invitation to build one.
- **Schedule cadence is fixed** (daily, off-peak, `withoutOverlapping()`), matching the #4 sweeper's
  posture. Batch size and the H4 horizon are config defaults; **no GC-latency, throughput, or storage
  target is asserted** (AC20).
- **Migrations here require MySQL**, following the #3 precedent (raw `ALTER … LONGBLOB`). Do not
  attempt a portable Blueprint equivalent for the `LONGBLOB`/`MEDIUMTEXT` steps.
- Pint (`composer lint`) + PHPStan L7 (`composer types:check`) green; short Conventional-Commit
  messages with context list items (CLAUDE.md).

## Test strategy
Backend PHPUnit (`./vendor/bin/sail test`), `Http::fake()` for delivery, `Queue::fake()` / the `sync`
driver for dispatch, `travel()`/`Carbon::setTestNow()` for the window. Mapped to acceptance criteria:

**Retention & expiry (AC1–AC4, AC7):**
- An event captured 31 days ago is cleaned; one captured 29 days ago is **not** — its `body`,
  `headers` and `payload_cleaned_at` are byte-for-byte unchanged (AC1, AC2, AC7).
- The window is measured from capture, not from dispatch, delivery, or last access — age a payload
  past the window while its attempt records are recent; it is still cleaned (AC1).
- Two teams, same 30-day window; both cleaned on the same schedule; substituting
  `RetentionPolicy::windowFor` for one team cleans only that team's payloads — proving the window is
  team-keyed, not global (AC3).
- Simple-mode and enhanced-mode proxies' raw payloads are both cleaned (AC4).
- A soft-deleted team's expired payloads are still cleaned (Risk 12).

**Erasure completeness (AC5, AC6, AC9, AC12, AC22b):**
- The scheduled command is registered and runs recurrently (AC5).
- After a pass: `webhook_events.body IS NULL`, `headers IS NULL`, `payload_cleaned_at` set;
  `dispatched_payloads.body IS NULL`; and **no** row anywhere retains any part of the body or the
  header collection — asserted against the raw column values, not the cast attributes (AC6, AC22b).
- Retained descriptors are intact and readable after erasure: `method`, `content_type`, `byte_size`,
  `received_at`, `ingest_id`, `team_id`, `proxy_id`, `created_at` (AC6, AC10).
- `updated_at` on the captured row is **unchanged** by the erase — proving the pass wrote exactly
  three columns via the query builder (ADR-014 Decision 2).
- `delivery_attempts` rows for the cleaned event are **byte-identical before and after** (AC9) and
  remain queryable; the event's `fifo_dispatches` row is **still present and unchanged** (ADR-012
  Decision 5).
- **AC12 atomicity:** both stores are erased in one pass with no observable intermediate; and if the
  output `UPDATE` fails, the event `UPDATE` **rolls back** — the event is not left marked cleaned
  with its output intact.
- **H0 idempotence:** a second run is a no-op and does **not** re-stamp `payload_cleaned_at` (AC5).

**In-flight holds (AC8) — one test per hold:**
- **H2 FIFO:** an expired event whose `fifo_dispatches` row is `pending` is **not** cleaned; same for
  `claimed`; once `settled`, it is.
- **H3 Async:** an expired event with a `dispatched` (non-terminal) attempt is **not** cleaned; once
  every attempt is terminal, it is.
- **H4 horizon:** an expired event with zero attempt rows younger than the horizon is **not** cleaned;
  older than the horizon, it is.
- **Compare-and-set:** if a hold reappears between selection and the erase (e.g. a `fifo_dispatches`
  row flips back to `pending`), the `UPDATE` affects zero rows, the event is skipped, and its payload
  survives the run intact.
- **FIFO liveness under GC:** with a proxy's line mid-advance, a GC pass over that proxy's expired
  events leaves the pending set, the claim, the lease, and the delivery order intact — the line still
  advances in received order (ADR-011 composition).

**Cleaned state and the reader guard (AC10, AC21):**
- `StoredPayloadLookup` returns `Retained` for an uncleaned event, `Cleaned` for a cleaned one, and
  `NeverCaptured` for an unknown `ingest_id` — including the case where `delivery_attempts` rows
  exist but no captured row does (proving the state is read, not inferred).
- `ProcessIngestedWebhook` on a **cleaned** event returns cleanly, throws nothing, and — the
  load-bearing assertion — **`Http::fake()` records no outbound request**: no empty payload is ever
  dispatched (AC10, ADR-014 Decision 7).
- `ProcessIngestedWebhook` on a genuinely **missing** row still throws `ModelNotFoundException`
  (`firstOrFail` retained — an absent row is a bug, not expiry).
- `AdvanceProxyFifoQueue` is **unmodified** and still settles the claimed row and advances the line
  when the entry returns early on a cleaned payload (no stall, no 500).

**Header encryption (AC15, AC22a):**
- The stored `headers` value is **encrypted at rest**: the raw column value is not the plaintext
  JSON, and the model attribute round-trips to the original array (mirrors the existing body test).
- `content_type` is still populated at capture and **survives erasure**, while the header collection
  does not (AC6, ADR-014 Decision 6).
- ADR-008 forwarding is unchanged end to end: the same header set reaches every destination after
  the cast change, with `STRIPPED_HEADERS` still filtered (`IngestEventCaptureTest` and
  the delivery tests keep passing unmodified).

**Dispatched-output store (AC12–AC15, AC19):**
- An **enhanced**-mode proxy produces exactly **one** `dispatched_payloads` row per received event,
  associated to that event (AC12, AC13).
- A **simple**-mode proxy produces **none** (AC12, AC14).
- Multiple destinations for one event still produce exactly one output row (AC13, R3).
- **Divergence gate — identical:** pre-#9, the row exists with `body IS NULL`, `byte_size` equal to
  the raw `byte_size`, and `dispatched_at` set (AC19, ADR-013 Decision 2).
- **Divergence gate — diverged:** with a test-only step that mutates `$ctx->payload`, the body **is**
  stored, is encrypted at rest, and decrypts back to the diverged bytes (AC15).
- The raw `webhook_events` row is **unchanged** by the output write — same attributes, same
  `updated_at` (AC11).
- Re-running the pipeline for the same event (queue redelivery) still yields exactly one row
  (AC13, idempotency).
- **Post-clean guard:** the step does not write for an event whose parent is already cleaned (the
  ruling above).

**Unit:**
- `RetentionPolicy::windowFor` / `cutoffFor` / `expiresAt` arithmetic, including a substituted
  non-default window (the V5 seam).
- The H0–H4 hold predicates in isolation on the query builder.
- `StoredPayloadState` mapping from `payload_cleaned_at` (the three AC21 states).
- `CaptureDispatchedStep` leaves `$ctx->payload` and `$ctx->rawBody` untouched and calls `$next`.
- `PipelineFactory::stepsFor` includes the step for enhanced and excludes it for simple, in the
  correct position relative to `DeliverStep` and the two transform seams.

## Handoff
- **Inputs:** **Approved and amended PRD-05** (incl. § Amendment A, AC21, AC22, D1); resolved
  `docs/questions/prd-05-q-05-01-payload-inspection-surface.md` (Option B — no UI, no Designer gate),
  `docs/questions/prd-05-q-05-02-retention-and-dispatched-output-scope.md` (both defaults confirmed),
  `docs/questions/prd-05-q-05-03-gc-composition-and-output-store.md` (resolved + amended) and
  `docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md` (resolved); ADR-010 (incl.
  Amendment B, two positions superseded), ADR-011, ADR-008, ADR-003, ADR-002, ADR-009, ADR-005 (all
  Accepted); **ADR-012, ADR-013, ADR-014 (all Proposed, this plan)**; plan-03 and plan-04; and the
  current code (`webhook_events` / `fifo_dispatches` / `delivery_attempts` migrations, `WebhookEvent`,
  `WebhookEventCapture`, `IngestController`, `ProcessIngestedWebhook`, `AdvanceProxyFifoQueue`,
  `SweepStalledFifoDispatches`, `PipelineFactory`, `PipelineContext`, `DeliverStep`, `DeliveryUnit`,
  `routes/console.php`).
- **Outputs:** this plan (**revised for Amendment A**); **ADR-012** (revised); **ADR-013** (revised);
  **ADR-014** (new); the annotated **ADR-010**; the answered Q-05-03 (amended) and Q-05-04.
- **Dependencies:** none new; within-stack. All seven flagged items must be Owner-approved before the
  corresponding work starts.
- **Outstanding Questions:** **none.** Q-05-01 and Q-05-02 were resolved by the Owner (2026-08-05);
  **Q-05-03 is answered and amended**; **Q-05-04 is answered — both items feasible as stated, and no
  requirement returns to the Product Manager.** Roadmap **V4, V5, V6** remain settled by PRD-05 and
  are not reopened; this plan records the concrete extension points each was promised (V4 — retention
  anchored on the captured event's `created_at` + `team_id`, never the ingest request; V5 —
  `RetentionPolicy::windowFor(Team)` is the single value source; V6 — the same team-keyed seam for a
  region dimension, and no datastore change is warranted). **Deferred concern D1** is accepted as
  out of scope and is **not** designed against here.

### Owner-approval flags (✋) — the complete current list: SEVEN items
The plan's self-certification does **NOT** cover any of these. They grew from four to seven under
Amendment A. Items 3, 5 and 7 are consequences of the same Owner ruling but each carries a distinct
ask, so each is put separately.

1. **ADR-012 — payload retention model and garbage collection (erase-in-place).**
   *Ask:* approve a recurrent, scheduled pass that erases expired payload content **in place** via a
   conditional compare-and-set `UPDATE` (holds H0–H4 re-asserted inside the mutating statement),
   erasing the raw store and the dispatched-output store **in one transaction**, with a team-keyed
   window resolver (`RetentionPolicy::windowFor(Team)`) and **no `teams.retention_days` column**.
   Also approve what the revision **drops**: the `settled`-only `fifo_dispatches` delete, the
   disjoint-index-range argument, and the restrict FK as a GC safety net — GC now writes nothing
   ADR-011 reads or writes.

2. **ADR-013 — the dispatched-output store, with the Owner's Option B.**
   *Ask:* approve `dispatched_payloads` + `CaptureDispatchedStep`, with `body` **NULL-able and
   written only on divergence** (`$ctx->payload !== $ctx->rawBody`); NULL means "output == input",
   disambiguated **only** by the parent's `payload_cleaned_at`. Two consequences to ratify
   explicitly: **(a)** after erasure, whether the output had diverged is **not recoverable** (no
   `diverged` flag is added); **(b)** the *Post-clean dispatched-output write* ruling in this plan —
   fold it into ADR-013 Decision 4, or rule it an implementation detail of the plan.

3. **ADR-014 — captured-entity changes, and the partial supersession of an Accepted ADR.**
   *Ask:* approve ADR-014 as the superseding instrument that **reverses two Owner-ratified positions
   of ADR-010** — **P1** "never mutate a captured row here" (**narrowed**: the expiry pass may write
   `body`, `headers`, `payload_cleaned_at`, and nothing else, and only after the window elapses) and
   **P2** "inbound headers remain plaintext at rest until #10" (**reversed**). ADR-010 keeps its file,
   its Accepted status and its full text; nothing ratified is deleted or reworded.

4. **Data-model change — the new table `dispatched_payloads`.**
   *Ask:* approve the exact shape in *Data Model* above, verbatim: `id`, `team_id` (restrict),
   `proxy_id` (restrict), `webhook_event_id` (`cascadeOnDelete`, **UNIQUE**),
   **`body LONGBLOB NULL`** with cast `'body' => 'encrypted'`, `byte_size` (plaintext, always
   recorded), `dispatched_at`, timestamps; indexes `UNIQUE(webhook_event_id)` and
   `(team_id, created_at)`. No soft delete, no headers, no method, no retention state of its own, no
   backfill.

5. **Data-model change — schema change to the EXISTING `webhook_events` table** (new since
   Amendment A; the prior plan asserted "no change to any existing table or index").
   *Ask:* approve, verbatim: **(a)** `body` `LONGBLOB NOT NULL` → **`LONGBLOB NULL`**;
   **(b)** `headers` `json NOT NULL` → **`MEDIUMTEXT NULL`** with cast **`'encrypted:array'`**,
   applied by **dropping and re-adding the column** (existing rows hold plaintext JSON the new cast
   cannot decrypt) — **destructive to captured headers in any existing local/CI database**, and with
   a `down()` that does not round-trip; **(c)** new column **`payload_cleaned_at TIMESTAMP NULL`**
   after `byte_size`; **(d)** new index **`(team_id, payload_cleaned_at, created_at)`**. No backfill;
   MySQL-specific, per the #3 precedent. This rests on the Owner's own statement that there is no
   production data to protect.

6. **Irreversible operation — scheduled, permanent, unrecoverable erasure of customer payload
   content.**
   *Ask:* approve that a daily scheduled pass **permanently erases** raw bodies, captured inbound
   headers, and dispatched output bodies once 30 days elapse — **enabled by default for all teams**,
   with no opt-in, no soft delete, no archive, and **no recovery path** (AC5, AC6, AC22b).
   Erase-in-place is **not less destructive** than the delete it replaces: the content is
   unrecoverable either way; only a descriptor-only record survives, marked cleaned. At deploy the
   first pass erases nothing (oldest captures date from #3, 2026-08-04); it becomes destructive from
   **~2026-09-03**.

7. **Security-sensitive — at-rest encryption coverage extended to captured headers.**
   *Ask:* approve that captured inbound headers are **encrypted at rest from #5** (AC22a), reversing
   the position the Owner explicitly accepted in ADR-010 Amendment B, and acknowledge the two
   consequences: **(a)** ADR-010 Amendment B's **binding `APP_PREVIOUS_KEYS` guard now spans three
   columns across two tables** (`webhook_events.body`, `webhook_events.headers`,
   `dispatched_payloads.body`) — a prior key may not be dropped until the future re-encryption job has
   rehashed every row in all three; **(b)** losing a key now breaks **header forwarding** as well as
   the body (no new failure *class*, wider scope). The re-encryption command remains ADR-010's
   accepted FUTURE task and is **not built at #5**. #10 keeps its full scope: only at-rest protection
   and expiry move; all header **policy** stays at #10.

**Not tripped:** no new Composer/pnpm dependency, no stack change, no backfill, no change to
`teams`, `proxies`, `fifo_dispatches`, or `delivery_attempts`. **V6/Postgres is not reopened.**

### Re-certification (Principal Engineer, 2026-08-05)
I have re-read this plan against **PRD-05 as amended** (Amendment A, AC5–AC12, AC15, AC21, AC22, D1),
against **ADR-012** and **ADR-013** as revised, against **ADR-014** as written, against the annotated
**ADR-010**, and against the amended **Q-05-03** and resolved **Q-05-04**. Every stale premise of the
deletion-era plan is either revised or explicitly recorded as dropped in § Revision A. Outstanding
Questions are **none**. `docs/stack/stack.md` is unchanged and no new dependency is introduced.

**I self-certify this plan** under the delegated plan gate in `CLAUDE.md` — **except** the **seven
Owner-approval flags above, which self-certification does not and cannot cover**: three ADRs, two
data-model changes (one of them to an existing table), one irreversible destructive operation, and
one security-coverage extension that reverses two Owner-accepted positions. No work depending on a
flagged item may start before the Owner rules on that item. Nothing in this plan changes a
requirement, reinterprets the PRD, or reopens V4, V5, V6, Q-05-01, Q-05-02, the 30-day window, or D1.

- **Next Agent:** **Task Planner** — for the non-flagged work immediately, and for the flagged work
  **after** Owner approval of items 1–7. **No Designer round-trip and no Designer gate:** PRD-05 has
  no UX Direction section and #5 adds no UI (Q-05-01 Option B, Owner 2026-08-05); Amendment A
  explicitly leaves that routing unchanged.
