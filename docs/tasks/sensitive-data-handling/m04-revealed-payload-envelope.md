> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M4 — Revealed-payload envelope

## T8 — `ProxyEventPayloadController`'s dual response shape (AC15, AC18, AC21, AC22; plan § Architecture D, ADR-024 Decision 2)
- **Description:** The controller branches on whether the stored body parses as JSON. **Parses:**
  `application/json` envelope `{format, document, obfuscated}` — `document` is the payload re-encoded
  via `PayloadObfuscator` (T6) with every sensitive value replaced by `null`; `obfuscated` is the
  pointer index, each value `"default"` or `"addition"`. **Does not parse:** unchanged raw bytes,
  `text/plain; charset=utf-8`, no field-level claim (AC22). **Cleaned:** unchanged **410 Gone**.
  `nosniff`, `no-store, private`, never-logged, never-cached are unchanged on both paths (ADR-017
  Decision 6, narrowed only in its `Content-Type` half per ADR-024).
- **Dependencies:** T6
- **Files:** `app/Http/Controllers/ProxyEventPayloadController.php`
- **Acceptance Criteria:**
  - A JSON-parseable retained payload returns the `{format, document, obfuscated}` envelope with
    `Content-Type: application/json`; every sensitive value in `document` is `null`; `obfuscated`
    carries the correct RFC 6901 pointer for each.
  - A non-JSON retained payload returns unchanged raw bytes, `text/plain; charset=utf-8`, and no
    envelope keys at all.
  - A cleaned event still returns **410 Gone**, on both content shapes, with no envelope.
  - `nosniff` and `Cache-Control: no-store, private` are present on every response this endpoint
    returns, unchanged from before this task.
  - The response never contains a stored secret value under any circumstance (there is nothing in this
    endpoint's data path that could carry one — asserted as a smoke check here, swept exhaustively at
    T47).
