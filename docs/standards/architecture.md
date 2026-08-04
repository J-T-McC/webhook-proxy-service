# Architecture Standards

> **Status: Proposed — pending Project Owner approval.** Owned by the Principal
> Engineer (proposes) and Project Owner (approves). The four sections below the
> Requirements section codify patterns this codebase already follows (grounded in
> ADR-001..009 and current `app/` code); rules with no existing precedent are
> explicitly tagged **Proposed default (no prior precedent)** so the Owner ratifies
> the observed patterns and decides the genuinely new ones.

## Requirements (active)
- Significant, hard-to-reverse decisions require an ADR (plugin `templates/adr.md`).
- Designs stay within `docs/stack/stack.md`; deviations require an Owner-approved ADR.
- Every technical plan section must trace to a PRD acceptance criterion.

## Module boundaries

Single Laravel application (no packages/modules split). Layering is by `app/`
namespace, with a one-directional dependency flow.

**Roles of each layer (codifies observed structure):**
- **Controllers** (`Http/Controllers`) — thin HTTP adapters. They call
  `$this->authorize(...)`, type-hint a Form Request for validation, orchestrate the
  use case (may open a `DB::transaction` and coordinate models/actions), and return
  an Inertia render or a redirect. No business rules live outside a transaction body,
  no validation inline, no authorization logic beyond the `authorize` call.
- **Actions** (`Actions/`, `lorisleiva/laravel-actions` per ADR-007) — reusable
  domain operations and pipeline units. Two established uses: (a) pipeline `Step`s
  (`AsObject`, run in-process) implementing the first-party `App\Pipeline\PipelineStep`
  contract; (b) dispatchable units (`AsJob`: `ProcessIngestedWebhook`,
  `DeliverToDestination`) that are the ADR-005 sync-or-queue timing seam. Also used
  for discrete write operations (`Actions/Teams/CreateTeam`, Fortify actions).
- **Services** (`Services/`) — stateless domain helpers with no HTTP knowledge
  (`IngestTokenService`, `ResponseResolver`). Prefer a Service for logic reused
  across controllers/actions; prefer an Action when the unit must also be
  dispatchable or pipeline-composed.
- **Policies** (`Policies/`) — the only place authorization decisions are made.
  They consume `TeamPermission` via `$user->hasTeamPermission($team, ...)` and never
  branch on a role name (see Security baseline; ADR-009).
- **Models** (`Models/`) — persistence + relations + casts only. Cross-cutting model
  behavior is factored into **Concerns** (`Concerns/`: `HasTeams`,
  `BelongsToCurrentTeam`, validation-rule traits) and **Scopes** (`Models/Scopes/`).
- **Enums** (`Enums/`) — domain vocabulary (`TeamRole`, `TeamPermission`,
  `ProxyMode`, `HttpMethod`, `AttemptStatus`). String-backed where persisted; the
  source of truth for validation (`Rule::enum(...)`) and casts.
- **Data** (`Data/`) — readonly DTOs shaping server→frontend props
  (`TeamPermissions`, `UserTeam`). **Http/Resources** (`Http/Resources/`) — Eloquent
  → Inertia prop serialization (`ProxyResource`, `DestinationResource`), `$wrap = null`.
- **Rules** (`Rules/`) — custom validation rules. **Events** (`Events/`) — domain
  events emitted by delivery units (ADR-003).

**Allowed dependency direction (Proposed default — codifies observed, never written
down):** Controllers → (Form Requests, Policies, Actions, Services, Resources/DTOs,
Models). Actions/Services → (Services, Models, Enums, Events). Models → (Concerns,
Scopes, Enums, Services only for self-contained helpers like token minting).
Resources/DTOs/Enums are leaves. No lower layer may depend on `Http/` (a Model,
Service, Action, or Policy must never reference a Controller, Request, or Resource).
Policies depend only on Models, Concerns, and Enums.

**Ingest path is a deliberate exception.** The token-authenticated ingest flow
(`routes/ingest.php` → `IngestController` → `ProcessIngestedWebhook`) runs **outside**
the team-scoped web group: no session, CSRF-exempt, and **not** team-scoped (it
resolves a proxy by token hash across all teams). Do not route ingest through the
team-scope middleware or the web policies; its authorization *is* the opaque token
(ADR-006).

## API design

**This is an Inertia (server-driven) application, not a public REST/GraphQL/RPC API.**
Vue pages receive props from controllers via `Inertia::render(...)`; there is no
versioned JSON API surface for third parties, and none is planned at the current
roadmap stage.

**Conventions (codify observed):**
- **Mutations follow Post/Redirect/Get.** Store/update/destroy return
  `to_route(...)` (a 302), never a rendered page. User feedback is a flash toast via
  `Inertia::flash('toast', ['type' => ..., 'message' => ...])`.
