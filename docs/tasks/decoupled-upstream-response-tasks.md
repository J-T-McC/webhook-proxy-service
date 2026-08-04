# Task Plan: Decoupled upstream response (with always-on raw capture) — item #3

- **Status:** Approved
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-03-decoupled-upstream-response.md` (Approved — Principal
  Engineer self-certified, 2026-08-03; ADR-010 data-model/security flag ratified by Owner
  2026-08-04)
- **PRD:** `docs/product/prd-03-decoupled-upstream-response.md` (Approved — Owner, 2026-08-03,
  11 ACs) · **ADRs:** ADR-010 (Accepted, incl. Amendment B), ADR-003/004/005/006 (Accepted,
  unchanged)
- **Approved by:** Task Planner (task-plan gate; no Owner approval required at this stage — the
  Reviewer catches drift against the plan/PRD-03/ADR-010 at review time)

> **Scope / conventions.** Every task traces to plan-03 and its PRD-03 ACs (AC1–AC11). The plan
> describes two orthogonal changes that share only the ingest hot path
> (`app/Http/Controllers/IngestController.php`): **(A) user-defined response** (T1–T8) and
> **(B) always-on raw capture** (T9–T12). Sequence follows the plan's own ordering (response
> first, capture second); the two tracks do not depend on each other except that both touch
> `IngestController` (T11) and both are exercised end-to-end only once wired.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green, and
> `./vendor/bin/sail test` green with its own tests included (CLAUDE.md, `docs/standards/planning.md`).
> Frontend-touching tasks (T6–T7) additionally require `pnpm types:check` (vue-tsc),
> `pnpm lint:check` (ESLint), and `pnpm format:check` (Prettier) green.
>
> **No new dependency, no stack change.** Uses only Eloquent, migrations, the existing `Http`
> client, `lorisleiva/laravel-actions` (already adopted), and the existing config pattern (plan
> §Dependencies).
>
> **Scope discipline (plan §Overview / PRD Out of Scope) — do NOT build in this feature:**
> retry/replay/backoff (#6), retention/GC of `webhook_events` (#5), queued/async dispatch (#4),
> the future re-encryption artisan command/job (ADR-010 Amendment B — accepted future task, not
> #3), a content-type selector or any UI beyond the two form fields (route new UI needs back to
> the Product Manager per the plan's Handoff), and inbound-header encryption (plaintext until
> #10, ADR-010 Amendment B — do not add it here).
>
> **Load-bearing ordering invariant (ADR-010, AC5/AC6).** In `IngestController`, capture must
> commit **before** the response is resolved/returned and **before** `ProcessIngestedWebhook` is
> dispatched. T11 is the only task that changes this ordering; no other task may reorder it.
>
> **`webhook_events.body` LONGBLOB requirement (ADR-010 Amendment B).** The column must be
> `LONGBLOB` at the database level (binary-safe, ~4 GiB capacity for the encrypted-cast envelope
> at the ADR-006 body cap) — not the framework's default `BLOB`/`TEXT` mapping. T9's Acceptance
> Criteria requires verifying the actual `information_schema` `DATA_TYPE`, not just that a column
> named `body` exists; the exact migration mechanism (e.g. a raw column-type statement) is the
> Senior Developer's implementation choice.

---

## T1 — Ingest response-body cap config (`ingest.response_body_max_bytes`)

- **Description:** Add the config-driven cap for a proxy's user-defined response body per plan
  §Validation: `config('ingest.response_body_max_bytes')`, env `INGEST_RESPONSE_BODY_MAX_BYTES`,
  default **8192 (8 KiB)**. Follows the same config pattern as the existing `ingest.max_body_bytes`
  / `ingest.rate_limit_per_minute` keys in `config/ingest.php`.
- **Dependencies:** none
- **Files:** `config/ingest.php`, `.env.example`
- **Acceptance Criteria:** `config('ingest.response_body_max_bytes')` returns `8192` when
  `INGEST_RESPONSE_BODY_MAX_BYTES` is unset, and the env value when set; documented inline as the
  response-body size cap (distinct from the existing ingest body-size cap).
- **Testing:** extend `tests/Unit/Config/IngestConfigTest.php` (or equivalent) with a case for the
  default and the env override, mirroring the existing `max_body_bytes` test.
- **Completion notes:** Done (2026-08-04). Added `ingest.response_body_max_bytes` to
  `config/ingest.php` — `(int) env('INGEST_RESPONSE_BODY_MAX_BYTES', 8192)`, following the existing
  `max_body_bytes` / `rate_limit_per_minute` casting pattern, with an inline block documenting it as
  the *response*-body cap (an acknowledgement/challenge-echo contract) explicitly distinct from
  `max_body_bytes` (the inbound request-body cap). Added the commented env key to `.env.example`
  under the ingest guards. Extended `tests/Unit/Config/IngestConfigTest.php` with two cases mirroring
  the URL-override pattern: default returns `8192` when the env is unset (via `config()`), and an env
  override (`16384`) is picked up via `require base_path('config/ingest.php')` with `putenv` cleanup
  in a `finally`. Note: no standalone `max_body_bytes` test existed to mirror, so I followed the
  file's established `url` env-override pattern. Gates: `composer lint` passed, `composer types:check`
  passed (0 errors), `./vendor/bin/sail test --filter IngestConfigTest` 4/4 passed; full suite
  `./vendor/bin/sail test --parallel` 225/225 passed (no broader breakage).

## T2 — `proxies` response-config columns + model updates (AC1, AC3, AC4)

- **Description:** Per plan §Data Model → `proxies`. New migration adds two **nullable, no
  schema-default** columns: `response_status` (UNSIGNED SMALLINT) and `response_body` (TEXT). NULL
  means unconfigured; the `202` default is owned by `ResponseResolver` (T3), never the schema — no
  backfill for existing rows (AC3). Extend `Proxy`'s `#[Fillable]` to include `response_status`,
  `response_body`; add cast `'response_status' => 'integer'` (nullable, no cast needed on
  `response_body`); update the model's `@property` docblock.
