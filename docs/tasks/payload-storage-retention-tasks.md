# Task Plan: Payload storage & retention — item #5

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-05-payload-storage-retention.md` (Approved — Principal
  Engineer self-certified, re-certified 2026-08-05 against Amendment A; all seven Owner-approval
  flags ratified — ADR-012, ADR-013, ADR-014 all Accepted, Owner 2026-08-05)
- **PRD:** `docs/product/prd-05-payload-storage-retention.md` (Approved — Owner, 2026-08-05;
  amended by Amendment A, same date; AC1–AC22, D1) · **Design:** none — no UX Direction section, no
  UI, no Designer gate (Q-05-01 RESOLVED Option B) · **ADRs:** ADR-012, ADR-013, ADR-014 (all
  Accepted, Owner 2026-08-05)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against the plan/PRD-05/ADR-012–014 at review time)

> **Scope / conventions.** Every task traces to plan-05 and PRD-05's amended ACs (AC1–AC22) or a
> named plan/ADR decision. Sequencing follows the plan's own layering: **config + small
> primitives** (T1–T3) → **data model** (T4–T5, both Owner-approved shapes) → **services built on
> the new columns** (T6) → **the dispatched-output step, split so its post-clean guard is
> separately visible per plan Risk 4** (T7–T8) → **wiring the step into the live pipeline** (T9) →
> **the entry guard on the existing pipeline-entry action** (T10) → **the garbage collector and its
> scheduling** (T11–T12) → **acceptance-test suites, one per named requirement group, proving
> composition rather than duplicating the unit tests above** (T13–T18). Migrations and models
> precede the code that reads their new columns; the dispatched-output step is built and unit-proven
> (T7–T8) before `PipelineFactory` is wired to run it live (T9), so no event is ever exposed to a
> half-built step.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green, and
> `./vendor/bin/sail test` green with its own tests included (CLAUDE.md, `docs/standards/planning.md`).
>
> **No new dependency, no stack change.** Uses only Eloquent, migrations, the Laravel scheduler
> (already required by #4's FIFO sweeper), the native `Illuminate\Pipeline\Pipeline`, and
> `lorisleiva/laravel-actions` `AsAction`/`AsObject`/`AsCommand` (already adopted, ADR-007).
>
> **Scope discipline (plan §Overview / PRD Out of Scope, Amendment A, D1) — do NOT build in this
> feature:** any UI, read path, resource, route, prop, or policy for stored payload content
> (Q-05-01 Option B) — `BelongsToCurrentTeam` on `DispatchedPayload` is defence for a future path,
> not an invitation to build one at #5; any cap, prune, archival, roll-up, partitioning, or numeric
> target on retained **records** (D1 — out of scope; `fifo_dispatches` rows are never pruned by
> this feature and that is intentional, not a gap); any change to `AdvanceProxyFifoQueue` (it stays
> **unmodified** — T16 proves this by test, not by code change); any change to
> `ProcessIngestedWebhook` beyond the single guard in T10 (`firstOrFail()` stays); any per-team,
> per-plan, or user-facing retention control (V5); any region/residency dimension or datastore change
> (V6, not reopened); any retry/replay/dead-letter, mode-toggle UI, or mapping (#6/#7/#8); any
> header **policy** — redaction, classification, verification tokens (#10 — #5 moves only at-rest
> encryption and expiry-clearing of headers, per AC22).
>
> **Load-bearing invariant carried through every task below (ADR-014 Decision 7, binding).** Guard
> on `payload_cleaned_at`, **never** on `body === null` or `headers === null`. Every task that reads
> a captured or dispatched row for any purpose other than the erasure pass itself must respect this.
> `StoredPayloadLookup` (T6) is the only resolver.
>
> **Migration note carried through T4.** The `webhook_events.headers` schema change is a
> **drop-and-re-add**, not a `MODIFY`, and is **destructive to existing captured headers in any
> local/CI database that already has rows**; `down()` is best-effort and does not round-trip once
> any row has been cleaned. This rests on the Owner's stated basis that there is no production data
> to protect (ADR-014 §Decision 2 reasoning) — it is not an oversight, and T4 states it explicitly so
> no implementer is surprised mid-task.

---

## T1 — `config/retention.php` (AC2, AC3; ADR-012 Decision 2)

- **Description:** New config file mirroring `config/ingest.php`'s inline-doc + env-override
  pattern: `days` (env `RETENTION_DAYS`, default **30**, AC2), `purge_batch` (env
  `RETENTION_PURGE_BATCH`, default a documented positive integer, e.g. 500 — the GC's per-team
  per-run `LIMIT`), `dispatch_horizon_minutes` (env `RETENTION_DISPATCH_HORIZON_MINUTES`, default
  60 — hold H4's pre-dispatch horizon). Env-overridable for dev/test convenience only — **not** a
  per-team or user-facing lever (AC3); 30 days is the fixed product value, not a per-deployment
  tunable, and `RetentionPolicy` (T3) is the only consumer that may read `retention.days`.
- **Dependencies:** none
- **Files:** `config/retention.php` (new), `.env.example`
- **Acceptance Criteria:** `config('retention.days')` returns `30` by default and the env override
  when set; `config('retention.purge_batch')` and `config('retention.dispatch_horizon_minutes')`
  likewise default + override; all three documented inline; commented keys added to `.env.example`
  matching the existing `config/ingest.php` placeholder pattern.
- **Testing:** `tests/Unit/Config/RetentionConfigTest.php` (new) — default + env-override cases for
  all three keys, mirroring `IngestConfigTest`.
- **Completion notes:** Done. `config/retention.php` added with `days` (env `RETENTION_DAYS`,
  default 30), `purge_batch` (env `RETENTION_PURGE_BATCH`, default 500), `dispatch_horizon_minutes`
  (env `RETENTION_DISPATCH_HORIZON_MINUTES`, default 60), inline-doc'd mirroring
  `config/ingest.php`. Commented placeholder lines added to `.env.example` alongside the existing
  `INGEST_*` block. `tests/Unit/Config/RetentionConfigTest.php` covers default + env-override for
  all three keys (mirrors `IngestConfigTest`). Verified: `composer lint`, `composer types:check`,
  `./vendor/bin/sail test --filter RetentionConfigTest` green.

## T2 — `StoredPayloadState` enum (AC21; ADR-014 Decision 4)

- **Description:** `App\Enums\StoredPayloadState` — backed string enum naming AC21's three states
  once: `Retained = 'retained'`, `Cleaned = 'cleaned'`, `NeverCaptured = 'never_captured'`. No
  mapping logic here — that is `StoredPayloadLookup` (T6).
- **Dependencies:** none
- **Files:** `app/Enums/StoredPayloadState.php` (new)
- **Acceptance Criteria:** the enum exposes exactly three cases and no other; case names and
  backing values match AC21(a)/(b)/(c).
- **Testing:** extend `tests/Unit/Enums/DomainEnumsTest.php` with an exact case-set assertion,
  mirroring the existing `ProcessingMode`/`FifoDispatchStatus` cases.
- **Completion notes:** Done. `App\Enums\StoredPayloadState` added with exactly `Retained`,
  `Cleaned`, `NeverCaptured` (backing values `retained`/`cleaned`/`never_captured`), no mapping
  logic (that is T6). `DomainEnumsTest` extended with an exact case-set assertion mirroring the
  existing enum tests. Verified: `composer lint`, `composer types:check`,
  `./vendor/bin/sail test --filter DomainEnumsTest` green.

## T3 — `RetentionPolicy` service (AC1–AC3; ADR-012 Decision 2)

- **Description:** `App\Services\RetentionPolicy` — the single source of the retention window:
  `windowFor(Team $team): CarbonInterval` (today `CarbonInterval::days(config('retention.days'))`
  for every team — the `Team` parameter is the V5/V6 extension point per Q-05-02(a); the method
  body is the only thing a later tier/region lever changes); `cutoffFor(Team $team):
  CarbonImmutable` (`now()->sub($this->windowFor($team))`); `expiresAt(WebhookEvent $event):
  CarbonImmutable` (`$event->created_at->add($this->windowFor($event->team))` — nothing persists
  this value). No other place in the codebase may read `config('retention.days')` directly or
  hard-code a day count (AC3).
- **Dependencies:** T1
- **Files:** `app/Services/RetentionPolicy.php` (new)
- **Acceptance Criteria:** `windowFor` returns the configured default for every team;
  `cutoffFor($team) === now()->sub(windowFor($team))`; `expiresAt($event) ===
  $event->created_at->add(windowFor($event->team))`; a test double overriding `windowFor` for one
  team changes only that team's `cutoffFor`/`expiresAt` outcome, proving the resolver is the single
  seam these two methods compose through (the V5 seam).
- **Testing:** `tests/Unit/Services/RetentionPolicyTest.php` (new) — arithmetic for all three
  methods against the default window, plus a substituted non-default window via a test subclass or
  partial mock, proving `cutoffFor`/`expiresAt` derive from `windowFor` rather than duplicating the
  config read.
- **Completion notes:** Done. `App\Services\RetentionPolicy` added with `windowFor(Team):
  CarbonInterval`, `cutoffFor(Team): CarbonImmutable`, `expiresAt(WebhookEvent): CarbonImmutable` —
  the latter two compose through `windowFor` rather than re-reading config. `WebhookEvent` carries
  no `team()` relation (raw-only, ADR-010), so `expiresAt` resolves the owning team by id via
  `Team::withTrashed()->findOrFail()` rather than adding a relation outside this task's file list.
  Test uses an anonymous subclass overriding `windowFor` for one team id to prove
  `cutoffFor`/`expiresAt` derive from the single seam. Note: `Carbon\CarbonInterval` /
  `Carbon\CarbonImmutable` are the correct import paths (not `Illuminate\Support\*`, which do not
  exist for these two classes). Verified: `composer lint`, `composer types:check`,
  `./vendor/bin/sail test --filter RetentionPolicyTest` green.

## T4 — `webhook_events` schema change for erase-in-place + header encryption (AC6, AC11, AC15, AC21, AC22; ADR-014 Decisions 2–7) — destructive migration, Owner-approved shape

- **Description:** New migration `alter_webhook_events_for_payload_erasure`, MySQL-specific (raw
  `ALTER`, following the #3 `create_webhook_events_table` precedent — no portable Blueprint
  equivalent exists for these two column types):
  - **(a)** `body`: `LONGBLOB NOT NULL` → `LONGBLOB NULL` via raw `ALTER … MODIFY` (value-preserving).
  - **(b)** `headers`: **drop and re-add** (not `MODIFY`) as `MEDIUMTEXT NULL`, `AFTER method`
    (preserving original column order) — existing rows hold plaintext `json` the new
    `'encrypted:array'` cast cannot decrypt, and MySQL validates `json` on write against a value
    that is not valid JSON once encrypted (error 3140), so the type change is mandatory. **This step
    discards every existing captured header value in any local/CI database that already has rows.**
    Acceptable only on the Owner's stated basis that there is no production data to protect
    (ADR-014 §Decision 2 reasoning) — state this explicitly in the migration docblock.
  - **(c)** add `payload_cleaned_at TIMESTAMP NULL`, `AFTER byte_size`.
  - **(d)** add index `(team_id, payload_cleaned_at, created_at)`.
  - `down()` is **best-effort only and does not round-trip**: it re-adds `headers` as `json NOT
    NULL` (empty on rows that held header data) and drops `payload_cleaned_at`/`body NOT NULL` will
    fail against any row already erased (a NULL `body` cannot become `NOT NULL`). Document this in
    the migration docblock rather than pretending it round-trips.
  - `WebhookEvent` model: casts become `'body' => 'encrypted'` (unchanged), `'headers' =>
    'encrypted:array'` (was `'array'`), add `'payload_cleaned_at' => 'datetime'`. Do **not** add
    `payload_cleaned_at` to `#[Fillable]` — the expiry pass (T11) writes it through the query
    builder only, never mass assignment. Update the docblock: remove "`headers` stay plaintext
    until #10", point at ADR-014 instead; change `@property string $body` /
    `@property array<string, mixed> $headers` to nullable; add
    `@property Carbon|null $payload_cleaned_at`.
  - Add a `cleaned()` factory state to `database/factories/WebhookEventFactory.php`
    (`payload_cleaned_at => now(), body => null, headers => null`) — test-support convenience
    every later task in this plan needs to construct a cleaned event without running the GC.
