# Review Standards

## Severity definitions (active)
- **Blocker** — violates acceptance criteria, breaks functionality, or introduces a security defect. Blocks approval.
- **Major** — materially violates the technical plan or standards. Blocks approval.
- **Minor** — style or improvement; recorded as follow-up. Does not block.

## Review scope (active)
- Verify every task acceptance criterion and every PRD acceptance criterion.
- Run the test suite; do not trust claimed results.
- Findings cite a location and the violated criterion or standard.
- The Reviewer never edits code and never reviews their own work.

## Checklists

> **Status: Proposed — pending Project Owner approval.** Owned by the Reviewer
> (proposes) and Project Owner (approves). Each check derives from a ratified
> standard and cites it. `(manual — no automated coverage)` marks checks with no
> automated gate today (frontend a11y/Vue — no JS test framework, stack.md /
> testing.md). `(ADR-009)` marks checks that bind only once ADR-009 is **Accepted**
> — they apply to feature #2 permission/ownership work, not shipped item #1.

### Security
- No secret, ingest-token plaintext or hash, `encrypted`-cast value, password, 2FA/recovery code, session token/cookie, `Authorization`/bearer header, `.env` value, or raw inbound webhook payload is written to logs, APM, or Inertia-prop analytics — coding.md never-log list; architecture.md → Secret handling.
- Every proxy/team authorization decision lives in a Policy consuming `TeamPermission` via `hasTeamPermission(...)`; no policy or controller branches on a role literal (`role === 'Member'`) — architecture.md → Authorization; coding.md → Error handling.
- `ApplyTeamScope` is registered before `SubstituteBindings`; `TeamScope` adds a `team_id` predicate for authed users and constrains team-less users to sentinel id `0` (fail-closed, never global) — architecture.md → Authorization.
- Ingest tokens are 256-bit CSPRNG, stored as `SHA-256 BINARY(32)` hash for lookup plus an `encrypted`-cast display column; inbound resolution hashes the presented token — architecture.md → Secret handling (ADR-006).
- Ingest/bearer URLs are built from `config('ingest.url')`, never the request `Host` header — architecture.md → Secret handling (ADR-006).
- Validation is server-authoritative in Form Requests (never inline, never client-trusted); the Form Request `authorize()` returns `true` and the controller calls `$this->authorize(...)` against a Policy — architecture.md → Input validation; coding.md → Error handling.
- Destination URLs validate `url:https`; `http://` and scheme-less URLs are rejected — architecture.md → Input validation (Owner ruling PRD-01).
- Public ingest route asserts HTTPS at app layer, caps body size, per-token throttles, returns 404 with no existence disclosure on unknown/soft-deleted token, and is CSRF-exempt only because it sits outside the web group — architecture.md → API design / Public ingest hardening (ADR-006).
- `created_by` id-equality ownership is composed with (not instead of) the permission check; a null creator is a safe deny — `(ADR-009)` architecture.md → Authorization.

### Data / Migrations
- No lower layer references `Http/`; authorization appears only in Policies — architecture.md → Module boundaries.
- FK onDelete matches intent: owned children `cascadeOnDelete`, surviving actor/creator refs `nullable()->nullOnDelete()`, immutable fact tables default (restrict) `constrained()` and never cascade-cleaned — architecture.md → Data.
- `SoftDeletes` on mutable user-owned entities (`Team`, `Proxy`, `Destination`); no `deleted_at` on immutable fact rows (`delivery_attempts`); the `ingest_token_hash` UNIQUE is not scoped to `deleted_at`; span-deleted checks use `withTrashed()` — architecture.md → Data / Soft-delete policy.
- Migrations are forward-only in production (roll forward, not `down()`); no destructive backfills; pre-feature `created_by` stays null — architecture.md → Data.
- Naming: `snake_case`, plural tables, `<singular>_id` FKs, domain-meaningful pivots; access-pattern indexes present (composite filters, `BINARY(32)` UNIQUE for token hash) — architecture.md → Data.
- Persisted enums cast to their enum class; secrets use the `encrypted` cast; casts declared via the `casts()` method — architecture.md → Data.
- `created_by` uses `HasCreator` + a nullable `->constrained('users')->nullOnDelete()` column — `(ADR-009)` architecture.md → Creator convention.

