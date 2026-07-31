# Task Plan: Walking skeleton — ingest → fan-out delivery (item #1)

- **Status:** Approved
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-01-walking-skeleton.md` (Accepted — Project Owner, 2026-07-30)
- **PRD:** `docs/product/prd-01-walking-skeleton.md` (Approved) · **Design:** `docs/design/design-01-walking-skeleton.md` (Approved) · **ADRs:** 001–008 (Accepted)
- **Approved by / date:** Project Owner, 2026-07-30

> **Scope / conventions.** Every task traces to the plan and its ACs/flows. Sequence is
> migrations/models → services → controllers → Vue pages, tests accompanying each behavioral
> task. Every task must leave `composer lint` (Pint), `composer types:check` (PHPStan L1) and
> `./vendor/bin/sail test` green before it is considered done (CLAUDE.md). Do not build any
> `LATER` scaffolding (async `::dispatch`/`onQueue`/FIFO, payload storage, mapping, retry, #3
> resolver body, V3 publisher) — those are commented-out seams only (foundational plan
> Appendix A).
>
> **Team-scope binding (confirmed against the installed kit; resolves plan residual #7).** The
> "current team" is the authenticated user's `current_team_id` (`App\Concerns\HasTeams`,
> `User::currentTeam()`). Management routes nest under the existing `{current_team}` slug prefix
> with `['auth','verified',EnsureTeamMembership::class]` (mirroring the `dashboard` route in
> `routes/web.php`); `EnsureTeamMembership` guarantees the URL team == the user's membership and
> switches `current_team_id` to it. Model global scopes key on `current_team_id`.
> **Owner decision 2026-07-30:** this kit-inspected binding is the **confirm-target** — the
> **Senior Developer verifies it against the actually installed starter-kit** (not treated as
> settled) before implementing T7/T20; see the annotations on those tasks and Flagged gap #4.

---

## T1 — Add `lorisleiva/laravel-actions` dependency (ADR-007)
- **Description:** Add the approved `lorisleiva/laravel-actions` package (ADR-007) that realizes
  the `AsAction`/`AsObject` traits the pipeline steps and dispatchable Actions use. Install only;
  no Actions built here.
- **Dependencies:** none
- **Files:** `composer.json`, `composer.lock`
- **Acceptance Criteria:** package installed at a Laravel-13-compatible version; `AsAction` and
  `AsObject` traits resolvable; `composer types:check` still green.
- **Testing:** `composer types:check` green; a throwaway `use Lorisleiva\Actions\Concerns\AsAction;`
  resolves (verified by later tasks that consume it).
- **Completion notes:** Done. `composer require lorisleiva/laravel-actions` → resolved `^2.10`
  (Laravel 13 compatible), auto-discovered. Verified `AsAction` and `AsObject` traits resolve at
  runtime (`trait_exists` both OK). `composer.json` + `composer.lock` updated. PHPStan green
  (`php -d memory_limit=1G vendor/bin/phpstan analyse` → 0 errors; see env note below re: default
  128M memory limit). **Env note:** the `composer types:check` script inherits the local
  php.ini `memory_limit=128M` and PHPStan's parallel worker OOMs while reflecting `Carbon`/`User`
  (pre-existing, unrelated to this change); it passes clean at `memory_limit=1G`.

## T2 — Ingest-URL config key (`config('ingest.url')`)
- **Description:** Add a dedicated ingest base config key per ADR-006 "Where the base/host comes
  from" and the plan's Services → ingest URL builder: `config('ingest.url')` backed by env
  `INGEST_URL`, defaulting to `config('app.url')`. This is the sole source of the ingest host —
  never the request `Host` header.
- **Dependencies:** none
- **Files:** `config/ingest.php` (new), `.env.example`
- **Acceptance Criteria:** `config('ingest.url')` returns `INGEST_URL` when set, else
  `config('app.url')`; no code reads the request `Host` header to build ingest URLs.
- **Testing:** unit/config test asserting the default-to-`app.url` fallback and the env override.
- **Completion notes:** Done. Added `config/ingest.php` with `'url' => env('INGEST_URL',
  env('APP_URL', 'http://localhost'))` — INGEST_URL wins, else mirrors the app URL; no request
  `Host` header is read. `.env.example` documents the commented `INGEST_URL` key.
  `tests/Unit/Config/IngestConfigTest.php` asserts (a) fallback: `config('ingest.url')` ===
  `config('app.url')` when INGEST_URL unset, and (b) env override by re-evaluating the config file
  with `putenv('INGEST_URL=…')`. Pint + PHPStan L7 + tests green (2 passed). Additional
  ingest config keys (`max_body_bytes`, `rate_limit_per_minute`) are deferred to T17 per its scope.

## T3 — Domain enums (`Mode`, `HttpMethod`, `AttemptStatus`)
- **Description:** Backed string enums used by the models and pipeline: `Mode(simple,enhanced)`
  (ADR-002), `HttpMethod(POST,PUT)` (V1/AC3), `AttemptStatus(dispatched,succeeded,failed)`
  (ADR-003).
- **Dependencies:** none
- **Files:** `app/Enums/ProxyMode.php`, `app/Enums/HttpMethod.php`, `app/Enums/AttemptStatus.php`
  (follow the existing `app/Enums/` convention, e.g. `TeamRole`)
- **Acceptance Criteria:** each enum exposes exactly the plan's cases and backing values; no other
  cases.
- **Testing:** unit test asserting the case set and backing values of each enum.
- **Completion notes:** Done. Added backed string enums: `ProxyMode(Simple=simple, Enhanced=enhanced)`,
  `HttpMethod(Post=POST, Put=PUT)`, `AttemptStatus(Dispatched=dispatched, Succeeded=succeeded,
  Failed=failed)` — case-name convention matches existing `TeamRole`/`TeamPermission`. (The mode
  enum is class `ProxyMode` per the task's Files list, not the description's shorthand `Mode`.)
  `tests/Unit/Enums/DomainEnumsTest.php` asserts each enum's exact case set + backing values (3
  passed). Pint green.

## T4 — `proxies` table + `Proxy` model
- **Description:** Migration and Eloquent model per plan §Data Model → `proxies`. Columns:
  `id`, `team_id` (FK→teams, indexed), `name`, `mode` enum default `simple`,
  `ingest_token_hash` **`BINARY(32)` single-column `UNIQUE`** (never a `_ci` collation; ADR-006
  perf addendum), `ingest_token` **`encrypted`** cast (text), timestamps, `deleted_at`
  (`SoftDeletes`). Model: `SoftDeletes`, `mode`→`ProxyMode` cast, `ingest_token`→`encrypted` cast,
  `destinations()` / `deliveryAttempts()` relations, factory. **Team global scope is added in T7,
  not here.**
- **Dependencies:** T3
- **Files:** `database/migrations/*_create_proxies_table.php`, `app/Models/Proxy.php`,
  `database/factories/ProxyFactory.php`
- **Acceptance Criteria:** migration applies cleanly; `ingest_token_hash` is `BINARY(32)` with a
  single-column unique index (not composite with `deleted_at`); `ingest_token` round-trips through
  the `encrypted` cast; `mode` casts to `ProxyMode` and defaults to `simple`; soft delete sets
  `deleted_at` and hides the row from default queries.
- **Testing:** model unit test — encrypted-token round-trip, `mode` default + cast, `BINARY(32)`
  unique column present, `assertSoftDeleted` after `delete()`.
- **Completion notes:** Done. Migration `2026_07_30_000001_create_proxies_table.php`:
  `binary('ingest_token_hash', 32, true)` → fixed `BINARY(32)` with a single-column `->unique()`
  (not composite with `deleted_at`); `enum('mode',['simple','enhanced'])->default('simple')`;
  `text('ingest_token')`; `timestamps()` + `softDeletes()`; `team_id` indexed via `constrained()`.
  Model `Proxy`: `SoftDeletes`, casts `mode`→`ProxyMode` and `ingest_token`→`encrypted`,
  `team()`/`destinations()`/`deliveryAttempts()` relations, `Fillable([team_id,name,mode])`.
  `ProxyFactory` mints a random token + matching SHA-256 hash, `enhanced()`/`trashed()` states.
  Test `ProxyTest` (5 passed, 11 assertions): encrypted round-trip (ciphertext ≠ plaintext at rest),
  `mode` cast + DB default `simple`, `information_schema` proves `BINARY(32)` + single-column
  unique index, duplicate-hash rejected (`QueryException`), `assertSoftDeleted` + hidden from
  default query. Pint green. **PHPStan note:** the `Destination`/`DeliveryAttempt` relation return
  types forward-reference the T5/T6 model classes (plan's ordering); the consolidated PHPStan L7
  gate is run green at T6 once those classes exist.

## T5 — `destinations` table + `Destination` model
- **Description:** Migration and model per plan §Data Model → `destinations`. Columns: `id`,
  `proxy_id` (FK→proxies, indexed, plain `RESTRICT` — **no `ON DELETE CASCADE`**), `team_id`
  (FK→teams, indexed), `url`, `http_method` enum(`POST`,`PUT`), timestamps, `deleted_at`
  (`SoftDeletes`). Model: `SoftDeletes`, `http_method`→`HttpMethod` cast, `proxy()` relation,
  factory. Team global scope added in T7.
- **Dependencies:** T4
- **Files:** `database/migrations/*_create_destinations_table.php`, `app/Models/Destination.php`,
  `database/factories/DestinationFactory.php`
- **Acceptance Criteria:** migration applies cleanly; FK is not `ON DELETE CASCADE`;
  `http_method` casts to `HttpMethod`; `Proxy::destinations()` returns **live only** (SoftDeletes
  scope) and excludes trashed rows.
- **Testing:** model unit test — `http_method` cast, `proxy` relation, `assertSoftDeleted` after
  `delete()`, and that a soft-deleted destination is absent from `proxy->destinations`.
- **Completion notes:** Done. Migration `2026_07_30_000002_create_destinations_table.php`:
  `proxy_id`/`team_id` via plain `constrained()` (RESTRICT — **no** `cascadeOnDelete`),
  `url` string, `enum('http_method',['POST','PUT'])`, `timestamps()` + `softDeletes()`.
  Model `Destination`: `SoftDeletes`, `http_method`→`HttpMethod` cast, `proxy()` `BelongsTo`.
  `DestinationFactory` (https url, POST default, team_id derived from parent proxy, `trashed()`).
  Test `DestinationTest` (5 passed, 8 assertions): `http_method` cast, `proxy` relation,
  `assertSoftDeleted`, trashed destination excluded from `proxy->destinations` (SoftDeletes scope on
  the relation), and `information_schema.REFERENTIAL_CONSTRAINTS` confirms the FK `DELETE_RULE` is
  not `CASCADE`. Pint green. PHPStan consolidated at T6 (see T4 note).

## T6 — `delivery_attempts` table + `DeliveryAttempt` model (payload-free)
- **Description:** Migration and model per plan §Data Model → `delivery_attempts` and ADR-003.
  Columns: `id`, `team_id` (FK), `proxy_id` (FK), `destination_id` (FK), `ingest_id` (uuid,
  indexed), `status` enum(`dispatched`,`succeeded`,`failed`), `http_status` (smallint, nullable),
  `error_summary` (string(250), nullable), `attempt_number` (int default 1), `started_at`,
  `duration_ms` (int, nullable), timestamps. Indexes: `(team_id, created_at)`, `(proxy_id,
  status)`, `(ingest_id)`. **No payload/body column and no `deleted_at`** (always retained,
  never soft-deleted). Model: `status`→`AttemptStatus` cast, `proxy()`/`destination()` relations,
  factory. Team global scope added in T7.
- **Dependencies:** T4, T5
- **Files:** `database/migrations/*_create_delivery_attempts_table.php`,
  `app/Models/DeliveryAttempt.php`, `database/factories/DeliveryAttemptFactory.php`
- **Acceptance Criteria:** migration applies cleanly; the three indexes exist; **no** body/payload
  column and **no** `deleted_at` column exist (assert by schema); `status` casts to
  `AttemptStatus`.
- **Testing:** unit/schema test asserting the payload-free/`deleted_at`-free column set, the three
  indexes, and the `status` cast.
- **Completion notes:** Done. Migration `2026_07_30_000003_create_delivery_attempts_table.php`:
  `team_id`/`proxy_id`/`destination_id` FKs, `uuid('ingest_id')`, `enum('status',[dispatched,
  succeeded,failed])`, nullable `smallInteger http_status`, `string('error_summary',250)` nullable,
  `attempt_number` default 1, `started_at`, nullable `duration_ms`, timestamps. Indexes
  `(team_id,created_at)`, `(proxy_id,status)`, `(ingest_id)`. **No** payload/body column and **no**
  `deleted_at`. Model `DeliveryAttempt`: `status`→`AttemptStatus`, `started_at`→datetime,
  `proxy()`/`destination()` relations, **no** SoftDeletes. `DeliveryAttemptFactory` with
  `succeeded()`/`failed()` states. Test `DeliveryAttemptTest` (3 passed, 9 assertions):
  `status` cast, `Schema::getColumnListing` proves absence of `deleted_at`/`payload`/`body`/…,
  `information_schema.STATISTICS` proves the three indexes. **Consolidated gate (all three models
  now exist):** Pint green, PHPStan L7 green (0 errors — fixed a larastan `findOrFail`
  `Model|Collection` inference in the two factories by switching to `whereKey(...)->firstOrFail()`),
  full `tests/Unit/Models` suite green (13 passed, 28 assertions). Note: PHPStan analyses
  `app/bootstrap/config/database/routes` (not `tests/`) per `phpstan.neon`.

## T7 — Team global scope, `team_id` auto-assign, and `ProxyPolicy` (AC5/AC6/AC15/AC16e)
- **Description:** A single team-scoping mechanism per plan §Services → Team scoping. Add a global
  scope (e.g. a `TeamScope` / `BelongsToCurrentTeam` trait) to `Proxy`, `Destination`,
  `DeliveryAttempt` that filters `team_id = auth()->user()?->current_team_id`, and auto-sets
  (**Senior Developer: confirm the `current_team_id` / `EnsureTeamMembership` / `{current_team}`
  binding against the installed kit before building — Owner decision 2026-07-30; see intro note &
  Flagged gap #4**)
  `team_id` (and `Destination`/`DeliveryAttempt` denormalized `team_id`) on create. Add a
  `ProxyPolicy` expressing `view`/`update`/`delete` against proxy **actions** (leaving the #2
  roles seam open — not a hard-wired role set). Register the policy. **Do not** scope the ingest
  path (T17 strips only this team scope).
- **Dependencies:** T4, T5, T6
- **Files:** `app/Models/Scopes/*` or `app/Concerns/*` (team scope + `team_id` auto-set),
  `app/Policies/ProxyPolicy.php`, `app/Providers/AppServiceProvider.php` (policy registration),
  `app/Models/{Proxy,Destination,DeliveryAttempt}.php` (apply the trait/scope)
- **Acceptance Criteria:** with an authenticated user, model queries return only rows whose
  `team_id === current_team_id`; creating a model auto-sets `team_id` from the current team;
  another team's rows are invisible to default queries; `ProxyPolicy` allows an owning-team member
  and denies a non-member for view/update/delete.
- **Testing:** feature tests — user A cannot see user B's proxies via the scoped query; created
  proxy/destination/attempt carry the actor's `current_team_id`; `ProxyPolicy` allow/deny cases.
- **Completion notes:** Done. **Team-scope binding CONFIRMED against the installed kit** (Owner
  decision / Flagged gap #4): `User` uses `App\Concerns\HasTeams`; `current_team_id` column +
  `currentTeam()` relation exist; `EnsureTeamMembership` middleware switches `current_team_id` when
  the `{current_team}` route param is present; `routes/web.php` nests `dashboard` under
  `Route::prefix('{current_team}')->middleware(['auth','verified',EnsureTeamMembership::class])`.
  The plan's kit-inspected binding matches reality exactly — no deviation needed.
  Implemented: `app/Models/Scopes/TeamScope.php` (class-based global scope filtering
  `team_id = Auth::user()->current_team_id`, applied only when authenticated so console/ingest are
  unconstrained; removable via `withoutGlobalScope(TeamScope::class)` keeping SoftDeletes);
  `app/Concerns/BelongsToCurrentTeam.php` (registers the scope + `creating` hook auto-setting
  `team_id`), applied to `Proxy`/`Destination`/`DeliveryAttempt`. `app/Policies/ProxyPolicy.php`
  (view/update/delete against team membership — roles seam #2 left open), registered via
  `Gate::policy(Proxy::class, ProxyPolicy::class)` in `AppServiceProvider::boot`. Factories updated
  to strip `TeamScope` when resolving a parent proxy's `team_id`. Test `TeamScopingTest` (5 passed,
  13 assertions): scoped read hides other team's proxies, auto-assign on create for
  proxy/destination/attempt, policy allow owner / deny outsider for all three abilities, and Gate
  registration. Pint + PHPStan L7 (0 errors; `TeamScope` typed `@implements Scope<TModel>` with
  `Builder<covariant TModel>` to match the framework interface) + **full suite green (113 passed,
  372 assertions)**.

## T8 — `IngestTokenService` + `Proxy::ingestUrl()` accessor (AC12a/b/d)
- **Description:** Per plan §Services → `IngestTokenService` and ingest URL builder.
  `generate()` returns a URL-safe 32-byte (256-bit) CSPRNG token (`random_bytes` → base64url);
  a method sets `ingest_token` (encrypted cast) + `ingest_token_hash = hash('sha256', $token,
  binary: true)` on a `Proxy`, regenerating on the (astronomically unlikely) unique-index
  collision. A `rotate()` method exists on the model/service (no UI). Add
  `Proxy::ingestUrl()` = `rtrim(config('ingest.url'),'/').'/ingest/'.$token` from the **decrypted**
  token — host from config, never the request `Host` header. Keep the plaintext token out of logs.
- **Dependencies:** T2, T4
- **Files:** `app/Services/IngestTokenService.php`, `app/Models/Proxy.php` (accessor + `rotate`)
- **Acceptance Criteria:** generated tokens are 256-bit and URL-safe; the hash is `BINARY(32)`
  SHA-256 of the plaintext; `ingest_token` decrypts for display (AC12d); two generations never
  collide in practice and the collision path regenerates (AC12a); the token embeds no team/proxy
  id (AC12b); `ingestUrl()` uses `config('ingest.url')`, not the request host.
- **Testing:** unit tests — token length/entropy and URL-safety; encrypted-store + hash-set +
  decrypt round-trip; `ingestUrl()` built from config; simulated hash-collision regenerates
  (mock/force a duplicate hash once).
- **Completion notes:** Done. `app/Services/IngestTokenService.php`: `generate()` →
  `rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')` (256-bit URL-safe base64url,
  unpadded); `hash()` → `hash('sha256',$t,binary:true)`; `assignTo(Proxy)` sets encrypted
  `ingest_token` + binary `ingest_token_hash` using a collision-free token; `rotate(Proxy)` assigns
  + saves (no UI). Collision check `hashExists()` strips `TeamScope` **and** uses `withTrashed()`
  (the UNIQUE index spans all teams + soft-deleted rows). `Proxy::ingestUrl()` =
  `rtrim((string) config('ingest.url'),'/').'/ingest/'.$this->ingest_token` (config host, decrypted
  token, never request `Host`); `Proxy::rotateIngestToken()` delegates to the service. Plaintext
  token never logged. Test `IngestTokenServiceTest` (5 passed, 10 assertions): token is 32-byte
  URL-safe, two generations differ, encrypted-store + binary-hash + decrypt round-trip, `ingestUrl()`
  from config, and a forced hash collision (Mockery partial mock of `generate()`) regenerates to a
  fresh token. Pint + PHPStan L7 green.

## T9 — `PipelineContext` envelope + first-party `PipelineStep` interface (ADR-001)
- **Description:** Build the in-memory envelope and the first-party pipe contract per foundational
  Appendix A §1: `PipelineContext` (readonly `ingestId`, `proxy`, `method`, `headers`, `rawBody`;
  mutable `payload` starting == `rawBody`) and `PipelineStep { handle(PipelineContext, Closure
  $next): PipelineContext }`. The interface is ours (steps also use `AsObject`).
- **Dependencies:** T1, T4
- **Files:** `app/Pipeline/PipelineContext.php`, `app/Pipeline/PipelineStep.php`
- **Acceptance Criteria:** `PipelineContext` holds the raw inputs immutably and a mutable
  `payload` initialized to `rawBody`; `PipelineStep` has the exact middleware-shaped signature;
  PHPStan L1 green.
- **Testing:** unit test constructing a `PipelineContext` and asserting `payload === rawBody` at
  construction and that raw fields are readonly.
- **Completion notes:** Done. `app/Pipeline/PipelineContext.php` (final): readonly `ingestId`,
  `proxy`, `method`, `headers`, `rawBody`; mutable `public string $payload` set to `$payload ??
  $rawBody` in the constructor so it defaults to the raw body (overridable). `headers` typed
  `array<string, list<string|null>>` (matches `$request->headers->all()`).
  `app/Pipeline/PipelineStep.php`: `handle(PipelineContext $ctx, Closure $next): PipelineContext`
  with `@param Closure(PipelineContext): PipelineContext $next` — the middleware-shaped first-party
  contract. Test `PipelineContextTest` (3 passed, 10 assertions): payload === rawBody at
  construction, all five raw fields readonly via reflection (payload not readonly), payload override
  + mutation. Pint + PHPStan L7 green.

## T10 — `DeliveryUnit` DTO + `forwardHeaders()` allowlist (ADR-008, AC8)
- **Description:** Per Appendix A §4 and ADR-008. `DeliveryUnit` (readonly `ingestId`, `teamId`,
  `proxyId`, `destination`, `method`, `headers`, `payload`, `attemptNumber`). `forwardHeaders()`
  returns the outbound header set: **forward all inbound headers except a maintained stripped
  constant** — `Host`; hop-by-hop (`Connection`, `Keep-Alive`, `Proxy-Authenticate`,
  `Proxy-Authorization`, `TE`, `Trailer`, `Transfer-Encoding`, `Upgrade`) + `Content-Length`;
  `Cookie`; inbound `Authorization`; and inbound webhook signature/verification headers
  (`Stripe-Signature`, `X-Hub-Signature`, `X-Hub-Signature-256`, `X-Signature`,
  `X-Webhook-Signature`, and equivalents). **`Content-Type` is preserved.** Matching is
  **case-insensitive**. The strip list is a maintained constant (extensible for #10). No header is
  added.
- **Dependencies:** T5
- **Files:** `app/Pipeline/DeliveryUnit.php` (or `app/Delivery/DeliveryUnit.php`)
- **Acceptance Criteria:** given inbound headers containing `Content-Type`, a custom `X-*` header,
  and the full stripped set, `forwardHeaders()` **includes** `Content-Type` + the custom header
  and **omits** every stripped header regardless of header-name casing.
- **Testing:** unit test asserting exactly the forward/strip partition above, including a
  mixed-case variant of each stripped header.
- **Completion notes:** Done. `app/Pipeline/DeliveryUnit.php` (final, readonly `ingestId`, `teamId`,
  `proxyId`, `destination`, `method`, `headers`, `payload`, `attemptNumber`). Maintained
  `const STRIPPED_HEADERS` (lowercased list): `host`, hop-by-hop
  (connection/keep-alive/proxy-authenticate/proxy-authorization/te/trailer/transfer-encoding/upgrade)
  + `content-length`, `cookie`, `authorization`, and the ADR-008 webhook signature headers
  (stripe-signature, x-hub-signature, x-hub-signature-256, x-signature, x-webhook-signature).
  `forwardHeaders()` = `array_filter(..., ARRAY_FILTER_USE_KEY)` keeping headers whose lowercased
  name is not in the deny-list — `Content-Type` preserved, no header added, case-insensitive.
  Test `DeliveryUnitTest` (2 passed, 21 assertions): mixed-case variant of every stripped header is
  removed, Content-Type + a custom X- header forwarded, exactly-two-remain, and no-header-added.
  Pint + PHPStan L7 green.

## T11 — Delivery domain events (ADR-003)
- **Description:** Three events per Appendix A §5: `DeliveryAttempted`, `DeliverySucceeded`,
  `DeliveryFailed`, each carrying the `DeliveryAttempt`.
- **Dependencies:** T6
- **Files:** `app/Events/DeliveryAttempted.php`, `app/Events/DeliverySucceeded.php`,
  `app/Events/DeliveryFailed.php`
- **Acceptance Criteria:** each event constructs from a `DeliveryAttempt` and exposes it; no
  listeners are registered at #1 (seam only).
- **Testing:** covered behaviorally by T12 via `Event::fake()` assertions.
- **Completion notes:** Done. `app/Events/{DeliveryAttempted,DeliverySucceeded,DeliveryFailed}.php`
  — each uses `Dispatchable` and constructs from `public readonly DeliveryAttempt $attempt`. No
  listeners registered at #1 (pure seams). Behavioral coverage lands in T12/T18 via `Event::fake()`.
  Pint + PHPStan L7 green.

## T12 — `DeliverToDestination` action (delivery-level, sync `::run`) (AC13/AC14, ADR-003)
- **Description:** Per Appendix A §5 and plan §Services. `AsAction`. `handle(DeliveryUnit)`:
  write the **`dispatched`** `DeliveryAttempt` **before** the HTTP call (crash safety), emit
  `DeliveryAttempted`; perform the HTTP `POST`/`PUT` via Laravel `Http` with a timeout and the
  ADR-008 `forwardHeaders()`; on completion update `status` succeeded/failed + `http_status` +
  `duration_ms` and emit `DeliverySucceeded`/`DeliveryFailed`; `catch (\Throwable)` → `failed` +
  truncated `error_summary` (≤250, **no payload**) + `DeliveryFailed`. Invoked `::run` only at #1
  (no `::dispatch`/job config).
- **Dependencies:** T6, T10, T11
- **Files:** `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:** exactly one `DeliveryAttempt` per invocation; a `dispatched` row exists
  before the outcome is written; a 2xx → `succeeded` + `http_status`; a non-2xx → `failed` +
  `http_status`; a thrown transport error → `failed` + truncated `error_summary` and **no**
  payload/body persisted anywhere; the correct event fires for each outcome.
- **Testing:** feature/unit with `Http::fake()` + `Event::fake()` — success, non-2xx, and
  thrown-exception paths; assert the `dispatched`-before-outcome ordering, the field values, the
  events, and that `error_summary` is truncated and carries no body.
- **Completion notes:** Done. `app/Actions/DeliverToDestination.php` (`AsAction`): writes the
  `dispatched` `DeliveryAttempt` (payload-free) **before** the HTTP call and emits
  `DeliveryAttempted`; sends via `Http::withHeaders($unit->forwardHeaders())->timeout(15)
  ->send($method,$url,['body'=>$payload])`; on completion updates `status`
  (succeeded/failed by `$response->successful()`) + `http_status` + `duration_ms` and emits
  `DeliverySucceeded`/`DeliveryFailed`; `catch (Throwable)` → `failed` + `Str::limit($msg, 247)`
  (247+'...' = 250, fitting `string(250)`, no payload) + `DeliveryFailed`. Invoked `::run` only.
  Test `DeliverToDestinationTest` (4 passed, 21 assertions): 2xx→succeeded+200 (attempted+succeeded,
  not failed), 500→failed+500 (attempted+failed), thrown `ConnectionException`→failed, null
  http_status, error_summary ≤250 and not containing the 400-char payload; and a Http::fake closure
  proves a `dispatched` row exists during the call with exactly one attempt persisted. Pint +
  PHPStan L7 green.

## T13 — `DeliverStep` fan-out (terminal pipe) (AC7/AC9/AC10)
- **Description:** Per Appendix A §3. `AsObject`, implements `PipelineStep`. Iterate the proxy's
  **live** destinations, build a `DeliveryUnit` per destination (method = destination's
  `http_method`, headers/payload from context, `attemptNumber` 1), call
  `DeliverToDestination::run($unit)`, then `return $next($ctx)`. One destination failing does not
  abort the loop (AC9). Reads only `$ctx->payload` (never mutates it).
- **Dependencies:** T9, T10, T12
- **Files:** `app/Actions/DeliverStep.php` (or `app/Pipeline/DeliverStep.php`)
- **Acceptance Criteria:** iterates live destinations only (trashed excluded); dispatches one
  `DeliverToDestination` per live destination with that destination's method; a throwing/failing
  destination does not prevent the others; returns `$next($ctx)`.
- **Testing:** unit — `DeliverStep::make()->handle($ctx, fn ($c) => $c)` over a proxy with N live
  destinations (+ a trashed one that must be skipped); with one destination faked to fail, assert
  the rest still receive their call.
- **Completion notes:** Done. `app/Actions/DeliverStep.php` (`AsObject`, implements `PipelineStep`):
  iterates `$ctx->proxy->destinations` (live-only via SoftDeletes scope), builds a `DeliveryUnit`
  per destination (`method = $destination->http_method->value`, headers/payload from context,
  `attemptNumber` 1), calls `DeliverToDestination::run($unit)`, then `return $next($ctx)`. Reads
  only `$ctx->payload`. AC9 independence is provided by DeliverToDestination's internal Throwable
  catch (the step stays thin, matching the Appendix A reference). Test `DeliverStepTest` (2 passed,
  9 assertions): 2 live + 1 trashed → exactly 2 attempts (none for trashed), each destination's own
  method on the wire (POST/PUT via `Http::assertSent`), returns the same `$ctx`; and one destination
  faked to throw does not prevent the other's delivery. Pint + PHPStan L7 green.

## T14 — `PipelineFactory::stepsFor()` (ADR-001/002)
- **Description:** Per Appendix A §2. `stepsFor(Proxy): PipelineStep[]` returns exactly
  `[DeliverStep::make()]` for **both** `simple` and `enhanced` modes at #1. Enhanced branches are
  the commented insertion contract only — not built.
- **Dependencies:** T13
- **Files:** `app/Pipeline/PipelineFactory.php`
- **Acceptance Criteria:** returns exactly one step (`DeliverStep`) for a `simple` proxy and the
  identical single-step list for an `enhanced` proxy.
- **Testing:** unit test asserting `[DeliverStep::class]` for both modes.
- **Completion notes:** Done. `app/Pipeline/PipelineFactory.php`: `stepsFor(Proxy): list<PipelineStep>`
  returns exactly `[DeliverStep::make()]` for both `simple` and `enhanced`. Enhanced front/tail
  stages are commented insertion-contract stubs only (VerifyStep/NormalizeStep/CaptureRawStep/
  MapStep/CaptureDispatchedStep/ChangeDetectStep) — nothing built. Test `PipelineFactoryTest`
  (2 passed, 4 assertions): simple and enhanced each yield a single `DeliverStep`. Pint + PHPStan
  L7 green.

## T15 — `ProcessIngestedWebhook` action (pipeline-level, sync `::run`)
- **Description:** Per Appendix A §4(a). `AsAction`. `handle(PipelineContext)` drives the native
  `Illuminate\Pipeline\Pipeline` — `->send($ctx)->through($factory->stepsFor($ctx->proxy))
  ->thenReturn()`. Invoked `::run` only at #1 (no `::dispatch`/job config).
- **Dependencies:** T9, T14
- **Files:** `app/Actions/ProcessIngestedWebhook.php`
- **Acceptance Criteria:** running it over a context executes `DeliverStep` for the proxy; no job
  is queued; no `configureJob`/`onQueue`/middleware present.
- **Testing:** feature/unit — `ProcessIngestedWebhook::run($ctx)` with `Http::fake()` results in
  one delivery per live destination (thin integration over T13/T14).
- **Completion notes:** Done. `app/Actions/ProcessIngestedWebhook.php` (`AsAction`): constructor
  injects `PipelineFactory`; `handle(PipelineContext)` drives the native
  `app(Illuminate\Pipeline\Pipeline::class)->send($ctx)->through($factory->stepsFor($ctx->proxy))
  ->thenReturn()`. Invoked `::run` only — **no** `configureJob`/`onQueue`/`getJobMiddleware`.
  Test `ProcessIngestedWebhookTest` (1 passed, 2 assertions): over a proxy with 3 live + 1 trashed
  destination, `::run` yields 3 delivery attempts and `Http::assertSentCount(3)`. Pint + PHPStan
  L7 green.

## T16 — `ResponseResolver` (202) (ADR-004)
- **Description:** Per Appendix A §6. `resolve(Proxy): Response` returns `202 Accepted` with a
  minimal body, resolved before/independent of delivery. No proxy columns read at #1 (that is #3).
- **Dependencies:** T4
- **Files:** `app/Services/ResponseResolver.php`
- **Acceptance Criteria:** returns HTTP `202` for any proxy, independent of any delivery outcome.
- **Testing:** unit test asserting a `202` response.
- **Completion notes:** Done. `app/Services/ResponseResolver.php`: `resolve(Proxy): Response` returns
  `new Response('', 202)` — minimal body, resolved independent of delivery; no proxy columns read
  at #1 (the #3 body is a commented seam). Test `ResponseResolverTest` (1 passed): asserts status
  202 for any proxy. Pint + PHPStan L7 green.

## T17 — Ingest route + `IngestController` resolution (AC12c, ADR-004/006)
- **Description:** Per plan §API → Ingest. Register `Route::match(['post','put'],
  '/ingest/{token}', IngestController::class)->name('ingest')` **outside** the `web`/session
  group — no session auth, **CSRF-exempt**, with a **thin app-layer HTTPS assert** (middleware on
  the ingest route), a **per-token throttle**, and a **body-size cap**. **HTTPS-only invariant
  (incoming), Owner security decision 2026-07-30 (recorded in PRD-01):** non-HTTPS ingest requests
  are rejected. Enforcement is **defense-in-depth on both layers** — edge termination/redirect at
  the LB/Forge (ops-level, out of app scope) **and** this app-layer middleware asserting
  `$request->isSecure()`, rejecting non-HTTPS requests (do not silently accept them). The
  throttle and body-size cap are **config-driven** with **deliberately very high placeholder
  defaults** (see below; Owner decision 2026-07-30) — provisional, not risk-tuned. Controller:
  resolve the proxy by
  `where('ingest_token_hash', hash('sha256', $token, binary:true))->first()` on a query that
  **strips only the team global scope, keeping the `SoftDeletes` scope** (do **not** use
  `withTrashed()`); `abort_if(null, 404)` (no existence disclosure); build the `PipelineContext`
  (uuid `ingestId`, method, headers, rawBody, payload = rawBody); `ResponseResolver::resolve`;
  `ProcessIngestedWebhook::run($ctx)`; return the response. Scrub the token from any request
  logging of this path.
- **Config (provisional placeholders — revisit before MVP/public exposure):** introduce
  `config('ingest.max_body_bytes')` (env `INGEST_MAX_BODY_BYTES`) and
  `config('ingest.rate_limit_per_minute')` (env `INGEST_RATE_LIMIT_PER_MINUTE`) in `config/ingest.php`,
  each defaulting to a **deliberately very high placeholder value** documented inline as
  "placeholder — revisit before MVP/public exposure" (not a low/tuned number). The per-token
  throttle keys on the resolved token/proxy.
- **Dependencies:** T8, T15, T16
- **Files:** `routes/web.php` (or a dedicated `routes/ingest.php` registered in `bootstrap/app.php`),
  `app/Http/Controllers/IngestController.php`, `app/Http/Middleware/*` (app-layer HTTPS assert),
  `config/ingest.php` (body-size + rate-limit keys), `.env.example`, throttle config
- **Acceptance Criteria:** a valid token returns `202`; an unknown/invalid token returns `404`
  with no body distinguishing "no such proxy" from other misses (AC12c); a **soft-deleted** proxy's
  token returns `404` and no longer ingests (team scope stripped, soft-delete scope kept); the
  route is CSRF-exempt and requires no session; a spoofed `Host` header does not affect resolution;
  a **non-HTTPS ingest request is rejected** by the app-layer HTTPS assert; the body-size and
  rate-limit config keys exist with the documented high placeholder defaults.
- **Testing:** feature tests — 202 on valid token; 404 on unknown token; 404 on soft-deleted
  proxy's token; CSRF-exempt POST/PUT succeed without a session; **a non-HTTPS (insecure) ingest
  request is rejected** (e.g. simulate `$request->isSecure() === false`); config test asserting the
  body-size + rate-limit keys resolve to their placeholder defaults.
- **Completion notes:** Done. Route `Route::match(['post','put'],'/ingest/{token}',
  IngestController::class)->name('ingest')` in new `routes/ingest.php`, registered via the
  `withRouting(then: …)` closure in `bootstrap/app.php` as `Route::group([], routes/ingest.php)` —
  **outside** the web group (no session, CSRF-exempt). Middleware stack: `EnsureIngestIsSecure`
  (app-layer `abort_if(! $request->isSecure(), 403)` HTTPS assert — defense-in-depth with edge TLS;
  note: needs trusted-proxy + X-Forwarded-Proto behind a LB), `EnforceIngestBodyLimit`
  (413 over `config('ingest.max_body_bytes')`), and `throttle:ingest`. Per-token `RateLimiter::for('ingest')`
  registered in `AppServiceProvider` keyed by `hash('sha256',$token)` (plaintext token never in a
  cache key), limit `config('ingest.rate_limit_per_minute')`. `config/ingest.php` adds
  `max_body_bytes` (50 MB) + `rate_limit_per_minute` (6000) high placeholders (+ `.env.example`).
  `IngestController::__invoke`: resolves via `Proxy::withoutGlobalScope(TeamScope::class)
  ->where('ingest_token_hash', hash('sha256',$token,binary:true))->first()` (keeps SoftDeletes — no
  `withTrashed()`), `abort_if(null,404)`; builds `PipelineContext` (uuid, method, headers, rawBody,
  payload=rawBody); `ResponseResolver::resolve`; `ProcessIngestedWebhook::run`; returns the 202.
  Token never logged. Also made `ProxyFactory` tokens URL-safe base64url (matches production) so
  route path matching is reliable. Test `IngestControllerTest` (7 passed): 202 on valid token,
  CSRF-less PUT 202, 404 unknown, 404 + nothing-sent for soft-deleted proxy, spoofed `Host` still
  202, non-HTTPS rejected 403 + nothing-sent, and body/rate config defaults. **Full suite green
  (140 passed, 460 assertions).** Pint + PHPStan L7 green.

## T18 — Ingest fan-out acceptance tests (AC7–AC11, AC13–AC15, ADR-003/004/008)
- **Description:** End-to-end feature tests over the wired ingest path (the AC acceptance harness
  for the ingest surface). No new production code; if a test reveals a wiring gap, fix it in the
  owning task.
- **Dependencies:** T17
- **Files:** `tests/Feature/Ingest/*` (Pest)
- **Acceptance Criteria (each an assertion):**
  - Posting a valid token fans out **one** HTTP request **per live destination** with that
    destination's method and the received body unchanged (AC7/AC8/AC10).
  - Header forwarding end-to-end (ADR-008): inbound `Content-Type` + a custom header are
    **forwarded**; `Host`, `Cookie`, `Authorization`, a hop-by-hop header, and a signature header
    are **omitted** at the destination.
  - Independent failure: one destination faked to 500/throw; the others still receive their
    request and the ingest response is still `202` (AC9, ADR-004).
  - Response is `202` regardless of delivery outcome (AC7/AC11, ADR-004).
  - Exactly one `DeliveryAttempt` per destination (AC13); success → `succeeded` + `http_status`;
    failure/exception → `failed` + `error_summary`; records carry `proxy_id`/`destination_id`/
    `ingest_id` and there is **no payload column/body** (AC14/AC15); the `dispatched` row exists
    before the outcome (ADR-003). Events asserted via `Event::fake()`.
  - Simple mode stores no payload (no payload table exists) and delivers the body unchanged (AC11).
- **Testing:** the above, using `Http::fake()` + `Event::fake()`.
- **Completion notes:** Done. `tests/Feature/Ingest/IngestFanOutTest.php` (7 passed, 36 assertions),
  end-to-end over the wired ingest path (no new production code). Covers: one request per **live**
  destination with the destination's own method and **body unchanged** (trashed excluded); header
  forwarding (Content-Type + custom X- forwarded; Cookie/Authorization/Connection/Stripe-Signature
  stripped); independent failure (one destination throws, the other still delivers, response still
  202); exactly one `DeliveryAttempt` per destination with succeeded+`http_status`,
  proxy_id/destination_id/ingest_id set, single shared `ingest_id`, no `payload` column and no
  `webhook_payloads` table; 500 → failed+http_status; 202 even when all deliveries 503; simple mode
  delivers unchanged and stores no payload. Events asserted via `Event::fake()`. **Wiring note:** a
  test-harness gotcha surfaced — raw `$this->call()` does not apply `withHeaders()` defaults, so the
  helper uses `transformHeadersToServerVars()` to inject inbound headers while preserving the exact
  raw body; no production wiring gap was found. Pint + PHPStan L7 green.

## T19 — `StoreProxyRequest` + `UpdateProxyRequest` (Validation; AC2/AC3/AC16b)
- **Description:** Per plan §Validation. Server-authoritative FormRequests with the **confirmed
  error-bag keys**: `name` (required, string, max), `mode` (required, `in:simple,enhanced`),
  `destinations` (required array, **`min:1`**), `destinations.*.url` (required, valid **absolute
  `https://` URL — HTTPS-only invariant: reject any non-`https://` scheme, e.g. `http://` or a
  missing scheme**; Owner security decision 2026-07-30, recorded in PRD-01), `destinations.*.http_method`
  (required, `in:POST,PUT`). This HTTPS-only URL rule applies identically on **both** create
  (`StoreProxyRequest`) and edit (`UpdateProxyRequest`, consumed by T23). Authorize via `ProxyPolicy`.
- **Dependencies:** T3, T5
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:** zero destinations rejected on both create and update with a
  `destinations` error; a non-`https://` URL — including `http://…` and a scheme-less/malformed URL —
  is rejected under `destinations.{i}.url` on both create and update, while a valid `https://…` URL
  is accepted; a method other than POST/PUT rejected under `destinations.{i}.http_method`; missing
  name rejected under `name`; the exact keys `name`, `mode`, `destinations`, `destinations.{i}.url`,
  `destinations.{i}.http_method` are used.
- **Testing:** feature tests asserting each rule and the exact error-bag keys (the keys the Vue
  form renders against), including explicit HTTPS-only cases on both Store and Update: **invalid**
  `http://example.com/hook` (rejected under `destinations.{i}.url`) and **valid**
  `https://example.com/hook` (accepted).
- **Completion notes:** Done. `app/Http/Requests/StoreProxyRequest.php` +
  `UpdateProxyRequest.php`, identical rules: `name` required|string|max:255; `mode`
  required|`in:simple,enhanced`; `destinations` required|array|**min:1**; `destinations.*.url`
  required|string|**`url:https`** (Laravel URL rule restricted to the https scheme — rejects
  `http://` and scheme-less/malformed, accepts `https://`); `destinations.*.http_method`
  required|`in:POST,PUT`. Update also allows optional `destinations.*.id` (keys reconciliation).
  Authorization: Store → `can('create', Proxy::class)`; Update → `can('update', $route('proxy'))`
  via `ProxyPolicy`. Exact error-bag keys are `name`/`mode`/`destinations`/`destinations.{i}.url`/
  `destinations.{i}.http_method`. Test `ProxyRequestValidationTest` (16 passed — 8 cases ×
  Store/Update via `#[DataProvider]`): valid passes, zero destinations → `destinations`, `http://`
  and scheme-less → `destinations.0.url`, valid https accepted, bad method → `destinations.0.http_method`,
  missing name → `name`, bad mode → `mode`. (PHPUnit 12 needs the `#[DataProvider]` attribute, not
  the `@dataProvider` annotation.) Pint + PHPStan L7 green.

## T20 — Management routes (team-scoped resource + destination destroy)
- **Description:** Per plan §API → Management. Register inside the existing `{current_team}` prefix
  group (`['auth','verified',EnsureTeamMembership::class]`, mirroring `dashboard`; **Senior
  Developer: confirm this prefix/middleware against the installed kit before building — Owner
  decision 2026-07-30, see Flagged gap #4**):
  `Route::resource('proxies', ProxyController::class)` and
  `DELETE proxies/{proxy}/destinations/{destination}` →
  `DestinationController@destroy` (name `proxies.destinations.destroy`). Route-model binding for
  `{proxy}`/`{destination}` resolves **through the team global scope** (cross-team id → 404).
- **Dependencies:** T7
- **Files:** `routes/web.php`
- **Acceptance Criteria:** all eight management endpoints exist under `{current_team}` and require
  auth + membership; binding a cross-team `{proxy}`/`{destination}` yields 404; guests are
  redirected to login (AC6).
- **Testing:** covered by T21–T25 (guest redirect + cross-team 404 assertions there).
- **Completion notes:** Done. `routes/web.php`: inside the **confirmed** `{current_team}` prefix
  group (`['auth','verified',EnsureTeamMembership::class]`, mirroring `dashboard`) added
  `Route::resource('proxies', ProxyController::class)` (8 REST endpoints) and
  `DELETE proxies/{proxy}/destinations/{destination}` → `[DestinationController::class,'destroy']`
  named `proxies.destinations.destroy` with `->scopeBindings()` so `{destination}` must belong to
  `{proxy}` (and be live). Implicit route-model binding applies the team `TeamScope`, so a
  cross-team `{proxy}`/`{destination}` id 404s. Full suite green (163 passed) — routes register
  lazily. **PHPStan note:** `routes/web.php` forward-references `ProxyController` (created T21) and
  `DestinationController` (created T25); those two `class.notFound` errors clear as each controller
  lands, and the consolidated PHPStan L7 gate is run green at T25 (same forward-reference pattern
  as T4). Guest-redirect + cross-team-404 assertions live in T21–T25.

## T21 — `ProxyController` index / create / show (AC4/AC12d, AC5/AC6)
- **Description:** Per plan §API → Inertia responses. `index` (paginated `Proxies/Index` with
  `{ id, name, mode, ingest_url }` per row), `create` (`Proxies/Create`), `show`
  (`Proxies/Show` with `{ proxy:{ id, name, mode, ingest_url, destinations:[{id,url,http_method}] }}`).
  `ingest_url` is built **server-side** via `Proxy::ingestUrl()` (config host, decrypted token) —
  never the request `Host`. Keep tokens out of logging/prop capture.
- **Dependencies:** T8, T20
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** index lists only the current team's proxies, paginated, each with its
  full `ingest_url` (AC4/AC12d); show returns the proxy's ingest URL + destinations + mode;
  cross-team `show` → 404 (AC5/AC16e); guests redirected (AC6); a request with a spoofed `Host`
  header still yields the config-based ingest URL.
- **Testing:** feature/Inertia tests — team-scoped index; cross-team show 404; guest redirect;
  spoofed-`Host` asserts config host is used (AC4/AC12d).
- **Completion notes:** Done. `app/Http/Controllers/ProxyController.php` `index`/`create`/`show`:
  `index` renders `proxies/Index` with `paginate(15)->through()` rows `{id,name,mode,ingest_url}`
  (team-scoped via global scope); `create` renders `proxies/Create`; `show` renders `proxies/Show`
  with `{proxy:{id,name,mode,ingest_url,destinations:[{id,url,http_method}]}}`. `ingest_url` built
  server-side via `Proxy::ingestUrl()` (config host). `Gate::authorize` viewAny/create/view.
  Component names use the lowercase `proxies/` dir to match the Vue file paths (T27-T29) and the kit
  convention (`teams/Index`). Test `ProxyIndexShowTest` (5 passed, 41 assertions): team-scoped index
  with ingest_url, show with mode+destinations, cross-team show 404, guest redirect, and
  config-host ingest_url under a spoofed `Host`.
  **Two implementation findings (local-detail authority):** (1) **Route-model binding under the
  `{current_team}` prefix** — a leading non-model route param (`{current_team}`) misaligns Laravel's
  implicit binding of `{proxy}` (controller receives the slug string → TypeError). Verified via
  isolation tests; the fix is to declare the leading `string $current_team` param before the bound
  model (`show(string $current_team, Proxy $proxy)`). Applied here and to T22-T25 bound methods.
  (2) **Inertia page-existence in backend-first tests** — the Vue pages don't exist until T27-T29,
  so these tests set `config('inertia.testing.ensure_pages_exist', false)` to assert props/components
  without a built frontend (standard Inertia backend-testing approach). Pint green. **PHPStan:**
  `ProxyController` reference in `routes/web.php` now resolves; the remaining `DestinationController`
  `class.notFound` clears at T25 (consolidated green there).

## T22 — `ProxyController@store` (AC1/AC2/AC3/AC12)
- **Description:** Per plan. In a DB transaction: create the proxy (mint token via
  `IngestTokenService`, set `team_id` from current team), create its ≥1 destinations, assert the
  min-1 (**live**) invariant, redirect to `show` with a `Proxy created` flash.
- **Dependencies:** T8, T19, T20
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** creating with ≥1 destination succeeds and yields a distinct ingest URL
  (AC1/AC12a); each created destination's method is constrained to POST/PUT (AC3); zero
  destinations rejected (AC2, via T19); the whole create is transactional.
- **Testing:** feature tests — successful create → distinct ingest URL + destinations persisted +
  flash; two creates yield distinct hashes/URLs (AC12a); zero-destination rejected.
- **Completion notes:** Done. `ProxyController@store(StoreProxyRequest, IngestTokenService)`:
  `DB::transaction` creates the `Proxy` (token minted via `IngestTokenService::assignTo`, `team_id`
  auto-set by the current-team trait), creates each destination via `$proxy->destinations()->create`,
  asserts ≥1 live destination before commit (throws `ValidationException` on `destinations` as a
  belt-and-suspenders to the FormRequest min:1), flashes `toast` (`Inertia::flash`) and
  `to_route('proxies.show')`. Test `ProxyStoreTest` (4 passed, 16 assertions): create persists proxy
  + 2 destinations + team_id + minted hash, redirects to show with `assertInertiaFlash('toast', …)`;
  per-destination POST/PUT persisted; two creates → distinct hashes + URLs (AC12a); zero destinations
  → `assertInvalid(['destinations'])` with no proxy row. (Kit provides the `assertInertiaFlash` test
  macro.) Pint green. PHPStan: only the T25 `DestinationController` forward-ref remains.

## T23 — `ProxyController@edit` + `@update` with destination reconciliation (AC16a/AC16b)
- **Description:** Per plan §API and §Validation. `edit` returns `Proxies/Edit` pre-filled with
  `{ name, mode, destinations:[{id,url,http_method}] }` (live only). `update` runs, in one DB
  transaction: update name/mode; reconcile destinations — add new rows, update existing live rows
  by id, **soft-delete** omitted rows — asserting **≥1 live** destination remains **before
  commit**. Editing does not rotate the token. Redirect to `show` with `Changes saved`.
- **Dependencies:** T19, T20
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** edit pre-fills current values (live destinations only); update
  changes name/mode and adds/updates/soft-deletes destinations correctly; an update that would
  leave zero **live** destinations is rejected (AC16b) and nothing is committed; the ingest URL is
  unchanged after edit; omitted destinations are `assertSoftDeleted`, not hard-deleted.
- **Testing:** feature tests — pre-fill; add/change/remove reconciliation; reject zero-live update;
  ingest URL unchanged; soft-delete (not hard-delete) of removed rows.
- **Completion notes:** _pending_

## T24 — `ProxyController@destroy` (soft-delete cascade + retention) (AC16d)
- **Description:** Per plan (Flow F, Owner ruling 1). In one transaction: **soft-delete** the
  proxy and its **live** destinations; leave `delivery_attempts` untouched (always retained).
  Redirect to `index` with `Proxy deleted`.
- **Dependencies:** T20, T17
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** proxy and its destinations are `assertSoftDeleted` (not hard-deleted)
  and no longer appear in team-scoped index/show; the proxy's token **no longer ingests** (`404`
  via T17); the proxy's existing `delivery_attempts` are **unchanged** (count intact, still
  team-scoped/queryable — AC15); creating a **new** proxy afterward still yields a distinct hash
  (soft-deleted row retains its unique hash slot — no reuse).
- **Testing:** feature tests — `assertSoftDeleted` proxy + destinations; post-delete ingest `404`;
  attempt records retained/unchanged; unique-hash-retained-after-soft-delete.
- **Completion notes:** _pending_

## T25 — `DestinationController@destroy` (single soft-remove, min-1 guard) (AC16c/AC16b)
- **Description:** Per plan §Validation (Flow E). In a DB transaction with a re-count of
  **non-soft-deleted** destinations: **soft-delete** the destination, but reject with a validation
  error / `422` if it is the proxy's **last remaining live** one. Redirect back to `show` with a
  removed flash on success.
- **Dependencies:** T20
- **Files:** `app/Http/Controllers/DestinationController.php`
- **Acceptance Criteria:** removing a non-last destination soft-deletes it (`assertSoftDeleted`)
  and it disappears from `show`; removing the **last live** destination is refused (`422`) and
  nothing changes (AC16b/AC16c); cross-team destination → 404 (AC16e); the re-count counts only
  live rows (guards the concurrent last-two race).
- **Testing:** feature tests — soft-remove non-last; reject last-live `422`; cross-team 404.
- **Completion notes:** _pending_

## T26 — Shared Vue primitives + composites (CopyField, DestinationRows)
- **Description:** Per design §Components ("new composites") and §Accessibility. Add the missing
  shadcn-vue primitives this feature needs but the kit hasn't scaffolded yet — **Table** and
  **AlertDialog** (Badge, Select, Sonner, Dialog, Card, Input, Label already present). Build the
  two new composites: (1) **CopyField** — read-only value + Copy button (Clipboard API), label
  swap to "Copied", `aria-live="polite"` announcement "Ingest URL copied to clipboard", discernible
  button name "Copy ingest URL", selectable text fallback; (2) **DestinationRows** — repeatable
  URL(`type=url`) + Method(Select POST/PUT) + Remove rows in a `fieldset`/legend "Destinations",
  an "Add destination" button, **Remove disabled/hidden when one row remains**, focus moves to the
  new row's URL on add and to a sensible neighbour on remove, `InputError` slot keyed
  `destinations.{i}.url` / `destinations.{i}.http_method`, each Remove named "Remove destination N".
- **Dependencies:** none (frontend primitives; sequence after backend prop shapes are known)
- **Files:** `resources/js/components/ui/table/*`, `resources/js/components/ui/alert-dialog/*`
  (shadcn-vue add), `resources/js/components/CopyField.vue`,
  `resources/js/components/DestinationRows.vue`
- **Acceptance Criteria:** CopyField copies its value and announces via `aria-live`, keyboard
  operable, has a discernible accessible name; DestinationRows enforces ≥1 in the UI (Remove
  disabled at one row), manages focus on add/remove, associates labels/errors via
  `aria-describedby`, and renders array-keyed errors. Fidelity posture respected (kit defaults, no
  bespoke polish).
- **Testing:** component-level assertions for the a11y behaviours above **if** a JS/Vue test
  harness is available; otherwise verify via the Inertia page tests (T27–T29) plus documented
  manual keyboard/screen-reader check (see Flagged gap: no JS component test tooling).
- **Completion notes:** _pending_

## T27 — `Proxies/Index.vue` + nav item (Flow B, AC4)
- **Description:** Per design Screen 1 / Flow B. Paginated Table: Name (links to detail), Mode
  badge, **full ingest URL inline** via CopyField, Actions (View / Edit / Delete). Empty state
  Card ("No proxies yet" + "Create your first proxy"). One table-level secrecy caution line.
  Delete opens the AlertDialog (Flow F, Cancel default focus, focus-trapped, Esc-dismissible).
  Add a **"Proxies"** nav item to the sidebar "Platform" section (icon + label + active state).
- **Dependencies:** T21, T24, T26
- **Files:** `resources/js/pages/proxies/Index.vue`, `resources/js/components/AppSidebar.vue`
  (nav item), `resources/js/types/*` (page prop types if needed)
- **Acceptance Criteria:** renders the paginated list with each row's inline ingest URL + Copy;
  empty state shown with no proxies; Delete flows through a confirming AlertDialog then a Sonner
  flash; nav item routes to the index and shows active state; badges are not the sole carrier of
  meaning.
- **Testing:** Inertia feature assertion (`Proxies/Index` with props) from T21; interaction/a11y
  per T26 note.
- **Completion notes:** _pending_

## T28 — `Proxies/Create.vue` + `Proxies/Edit.vue` shared form (Flows A/D, AC1–AC3/AC16a/AC16b)
- **Description:** Per design Screen 2. One shared form component serving create and edit:
  **Name** (Input+Label), **Mode** selector (Simple default, Enhanced selectable — **no
  "coming soon"/disabled gating**, optional neutral help note), **DestinationRows** (T26), primary
  button "Create proxy" (create) / "Save changes" (edit), Cancel. Pre-filled in edit; breadcrumb
  differs. Renders inline validation errors from Inertia's `errors` bag on the confirmed keys;
  focus moves to the first field in error; `processing` disables the form; success → detail with a
  Sonner flash.
- **Dependencies:** T22, T23, T26
- **Files:** `resources/js/pages/proxies/Create.vue`, `resources/js/pages/proxies/Edit.vue`,
  `resources/js/pages/proxies/ProxyForm.vue` (shared)
- **Acceptance Criteria:** create submits name+mode+≥1 destinations and lands on detail with a
  flash; edit is pre-filled and submits the same shape; per-field/per-row errors render on the
  right keys with focus to the first error; Remove disabled at one row; Enhanced is selectable and
  persists; the ingest URL is not shown/rotated on edit.
- **Testing:** Inertia feature assertions from T22/T23 (prop shapes, redirects, validation errors);
  a11y/interaction per T26 note.
- **Completion notes:** _pending_

## T29 — `Proxies/Show.vue` detail (Flows C/E/F, AC4/AC12d/AC16c/AC16d)
- **Description:** Per design Screen 3. Header: name + Mode badge. **Ingest URL card**: full URL in
  a read-only monospace field + CopyField + secrecy caution line. **Destinations card**: list of
  URL + Method badge with a per-row **Remove** (confirm dialog; **disabled on the last remaining
  destination** with the `aria-describedby` hint "A proxy must keep at least one destination").
  **Actions**: Edit (→ pre-filled form) and Delete (AlertDialog, Flow F). Success flashes via
  Sonner.
- **Dependencies:** T21, T24, T25, T26
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** shows the full server-built ingest URL with Copy (AC4/AC12d);
  per-destination Remove confirms then soft-removes and re-renders with a flash (AC16c); Remove is
  disabled on the last destination with the accessible hint (AC16b); Edit/Delete actions route/flow
  correctly (Delete via focus-trapped AlertDialog defaulting to Cancel); cross-team access is the
  kit 403/404 (backend).
- **Testing:** Inertia feature assertion (`Proxies/Show` props) from T21; destination-remove and
  proxy-delete flows exercised by T24/T25 backend tests; a11y/interaction per T26 note.
- **Completion notes:** _pending_

## T30 — Green-suite + accessibility verification gate
- **Description:** Final pass ensuring the whole item is coherent and the design's non-negotiable
  a11y requirements hold across the composed screens (labels/`aria-describedby`, focus management
  on add/remove/validation, `aria-live` copy announcement, AlertDialog focus trap + Cancel-default
  + Esc, disabled-last-destination reason exposed non-visually, badges not sole meaning).
- **Dependencies:** T18, T27, T28, T29
- **Files:** none (verification); minor fixes in the owning task's files if a gap is found
- **Acceptance Criteria:** `composer lint`, `composer types:check`, and `./vendor/bin/sail test`
  all green; the a11y checklist above is verified (by JS component tests if tooling exists, else a
  documented manual keyboard + screen-reader pass).
- **Testing:** run the three commands; record the a11y checklist outcome in completion notes.
- **Completion notes:** _pending_

## T31 — Vue component test harness + automated a11y coverage (DEFERRED — not gating item #1)
- **Status:** **Deferred / Backlog — out of scope for item #1.** Wanted by the Owner but explicitly
  deferred (Owner decision 2026-07-30). It is **not** a dependency of any item-#1 task and does
  **not** gate T26–T30 or T30's green-suite gate. Do **not** start it as part of item #1.
- **Description:** Stand up a Vue/JS component test harness (e.g. **Vitest + @testing-library/vue**)
  and **port the manual a11y checks (T26–T30) to automated component tests** — labels /
  `aria-describedby` associations, focus management on add/remove/validation, the `aria-live`
  "Ingest URL copied to clipboard" announcement, the AlertDialog focus trap + Cancel-default + Esc
  dismissal, the disabled-last-destination reason exposed non-visually, and badges-not-sole-meaning.
  Until this lands, the frontend tasks are verified by Inertia feature tests + a documented manual
  a11y pass (the standing approach for item #1).
- **Dependencies:** T26–T29 (the components under test); to be scheduled in a later item, not #1.
- **Files:** JS test tooling config (e.g. `vitest.config.ts`, `package.json` devDeps),
  `resources/js/components/**/*.spec.ts` (new component specs).
- **Acceptance Criteria (for the future item, not now):** a JS test runner executes in CI/local;
  the T26–T30 a11y checklist is covered by automated component assertions rather than manual pass.
- **Testing:** the harness's own runner (green) plus the ported a11y assertions.
- **Completion notes:** _deferred — do not implement under item #1._

## Handoff
- **Inputs:** Accepted `docs/plans/plan-01-walking-skeleton.md`; Approved PRD-01 & design-01;
  ADR-001–008; foundational plan Appendix A; confirmed installed teams API (`HasTeams`,
  `EnsureTeamMembership`, `{current_team}` route prefix).
- **Outputs:** this task plan (30 gating tasks T1–T30 for item #1, plus deferred/backlog **T31** —
  Vue component test harness — explicitly out of scope for item #1).
- **Dependencies:** `lorisleiva/laravel-actions` (T1); shadcn-vue Table + AlertDialog primitives
  (T26); Laravel starter-kit auth/teams (reused).
- **Outstanding Questions / Flagged gaps (see below).**
- **Next Agent:** Senior Developer (after Project Owner approval).

## Flagged gaps (Owner-resolved 2026-07-30 unless noted)
1. **Frontend component test harness — RESOLVED (wanted but deferred).** Owner decision: do **not**
   stand up a Vue/JS test harness for item #1. T26–T30 keep Inertia feature assertions + a
   documented manual a11y pass as their verify steps. The harness + automated a11y porting is
   captured as deferred/backlog **T31**, which does not gate item #1.
2. **Ingest HTTPS enforcement — RESOLVED (both layers).** Owner decision: HTTPS-only ingest is
   enforced by **defense-in-depth on both layers** — edge termination/redirect at the LB/Forge
   (ops) **and** a thin app-layer HTTPS assert (middleware on the ingest route), with a test that a
   non-HTTPS ingest request is rejected. Folded into T17. (Requirement wording recorded in PRD-01
   by the PM in parallel.)
3. **Ingest body-size cap + per-token rate-limit — RESOLVED (high placeholders).** Owner decision:
   keep config-driven; set defaults to **deliberately very high placeholder values** documented as
   "placeholder — revisit before MVP/public exposure" (not risk-tuned, no low numbers). Config keys
   introduced in T17 with those provisional defaults.
4. **Team-scope "current team" API — passed to the Senior Developer to confirm.** Owner decision:
   keep the kit-inspected default (`current_team_id` / `EnsureTeamMembership` / `{current_team}`
   prefix) as the **confirm-target**; the **Senior Developer verifies it against the actually
   installed kit** before T7/T20 (annotated on both tasks and the intro binding note), rather than
   treating it as settled.
5. **Referenced docs being scaffolded (no longer flagged).** `docs/status.md` and
   `docs/standards/planning.md` are being scaffolded in parallel (Owner approved); their prior
   absence is no longer a gap.
6. **shadcn-vue Table + AlertDialog scaffold in T26 — APPROVED.** Owner approved adding the
   **Table** and **AlertDialog** primitives in T26; kept as-is (noted so it is not mistaken for
   missing scope).