- **Validation errors** are the standard Laravel/Inertia error bag: a Form Request
  failure redirects back with `errors`, which the Vue form reads. Server-side
  invariants that survive validation throw `ValidationException::withMessages([...])`
  inside the transaction (see `ProxyController::store`). This is the single error
  format for the app UI — do not invent a JSON error envelope for Inertia routes.
- **Prop shape** goes through an `Http/Resources` resource with `public static $wrap
  = null` (Inertia consumes attributes directly, not a `data` envelope). Lists keep
  the native paginator envelope and map rows `->through(fn ($m) => new XResource($m))`.
- **JSON responses** are rendered only when the request `is('api/*')` or
  `expectsJson()` (`bootstrap/app.php`). There is no `routes/api.php` in use.

**The one public HTTP endpoint is the ingest webhook** (`POST|PUT /ingest/{token}`,
`->name('ingest')`):
- Path carries an opaque high-entropy token only — no team/proxy id (ADR-006).
- Public, token-authenticated, CSRF-exempt, HTTPS-asserted, body-size-capped,
  per-token throttled (`throttle:ingest`).
- Unknown/soft-deleted token ⇒ **404**, no existence disclosure.
- Response is resolved independently of delivery (ADR-004).

**Versioning:** none today, and none is needed while ingest URLs are opaque bearer
tokens (rotation replaces a URL rather than versioning a contract).
**Proposed default (no prior precedent):** if a versioned public API is ever
introduced, version it by URL prefix (`/api/v1/...`) under a dedicated
`routes/api.php`, keep DTOs/Resources as the serialization boundary, and record the
introduction as an ADR — do not retrofit Inertia routes into a public API.

## Data

**Migrations:** Laravel migrations are the only schema mechanism (`database/migrations`,
timestamp-prefixed). Each migration defines `down()` for local reversibility.
- **Proposed default (no prior precedent):** treat migrations as **forward-only in
  production** — roll forward with a new migration rather than relying on `down()` in
  prod. **No destructive backfills**; do not fabricate data for historical rows (per
  ADR-009: pre-feature `created_by` stays null rather than guessing a creator).

**Naming (codifies observed):** `snake_case`, plural table names (`proxies`,
`destinations`, `delivery_attempts`). Pivots use a domain-meaningful name, not the
alphabetical default — `team_members` (with `Membership` model), not `team_user`.
Columns `snake_case`; FKs `<singular>_id` (`team_id`, `proxy_id`), non-FK actor refs
spell the relation (`invited_by`, and `created_by` per ADR-009).

**Foreign keys / onDelete (codifies observed):**
- Owned children that must not outlive their parent: `foreignId(...)->constrained()
  ->cascadeOnDelete()` (`proxies.team_id`, pivot `team_id`/`user_id`).