- **Dependencies:** none
- **Files:** `database/migrations/*_alter_webhook_events_for_payload_erasure.php` (new),
  `app/Models/WebhookEvent.php`, `database/factories/WebhookEventFactory.php`
- **Acceptance Criteria:** migration applies cleanly on a fresh database (`up`/`down` both
  exercised; `down` is verified only against a database with no cleaned rows, matching the
  documented no-round-trip caveat); `body`/`headers` are nullable at the schema level;
  `headers`'s `information_schema` `DATA_TYPE` is `mediumtext`; `payload_cleaned_at` exists,
  nullable, `TIMESTAMP`; the composite index `(team_id, payload_cleaned_at, created_at)` exists; a
  `WebhookEvent`'s `headers` value round-trips through the model attribute to the original array
  and the raw stored column value is not the plaintext JSON (encrypted at rest); `content_type`
  capture and read is unaffected — existing `WebhookEventCaptureTest`,
  `WebhookEventCaptureAcceptanceTest`, and `ProcessIngestedWebhookTest` stay green unmodified,
  proving the cast change is transparent to every existing consumer (Q-05-04(i)).
- **Testing:** extend `tests/Unit/Models/WebhookEventTest.php` — schema assertions for the two
  nullable columns, the `mediumtext` type, the new column + index; a new
  `test_headers_round_trip_through_the_encrypted_cast` mirroring the existing
  `test_body_round_trips_through_the_encrypted_cast` (raw column value ≠ plaintext; model attribute
  round-trips); confirm the existing `test_headers_round_trip_as_an_array` and
  `test_table_is_raw_only_with_no_soft_delete_or_dispatched_output_columns` still pass unmodified.
  Run the full existing capture/ingest suites to confirm green (no new test needed there).