- **Testing:** extends `tests/Feature/Proxies/ProxyEventPayloadControllerTest.php` (existing, from #6)
  — the JSON-envelope case, the non-JSON-unchanged case, the cleaned-410-both-shapes case, the header
  assertions.
- **Completion notes:** Done. `ProxyEventPayloadController` now `json_decode($body, true)`s the
  stored body and branches on `json_last_error()`: a decode success re-encodes through
  `PayloadObfuscator::obfuscate()` (a `SensitiveFieldMatcher` built from the proxy) into the
  `{format, document, obfuscated}` envelope via `response()->json()`, with `obfuscated`'s
  `MatchSource` values mapped to their `->value` strings; a decode failure falls through to the
  existing unchanged raw-bytes/`text/plain` response. The `payload_cleaned_at` guard is unchanged and
  runs before either branch, so a cleaned event returns the same empty 410 regardless of what the
  stored body would have parsed as. `nosniff`/`no-store, private` are set on both paths.

  **File path correction, not a deviation:** this task's own Testing line names
  `tests/Feature/Proxies/ProxyEventPayloadControllerTest.php`; the file `ProxyEventPayloadController`
  actually has (added at #6/T28) lives at `tests/Feature/ProxyEvents/ProxyEventPayloadControllerTest.php`
  — extended that existing file rather than creating a second, since the task names the class under
  test unambiguously and a duplicate test file for the same controller would itself be a review
  finding.

  **Return type widened** from `Illuminate\Http\Response` to `Response|JsonResponse` —
  `response()->json()` returns `JsonResponse`, which is not a `Response` in this Laravel version;
  PHPStan/the framework's own dispatcher enforce the declared return type at the controller boundary
  regardless of PHPStan level, so this was required for the new branch to run at all, not a style
  choice.

  Extended the existing test file: renamed the raw-bytes test to a genuinely non-JSON body (a JSON
  body no longer takes that path), added the JSON-envelope test (asserts the exact envelope shape,
  headers, and that a default-list match and a proxy addition both obfuscate correctly with the right
  `MatchSource`), added a cleaned-with-JSON-shaped-stored-body case for the "both shapes" requirement,
  and added a smoke-check test with a live `ProxySecret` row on the proxy, asserting the response
  never contains its value (the exhaustive sweep is T47's).

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  ProxyEventPayloadControllerTest` green (12 tests, 33 assertions); full-suite run deferred to the end
  of this batch.

## T9 — `PayloadViewer.vue`: the obfuscated-value token, both C3 descriptions (Screen 7; AC16, AC20, AC21, C3, C6, C8, C9, N1)
- **Description:** Extends the existing masked/revealed toggle (design-06, unchanged) so the
  **revealed, JSON** state renders the T8 envelope: pretty-printed structure (a parsing consequence,
  C9), field names and non-sensitive values exactly as received, and every pointer-index entry
  rendered as an inline, muted, **inert** `[Hidden]` token (**C8** — fixed string, no click handler, no
  `tabindex`, no actionable role — AC20) carrying **both** a native `title` and an `sr-only` text node
  (**N1**), whose text is one of the two fixed descriptions depending on `default` vs `addition`
  (**C3**). Fixed-width, identical rendering regardless of the real value's type/length/emptiness
  (AC16). The **revealed, non-JSON** state is unchanged from design-06 — the existing raw
  `whitespace-pre-wrap` block, no field-level treatment (AC22). Renders through text interpolation
  only, never `v-html` (ADR-017, unchanged).
- **Dependencies:** T8
- **Files:** `resources/js/components/PayloadViewer.vue`
- **Acceptance Criteria:**
  - A revealed JSON payload with a sensitive scalar, a sensitive object, and a sensitive array each
    render as exactly one `[Hidden]` token, none of their sub-structure visible.
  - The token's `title` and `sr-only` text differ correctly between a default-list match and a
    proxy-addition match, per C3's two fixed strings.
  - The token has no click handler, no `tabindex`, and does not announce as a button or link to
    assistive technology.
  - A revealed non-JSON payload renders exactly as it did before this feature — no `[Hidden]` token
    anywhere.
  - No `v-html` is introduced anywhere in this component.
- **Testing:** no frontend test harness exists (backlog T31 on `docs/tasks/walking-skeleton-tasks.md`)
  — **manual verification**, `design-10` Flow E, against `pnpm run build` with `public/hot` confirmed
  absent (review-07 Finding 8), in both themes: a sensitive scalar, object and array each render
  `[Hidden]`; the two C3 descriptions read correctly via a screen reader or the `title` attribute; a
  non-JSON payload is unaffected.
- **Completion notes:** Done. `PayloadViewer.vue`'s `reveal()` now branches on the response's
  `Content-Type` header (never on what it requested — the server decides, per ADR-024) rather than
  always treating the body as text. A JSON envelope is parsed and walked by a new `walk()` function
  that reproduces `PayloadObfuscator`'s own pointer-escaping (`~` before `/`) so a pointer computed
  client-side from the same structure always matches an entry in `obfuscated`, emitting an ordered
  array of `{kind: 'text', text}` / `{kind: 'hidden', source}` parts; the template renders text parts
  via `v-text` (never `v-html`, unchanged from ADR-017) and hidden parts as an inert `<span>` — no
  click handler, no `tabindex`, no role — carrying a native `title` plus a nested `aria-hidden="true"`
  visible `[Hidden]` label and a sibling `sr-only` text node holding the same C3 description (N1); the
  two fixed C3 strings live in one `HIDDEN_DESCRIPTIONS` map, verbatim from `design-10`. A non-JSON
  response takes the unchanged `format.value = 'text'` path, byte-identical to before this task.

  **Manual verification performed** (own local Sail dev environment, own seeded data only — a
  `t9-verify@example.com` user with an isolated team, deleted again immediately after): applied this
  branch's pending `2026_08_27_000001_add_sensitive_data_handling_schema` migration to the local dev
  database (was un-migrated — `sensitive_fields` didn't exist yet on that schema); confirmed
  `public/hot` absent (removed a stale leftover from an old, still-running `pnpm run dev` process —
  the file was regenerated by nothing since, so its absence is not a temporary state) and ran `pnpm
  run build` before checking. Seeded a proxy with one addition (`ssn_last4`) and an event whose body
  had a sensitive scalar (`customer.password`, a default match), a sensitive object
  (`payment.token`, containing `card`/`cvv`, a default match), a sensitive array-element field
  (`items[0].password`), and an addition match (`ssn_last4`). Logged in via Playwright (headless,
  real session, `password` factory default) and clicked Reveal on the event's Payload card:

  - `customer.password`, `payment.token` and `items[0].password` each rendered exactly one
    `[Hidden]` token; `payment.token`'s own sub-keys (`card`, `cvv`) never appeared anywhere in the
    rendered output (C6).
  - The two `title` values differed exactly as specified: the three default matches all carried
    "Hidden — this field's name matches a product default (password, token, or credit card). It
    can't be removed from Sensitive fields."; `ssn_last4` carried "Hidden — this field's name
    matches an addition to this proxy's Sensitive fields list. Remove the name from Sensitive fields
    to stop hiding it." — confirmed via `getAttribute('title')` on all four tokens, not just visual
    inspection.
  - None of the four tokens had a `tabindex` or `role` attribute (checked via `getAttribute`, both
    `null`).
  - Non-sensitive fields (`customer.email`, `items[0].sku`, `amount`) rendered their real values
    unchanged; structure (nesting, array brackets) rendered pretty-printed as C9 accepts.
  - Screenshots taken in both light and dark mode (`localStorage.setItem('appearance', 'dark')`
    before reload, per this project's established headless dark-mode recipe) — the `[Hidden]` token's
    muted background is legible and distinct from surrounding text in both themes.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green; `composer lint`/`composer types:check`/`./vendor/bin/sail test --parallel` unaffected by this
  frontend-only task (no PHP file touched) and re-run at the end of this batch regardless.

---
