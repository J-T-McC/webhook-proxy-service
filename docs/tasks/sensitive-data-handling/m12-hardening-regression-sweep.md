> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M9 — Cross-cutting hardening and final regression sweep

Physically placed here, after M11 and M10, per ADR-026 § *Sequencing and build order*, Steps 6 and 7.
T45's narrowed scope and T46–T48 are unrelated to header policy and could in principle run earlier, but
ADR-026 groups the whole of M9 after the removal/strip-reduction work as one continuous finish to the
branch, and this document follows that grouping. **Renamed from "…and the verification sweep"** — there
is no verification sweep left; T45 loses its verification half (see its own note) and T49 loses
Flows A–C and folds in T44.

## T45 — Old-input scrub (R4; plan Technical ruling 7)
> **Narrowed by ADR-026, 2026-08-28.** This task originally also added `verification_secret` to
> `bootstrap/app.php`'s `dontFlash` list; that half is dropped — the field no longer exists, removed at
> **T53** — leaving the `destinations.*.credential_secret` `failedValidation()` scrub as this task's
> whole scope, which is also its harder half (`Arr::forget()`'s lack of wildcard support is what forces
> the manual override in the first place). `bootstrap/app.php` is no longer touched by this task.
- **Description:** `destinations.*.credential_secret` cannot be excluded from Laravel's old-input flash
  via `bootstrap/app.php`'s `dontFlash` list — `Arr::forget()` (the flashing mechanism) has no wildcard
  support. `StoreProxyRequest`/`UpdateProxyRequest` override `failedValidation()` to scrub the nested
  secret values from `$request->input()` before the validation exception propagates. Inertia's client
  form keeps its own state and never reads `old()`, so nothing functional is lost.
- **Dependencies:** T29
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:**
  - A 422 on the proxy form leaves **no submitted** `destinations.*.credential_secret` in the flashed
    old input, for both `Store` and `Update`.
- **Testing:** `tests/Feature/Proxies/OldInputScrubTest.php` (new) — asserts session-flashed input after
  a failed validation contains no credential secret, for both `Store` and `Update`.
