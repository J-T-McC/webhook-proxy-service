# Technical Plan: Walking skeleton — ingest → fan-out delivery (item #1)

- **Status:** Accepted
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-01-walking-skeleton.md` (Approved — Project Owner, 2026-07-30)
- **Design spec:** `docs/design/design-01-walking-skeleton.md` (Approved — Project Owner, 2026-07-30)
- **Approved by / date:** Project Owner, 2026-07-30
- **Revision note:** 2026-07-30 — folded in the Project Owner's rulings on the two
  flagged residual decisions: (1) **soft-delete-only retention** — proxies and
  destinations use `SoftDeletes`, `delivery_attempts` are always retained (never
  cascade-removed); (2) **header forwarding** — safe allowlist recorded as **ADR-008
  (Proposed)**. Residual decisions (2) and (3) below are now resolved; plan remains
  **Draft** pending Owner approval of the plan as a whole.

## Overview
This is the per-PRD implementation plan for Roadmap item #1, building directly on
the Accepted foundational plan (`docs/plans/foundational-architecture-plan.md`,
Appendix A) and ADR-001…007. It delivers two surfaces: (1) a **public,
token-authenticated ingest path** — `POST|PUT /ingest/{token}` resolves a
team-scoped proxy by SHA-256 token-hash lookup, returns a config-driven upstream
response (202) independent of delivery, and runs the native `Illuminate\Pipeline\Pipeline`
composed to exactly `[DeliverStep]`, which fans out one HTTP POST/PUT per
destination, each writing a payload-free `DeliveryAttempt` and emitting domain
events; and (2) an **authenticated, team-scoped management surface** — Inertia/Vue
pages for list/create/view/edit/delete of proxies and their destinations, with the
full ingest URL rendered server-side from config for display + copy. Item #1 runs
the pipeline **synchronously** (`::run`) and is **fire-and-forget** (no retry); the
dispatch-timing seam (ADR-005) is present but not exercised, so #4 async/FIFO drops
in without reworking the spine. This plan invents no requirements; residual choices
are flagged in **Risks / Residual decisions**, not decided here.

## Architecture

Component shape and seams are fixed by the foundational plan and ADRs; this plan
scopes them to what item #1 builds and ties each to PRD acceptance criteria. Where
Appendix A of the foundational plan gives the illustrative code shape, it is the
reference — this plan does not restate it, only the item-#1 build list and the
concrete decisions the Task Planner needs.

**Two entry points, one shared domain:**

- **Ingest (public):** `IngestController` (single invokable) → `ResponseResolver`
  (202 default, ADR-004) → `ProcessIngestedWebhook` action (`::run`, ADR-005/007) →
  native `Pipeline` through `PipelineFactory::stepsFor()` (= `[DeliverStep]` for both
  modes at #1, ADR-001/002) → `DeliverStep` fan-out → `DeliverToDestination` action
  per destination (`::run`) → HTTP send + `DeliveryAttempt` write + events (ADR-003).
- **Management (authenticated, team-scoped):** `ProxyController` (resource) +
  `DestinationController@destroy` (quick single-destination remove) → FormRequests →
  Eloquent models under a team global scope → Inertia responses rendering Vue pages.
  `IngestTokenService` mints/hashes/encrypts the token on create.

**Built at item #1** (per Appendix A boundary): `PipelineContext`, first-party
`PipelineStep` interface, `PipelineFactory` (composing exactly `[DeliverStep]`),
`DeliverStep`, `ProcessIngestedWebhook` and `DeliverToDestination` (both invoked with
`::run` only), `DeliveryUnit`, `DeliveryAttempt` + the three events
(`DeliveryAttempted` / `DeliverySucceeded` / `DeliveryFailed`), `IngestController`,
`ResponseResolver` (202), `IngestTokenService`, the `Proxy` / `Destination` /
`DeliveryAttempt` Eloquent models with the team global scope, the management
controllers/requests/routes, and the four Vue pages. **Not built** (all `LATER` in
Appendix A): every enhanced-only step, all `::dispatch`/`onQueue`/`WithoutOverlapping`
job config, the V3 publisher, payload storage, and the #3 resolver body.

## Data Model

All tables are **team-scoped from the first commit (R1)** via a `team_id` FK; models
carry a team global scope (see Services → team scoping). MySQL, InnoDB. The starter
kit supplies `users`/`teams` (see Risk: teams implementation). Column shapes below are
item-#1 only; forward-compatible "(later)" columns from the foundational plan are
**not** created here.

### `proxies` (AC1, AC2, AC5, AC12, ADR-002/006)
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK, auto-increment | clustered PK stays sequential (ADR-006 perf note) |
| `team_id` | FK → teams, indexed | team ownership (AC5) |
| `name` | string | required |
| `mode` | enum(`simple`,`enhanced`) NOT NULL default `simple` | ADR-002; item #1 may persist `enhanced` (mode selector) but composes the same pipeline |
| `ingest_token_hash` | **`BINARY(32)`**, **UNIQUE** secondary index | SHA-256 of the plaintext token; O(1) inbound lookup, guarantees AC12a uniqueness (ADR-006 perf addendum — do **not** use a `char(64)` `utf8mb4_*_ci` column) |
| `ingest_token` | text, Laravel **`encrypted`** cast | decrypted server-side for display only (AC12d); never logged |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable — **`SoftDeletes`** | Owner ruling: proxies are **soft-deleted, never hard-deleted** at #1; a soft-deleted proxy no longer ingests |

- Uniqueness is enforced by the DB unique index on `ingest_token_hash` (AC12a),
  with regenerate-on-collision in `IngestTokenService` (astronomically unlikely).
- No team/proxy id is embedded in the token or URL path (AC12b).
- **Soft delete ⇄ UNIQUE(`ingest_token_hash`) interaction (Owner ruling 1).** The
  unique index stays a **single-column `UNIQUE(ingest_token_hash)`** — it is **not**
  scoped to `deleted_at`. A soft-deleted proxy therefore continues to occupy its hash
  slot, which is the intended behaviour: a retired proxy's token is permanently
  retired and can never be silently re-issued to another proxy. Because tokens are
  256-bit CSPRNG values (ADR-006), soft-deleted rows consuming index slots create no
  practical collision pressure. We deliberately do **not** use a composite
  `UNIQUE(ingest_token_hash, deleted_at)` (MySQL treats each `NULL` `deleted_at` as
  distinct and would let a live proxy reuse a trashed hash) — that weakens the
  guarantee for no benefit. See Risks / Residual decisions #2 for the second-order note.

### `destinations` (AC2, AC3, AC16, ADR-001)
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `proxy_id` | FK → proxies, indexed | **no `ON DELETE CASCADE`** — proxies/destinations are soft-deleted, never hard-deleted, so a row-cascade never fires; proxy delete **soft-deletes** its destinations at the application layer (Flow F). FK stays plain (`RESTRICT`) as a safety net |
| `team_id` | FK → teams, indexed | denormalized for direct team scoping (foundational plan) |
| `url` | string | absolute HTTP(S) URL (validation) |
| `http_method` | enum(`POST`,`PUT`) NOT NULL | V1 / AC3 |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable — **`SoftDeletes`** | Owner ruling: destinations are **soft-deleted, never hard-deleted** at #1 |

- **Min-1-destination invariant (AC2/AC16b)** cannot be expressed declaratively in
  MySQL; it is enforced in the application layer (FormRequest `min:1` on create/update
  and a guard on single-destination delete) inside a DB transaction, and covered by
  tests. **Under the soft-delete ruling the invariant counts only non-soft-deleted
  (`whereNull(deleted_at)`) destinations** — a proxy must always have ≥1 *live*
  destination; trashed rows do not satisfy it. See Validation.
- **Delete = soft delete.** Both the detail-page per-destination remove (Flow E) and
  proxy delete (Flow F) set `deleted_at`; no destination row is ever hard-deleted at #1.
  The management/edit queries load only live destinations (the `SoftDeletes` global
  scope), so trashed rows never appear in the edit form or `Show`/`Index` props.

### `delivery_attempts` (AC13–AC15, ADR-003) — payload-free
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → teams, indexed | team-scoped, queryable (AC15) |
| `proxy_id` | FK → proxies | identifies the proxy (AC13); **always retained** — no cascade/null-out (Owner ruling 1). Since proxies are soft-deleted (never hard-deleted) the reference stays valid forever |
| `destination_id` | FK → destinations | identifies the destination (AC13); **always retained** — no cascade/null-out. Destinations are soft-deleted, so the reference stays valid |
| `ingest_id` | uuid, indexed | correlates one webhook's fan-out set (ADR-003) |
| `status` | enum(`dispatched`,`succeeded`,`failed`) | outcome (AC13) |
| `http_status` | smallint, nullable | destination response status (AC13) |
| `error_summary` | string(250), nullable | summary only — **never a payload body** (AC15) |
| `attempt_number` | int default 1 | `1` at #1; #6 increments |
| `started_at` | timestamp | |
| `duration_ms` | int, nullable | |
| `created_at` / `updated_at` | timestamps | |
| indexes | `(team_id, created_at)`, `(proxy_id, status)`, `(ingest_id)` | for #11 later; cheap now |

- **No payload/body column exists by construction** (AC11/AC15, ADR-003). There is
  **no payload storage table** at #1 (R2 / #5).
- The `dispatched` row is written **before** the HTTP call so a crash still leaves a
  durable record (no lost data, ADR-003).
- **Retention (Owner ruling 1): `delivery_attempts` are always retained.** They are
  never cascade-removed and have **no** soft-delete/`deleted_at` column of their own —
  a `DeliveryAttempt` is an immutable historical fact. Soft-deleting a proxy or
  destination leaves its attempt records intact and still team-scoped/queryable (AC15),
  which is exactly what #11 analytics needs. The referenced `proxy_id`/`destination_id`
  rows are never physically gone (soft delete), so the records never dangle.

## API

### Ingest — public, external senders (AC7–AC12, ADR-004/006)
- **Route:** `Route::match(['post','put'], '/ingest/{token}', IngestController::class)`
  registered **outside** the `web` group — no session auth, **CSRF-exempt** (external
  callers), with a TLS guard and a per-token throttle (see below). Named `ingest`.
- **Resolution:** `Proxy::query()->where('ingest_token_hash', hash('sha256', $token, binary: true))->first()`;
  `abort_if(null, 404)` — no existence disclosure (AC12c). This query **bypasses the
  team global scope** (there is no authenticated user; the token *is* the authenticator)
  — implemented with an explicit unscoped query builder, not the default model query.
  **It does, however, keep the `SoftDeletes` scope (Owner ruling 1): only the team
  global scope is stripped, not the soft-delete filter.** A soft-deleted proxy therefore
  resolves to `null` → `404` and **no longer ingests** — do **not** use `withTrashed()`
  here. (Concretely: remove the team scope via the model's team-scope class, not
  `withoutGlobalScopes()`, so the soft-delete scope survives.)
- **Method:** POST or PUT as received, forwarded per-destination using the
  destination's own `http_method` (AC3/AC7/AC10).
- **Response:** `ResponseResolver::resolve($proxy)` → **`202 Accepted`**, minimal body,
  resolved **before and independent of** delivery (ADR-004). Item #1 promises no
  particular contract (PRD).
- **Errors:** unknown/invalid token → `404`; non-TLS request → rejected (layer flagged
  as Owner preference, see Residual decisions); over-cap body → `413` (cap flagged);
  over-rate → `429`.

### Management — authenticated, session + team (AC1–AC6, AC16)
Registered inside the `web` + `auth` middleware groups; every route additionally
team-scoped (see Services). Cross-team or missing → kit 403/404 (AC5/AC6/AC16e).

| Method | Path | Action | AC |
|---|---|---|---|
| GET | `/proxies` | `ProxyController@index` — paginated list, each row with full ingest URL | AC4, AC12d |
| GET | `/proxies/create` | `@create` | AC1 |
| POST | `/proxies` | `@store` | AC1–AC3, AC12 |
| GET | `/proxies/{proxy}` | `@show` — ingest URL + destinations + mode | AC4, AC12d |
| GET | `/proxies/{proxy}/edit` | `@edit` — pre-filled form | AC16a |
| PUT/PATCH | `/proxies/{proxy}` | `@update` — name, mode, destinations add/remove/change | AC16a, AC16b |
| DELETE | `/proxies/{proxy}` | `@destroy` — **soft-deletes** proxy + cascades soft delete to its destinations (Flow F) | AC16d |
| DELETE | `/proxies/{proxy}/destinations/{destination}` | `DestinationController@destroy` — quick single **soft** remove (Flow E) | AC16c, AC16b |

- Destination add/remove/URL/method changes are transacted through `@update` from the
  edit form's destinations array (design Screen 2 / Flow D). The detail-page per-row
  quick remove (design Screen 3 / Flow E) is the dedicated DELETE above, guarded by the
  min-1 invariant. **All removes are soft deletes (Owner ruling 1):** `@update`
  reconciliation and Flow E set `deleted_at` rather than issuing SQL `DELETE`; proxy
  `@destroy` (Flow F) soft-deletes the proxy and, in the same transaction, soft-deletes
  its live destinations. `delivery_attempts` are untouched (always retained).
- Route-model binding for `{proxy}` and `{destination}` resolves **through the team
  global scope**, so another team's id resolves to 404. A `ProxyPolicy` provides the
  authorization check expressed against proxy **actions** (view/update/delete) — not a
  hard-wired role set — to leave the #2 roles seam open (foundational plan API note).

### Inertia responses (design-driven)
- `Proxies/Index` — `{ proxies: paginated[{ id, name, mode, ingest_url }], ... }`.
- `Proxies/Create` / `Proxies/Edit` — shared form component; edit is pre-filled with
  `{ name, mode, destinations:[{ id, url, http_method }] }`.
- `Proxies/Show` — `{ proxy:{ id, name, mode, ingest_url, destinations:[...] } }`.
- **`ingest_url` is built server-side** as `rtrim(config('ingest.url'),'/').'/ingest/'.$token`
  from the decrypted `ingest_token` (see Services → ingest URL). It is a bearer secret:
  it is included in these props **only** for the owning team's authenticated member and
  must be kept out of any request/response logging, APM prop capture, and client-side
  event tracking (design constraint / ADR-006 addendum; no such capture exists at #1,
  so the concrete obligation is "do not add it, and scrub the token from logs").
- Flash messages (`Proxy created` / `Changes saved` / `Proxy deleted` / destination
  removed) via Inertia shared flash props → kit Sonner toast (design Interactions).

## Services

- **`IngestController`** — builds `PipelineContext` (uuid `ingest_id`, resolved proxy,
  method, headers, raw body, `payload = rawBody`), resolves the response, calls
  `ProcessIngestedWebhook::run($ctx)`, returns the response. Never reads delivery
  outcome (ADR-004).
- **`ResponseResolver`** — returns `202` at #1 (ADR-004); #3 later reads proxy columns.
- **`PipelineFactory::stepsFor(Proxy): PipelineStep[]`** — returns `[DeliverStep::make()]`
  for both `simple` and `enhanced` at #1 (ADR-001/002). Enhanced branches are the
  commented insertion contract only.
- **`ProcessIngestedWebhook`** (Action, `AsAction`) — runs the native pipeline over one
  `PipelineContext`; invoked `::run` (sync) at #1. Pipeline-level dispatch-timing seam.
- **`DeliverStep`** (Action, `AsObject`, implements first-party `PipelineStep`) — the
  terminal fan-out; iterates `proxy` destinations (**live only** — the destinations
  relation carries the `SoftDeletes` scope, so trashed destinations are not delivered
  to), builds a `DeliveryUnit` per destination, calls `DeliverToDestination::run($unit)`,
  then `$next($ctx)`. One destination failing does not abort the loop (AC9).
- **`DeliveryUnit::forwardHeaders()`** — computes the outbound header set from the
  inbound headers on the `PipelineContext` per the **safe allowlist in ADR-008**:
  forward inbound headers **except** a stripped sensitive set (`Host`, hop-by-hop
  headers, `Cookie`, inbound `Authorization`, and inbound webhook
  signature/verification headers); `Content-Type` is preserved (AC8). No signature is
  added (that is #10).
- **`DeliverToDestination`** (Action, `AsAction`) — writes the `dispatched`
  `DeliveryAttempt`, emits `DeliveryAttempted`, performs the HTTP POST/PUT via Laravel's
  `Http` client with a timeout and the ADR-008 forwarded headers, updates the attempt to
  `succeeded`/`failed` with `http_status`/`duration_ms`, emits
  `DeliverySucceeded`/`DeliveryFailed`; catches `Throwable` → `failed` + truncated
  `error_summary` (no payload). Delivery-level dispatch-timing seam; invoked `::run`
  (sync) at #1.
- **`IngestTokenService`** — `generate()` returns a URL-safe 32-byte (256-bit) CSPRNG
  token (`random_bytes` → base64url), and on the model sets `ingest_token` (encrypted
  cast) + `ingest_token_hash = hash('sha256', $token, binary:true)`; regenerate on the
  (astronomically unlikely) unique-index collision. A `rotate()` method exists (model
  supports it) but **no UI is built** (design / ADR-006).
- **Ingest URL builder** — a small helper / model accessor `Proxy::ingestUrl()` builds
  the absolute URL from **`config('ingest.url')`** (env `INGEST_URL`, defaulting to
  `config('app.url')`) + `/ingest/{token}`. **The host comes from server config, never
  the request `Host` header** (ADR-006 addendum — Host-header injection guard). This
  plan pins that config key per the design handoff's request; it is the ADR-006
  recommendation, so no new ADR is required.

### Team scoping (AC5, AC6, AC15, AC16e)
- A single mechanism scopes every management query and every model to the authenticated
  user's **current team**: a global scope on `Proxy`/`Destination`/`DeliveryAttempt`
  keyed on the current team id, plus `team_id` auto-set on create. Route-model binding
  therefore 404s cross-team ids, and the `ProxyPolicy` provides the authorization layer.
- **The concrete "current team" API is a stack detail to confirm** against the actually
  installed starter kit (see Risk: teams implementation) — the plan expresses the scope
  abstractly so the Task Planner binds it to the real teams API rather than inventing one.
- The ingest path is the **only** place that queries a proxy **without** the team scope,
  because the token is the authenticator and there is no session/team context there.

## Validation

FormRequests (`StoreProxyRequest`, `UpdateProxyRequest`) are server-authoritative; the
design's inline errors render from Inertia's `errors` bag. **Confirmed error-bag keys**
(design Screen 2 asked the PE to confirm): `name`, `mode`, `destinations`, and
per-row `destinations.{i}.url` / `destinations.{i}.http_method`.

- `name` — required, string, max length.
- `mode` — required, `in:simple,enhanced` (Enhanced permitted, ADR-002 / mode selector).
- `destinations` — required array, **`min:1`** (AC2 / AC16b) — zero destinations rejected
  on both create and update.
- `destinations.*.url` — required, valid **absolute HTTP(S)** URL (foundational Validation
  section); reject non-HTTP(S) schemes.
- `destinations.*.http_method` — required, `in:POST,PUT` (V1/AC3); any other value rejected.
- **Single-destination delete** (`DestinationController@destroy`) — **soft-deletes** the
  destination, but rejects (validation error / 422) if it is the proxy's **last
  remaining live** one (AC16b/AC16c); the UI also disables the control, but the server is
  authoritative. Wrapped in a transaction with a re-count that **counts only
  non-soft-deleted destinations** (`whereNull(deleted_at)`) to avoid a concurrent
  last-two-remove race.
- **Update destination reconciliation** — `@update` adds new rows, updates existing live
  rows by id, and **soft-deletes** omitted rows, all inside one transaction, asserting
  **≥1 live** destination remains before commit.
- **Ingest** — non-TLS rejected (layer flagged); body-size cap enforced (value flagged);
  `404` on token miss; per-token throttle (limit flagged).
- **Auth/team** — unauthenticated → redirect to login (AC6); cross-team proxy/destination
  → 404 via scoped binding (AC5/AC16e).

## Test strategy

Framework: the project's existing test runner via `./vendor/bin/sail test` (Pest/PHPUnit),
`composer lint` (Pint) and `composer types:check` (PHPStan L1) green. Tests map to ACs:

**Ingest path (feature, `Http::fake()`):**
- Posting a valid token fans out one request **per destination** with the correct method
  and the received body (AC7, AC8, AC10). `Http::fake` asserts N sent requests.
- **Header forwarding (ADR-008):** an inbound request carrying `Content-Type`, a custom
  header, plus the stripped set (`Host`, `Cookie`, `Authorization`, a hop-by-hop header,
  and a webhook signature header) is asserted at the destination to **forward**
  `Content-Type` and the custom header and to **omit** every stripped header.
- Independent failure: one destination faked to 500/throw; others still receive their
  request; the request still returns 202 (AC9, ADR-004).
- Response is `202` regardless of delivery outcome, resolved independent of it (AC7/AC11,
  ADR-004).
- Unknown/invalid token → `404` with no existence disclosure (AC12c).
- Token uniqueness: two created proxies never share a hash/URL; unique-index collision
  path regenerates (AC12a). Token embeds no id (AC12b). URL viewable by the owning team
  (AC12d).
- **Attempt records:** exactly one `DeliveryAttempt` per destination (AC13); success →
  `succeeded` + `http_status`; failure/exception → `failed` + `error_summary`, both
  captured in simple mode (AC13, AC14); records carry `proxy_id`/`destination_id`/`ingest_id`
  and **no payload column/body** (AC15); the `dispatched` row exists before the outcome
  (ADR-003, crash safety). Events asserted via `Event::fake()`.
- Simple mode performs no mapping/storage and stores no payload (AC11): assert no payload
  table row and body delivered unchanged.

**Management surface (feature, Inertia assertions):**
- Auth required: guests redirected from every management route (AC6).
- Team scoping: a user cannot index/show/edit/update/delete/destroy-destination another
  team's proxy → 404; index shows only the team's proxies (AC5, AC15, AC16e).
- Create with ≥1 destination succeeds and yields a distinct ingest URL; zero destinations
  rejected with `destinations` error (AC1, AC2). Method constrained to POST/PUT (AC3).
- Show/index expose the full server-built ingest URL from config, not the request Host
  (AC4, AC12d); a test with a spoofed `Host` header asserts the config host is used.
- Edit pre-fills and updates name/mode/destinations (add/remove/change) (AC16a). Update
  cannot leave zero **live** destinations (AC16b). Detail-page single-destination remove
  **soft-deletes** and is refused on the last live destination (AC16c/AC16b). Proxy delete
  **soft-deletes** the proxy and its destinations (AC16d); assert both rows are
  soft-deleted (`assertSoftDeleted`), the proxy/destinations no longer appear in
  team-scoped lists, and a soft-deleted proxy's token **no longer ingests** (`404`).
- **Soft-delete retention (Owner ruling 1):** deleting a proxy/destination leaves its
  `delivery_attempts` **intact** (no cascade, still team-scoped/queryable per AC15);
  assert attempt counts are unchanged after delete. Test the unique-constraint
  interaction: creating a new proxy after another was soft-deleted still yields a
  distinct hash (soft-deleted rows retain their hash slot; no reuse).

**Unit:**
- `DeliverStep::make()->handle($ctx, fn($c)=>$c)` fans out over destinations (isolatable
  per ADR-007).
- `IngestTokenService` generates 256-bit tokens, sets encrypted token + binary hash,
  round-trips decrypt for display.
- `PipelineFactory::stepsFor()` returns exactly `[DeliverStep]` for both modes at #1.

**Accessibility / UI (design non-negotiables):** the design's a11y requirements
(labelled inputs, focus management, `aria-live` copy announcement, AlertDialog focus trap,
disabled-last-destination reason) are correctness, not polish — covered by component-level
assertions where the kit does not already guarantee them. No bespoke transient-state polish
is built (fidelity posture).

## Risks / Residual decisions

Flagged, **not decided here** — each needs an ADR or a Project Owner / upstream call.

1. **Synchronous ingest at #1 blocks upstream until fan-out completes.** Per ADR-005,
   #1 runs `ProcessIngestedWebhook::run` **inline**, so although the 202 is *constructed*
   independent of outcome (ADR-004), the HTTP response is *returned* only after all
   destinations have been attempted synchronously. This is acceptable for the walking
   skeleton (no throughput/latency targets — V8 unset) and #4 flips it to `::dispatch`
   to return immediately. Called out so it is a chosen tradeoff, not a surprise. **No
   action needed unless the Owner wants async at #1** (that would pull #4 forward).

2. **Deletion / retention — RESOLVED (Project Owner, 2026-07-30): soft delete only,
   attempts always retained.** Proxies and destinations use `SoftDeletes`; nothing is
   hard-deleted at #1, and `delivery_attempts` are **always retained** (never
   cascade-removed, no soft-delete column of their own). Flows E and F, the data model,
   the min-1 invariant (counts non-soft-deleted destinations), and the ingest lookup
   (keeps the soft-delete scope → a soft-deleted proxy `404`s and no longer ingests) are
   updated above. **Second-order concern carried forward (not blocking):** the
   `UNIQUE(ingest_token_hash)` index is intentionally *not* scoped to `deleted_at`, so a
   soft-deleted proxy permanently retains its token/hash slot. This is the desired
   behaviour (a retired token is never re-issued) and, given 256-bit CSPRNG tokens, has no
   practical collision cost — but it does mean trashed rows accumulate in the unique
   index, and any future "restore a soft-deleted proxy" or bulk-purge/GC of trashed rows
   is a **later** decision (no restore UI or purge job exists at #1). Recorded so #4+ /
   any retention-GC feature inherits it explicitly.

3. **Header-forwarding policy — RESOLVED (Project Owner, 2026-07-30): safe allowlist,
   recorded as ADR-008 (Proposed).** The Owner chose the safe-allowlist option: forward
   inbound headers to destinations **except** the stripped sensitive set (`Host`,
   hop-by-hop headers, `Cookie`, inbound `Authorization`, and inbound webhook
   signature/verification headers); `Content-Type` is preserved; no signature added
   (#10). This is now `docs/architecture/adr-008-inbound-header-forwarding-policy.md`
   (**Status: Accepted** — Project Owner, 2026-07-30). `DeliveryUnit::forwardHeaders()` and the delivery path
   above reference it.

4. **TLS enforcement layer (Owner preference, non-blocking — PRD Open Questions).** Whether
   a non-TLS ingest request is rejected at the **application layer** (middleware) or
   terminated at the **load balancer**. Both satisfy "TLS-only". **Plan builds against an
   app-layer guard by default** (does not preclude LB termination); resolvable at
   implementation time. **Flag, non-blocking.**

5. **Plaintext-token fallback (Owner preference, non-blocking — PRD Open Questions /
   ADR-006).** The plan follows ADR-006's **recommended** hash-lookup + encrypted-at-rest
   design. The simpler plaintext-unique fallback also satisfies AC12. **Flag, non-blocking;
   plan proceeds with the recommended design.**

6. **Ingest body-size cap and per-token rate-limit values (implementation defaults).**
   ADR-006 mandates a body-size cap and basic per-token rate limiting but sets no numbers
   (no throughput targets — V8). **Plan proposes sane config-driven defaults** (a body cap
   aligned to a config value; a modest per-token throttle) to be pinned at implementation;
   neither gates approval. **Flag, non-blocking.**

7. **Starter-kit teams implementation (stack gap).** PRD/design assert teams exist via the
   "official Laravel Vue starter kit" boilerplate (team switcher confirmed in screenshots),
   but the **concrete teams package and its "current team" / membership API are not recorded
   in a stack doc** (`docs/stack/stack.md` and `docs/standards/` do not exist — flagged in
   the foundational plan). The plan expresses team scope abstractly against a "current team"
   so the Task Planner binds it to the real API. **Recommend the Owner establish
   `docs/stack/stack.md`** pinning the teams boilerplate; **flag, does not block** (the
   scope contract is stable regardless of the concrete API).

8. **AC11 wording vs attempt-record capture — resolved.** The earlier tension (ADR-003 /
   `docs/questions/prd-01-attempt-records-vs-storage.md`) was resolved by the PRD's
   2026-07-30 revision: AC11 now states analytics *capture* is in scope and does not depend
   on payload storage. **No longer outstanding**; recorded here to close the foundational
   plan's open item.

## Dependencies
- Laravel 13 + Vue/Inertia via the **official Laravel Vue starter kit** (shadcn-vue /
  reka-ui, Tailwind, dark-mode default, sidebar app shell) — auth/teams reused, not rebuilt.
- MySQL (proxies/destinations/delivery_attempts), Redis (queue — behind the ADR-005 seam,
  **not exercised at #1**).
- `lorisleiva/laravel-actions` (ADR-007, Accepted) for the step + dispatchable actions.
- First-party `Illuminate\Pipeline\Pipeline` runner and Laravel `Http` client, `encrypted`
  cast, `random_bytes` — no new dependency beyond ADR-007.
- Approved `docs/product/prd-01-walking-skeleton.md`, `docs/design/design-01-walking-skeleton.md`,
  `docs/plans/foundational-architecture-plan.md`, ADR-001…007.

## Implementation Notes
- Team-scope every management entity/query from the first commit (R1); the ingest lookup is
  the **only** intentionally unscoped read, keyed on the token hash.
- `ingest_token_hash` must be `BINARY(32)` (or `char(64)` with a binary/`ascii_bin`
  collation) — never a case-insensitive `utf8mb4_*_ci` column (ADR-006 perf addendum).
- `DeliveryAttempt` stays **payload-free** by construction (ADR-003); no body column exists.
- Raw received body is never mutated; `PipelineContext.payload` starts equal to it
  (`DeliverStep` only reads it) so #8/#9 can overwrite `payload` later without changing
  `DeliverStep`.
- Build the ingest URL from `config('ingest.url')`, **never** the request `Host` header;
  keep the plaintext token and full URL out of logs/APM/analytics/prop capture (scrub the
  token from any request logging of the `/ingest/{token}` path).
- Keep all execution timing on the two dispatchable Actions (invoked `::run` at #1), never
  in the steps; do **not** implement any `::dispatch`/`onQueue`/FIFO/V3 machinery (all #4+).
- Both create and update run destination reconciliation inside a DB transaction that
  asserts the min-1 (**live**) invariant before commit.
- `Proxy` and `Destination` use the `SoftDeletes` trait (`deleted_at`); **no row is ever
  hard-deleted at #1.** Flows E/F and `@update` reconciliation soft-delete; proxy delete
  cascades the soft delete to live destinations in the same transaction. `DeliveryAttempt`
  is **never** soft-deleted or cascade-removed — it has no `deleted_at` and is always
  retained.
- Keep `UNIQUE(ingest_token_hash)` a **single-column** index (not composite with
  `deleted_at`); the ingest lookup keeps the soft-delete scope (strip only the team
  scope, never `withTrashed()`), so a soft-deleted proxy `404`s.
- Forward inbound headers to destinations per **ADR-008**'s safe allowlist in
  `DeliveryUnit::forwardHeaders()`; strip `Host`, hop-by-hop, `Cookie`, inbound
  `Authorization`, and inbound signature/verification headers; preserve `Content-Type`.
- Pint + PHPStan L1 green; short commit messages with context in list items (CLAUDE.md).

## Handoff
- **Inputs:** Approved PRD-01, Approved design-01, Accepted foundational plan (Appendix A),
  ADR-001…007.
- **Outputs:** this plan.
- **Dependencies:** Laravel starter-kit auth/teams boilerplate; `lorisleiva/laravel-actions`.
- **Outstanding Questions:** none **block** approval. Residual decisions (2)
  deletion/retention and (3) header-forwarding are now **RESOLVED by the Project Owner
  (2026-07-30)** — soft-delete-only + always-retain attempts, and the safe-allowlist
  header policy recorded as **ADR-008 (Proposed)**. Remaining flags are non-blocking Owner
  preferences / stack notes: (4) TLS layer, (5) plaintext fallback, (6) cap/rate values,
  (7) concrete teams API.
- **Next Agent:** Task Planner (after Project Owner approval of this plan; ADR-008 is
  Proposed and still needs the Owner's approval as a document).
```