- Records that must survive parent/actor loss use `nullable()->constrained()
  ->nullOnDelete()` — the standard shape for actor/creator refs (ADR-009 `created_by`:
  a proxy outlives its creator's account, falling back to Admin/Owner management).
- Immutable fact tables reference with the default (restrict) `constrained()`
  (`delivery_attempts` FKs) and are never cascade-cleaned.
- Index for the access pattern: composite indexes for common filters
  (`['team_id','created_at']`, `['proxy_id','status']`); a `BINARY(32)` single-column
  UNIQUE for the ingest-token hash lookup (ADR-006); FK columns are indexed by their
  constraint.

**Soft-delete policy (codifies observed + ADR-003):**
- `SoftDeletes` on mutable, user-owned entities: `Team`, `Proxy`, `Destination`.
- **No** soft delete on immutable fact/event rows: `delivery_attempts` has no
  `deleted_at` and is always retained (ADR-003) — an attempt is a historical fact,
  not editable state.
- A UNIQUE index that guards a bearer secret is **not** scoped to `deleted_at`
  (`proxies.ingest_token_hash`): a retired proxy keeps its hash slot so a token is
  never silently re-issued (ADR-006).
- Uniqueness/existence checks that must span deleted rows use `withTrashed()`
  explicitly (see `IngestTokenService::hashExists`).

**Casts (codifies observed):** persisted enums cast to their enum class
(`'mode' => ProxyMode::class`); secrets use the framework `encrypted` cast
(`'ingest_token' => 'encrypted'`); prefer the model `casts()` method over property
arrays.

**Creator convention (Proposed default — from ADR-009, currently *Proposed*, not yet
merged):** models needing creator attribution use the `App\Concerns\HasCreator`
trait (a `creating` boot hook mirroring `BelongsToCurrentTeam`, setting `created_by`
only when absent **and** `Auth::check()`), plus a nullable `created_by`
`->constrained('users')->nullOnDelete()` column. A null creator is a **safe deny**
for ownership checks. This becomes ratified standard only when ADR-009 is Accepted.

## Security baseline

**Authentication (codifies observed):** Laravel Fortify with passkeys (`@laravel/passkeys`,
WebAuthn) and two-factor. Authenticated routes sit behind `auth` (and `verified` where
required); sensitive settings additionally use `RequirePassword` and tight throttles
(`throttle:6,1`). No custom auth store — Fortify is authoritative.

**Authorization (codifies observed + ADR-009):**
- Every proxy/team decision goes through a **Policy** consuming `TeamPermission`
  (`$user->hasTeamPermission($team, TeamPermission::X)`, resolved from the
  `team_members` pivot role). **Never branch on a role literal** (`role === 'Member'`)
  in a policy or controller — role→permission mapping lives only in
  `TeamRole::permissions()`. (ADR-009 extends this to `proxy:` permissions and models
  ownership as its own `-any` permission axis, not a role check.)
- **Team scoping is request-scoped, fail-closed.** `ApplyTeamScope` registers
  `TeamScope` on team-owned models for the team-prefixed route group only, and **must
  run before `SubstituteBindings`** (priority registration in `bootstrap/app.php`) so
  route-model binding 404s a cross-team id. `TeamScope` always adds a `team_id`
  predicate for an authenticated user; a team-less user is constrained to sentinel id
  `0` (zero rows), never global. The scope is removed on the way out to avoid leaking
  into later requests/queue workers/Octane.
- **Membership gate.** `EnsureTeamMembership` guards the team group; the scope is
  applied selectively (not a global model scope) so settings routes and the
  token-authed ingest path are never wrongly constrained.
- `created_by` id-equality ownership checks are composed with (not instead of) the
  permission check; a removed team member is denied by the permission check regardless
  of `created_by` (ADR-009).
- **Enforcement vs. display authorization (ratified by Owner direction 2026-08-03;
  ADR-009 Amendment B).** These are two distinct concerns and must not be conflated:
  - **Enforcement** (may this request perform the action) lives **server-side in a
    Policy** and is the *only* authoritative gate — the controller `authorize(...)`
    call against `ProxyPolicy`/`TeamPolicy` stands regardless of any client state.
  - **Display** (should this affordance render) is computed **client-side** from data
    already shared to the stateful Inertia/Jetstream frontend: the current user's
    permission set (page-level `ProxyPermissions`/`TeamPermissions` booleans, mirrored
    from the role bundle once per page) plus record fields already on the serialized
    resource (e.g. `ProxyResource.is_creator`, a plain in-memory comparison — never a
    policy call). Affordance = `perms.<verb> && (record.is_creator || perms.<verb>Any)`.
  - **Per-record server-side policy evaluation to produce a display flag is
    disallowed** — calling `$user->can(...)` / `Gate::allows(...)` per row creates an
    N+1 (review-02 M2) and duplicates the enforcement path into a display concern. The
    stateful frontend already exposes the user's roles/permissions; derive from that +
    already-loaded record data. Do **not** eager/lazy-load or memoize to make a per-row
    policy call "cheap" — remove the per-row policy call. The client check never
    replaces the server gate; a tampered client that renders a hidden control still
    hits the Policy and is denied.

**Secret handling (codifies observed + ADR-006):**
- Config/secrets via `.env` (`.env.example` is the committed template; real values
  are never committed).
- Ingest tokens: 256-bit CSPRNG (`random_bytes(32)`), stored as a `SHA-256`
  `BINARY(32)` hash for O(1) lookup **and** an `encrypted`-cast column for display;
  the plaintext token is **never logged**. Inbound resolution hashes the presented
  token and looks up by hash.
- Bearer/ingest URLs are built from **server config** (`config('ingest.url')`), never
  the request `Host` header (Host-header injection guard, ADR-006). Keep tokens/URLs
  out of request/response logs, APM capture, and analytics that serialize Inertia
  props.

**Input validation defaults (codifies observed):**
- Validation is **server-authoritative via Form Requests** (`Http/Requests/...`),
  never trusted from the client and never inline in controllers. Authorization stays
  in the Policy (`authorize(): true` in the Form Request; the controller calls
  `$this->authorize`).
- Validate enums with `Rule::enum(...)` against the domain enum; validate URLs with
  the appropriate rule.
- **HTTPS-only** for destination URLs (`url:https`) — an explicit Owner security
  ruling (PRD-01, 2026-07-30); reject `http://` and scheme-less URLs.
- Defense-in-depth invariants that outlive validation are re-checked inside the
  transaction and raise `ValidationException` (e.g. the ≥1-live-destination guard).
- **Public ingest hardening (ADR-006):** app-layer HTTPS assertion, request
  body-size cap, and per-token rate limiting; CSRF exemption applies **only** because
  the caller is an external system on a route outside the web group.
