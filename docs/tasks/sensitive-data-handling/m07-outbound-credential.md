> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M7 — Outbound credential

## T26 — `App\Support\OutboundHeaders` (credential + verification-strip only) and the AC37 byte-identical regression (AC27, AC30, AC38; plan § Architecture C, Implementation Notes 3–4, R9)
- **Description:** Pure class, the five-step composition (this task builds the credential-and-strip
  half; T34 adds the signing half later): forward the inbound header set minus ADR-008's constant
  strip list, minus **this proxy's** verification headers (the member-named `shared-secret` header, or
  the three `standard-webhooks` headers — AC27), then add the **destination's** credential header
  (verbatim value, no scheme prefix added — AC30), removing any forwarded header whose **lowercased**
  name collides with it first (AC38), then merge. **This is the only place an outbound header set is
  built** — no other class may construct one. Do **not** add the credential/verification header names
  to `DeliveryUnit::STRIPPED_HEADERS` — that list is unrelated and untouched.
- **Dependencies:** T2
- **Files:** `app/Support/OutboundHeaders.php` (new)
- **Acceptance Criteria:**
  - **AC37, this task's named regression, run first:** a destination with no credential, on a proxy
    with no verification, produces a **byte-identical** header set to the pre-#10 baseline (compare
    against `DeliveryUnit::outboundHeaders()`'s pre-#10 output directly, not by inspection).
  - Under `shared-secret`, the member-named header is stripped outbound; under `standard-webhooks`, all
    three `webhook-*` headers are stripped — and a proxy with verification **off** still forwards a
    `webhook-signature` a sender happened to send (AC43 — nothing strips it when there is no
    verification configuration to strip it *for*).
  - A forwarded inbound header whose name collides with the credential header (case-insensitively) is
    displaced by the credential (AC38, R9).
  - The credential value is sent **verbatim** — `Bearer abc123` arrives unchanged, no prefix added.
- **Testing:** `tests/Unit/Support/OutboundHeadersTest.php` (new) — the AC37 byte-identical case as its
  own named test method, the verification-strip cases per scheme, the AC43 off-verification-forwards
  case, the AC38 collision case, the verbatim-value case.