- **Completion notes:** Done. New migration
  `2026_08_05_000001_alter_webhook_events_for_payload_erasure.php`: `body` `MODIFY`'d to `LONGBLOB
  NULL` (value-preserving raw `ALTER`); `headers` dropped and re-added as `MEDIUMTEXT NULL AFTER
  method` (destructive to existing captured headers, documented in the docblock per the Owner's
  no-production-data basis); `payload_cleaned_at TIMESTAMP NULL AFTER byte_size` added; composite
  index `(team_id, payload_cleaned_at, created_at)` added. `down()` is best-effort only (documented
  as not round-tripping) — verified by an explicit `migrate` → `migrate:rollback --step=1` →
  `migrate` cycle against a fresh (empty) database, which completed cleanly. `WebhookEvent` casts
  updated to `'headers' => 'encrypted:array'` and `'payload_cleaned_at' => 'datetime'` (not added to
  `#[Fillable]`); docblock updated to point at ADR-014 and mark `body`/`headers` nullable.
  `WebhookEventFactory::cleaned()` state added (`payload_cleaned_at => now(), body => null, headers
  => null`). `WebhookEventTest` extended with schema assertions (nullable body/headers, `mediumtext`
  DATA_TYPE, `payload_cleaned_at` presence/type, the new composite index) and a new
  `test_headers_round_trip_through_the_encrypted_cast`; the pre-existing
  `test_headers_round_trip_as_an_array` and `test_table_is_raw_only_with_no_soft_delete_or_dispatched_output_columns`
  pass unmodified. `WebhookEventCaptureTest`, `WebhookEventCaptureAcceptanceTest`, and
  `ProcessIngestedWebhookTest` all pass unmodified, confirming the cast change is transparent
  (Q-05-04(i)). Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test
  --parallel` (370 tests, all green).

## T5 — `dispatched_payloads` table + `DispatchedPayload` model + factory (AC12, AC13, AC15; ADR-013 Decision 1) — new table, Owner-approved shape

- **Description:** New migration `create_dispatched_payloads_table`, the exact shape ADR-013/plan-05
  §Data Model approve, verbatim: `id` bigint PK; `team_id` `foreignId()->constrained()` (restrict);
  `proxy_id` `foreignId()->constrained()` (restrict); `webhook_event_id`
  `foreignId()->constrained()->cascadeOnDelete()`, **UNIQUE** (orphan prevention for a future delete
  path only — not an AC6/AC12 mechanism at #5); `body` — raw `ALTER … LONGBLOB NULL` (same
  treatment as `webhook_events.body`; Blueprint's `binary()` maps to a too-small 64 KiB `BLOB`);
  `byte_size` `unsignedInteger`; `dispatched_at` `timestamp`; `timestamps()`. Indexes:
  `UNIQUE(webhook_event_id)`, `(team_id, created_at)`. No `headers`, no `method`, no retention/GC
  column of its own, no soft delete, no backfill (existing #3/#4 events simply have no output row).
  `DispatchedPayload` model: `BelongsToCurrentTeam`, `belongsTo(WebhookEvent)`, `belongsTo(Proxy)`;
  casts `'body' => 'encrypted'`, `'byte_size' => 'integer'`, `'dispatched_at' => 'datetime'`;
  `#[Fillable(['team_id', 'proxy_id', 'webhook_event_id', 'body', 'byte_size', 'dispatched_at'])]`.
  Factory anchors on a `WebhookEvent` and derives `team_id`/`proxy_id` from it, mirroring
  `WebhookEventFactory`'s team-unscoped pattern.
- **Dependencies:** none
- **Files:** `database/migrations/*_create_dispatched_payloads_table.php` (new),
  `app/Models/DispatchedPayload.php` (new), `database/factories/DispatchedPayloadFactory.php` (new)
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` exercised); `webhook_event_id`
  carries a single-column `UNIQUE` index (a second row for the same event is rejected at the DB
  level); `body`'s `DATA_TYPE` is `longblob` and nullable; `byte_size`/`dispatched_at` present with
  correct types; the `(team_id, created_at)` composite index exists; no `headers`/`method`/soft-delete
  column exists; `body` round-trips through the `encrypted` cast when set and reads back genuinely
  `NULL` (not an empty string) at both the raw-column and cast level when unset; the three
  `belongsTo` relations resolve to the correct records.
- **Testing:** `tests/Unit/Models/DispatchedPayloadTest.php` (new) — the schema assertions above,
  the encrypted-cast round-trip test mirroring `WebhookEventTest::test_body_round_trips_through_the_encrypted_cast`,
  the NULL-body case, and the relation-resolution cases.
- **Completion notes:** Done. New migration `2026_08_05_000002_create_dispatched_payloads_table.php`
  creates `dispatched_payloads`: `team_id`/`proxy_id` restrict `constrained()`; `webhook_event_id`
  `constrained()->cascadeOnDelete()` plus a separate single-column `unique()` (the
  `constrained()->unique()` chain doesn't exist on `ForeignKeyDefinition`); `body` added via raw
  `ALTER … LONGBLOB NULL` (same treatment as `webhook_events.body` — Blueprint's `binary()` maps to
  a too-small 64 KiB `BLOB`); `byte_size unsignedInteger`, `dispatched_at timestamp`, `timestamps()`;
  indexes `UNIQUE(webhook_event_id)` and `(team_id, created_at)`. No `headers`/`method`/soft-delete
  column. `App\Models\DispatchedPayload` added with `BelongsToCurrentTeam`, `belongsTo(WebhookEvent)`
  as `webhookEvent()`, `belongsTo(Proxy)`, casts `body => encrypted`, `byte_size => integer`,
  `dispatched_at => datetime`, `#[Fillable(...)]` per the plan. `DispatchedPayloadFactory` anchors on
  a `WebhookEvent::factory()` and derives `team_id`/`proxy_id` from it via
  `WebhookEvent::withoutGlobalScope(TeamScope::class)`, mirroring `WebhookEventFactory`'s pattern;
  default state has `body => null` (the identical-payload case) and a random `byte_size`.
  `tests/Unit/Models/DispatchedPayloadTest.php` covers the schema assertions (LONGBLOB nullable body,
  byte_size/dispatched_at types, the single-column UNIQUE on `webhook_event_id`, the
  `(team_id, created_at)` index, absence of `headers`/`method`/`deleted_at`), the encrypted-cast
  round-trip, the genuinely-NULL-when-unset case (raw column and cast level), and relation
  resolution. Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test --parallel`
  (378 tests, all green).

## T6 — `StoredPayloadLookup` service (AC10, AC21; ADR-012 Decision 3, ADR-013 Decision 3, ADR-014 Decision 4)

- **Description:** `App\Services\StoredPayloadLookup::for(string $ingestId): StoredPayloadState`
  (T2) — reads `webhook_events.payload_cleaned_at` for the given `ingest_id`: no row ⇒
  `NeverCaptured`; row with `payload_cleaned_at === null` ⇒ `Retained`; row with
  `payload_cleaned_at !== null` ⇒ `Cleaned`. **Never** infers "cleaned" from `body IS NULL`, a
  failed lookup, or the presence of `delivery_attempts` rows. The class docblock records that it is
  also the **only** place `dispatched_payloads.body IS NULL` may ever be interpreted (ADR-013
  Decision 3), even though nothing consumes that interpretation at #5.
- **Dependencies:** T2, T4
- **Files:** `app/Services/StoredPayloadLookup.php` (new)
- **Acceptance Criteria:** `for()` returns `Retained` for an event with `payload_cleaned_at` NULL;
  `Cleaned` for one with it set; `NeverCaptured` for an unknown `ingest_id`, including the case
  where a `delivery_attempts` row exists for that `ingest_id` but no `webhook_events` row does
  (proving the state is read from the captured row, never inferred from delivery history).
- **Testing:** `tests/Unit/Services/StoredPayloadLookupTest.php` (new) — the three cases above,
  including the delivery-attempts-without-a-captured-row case.
- **Completion notes:** Done. `App\Services\StoredPayloadLookup::for(string $ingestId):
  StoredPayloadState` added — reads only `webhook_events.payload_cleaned_at` via
  `WebhookEvent::query()->where('ingest_id', ...)->first()`, mapping no row to `NeverCaptured`, a
  `null` `payload_cleaned_at` to `Retained`, and a set one to `Cleaned`. Docblock records it as the
  only place `dispatched_payloads.body IS NULL` may ever be interpreted (ADR-013 Decision 3), per
  the task description. `tests/Unit/Services/StoredPayloadLookupTest.php` covers all four cases,
  including a `DeliveryAttempt` row existing for an `ingest_id` with no `webhook_events` row,
  proving the state is read from the captured row and never inferred from delivery history.
  Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test --filter
  StoredPayloadLookupTest` green (4 tests).