- **Dependencies:** none
- **Files:** `database/migrations/*_add_response_config_to_proxies_table.php` (new),
  `app/Models/Proxy.php`
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` both exercised); both columns are
  nullable with no schema default (verify via `information_schema.COLUMNS` — `IS_NULLABLE = 'YES'`,
  `COLUMN_DEFAULT IS NULL`); an existing (pre-migration) proxy row has both columns `NULL` after
  migrating, with no value written by the migration itself; `response_status` round-trips through
  the `integer` cast when set and stays `null` when unset.
- **Testing:** extend `tests/Unit/Models/ProxyTest.php` (or a schema test alongside it) — schema
  assertions for both columns' nullability/no-default, and a model-level cast/round-trip test for
  `response_status`.
- **Completion notes:** Done (2026-08-04). New migration
  `2026_08_04_000001_add_response_config_to_proxies_table.php` adds
  `response_status` (`unsignedSmallInteger`, nullable, no default, `after('mode')`) and
  `response_body` (`text`, nullable, no default) — NULL = unconfigured; the `202` default
  stays owned by `ResponseResolver` (T3), never the schema. `down()` drops both columns.
  Verified up/down both apply cleanly on the testing DB (`migrate:rollback --step=1` then
  `migrate`). `Proxy` model: extended `#[Fillable]` to add `response_status`/`response_body`,
  added cast `'response_status' => 'integer'` (nullable; no cast on `response_body`), and
  added `@property int|null $response_status` / `@property string|null $response_body`
  docblock lines. Tests in `tests/Unit/Models/ProxyTest.php`: (1) schema assertion that both
  columns are `IS_NULLABLE = 'YES'` with `COLUMN_DEFAULT IS NULL` via `information_schema`;
  (2) a factory-made (pre-#3-simulating) proxy has both columns NULL with no backfill;
  (3) `response_status` round-trips `201` through the integer cast and stays `null` when unset.
  **Verified information_schema facts (per intro note):** both columns report
  `IS_NULLABLE=YES`, `COLUMN_DEFAULT=NULL`. Gates: `composer lint` passed,
  `composer types:check` 0 errors, `./vendor/bin/sail test --filter ProxyTest` 10/10, full
  `--parallel` 228/228.

## T3 — `ResponseResolver`: config-driven resolution (AC1–AC4, ADR-004)

- **Description:** Per plan §API → Ingest response contract. Replace the fixed-`202` body in
  `ResponseResolver::resolve()` with: `$status = $proxy->response_status ?? 202`, `$body =
  $proxy->response_body ?? ''`, and `Content-Type: text/plain; charset=utf-8` set only when a body
  is present. Reads only `$proxy` columns — never delivery outcome or `delivery_attempts` (ADR-004,
  unchanged invariant). Remove the `// LATER (#3)` comment now that it is implemented.
- **Dependencies:** T2
- **Files:** `app/Services/ResponseResolver.php`
- **Acceptance Criteria:** both configured → exactly that status + body + the fixed content-type
  (AC1); neither configured → `202`, empty body, no content-type header forced (AC3); only one
  configured → that value + the other's default; the resolver contains no read of
  `DeliveryAttempt`/delivery outcome (AC2, inspection).
- **Testing:** `tests/Unit/Services/ResponseResolverTest.php` (extend or new) — the four
  status/body combinations above, asserting exact status, body, and `Content-Type` header presence/
  absence.
- **Completion notes:** Done (2026-08-04). `ResponseResolver::resolve()` now returns
  `new Response($body, $status, $headers)` where `$status = $proxy->response_status ?? 202`
  (HTTP_ACCEPTED) and `$body = $proxy->response_body ?? ''`; `Content-Type: text/plain;
  charset=utf-8` is set **only** when the body is non-empty (`$body === '' ? [] : [...]`).
  Reads only `$proxy` columns — no `DeliveryAttempt`/delivery-outcome read (ADR-004, AC2);
  removed the `// LATER (#3)` placeholder and updated the class docblock. Rewrote
  `tests/Unit/Services/ResponseResolverTest.php` (the prior single 202 case is subsumed) to
  cover all four combinations: both set → exact 201 + body + content-type; neither → 202,
  empty, no forced content-type; only status (204) → that status, empty body, no content-type;
  only body → 202 + body + content-type. Gates: `composer lint` passed, `composer types:check`
  0 errors, `--filter ResponseResolverTest` 4/4, full `--parallel` 231/231 (existing ingest
  tests unaffected — factory proxies are unconfigured, so still 202 empty).

## T4 — `StoreProxyRequest`/`UpdateProxyRequest`: response validation rules (AC4)

- **Description:** Per plan §Validation. Add identical rules to both FormRequests:
  `response_status` → `['nullable','integer','between:200,299']` (a non-2xx value is rejected;
  NULL/absent is allowed); `response_body` → `['nullable','string','max:<cap>']` where `<cap>` is
  `config('ingest.response_body_max_bytes')` (T1). No change to existing `name`/`mode`/
  `destinations.*` rules.
- **Dependencies:** T1
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:** a non-2xx `response_status` (e.g. `199`, `300`, `404`) is rejected under
  the `response_status` error key on both Store and Update; `response_status` absent/null is
  accepted; a `response_body` over the configured cap is rejected under `response_body`; a body at
  or under the cap is accepted; existing validation cases (`name`, `mode`, `destinations.*`) remain
  green.
- **Testing:** extend `tests/Feature/Proxies/ProxyRequestValidationTest.php` with data-provider
  cases for both new fields on Store and Update (non-2xx rejected, boundary values 200/299 accepted,
  null accepted, oversized body rejected, cap-sized body accepted).
- **Completion notes:** Done (2026-08-04). Added identical rules to both
  `StoreProxyRequest` and `UpdateProxyRequest`: `response_status` →
  `['nullable','integer','between:200,299']` and `response_body` →
  `['nullable','string','max:'.config('ingest.response_body_max_bytes')]` (cap from T1,
  default 8192). No change to existing `name`/`mode`/`destinations.*` rules. Extended
  `tests/Feature/Proxies/ProxyRequestValidationTest.php` (data-provider over both Store and
  Update): non-2xx statuses (199/300/404) rejected under `response_status`; explicit-null and
  absent accepted; boundaries 200/299 accepted; `response_body` at the cap accepted and cap+1
  rejected under `response_body`. Note: followed the plan's literal `max:<cap>` rule — Laravel's
  string `max` counts characters (mb-aware), so the cap is characters not raw bytes; the tests
  use single-byte `'a'` so the boundary is exact, and this matches the plan verbatim (no
  deviation). Gates: `composer lint` passed, `composer types:check` 0 errors,
  `--filter ProxyRequestValidationTest` 24/24, full `--parallel` 239/239.

## T5 — `ProxyController` store/update: persist response config (AC1, AC3)

- **Description:** Per plan §Services. `store()` already mass-assigns validated data onto
  `Proxy::make($data)`; once `response_status`/`response_body` are `#[Fillable]` (T2) and validated
  (T4) they persist automatically — verify this explicitly. `update()` currently passes an explicit
  `['name' => ..., 'mode' => ...]` array to `$proxy->update()`; extend it to also pass
  `response_status`/`response_body` from the validated data (including explicit `null` to clear a
  previously configured value).
- **Dependencies:** T2, T4
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** creating a proxy with `response_status`/`response_body` set persists both
  exactly; creating without them leaves both `NULL` (AC3); updating a proxy to set them persists the
  new values; updating a proxy to clear them (submit `null`) persists `NULL`, not the prior value.
- **Testing:** extend `tests/Feature/Proxies/ProxyStoreTest.php` and
  `tests/Feature/Proxies/ProxyUpdateTest.php` with cases for configured, unconfigured, and
  clear-to-null.
- **Completion notes:** Done (2026-08-04). `store()` needed no change — `Proxy::make($data)`
  mass-assigns `response_status`/`response_body` now that they are `#[Fillable]` (T2) and
  validated (T4); verified explicitly by test. `update()` extended to pass
  `'response_status' => $data['response_status'] ?? null` and
  `'response_body' => $data['response_body'] ?? null` alongside name/mode — the `?? null`
  lets an omitted/explicit-null field clear a previously configured value (AC3). Tests:
  `ProxyStoreTest` — create-with-config persists both (201 / `{"ok":true}`), create-without
  leaves both NULL; `ProxyUpdateTest` — update sets both (200 / `thanks`), and update with
  explicit `null` clears a proxy previously configured (201 / `previously set`) back to NULL.
  Gates: `composer lint` passed, `composer types:check` 0 errors,
  `--filter "ProxyStoreTest|ProxyUpdateTest"` 11/11, full `--parallel` 243/243.

## T6 — `ProxyResource` + frontend types: expose response config

- **Description:** Add `response_status` (`int|null`) and `response_body` (`string|null`) to
  `ProxyResource::toArray()` so the shared Create/Edit form (T7) can pre-fill them. Add the matching
  fields to the `ProxyListItem`/`ProxyDetail` TypeScript interfaces (index doesn't need them
  rendered, but `ProxyDetail` — used by `Edit.vue` — must carry them).
- **Dependencies:** T2
- **Files:** `app/Http/Resources/ProxyResource.php`, `resources/js/types/proxies.ts`
- **Acceptance Criteria:** the `show`/`edit` Inertia payload includes `response_status` and
  `response_body` reflecting the DB values, including `null` for an unconfigured proxy; `pnpm
  types:check` green.
- **Testing:** extend `tests/Feature/Proxies/ProxyIndexShowTest.php` (or a resource-focused test)
  asserting the two new keys appear with the correct values for a configured and an unconfigured
  proxy.
- **Completion notes:** _pending_

## T7 — `ProxyForm.vue`: response status/body inputs (PRD UX Direction)

- **Description:** Add a status-code input and a response-body input to the shared
  `resources/js/pages/proxies/ProxyForm.vue` (used by both `Create.vue` and `Edit.vue`), following
  the existing field patterns (`Label`/`Input`/`InputError`, help text, `aria-describedby`). Both
  fields are optional. Copy must make clear the response is returned **immediately and
  independently of delivery** — an acknowledgement contract, not a delivery report (plan §API →
  Management form props). Wire `Create.vue`'s `initial` to default both to `null`/empty, and
  `Edit.vue`'s `initial` (and its `EditProxy` interface) to pass through the resource's
  `response_status`/`response_body` (T6).
- **Dependencies:** T6, T5
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`, `resources/js/pages/proxies/Create.vue`,
  `resources/js/pages/proxies/Edit.vue`
- **Acceptance Criteria (manual — no JS test framework, per `docs/standards/coding.md` /
  role-based-collaboration T10/T11 precedent):** creating a proxy with both fields set persists and
  displays them on Edit; leaving both blank creates an unconfigured (`null`) proxy; a non-2xx status
  or an oversized body surfaces the server validation error (T4) under the correct field; the
  acknowledgement-vs-delivery copy is present and accurate; keyboard reachability / `aria-invalid` /
  focus-on-error behavior matches the existing `name`/`mode` fields; light and dark palettes checked.
- **Testing:** none automated (no JS test framework exists); the manual walkthrough above is the
  acceptance gate, recorded in Completion notes. `pnpm types:check` / `lint:check` / `format:check`
  green.
- **Completion notes:** _pending_

## T8 — Response-resolution acceptance tests (AC1–AC4, ADR-004)

- **Description:** End-to-end feature tests over the real `POST/PUT /ingest/{token}` route proving
  the response-config half is wired correctly, not just true at the unit level (T3). No new
  production code expected; fix any wiring gap here.
- **Dependencies:** T2, T3
- **Files:** `tests/Feature/Ingest/ResponseResolutionTest.php` (new)
- **Acceptance Criteria:**
  - A proxy with `response_status`/`response_body` configured → the ingest response is exactly that
    status and body, with `Content-Type: text/plain; charset=utf-8` (AC1).
  - A proxy with neither configured (including a factory-made proxy simulating a pre-#3, #1-created
    row) → `202 Accepted`, empty body (AC3, no-surprise inheritance).
  - The response is identical whether a faked destination succeeds, returns 500, or throws — same
    status/body in all three cases (AC2, ADR-004).
  - `ResponseResolver` is not passed and does not read any `DeliveryAttempt` (inspection note in the
    test referencing T3's code-level guarantee, plus the behavioral proof above).
- **Testing:** the cases above using `Http::fake()` for the destination outcomes.
- **Completion notes:** _pending_

## T9 — `webhook_events` table + `WebhookEvent` model (AC5, AC7–AC9, ADR-010)

- **Description:** Per plan §Data Model → `webhook_events` and ADR-010. New raw-only, immutable
  table: `id`; `team_id` FK → teams (`constrained()`, restrict — no cascade, matches
  `delivery_attempts`); `proxy_id` FK → proxies (`constrained()`, restrict); `ingest_id` uuid,
  **UNIQUE**; `method` string(7); `headers` JSON (plaintext until #10); `content_type` nullable
  string; `body` **LONGBLOB** (see intro note); `byte_size` UNSIGNED INT; `received_at` timestamp;
  `created_at`/`updated_at`. Indexes: `UNIQUE(ingest_id)`, `(team_id, created_at)`,
  `(proxy_id, created_at)`. **No** dispatched/derived-output column, **no** retention/GC column, **no**
  `deleted_at`/`SoftDeletes` (raw-only and immutable by construction).

  Model `WebhookEvent`: `BelongsToCurrentTeam`, `belongsTo(Proxy)`; casts `'body' => 'encrypted'`
  (ADR-010 Amendment B), `'headers' => 'array'`, `'received_at' => 'datetime'`,
  `'byte_size' => 'integer'`; `#[Fillable]`: `team_id`, `proxy_id`, `ingest_id`, `method`, `headers`,
  `content_type`, `body`, `byte_size`, `received_at`.
- **Dependencies:** none
- **Files:** `database/migrations/*_create_webhook_events_table.php` (new),
  `app/Models/WebhookEvent.php` (new), `database/factories/WebhookEventFactory.php` (new)
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` exercised); `body` column's actual
  `information_schema.COLUMNS.DATA_TYPE` is `longblob`; `ingest_id` has a single-column `UNIQUE`
  index; the two composite indexes exist; the table has no soft-delete column and no
  dispatched-output column (assert by schema, mirroring the `delivery_attempts` payload-free schema
  test); `body` round-trips through the `encrypted` cast (ciphertext ≠ plaintext at rest, decrypts
  back to the original bytes); `headers` round-trips as an array; `byte_size` casts to `integer`.
- **Testing:** `tests/Unit/Models/WebhookEventTest.php` (new) — schema assertions above, plus the
  encrypted-body round-trip and `headers`/`byte_size` cast tests (mirrors `ProxyTest`'s encrypted
  round-trip and `DeliveryAttemptTest`'s payload-free schema pattern).
- **Completion notes:** _pending_

## T10 — `WebhookEventCapture` service (AC5, AC7–AC9)

- **Description:** Per plan §Services. New `App\Services\WebhookEventCapture` — **a Service, not an
  Action** (must never be `::dispatch`ed; capture is inherently synchronous, ADR-010). Method
  `capture(Proxy $proxy, string $ingestId, string $method, array $headers, string $rawBody):
  WebhookEvent` writes one committed `webhook_events` row: `team_id` set **explicitly** from
  `$proxy->team_id` (the ingest path is team-unscoped, mirroring `DeliverToDestination`), `proxy_id`
  from `$proxy->id`, `byte_size = strlen($rawBody)` (the **plaintext** size, recorded before the cast
  encrypts), `content_type` derived from `$headers` (case-insensitive `Content-Type` lookup, `null`
  if absent), `received_at = now()`.
- **Dependencies:** T9
- **Files:** `app/Services/WebhookEventCapture.php` (new)
- **Acceptance Criteria:** calling `capture()` persists exactly one `webhook_events` row with the
  passed `ingestId`, `method`, `headers`, raw `body` (decrypts back to the original bytes),
  correctly derived `content_type`, and `byte_size === strlen($rawBody)`; `team_id`/`proxy_id` match
  the passed `$proxy` regardless of any authenticated user (no `Auth` dependency).
- **Testing:** `tests/Unit/Services/WebhookEventCaptureTest.php` (new) — asserts the row's field
  values above, including a case with no `Content-Type` header (`content_type` null) and one with a
  mixed-case header name.
- **Completion notes:** _pending_

## T11 — `IngestController`: synchronous pre-dispatch capture + 500 on failure (AC5–AC9, ADR-010)

- **Description:** Per plan §Architecture (revised ingest order) and ADR-010 Decision (2). Reorder
  `IngestController::__invoke()`: mint `ingestId` and read `method`/`headers`/`rawBody` **first**;
  call `WebhookEventCapture::capture()` synchronously, wrapped in a `try`/`catch (Throwable)` —
  `report()` the exception (never log the raw body or token) and `abort(500)` on failure, dispatching
  nothing; only on success build the `PipelineContext` (same `ingestId`), resolve the response via
  `ResponseResolver`, dispatch `ProcessIngestedWebhook::run($ctx)`, then return the resolved response.
  Capture is unconditional on `$proxy->mode` (AC7, R2 override — no mode branch). Inject
  `WebhookEventCapture` via the constructor alongside the existing `ResponseResolver`. Update the
  `PipelineFactory` `CaptureRawStep // #5` comment to note that raw capture now lives in
  `IngestController`/`WebhookEventCapture` (ADR-010 supersedes that placeholder for raw capture;
  leave the dispatched-output `CaptureDispatchedStep // #5` comment as-is).
- **Dependencies:** T9, T10
- **Files:** `app/Http/Controllers/IngestController.php`, `app/Pipeline/PipelineFactory.php`
  (comment only)
- **Acceptance Criteria:** a successful ingest request results in a committed `webhook_events` row
  before the response is returned, in both `simple` and `enhanced` mode (AC5, AC7); a capture-write
  failure returns `HTTP 500`, commits no `webhook_events` row, and dispatches nothing (`Http`
  assertions show no outbound call) (AC6); the resolved 2xx response is only reachable after a
  committed capture; the plaintext token and raw body never appear in logs on the failure path.
- **Testing:** feature tests (can extend `tests/Feature/Ingest/IngestControllerTest.php` or a new
  file) — success path asserts the `webhook_events` row exists with the request's `ingest_id`
  before/alongside the 2xx response, in both modes; failure path mocks `WebhookEventCapture::capture`
  to throw and asserts `500`, no `webhook_events` row, no delivery attempted.
- **Completion notes:** _pending_

## T12 — Capture acceptance tests (AC5–AC9, ADR-003/ADR-010)

- **Description:** End-to-end feature tests over the wired ingest path proving the full capture
  contract, complementing T11's wiring-focused tests with the AC-level assertions from plan §Test
  strategy. No new production code expected; fix any gap in the owning task (T9/T10/T11).
- **Dependencies:** T11
- **Files:** `tests/Feature/Ingest/WebhookEventCaptureAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A valid ingest (simple mode) writes exactly one `webhook_events` row with the raw body,
    `method`, `headers`, `content_type`, `byte_size`, and the **same `ingest_id`** as the request's
    resulting `delivery_attempts` rows (AC5, AC8, AC9). Repeat for enhanced mode (AC7).
  - **Capture failure → 500 (AC6):** force the capture write to throw; assert `HTTP 500`, **no**
    `webhook_events` row committed, **no** delivery attempted (`Http::assertNothingSent()` / no
    `delivery_attempts` row), and the proxy's configured 2xx is **not** returned.
  - **No parallel path (AC9, ADR-003):** the corresponding `delivery_attempts` rows remain
    payload-free (no body/payload column touched or introduced); capture and delivery-attempt
    creation happen independently, joined only by the shared `ingest_id`.
  - **Raw immutability (AC8):** the captured `body` equals the exact received bytes and is
    unchanged after delivery completes (re-read the row post-request and compare).
- **Testing:** the cases above using `Http::fake()` for delivery outcomes.
- **Completion notes:** _pending_

---

## Handoff

- **Inputs:** `docs/plans/plan-03-decoupled-upstream-response.md` (Approved), PRD-03 (Approved,
  11 ACs), ADR-010 (Accepted, incl. Amendment B), ADR-003/004/005/006 (Accepted, unchanged),
  `docs/standards/planning.md`; grounding reads of `app/Http/Controllers/IngestController.php`,
  `app/Services/ResponseResolver.php`, `app/Models/Proxy.php`, `app/Models/DeliveryAttempt.php`,
  `app/Concerns/BelongsToCurrentTeam.php`, `app/Actions/DeliverToDestination.php`,
  `app/Pipeline/PipelineFactory.php`, `app/Http/Requests/{Store,Update}ProxyRequest.php`,
  `app/Http/Resources/ProxyResource.php`, `app/Http/Controllers/ProxyController.php`,
  `resources/js/pages/proxies/{ProxyForm,Create,Edit}.vue`, `resources/js/types/proxies.ts`,
  `config/ingest.php`, `.env.example`, `database/migrations/2026_07_30_00000{1,3}_create_*.php`.
- **Outputs:** this task plan (`docs/tasks/decoupled-upstream-response-tasks.md`).
- **Dependencies:** Response track: T1→T4→T5→T7; T2→T3→T8, T2→T5, T2→T6→T7. Capture track:
  T9→T10→T11→T12. The two tracks are independent except both eventually touch
  `IngestController` (T11, capture only — response resolution in `IngestController` is untouched by
  this feature beyond the existing `ResponseResolver::resolve()` call). No task depends on a later
  task.
- **Outstanding Questions:** none blocking implementation. Q-03-05 (storage-shape ownership) was
  resolved by ADR-010; the plan's Owner-approval flags (ADR-010, the two `proxies` columns, and the
  body-encryption security acknowledgements) were all approved 2026-08-04 per `docs/status.md`.
- **Next Agent:** Senior Developer.