### Backend code
- New files sit in the directory matching their layer role; controllers stay thin (authorize, Form Request, transaction, Inertia/redirect) — architecture.md → Module boundaries; coding.md → Project structure.
- Generated dirs are not hand-edited: `resources/js/actions/**`, `routes/**`, `wayfinder/**`, `components/ui/*` — regenerated via their tool — coding.md → Project structure.
- Multi-write use cases run in `DB::transaction`; invariants surviving validation throw `ValidationException::withMessages([...])` inside the transaction — coding.md → Error handling.
- No exception message, stack trace, SQL, or secret leaks to the client; unexpected failures bubble to Laravel's handler or surface as a generic `type => 'error'` toast — coding.md → Error handling.
- Successful mutations flash a Sonner toast and `to_route(...)` (Post/Redirect/Get); user strings go through `__()` — architecture.md → API design; coding.md → Error handling.
- Inertia props serialize through an `Http/Resources` resource with `$wrap = null`; no bespoke JSON error envelope for Inertia routes — architecture.md → API design.
- Naming: `StudlyCase` classes, role-suffixed class names, verb-first Actions, typed properties/params/returns (PHPStan L7) — coding.md → Naming.
- Any new runtime Composer/pnpm dependency has an Owner-approved ADR and a committed lockfile in the same change; no npm / `package-lock.json` — coding.md → Dependencies.

### Frontend / Accessibility
_No JS test framework exists (stack.md; testing.md T31 gap) — every check here is `(manual — no automated coverage)` unless noted._
- WCAG 2.1 AA: every interactive control reachable and operable by keyboard alone (Tab/Shift+Tab, Enter/Space, Esc); focus ring never suppressed — design.md → Accessibility baseline. (manual — no automated coverage)
- Each validated field renders `InputError`, sets `:aria-invalid` and `aria-describedby` (help + error ids); on failed submit focus moves to the first `[aria-invalid="true"]` field — design.md → Validation feedback; coding.md → Error handling. (manual — no automated coverage)
- Destructive actions use `AlertDialog` with Cancel (default-focused) before a `bg-destructive` Confirm; confirm disabled during the request; `open` ref is separate from the delete target, target reset only in `onFinish` — design.md → Confirmation dialogs (regression `89cfd71`/`19e73c7`). (manual — no automated coverage)
- Every submit control disables itself for the duration of its request — design.md → Loading / disabled states. (manual — no automated coverage)
- Feedback is a single Sonner toast whose `type` is a valid sonner method; no parallel toast/banner path; no `throw` for expected server errors — design.md → Success / error feedback. (manual — no automated coverage)
- Every input has a programmatically associated `Label` (not placeholder-only); icon-only/ambiguous controls carry a target-naming `aria-label`; Dialog/AlertDialog supply both `*Title` and `*Description`; colour is never the sole carrier of meaning — design.md → Screen-reader requirements. (manual — no automated coverage)
- No hardcoded colours or arbitrary radii; use semantic token utilities (`bg-card`, `text-muted-foreground`) and `rounded-lg/md/sm`; every surface works in both light and `.dark` palettes — design.md → Design system. (manual — no automated coverage)
- Reuse existing `ui/*` primitives before adding one; style variants follow the `cva` + exported `*Variants` + `defaultVariants` shape; icons come only from `@lucide/vue` — design.md → Component library / Variant pattern. (manual — no automated coverage)
- Prop keys mirroring a server payload keep the Resource's `snake_case` keys; client-only props are `camelCase` — coding.md → Naming.

### Testing
- Tests run green locally via the suite, not by trusting claimed results — review.md → Review scope.
- Factory records use `createQuietly()` / `createManyQuietly()`, never `create()` / `createMany()` — testing.md → Quiet factory creation.
- No test class declares `RefreshDatabase` / `FasterRefreshDatabase`; rollback comes from base `Tests\TestCase` — testing.md → Database refresh.
- The production `BelongsToCurrentTeam` auto-assign hook is covered by a real `new Model(...)->save()` test (not a factory); cross-team isolation tests set distinct `team_id`s explicitly — testing.md → Quiet factory creation.
- Every task and PRD acceptance criterion is verified against the running code — review.md → Review scope.
- Vue/component behavior and a11y are verified by inspection only — no JS test framework — stack.md; testing.md. (manual — no automated coverage)

### Toolchain / CI gates
- `composer lint` (Pint `laravel` preset) is clean — stack.md → Formatting.
- `composer types:check` (PHPStan/Larastan level 7) is clean — stack.md → Static Analysis.
- `pnpm types:check` (`vue-tsc --noEmit`) and `pnpm lint:check` (ESLint) are clean — stack.md → Static Analysis.
- Prettier `--check` (with tailwind plugin) passes over `resources/` — stack.md → Formatting.
- CI `composer ci:check` is green on the PR — stack.md → CI/CD.

### Documentation / Process
- Approval is recorded in the artifact **and** `docs/status.md` — documentation.md → Active conventions.
- Every artifact carries Status, Author, Approval, and a Handoff section — documentation.md → Active conventions.
- Docs are dense (one line per fact, bullets/tables, link don't duplicate) — documentation.md → Write dense.
- No superseded/resolved/fixed document is deleted or relocated; lifecycle is carried by Status fields + the status.md index — documentation.md → Document lifecycle.
- Significant, hard-to-reverse decisions and stack deviations have an ADR that traces to a PRD acceptance criterion — architecture.md → Requirements.
