> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M5 — Sensitive-fields configuration surface

## T10 — Validation and persistence: `sensitive_fields` (AC13; plan § Validation)
- **Description:** `sensitive_fields` — `nullable`, `array`, `max:100`; `sensitive_fields.*` —
  `string`, `max:128`, non-blank after trim, on both `StoreProxyRequest` and `UpdateProxyRequest`.
  Server-side the list is trimmed and de-duplicated by normalised form (via T4's `normalise()`) before
  persistence. Additions are per-proxy (AC13) — no team-level list exists or is read.
- **Dependencies:** T4
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`,
  `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - A duplicate addition (by normalised form) is not stored twice; a blank/whitespace-only entry is
    rejected server-side.
  - Additions persist per proxy; a second proxy in the same team is unaffected (AC13).
  - Removing an addition never removes a default (the default list is not stored per-proxy at all —
    it is code, not data).
- **Testing:** `tests/Feature/Proxies/SensitiveFieldsPersistenceTest.php` (new) — the dedup case, the
  blank-entry rejection, the per-proxy isolation case.
- **Completion notes:** Done. `sensitive_fields`/`sensitive_fields.*` rules added to both
  `StoreProxyRequest` and `UpdateProxyRequest` (`nullable, array, max:100` / `string, max:128,
  regex:/\S/` — the regex rejects a blank/whitespace-only entry without a closure rule, matching this
  app's existing rule-array style). `ProxyController::sensitiveFieldAdditions()` (new private helper)
  trims each submitted name, drops blanks, and de-duplicates by `SensitiveFields::normalise()`,
  keeping the first occurrence's original spelling; wired into both `store()`'s `Proxy::make()` array
  and `update()`'s `$proxy->update()` array, alongside the existing response/retry fields.

  **Absent `sensitive_fields` on update clears previously saved additions** — this field follows the
  same full-replace-on-submission convention as `destinations`/`response_body`/etc. (whatever the form
  sends is what's persisted), not the write-only "absent means leave unchanged" contract this feature
  uses elsewhere for actual secret fields (verification secret, credential). This task's own binding
  constraint 8 lists T20/T23/T29/T30 as the write-only fields that rule applies to; `sensitive_fields`
  is not among them, and `ProxyForm.vue` (T12) always submits the full in-session list, exactly like
  `destinations`. Pinned by a dedicated test so a future change to this doesn't drift silently.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "SensitiveFieldsPersistenceTest|ProxyStoreTest|ProxyUpdateTest|ProxyRequestValidationTest"` all
  green (74 tests, 239 assertions); full-suite run deferred to the end of this batch.

## T11 — `defaultSensitiveFieldNames` page prop on `create()` and `edit()` (AC12; plan Technical ruling 3, Implementation Note 11)
- **Description:** `ProxyController::create()` and `::edit()` emit `defaultSensitiveFieldNames`,
  sourced directly from `SensitiveFields::DEFAULTS` (T4) — never a hand-typed copy. Per Technical
  ruling 3, this is a page prop on both routes, not a `ProxyResource` key (`create()` renders no proxy
  resource at all; `ProxyResource` also serves `index()`, which must gain nothing).
- **Dependencies:** T4
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - `create()` and `edit()` both emit `defaultSensitiveFieldNames` equal to `SensitiveFields::DEFAULTS`
    exactly (same 23 entries, same order).
  - `index()`'s response gains no new key.
- **Testing:** `tests/Feature/Proxies/ProxyControllerPagePropsTest.php` (new or extended) — asserts the
  prop's presence and exact content on `create`/`edit`, and its absence on `index`.
- **Completion notes:** Done. `ProxyController::create()` and `::edit()` both now emit
  `'defaultSensitiveFieldNames' => SensitiveFields::DEFAULTS` as a page prop, sourced directly from
  the T4 constant — no hand-typed copy. `index()` is untouched. New test file (none existed before
  this task) asserts the prop's exact content on both routes via `assertInertia`/`where`, and its
  absence on `index()` via `missing()`.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  ProxyControllerPagePropsTest` green (3 tests, 29 assertions); full-suite run deferred to the end of
  this batch.

## T12 — Screen 2: `ProxyForm.vue` Sensitive fields section (AC12, AC13, AC19, C4, N4; plan Implementation Note 16)
- **Description:** New section, placed after **Response** and before **Destinations** (Screen 2
  placement). Renders every default name **literally**, one badge per entry from
  `defaultSensitiveFieldNames`, wrapped in `flex flex-wrap` — never truncated, never summarised (C4).
  Below it, the proxy's own additions as removable badges with an Add input/button. No
  enable/disable-obfuscation control anywhere (**N4** — obfuscation is always on, AC19).
- **Dependencies:** T10, T11
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - Every one of the 23 default names renders as its own badge, none removable, none truncated or
    hidden behind "show more."
  - Adding a name (Enter or the Add button) appends a removable badge and clears the input, without a
    server round trip until the form saves.
  - Removing an addition badge removes only that addition, never a default.
  - No obfuscation toggle, switch, or "enable" control exists anywhere on this section.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow D, against a
  production build: the full default list renders and wraps correctly at 360px; add/remove works
  in-session; saving persists additions and a fresh view of an existing payload reflects the new list
  with no migration (AC19, cross-checked against T9's rendering).
- **Completion notes:** Done. New "Sensitive fields" `fieldset`/`legend` section added to
  `ProxyForm.vue`, placed after Response body and before Destinations exactly as Screen 2 specifies:
  "Always hidden" renders one `Badge` (`secondary`, no ×) per literal entry in the
  `defaultSensitiveFieldNames` prop; "Also hidden for this proxy" renders `form.sensitive_fields` as
  removable `Badge`s (`outline`, a plain inert-free `<button>` × with an `aria-label`), an Add
  input/button, Enter-to-add, silent no-op on a blank or already-present entry, and no bordered empty
  box when there are no additions (matching the Response-card precedent). No enable/disable-obfuscation
  control exists anywhere (N4). `addSensitiveField()`/`removeSensitiveField()` are plain array
  mutations on `form.sensitive_fields`, mirroring `form.destinations`' existing in-session-only
  semantics — nothing is sent to the server until the form saves.

  **Necessary supporting plumbing, not scope creep:** `ProxyResource` gained a `sensitive_fields` key
  (`$this->sensitive_fields ?? []`) alongside the existing `response_body`/retry keys it already
  exposes for the shared Create/Edit form — without it, `ProxyFormResource` (which extends
  `ProxyResource`, the Edit form's sole data source) would have had no way to pre-fill a proxy's
  existing additions, and design-10 Flow D step 1 states the additions render on Edit as well as
  Create. `sensitive_fields` is a plain per-proxy configuration column, not "security status" —
  Technical ruling 3's sibling-`security`-prop rule is scoped to verification/signing status, which
  this isn't — so it follows the same `ProxyResource` convention as `response_body`/`retry_*` rather
  than a new prop. `ProxyFormProxy`/`ProxyDetail`/`ProxyListItem` (TypeScript) and `Create.vue`/
  `Edit.vue` updated to carry it through; `Create.vue`/`Edit.vue` also now accept and forward the
  `defaultSensitiveFieldNames` page prop from T11.

  **Manual verification performed** (own local Sail dev environment, own seeded data, deleted
  immediately after — same recipe as T9): confirmed `public/hot` absent and ran `pnpm run build`
  first. On a proxy seeded with one addition (`ssn_last4`):
  - The Edit form rendered exactly 23 "Always hidden" badges, in the exact order and spelling of
    `SensitiveFields::DEFAULTS` (asserted programmatically via Playwright, not just visually).
  - `ssn_last4` pre-filled as a removable "Also hidden for this proxy" badge on page load.
  - Typing `api_secret_key` and pressing Enter appended a new removable badge and cleared the input;
    clicking `ssn_last4`'s × removed only that badge.
  - Saving and reloading the Edit page showed the change persisted (`api_secret_key` present,
    `ssn_last4` gone) — confirming the full-replace persistence path from T10.
  - **AC19 cross-check against T9:** seeded a fresh event on the same proxy with an
    `api_secret_key` field and revealed its payload — it rendered as `[Hidden]` with the "addition"
    C3 description, with no migration or backfill involved, exactly as AC19 requires.
  - Screenshots taken in both light and dark mode; the section (legend, help text, badge wrapping,
    Add row) is legible and correctly styled in both.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green. `composer lint`, `composer types:check` and `./vendor/bin/sail test --parallel` green (931
  tests, 4374 assertions) — the full suite, run at the close of this batch (T7–T12).

---