## T7 — `CaptureDispatchedStep`: divergence-gated dispatched-output write (AC12, AC13, AC19; ADR-013 Decisions 1, 2, 4, 5)

- **Description:** `App\Actions\CaptureDispatchedStep`, `AsObject`, implements
  `App\Pipeline\PipelineStep`. `handle(PipelineContext $ctx, Closure $next)`: resolve the
  `WebhookEvent` by the UNIQUE-indexed `$ctx->ingestId`; compute `$diverged = $ctx->payload !==
  $ctx->rawBody` (both already on `PipelineContext`, no new plumbing); `DispatchedPayload::
  updateOrCreate(['webhook_event_id' => $event->id], ['team_id' => $ctx->proxy->team_id, 'proxy_id'
  => $ctx->proxy->id, 'body' => $diverged ? $ctx->payload : null, 'byte_size' =>
  strlen($ctx->payload), 'dispatched_at' => now()])`; call `$next($ctx)` unchanged. **Never mutates
  `$ctx->payload` or `$ctx->rawBody`.** The post-clean write guard (per plan Risk 4) is deliberately
  **out of scope here** — it is T8, its own task with its own test.
- **Dependencies:** T5
- **Files:** `app/Actions/CaptureDispatchedStep.php` (new)
- **Acceptance Criteria:** for an event whose `$ctx->payload` equals `$ctx->rawBody` (the pre-#9
  default), the row is created with `body === null` and `byte_size` equal to the raw byte size; for
  a test-only step that mutates `$ctx->payload` ahead of this step, `body` is stored, encrypted at
  rest, and decrypts back to the diverged bytes, with `byte_size` reflecting the **dispatched**
  (post-divergence) length; re-invoking the step for the same event (simulated queue redelivery)
  still yields exactly one row, updated not duplicated; `$ctx->payload`/`$ctx->rawBody` are
  byte-identical before and after; `$next` is invoked with the same context.
- **Testing:** `tests/Unit/Actions/CaptureDispatchedStepTest.php` (new) — identical case (`body`
  NULL), diverged case (stored + encrypted + decrypts), idempotent re-run (one row, updated),
  context-untouched assertion, `$next`-invocation assertion.
- **Completion notes:** Done. `App\Actions\CaptureDispatchedStep` added (`AsObject`, implements
  `PipelineStep`): resolves the `WebhookEvent` by `$ctx->ingestId` via `firstOrFail()`, computes
  `$diverged = $ctx->payload !== $ctx->rawBody`, and `updateOrCreate`s the
  `dispatched_payloads` row keyed on `webhook_event_id`, storing `body` only when diverged and
  `byte_size = strlen($ctx->payload)` (the dispatched length). Calls `$next($ctx)` unchanged; never
  mutates `$ctx->payload`/`$ctx->rawBody`. The post-clean write guard is explicitly out of scope
  here (T8). `tests/Unit/Actions/CaptureDispatchedStepTest.php` covers the identical case (`body`
  NULL, raw byte size), the diverged case (stored, encrypted at rest, decrypts to the diverged
  bytes, dispatched byte size), idempotent re-run (one row, updated not duplicated), the
  context-untouched assertion, and the `$next`-invocation assertion. Verified: `composer lint`,
  `composer types:check`, `./vendor/bin/sail test --filter CaptureDispatchedStepTest` green
  (5 tests).

## T8 — `CaptureDispatchedStep`: post-clean write guard (plan §Architecture "Post-clean dispatched-output write" ruling; Risk 4)

