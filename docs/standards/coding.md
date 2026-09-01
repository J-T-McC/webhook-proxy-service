# Coding Standards

> **Status: Proposed — pending Project Owner approval.** Owned by the Principal
> Engineer (proposes) and Project Owner (approves). Most rules below **codify
> patterns this codebase and its toolchain already enforce** (Pint `laravel`
> preset, PHPStan/Larastan level 7, Prettier 3 + tailwind plugin, ESLint 9,
> `vue-tsc`) and are grounded in current `app/` and `resources/js/` code. Rules
> with no existing precedent are tagged **Proposed default (no prior precedent)**
> so the Owner ratifies the observed patterns and decides the genuinely new ones.
> Where a rule differs by language, PHP and TS/Vue are called out separately.
> This document never overrides the formatters/linters — if a tool disagrees, the
> tool wins and this doc is corrected.

## Naming

**PHP (codifies observed; Pint `laravel` preset = PSR-12 enforced):**
- **Namespaces / files:** PSR-4 under `App\`; one class per file; filename ==
  class name. Directory path mirrors the namespace segment in `StudlyCase`
  (`app/Http/Controllers`, `app/Actions/Teams`).
- **Classes / enums / traits:** `StudlyCase` (`ProxyController`,
  `IngestTokenService`, `HasTeams`). Enum **cases** are `StudlyCase`
  (`TeamRole::Owner`); when persisted they are string-backed with explicit values.
- **Methods / properties / variables:** `camelCase`, always with typed properties
  and typed parameters + return types (PHPStan L7 leaves no room for untyped).
  Constants `UPPER_SNAKE_CASE`.
- **Role-suffixed class names (codifies observed):** `<Model>Controller`,
  `Store<Model>Request` / `Update<Model>Request`, `<Model>Resource`,
  `<Model>Policy`, `<Domain>Service`. **Actions** are verb-first, named for the
  operation, not a suffix (`CreateTeam`, `ProcessIngestedWebhook`,
  `DeliverToDestination`). **Concerns** read as capabilities/relations
  (`HasTeams`, `BelongsToCurrentTeam`, `GeneratesUniqueTeamSlugs`). **Data DTOs**
  are nouns (`TeamPermissions`, `UserTeam`).
- **Database identifiers:** `snake_case`, plural tables, `<singular>_id` FKs — see
  `architecture.md → Data` (authoritative; not repeated here).

**TS / Vue (codifies observed; ESLint + Prettier enforced):**
- **Components:** `PascalCase.vue`, one component per file, filename == component
  name (`InviteMemberModal.vue`, `ProxyForm.vue`, `DestinationRows.vue`).
  `vue/multi-word-component-names` is **off**, so single-word page files
  (`Index.vue`, `Show.vue`, `Create.vue`, `Edit.vue`) are allowed and expected.
- **Inertia page files:** `PascalCase.vue` under a **lowercase feature directory**
  (`pages/proxies/Index.vue`) so the string passed to `Inertia::render(...)`
  matches the path (`Inertia::render('proxies/Index')`). Keep server render key
  and file path in lockstep.
- **Composables:** `useX.ts`, function `useX()` (`useInitials`, `useAppearance`).
- **lib / helpers:** `camelCase.ts` exporting `camelCase` functions
  (`flashToast.ts`, `utils.ts`).
- **Type modules:** lowercase filename by domain (`types/proxies.ts`,
  `types/teams.ts`, `types/ui.ts`); `interface` / `type` names are `PascalCase`
  (`ProxyDetail`, `DestinationRow`, `FlashToast`).
- **Prop key casing (codifies observed):** props that mirror a server payload use
  the **`snake_case` keys the PHP Resource emits** (`ingest_url`, `http_method`,
  `is_personal`); component-local / client-only props are `camelCase`
  (`submitLabel`, `cancelHref`, `currentTeam`). Do not rename server keys to
  camelCase on the client — the Resource is the contract.
- **Imports:** use the `@/` alias for `resources/js/*` (tsconfig `paths`); ESLint
  `import/order` groups and alphabetizes imports and `consistent-type-imports`
  requires `import type { ... }` as separate, top-level statements. Let
  `--fix`/Prettier arrange these — do not hand-order.

## Project structure

Directory layout and naming only; **module boundaries, layer roles, and allowed
dependency direction are defined in `architecture.md → Module boundaries` and are
not repeated here.** Place a new file in the directory whose layer role
(architecture.md) matches its responsibility; the conventions below govern how it
is laid out and named within that directory.

**PHP — `app/` (codifies observed):**
- One class per file; directory == namespace. Group by layer/role directory
  (`Http/Controllers`, `Http/Requests`, `Http/Resources`, `Actions`, `Services`,
  `Policies`, `Models`, `Models/Scopes`, `Concerns`, `Enums`, `Data`, `Rules`,
  `Events`, `Pipeline`), sub-foldered by feature where a domain grows
  (`Actions/Teams`, `Http/Controllers/Teams`, `Http/Controllers/Settings`).
- Controllers stay thin (see architecture.md); private helpers that only shape a
  controller's own payload live on the controller (`ProxyController::destinationRows`),
  not in a Service, unless reused across layers.

**TS / Vue — `resources/js/` (codifies observed):**
- `pages/` — Inertia page components, one directory per feature
  (`pages/proxies/`, `pages/teams/`, `pages/settings/`, `pages/auth/`). A shared
  sub-form used by sibling pages may live alongside them (`pages/proxies/ProxyForm.vue`).
- `components/` — reusable app components (`InviteMemberModal.vue`,
  `InputError.vue`, `DestinationRows.vue`). `components/ui/` holds the Reka UI
  wrapper primitives.
- `composables/` — `useX` composition functions. `lib/` — framework-agnostic
  helpers. `types/` — shared TypeScript types/DTO shapes. `layouts/` — Inertia
  persistent layouts. `data/` — structured data consts (see convention below).
- **Structured data const + standard type; no UI magic numbers (codifies observed
  — `data/proxyResponseStatuses.ts`):** a value/label set reused across components
  (select options, status/badge label maps, closed enum-like sets) lives once as a
  single source of truth in `resources/js/data/<camelCase>.ts`, not re-declared per
  component. Type each entry against the standard `DataOption<TValue>` shape
  (`types/data.ts`: required `value` + `label`, optional `description`), extending
  it per set for extra fields; declare the array `as const satisfies readonly
  <Option>[]` and **derive** the value union from it
  (`type X = (typeof CONST)[number]['value']`) rather than hand-maintaining a
  parallel union. Components import the const, derived type, and any label/predicate
  helpers from that module — **no bare literals in UI conditionals** (`=== '204'`);
  reference a named/derived value (a helper over an option flag, e.g.
  `emptyBody`) instead. Where the values mirror a server contract, note the coupling
  in a comment; the backend rule stays authoritative.
- **Generated code is never hand-edited.** `resources/js/actions/**`,
  `resources/js/routes/**`, `resources/js/wayfinder/**`, and
  `resources/js/components/ui/*` are ESLint-`ignores` entries produced by
  Wayfinder/Chisel and the Reka UI generator. Regenerate them via their tool;
  never edit in place, and import route/action helpers from them
  (`@/routes/proxies`) rather than hardcoding URLs.

## Error handling

**PHP (codifies observed + architecture.md → API design):**
- **User-correctable input errors go through Form Requests.** Validation is
  server-authoritative in `Http/Requests/...`; never validate inline in a
  controller and never trust the client. `authorize()` on the Form Request returns
  `true` — authorization is a separate concern handled by
  `$this->authorize(...)` against a Policy.
- **Invariants that survive validation throw `ValidationException::withMessages([...])`
  inside the transaction** (e.g. the ≥1-live-destination guard in
  `ProxyController::store`/`update`). This rolls the transaction back and surfaces
  as a normal field error in the Inertia error bag — do **not** invent a JSON error
  envelope for Inertia routes.
- **Not-found is a 404 via route-model binding**, not a hand-thrown message; the
  team scope + `SubstituteBindings` ordering makes cross-team ids 404 (see
  architecture.md → Security baseline). The public ingest route returns **404 with
  no existence disclosure** on an unknown/soft-deleted token (ADR-006).
- **Multi-write use cases run in `DB::transaction(...)`;** throwing inside rolls
  back. Keep business rules inside the transaction body, not scattered.
- **User-facing feedback for successful mutations** is a flash toast:
  `Inertia::flash('toast', ['type' => 'success', 'message' => __('...')])`,
  followed by `to_route(...)` (Post/Redirect/Get). User-facing strings go through
  `__()` so they stay translatable.
- **Proposed default (no prior precedent):** for a genuinely unexpected failure
  (not user-correctable), let it bubble to Laravel's handler rather than
  swallowing it; if you must present it, use a flash toast with `type => 'error'`
  and a **generic** message — never leak an exception message, stack trace, SQL,
  or secret to the client.

**TS / Vue (codifies observed):**
- **Consume server validation errors from the Inertia error bag** — `form.errors`
  with `useForm`, or the `errors` slot prop with `<Form v-slot="{ errors }">`.
  Render each next to its field with the shared `InputError` component, and set
  `:aria-invalid` + `aria-describedby` on the input (see `ProxyForm.vue`). On
  submit error, move focus to the first `[aria-invalid="true"]` field.
- **Consume flash toasts** via `initializeFlashToast()` → `vue-sonner`; the toast
  `type` must be a valid sonner method (`success` / `error` / ...) matching the
  server payload. Do not build a parallel notification path.
- **Do not throw for expected server errors** in components; rely on the Inertia
  error bag / flash channel. Reserve thrown errors for genuine programmer bugs.
- **Affordance gating derives client-side (ratified by Owner direction 2026-08-03;
  ADR-009 Amendment B; architecture.md → Authorization).** Whether an
  action affordance (edit/delete button, menu item) *renders* is computed in the
  component from the **shared permission set** (page-level `ProxyPermissions`/
  `TeamPermissions` booleans) plus **record fields already on the serialized prop**
  (e.g. `proxy.is_creator`) — e.g.
  `permissions.canUpdateProxy && (proxy.is_creator || permissions.canUpdateAnyProxy)`.
  **Never** trigger a per-row round trip or a per-row server policy call to decide
  visibility, and never re-fetch to answer "can I see this control." Gating is
  display-only: the server Policy still enforces the action, so a rendered-but-
  unauthorized control is denied server-side (defence-in-depth, not a leak).

## Logging

**No logging precedent exists in `app/` today** (no `Log::` / `logger()` usage).
The rules below are **Proposed default (no prior precedent)** except the
never-log list, which is a **codification of the security baseline** (ADR-006,
`IngestTokenService`) and is binding regardless.

- **Use the Laravel `Log` facade** (channels/handlers configured in
  `config/logging.php`), not `error_log`/`echo`/`dump`.
- **Proposed default — levels:**
  - `error` — an operation failed unexpectedly (delivery attempt exhausted its
    retries, an unhandled integration failure).
  - `warning` — recoverable or suspicious but handled (a delivery that will be
    retried, a rejected ingest request).
  - `info` — significant domain events worth an audit trail (proxy created,
    token rotated) — identifiers only.
  - `debug` — developer diagnostics; must be safe to disable in production.
- **Proposed default — format:** prefer **structured context arrays** over
  interpolated strings: `Log::info('proxy.created', ['proxy_id' => $proxy->id,
  'team_id' => $proxy->team_id])`. Log stable identifiers (`proxy_id`, `team_id`,
  `delivery_attempt_id`), never whole models or request payloads.
- **Never log (binding — security baseline / ADR-006):** ingest token plaintext
  or its hash, any `encrypted`-cast column value, passwords, 2FA secrets or
  recovery codes, session tokens/cookies, `Authorization`/bearer headers, `.env`
  values or other credentials, and **raw inbound webhook payloads** (they may
  carry third-party signing secrets or customer PII). If a value must be
  referenced, log a non-reversible identifier, never the secret. This applies
  equally to APM capture and to analytics that serialize Inertia props.

## Comments and documentation in code

### Budget (active) — a comment must not outweigh its code

Ruled by the Project Owner on 2026-09-01, after review of this codebase found
methods carrying twenty lines of comment for twenty lines of code. The
"explain why, not what" rule below is right and stays; it is not a licence to
write an essay in a source file.

- **A comment block must be shorter than the code it explains.** If the
  reasoning genuinely needs more room than that, it does not belong in the
  file. Put it in the plan, the ADR or a `docs/fixes/` note, and leave a
  one-line comment citing it.
- **Ceilings.** Inline comment: 3 lines. Method docblock: 2 lines of prose plus
  whatever tags PHPStan needs. Class docblock: 5 lines. Exceeding one is a
  signal to move the text, not to reformat it.
- **Cite, do not restate.** `(AC18)`, `ADR-027`, or a path to a fix note carries
  the whole argument in a few characters and stays correct when the argument is
  amended. A paragraph reproducing that argument is a second copy that will
  drift from the first.
- **No history in source.** Who ruled what, on what date, in response to which
  review finding, is what git log, `docs/` and the pull request are for. A
  comment describes the code as it stands now. "Amended 2026-09-01 after
  review-18 finding 5" is history; "the destination's configured method, not an
  unconditional POST (AC17)" is the code.
- **The exceptions, and they are narrow.** Spend the words where being wrong is
  expensive and the reason is invisible in the code: a security invariant, a
  fail-closed choice, a deliberate non-obvious omission, or a footgun that will
  look like a bug to the next reader. `OutboundAddressGuard`'s pinning rationale
  earns its length. A grace-period arithmetic line does not.

Applies equally to test files. A test name that states the behaviour needs no
comment restating it.

**PHP (codifies observed):**
- **Class-level PHPDoc** states purpose in one or two lines and cites the driving
  ADR/PRD where relevant (`IngestTokenService`, `StoreProxyRequest`).
- **Method PHPDoc** gives a one-line summary; add `@param` / `@return` **only
  where they carry type information PHPStan L7 needs** — array shapes
  (`list<array{id: int|null, url: string, http_method: string}>`), generics on
  Eloquent relations (`BelongsToMany<User, $this, Membership, 'pivot'>`,
  `HasMany<Membership, $this>`), and collection generics
  (`Collection<int, Membership>`). Don't restate a fully-typed signature in prose.
- **Model `@property` / `@property-read` blocks** document columns and relations
  for IDE + static analysis; keep them in sync with migrations and casts. Use
  `@use HasFactory<Factory>` on the trait import and the `#[Fillable([...])]`
  attribute for mass-assignment.
- **Inline comments explain *why*, not *what*** — invariants, security rationale,
  and acceptance-criterion references (`// Guard the min-1 live invariant before
  commit`, `(AC1/AC2)`). Delete commented-out code; git is the history.

**TS / Vue:**
- **Codifies observed:** inline comments are used sparingly to explain non-obvious
  rationale (e.g. focusing the first invalid field), and short doc comments appear
  on exported types where the shape isn't self-describing (`DestinationRow`).
- **Proposed default (no prior precedent):** use TSDoc `/** ... */` on exported
  composables, `lib` helpers, and non-obvious exported types; skip redundant
  comments on self-describing code. Prefer precise TypeScript types over prose —
  a good type is the documentation.

## Dependencies

**Package managers (codifies stack.md):** Composer (`composer.lock`) for PHP and
**pnpm** (`pnpm-lock.yaml`, pinned via `packageManager`) for JS/TS — **not npm**.
Both lockfiles are committed and CI installs from them.

- **Prefer a well-maintained existing library over reinventing the wheel — and
  say so.** The team is encouraged to *suggest* established packages rather than
  hand-roll equivalent functionality; surfacing a candidate is free and welcome.
  This does **not** bypass the gate below: **suggesting is encouraged; adopting
  still needs Owner sign-off** via an ADR. Raise the suggestion, then escalate.
- **Adding any new runtime dependency (Composer or pnpm) requires an
  Owner-approved ADR** and must stay within `docs/stack/stack.md`; a stack change
  is itself an ADR. This is the project default and is binding — do not add a
  dependency to work around a gap without escalating first (architecture.md →
  Requirements).
- **Commit the updated lockfile in the same change** as the manifest edit; never
  commit a `package.json`/`composer.json` change without its lockfile, and never
  introduce a second package manager or a `package-lock.json`.
- **Proposed default (no prior precedent):** dev-only tooling and version bumps of
  already-approved dependencies do **not** each need a fresh ADR, but must keep the
  lockfile committed and stay within the approved major versions in stack.md; a
  **major** upgrade or a new tool in the build/test toolchain is a stack change and
  needs an ADR. Automated dependency PRs are in scope of Dependabot **for GitHub
  Actions only** today (`.github/dependabot.yml`).
- **Security note (codifies stack.md gap):** there is currently **no automated
  vulnerability scanning for Composer/pnpm dependencies** and no SAST/secret
  scanning in CI — this is a known gap flagged for an Owner decision. Until it is
  closed, the ADR proposing a new dependency must note its maintenance/security
  posture (maintained, license, transitive weight) so the Owner can weigh it.