- **Completion notes:** Done. `App\Support\OutboundHeaders::build()` takes a `DeliveryUnit`, this
  proxy's verification header name(s), and the destination's credential header name/value; it starts
  from `$unit->forwardHeaders()` (the existing ADR-008 strip, reused rather than duplicated — no
  second copy of `DeliveryUnit::STRIPPED_HEADERS` exists anywhere), strips the verification names
  case-insensitively, then overlays the credential header, first stripping any forwarded header whose
  lowercased name collides with it (AC38, R9). `DeliveryUnit::STRIPPED_HEADERS` itself is untouched
  (Implementation Note 4).

  **Naming note, not a deviation:** this task's own Acceptance Criteria names the AC37 comparison
  target as `DeliveryUnit::outboundHeaders()`, a method that does not exist on that class — the
  pre-#10 method `DeliverToDestination::send()` actually calls is `forwardHeaders()`. Read as the
  same intent (the pre-#10 outbound header set) and implemented/tested against the real method name.

  Six tests: the AC37 byte-identical regression (named first, per the task's own instruction), the
  `shared-secret`/`standard-webhooks` per-scheme strip cases, the AC43 off-verification-forwards case,
  the AC38 collision case, and the verbatim-no-prefix case.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter OutboundHeadersTest`
  green (6 tests, 12 assertions); full-suite run deferred to the end of this batch (T26-T33).

> **Note (ADR-026 Decision B, 2026-08-28) — partially superseded, narrower than T16–T21/T23–T25.** The
> AC27 verification-header strip step this task built inside `OutboundHeaders::build()` is removed at
> **T53** — inbound verification no longer exists, so there is no per-proxy verification header left to
> strip, and `build()`'s signature narrows accordingly (drops the verification header-names parameter).
> **Everything else this task built stands unchanged**: the credential composition, the AC30 verbatim
> value, the AC38 collision rule, and the AC37 byte-identical regression (which becomes a *stronger*
> claim once fewer headers are stripped overall — see T55). Do not delete this file or its surviving
> methods when acting on the removal at T53.

## T27 — `DeliveryUnitResolver`: load the proxy `withTrashed()`, carry verification header names (R3; plan § Architecture C, Implementation Note 5) — **delivery path**
- **Description:** `DeliveryUnitResolver` must load `$delivery->proxy()->withTrashed()->firstOrFail()`
  — `Delivery::proxy()` is a plain `belongsTo` on a model using `SoftDeletes`, so an unqualified load
  returns `null` for a soft-deleted proxy and would blow up at runtime (PHPStan cannot see this).
  `DeliveryUnit` gains the proxy's verification header name(s), needed by `OutboundHeaders`' strip step
  at send time.
- **Dependencies:** T2
- **Files:** `app/Services/DeliveryUnitResolver.php`
- **Acceptance Criteria:**
  - A retry against a **soft-deleted** proxy resolves successfully and still applies AC27's header
    strip correctly (the regression this task exists to prevent — `ProcessIngestedWebhook` and
    `DeliverToDestination::settleDelivery()` are the existing precedents for this load shape).
  - `DeliveryUnit` carries the resolved proxy's verification header name(s) for the destination's
    outbound build.
- **Testing:** `tests/Unit/Services/DeliveryUnitResolverTest.php` (extend existing) — the soft-deleted
  proxy + retry case, an assertion that `DeliveryUnit` carries the verification header name(s).
- **Completion notes:** Done. `DeliveryUnitResolver::resolve()` now also loads
  `$delivery->proxy()->withTrashed()->firstOrFail()` and passes a new
  `verificationHeaderNames` list into the resolved `DeliveryUnit` — the member-named header under
  `shared-secret`, the three fixed `webhook-*` names under `standard-webhooks`, or `[]` when
  verification is not required (AC43). `DeliveryUnit`'s new constructor parameter defaults to `[]`
  rather than being made required, so every pre-#10 `DeliveryUnit` construction site across the
  existing delivery-path test suite (unrelated to #10) stays valid unchanged — only
  `DeliveryUnitResolver`, the single resolver, ever supplies a non-empty value.

  Four new tests: the soft-deleted-proxy-plus-retry regression (asserting both that resolution still
  succeeds and that the header names are carried), the `standard-webhooks` three-fixed-names case, and
  the no-verification-configured empty-list case, alongside the existing suite (all still green).

  `composer lint`, `composer types:check`, `./vendor/bin/sail test --filter DeliveryUnitResolverTest`
  (7 tests, 21 assertions) and `./vendor/bin/sail test --filter "DeliveryUnitTest|DeliverToDestinationTest"`
  (21 tests, 101 assertions, confirming the new defaulted constructor parameter didn't disturb any
  existing `DeliveryUnit` construction site) all green; full-suite run deferred to the end of this
  batch (T26-T33).

> **Note (ADR-026 Decision B, 2026-08-28) — partially superseded, narrower than T16–T21/T23–T25.** The
> verification-header-name carrying this task added — `DeliveryUnitResolver::verificationHeaderNamesFor()`
> and the `verificationHeaderNames` constructor argument/property on `DeliveryUnit` — is removed at
> **T53**. **The `withTrashed()` proxy load this task also built stands unchanged.** It was introduced
> carrying two reasons (the AC27 verification header names and the proxy's live signing set); the first
> is gone, and the second — `Delivery::proxy()` is a plain `belongsTo` on a `SoftDeletes` model, so an
> unqualified load would resolve `null` for a soft-deleted proxy at runtime where PHPStan cannot see it
> — was always independently sufficient (ADR-026 Decision 3). Do not remove the `withTrashed()` load
> when acting on T53.

## T28 — `DeliverToDestination::send()` calls `OutboundHeaders` (AC17, AC30, AC32; plan § Architecture C) — **delivery path**
- **Description:** `send()` gains the one build point: composes the outbound header set through
  `OutboundHeaders` (T26) before dispatching the HTTP request. Both attempt 1 (`asJob()`) and attempts
  2..N (`RetryDelivery`) funnel through this same `send()` call, so the credential and verification
  strip apply identically to the original attempt, every retry, and every replay — no separate code
  path for any of them.
- **Dependencies:** T26, T27
- **Files:** `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:**
  - The credential is present on attempt 1, on a retry, and on a replay of the same delivery, and
    absent on every other destination of the same proxy that has no credential.
  - The request body is unchanged by this task — only headers are built through `OutboundHeaders`; the
    dispatched bytes are identical to before this task for a non-credentialed destination (composes
    with T26's AC37 regression).
- **Testing:** extends `tests/Feature/Delivery/DeliverToDestinationTest.php` — the attempt-1/retry/
  replay credential-presence case, the cross-destination absence case.
- **Completion notes:** Done. `DeliverToDestination::send()` now builds the outbound header set through
  `OutboundHeaders::build($unit, $unit->verificationHeaderNames, $unit->destination->credential_header_name,
  $unit->destination->credential_secret)` before dispatching — `credential_secret` decrypts through the
  model's `encrypted` cast at this read, in the send path, never earlier. Both `asJob()` (attempt 1) and
  `RetryDelivery` (attempts 2..N) funnel into this same `send()` call unchanged, so the credential and
  verification strip apply identically to every attempt and every replay with no separate code path.

  Two new tests: one exercises attempt 1, a retry (same delivery, attempt 2), a replay (a fresh delivery,
  attempt 1 again) and a second, uncredentialed destination on the same proxy in one test, asserting via
  `Http::recorded()` (order-preserving) that the credential header is present on the first three requests
  and absent on the fourth; the second pins that an uncredentialed destination's dispatched body bytes are
  unchanged (composes with T26's AC37 regression).

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs
  `DeliverToDestination::run()` inline under this suite, so this is strong evidence the *logic* --
  `OutboundHeaders` called correctly, with the correct arguments, on every code path that reaches
  `send()` -- is correct, but it exercises none of Horizon's real async/queued dispatch. No claim of
  having exercised the async path is made.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter DeliverToDestinationTest`
  (20 tests, 84 assertions) green; full-suite run deferred to the end of this batch (T26-T33).

## T29 — Credential validation and persistence (AC30, AC33; plan § Validation)
- **Description:** `destinations.*.credential_header_name` — `required_with:destinations.*.credential_secret`,
  `string`, `max:128`, HTTP field-name pattern, default `Authorization` supplied by the form (not the
  schema). `destinations.*.credential_secret` — `nullable`, `string`, `max:1024`; absent means "leave
  unchanged," reconciled by the destination row's existing `id`-based matching.
- **Dependencies:** T2
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`,
  `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - A header name without a secret value fails validation only if `credential_secret` is present
    (`required_with` is directional as written — a header name alone with no secret is a no-op state,
    confirm this reading against T30's UI, which never submits a header name with no secret).
  - An empty `credential_secret` on a destination that already has one leaves it stored unchanged.
  - A new destination row added this session persists its credential exactly like an existing row's
    replacement.
- **Testing:** `tests/Feature/Proxies/CredentialValidationTest.php` (new) — one case per bullet.
- **Completion notes:** Done. `destinations.*.credential_header_name`
  (`required_with:destinations.*.credential_secret`, string, max:128, the same HTTP field-name regex
  `verification_header_name` already uses) and `destinations.*.credential_secret` (`nullable`, string,
  max:1024 — no `min` length, unlike `verification_secret`'s `min:8`, exactly as this task specifies)
  added to both `StoreProxyRequest` and `UpdateProxyRequest`. `Destination`'s `#[Fillable]` list gains
  `credential_secret` (it already had `credential_header_name`/`credential_set_at` from T2) — without
  it, mass-assigning the secret would throw a `MassAssignmentException`.

  `ProxyController::destinationRows()` normalises the two new fields the same `isset()`-then-type-check
  way as `url`/`http_method`, to `''` when absent/non-string. New private
  `destinationCredentialAttributes(array $row): array` is the single write-only decision point: `[]`
  (a no-op — Eloquent's `update()` only touches passed keys, so omission is how "leave unchanged" is
  achieved, the same idiom `verification_secret` already uses) whenever `credential_secret === ''`;
  otherwise all three columns together (`credential_header_name` defaulted to `Authorization` only as
  a defensive fallback, `credential_secret`, `credential_set_at` = `now()`). Wired into `store()`'s
  create array, and both branches of `update()`'s reconciliation (existing-row replace, new-row create)
  — a new destination row persists its credential through the exact same helper as an existing row's
  replacement, satisfying the task's third bullet structurally rather than by a separate code path.

  PHPStan required the docblock's `credential_set_at` type to read `\Carbon\CarbonImmutable`, not
  `Illuminate\Support\Carbon` — this project's `now()` returns `CarbonImmutable` (the same gotcha
  recorded from the T20-T25 batch); not a deviation, just the accurate return type of `now()` here.

  Four tests, one per bullet: header-name-without-secret passes validation and persists no credential;
  secret-without-header-name 422s; an empty `credential_secret` against an already-configured
  destination leaves the header name, secret and `credential_set_at` all byte-for-byte unchanged; and a
  brand-new row added this session persists its credential through the same path as a replacement.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "ProxyStoreTest|ProxyUpdateTest|ProxyRequestValidationTest|SensitiveFieldsPersistenceTest|CredentialValidationTest"`
  all green (78 tests, 253 assertions); full-suite run deferred to the end of this batch (T26-T33).

  **Rework (review-10 finding 4).** `destinationCredentialAttributes()`'s blank-secret branch was a
  total no-op, so an edited `credential_header_name` was silently discarded unless the secret was also
  replaced — but `design-10` Screen 3 keeps the Header name input visible and editable in the
  credential-set state, and `DestinationRows.vue`/`ProxyForm.vue` submit the edited value regardless.
  Gave the method a `bool $hasExistingCredential` parameter (true only for an `update()` row matched to
  an existing `Destination` whose `credential_set_at` is not null; the `store()` call site and the
  new-row branch of `update()` always pass the `false` default, since neither has an existing credential
  to preserve). The blank-secret branch now writes `credential_header_name` alone when
  `$hasExistingCredential` is true and the submitted name is non-empty, leaving `credential_secret` and
  `credential_set_at` untouched — a header-name-only edit does not count as (re)setting the credential,
  so it does not bump `credential_set_at` and does not make the Show page's "Credential set — changed
  {date}" line lie in either direction; only a non-empty secret still moves that date. A destination with
  no credential yet still gets `[]` for a blank secret regardless of header name, so a row can still
  never come to rest holding a header name with no secret. Added
  `test_a_changed_header_name_with_a_blank_secret_persists_the_new_name_and_leaves_the_secret_and_changed_at_unchanged`
  to `CredentialValidationTest`, alongside the existing same-name preservation test. `composer lint`,
  `composer types:check`, and the full suite (`./vendor/bin/sail test --parallel`, 1019 tests) all green.

## T30 — Screen 3: `DestinationRows.vue` Credential disclosure (AC30, AC33; Flow F; plan § Architecture E)
- **Description:** Each destination row gains a `Collapsible` — trigger label "Add credential" /
  "Credential: set", default **expanded** only when already set (flagged design call 2). Expanded
  content: Header name (default `Authorization`, always visible/editable), the shared write-only secret
  shape (unset input / set status + Replace). No rotation language anywhere on this block — AC29's
  exclusion (no "previous," no countdown, no end-overlap control); replacing takes effect immediately,
  stated in the help copy.
- **Dependencies:** T29
- **Files:** `resources/js/components/DestinationRows.vue`
- **Acceptance Criteria:**
  - A row with an existing credential opens expanded by default; an unconfigured row opens collapsed.
  - Replace reveals a pre-filled header name and a blank secret field; saving replaces immediately, no
    overlap language anywhere in this block.
  - A present-but-empty Replace field left untouched does not clear the stored credential on save.
  - Removing the destination row removes its credential with it, no separate prompt.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow F, against a
  production build: default-expand rule; Replace flow; save-time semantics; row removal.
- **Completion notes:** Done. `DestinationRows.vue` gains a `Collapsible` block per row (the same
  primitive `events/Show.vue`'s attempt-history section already uses — no new dependency), trigger
  label `Credential: set` / `Add credential`, `:default-open="row.has_credential === true"` (reka-ui's
  uncontrolled initial-state prop — read once at mount, exactly the "default" the AC asks for, and the
  member can still toggle it afterward). Content: an always-visible/editable Header name input, and
  Screen 1's write-only shared shape reused per row — a collapsed "Credential set — changed {date}" +
  Replace when `credentialIsSet(row)`, else a blank `Input type="password" autocomplete="off"`. No
  rotation language anywhere in the block, and the help copy states the immediate-replace semantics
  verbatim from `design-10`. Removing a row removes its whole `DestinationRow` object, so its Credential
  block goes with it structurally — no separate code path exists for it to diverge from.

  **Necessary supporting plumbing, not scope creep (T12's own precedent followed):** the "already set"
  status this section needs cannot come from the `security` prop — that carries `destinations` only
  from **T32**, a later task, and `plan-10`'s own binding note states no task depends on a later one, so
  `security.destinations` cannot be the transport for a task ordered before it. It also cannot be the
  right transport in substance: `security.destinations` (T32/ruling 4) exists specifically so the Show
  page's analytics-sourced Destinations table can cover a **soft-deleted** destination with historical
  traffic, which the Edit form's live-only relation structurally excludes — the two surfaces have
  different row sets by design. So `DestinationResource` (used by both `ProxyResource` and, through it,
  `ProxyFormResource` — the Edit form's sole data source) gains `credential_header_name` (already
  visible, non-secret), `has_credential` (derived from `credential_set_at !== null`, never reading — and
  so never decrypting — `credential_secret` itself, the same not-touching-the-value discipline
  `SecretStore::statusFor()` already establishes), and `credential_changed_at`. `DestinationRow`
  (`types/proxies.ts`) gains the two writable fields (`credential_header_name`, `credential_secret`) and
  two mount-seeded read-only display fields, plus a local-only `credential_replacing` UI flag (kept on
  the row object itself, not a parallel index-keyed structure, so it always travels correctly with its
  row through add/remove — unlike an index-keyed `Set`, which would silently misattribute state after a
  mid-list removal). None of the three added keys (`has_credential`, `credential_changed_at`,
  `credential_replacing`) has a matching server-side validation rule, so `FormRequest::validated()`
  silently drops them even if submitted — verified directly by the new backend test below, not just
  argued from Laravel's documented behaviour. `ProxyForm.vue`'s `form.destinations` initialisation and
  `DestinationRows.vue`'s `addRow()` both default `credential_header_name` to `'Authorization'` and
  `credential_secret` to `''` for every row (existing or brand-new) — matching Screen 3's states table
  exactly, and letting `Create.vue`'s minimal initial shape (`[{ url: '', http_method: 'POST' }]`) stay
  untouched, since `ProxyForm.vue`'s own mapping supplies the defaults uniformly for both Create and Edit.

  New backend test (`tests/Feature/Proxies/ProxyUpdateTest.php`,
  `test_edit_prefill_carries_credential_status_but_never_the_secret_value`): asserts the Edit page's
  `proxy.destinations` correctly carries `has_credential`/`credential_header_name`/`credential_changed_at`
  for both a credentialed and an uncredentialed row, and — the more important half — that the raw secret
  value never appears anywhere in the response body and the string `credential_secret` never appears as
  a JSON key at all. This directly answers the standing instruction that a credential must never be
  returned in a response.

  **Manual verification performed against a live Vite dev server, not a production build** — `public/hot`
  was present (`http://[::1]:5174`) and, checked directly, a real dev-server process was listening there
  (not a stale leftover file — review-07 Finding 8's trap, checked rather than assumed), so this is
  honestly a live-dev-server verification, not the production-build claim this task's own Testing line
  asks for; `pnpm run build` was still run and is green, but nothing was checked against its output in
  the browser. Seeded a fresh Sail-DB user/team/proxy (own local dev DB, deleted again immediately after)
  with two destinations, one credentialed (`X-Api-Key`) and one not, logged in via Playwright, opened the
  Edit form, and confirmed via DOM assertions (not just visual inspection): the credentialed row's trigger
  read "Credential: set" with `data-state="open"` (Radix/reka-ui's own expanded-state attribute) and its
  status line/header value were visible without clicking; the uncredentialed row's trigger read
  "Add credential" with `data-state="closed"` and no content visible until clicked; clicking Replace on
  the credentialed row swapped the status line for a blank password input; expanding the uncredentialed
  row showed the header pre-filled `Authorization` and a blank secret. Screenshot taken confirming layout
  or overflow issues at the section. Dark-mode was not screenshotted for this task (not named in this
  task's own Acceptance Criteria, unlike T9/T12's rendering tasks) — noted rather than silently skipped.

  `pnpm run format:check`, `pnpm run lint:check` and `pnpm run types:check` all green; `pnpm run build`
  green (with the live-dev-server caveat above). `composer lint`, `composer types:check` and
  `./vendor/bin/sail test --filter ProxyUpdateTest` (10 tests, 75 assertions) green; full-suite run
  deferred to the end of this batch (T26-T33).

## T31 — Screen 3: Remove credential control (Q-10-03 item 1; correction B3; `plan-10` § Revision A, technical ruling 15)
- **Description:** **Unblocked** — `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`
  is RESOLVED (Principal Engineer, 2026-08-27), recorded as `plan-10` § *Revision A*, technical ruling
  15. `design-10`'s amendment gate requires a **Remove credential** control beside Replace on Screen
  3's expanded disclosure, carried to the server as a signal distinguishable end to end from an
  ordinary blank Replace field (correction B3) — never collapsible into the "leave unchanged"
  semantics T29/T30 already build. **The ruled transport is a sibling boolean per destination row,
  `destinations.*.remove_credential`** — a real JSON boolean, `sometimes`/`boolean`, submitted
  alongside the row's `id`/`url`/`http_method`/`credential_header_name`/`credential_secret`. Absent or
  `false` means no removal; `true` means "clear this destination's credential on save."
  `credential_secret` **keeps exactly one meaning and gains no second one** — a new value, or absent
  meaning leave unchanged; ruling 15 exists precisely to prevent a sentinel value on that field from
  ever meaning anything else (the reserved-sentinel alternative was rejected on failure direction: a
  lost boolean keeps the credential, harmlessly costing a second click, whereas a collapsed
  absent-versus-empty distinction turns every abandoned Replace into a silent removal — and
  `ProxyController::destinationRows()`'s existing `isset()` normalisation would have silently killed
  the sentinel, since `isset()` is `false` for an explicit `null`).
  Concretely: add the **Remove credential** ghost button beside Replace
  (`aria-label="Remove credential for {url}"`); clicking it resets the row to the unconfigured
  in-session presentation (header name back to `Authorization`, secret status back to unset, a blank
  Secret input); `ProxyForm.vue`'s submit `transform()` sends `remove_credential: true` for that row
  unless the member has since typed a new secret into the now-blank field, in which case `transform()`
  sends `remove_credential: false` — typing into an unconfigured row has always meant "set this
  secret," so the row's later act supersedes the staged removal, and this is the states table read
  forwards rather than a new decision. `destinations.*.credential_secret` gains
  `prohibited_if:destinations.*.remove_credential,true` on both `StoreProxyRequest` and
  `UpdateProxyRequest` — a deterministic 422 for a malformed request that sends both, which this
  application's own UI can never produce given the `transform()` rule above. `ProxyController`'s
  reconciliation step, for a submitted row with `remove_credential: true` that reconciles to an
  existing destination by `id`, writes NULL to **all three** credential columns —
  `credential_header_name`, `credential_secret`, `credential_set_at` — together, so a row can never
  come to rest holding a header name with no secret; the result is byte-identical to a destination
  that never had a credential. A row with no `id` (created this session) has nothing to remove, so the
  flag is a no-op there; the rule is declared on both requests regardless, since `ProxyForm.vue` is one
  component serving Create and Edit. `ProxyController::destinationRows()` reads the flag positively —
  `($row['remove_credential'] ?? false) === true` — so presence-versus-absence is never load-bearing on
  this key the way it would have been for the rejected sentinel shape.
- **Dependencies:** T30
- **Files:** `resources/js/components/DestinationRows.vue`, `resources/js/pages/proxies/ProxyForm.vue`,
  `resources/js/types/proxies.ts`, `app/Http/Requests/StoreProxyRequest.php`,
  `app/Http/Requests/UpdateProxyRequest.php`, `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - **The distinguishability pair, asserted in one test so the two cases can never be collapsed
    independently later:** an update carrying a present-but-empty `credential_secret` and no
    `remove_credential` leaves the stored credential byte-identical; an update carrying
    `remove_credential: true` for the **same row on the same route** nulls it — same route, same row,
    different outcome, both assertions in one test.
  - A saved removal leaves `credential_header_name`, `credential_secret` and `credential_set_at` all
    NULL, asserted with a raw query, indistinguishable from a destination that never had a credential.
  - An update carrying both `remove_credential: true` and a non-empty `credential_secret` is a 422 and
    changes nothing on the row.
  - `remove_credential: true` on a row with no `id` is a no-op: the row is created with NULL credential
    columns and no error.
  - Clicking Remove credential in-session, then typing a new secret into the now-unconfigured row
    before saving, persists the **new** secret rather than removing the credential (the `transform()`
    supersession rule).
  - No confirmation dialog is added (nothing stored is exposed; the credential can always be
    re-entered).
- **Testing:** `tests/Feature/Proxies/CredentialRemovalTest.php` (new) — the five items above, one test
  method each, per `plan-10` § *Revision A*'s added § *Test strategy* items (grouped with the existing
  destination-credential tests). Manual verification of the UI control per `design-10` Screen 3's
  states table, including the added post-save row and the Remove-then-retype supersession case, against
  a production build.
- **Completion notes:** Done, built to ruling 15 exactly as written. Backend: `destinations.*.remove_credential`
  (`sometimes`, `boolean`) added to both `StoreProxyRequest` and `UpdateProxyRequest`;
  `destinations.*.credential_secret` gains `prohibited_if:destinations.*.remove_credential,true` on both.
  `ProxyController::destinationRows()` reads the flag positively (`($row['remove_credential'] ?? false)
  === true`) — never `isset()`, which is the exact hazard ruling 15 names (`isset()` is `false` for an
  explicit `null`, which would have silently killed the rejected sentinel-on-`credential_secret`
  alternative). `destinationCredentialAttributes()` checks `remove_credential` first — validation's
  `prohibited_if` already guarantees `credential_secret` is empty whenever it's true, so there is no
  ordering ambiguity with the "leave unchanged" branch below it — and nulls all three credential columns
  together when true, so a row can never come to rest holding a header name with no secret.

  Frontend: `DestinationRows.vue` gains a ghost **Remove credential** button beside **Replace**
  (`aria-label="Remove credential for {url}"`), visible only in the same `credentialIsSet(row)` branch
  Replace already renders in (design-10's states table lists Remove only there, not mid-Replace).
  Clicking it sets a new local-only `credential_removed` flag (kept on the row object itself, the same
  pattern `credential_replacing` already established at T30, so it always travels correctly with its row
  through add/remove rather than needing an index-keyed parallel structure that a mid-list removal could
  misattribute) and resets the row's in-session presentation — header back to `Authorization`, secret
  back to blank — exactly like an unconfigured row. `ProxyForm.vue`'s `submit()` `transform()` is the
  single place the real `remove_credential` signal is computed, per row, at submit time:
  `row.credential_removed === true && row.credential_secret === ''` — so typing a new secret into the
  now-unconfigured row after clicking Remove supersedes the staged removal and persists the new secret
  instead, exactly as the task's `transform()` rule specifies. `remove_credential` itself is never part
  of `DestinationRow`'s in-session shape (only added by `transform()` at submit), matching the task's own
  framing that it is a transport concern, not a UI state one.

  Five backend tests, one per Acceptance Criteria bullet: **the distinguishability pair in one test**
  (a present-but-empty Replace, no `remove_credential`, leaves the credential byte-identical; the same
  row on the same route with `remove_credential: true` nulls it — both assertions in one test method, so
  the two cases cannot be split apart independently later, exactly as the task requires); the raw-query
  all-three-columns-null assertion; the `remove_credential: true` + non-empty `credential_secret` 422
  case (`prohibited_if`, changing nothing); the no-`id`-row no-op case; and the `transform()` supersession
  case, asserted at the transport boundary this test suite actually exercises (submitting exactly what
  the superseding `transform()` output would be — `remove_credential: false` alongside a non-empty
  `credential_secret` — since the supersession decision itself is a frontend-only computation with no
  server-observable trace of "Remove was clicked and then undone").

  **No confirmation dialog was added** (AC6) — verified directly (`[role="dialog"]` count is `0` both
  before and after clicking Remove credential), not just asserted by omission.

  **Manual verification performed against a live Vite dev server, not a production build** — same
  `public/hot` caveat as T30 (a real dev-server process confirmed listening at `http://[::1]:5174`, not a
  stale file); `pnpm run build` is green but nothing was checked against its output in the browser.
  Seeded a fresh Sail-DB user/team/proxy (own local dev DB, deleted again immediately after) with one
  credentialed destination, logged in via Playwright, opened the Edit form, and confirmed via DOM
  assertions: the Remove credential button is present with the correct interpolated `aria-label`; no
  `[role="dialog"]` element exists on the page before or after clicking it; clicking it changes the
  trigger to "Add credential", resets the header input to "Authorization", and shows a blank password
  input; typing into that now-blank input succeeds (the supersession outcome itself — that this persists
  as the new secret rather than a removal — is the backend test's job, already covered above, since
  nothing in the DOM alone can distinguish "typed after Remove" from "typed on a row that was never
  configured"). Screenshot taken confirming layout.

  `pnpm run format:check`, `pnpm run lint:check` and `pnpm run types:check` all green; `pnpm run build`
  green (with the live-dev-server caveat above). `composer lint`, `composer types:check` and
  `./vendor/bin/sail test --filter "CredentialValidationTest|CredentialRemovalTest|ProxyStoreTest|ProxyUpdateTest|ProxyRequestValidationTest"`
  (79 tests, 286 assertions) green; full-suite run deferred to the end of this batch (T26-T33).

## T32 — `security` prop: the `destinations` map (AC30, AC33; plan Technical ruling 4)
- **Description:** Extends `ProxySecurityResource` (T22) with `destinations: { [id]: { has_credential,
  credential_changed_at } }`. **Does not** touch `DestinationBreakdownRow` or `DeliveryStatistics`
  (Technical ruling 4 — putting security flags on #11's analytics DTO would make the analytics service
  read secret columns, reopening a shape plan-11 certified). `Show.vue` looks up by the row's existing
  `id`.
- **Dependencies:** T22, T29
- **Files:** `app/Http/Resources/ProxySecurityResource.php`
- **Acceptance Criteria:**
  - The `security` prop's `destinations` map has one entry per destination the proxy has, keyed by id,
    `has_credential`/`credential_changed_at` only — never a value, never a length.
  - `DestinationBreakdownRow` and `DeliveryStatistics` are unchanged by this task (grep-level check —
    no diff in either file).
- **Testing:** extends `tests/Feature/Proxies/ProxySecurityResourceTest.php` — the `destinations` map
  shape, the untouched-analytics-DTO assertion.
- **Completion notes:** Done. `ProxySecurityResource` gains a `destinations` key: `$this->destinations()
  ->withTrashed()->get(['id', 'credential_set_at'])->mapWithKeys(...)`, keyed by destination id,
  `has_credential` (derived from `credential_set_at !== null`, never reading — and so never decrypting —
  `credential_secret` itself) and `credential_changed_at` only. Deliberately `withTrashed()`: T33's
  Destinations table (`Show.vue`) renders the union of live destinations and any soft-deleted one with
  historical traffic (`DeliveryStatistics::destinationBreakdown()`), so this map's id coverage has to be
  a superset of the live relation alone, matching ruling 4's own framing. `DestinationBreakdownRow`/
  `DeliveryStatistics` are untouched — grepped directly in a test, not just claimed.

  **Defect found and fixed, load-bearing for this task's entire purpose:** the naive implementation
  serialized `destinations` as an unkeyed JSON *array*, silently discarding every destination id — found
  by testing against a real HTTP response (`$resource->response()->getContent()`), not assumed from the
  unit-level `toArray()` call, which looked correct in isolation and would have hidden this. Root cause:
  `Illuminate\Http\Resources\ConditionallyLoadsAttributes::removeMissingValues()` calls `array_values()`
  on any array (including a nested one, recursively) whose keys are **all** numeric, discarding the keys
  entirely — destination ids are exactly that case, and this fires on every level of a resource's `toArray()`
  output, not just the top. Laravel's own fix for this, `#[PreserveKeys]` (a class-level attribute added to
  `ProxySecurityResource`), was verified to be scoped correctly: it only changes behaviour for a nested
  array whose keys are already all-numeric, which is exclusively the `destinations` map here (`verification`'s
  keys are all string names — `scheme`, `header_name`, etc — so `removeMissingValues()` never treated that
  branch as reindexable in the first place, before or after the attribute). Re-verified against the real
  response after the fix; the existing `verification`-shape tests (T22) still pass unchanged, confirming no
  side effect on the sibling sub-object. This is a defect in the naive approach, not a deviation from the
  task's own Acceptance Criteria — the task explicitly requires "keyed by id", which the array form silently
  broke.

  Two new tests: the `destinations` map's shape (a credentialed, an uncredentialed, and a **soft-deleted**
  credentialed destination, each asserted by id — the soft-deleted case is what proves `withTrashed()` is
  actually wired, not merely present in a comment — plus the secret value/key-name-never-in-response
  assertion, mirroring T30's own discipline), and the grep-level untouched-analytics-DTO check.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter ProxySecurityResourceTest`
  (7 tests, 82 assertions) green; also re-ran the full `Proxies` feature suite (191 tests, 1010 assertions)
  as a regression check for the `#[PreserveKeys]` change, green. Full-suite run deferred to the end of this
  batch (T26-T33).

## T33 — Screen 5: Destinations table Credential badge (AC30; plan § Architecture E)
- **Description:** Extends the existing Destinations table on `proxies/Show.vue` (design-11's table)
  with one inline `Badge` — "Credential" — rendered only when `security.destinations[id].has_credential`
  is true. No new column, no new action; the badge is a status indicator, never a button. No `Signed`
  badge is added here or anywhere on this table (signing is proxy-wide — Screen 4b, T41, carries that
  fact instead).
- **Dependencies:** T32
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:**
  - A destination with a credential shows the badge; one without shows nothing extra in that cell.
  - No new table column exists; the existing Actions cell is unchanged.
- **Testing:** no frontend test harness — **manual verification** against a production build: badge
  presence/absence matches `has_credential` for a small fixture of destinations.
- **Completion notes:** Done, to `design-10` Screen 5's pseudocode exactly (badge inline in the
  Destination cell, after the URL span, `outline` variant, text "Credential", no new column, Actions
  cell untouched). One difference from the spec's literal pseudocode, a naming detail within this task's
  own discretion: the spec writes `v-if="destination.hasCredential"` as if that were a field on
  `DestinationBreakdownRow` itself; per plan-10 Technical ruling 4 that DTO is untouched by this feature,
  so a new `hasCredential(destination)` helper looks the flag up by the row's existing id in the
  `security.destinations` map (T32) instead — the same "looks up by id" transport ruling 4 and this
  task's own description both name. Defaults `false` for an id the map doesn't carry (none in practice,
  since T32's map is built `withTrashed()` over every destination the proxy has), keeping the lookup
  total rather than partial.

  **Manual verification performed against a live Vite dev server, not a production build** — same
  `public/hot` caveat as T30/T31 (a real dev-server process confirmed listening, not a stale file);
  `pnpm run build` is green but nothing was checked against its output in the browser. Seeded a fresh
  Sail-DB user/team/proxy (own local dev DB, deleted again immediately after) with one credentialed and
  one uncredentialed destination, logged in via Playwright, opened the Show page, and confirmed via DOM
  text extraction (not just visual inspection): the credentialed row's text includes "Credential", the
  uncredentialed row's does not; the table header row is unchanged (`Destination`, `Delivery success`,
  `Attempt success`, `Latency (avg)`, `Actions` — five columns, no sixth). Screenshot confirms the badge
  renders inline beside the URL, correctly styled, with the Actions cell (`View events`) unchanged.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all green
  (with the live-dev-server caveat above). `composer lint`/`composer types:check` unaffected by this
  frontend-only task (no PHP file touched); full-suite run at the close of this batch (T26-T33) below.

---