- **Description:** Dedicated task for the hazard the plan names but does not fold into a finished
  ADR: under erase-in-place the parent `webhook_events` row survives its own erasure, so an
  unconditioned write here could create/update a `dispatched_payloads` row for an event already
  marked cleaned (a write that would have failed loudly on the FK under the old delete design).
  **Ruling applied:** the write is conditioned on the parent's `payload_cleaned_at IS NULL`,
  evaluated in the same statement/transaction as the write — a compare-and-set on the parent, not a
  separate read-then-write. If the parent is already cleaned, the step logs `payload.expired`
  (identifiers only — never payload content, per `docs/standards/coding.md`'s never-log list) and
  returns **before** calling `$next`, mirroring the `ProcessIngestedWebhook` entry guard (T10) so
  `DeliverStep` never runs for a cleaned event.
- **Dependencies:** T7, T4
- **Files:** `app/Actions/CaptureDispatchedStep.php`
- **Acceptance Criteria:** for an event whose parent `payload_cleaned_at` is already set, the step
  does **not** create or update a `dispatched_payloads` row, does **not** call `$next` (so nothing
  downstream runs), and logs `payload.expired` with identifiers only; for an uncleaned parent,
  behaviour is unchanged from T7.
- **Testing:** extend `tests/Unit/Actions/CaptureDispatchedStepTest.php` — a case with
  `payload_cleaned_at` pre-set on the parent (use T4's `cleaned()` factory state) asserts no
  `dispatched_payloads` row is created/updated and `$next` is never invoked (e.g. via a mutable flag
  on a downstream test double); assert the log entry carries no payload bytes.
- **Completion notes:** Done. `CaptureDispatchedStep::handle()` now wraps the parent lookup and the
  `dispatched_payloads` write in one `DB::transaction()`: the parent `WebhookEvent` row is locked
  (`lockForUpdate()`) and its `payload_cleaned_at` re-checked inside that same transaction — a
  compare-and-set on the parent, not a separate read-then-write — closing the race against the GC's
  own compare-and-set `UPDATE` (T11), which takes the same row lock. If already cleaned, the
  transaction does nothing, `Log::info('payload.expired', ['ingest_id' => ...])` fires (identifiers
  only), and the method returns `$ctx` **before** calling `$next` — mirroring the
  `ProcessIngestedWebhook` entry guard (T10) so `DeliverStep` never runs. This is the first
  `Log::` usage in `app/` (no prior precedent; `info` level per `docs/standards/coding.md`'s
  proposed default for a significant domain event). `tests/Unit/Actions/CaptureDispatchedStepTest.php`
  extended with a cleaned-parent case (`Log::spy()`; asserts no `dispatched_payloads` row, `$next`
  never invoked, exactly one `payload.expired` log call with only `ingest_id` in context) and an
  uncleaned-parent case confirming T7 behaviour is unchanged. Verified: `composer lint`, `composer
  types:check`, `./vendor/bin/sail test --filter CaptureDispatchedStepTest` (7 tests) and
  `./vendor/bin/sail test --parallel` (389 tests), all green.

## T9 — Wire `CaptureDispatchedStep` into `PipelineFactory` (AC12, AC14, AC19; ADR-013 Decision 4)

- **Description:** Replace the reserved comment `// $steps[] = CaptureDispatchedStep::make(); //
  #5` at `PipelineFactory.php:32` with the real step, keeping its exact position: inside the
  `ProxyMode::Enhanced` front stage, **after** both still-commented transform seams (`// #9
  NormalizeStep` `:27`, `// #8 MapStep` `:31`), **immediately before** the always-present
  `DeliverStep`. Simple-mode pipelines are structurally unchanged (the `if` branch is not entered)
  and therefore cannot produce a `dispatched_payloads` row (AC12, AC14). Do not move the step and do
  not add it outside the enhanced-mode branch — placing it before the #9 seam would make the
  divergence test permanently false.
- **Dependencies:** T7, T8
- **Files:** `app/Pipeline/PipelineFactory.php`
- **Acceptance Criteria:** `PipelineFactory::stepsFor()` for an enhanced-mode proxy returns
  `[CaptureDispatchedStep, DeliverStep]` in that order; for a simple-mode proxy it returns exactly
  `[DeliverStep]`, unchanged from today.
- **Testing:** extend `tests/Unit/Pipeline/PipelineFactoryTest.php` — enhanced-mode case asserting
  the two-step list and order; simple-mode case asserting the existing single-step list is
  unaffected.
- **Completion notes:** Done. Replaced the reserved comment at `PipelineFactory.php` with
  `$steps[] = CaptureDispatchedStep::make(); // #5`, in place, still inside the `ProxyMode::Enhanced`
  branch, after both still-commented transform seams and immediately before the always-present
  `DeliverStep::make()`. Simple-mode branch untouched. Updated the class docblock (stale after this
  change) to state the enhanced-mode two-step composition instead of the old "`[DeliverStep]` for
  BOTH modes" claim. `PipelineFactoryTest`'s enhanced-mode case updated to assert the two-step list
  `[CaptureDispatchedStep, DeliverStep]` in order; the simple-mode case is unchanged. Verified:
  `composer lint`, `composer types:check`, `./vendor/bin/sail test --parallel` (389 tests, all
  green — confirms the change is transparent to every existing enhanced-mode ingest/delivery test).

## T10 — `ProcessIngestedWebhook`: cleaned-state entry guard (AC10, AC21; ADR-014 Decision 7, plan §Architecture C)

- **Description:** Keep `firstOrFail()` unchanged (`:29`) — an absent row is now genuinely a bug,
  never expiry. Add one guard **before** constructing the `PipelineContext`: if
  `$event->payload_cleaned_at !== null`, log `payload.expired` (identifiers only — `ingest_id`, no
  payload content) and `return` cleanly — nothing is delivered, no pipeline runs. No other change:
  dispatch-by-reference, the trashed-inclusive proxy load, and the pipeline run stay exactly as they
  are. **`AdvanceProxyFifoQueue` receives no code change** — its correctness under this guard is
  proven by test in T16, not built here.
- **Dependencies:** T4
- **Files:** `app/Actions/ProcessIngestedWebhook.php`
- **Acceptance Criteria:** for an event with `payload_cleaned_at` set, `ProcessIngestedWebhook::
  run($ingestId)` returns cleanly, throws nothing, and `Http::fake()` records **no** outbound
  request (the load-bearing assertion — no empty payload is ever dispatched); for a genuinely
  unknown `ingest_id`, `ModelNotFoundException` is still thrown; for an uncleaned event, behaviour
  is unchanged — the three existing `ProcessIngestedWebhookTest` cases stay green unmodified.
- **Testing:** extend `tests/Feature/Ingest/ProcessIngestedWebhookTest.php` — new case: a cleaned
  event (via T4's `cleaned()` factory state) → `run()` returns, zero `DeliveryAttempt` rows,
  `Http::assertNothingSent()`; confirm the existing unknown-`ingest_id` and normal-delivery cases
  still pass.
- **Completion notes:** Done. `ProcessIngestedWebhook::handle()` keeps `firstOrFail()` unchanged and
  adds one guard immediately after it, before constructing the `PipelineContext`: if
  `$event->payload_cleaned_at !== null`, logs `payload.expired` (`ingest_id` only) and returns
  cleanly — no proxy load, no pipeline run. No other change: dispatch-by-reference, the
  trashed-inclusive proxy load, and the pipeline run are untouched. `AdvanceProxyFifoQueue` received
  no code change (its correctness under this guard is T16). `ProcessIngestedWebhookTest` extended
  with a cleaned-event case (`cleaned()` factory state) asserting zero `DeliveryAttempt` rows and
  `Http::assertNothingSent()`; the existing normal-delivery and unknown-`ingest_id` cases pass
  unmodified. Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test --filter
  ProcessIngestedWebhookTest` (4 tests) and `./vendor/bin/sail test --parallel` (390 tests), all
  green.

## T11 — `PurgeExpiredPayloads` action: the garbage collector (AC5, AC6, AC9, AC12, AC22b; ADR-012 Decisions 1, 4, 6, 7)

- **Description:** `App\Actions\PurgeExpiredPayloads`, `AsCommand` (`payloads:purge-expired`, no
  arguments) + `AsAction`. Per run: **(1)** iterate `Team::withTrashed()` in chunks — a
  soft-deleted team's payloads must still expire (plan Risk 12). **(2)** per team, `cutoff =
  RetentionPolicy::cutoffFor($team)` (T3). **(3)** select up to `config('retention.purge_batch')`
  collectable `webhook_events` ids for that team under holds H0–H4, seeking on
  `(team_id, payload_cleaned_at, created_at)` (T4): **H0** `payload_cleaned_at IS NULL`; **H1**
  `created_at <= cutoff`; **H2** no `fifo_dispatches` row for the event with `status <> 'settled'`;
  **H3** no `delivery_attempts` row for the event's `ingest_id` with `status = 'dispatched'`; **H4**
  if the event has zero `delivery_attempts` rows, `created_at <= now() -
  config('retention.dispatch_horizon_minutes')`. **(4)** per id, in one short `DB::transaction()`:
  conditional `UPDATE webhook_events SET body = NULL, headers = NULL, payload_cleaned_at = NOW()
  WHERE id = ? AND <H0–H4 re-asserted>` via the query builder (never a model `save()`, so
  `updated_at` stays untouched); **only if exactly one row was affected**, `UPDATE
  dispatched_payloads SET body = NULL WHERE webhook_event_id = ?`; commit. Zero rows affected on the
  first `UPDATE` ⇒ skip the event (a hold reappeared) and continue the batch — never proceed to the
  second `UPDATE`. **(5)** loop per team until a batch comes back short. Log counts and identifiers
  only — **never** payload content.
- **Dependencies:** T1, T3, T4, T5
- **Files:** `app/Actions/PurgeExpiredPayloads.php` (new)
- **Acceptance Criteria:** invoking the command/action with no collectable rows is a no-op (no row
  touched, no error); a single event past its window with no holds is erased in one pass (`body`/
  `headers` NULL, `payload_cleaned_at` set; `dispatched_payloads.body` NULL if a row existed); an
  event still within its window is untouched byte-for-byte, including `updated_at`; a second run
  over an already-cleaned event is a no-op (H0 idempotence — `payload_cleaned_at` is not
  re-stamped); a soft-deleted team's expired payload is still cleaned.
- **Testing:** `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` (new) — no-op on empty state;
  single-event happy-path erasure (both stores, three columns only, `updated_at` unchanged);
  unexpired event untouched; second-run idempotence; soft-deleted team's payload still cleaned.
  Per-hold behaviour (H1–H4), atomicity-under-failure, and FIFO composition are dedicated
  acceptance-test tasks (T14/T15) — not duplicated here.
- **Completion notes:** Done. `App\Actions\PurgeExpiredPayloads` added (`AsAction`, `commandSignature
  = 'payloads:purge-expired'` — `AsAction` already composes `AsCommand`, so no separate trait is
  used). `handle()` iterates `Team::query()->withTrashed()->chunkById(100, ...)`; per team,
  `RetentionPolicy::cutoffFor($team)` is computed **once** from the `Team` already in hand (never
  per-row via `expiresAt()`'s per-event resolver, per the carry-forward note) alongside a
  `dispatch_horizon_minutes`-derived horizon. A private `applyHolds(Builder, cutoff, horizon)`
  expresses H0-H4 once (`whereNull('payload_cleaned_at')`; `created_at <= cutoff`;
  `whereNotExists` against non-`settled` `fifo_dispatches` rows; `whereNotExists` against
  `dispatched`-status `delivery_attempts` rows; a `whereExists(...)->orWhere('created_at', '<=',
  $horizon)` group for H4) and is applied **identically** to the selection query
  (`DB::table('webhook_events')->where('team_id', ...)`, `orderBy('id')->limit($batchSize)`) and to
  the erase `UPDATE`'s own `WHERE` (`DB::table('webhook_events')->where('id', $id)`) — the
  compare-and-set. Each event is erased in its own `DB::transaction()`: the conditional `UPDATE`
  nulls `body`/`headers` and stamps `payload_cleaned_at` via the query builder (never a model
  `save()`); only if it affected exactly one row does a second `UPDATE` null
  `dispatched_payloads.body` for that `webhook_event_id`, in the same transaction. Zero rows
  affected on the first `UPDATE` skips the event without the second. Per-team batches loop until one
  comes back short of `purge_batch`. Logs `payload.purged` with `team_id`/`count` only (identifiers
  and counts, no payload content). `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` covers: no-op
  on empty/unexpired state; a single expired event with no holds erased in both stores (raw column
  assertions); an unexpired event byte-for-byte untouched including `updated_at`; a second run over
  an already-cleaned event is a no-op (H0 idempotence); a soft-deleted team's expired payload still
  cleaned. Per-hold behaviour, atomicity-under-failure, and FIFO composition are T14/T15, not
  duplicated here. Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test
  --filter PurgeExpiredPayloadsTest` (6 tests, including T12's schedule-registration case written
  ahead as part of the same file) and `./vendor/bin/sail test --parallel` (396 tests), all green.

## T12 — Scheduler wiring: `PurgeExpiredPayloads` in `routes/console.php` (AC5; ADR-012 Decision 7)

- **Description:** One `Schedule::` entry alongside the existing invitation-cleanup and FIFO-sweeper
  entries: daily, off-peak, `withoutOverlapping()`, `->description('Erase expired stored
  payloads')` — matching the #4 sweeper's fixed-cadence posture (not a tunable). Use
  `Schedule::command('payloads:purge-expired')` (T11's `AsCommand` signature) or an equivalent
  `Schedule::call(fn () => PurgeExpiredPayloads::run())` closure, whichever T11's actual
  registration shape calls for.
- **Dependencies:** T11
- **Files:** `routes/console.php`
- **Acceptance Criteria:** the schedule entry is registered on a `daily` cron expression with
  `withoutOverlapping()` applied and a description present.
- **Testing:** extend `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` (or a dedicated schedule
  test alongside it) with a `Schedule::events()` inspection confirming the daily registration and
  `withoutOverlapping`, mirroring `SweepStalledFifoDispatchesTest`'s `everyMinute()` check.
  **Not verifiable by `./vendor/bin/sail test`:** that the deployed environment actually invokes
  `schedule:run` via cron. This is the same operational precondition #4's sweeper already carries
  (plan §Dependencies) — an ops/runbook item, not new to #5 and not a code task here.
- **Completion notes:** Done. `routes/console.php` gains
  `Schedule::command('payloads:purge-expired')->daily()->at('02:00')->withoutOverlapping()
  ->description('Erase expired stored payloads')` — daily, off-peak, matching the #4 sweeper's
  fixed-cadence posture (not a tunable). `Schedule::command(...)` (T11's `AsCommand` signature) was
  chosen over an equivalent `Schedule::call(fn () => PurgeExpiredPayloads::run())` closure since T11
  already exposes a console signature and the command form is the more direct registration for it.
  This required one addition with no prior precedent (`docs/standards/planning.md`/laravel-actions
  gotcha, confirmed in this codebase by `grep`): `Lorisleiva\Actions\Facades\Actions::
  registerCommands();` at the top of `routes/console.php` — without it `Schedule::command(...)`
  cannot resolve the signature to an Artisan command (`AsCommand` classes are not auto-registered).
  Extended `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` (rather than a dedicated file, per the
  task's explicit "or a dedicated schedule test alongside it") with a `Schedule::events()`
  inspection mirroring `SweepStalledFifoDispatchesTest`'s `everyMinute()` check, asserting the
  `0 2 * * *` cron expression and `withoutOverlapping === true`. Verified: `composer lint`,
  `composer types:check`, `./vendor/bin/sail test --filter PurgeExpiredPayloadsTest` (6 tests) and
  `./vendor/bin/sail test --parallel` (396 tests), all green.

---

## T13 — Retention & expiry acceptance tests (AC1–AC4, AC7)

- **Description:** End-to-end proof, over the real `PurgeExpiredPayloads` pass and real
  `RetentionPolicy`, of the retention-window requirements — complementing T11's unit-level happy
  path. No new production code expected; fix any wiring gap here.
- **Dependencies:** T11
- **Files:** `tests/Feature/Retention/RetentionExpiryAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - An event captured 31 days ago is cleaned; one captured 29 days ago is not — its `body`,
    `headers`, `payload_cleaned_at` are byte-for-byte unchanged (AC1, AC2, AC7).
  - The window is measured from `created_at` (capture), not from dispatch/delivery/last access —
    age a payload past the window while its `delivery_attempts` rows are recent; it is still
    cleaned (AC1).
  - Two teams share the same 30-day window and are both cleaned on the same run; a test double
    substituting `RetentionPolicy::windowFor` for one team cleans only that team's payloads,
    proving the window is team-keyed, not global (AC3).
  - Simple-mode and enhanced-mode proxies' raw payloads are both cleaned (AC4).
- **Testing:** the cases above via `travel()`/`Carbon::setTestNow()` to age events,
  `Team::factory()`, `Proxy::factory()->enhanced()`/simple, and a bound test double of
  `RetentionPolicy`.
- **Completion notes:** Done. `tests/Feature/Retention/RetentionExpiryAcceptanceTest.php` added,
  ageing events via an explicit `created_at` (mirrors `RetentionPolicyTest`'s precedent — dirty
  timestamp attributes bypass Eloquent's auto-stamp) rather than `travel()`, since only the
  captured row's age needs to move, not the whole clock. Covers: a 31-day-old event cleaned, a
  29-day-old one byte-for-byte untouched on the same run (AC1, AC2, AC7); a 31-day-old event with a
  recent, terminal `DeliveryAttempt` still cleaned, proving the anchor is `created_at`, never
  delivery activity (AC1); two teams on the default window both cleaned in one run, and a bound
  anonymous `RetentionPolicy` subclass overriding `windowFor` for one team's id (a 1000-day window)
  leaves only that team uncleaned while the other team (default window) is cleaned in the same run
  — proving the window is team-keyed via container-injected `RetentionPolicy`, not global (AC3); a
  simple-mode and an enhanced-mode proxy's raw payloads are both cleaned (AC4). No production-code
  gap found; no new wiring needed. Verified: `composer lint`, `composer types:check`,
  `./vendor/bin/sail test --filter RetentionExpiryAcceptanceTest` (5 tests) and `./vendor/bin/sail
  test --parallel` (401 tests), all green.

## T14 — Erasure completeness & atomicity acceptance tests (AC5, AC6, AC9, AC12, AC22b)

- **Description:** End-to-end proof that a pass erases completely, touches nothing else, and is
  atomic across both stores — complementing T11's unit-level happy path.
- **Dependencies:** T11, T8, T4
- **Files:** `tests/Feature/Retention/RetentionErasureCompletenessAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - The `payloads:purge-expired` command is registered and callable (AC5).
  - After a pass: `webhook_events.body IS NULL`, `headers IS NULL`, `payload_cleaned_at` set;
    `dispatched_payloads.body IS NULL` — asserted against the **raw column values**, not the cast
    attributes (AC6, AC22b).
  - Retained descriptors are intact and readable after erasure: `method`, `content_type`,
    `byte_size`, `received_at`, `ingest_id`, `team_id`, `proxy_id`, `created_at` (AC6, AC10).
  - `updated_at` on the captured row is unchanged by the erase, proving exactly three columns were
    written via the query builder.
  - `delivery_attempts` rows for the cleaned event are byte-identical before/after (AC9) and remain
    queryable; the event's `fifo_dispatches` row is still present and unchanged.
  - **AC12 atomicity:** forcing the `dispatched_payloads` erase to fail (a fault-injection seam —
    e.g. a spy/partial mock intercepting the second `UPDATE`, or a deliberately invalid state) rolls
    back the `webhook_events` erase too — the event is never left marked cleaned with its output
    intact.
  - **H0 idempotence, end to end:** a second scheduled run over already-cleaned rows does not
    re-stamp `payload_cleaned_at` and touches no row.
- **Testing:** the cases above; raw `DB::table(...)->value(...)` assertions for the raw-column
  checks; a test-only fault-injection point for the atomicity case.
- **Completion notes:** Done. `tests/Feature/Retention/RetentionErasureCompletenessAcceptanceTest.php`
  added. **Fault-injection mechanism chosen (the task's open implementation choice):** `DB::listen()`
  registering a closure that throws a `RuntimeException` when it sees a query whose SQL contains
  `` update `dispatched_payloads` `` — `Connection::logQuery()` dispatches the `QueryExecuted` event
  synchronously, after the UPDATE has already executed against the open transaction but before
  `DB::transaction()`'s wrapper returns, so the thrown exception propagates out of `eraseOne()`'s
  closure exactly as a genuine failure would, and Laravel's transaction wrapper rolls back
  everything executed so far in that transaction — including the already-run `webhook_events`
  UPDATE — before rethrowing. No DDL, no mock of the query builder, no change to production code:
  the seam is a real exception surfacing through the real transaction machinery. (Considered and
  rejected: a `CREATE TRIGGER`/schema-altering fault, which would implicitly commit the
  `RefreshDatabase`-managed test transaction in MySQL and leak fixture rows past the test.)
  Covers: the command is registered and callable (`$this->artisan('payloads:purge-expired')
  ->assertExitCode(0)`, AC5); a full pass nulls `body`/`headers` in both stores at the raw-column
  level while every retained descriptor (`method`, `content_type`, `byte_size`, `received_at`,
  `ingest_id`, `team_id`, `proxy_id`, `created_at`) and `updated_at` stay byte-for-byte identical,
  and a sibling `settled` `fifo_dispatches` row and a `succeeded` `delivery_attempts` row are both
  still present and byte-identical afterward (AC6, AC9, AC10, AC22b); the fault-injection case
  proves neither `UPDATE` survives when the second fails (AC12); a second `artisan` run over an
  already-cleaned row is a no-op end to end (H0 idempotence). No production-code gap found.
  Verified: `composer lint`, `composer types:check`, `./vendor/bin/sail test --filter
  RetentionErasureCompletenessAcceptanceTest` (4 tests) and `./vendor/bin/sail test --parallel`
  (405 tests), all green.

## T15 — In-flight holds acceptance tests (AC8) — one test per hold + compare-and-set + FIFO liveness under GC

- **Description:** End-to-end proof of each named hold, of the compare-and-set closing the
  select→act gap, and of GC composing with a live FIFO line without stalling or reordering it —
  complementing T11's unit-level happy path.
- **Dependencies:** T11
- **Files:** `tests/Feature/Retention/RetentionInFlightHoldsAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - **H2 FIFO:** an expired event whose `fifo_dispatches` row is `pending` is not cleaned; same for
    `claimed`; once `settled`, it is.
  - **H3 Async:** an expired event with a `dispatched` (non-terminal) `delivery_attempts` row is not
    cleaned; once every attempt is terminal, it is.
  - **H4 horizon:** an expired event with zero attempt rows younger than
    `config('retention.dispatch_horizon_minutes')` is not cleaned; older than the horizon, it is.
  - **Compare-and-set:** if a hold reappears between selection and erase (e.g. a `fifo_dispatches`
    row flips back to `pending` via a deliberately-inserted timing seam), the erase `UPDATE` affects
    zero rows, the event is skipped, and its payload survives the run intact.
  - **FIFO liveness under GC:** with a proxy's line mid-advance (a live claim), a GC pass over that
    proxy's expired events leaves the pending set, the claim, the lease, and delivery order intact —
    the line still advances in received order afterward (ADR-011 composition).
- **Testing:** the cases above — direct `FifoDispatch`/`DeliveryAttempt` factory states to construct
  each hold, `travel()` to age events, and a targeted test seam to reproduce the reappeared-hold
  race for the compare-and-set case.
- **Completion notes:** Done. `tests/Feature/Retention/RetentionInFlightHoldsAcceptanceTest.php`
  added (5 tests). **H2:** a `pending` then `claimed` `fifo_dispatches` row holds an otherwise-expired
  event across two GC passes; once `settled`, a third pass cleans it. **H3:** a `dispatched`
  (non-terminal) `DeliveryAttempt` holds the event; once resolved to `Succeeded`, the next pass
  cleans it. **H4:** since the default horizon (60 min) is always far shorter than any past-cutoff
  event's age, the test decouples the horizon from the retention window via
  `Config::set('retention.dispatch_horizon_minutes', 35 * 24 * 60)` — an event 32 days old (past H1,
  younger than the 35-day horizon) stays held with zero attempt rows; one 36 days old (past both) is
  cleaned. **Compare-and-set:** `DB::listen()` detects the selection query (`select \`id\` from
  \`webhook_events\``, matched once via a captured guard flag) and, inside the listener, inserts a
  `pending` `fifo_dispatches` row for the already-selected event — reproducing a hold reappearing
  between selection and the erase `UPDATE`. Asserts the erase affects the event not at all
  (`payload_cleaned_at` still null, the row byte-for-byte unchanged) — proving `eraseOne()`'s
  re-assertion of holds in the `UPDATE`'s own `WHERE` (T11) is what makes this safe, no production
  code change needed. **FIFO liveness under GC:** a 3-row FIFO line (`evt-1` claimed/live,
  `evt-2`/`evt-3` pending), all three events backdated 31 days (expired), proxied through a GC pass —
  the claim, its `lease_expires_at`/`claimed_at`, and the pending set are all untouched (H2 holds all
  three), and no event is cleaned; settling the frozen claim afterward and driving
  `AdvanceProxyFifoQueue::run()` twice (mirrors the existing `FifoLivenessAcceptanceTest`
  step-by-step-advance pattern — `Queue::fake()` prevents the internal self-dispatch from recursing
  inline) settles `evt-2` then `evt-3` and delivers them in receive order (`Http::recorded()` bodies
  `['evt-2', 'evt-3']`), proving the GC pass in between disturbed neither the claim nor delivery order
  (ADR-011 composition). No production-code gap found. Verified: `composer lint`, `composer
  types:check`, `./vendor/bin/sail test --filter RetentionInFlightHoldsAcceptanceTest` (5 tests) and
  `./vendor/bin/sail test --parallel` (410 tests), all green.

## T16 — Cleaned-state & reader-guard acceptance tests (AC10, AC21)

- **Description:** End-to-end proof that the cleaned state is signalled correctly and that every
  reader guards on it — including proof that `AdvanceProxyFifoQueue`, left **unmodified**, remains
  correct under the entry guard (T10).
- **Dependencies:** T6, T10
- **Files:** `tests/Feature/Retention/CleanedStateReaderGuardAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - `StoredPayloadLookup::for()` returns `Retained` for an uncleaned event, `Cleaned` for a cleaned
    one, and `NeverCaptured` for an unknown `ingest_id` — including the case where
    `delivery_attempts` rows exist but no captured row does.
  - `ProcessIngestedWebhook` on a cleaned event returns cleanly, throws nothing, and `Http::fake()`
    records no outbound request.
  - `ProcessIngestedWebhook` on a genuinely missing row still throws `ModelNotFoundException`.
  - `AdvanceProxyFifoQueue`, run **unmodified**, still settles the claimed `fifo_dispatches` row and
    advances the line (self-dispatches to the next pending row) when `ProcessIngestedWebhook`
    returns early on a cleaned payload — no stall, no 500, no exception propagating out of the
    advancer.
- **Testing:** the cases above; the last one constructs a FIFO proxy with a pending line, marks the
  claimed event's parent cleaned before the advancer processes it, and asserts the claim settles and
  the line advances to the next row exactly as it would for a normal delivery.
- **Completion notes:** _pending_

## T17 — Header encryption acceptance tests (AC15, AC22a)

- **Description:** End-to-end proof that header encryption at rest is transparent to every existing
  consumer, complementing T4's unit-level cast test.
- **Dependencies:** T4
- **Files:** `tests/Feature/Retention/HeaderEncryptionAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - The stored `headers` value is encrypted at rest over the real capture path: the raw column
    value is not the plaintext JSON, and the model attribute round-trips to the original array.
  - `content_type` is still populated at capture and survives erasure, while the header collection
    does not (AC6, ADR-014 Decision 6).
  - ADR-008 forwarding is unchanged end to end: the same header set reaches every destination after
    the cast change, with `STRIPPED_HEADERS` still filtered — confirm the existing
    `WebhookEventCaptureAcceptanceTest` and delivery tests keep passing unmodified (no duplicate
    coverage added here).
- **Testing:** the cases above via a real ingest through `IngestController`, `Http::fake()` for the
  destination call, and raw `DB::table('webhook_events')` column assertions.
- **Completion notes:** _pending_

## T18 — Dispatched-output store acceptance tests (AC12–AC15, AC19)

- **Description:** End-to-end proof of the dispatched-output store through the real, wired pipeline
  (T9), complementing T7/T8's unit-level step tests.
- **Dependencies:** T9
- **Files:** `tests/Feature/Retention/DispatchedOutputAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - An enhanced-mode proxy produces exactly one `dispatched_payloads` row per received event,
    associated to that event (AC12, AC13).
  - A simple-mode proxy produces none (AC12, AC14).
  - Multiple destinations for one event still produce exactly one output row (AC13, R3).
  - **Divergence gate — identical:** pre-#9, the row exists with `body IS NULL`, `byte_size` equal
    to the raw `byte_size`, `dispatched_at` set (AC19, ADR-013 Decision 2).
  - **Divergence gate — diverged:** with a test-only step mutating `$ctx->payload` ahead of
    `CaptureDispatchedStep` in a test-local pipeline, the body is stored, encrypted at rest, and
    decrypts back to the diverged bytes (AC15).
  - The raw `webhook_events` row is unchanged by the output write — same attributes, same
    `updated_at` (AC11).
  - Re-running the pipeline for the same event (simulated queue redelivery) still yields exactly one
    row (AC13, idempotency).
  - **Post-clean guard, end to end:** re-processing an event whose parent is already cleaned
    produces no `dispatched_payloads` write and no delivery.
- **Testing:** the cases above via real ingests to enhanced/simple proxies, `Http::fake()`, and
  direct re-invocation of `ProcessIngestedWebhook::run()` to simulate redelivery.
- **Completion notes:** _pending_

---

## Handoff
- **Inputs:** `docs/plans/plan-05-payload-storage-retention.md` (Approved, re-certified against
  Amendment A); `docs/product/prd-05-payload-storage-retention.md` (Approved, amended — AC1–AC22,
  D1); ADR-012, ADR-013, ADR-014 (all Accepted, Owner 2026-08-05); `docs/questions/prd-05-q-05-03-...md`
  (RESOLVED, amended) and `prd-05-q-05-04-...md` (RESOLVED); `docs/tasks/queued-processing-tasks.md`
  (house format/granularity precedent); `docs/standards/planning.md`; the current codebase
  (`webhook_events`/`fifo_dispatches` migrations, `WebhookEvent`, `WebhookEventCapture`,
  `ProcessIngestedWebhook`, `AdvanceProxyFifoQueue`, `SweepStalledFifoDispatches`, `PipelineFactory`,
  `PipelineContext`, `DeliverStep`, `routes/console.php`, `config/ingest.php`).
- **Outputs:** this task plan.
- **Dependencies:** none new; within-stack (Eloquent, migrations, the Laravel scheduler,
  `lorisleiva/laravel-actions`).
- **Outstanding Questions:** none. No design ambiguity was found in plan-05/ADR-012–014 that
  required a question doc — every task above traces to an explicit plan decision or an explicit ADR
  decision number. One implementation-level judgment call is deliberately left to the Senior
  Developer rather than specified here (T14's AC12-atomicity fault-injection mechanism, and T11/T12's
  exact `AsCommand`-vs-schedule-closure registration shape), consistent with house convention of not
  prescribing code in a task's Description.
- **Next Agent:** Senior Developer — implement T1–T18 in order, one feature-branch commit per
  completed task (or per logical part of a large task), leaving `composer lint`, `composer
  types:check`, and `./vendor/bin/sail test` green at every commit, per `docs/standards/planning.md`.