- **Completion notes:** Added a `failedValidation()` override to both `StoreProxyRequest` and
  `UpdateProxyRequest` that walks the submitted `destinations` array and `unset()`s each row's
  `credential_secret` before calling `parent::failedValidation()`.

  **A real gap surfaced while writing the first test, corrected before commit rather than shipped and
  found in review:** the plan's own rationale ("`merge()` rewrites the request's own input bag, so the
  scrubbed value is what the exception handler reads and flashes") does not hold. `FormRequestServiceProvider`
  builds the validated `FormRequest` via `Request::createFrom($app['request'], $request)` — the FormRequest
  is a *copy* initialized from the container-bound `request` singleton, never the same object. Laravel's
  exception handler builds the redirect-with-input response from the container-bound singleton
  (`Illuminate\Foundation\Http\Kernel::handle()` binds `app->instance('request', $request)` once, and that
  same instance flows through `render()`/`convertValidationExceptionToResponse()`), which a FormRequest's
  own `$this->merge()` never touches. Verified by reading `FormRequestServiceProvider::boot()`,
  `Request::createFrom()`, and `Kernel::handle()` in vendor — no test exists to lean on for this, since
  no prior task in this feature has scrubbed old input at all. The fix resolves the container-bound
  `request` via `$this->container->make('request')` (the container is already set on `$this` by the time
  `failedValidation()` runs, per `FormRequestServiceProvider`'s `resolving()`-before-`afterResolving()`
  callback order) and merges the same scrubbed `destinations` array into it when it is a distinct object
  from `$this`. Both instances are scrubbed — the FormRequest's own bag as defence-in-depth for any other
  reader of `$this->input()`, the container-bound singleton because it is what actually gets flashed.

  An initial `?->`/`instanceof Request` guard around the container-bound lookup was removed after
  `composer types:check` flagged both as vacuous — PHPStan resolves `FormRequest::$container` and
  `Container::make('request')` precisely enough from vendor docblocks/service-container aliasing that
  neither check can ever be false. Replaced with a plain `$this->container->make('request')` call and an
  identity check (`$bound !== $this`).

  `tests/Feature/Proxies/OldInputScrubTest.php` (new): one test per request class, each posting/putting
  a payload that fails validation on `name` while carrying a full `destinations` row (`url`,
  `http_method`, `credential_header_name`, `credential_secret`), then reading `session('_old_input')`
  directly and asserting `credential_secret` is absent from the flashed `destinations.0` row while `url`
  survives, plus a `assertStringNotContainsString` sweep of the whole serialized flash for the literal
  secret value.

  Gates: `composer lint`, `composer types:check` both green (0 errors after the guard-clause fix above).
  Full suite, `./vendor/bin/sail test --parallel` — **1006/1006 passing, 4765 assertions**: +2 tests
  (`OldInputScrubTest`'s Store/Update cases) and +12 assertions (6 each) over the 1004/4753 baseline.
  No frontend file touched; `pnpm` gates not run.

  Folded in one of the three stale-docblock corrections flagged as out of scope by T52–T54: none of
  them land naturally in this task's own files (`ProxySecret.php`, `DestinationResource.php`,
  `ProxySigningOverlapController.php` are untouched by T45–T48's Files lists), so all three are
  corrected together in one small `docs` commit after T48 instead — see that commit's message for
  detail.

## T46 — Capture-failure report wrap: no interpolated SQL (R5; plan Technical ruling 8)
- **Description:** `QueryException::formatMessage()` interpolates bindings into the exception message.
  Today those bindings are ciphertext (the `encrypted` cast runs before binding), so no plaintext has
  ever reached a log — but an encrypted copy of payload content in a log file is still a copy AC3's
  enumeration does not include and no retention pass touches. Wrap the capture-failure `report($e)`
  call in `IngestController` so what is reported names the `ingest_id`, the proxy, and the SQLSTATE —
  never the interpolated statement. **Note (ADR-026):** the `IngestController` this task edits is
  T53's simplified, post-removal version — the capture-failure `report($e)`/`try`/`catch (Throwable)`
  block this task wraps is exactly the block ADR-026 Decision 2 states is untouched by the removal, so
  nothing about this task's own target code changes; the added dependency below only sequences the two
  tasks' edits to the same file to avoid a stale patch.
- **Dependencies:** T19, T53
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:**
  - A simulated `QueryException` on a payload-bearing insert produces a report carrying `ingest_id`,
    proxy identifier, and SQLSTATE, and **no SQL statement** — including no ciphertext binding.
  - The same treatment applies to a failed secret write (`proxy_secrets`/`destinations.credential_secret`).
- **Testing:** `tests/Unit/Http/IngestControllerReportWrapTest.php` (new) — simulates a `QueryException`
  during capture and asserts the reported payload's shape.
- **Completion notes:** The production `reportCaptureFailure()` wrap in `IngestController` (already
  present on this branch, ADR-026-noted as untouched by T53's removal) was reviewed and found correct
  as written: it reports a fresh, unchained `RuntimeException` carrying only `ingest_id`, `proxy_id`
  and, when the caught exception is a `QueryException`, `sqlstate=` — never the original message and
  never set as `previous`, so no interpolated SQL statement or ciphertext binding can resurface via
  exception-chain formatting. The wrap sits around the whole `DB::transaction()` closure, so it is
  table-agnostic by construction: whichever write inside it fails (`webhook_events`, or
  `fifo_dispatches`, or in principle a `proxy_secrets`/`destinations.credential_secret` write elsewhere
  in the same transaction) reaches the same sanitized `catch (Throwable $e)` block. No production code
  changed for this task.

  `tests/Unit/Http/IngestControllerReportWrapTest.php` (new): `Exceptions::fake()` plus a mocked
  `WebhookEventCapture::capture()` throwing (1) a real `QueryException` built with an interpolatable
  SQL string and a ciphertext-shaped binding, asserting the one reported exception's message contains
  `proxy_id=` and `sqlstate=23000` but neither the binding nor the SQL text, and has no `previous`; and
  (2) a plain `RuntimeException` (standing in for a non-`QueryException` failure, e.g. a secret-table
  write), asserting the reported message carries no `sqlstate=` and not the original exception's own
  message text. Both drive the real HTTP path (`$this->post()`) rather than a direct controller
  invocation, so the `abort(500)` half of the catch block renders through the real exception handler
  instead of throwing out of the test; `Exceptions::fake()`'s explicit `report()` capture is unaffected
  by that render.

  Gates: `composer lint`, `composer types:check` both green (0 errors). Full suite,
  `./vendor/bin/sail test --parallel` — **1008/1008 passing, 4771 assertions**: +2 tests and +6
  assertions (3 each) over the 1006/4765 T45 baseline. No frontend file touched; `pnpm` gates not run.

## T47 — Prune/trim/retention ordering test (Q-10-02 finding B)
- **Description:** One test asserting `queue:prune-failed --hours 168`, Horizon's `failed`/`monitored`
  trim (10080 minutes), and the resolved `retention.days` window (default 30, env-overridable) stay
  correctly ordered — the failed-job/Horizon windows below the retention window — so a failure record
  cannot outlive the erase meant to remove the content it once referenced. Two of the three are
  literals in different files and one is env-overridable, hence one dedicated test rather than
  three separate assumptions.
- **Dependencies:** none
- **Files:** none production; test-only (unless the ordering is found to already be wrong, in which
  case flag rather than silently fix — this is a pre-existing configuration this feature is pinning,
  not changing)
- **Acceptance Criteria:** a single test reads all three values from their actual sources (the
  `routes/console.php` literal, Horizon's config, `config('retention.days')`) and asserts the ordering
  holds at whatever the current environment resolves them to.
- **Testing:** `tests/Unit/Config/RetentionOrderingTest.php` (new).
- **Completion notes:** Ordering confirmed already correct — no gap, nothing flagged. Read all three
  values from their actual sources at test time: `queue:prune-failed`'s `--hours` is read off the
  registered `Schedule::events()` entry's built command string (same technique as
  `PruneFailedJobsScheduleTest`, `routes/console.php`), Horizon's `failed`/`monitored` trim windows via
  `config('horizon.trim.failed')`/`config('horizon.trim.monitored')` (`config/horizon.php`), and the
  retention window via `config('retention.days')` (`config/retention.php`, env-overridable). Currently
  resolves to 10080 minutes (7 days) for `queue:prune-failed` and both Horizon trim windows, against
  43200 minutes (30 days) for retention — comfortably ordered. One test, three `assertGreaterThan`
  checks (retention above each of the other two), all against the live-resolved values rather than
  copied literals, so the test would catch a future env-driven `RETENTION_DAYS` regression as well as a
  literal edit to either other file.

  Gates: `composer lint`, `composer types:check` both green (0 errors). Full suite,
  `./vendor/bin/sail test --parallel` — **1009/1009 passing, 4776 assertions**: +1 test and +5
  assertions over the 1008/4771 T46 baseline. No frontend file touched; `pnpm` gates not run.

## T48 — Secret-absence sweep (R6)
- **Description:** A sweep across every proxy-bearing response — `show`, `edit`, `index`, the events
  pages, and the payload endpoint — asserting the absence of every stored secret's value, including a
  deliberately constructed case where the proxy's `secrets` relation has been eager-loaded (`->with('secrets')`)
  before the response is built, proving the two independent guards (never serialized into a resource,
  and `$hidden = ['value']`) both hold even under that mistake.
- **Dependencies:** T3, T14, T22, T32, T38
- **Files:** none production expected; test-only unless a gap is found
- **Acceptance Criteria:** no response from any of the five surfaces above contains any stored secret's
  value, under both the ordinary query path and the eager-loaded-relation path.
- **Testing:** `tests/Feature/Proxies/SecretAbsenceSweepTest.php` (new).
- **Completion notes:** Swept all five surfaces — no gap found, both guards (`ProxySecret` never
  serialized into a resource; `ProxySecret::$hidden = ['value']`) hold, nothing flagged.

  `tests/Feature/Proxies/SecretAbsenceSweepTest.php` (new), 7 tests. A shared fixture
  (`proxyWithEverySecret()`) builds one proxy carrying a destination credential and a live signing
  secret (via `SecretStore::replace()`) plus one captured event, so every surface below has something
  to leak if a guard were broken.

  Ordinary query path — one test per surface, real HTTP round trip, asserting neither secret literal
  appears in the response body: `index` (`proxies.index`), `show` (`proxies.show`), `edit`
  (`proxies.edit`), the events pages (`proxies.events.index`, `proxies.events.show`), and the payload
  endpoint (`proxies.events.payload`).

  Eager-loaded-relation path — one dedicated test (`test_secrets_eager_loaded_before_serialization_still_never_leaks`):
  loads the proxy with `->load(['destinations', 'secrets'])` (the mistake `ProxySecret`'s own doc-block
  calls out as never happening today: "this relation is never eager-loaded onto a resource") and
  serializes it directly through the three resource classes that carry a `Proxy` onto the five surfaces
  — `ProxyResource` (index/show/events pages), `ProxyFormResource` (edit), `ProxySecurityResource`
  (show/edit). Asserts no resource output contains a `"secrets"` key at all (guard 1: never serialized
  into a resource) or either secret's literal value, and separately asserts
  `$eagerLoaded->secrets->first()->toArray()` has no `value` key (guard 2: `$hidden = ['value']`),
  proven independently of whether any resource reads the relation.

  No gap found — both guards hold under eager-loading exactly as `ProxySecret`'s doc-block claims; the
  test exists to keep it true, not because it was found false. Nothing flagged for T48.

  Gates: `composer lint` (auto-fixed one `no_unused_imports` on this new file), `composer types:check`
  both green (0 errors). Full suite, `./vendor/bin/sail test --parallel` — **1016/1016 passing, 4809
  assertions**: +7 tests and +33 assertions over the 1009/4776 T47 baseline. No frontend file touched;
  `pnpm` gates not run.

## T49 — Whole-surface manual verification pass (`design-10` Flows D–I) and final regression sweep
- **Description:** The feature's closing task, mirroring `plan-11`'s T29 — re-checks `plan-10`'s
  Implementation Notes and § Explicitly out of scope list against the finished diff (not against
  earlier tasks' own completion notes), then walks every surviving `design-10` flow against a real
  production build, both themes, at 360px. **Narrowed by ADR-026, 2026-08-28: Flows A, B and C
  (inbound verification) are withdrawn along with Screens 1 and 4 — there is nothing left on those
  flows to walk.** **Folds in T44** (Task Planner's call — ADR-026 § *Sequencing and build order*
  flagged the question rather than making it): Flows G, H and I — T44's own original scope — are
  walked here instead, since T44 would otherwise sit immediately before this task re-walking the
  identical ground with nothing in between to regress. **This task's flow walk is therefore Flows D
  through I, six flows, not nine.** If a queued/async environment is available
  (`QUEUE_CONNECTION=redis`, Horizon), a spot check of one signed dispatch through the real async path
  is recommended given this document's delivery-path caveat, though not required by any AC below (AC47
  — no numeric or environment target). **Sequencing:** T50 and T55 both run before this task, not
  after — both change delivery-path header behaviour (a header-name rename and a `STRIPPED_HEADERS`
  reduction), and this task's own byte-identical re-run and flow walkthrough must certify the header
  set item #10 actually ships, not a pre-rename, pre-reduction one. **AC29's ruling-2a disclosure
  requirement narrows to one surface**: T23 (the inbound half) is withdrawn along with Screen 1; only
  T43's signing-surface disclosure remains to confirm.
- **Dependencies:** T9, T12, T30, T31, T33, T41, T42, T43, T45, T46, T47, T48, T50, T52, T53, T54, T55
- **Files:** none; verification-only
- **Acceptance Criteria:**
  - Every Implementation Note (1–23) holds against the finished code, checked by inspection of the
    diff, read against ADR-026's own removal set rather than against the pre-removal plan text where
    the two differ.
  - Every item in `plan-10` § Explicitly out of scope, and this document's own ADR-026 scope-discipline
    addition (no inbound verification of any kind), are confirmed **not** built.
  - `design-10` Flows D, E, F, G, H and I each pass end to end against a production build (`public/hot`
    absent), both themes, 360px — **not** Flows A, B, C (withdrawn) and not a separate T44 pass (folded
    in here).
  - The AC37 (T26) and AC63 (T35) byte-identical regressions both still hold against the finished tree,
    re-run one final time, against the **narrower** `STRIPPED_HEADERS` (T55).
  - AC29's cap-of-two and its ruling-2a disclosure are confirmed present on the **signing surface**
    (T43) — the inbound surface no longer exists to check.
  - The finished outbound signing headers are the **renamed** set — `WebhookProxy-Id`,
    `WebhookProxy-Timestamp`, `WebhookProxy-Signature` (T50) — nowhere a `webhook-id`/`webhook-timestamp`/`webhook-signature`
    key on an outbound request; `Authorization`, `Cookie` and every provider-signature header pass
    through unconditionally (T55); and no inbound verification scheme, header, secret or surface exists
    anywhere in the finished tree (T52–T54).
  - **This pass certifies against the landed amendments, not a summary of them**: PRD-10 `##
    Amendment C` (Product Manager, commit `3015b28` — AC23–AC28, AC46, AC50–AC53 withdrawn in full;
    AC29, AC11, AC10, AC44, AC43, AC55, AC60, AC38, AC64 narrowed, AC29's signing half confirmed
    surviving) and the `design-10` revision (Designer, commit `622b454` — Screens 1/4 and Flows A/B/C
    withdrawn, correction B2 restated on the signing dialog). Re-read both documents directly at the
    time this task runs rather than trusting this task plan's own summary of them, in case either has
    moved again since this plan was last updated.
- **Testing:** manual, recorded in completion notes with concrete steps and observed outcomes.
- **Completion notes:** _pending_

## Handoff
- **Inputs:** `docs/plans/plan-10-sensitive-data-handling.md` (fully approved, all four Owner-approval
  flags ruled); `docs/product/prd-10-sensitive-data-handling.md` (Approved, `## Amendment A` and
  `## Amendment B` both ratified; **`## Amendment C` now committed, Product Manager, commit `3015b28`**
  — AC23–AC28, AC46 and AC50–AC53 withdrawn in full, AC29 (signing half survives, see M10's note),
  AC11, AC10, AC44, AC43, AC55, AC60, AC38 and AC64 narrowed rather than withdrawn);
  `docs/design/design-10-sensitive-data-handling.md` (Approved, as amended, both original gates closed
  — C1–C10 and B1–B4 all landed; **a further revision is now committed, Designer, commit `622b454`** —
  Screens 1 and 4 and Flows A, B and C withdrawn, correction B2 restated for the signing dialog, its
  one surviving surface); `docs/architecture/adr-021-secret-handling-and-rotation.md`,
  `docs/architecture/adr-022-inbound-verification-at-the-ingest-boundary.md` (superseded in full by
  ADR-026, kept for history), `docs/architecture/adr-023-outbound-request-contract.md`,
  `docs/architecture/adr-024-field-obfuscation-and-revealed-payload-envelope.md` (all Accepted);
  `docs/architecture/adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md`
  (Accepted, Project Owner, 2026-08-28 — M10/T50 only now, Decision 1 superseded in part by ADR-026;
  committed on branch `docs/adr-025-outbound-header-policy`, not yet merged onto this branch, see the
  Authority note at the top of this document); `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
  (Accepted, Project Owner, 2026-08-28 — M11/T52–T54 and M10/T55; committed at `38ac603` on this
  branch, no merge step needed); `docs/product/prd-16-configurable-inbound-verification.md`
  (**withdrawn**, Product Manager, commit `a7a32e5` — nothing left for it to configure once inbound
  verification is removed; `docs/architecture/prd-16-template-model-feasibility.md`, the separate
  feasibility study, is retained per ADR-026 § Impact and is unaffected by PRD-16's own withdrawal, no
  task here touches either); `docs/questions/prd-10-q-10-02-…` (RESOLVED), `prd-10-q-10-03-…`
  (RESOLVED), `prd-10-q-10-04-…` (RESOLVED), `prd-10-q-10-05-…` (RESOLVED, Principal Engineer — see
  `plan-10` § *Revision A*); `docs/standards/planning.md`; `docs/tasks/analytics-tasks.md` (the most
  recent prior task plan, whose house format this document follows).
- **Outputs:** this task plan; `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`
  (raised here, **RESOLVED** by the Principal Engineer, recorded as `plan-10` § *Revision A*, technical
  ruling 15 — T31 built to it).
- **Dependencies:** none new — no Composer package, no pnpm package, no stack change
  (`docs/stack/stack.md` untouched).
- **Outstanding Questions:** none. `Q-10-05` is RESOLVED; no task in this plan is blocked on anything
  outside this document. PRD-10 `## Amendment C` and the `design-10` revision are both now committed
  (see Inputs above); neither is a blocker for any task in this document, and T49's closing sweep
  re-certifies against both directly rather than against this plan's own summary of them.
- **Next Agent:** Senior Developer. **T1–T43 are already built and committed** (this branch's own git
  history). Resume at **T52** — the next unbuilt task in build order — not at the next numeric ID
  (T44, withdrawn) and not at T45 (M9 runs after M10/M11, see the milestone list above). Build order
  from here: **T52 → T53 → T54 → T50 → T55 → T45 → T46 → T47 → T48 → T49.** Every task is listed with
  an explicit Dependencies line; consult it, not the numeric sequence, for any task from T50 on.
