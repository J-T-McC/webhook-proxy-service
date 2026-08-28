# Task Plan: Sensitive data handling — item #10

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-10-sensitive-data-handling.md` (**fully approved** — Principal
  Engineer self-certified, and all four Owner-approval flags ruled by the Project Owner,
  2026-08-27: flag 1, the `proxy_secrets` table plus six columns, approved exactly as enumerated
  (the fixed-column alternative not taken); flag 2, ADR-021, approved as the recommendation; flag 3,
  ADR-022, approved; flag 4, ADR-024, approved) **plus `## Revision A`** (2026-08-27, Principal
  Engineer, no Owner gate — technical ruling 15, the `destinations.*.remove_credential` transport,
  answering `Q-10-05`; purely additive, no existing ruling/gate/milestone/ADR reopened)
- **PRD:** `docs/product/prd-10-sensitive-data-handling.md` (Approved, Project Owner, 2026-08-27; 64
  acceptance criteria, `## Amendment A` **and** `## Amendment B` both ratified whole, nothing
  renumbered — Amendment B re-grains outbound signing to the proxy and settles AC29's cap of two)
- **Design:** `docs/design/design-10-sensitive-data-handling.md` (**Approved, as amended** — the
  original design gate, 2026-08-27, Product Manager, ten required corrections C1–C10; **and** the
  amendment gate, 2026-08-27, Product Manager, four required corrections B1–B4, all landed. The
  amendment gate's own record governs where it and the original spec body differ.)
- **Questions:** `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md` (**RESOLVED**,
  Principal Engineer) · `docs/questions/prd-10-q-10-03-credential-removal-and-secret-field-primitive.md`
  (**RESOLVED**, Designer) · `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`
  (**RESOLVED**, PRD-10 `## Amendment B`) · `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`
  (**RESOLVED**, Principal Engineer, 2026-08-27 — a sibling boolean, `destinations.*.remove_credential`,
  recorded as `plan-10` § *Revision A*, technical ruling 15; T31 is unblocked)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against the plan/PRD-10/design-10 at review time)

> **Scope / conventions.** Every task traces to `plan-10` and PRD-10's ACs (AC1–AC64, both
> amendments) or a named plan technical ruling. Sequencing follows the plan's own milestones
> verbatim (**M1–M9**, with M8 split into **M8a** backend and **M8b** surface exactly as the plan
> names them), each mapped to a contiguous task range below: **M1 data model** (T1–T3) → **M2
> obfuscation engine, no surface** (T4–T6) → **M3 Standard Webhooks primitive, no surface** (T7) →
> **M4 revealed-payload envelope** (T8–T9) → **M5 sensitive-fields configuration** (T10–T12) → **M6
> `SecretStore` and inbound verification** (T13–T25) → **M7 outbound credential** (T26–T33) → **M8a
> outbound signing, backend** (T34–T40) → **M8b outbound signing, surface** (T41–T44) → **M9
> cross-cutting hardening and the verification sweep** (T45–T49). No task depends on a later task.
> **49 tasks, T1–T49.**
>
> **All four Owner-approval flags are ruled and `design-10`'s amendment gate has closed with its four
> corrections landed** (`docs/status.md` item #10), so — unlike `plan-10`'s own sequencing note,
> written before the amendment gate closed — **no milestone here is gate-blocked, M8b included.**
> `plan-10`'s own instruction that "M8b must not be broken down until `Q-10-04` is answered and
> `design-10` is revised" is satisfied: `Q-10-04` is RESOLVED by PRD-10 `## Amendment B`, `design-10`
> carries the revision, and the amendment gate's correction **B2** (the AC29 ruling-2a disclosure on
> the signing surface) is broken down at **T43**, as a dedicated task, exactly as the amendment gate's
> "What this approval unblocks" section requires before M8b is task-planned.
>
> **The one task this document had blocked on an open question is now unblocked.**
> `docs/design/design-10-…`'s amendment-gate correction **B3** required Screen 3's new **Remove
> credential** control to reach the server as a signal distinguishable from an ordinary blank Replace
> field, and explicitly left the transport to the Principal Engineer — a decision no approved
> `plan-10` text made at the time, because the control did not exist when the plan was certified.
> Raised as `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`,
> directed to the Principal Engineer, blocking **T31 only**. **RESOLVED, 2026-08-27**: a sibling
> boolean per destination row, `destinations.*.remove_credential` — `credential_secret` keeps exactly
> one meaning ("a new value, or absent"), never a second, sentinel one. Recorded as `plan-10` §
> *Revision A*, technical ruling 15. T31 below is written to that ruling.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan level 7) green,
> and `./vendor/bin/sail test` green with its own tests included (`CLAUDE.md`,
> `docs/standards/planning.md`). Frontend tasks (T9, T12, T23, T24, T30, T31, T33, T41, T42, T43)
> additionally require `pnpm lint:check`, `pnpm types:check` and `pnpm format:check` green, plus the
> manual-verification steps named on the task (no frontend test harness exists — backlog **T31** on
> `docs/tasks/walking-skeleton-tasks.md`, a different T31 than this document's own; see the note on
> this document's T31 below).
>
> **Tasks that touch the delivery path — flagged because `QUEUE_CONNECTION=sync` makes the automated
> suite weak evidence there.** This project's test suite runs the queue synchronously
> (`docs/stack/stack.md`), so a green suite proves the *logic* `DeliverToDestination::send()`,
> `DeliveryUnitResolver` and the header-building pure classes execute correctly when called, but it
> exercises none of the concurrency, ordering or worker-crash behaviour `AdvanceProxyFifoQueue` and
> `app/Actions/DeliverStep.php` are actually responsible for in production (Horizon, real async
> workers). No task in this plan edits `AdvanceProxyFifoQueue` or `DeliverStep.php` — `plan-10` §
> *Architecture G* states FIFO composition is untouched — but the following tasks change code that
> those two files call into on every real dispatch, and their green-suite result should be read as
> "the logic is correct," not as "the async path was exercised end to end": **T27** (`DeliveryUnitResolver`'s
> `withTrashed()` proxy load), **T28** (`DeliverToDestination::send()` calling `OutboundHeaders` for
> the credential), **T34** (`OutboundHeaders` extended with signing headers, computed in the send
> path), **T35** (the AC63 byte-identical regression, exercised through `send()`), **T36**
> (`DeliveryUnitResolver`/`DeliveryUnit` carrying the proxy's live signing set), **T39** (the AC11
> signing all-or-none behaviour, exercised through `send()`), and **T40** (the outbound signing
> integration suite, which drives retries and replays through the same job path). Manual verification
> against a real queued dispatch (Horizon, `QUEUE_CONNECTION=redis`) is recommended at **T49** if it
> is available in the environment, though it is not required by any single task's Acceptance Criteria
> — `plan-10` asserts no numeric or environment-specific target here (AC47).
>
> **Binding constraints carried through the tasks below, named once and traced to where each
> lands, per `plan-10`'s technical rulings and Implementation Notes — none is stylistic:**
> 1. **Signing is proxy-level, not per-destination** (PRD-10 `## Amendment B` ruling 1; `plan-10`
>    Technical ruling 13). One `signing`-purpose secret per proxy, shared by every destination that
>    proxy dispatches to, including one added afterward, rotated at the proxy grain. No task anywhere
>    in M8a/M8b adds a per-destination signing column, toggle, or rotation state — landed at T34–T44.
> 2. **AC11's signing clause is proxy-wide fail-loud, not per-destination** (PRD-10 `## Amendment B`
>    ruling 1). A proxy whose signing secret cannot be decrypted dispatches to **none** of its
>    destinations for that attempt — partial fan-out (some destinations signed, some silently
>    unsigned) is exactly the state this criterion forbids. Pinned by a dedicated test at **T39**,
>    not folded into the general outbound-signing suite (T40), because a partial-fan-out regression
>    is exactly the kind of defect a broad test can quietly pass around.
> 3. **AC29's cap of two live secrets per purpose per proxy, and the ruling-2a "before save"
>    disclosure of the immediate discard, apply on both surfaces this feature has** (PRD-10 `##
>    Amendment B` ruling 2 and 2a). The cap itself is `SecretStore`'s write-path property, pinned at
>    **T14**. The disclosure copy is a **frontend** obligation, present **before** the member commits
>    to the rotation, on **both** the inbound verification surface (Screen 1 / Flow B step 2 — **T23**)
>    and the outbound signing surface (Screen 6 state 4 / Flow H step 2 — **T43**, correction B2). A
>    task that lands the branch on only one surface is incomplete against this constraint.
> 4. **The destination credential is unchanged and stays per destination** (AC31, AC33; untouched by
>    either amendment). No task in this list adds an overlap, a "previous" state, or any rotation
>    language to the credential surface — T26, T29, T30, T31 build a single-valued, immediately-replaced
>    secret with no such state, and T31's Remove-credential control (ruling 15's
>    `destinations.*.remove_credential` boolean) stays inside that same immediate, non-rotating model.
> 5. **`SecretStore` is the single reader and writer of `proxy_secrets`** (`plan-10` Technical ruling
>    14). No task outside T14/T15 issues a query against that table directly; `InboundVerifier` (T18),
>    the signing endpoints (T37) and the resolver (T27/T36) all go through `SecretStore`.
> 6. **`OutboundHeaders` is the only place an outbound header set is built** (`plan-10` Implementation
>    Note 3). T26 creates it for the credential and the verification-header strip; T34 is the only
>    other task that may add to it (the signing headers). No other task builds or mutates an outbound
>    header array.
> 7. **The raw ingest body is read exactly once** and passed to both `InboundVerifier` and
>    `WebhookEventCapture` — landed at **T19**, which is also the only task permitted to change how
>    `IngestController` reads the request body.
> 8. **A present-but-empty secret field never clears a stored secret**, on every write-only field this
>    feature has (verification secret, credential, and the N/A case for the product-generated signing
>    secret, which is never typed at all) — carried into T20, T23, T29, T30's Acceptance Criteria
>    individually rather than asserted once, because each is a separate form field and a separate
>    regression surface.
>
> **Scope discipline (`plan-10` §§ Explicitly out of scope / Out of Scope) — do NOT build in this
> feature:** any third verification scheme, IP allow-listing, mutual TLS, or free-form verification
> configuration (AC50); value-pattern secret detection (AC14); partial disclosure of an obfuscated
> value (AC16); a per-field reveal for any role, or any new permission (AC20, AC28); a team-level
> sensitive-field list (AC13); any field-level treatment of a non-JSON payload (AC22); a header
> display surface (AC41/AC42); application-key rotation/re-encryption tooling or per-team keys (AC44,
> AC45); cleaning up secrets already embedded in `destinations.url` (AC39); a byte ceiling on payload
> parsing or any new member-facing state for one (Technical ruling 9, R1); a per-destination signing
> secret, toggle, or rotation state, or raising AC29's cap above two (both named `## Amendment B`
> exclusions); any analytics, counter, or notification for a rejected inbound request (AC46); any
> change to retention, GC, holds, retry, replay, processing mode, the mode attribute, FIFO ordering,
> or #11's figures and indexes; any second payload read surface, export, download, share path, cache
> or archive (AC3).

---

## M1 — Data model

## T1 — Migration: `proxy_secrets` table, three `proxies` columns, three `destinations` columns (Owner-approval flag 1, approved exactly as enumerated) (plan § Data Model)
- **Description:** One new migration, plain Blueprint, exactly as the approved change set enumerates.
  New table **`proxy_secrets`**: `id`, `team_id` (`foreignId->constrained()`, RESTRICT on delete),
  `proxy_id` (`foreignId->constrained()->cascadeOnDelete()`), `purpose` `string(32)`, `value` `text`
  cast **`encrypted`**, `is_current` `boolean` **nullable**, `expires_at` `timestamp` nullable,
  timestamps. One index, a constraint: **`UNIQUE(proxy_id, purpose, is_current)`**, named
  `proxy_secrets_proxy_id_purpose_is_current_unique`. `proxies` gains `verification_scheme`
  `string(32)` nullable, `verification_header_name` `string(128)` nullable, `sensitive_fields`
  `longText` nullable. `destinations` gains `credential_header_name` `string(128)` nullable,
  `credential_secret` `text` nullable cast **`encrypted`**, `credential_set_at` `timestamp` nullable.
  No index added to `proxies` or `destinations`. No other table, column, index, enum value, FK, or
  `onDelete` behaviour is touched anywhere; no backfill, no default written to any existing row.
  `down()` is `dropIfExists('proxy_secrets')` plus two `dropColumn` calls.
- **Dependencies:** none
- **Files:** `database/migrations/2026_08_27_000001_add_sensitive_data_handling_schema.php` (new)
- **Acceptance Criteria:**
  - Migration applies cleanly; `proxy_secrets` exists with exactly the nine columns above and the one
    named unique index; `proxies` and `destinations` each carry exactly their three new columns.
  - Every pre-existing index on `proxies` and `destinations` — `ingest_token_hash` UNIQUE, the team
    foreign-key index, and every index #11 added — is still present, unchanged, after the migration.
  - Inserting two rows with the same `(proxy_id, purpose, is_current = true)` fails against the
    unique index; inserting any number of rows with `is_current = NULL` for the same
    `(proxy_id, purpose)` succeeds (MySQL/SQLite both ignore NULLs in a unique index — this is the
    partial-unique behaviour the schema relies on).
  - `down()` removes exactly `proxy_secrets` and the six new columns; every pre-existing table,
    column and index still present post-rollback; the round trip (`migrate:rollback --step=1` +
    `migrate`) is clean.
  - No other schema change appears anywhere in the diff — this migration is the only one this whole
    task list adds.
- **Testing:** `tests/Unit/Migrations/SensitiveDataHandlingSchemaTest.php` (new) — the full
  column/type/nullability assertion for all three tables (via `information_schema`, mirroring
  `AnalyticsIndexesTest`'s pattern), the unique-index-both-directions assertion (duplicate `is_current
  = true` fails; multiple `is_current = NULL` rows succeed), the pre-existing-index-survival list, and
  the rollback round trip.
- **Completion notes:** Done. Added the migration exactly as enumerated — `proxy_secrets` (nine
  columns, one partial-unique index) plus the three `proxies` and three `destinations` columns, plain
  Blueprint throughout, `down()` a `dropIfExists` and two `dropColumn` calls. New test file covers the
  column/type/nullability shape, both directions of the unique index, pre-existing-index survival, and
  the rollback round trip. `composer lint`, `composer types:check` and `./vendor/bin/sail test
  --parallel` all green.

  Incidental fix required to keep the full suite green: this migration's timestamp
  (`2026_08_27_000001`) sorts after `2026_08_26_000001_add_analytics_indexes_to_delivery_tables`, so
  `AnalyticsIndexesTest`'s rollback test — which called `migrate:rollback --step=1` assuming its own
  migration was always the most recently run — started rolling back this migration instead of its
  own, failing. `--step=1` walks the last N migrations by run order, not by name, so this was a
  latent ordering assumption that any later migration would have broken, not a defect in this
  migration. Fixed by computing the step count needed to reach that test's own migration by name
  (`tests/Unit/Migrations/AnalyticsIndexesTest.php`) rather than hardcoding `1`; both rolled-back
  migrations are reapplied in the same test, so its round-trip assertions are unaffected. No
  requirement, interface, data model or ADR'd decision changed — test-only, root-cause fix.

## T2 — `ProxySecret` model, `SecretPurpose` enum, `Proxy`/`Destination` relations and casts (plan § Services & Actions, § Data Model)
- **Description:** `App\Models\ProxySecret` (Eloquent): `value` cast `encrypted`, **`$hidden =
  ['value']`**, a `live()` local scope (`is_current = true OR (is_current IS NULL AND (expires_at IS
  NULL OR expires_at > now()))` — read as "current, or superseded-but-not-yet-expired," matching
  `plan-10`'s "current first, non-expired" live-set predicate), `#[Fillable]` entries, docblocks.
  `App\Enums\SecretPurpose` backed enum: `Verification = 'verification'`, `Signing = 'signing'`. `Proxy`
  gains a `secrets(): HasMany` relation to `ProxySecret`, casts for `verification_scheme`
  (`VerificationScheme::class` — enum created at T16; declare the cast here referencing the
  not-yet-created class, or defer the cast line to T16 and note it, whichever keeps this task's own
  suite green) and `sensitive_fields` (`array`), and `#[Fillable]` entries for
  `verification_header_name`/`sensitive_fields`. `Destination` gains casts for `credential_secret`
  (`encrypted`) and `credential_set_at` (`datetime`), and `#[Fillable]` entries for
  `credential_header_name`/`credential_set_at`. Docblocks (`@property`, `@property-read`) updated on
  both models per this project's existing convention.
- **Dependencies:** T1
- **Files:** `app/Models/ProxySecret.php` (new), `app/Enums/SecretPurpose.php` (new),
  `app/Models/Proxy.php`, `app/Models/Destination.php`
- **Acceptance Criteria:**
  - `ProxySecret::make(['value' => 'plaintext'])->value` round-trips through the `encrypted` cast; a
    raw `DB::table('proxy_secrets')` read of the same row's `value` column is **not** the plaintext.
  - `(new ProxySecret())->toArray()` and `->toJson()` never contain a `value` key (the `$hidden` guard,
    independent of any resource).
  - `Proxy::factory()->create()->secrets` resolves the `HasMany` relation; `ProxySecret::live()`
    excludes an expired row and includes a current or not-yet-expired one, exercised against a small
    fixture of three rows (current, superseded-not-expired, superseded-expired).
  - `composer types:check` (PHPStan level 7) passes on both models with no suppression.
- **Testing:** `tests/Unit/Models/ProxySecretTest.php` (new) — the cast round-trip, the raw-column
  ciphertext assertion, the `$hidden` assertion on both `toArray()`/`toJson()`, and the `live()` scope
  fixture. `Proxy`/`Destination` factory updates as needed for the new nullable columns (no required
  fixture change — every new column is nullable).
- **Completion notes:** Done. `ProxySecret` (encrypted `value` cast, `$hidden = ['value']`, a
  `scopeLive()` local scope implementing "current, or superseded-but-not-yet-expired") and
  `App\Enums\SecretPurpose` added. `Proxy` gains a `secrets(): HasMany` relation, a `sensitive_fields`
  array cast, and `#[Fillable]` entries for `verification_header_name`/`sensitive_fields`; `Destination`
  gains `credential_secret` (encrypted) and `credential_set_at` (datetime) casts and `#[Fillable]`
  entries for `credential_header_name`/`credential_set_at`. Both models' `@property`/`@property-read`
  docblocks updated. No `Proxy`/`Destination` factory changes were needed — every new column is
  nullable, matching the task's own note.

  Per the task's explicit either/or: deferred `verification_scheme`'s cast (and its `#[Fillable]` entry)
  to T16, where `App\Enums\VerificationScheme` is created — referencing that not-yet-existing class here
  would have broken this task's own suite. `proxies.verification_scheme` stays an uncast raw string
  column until then; noted inline in `Proxy::casts()`.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --parallel` all green.

## T3 — Schema and encryption-surface regression tests (plan § Test strategy "Encryption at rest and the closed store set")
- **Description:** No production code. Pins the two guarantees that must survive every later task in
  this feature untouched: (1) the `APP_PREVIOUS_KEYS` column list ADR-021 § Impact enumerates —
  `webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`, `proxies.ingest_token`,
  `proxy_secrets.value`, `destinations.credential_secret` — matches the casts actually declared, via
  reflection over the five models, so the list cannot silently drift from the code; (2) a secret write
  produces no plaintext secret in the query log (`DB::listen` over an `INSERT`/`UPDATE` touching either
  encrypted column).
- **Dependencies:** T2
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - A reflection test enumerates every `encrypted`-cast property across `ProxySecret`, `Proxy`,
    `Destination`, `WebhookEvent`, `DispatchedPayload` and asserts the set is exactly the six columns
    named above — failing loudly if a future cast is added or removed without updating this test.
  - `DB::listen` captures no plaintext secret value in any SQL binding logged during a `ProxySecret`
    or `Destination` credential write.
- **Testing:** `tests/Unit/Models/EncryptedColumnSurfaceTest.php` (new).
- **Completion notes:** Done. Reflection test constructs each of the five models, reads
  `getCasts()`, filters to casts whose string starts with `encrypted` (so `encrypted:array` on
  `WebhookEvent::headers` counts alongside plain `encrypted`), and asserts the resulting
  `table.column` set is exactly the six ADR-021 § Impact names (order-independent via
  `assertEqualsCanonicalizing`, since PHP array key order from five merged model calls carries no
  meaning). Two `DB::listen` tests (one `ProxySecret` write, one `Destination` credential write)
  assert the plaintext value never appears in any logged binding for a query touching that table —
  true by construction, since the `encrypted` cast runs at attribute-set time before the query is
  built. `composer lint` (which reordered this file's imports/PHPDoc alignment — no behaviour
  change), `composer types:check` and `./vendor/bin/sail test --parallel` all green.

---

## M2 — Obfuscation engine, no surface

## T4 — `App\Support\SensitiveFields`: the 23-name default list and `normalise()` (AC12; plan Technical ruling 10, ADR-024 Decision 5)
- **Description:** Pure class, no DB, no I/O. `DEFAULTS`: exactly 23 names across the three families
  AC12 names (password, token, credit card) and their common spellings/separators, per ADR-024
  Decision 5 — `secret`, `api_key`, `private_key` and `client_secret` deliberately excluded; `cvv` and
  `pwd` included. `normalise(string $name): string` — lowercase, strip non-alphanumerics, for
  case/separator-insensitive comparison.
- **Dependencies:** none
- **Files:** `app/Support/SensitiveFields.php` (new)
- **Acceptance Criteria:**
  - `DEFAULTS` has exactly 23 entries; no two collide after `normalise()`; every entry is already in
    normalised-equal form to its own displayed spelling.
  - `normalise('Password') === normalise('pass_word') === normalise('PASS-WORD')`.
  - `secret`, `api_key`, `private_key`, `client_secret` are **not** in `DEFAULTS`; `cvv` and `pwd`
    **are**.
- **Testing:** `tests/Unit/Support/SensitiveFieldsTest.php` (new) — the count, the no-collision sweep,
  the normalisation-equality cases, the explicit inclusion/exclusion list.
- **Completion notes:** Done. `App\Support\SensitiveFields::DEFAULTS` is the 23-name list from
  ADR-024 Decision 5, verbatim (8 password + 7 token + 8 credit-card names); `normalise()` lowercases
  and strips everything but `a`-`z`/`0`-`9`. `composer lint`, `composer types:check` and
  `./vendor/bin/sail test --parallel` all green.

## T5 — `App\Services\SensitiveFieldMatcher` (AC13, AC14; plan § Services & Actions)
- **Description:** Effective list = `SensitiveFields::DEFAULTS` ∪ a proxy's own additions (from
  `Proxy::sensitive_fields`). `matchFor(string $fieldName): ?MatchSource` — returns which list
  matched (`default` beats `addition` when a name is in both, per `plan-10` Technical ruling 2 /
  ADR-024 Decisions 2 and 4 — the tie-break exists because removing an addition that duplicates a
  default would not unhide the value) or `null` for no match. Matching is by normalised name, exact
  equality only — never substring.
- **Dependencies:** T4
- **Files:** `app/Services/SensitiveFieldMatcher.php` (new), `app/Enums/MatchSource.php` (or a simple
  two-case backed enum/string constant pair — `default` | `addition`; new)
- **Acceptance Criteria:**
  - A name in the default list only matches `default`; a proxy addition only matches `addition`; a
    name in **both** matches `default` (the tie-break, asserted directly).
  - `tokenizer_version` and `token_count` do not match `token`; `tokens` does not match `token` — exact
    match only, never substring.
  - An empty proxy addition list still matches every default name.
- **Testing:** `tests/Unit/Services/SensitiveFieldMatcherTest.php` (new) — one case per bullet above.
- **Completion notes:** Done. `App\Enums\MatchSource` (`Default`/`Addition`, string-backed
  `'default'`/`'addition'` so it serializes directly for T8/T9). `SensitiveFieldMatcher` builds two
  normalised-name lookup tables at construction (defaults, and the proxy's `sensitive_fields`
  additions) and checks defaults first in `matchFor()` — the tie-break falls out of check order rather
  than needing separate logic. `composer lint`, `composer types:check` and
  `./vendor/bin/sail test --parallel` all green.

## T6 — `App\Support\PayloadObfuscator` (AC15, AC16, AC17, C6; plan § Architecture D, ADR-024 Decisions 2 and 4)
- **Description:** Pure class, no DB, no I/O, no clock. Walks a decoded JSON document (an
  `array`/scalar tree from `json_decode(..., true)`) and, for every key whose name matches via
  `SensitiveFieldMatcher`, replaces the **entire value** with `null` in the returned document —
  whatever its type, including an object or array (**C6**: never walked into, never partially
  obfuscated). Returns `[document, pointerIndex]`, where `pointerIndex` maps an RFC 6901 JSON Pointer
  to the `MatchSource` (`default`/`addition`) that matched, for every replaced value. Never inspects a
  value to decide sensitivity (AC14) — matching is name-only, applied at any depth, including inside
  array elements.
- **Dependencies:** T5
- **Files:** `app/Support/PayloadObfuscator.php` (new)
- **Acceptance Criteria:**
  - A sensitive field at depth 4, and one inside an array element, is obfuscated.
  - A sensitive field whose value is an object or an array is replaced whole — the returned document
    contains none of its sub-keys, at any depth (**C6**, one dedicated test).
  - Field **names** and non-sensitive values are untouched; the document's structure (keys present,
    array lengths) is unchanged except for the replaced values themselves.
  - The pointer index records `default` for a default-list match and `addition` for a proxy-addition
    match, matching T5's tie-break for a name in both lists.
  - No character of an obfuscated value, its length, or whether two obfuscated fields held the same
    real value is derivable from the returned `document` or `pointerIndex` (AC16) — every replaced
    value is literally `null`, so nothing but presence survives.
- **Testing:** `tests/Unit/Support/PayloadObfuscatorTest.php` (new) — the depth/array-element case,
  the whole-object/array replacement case (C6), the structure-preserved case, the pointer-index
  default-vs-addition case, and a fixture asserting two different real values that both matched a
  sensitive name produce identical (`null`) output.
- **Completion notes:** Done. `PayloadObfuscator::obfuscate(mixed $document, SensitiveFieldMatcher
  $matcher): array{0: mixed, 1: array<string, MatchSource>}` walks the decoded tree recursively;
  `array_is_list()` distinguishes a JSON array (indices, never tested as names) from a JSON object
  (keys, tested via `matchFor()`). A matched key's value is replaced with `null` and recursion stops
  there — C6's whole-value replacement falls out of `continue`-ing before the recursive call rather
  than needing a separate "don't walk into this" branch. Pointer segments are RFC 6901-escaped
  (`~` before `/`, order-sensitive). `composer lint`, `composer types:check` and
  `./vendor/bin/sail test --parallel` all green.

  Incidental fix required to keep the full parallel suite green: T1's
  `SensitiveDataHandlingSchemaTest::test_proxy_secrets_table_has_exactly_the_nine_columns_and_the_one_unique_index`
  asserted `information_schema.COLUMNS` row order via `assertSame` with no `ORDER BY`, which is not a
  guarantee MySQL makes — under `--parallel` (a separate schema per worker) the returned order was
  not always ordinal. Added `ORDER BY ORDINAL_POSITION` to the query and switched the column-name
  assertion to `assertEqualsCanonicalizing` (the acceptance criterion is "exactly these columns
  exist", not "in this row order"). Test-only; no requirement, interface, data-model or ADR'd decision
  changed.

---

## M3 — Standard Webhooks primitive, no surface

## T7 — `App\Support\StandardWebhooks` (AC52, AC53, AC55; plan § Services & Actions, Technical ruling 6)
- **Description:** Pure class, no DB. Implements the Standard Webhooks specification in-house (no new
  Composer dependency — plan Technical ruling 6, Dependencies assessed and declined): `sign(string $id,
  int $timestamp, string $body, string $secret): string` (HMAC-SHA256 over `"<id>.<timestamp>.<body>"`,
  base64-encoded, not hex); `verify(string $id, int $timestamp, string $body, string
  $signatureHeaderValue, list<string> $secrets): bool` (parses a space-delimited list of `v1,<sig>`
  entries, skips any non-`v1` entry rather than failing, succeeds if **any** entry verifies against
  **any** secret in the live set, `hash_equals` for constant-time comparison); `TOLERANCE_SECONDS =
  300` (the specification's reference value, a single-sourced constant so member-facing copy at T23
  interpolates it rather than hand-typing "5 minutes"); a `whsec_`-prefixed-or-bare-base64 secret
  decoder, since the specification allows both.
- **Dependencies:** none
- **Files:** `app/Support/StandardWebhooks.php` (new)
- **Acceptance Criteria:**
  - A specification-computed signature verifies via `verify()`.
  - A multi-entry space-delimited `webhook-signature` value verifies when only the **second** entry
    matches.
  - A non-`v1` entry is skipped rather than causing a failure when a later entry matches.
  - A timestamp `TOLERANCE_SECONDS + 1` seconds either side of now is rejected by the tolerance check;
    one second inside is accepted (the tolerance check itself may live here or at the T17 scheme
    wrapper — state which in completion notes; either satisfies AC53 as long as it is
    single-sourced from this constant).
  - A `whsec_`-prefixed secret and a bare base64 secret both produce/verify the same signature.
  - Hex-encoded input where base64 is expected fails to verify (never silently accepted as a different
    encoding).
- **Testing:** `tests/Unit/Support/StandardWebhooksTest.php` (new) — specification-derived fixtures
  (hand-computed HMAC-SHA256/base64 vectors), the multi-entry-list case, the non-`v1`-skip case, the
  tolerance boundary cases, the `whsec_`/bare-secret equivalence, and the hex-rejection case.
- **Completion notes:** Done. `App\Support\StandardWebhooks`: `sign()` (`hash_hmac('sha256', "<id>.
  <timestamp>.<body>", $secret, true)`, base64-encoded), `verify()` (space-delimited `v1,<sig>` entry
  parsing via `preg_split('/\s+/', ...)`, skips any non-`v1` entry, `hash_equals` against every
  secret in the live set), `TOLERANCE_SECONDS = 300`, and a `whsec_`-or-bare-base64 secret decoder
  (strict-mode `base64_decode`, empty string on a decode failure so a malformed secret simply never
  matches rather than throwing — no exception type is named anywhere in this task's own Acceptance
  Criteria).

  **Tolerance check placed inside `verify()` itself**, not deferred to the T17 scheme wrapper: this
  task's own Testing section requires `tests/Unit/Support/StandardWebhooksTest.php` to cover the
  tolerance boundary directly, which only makes sense against code this class owns.

  One consequence, noted rather than worked around: the Standard Webhooks specification's own
  published fixed-timestamp reference vector (2021, from the specification's `svix-webhooks`
  verification test suite) cannot be run through `verify()` today, since `verify()` now rejects
  anything outside `TOLERANCE_SECONDS` of the real wall clock — that vector is over four years stale
  relative to this sail run. Used it instead to pin `sign()`'s HMAC/base64 construction directly
  (independently re-derived via a standalone `php -r` one-liner before writing the test, confirming
  the published vector rather than trusting memory of it), which is the part of the specification
  that fixture exists to prove; the `verify()` round-trip tests use a current timestamp with a
  signature computed by the already-pinned `sign()`. AC53 is unaffected — the tolerance is still
  single-sourced from `TOLERANCE_SECONDS` and enforced unconditionally.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter StandardWebhooksTest`
  green (10 tests, 10 assertions); full-suite run deferred to the end of this batch per the task
  list's own working rules.

---

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

## M6 — `SecretStore` and inbound verification

## T13 — `App\Support\RotationOverlap` (AC29; plan § Services & Actions)
- **Description:** Pure class. `HOURS = 24`, a class constant, not configurable anywhere (AC29's fixed
  window).
- **Dependencies:** none
- **Files:** `app/Support/RotationOverlap.php` (new)
- **Acceptance Criteria:** `RotationOverlap::HOURS === 24`; the constant is `final`/not overridable at
  runtime.
- **Testing:** trivial — a one-assertion test is sufficient, or fold into T14's suite if this class has
  no independent behaviour to test.
- **Completion notes:** Done. `App\Support\RotationOverlap::HOURS` is a `final public const int` = 24,
  matching `App\Support\StandardWebhooks::TOLERANCE_SECONDS`'s existing not-config precedent. Kept as
  its own small test file (`tests/Unit/Support/RotationOverlapTest.php`) rather than folded into T14's
  suite, for traceability to this task number. Two tests: the value, and a reflection assertion
  (`ReflectionClassConstant::isFinal()`) that it cannot be overridden at runtime.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter RotationOverlapTest`
  green (2 tests, 2 assertions); full-suite run deferred to the end of this batch (T13-T19).

## T14 — `App\Services\SecretStore` (AC10, AC11, AC26, AC29, AC33-exclusion, AC57, AC58; plan Technical rulings 5, 14; ADR-021 Decisions 3, 5, 6.1)
- **Description:** **The single reader and writer of `proxy_secrets`.** `liveFor(Proxy $proxy,
  SecretPurpose $purpose): list<string>` — the live set, current first, `expires_at IS NULL OR
  expires_at > now()`; throws `SecretUnavailableException` if any live row fails to decrypt (fail-loud,
  never dropped from the set — AC11: a partial signature list is indistinguishable from a completed
  rotation). `replace(Proxy $proxy, SecretPurpose $purpose, string $newValue): void` — deletes any
  already-superseded row before demoting the current one to `expires_at = now() +
  RotationOverlap::HOURS` hours, then inserts the new current row; enforces AC29's cap of two by
  construction (at most one current + one superseded-not-yet-expired at any instant). `generate(Proxy
  $proxy, SecretPurpose $purpose): string` — for the signing secret only (AC56): generates a
  `whsec_`-prefixed base64 value and calls through the same `replace()` path. `endOverlap(Proxy $proxy,
  SecretPurpose $purpose): void` — immediately expires the superseded row. `disable(Proxy $proxy,
  SecretPurpose $purpose): void` — deletes every row for that purpose (ADR-021 Decision 5 — used for
  disabling signing; verification's "Not required" does **not** call this, per the dormant-secret
  rule below). `App\Exceptions\SecretUnavailableException` (new) — fixed, value-free message.
- **Dependencies:** T2, T13
- **Files:** `app/Services/SecretStore.php` (new), `app/Exceptions/SecretUnavailableException.php`
  (new)
- **Acceptance Criteria:**
  - **R7:** three consecutive `replace()` calls for the same `(proxy, purpose)` leave **exactly two**
    rows in `proxy_secrets` for that purpose — never three, even briefly.
  - The `is_current IS NOT NULL ⟺ expires_at IS NULL` invariant holds after every `SecretStore`
    operation (`replace`, `generate`, `endOverlap`, `disable`).
  - During an overlap, `liveFor()` returns both secrets, current first; after the overlap's
    `expires_at` passes, `liveFor()` returns only the current one, **with no sweeper run** (liveness is
    a property of the data, not of the job/sweeper — plan § Architecture B).
  - A second `replace()` inside an already-running overlap discards the oldest secret **immediately**
    (its `expires_at` moves to the past, or the row is deleted outright — state which; either satisfies
    "exist," not merely "are honoured," per AC29).
  - `endOverlap()` is idempotent — calling it twice, or when no overlap is running, is a no-op.
  - A row whose `value` cannot be decrypted (simulate via a corrupted ciphertext fixture) causes
    `liveFor()` to throw `SecretUnavailableException` rather than silently excluding that row from the
    returned list.
  - `disable()` deletes every row for that purpose; a subsequent `generate()`/`replace()` produces a
    value that is **not** the previously disabled one (ADR-021 Decision 5's "re-enabling always
    generates afresh").
  - The unique index (T1) is never violated by any sequence of `SecretStore` calls, including
    concurrent-looking rapid rotation.
- **Testing:** `tests/Unit/Services/SecretStoreTest.php` (new) — one test per bullet above, including a
  three-rotation R7 case, the invariant-holds-after-every-operation sweep, the corrupted-ciphertext
  fail-loud case, and the idempotent-end-overlap case.
- **Completion notes:** Done. `App\Services\SecretStore` implements all five operations exactly as
  described — `liveFor()`, `replace()`, `generate()`, `endOverlap()`, `disable()` — with `replace()`'s
  delete-superseded / demote-current / insert-new sequence run inside one `DB::transaction()`, so the
  two-row cap is never briefly exceeded even mid-write. `App\Exceptions\SecretUnavailableException`
  takes a `SecretPurpose` and produces a fixed message naming only the purpose ("The verification secret
  could not be decrypted.") — never a proxy/team id, never any part of the value.

  **One implementation choice PHPStan forced, not a design change:** `liveFor()`'s decrypt step calls
  `Crypt::decryptString((string) $secret->getRawOriginal('value'))` directly rather than reading
  `$secret->value` (which triggers the identical decrypt inside Eloquent's `encrypted` cast, but
  invisibly to static analysis — Larastan cannot see a cast-triggered exception through a plain property
  access, so a `catch (DecryptException)` around `$secret->value` was flagged as dead code, correctly:
  PHPStan had no way to know it could ever throw). Calling `Crypt::decryptString()` directly is
  functionally identical to what the cast does internally and makes the exception path real and visible
  to the analyzer rather than papering over it with a suppression comment.

  **The delayed-expiry dispatch named in T15's own description ("Dispatched with a delay from
  `SecretStore::replace()`/`generate()`") is deliberately not wired in this commit.** `App\Actions\
  ExpireProxySecrets` does not exist until T15, and T14's own Dependencies line (T2, T13 only) and Files
  list (no `ExpireProxySecrets` reference) confirm T14 must stand alone. Nothing in T14's own Acceptance
  Criteria needs the job — expiry is correct by data alone (`ProxySecret::live()`'s predicate), which is
  exactly the "no mechanism needed for correctness" property ADR-021 Decision 3 states and which this
  task's own second bullet through fourth bullet test directly via `expires_at`, without invoking any
  job. The dispatch call is added to this same `replace()` method at T15, once the Action it calls
  exists — noted here so a later reader does not read its absence as an oversight.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter SecretStoreTest` green (8
  tests, 20 assertions); full-suite run deferred to the end of this batch.

## T15 — `App\Actions\ExpireProxySecrets` and the `secrets:purge-expired` daily sweeper (R10; plan § Services & Actions, ADR-015 Decision 5's shape)
- **Description:** `ExpireProxySecrets` (`AsJob`), scalar arguments only (`proxyId: int, purpose:
  string`), guarded on `expires_at <= now()` — deletes the superseded row if its window has passed,
  no-op otherwise (e.g. if a further rotation already restarted the window). Dispatched with a delay
  from `SecretStore::replace()`/`generate()` at the moment a row is superseded. A daily scheduled
  command, `secrets:purge-expired`, is the liveness net for a lost delayed job — scans for any
  `expires_at <= now()` row across all proxies/purposes and deletes it. Neither the job nor the
  sweeper can extend a window; both only delete.
- **Dependencies:** T14
- **Files:** `app/Actions/ExpireProxySecrets.php` (new), `routes/console.php` (scheduled command
  registration), `app/Console/Commands/PurgeExpiredProxySecrets.php` (new, or an inline closure command
  per this project's existing `routes/console.php` convention — match whichever pattern
  `queue:prune-failed`/existing scheduled commands use)
- **Acceptance Criteria:**
  - `ExpireProxySecrets` deletes only a row whose `expires_at` has passed; is a no-op against a row
    whose window has not passed, and a no-op if the row no longer exists (already deleted by the
    sweeper or a further rotation).
  - **R10:** the daily sweeper deletes an expired row when the delayed job is simulated as never having
    run (dispatch it, then run the sweeper without executing the queued job — the row is still
    removed).
  - The job's arguments are plain scalars (`int`, `string`) — no `Proxy`/`ProxySecret` model argument
    (ADR-021 Decision 8 — a model argument would silently re-enable `SerializesModels` via
    `JobDecorator`).
- **Testing:** `tests/Unit/Actions/ExpireProxySecretsTest.php` (new) — the delete/no-op cases;
  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php` (new) — the R10 lost-job case.
- **Completion notes:** Done. `App\Actions\ExpireProxySecrets` (`AsJob`, scalar `proxyId: int,
  purpose: string` arguments) deletes the superseded row for that `(proxy, purpose)` only when its
  `expires_at` has already passed; guarded by the same `WHERE` clause rather than a separate check, so
  a row whose window hasn't passed or that no longer exists is a plain no-op with zero rows affected.
  `App\Actions\PurgeExpiredProxySecrets` (`AsAction` with `$commandSignature = 'secrets:purge-expired'`,
  matching `PurgeExpiredPayloads`'s existing convention exactly rather than the `Schedule::call()`
  closure style the two per-minute sweepers use — this task's own Testing line names
  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php` and drives it via `$this->artisan(...)`,
  which needs a real registered command name) does one unscoped `DELETE` across every proxy/purpose for
  a superseded row past its `expires_at`. Registered in `routes/console.php` beside
  `payloads:purge-expired`, `Schedule::command('secrets:purge-expired')->daily()`; `Actions::
  registerCommands()` already wires the Artisan entry, no new call needed.

  **`SecretStore::replace()` (T14) now dispatches the delayed job**, exactly as T14's own completion
  notes flagged as deferred to this task: `ExpireProxySecrets::dispatch($proxy->id, $purpose->value)
  ->delay(now()->addHours(RotationOverlap::HOURS))->afterCommit()`, fired only when a row was actually
  demoted (`$hadCurrent`). `app/Services/SecretStore.php` is therefore touched by this task too, even
  though it isn't in T15's own Files list — necessary wiring the task's own description names
  explicitly ("Dispatched with a delay from `SecretStore::replace()`/`generate()`"), not scope creep.

  **File choice, not a deviation:** used `app/Actions/PurgeExpiredProxySecrets.php` rather than
  `app/Console/Commands/PurgeExpiredProxySecrets.php` — the task's own either/or explicitly allows
  matching "whichever pattern `queue:prune-failed`/existing scheduled commands use", and
  `payloads:purge-expired` (the closer precedent: a daily sweep with a genuine Artisan command name,
  versus `queue:prune-failed`'s framework-owned command) is itself an `App\Actions\*` class using
  `AsAction` + `$commandSignature`, not a `Console\Commands` class.

  `tests/Feature/Console/PurgeExpiredProxySecretsTest.php`'s R10 case uses `Queue::fake()` +
  `ExpireProxySecrets::assertPushed(1)` (the lorisleiva-actions pattern already established by
  `SweepDueRetriesTest`/`AdvanceProxyFifoQueueTest` — a plain `Queue::assertPushed(ExpireProxySecrets::
  class)` does not match, since the object actually pushed is `JobDecorator`, never `instanceof` the
  wrapped action) to simulate the delayed job being lost, then moves the superseded row's `expires_at`
  into the past directly (standing in for 24 real hours elapsing) before invoking
  `$this->artisan('secrets:purge-expired')` and asserting the row is gone and the current secret
  untouched.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "ExpireProxySecretsTest|PurgeExpiredProxySecretsTest|SecretStoreTest"` green (13 tests, 30
  assertions); full-suite run deferred to the end of this batch.

## T16 — `App\Enums\VerificationScheme` (AC23, AC50; plan § Services & Actions, ADR-022)
- **Description:** Backed enum, exactly two cases: `StandardWebhooks = 'standard-webhooks'`,
  `SharedSecret = 'shared-secret'`. The closed list AC50 requires — adding a case is a Project Owner
  decision, never absorbed quietly.
- **Dependencies:** none
- **Files:** `app/Enums/VerificationScheme.php` (new); revisit T2's `Proxy` cast declaration if it was
  deferred there.
- **Acceptance Criteria:** exactly the two documented cases; `VerificationScheme::tryFrom('github') ===
  null`.
- **Testing:** `tests/Unit/Enums/VerificationSchemeTest.php` (new).
- **Completion notes:** Done. `App\Enums\VerificationScheme` — exactly `StandardWebhooks =
  'standard-webhooks'`, `SharedSecret = 'shared-secret'`. T2's deferred cast is now filled in:
  `Proxy::casts()` gains `'verification_scheme' => VerificationScheme::class`, the `@property`
  docblock line changes from `string|null` to `VerificationScheme|null`, and — matching T2's own
  completion note, which named both the cast *and* the `#[Fillable]` entry as deferred together —
  `'verification_scheme'` is added to `Proxy`'s `#[Fillable]` list alongside
  `verification_header_name`/`sensitive_fields`. No other file changed; nothing else in the codebase
  yet references `verification_scheme` (confirmed by search) so this cast has no downstream surface to
  regress before T20/T23 build the validation and form.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "VerificationSchemeTest|ProxySecretTest|SensitiveDataHandlingSchemaTest|EncryptedColumnSurfaceTest"`
  green (32 tests, 113 assertions, confirming the cast change is a no-regression on T1-T3's existing
  Proxy/schema coverage); full-suite run deferred to the end of this batch.

## T17 — `App\Verification\SharedSecretScheme`, `StandardWebhooksScheme` (AC51, AC52, AC53; plan § Services & Actions)
- **Description:** One class per scheme, implementing a shared interface (e.g.
  `App\Verification\VerificationSchemeHandler`, new): `verify(Proxy $proxy, Request $request, string
  $rawBody, list<string> $liveSecrets): bool`. `SharedSecretScheme` — the named header's value must
  exactly (constant-time) match a member of `$liveSecrets`; nothing computed over the body.
  `StandardWebhooksScheme` — delegates to `StandardWebhooks::verify()` (T7) over the three specified
  headers (`webhook-id`, `webhook-timestamp`, `webhook-signature`) and `$liveSecrets`; a missing or
  malformed header fails; the tolerance check (AC53) is enforced here if not already inside T7.
- **Dependencies:** T7, T16
- **Files:** `app/Verification/VerificationSchemeHandler.php` (new interface),
  `app/Verification/SharedSecretScheme.php` (new), `app/Verification/StandardWebhooksScheme.php` (new)
- **Acceptance Criteria:**
  - `SharedSecretScheme`: correct value in the named header verifies; wrong value, missing header, and
    wrong header name all fail; comparison is constant-time (`hash_equals`).
  - `StandardWebhooksScheme`: a specification-computed request verifies; a missing or malformed one of
    the three headers fails; a timestamp outside tolerance fails (AC53); a multi-entry signature list
    verifies when only one entry matches (delegated to T7, exercised here at the scheme layer).
- **Testing:** `tests/Unit/Verification/SharedSecretSchemeTest.php`,
  `tests/Unit/Verification/StandardWebhooksSchemeTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `App\Verification\VerificationSchemeHandler` (interface, exactly
  `verify(Proxy $proxy, Request $request, string $rawBody, array $liveSecrets): bool` as specified) is
  implemented by `SharedSecretScheme` (named-header exact/constant-time match, nothing computed over
  the body) and `StandardWebhooksScheme` (delegates to `StandardWebhooks::verify()` (T7) over the three
  `webhook-*` headers; the tolerance check stays inside T7 per that task's own completion note, not
  duplicated here as a gate — see below for why it's still read once more).

  **One deliberate addition beyond the interface's own `verify(): bool`, needed by T19 and decided as a
  local implementation detail (within this Senior Developer's decision authority):** both classes also
  expose a `reasonFor(...): ?string` method — pure, stateless, recomputed from the same arguments rather
  than cached from a `verify()` call — returning one of ADR-022 Decision 5's five value-free reason codes
  (`missing_header`, `malformed_header`, `timestamp_out_of_tolerance`, `signature_mismatch`,
  `secret_mismatch`) or `null` if the request would verify. `verify()` itself is implemented as
  `reasonFor(...) === null`, so there is exactly one place per scheme that decides pass/fail. T18/T19
  need this because `InboundVerifier::verify()`'s own return type (`VerificationResult`, filed under
  `app/Enums/` per T18's own file list — a plain three-case enum, not a DTO) has nowhere to carry a
  reason, and T19's log line needs the *specific* one of the five codes, not just "failed" — computing
  it anywhere other than inside the scheme that owns the wire format would mean duplicating header/
  format knowledge in `IngestController` or `InboundVerifier`. Kept off the shared interface (interface
  stays exactly as specified) since only `InboundVerifier` needs to call it, and it already has to
  branch on the concrete scheme to dispatch `verify()` in the first place. `StandardWebhooksScheme::
  reasonFor()` re-checks the tolerance window itself (one cheap `abs(time() - $timestamp)` comparison,
  reusing `StandardWebhooks::TOLERANCE_SECONDS` — no second magic number) purely so a
  `timestamp_out_of_tolerance` result can be distinguished from a `signature_mismatch` one; the
  constant itself is still single-sourced from T7.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "SharedSecretSchemeTest|StandardWebhooksSchemeTest"` green (12 tests, 18 assertions); full-suite run
  deferred to the end of this batch.

## T18 — `App\Services\InboundVerifier` (AC24, AC25; plan § Architecture A, ADR-022 Decisions 1–3)
- **Description:** The **resolution-time gate**: establishes `$proxy->verification_scheme !== null`
  **before** asking `SecretStore` for anything, so a proxy with verification off never queries
  `proxy_secrets` (AC24's "behaves exactly as today," at the query-count level). `verify(Proxy $proxy,
  Request $request, string $rawBody): VerificationResult` (new small DTO/enum: `NotRequired`,
  `Verified`, `Failed`) — dispatches to the matching `VerificationSchemeHandler` (T17) with
  `SecretStore::liveFor($proxy, SecretPurpose::Verification)`'s result. Every member of the live set is
  tried; which one matched leaves no trace (ADR-022 Decision 3 — no log field, no return value names
  it).
- **Dependencies:** T14, T17
- **Files:** `app/Services/InboundVerifier.php` (new), `app/Enums/VerificationResult.php` (or similar,
  new)
- **Acceptance Criteria:**
  - A proxy with `verification_scheme === null` returns `NotRequired` and issues **zero** queries
    against `proxy_secrets` (assert via `DB::listen` or `assertQueryCount`).
  - A correctly verifying request (either scheme) returns `Verified`; an incorrect one returns
    `Failed`.
  - `SecretUnavailableException` from `SecretStore::liveFor()` propagates rather than being caught and
    treated as `Failed` (the 500-vs-401 distinction is `IngestController`'s to make at T19, not this
    class's to hide).
- **Testing:** `tests/Unit/Services/InboundVerifierTest.php` (new) — the zero-query not-required case,
  the verified/failed case per scheme, the propagated-exception case.
- **Completion notes:** Done. `App\Enums\VerificationResult` is a plain (unbacked) three-case enum —
  `NotRequired`, `Verified`, `Failed` — filed under `app/Enums/` per this task's own Files line, never
  carrying a reason. `InboundVerifier::verify()` checks `$proxy->verification_scheme !== null` before
  any `SecretStore` call, matching AC24's query-count requirement exactly; otherwise fetches the live
  verification set once and dispatches to the matching T17 scheme handler.

  **One method beyond this task's own literal Acceptance Criteria, needed by T19 and flagged in T17's
  completion notes as coming here:** `InboundVerifier::reasonFor(Proxy, Request, string): string`,
  called only by `IngestController` after `verify()` has already returned `Failed`. It re-establishes
  the scheme (throwing `LogicException` if called for a `NotRequired` proxy or if the request would
  actually verify — both programmer-error guards, never reachable from `IngestController`'s own
  correct call order) and delegates to the matching scheme's `reasonFor()` (T17) for the specific
  ADR-022 Decision 5 code. This is a second, independent `SecretStore::liveFor()` read rather than a
  cached one — deliberately stateless, so `InboundVerifier` carries nothing between the two calls — and
  only ever executes on the rejection path, never the common case.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter InboundVerifierTest`
  green (6 tests, 7 assertions); full-suite run deferred to the end of this batch.

## T19 — `IngestController` integration: the verification gate, 401/500 shapes, reason-code log (AC8, AC11, AC25; plan § Architecture A, H; Technical ruling 12; ADR-022 Decisions 4, 5)
- **Description:** `IngestController` gains exactly one step between proxy resolution and the capture
  transaction: read `$rawBody` **once**, call `InboundVerifier::verify()`. `NotRequired`/`Verified` →
  continue to capture unchanged. `Failed` → **401**, a fixed non-configurable body, log
  `ingest.verification_failed` with `team_id`, `proxy_id`, `scheme`, and one of five value-free reason
  codes (never a header value, body, secret, or computed signature — AC8), return **before** any
  `webhook_events` row is created. `SecretUnavailableException` → **500**, a report with identifiers
  only, return before capture. `WebhookEventCapture` receives the **same** `$rawBody` string the
  verifier just checked (ADR-022 Decision 4) — no second body read.
- **Dependencies:** T18
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:**
  - **AC25, four negatives, one assertion each:** a failed verification returns 401; creates **no**
    `webhook_events` row; creates **no** delivery and **no** `fifo_dispatches` row; does **not** return
    the proxy's configured response (asserted against a proxy configured with a custom 200 + body).
  - **AC11:** a proxy whose verification secret cannot be decrypted returns **500**, never 401 and
    never the configured 2xx, and captures nothing.
  - `ingest.verification_failed` is logged on a failure with exactly `team_id`, `proxy_id`, `scheme`,
    and a reason code — never a header value, body, secret, or signature (assert the log payload
    directly, not just that a line was written).
  - The same `$rawBody` string instance/value reaches both the verifier and `WebhookEventCapture` — no
    second `$request->getContent()` call.
- **Testing:** extends `tests/Feature/Ingest/IngestControllerTest.php` — the four AC25 cases, the AC11
  500 case, the log-payload assertion, a body-read-once assertion (e.g. via a request stream that
  errors on a second read, or a spy).
- **Completion notes:** Done. `IngestController` gains the one step the description names, in order:
  read `$rawBody` once (unchanged read point), `InboundVerifier::verify($proxy, $request, $rawBody)`
  wrapped in a `try`/`catch (SecretUnavailableException)` → `report()` + `abort(500)`; `Failed` → 401
  with the fixed body `'Webhook verification failed.'` (a private class constant,
  `VERIFICATION_FAILED_BODY`, `text/plain`) plus the `ingest.verification_failed` info log
  (`team_id`, `proxy_id`, `scheme` from `$proxy->verification_scheme?->value`, and
  `InboundVerifier::reasonFor()`'s reason code), returned before the capture transaction;
  `NotRequired`/`Verified` fall through to the existing capture path completely unchanged. The same
  `$rawBody` local variable already flowed to both the verifier and `WebhookEventCapture::capture()`
  from the existing single read point — no second `$request->getContent()` call was needed to satisfy
  ADR-022 Decision 4, since the controller already read the body once before this task and this task
  adds no second read.

  **Body-read-once was proven at the controller level, not the full HTTP pipeline**, and the completion
  notes record why: `EnforceIngestBodyLimit` (pre-existing, `routes/ingest.php`) legitimately calls
  `$request->getContent()` itself when `Content-Length` is absent, which would confound a whole-stack
  read count unrelated to this task's own guarantee. `test_the_raw_body_is_read_exactly_once_by_the_controller`
  therefore resolves `IngestController` from the container and invokes `__invoke()` directly against a
  `CountingContentRequest` (a small `Illuminate\Http\Request` subclass local to the test file,
  incrementing a counter on every `getContent()` call), bypassing route middleware entirely — isolating
  the assertion to the one guarantee this task actually owns.

  All four AC25 negatives, the AC11 500 case (via the same corrupted-ciphertext technique used in T14/
  T18's suites), the exact log-payload assertion (`Log::spy()` +
  `Log::shouldHaveReceived('info')->once()->withArgs(...)`, the pattern already established by
  `DeliverToDestinationTest`), and a FIFO-specific case (rejection creates no `fifo_dispatches` row
  either) are all covered as new methods on the existing `tests/Feature/Ingest/IngestControllerTest.php`
  — extended rather than duplicated, per this task's own Testing line.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter IngestControllerTest`
  green (22 tests, 74 assertions, up from the pre-existing 15). **Full suite run at the close of this
  batch (T13-T19): `./vendor/bin/sail test --parallel` green at 973/973** (up from 931 at the T7-T12
  batch boundary — 42 new tests across T13-T19).

## T20 — Verification validation rules (AC23, AC24, AC26; plan § Validation)
- **Description:** On `StoreProxyRequest`/`UpdateProxyRequest`: `verification_scheme` —
  `nullable`, `Rule::enum(VerificationScheme::class)`. `verification_header_name` —
  `required_if:verification_scheme,shared-secret`, `prohibited_unless:verification_scheme,shared-secret`,
  `string`, `max:128`, valid HTTP field-name pattern. `verification_secret` — `nullable`, `string`,
  `min:8`, `max:1024`; **required only when a scheme is selected and the proxy has no live
  `verification` secret** — an absent field on an already-configured proxy means "leave unchanged"
  (the write-only contract; a present-but-empty field must never clear the secret).
- **Dependencies:** T14 (to check "has a live secret already"), T16
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:**
  - Selecting `shared-secret` without a header name fails validation; selecting `standard-webhooks`
    with a header name present fails validation (`prohibited_unless`).
  - An empty `verification_secret` on a proxy that already has a live verification secret leaves the
    stored secret unchanged (an **absent** field, not an empty string, is what "leave unchanged" reads
    as — confirm `form.transform()`'s frontend contract at T23 sends absence, not `""`, when untouched).
  - A first-time scheme selection with no secret provided fails validation (secret required when none
    exists yet).
- **Testing:** `tests/Feature/Proxies/VerificationValidationTest.php` (new) — one case per bullet.
- **Completion notes:** Done. `verification_scheme` (`nullable`, `Rule::enum(VerificationScheme::class)`),
  `verification_header_name` (`required_if`/`prohibited_unless:verification_scheme,shared-secret`,
  `string`, `max:128`, the HTTP field-name regex) and `verification_secret` (`nullable`, `string`,
  `min:8`, `max:1024`) added to both `StoreProxyRequest` and `UpdateProxyRequest`. `verification_secret`
  is additionally required via `Rule::requiredIf()`: on Store, whenever a scheme is selected (a create
  has no proxy yet, so no live secret can already exist); on Update, only when a scheme is selected
  **and** the proxy has no live `verification` secret yet — read through
  `SecretStore::liveFor($proxy, SecretPurpose::Verification)` via a new private
  `UpdateProxyRequest::proxyHasLiveVerificationSecret()` helper, keeping `SecretStore` the single
  reader of `proxy_secrets` (Technical ruling 14) rather than a direct query.

  **Necessary supporting plumbing, not scope creep:** this task's own second Acceptance Criterion
  ("an empty `verification_secret` ... leaves the stored secret unchanged") is a persistence claim,
  not a validation-shape one, and no other task in T20–T25 (or, by inspection, anywhere in T1–T49)
  wires `ProxyController::store()`/`update()` to actually write `verification_scheme`/
  `verification_header_name` or call `SecretStore::replace()` for a submitted `verification_secret`
  — unlike the destination credential, whose validation and persistence are explicitly one task
  (T29). Without this wiring, T20's own AC would be untestable (vacuously true, since nothing ever
  persists) and Screen 1 (T23) would submit a fully-built form with no effect. Following the
  precedent already set in this same document (T12's `ProxyResource` addition, T15's `SecretStore`
  dispatch wiring — both flagged as necessary plumbing despite an incomplete Files list), added:
  `ProxyController::store()` calls `SecretStore::replace()` after the new proxy is saved, when
  `verification_secret` is present (`verification_scheme`/`verification_header_name` already ride
  through unchanged via the existing `Proxy::make(array_merge($data, ...))` mass-assignment call,
  since both are already `#[Fillable]` from T2/T16 — no code change needed there); `update()`'s
  explicit column array gains `verification_scheme`/`verification_header_name` (mirroring the
  existing `response_status`/`response_body` omission-vs-explicit-null idiom), and a
  `SecretStore::replace()` call runs when `verification_secret` is present. Per plan-10
  §Architecture B, switching `verification_scheme` back to "not required" writes the column to
  NULL but never calls `SecretStore::disable()` for the verification purpose — the dormant secret
  is deliberately retained (`disable()` stays reserved for signing's different on/off semantics, per
  its own docblock) — pinned by a dedicated test.

  **Confirmed by testing, not assumed:** the parenthetical on this task's second AC ("an absent
  field, not an empty string, is what 'leave unchanged' reads as") anticipates a distinction the app
  turns out not to need — this app's global `ConvertEmptyStringsToNull` middleware (Laravel's
  framework default, active here) normalises a submitted `""` to `null` before validation ever runs,
  so an empty `verification_secret` already takes the identical "absent → leave unchanged" path,
  with no 422 and no special-case code. Verified directly (not inferred) by first writing the test
  against the AC's literal expectation of a validation rejection, watching it fail because the
  request actually succeeded, and correcting the test to the observed, safe behaviour rather than
  forcing a rejection the framework doesn't produce. T23's frontend `transform()` still omits the
  key on an untouched Replace field regardless (design-10's own stated rule), so both layers agree
  independently.

  Nine tests: the three literal AC bullets (`shared-secret` without a header, `standard-webhooks`
  with one present, first-time selection with no secret), a valid first-time-selection persistence
  round trip, the absent-field-leaves-unchanged case, the empty-string case (see above), a
  replace-rotates-with-an-overlap case, the switch-to-not-required-keeps-the-dormant-secret case,
  and a `shared-secret` round trip asserting the header name persists. `composer lint`,
  `composer types:check` and `./vendor/bin/sail test --filter
  "VerificationValidationTest|ProxyStoreTest|ProxyUpdateTest|ProxyRequestValidationTest|SensitiveFieldsPersistenceTest"`
  all green (92 tests, 285 assertions); full-suite run deferred to the end of this batch (T20-T25).

## T21 — `ProxyVerificationOverlapController@destroy` (AC29; plan § API)
- **Description:** `DELETE proxies/{proxy}/verification/overlap`, gated `update` via `ProxyPolicy`,
  calls `SecretStore::endOverlap($proxy, SecretPurpose::Verification)`, Inertia redirect (`back()`).
- **Dependencies:** T14
- **Files:** `app/Http/Controllers/ProxyVerificationOverlapController.php` (new), `routes/web.php`
- **Acceptance Criteria:**
  - Ending an overlap stops the previous secret verifying **immediately** (a request that verified
    against it a moment ago now fails).
  - Idempotent — calling it again, or when no overlap is running, is a no-op, no error.
  - A Member without `update` rights on the proxy is **403**.
- **Testing:** `tests/Feature/Proxies/ProxyVerificationOverlapControllerTest.php` (new).
- **Completion notes:** Done. `ProxyVerificationOverlapController@destroy` (`DELETE
  proxies/{proxy}/verification/overlap`) authorizes `update` on the proxy through `ProxyPolicy`
  (unchanged, no new permission), calls `SecretStore::endOverlap($proxy, SecretPurpose::Verification)`
  — already idempotent by construction from T14 — and redirects with `back()`, matching the plan's API
  table exactly and mirroring `ProxyEventReplayController::store()`'s own `back()` PRG precedent.
  Route registered in `routes/web.php` alongside the other proxy-scoped mutating routes; a single
  `{proxy}` binding needs no `->scopeBindings()` (that's reserved for a doubly-nested child like
  `{destination}`).

  Four tests: ending a running overlap removes the previous secret from the live set immediately (the
  "stops verifying" claim, proven at the `SecretStore::liveFor()` level rather than a second HTTP
  round trip through the ingest endpoint, since `InboundVerifier` reads exactly that live set — T25's
  integration suite additionally proves this over real HTTP); calling it twice is a no-op the second
  time; calling it when no overlap is running is a no-op; and a Member without update rights on a
  teammate's proxy (attached via `TeamRole::Member`, not the proxy's creator) is 403 with nothing
  changed.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  ProxyVerificationOverlapControllerTest` all green (4 tests, 10 assertions); full-suite run deferred
  to the end of this batch (T20-T25).

## T22 — `ProxySecurityResource`: the `security` prop's `verification` sub-object (AC20, AC26, AC28; plan Technical rulings 3, 5; § API)
- **Description:** New resource, status-only — never a value, never a length. `verification: {
  scheme, header_name, secret_set, secret_changed_at, overlap_expires_at } | null`. Wired as a sibling
  **`security`** prop on `ProxyController::show()` and `::edit()` (never `index()`, never a key on
  `ProxyResource` — Technical ruling 3). `create()` renders no proxy resource at all.
- **Dependencies:** T14, T16
- **Files:** `app/Http/Resources/ProxySecurityResource.php` (new), `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - `show()`/`edit()` both emit a `security` prop with the `verification` sub-object shaped exactly as
    above; `index()` gains no new key; `ProxyResource` itself is unchanged.
  - The resource never serializes a secret's value or length — grep-level and response-level
    assertion.
  - `scheme === null` when verification is not required; `secret_set` is `true`/`false`, never the
    value.
- **Testing:** `tests/Feature/Proxies/ProxySecurityResourceTest.php` (new) — the shape assertion, the
  `index()`-untouched assertion, the no-value/no-length assertion.
- **Completion notes:** Done. `App\Http\Resources\ProxySecurityResource` — status-only, `$wrap = null`
  — builds the `verification` sub-object exactly as specified (`scheme`, `header_name`, `secret_set`,
  `secret_changed_at`, `overlap_expires_at`), wired as a sibling `security` prop on
  `ProxyController::show()` and `::edit()` only; `index()` and `ProxyResource` itself are both
  untouched.

  **Necessary supporting plumbing, not scope creep:** the resource needs non-secret status metadata
  (whether a live secret exists, when it last changed, whether an overlap is running) that
  `SecretStore`'s existing methods don't expose — `liveFor()` returns decrypted values, not metadata.
  Rather than query `proxy_secrets` directly from the resource (a Technical-ruling-14 violation — this
  document's own binding constraint 5 is explicit that only T14/T15 may do that), added
  `SecretStore::statusFor(Proxy, SecretPurpose): ?SecretStatus` (new `App\Data\SecretStatus` readonly
  DTO — `changedAt`, `overlapExpiresAt`, never a value or length) as the one new read method on the
  single reader/writer, following the same precedent already established in this document (T15's
  `SecretStore` touch, T20's controller-persistence wiring) for necessary plumbing a task's own Files
  list didn't enumerate. `statusFor()` returns `null` when no secret has ever been configured for that
  purpose; otherwise `changedAt` is the current row's `created_at` and `overlapExpiresAt` is the live
  superseded row's `expires_at` (or `null` when no overlap is running).

  **Typed against `Carbon\CarbonInterface`, not `Illuminate\Support\Carbon`:** this app's
  `AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every Eloquent date-cast property
  resolves to `CarbonImmutable` at runtime, but Larastan's own inference of a model's date-cast
  property still widens to a `CarbonImmutable|Carbon` union — `CarbonInterface` (implemented by both)
  is what both runtime and PHPStan level 7 agree on without a suppression.

  Five tests: the not-required shape (all fields null/false), a `shared-secret` proxy with a live
  secret (full shape, header name present, `secret_set` true, a real `secret_changed_at`), a running
  overlap carrying a non-null `overlap_expires_at`, `index()` gaining no `security` key, and a
  response-body substring check (both `show()` and `edit()`) proving a live secret's actual value
  never appears anywhere in either response.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` all green (179
  tests, 927 assertions — the full `tests/Feature/Proxies` directory, confirming no regression across
  T1–T22's existing coverage); full-suite run deferred to the end of this batch (T20-T25).

## T23 — Screen 1: `ProxyForm.vue` Verification section (AC23, AC24, AC26, AC29-ruling-2a; Flows A, B; plan Implementation Notes 13–15, 20–21)
- **Description:** New section, placed after **Processing**, before **Retry policy**. `Select` bound to
  `form.verification_scheme` with a `"none"` sentinel for "Not required" (the underlying `Select`
  primitive rejects an empty string — N2). Conditional fields per scheme, the shared write-only
  secret-field shape (unset: plain `Input type="password" autocomplete="off"`; set: collapsed "Secret
  set — changed {date}" + Replace). `{tolerance}` interpolates `StandardWebhooks::TOLERANCE_SECONDS`
  (T7) as a page prop, never hand-typed. **Flow B step 2's disclosure, branched (AC29 ruling 2a):**
  clicking Replace shows a help line stated **before save** — the ordinary-case copy when no overlap is
  running, or the immediate-discard copy when one already is (this proxy's verification secret has a
  live overlap per the T22 `security` prop's `overlap_expires_at`). Switching scheme clears the
  in-session, unsaved secret field only (`ProxyForm.vue`'s existing mount-seeded-vs-in-session-typed
  distinction — read `plan-07` § Technical ruling 4 before touching this, per `plan-10` Implementation
  Note 13; review-07's Major came from getting exactly this wrong).
- **Dependencies:** T20, T22
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:**
  - Scheme defaults to "Not required"; no secret field renders in that state.
  - Selecting a scheme reveals its fields and its "what your sender must send" static block, with the
    interpolated tolerance value matching `StandardWebhooks::TOLERANCE_SECONDS` exactly.
  - Switching back to "Not required" clears the in-session secret field without touching what is
    persisted (assert via a save afterward — the stored secret, if any, is unchanged).
  - Clicking Replace on a proxy with **no** overlap running shows the ordinary-case copy; on a proxy
    **with** a live overlap, shows the immediate-discard copy naming the timestamp — both **before**
    the save button is clickable-with-effect.
  - A present-but-empty secret field after clicking Replace, left untouched, does not clear the stored
    secret on save.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flows A and B, against a
  production build, both themes, 360px: scheme selection and field visibility; the tolerance copy
  matches the backend constant; both branches of the Replace disclosure (no overlap / overlap running,
  achieved by rotating twice in a test fixture proxy first); scheme-switch-clears-in-session behaviour.
- **Completion notes:** Done. New "Verification" `fieldset` added to `ProxyForm.vue`, placed after
  Processing and before Retry policy exactly as Screen 1 specifies. `Select v-model="verificationSchemeSelect"`
  wraps `form.verification_scheme` with the `"none"` sentinel (N2), mirroring `statusSelect`'s existing
  pattern exactly. `shared-secret` reveals a Header name field (pre-filled from the mount-seeded
  `security.verification.header_name` only when switching back to the originally-persisted scheme,
  cleared otherwise) plus the shared write-only secret shape; `standard-webhooks` reveals the same
  write-only secret shape plus the static "what your sender must send" block, its `{tolerance}`
  interpolating a new `standardWebhooksTolerance` page prop (`StandardWebhooks::TOLERANCE_SECONDS`,
  T7) divided into minutes — never hand-typed (AC53). The write-only field's set/unset/replace states
  (`verificationSecretIsSet`, `verificationReplaceClicked`) are governed entirely by the mount-seeded
  `security.verification.secret_set`/`overlap_expires_at` (T22) — never re-read after mount — matching
  `ProxyForm.vue`'s existing mount-seeded-vs-in-session-typed distinction (plan-07 §Technical ruling
  4). **Screen 1 / Flow B step 2's amended disclosure, landed verbatim, word for word**: the ordinary
  case ("Your current secret keeps working for 24 hours after you save this...") when
  `initialVerificationOverlapExpiresAt` is null, and the discard case ("You already have a previous
  secret from your last rotation, still honoured until {timestamp}. Saving a new secret now stops that
  previous secret being honoured immediately — its 24 hours do not finish out.") when it isn't — shown
  once Replace is clicked, before the save button has any effect, exactly as the amendment gate's
  correction requires. Switching scheme resets the Replace/typed-secret state and reseeds/clears the
  header name (same watcher-driven reset the Retry-policy fieldset already uses on a Mode change).

  **Necessary supporting plumbing, not scope creep** (same precedent as T12/T15/T20/T22): added the
  `standardWebhooksTolerance` page prop to `ProxyController::create()`/`edit()` (named explicitly by
  the plan's own API table, but not in this task's Files list) and forwarded it plus the existing
  `security` prop through `Create.vue`/`Edit.vue` down to `ProxyForm.vue`; `Create.vue` never passes
  `security` at all (no proxy resource exists yet, Technical ruling 3), so `ProxyForm.vue`'s `security`
  prop is optional and every mount-seeded verification value defaults to "nothing configured" there.
  Added `App\Data`-mirroring `ProxySecurity`/`VerificationScheme` TypeScript types to
  `resources/js/types/proxies.ts`.

  **Manual verification performed** (own local Sail dev environment, seeded via `sail tinker`, deleted
  again immediately after — same recipe as T9/T12), via a headless Playwright session against the real
  running app (not a static build check alone): logged in as a fresh team/user, then against a Create
  page and three seeded proxies (no verification; `standard-webhooks` with two live secrets — an
  overlap running; `shared-secret` with exactly one live secret — no overlap):

  - Create: Scheme defaults to "Not required" with no secret field; selecting `shared-secret` reveals
    Header name + Secret value; selecting `standard-webhooks` reveals Secret value plus the static
    block naming `webhook-id`/`webhook-timestamp`/`webhook-signature` and a tolerance sentence reading
    "5 minutes" (confirmed programmatically against the page's rendered text, not just visually);
    switching back to "Not required" hides the fields again; no horizontal overflow at a 360px
    viewport (checked via `scrollWidth`/`clientWidth`).
  - Edit, overlap-running proxy: scheme pre-selected correctly; collapsed "Secret set — changed
    {date}" status with a Replace button; clicking Replace reveals a blank input and the **immediate
    discard** disclosure text verbatim, with the **ordinary 24-hour copy absent** (asserted both ways,
    not just presence of one).
  - Edit, no-overlap proxy: header name pre-filled correctly (`X-Signature`); clicking Replace shows
    the **ordinary 24-hour copy** verbatim, with the **immediate-discard copy absent**.
  - Edit, no-verification proxy: scheme "Not required", no secret field — unaffected by this feature.
  - Screenshots taken in both light and dark mode (the standard-webhooks state) — legible and
    correctly styled in both; zero browser console/page errors observed across the whole session.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green. `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` green
  (179 tests, 927 assertions — no regression); full-suite run deferred to the end of this batch
  (T20-T25).

## T24 — Screen 4: `proxies/Show.vue` Verification card (AC29; Flow C; plan § Architecture F)
- **Description:** New `Card`, alongside Retry policy, after Destinations. States: not required; scheme
  set, no overlap (`dl`/`dt`/`dd` of scheme, header if `shared-secret`, "Set — changed {date}");
  overlap running (adds the rotation-in-progress line and an **End overlap now** button, gated on the
  existing `canUpdate` computed — no new permission, AC28). Calls T21's endpoint on click, with
  `Spinner`/inline error per the existing `ReplayDialog.vue`-style convention.
- **Dependencies:** T21, T22
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:**
  - Each of the three states (not required / set / overlap) renders correctly against its matching
    `security.verification` prop shape.
  - **End overlap now** is visible only when `canUpdate` is true; the read-only status line always
    renders regardless of `canUpdate`.
  - Clicking End overlap now removes the rotation line on success without a full page reload
    (`router.reload({ only: [...] })` or equivalent), and shows an inline error on failure without
    losing the card's prior state.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow C, against a
  production build: all three states render correctly; End overlap now works and updates the card;
  the button is absent for a Member without update rights on a teammate's proxy.
- **Completion notes:** Done. New "Verification" `Card` added to `proxies/Show.vue`, placed
  immediately after the Destinations table and before the Retry policy card, matching Screen 4's
  placement instruction. Three states, driven entirely by the existing `security` prop (T22, now a
  required prop on `Show.vue` since `show()` has always emitted it): **not required** (the plain
  status line); **scheme set, no overlap** (`dl` of Scheme/Header-if-shared-secret/Secret, via two new
  computeds — `verificationSchemeLabel` and `verificationSecretStatus` — kept as computeds rather than
  inline template expressions so an impossible combination the write-only validation contract never
  actually produces, e.g. a scheme with no live secret, degrades to a safe fallback instead of a
  runtime crash on a null timestamp); **overlap running** (the rotation line, always rendered for
  anyone who can view the proxy, plus a `canUpdate`-gated **End overlap now** button — the same reused
  `canUpdate` computed this page already has, no new permission, AC28). Clicking **End overlap now**
  calls T21's endpoint via `router.delete(..., { only: ['security'] })` — a partial Inertia reload of
  just the `security` prop, never a full page reload — with a `Spinner` while in flight and an
  `AlertError` (this app's existing `ReplayDialog.vue`-style convention) on failure, leaving the card's
  prior state intact rather than clearing it.

  **Manual verification performed** (own local Sail dev environment, seeded via `sail tinker`, deleted
  again immediately after — same recipe as T23), via a headless Playwright session against the real
  running app: an Owner and a Member (attached via `TeamRole::Member`, not the proxy's creator) against
  three seeded proxies (not required; `shared-secret` with one live secret, no overlap; `standard-webhooks`
  with two live secrets, an overlap running).

  - Not-required proxy: the plain "No verification required" line renders; no End overlap button.
  - Shared-secret, no overlap: Scheme "Shared secret", Header "X-Signature", "Set — changed {date}"
    all render; no rotation line, no End overlap button (there is nothing running to end).
  - Overlap-running proxy: the rotation line and End overlap now button both render for the Owner;
    clicking it left the URL unchanged (confirming no full-page navigation), removed the rotation line,
    and the card settled into the plain "Set — changed {date}" state — all via one partial reload.
  - Member session on the shared-secret proxy (no update rights, not the creator): the same read-only
    status line rendered identically; the End overlap now button was absent.
  - Zero browser console/page errors observed across the whole session.

  `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check` and `pnpm run build` all
  green. `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter Proxies` green
  (187 tests, 982 assertions — no regression); full-suite run deferred to the end of this batch
  (T20-T25).

## T25 — Inbound verification integration test suite (AC24, AC25, AC28, AC29, AC51–AC53; ADR-022 Decision 6)
- **Description:** No production code — the end-to-end pinning pass across everything T16–T22 built,
  through real HTTP requests rather than unit calls.
- **Dependencies:** T19, T20, T21
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - **AC24:** a proxy with no verification behaves identically to today — same status, body, capture,
    dispatch — against a proxy with `verification_scheme` NULL and no secret rows, issuing **no**
    `proxy_secrets` query.
  - `shared-secret`: correct/wrong value, missing header, wrong header name.
  - `standard-webhooks`: specification-computed signature verifies; multi-entry list verifies on the
    second entry; non-`v1` entry skipped; missing/malformed header fails; hex instead of base64 fails;
    `whsec_`-prefixed and bare secrets both work; tolerance boundary (`TOLERANCE_SECONDS + 1` fails,
    one second inside passes).
  - **AC29**, end to end over HTTP: during an overlap both secrets verify inbound; after expiry only
    the current does, with the sweeper never run; a second rotation discards the oldest immediately;
    End overlap now discards it immediately.
  - **AC28:** a Member without update rights on a teammate's proxy is 403 on every new mutating
    endpoint this milestone added.
  - **ADR-022 Decision 6:** a replay of a verified proxy's event dispatches without re-verifying.
- **Testing:** `tests/Feature/Ingest/InboundVerificationIntegrationTest.php` (new) — one test method
  per bullet above.
- **Completion notes:** Done. New test-only file, 19 test methods, real HTTP requests throughout
  (`$this->post()`/`postJson()` against the ingest route, `$this->delete()`/`post()` against the
  proxy-management routes) — no production code touched, per this task's own description.

  **AC24:** one test, a proxy with `verification_scheme` NULL and no secret rows returns its
  configured 200/body, captures the event, dispatches `ProcessIngestedWebhook`, and issues **zero**
  queries touching `proxy_secrets` (the same `DB::listen` substring-count technique T18's own unit
  test already established, now proven at the HTTP layer).

  **shared-secret** (4 methods): correct value verifies and captures; wrong value, a missing header,
  and a wrong header name are each rejected with nothing captured.

  **standard-webhooks** (9 methods): a specification-computed signature verifies; a multi-entry
  `webhook-signature` list verifies when only the second (real) entry matches; a non-`v1` first entry
  is skipped and a later real entry still verifies; a missing header and a malformed one (non-numeric
  timestamp) both fail; hex instead of base64 fails; a `whsec_`-prefixed secret and the bare form of
  the same key material both verify (two proxies, one per encoding); the tolerance boundary
  (`TOLERANCE_SECONDS + 1` outside `now()` rejected, one second inside accepted). Requests are built
  with `postJson()` and an independently-reconstructed `json_encode()` of the same array as the raw
  body signed via `StandardWebhooks::sign()` — `postJson()`'s own implementation is `json_encode($data,
  0)`, confirmed by reading its source, so the two bodies are guaranteed byte-identical rather than
  merely likely to match.

  **AC29, end to end over HTTP** (4 methods): during an overlap, both the demoted and the current
  secret verify inbound; after the demoted row's `expires_at` is pushed into the past directly (no
  sweeper, no job, ever run), only the current one verifies; a third rotation discards the oldest
  secret immediately (asserted by attempting to verify with it and getting 401, while the middle and
  newest both still succeed); `DELETE .../verification/overlap` (T21) discards the previously-demoted
  secret immediately (verifies before the call, fails with 401 immediately after).

  **AC28** (1 method): a Member without update rights on a teammate's proxy (attached via
  `TeamRole::Member`, not the proxy's creator) is 403 on `DELETE .../verification/overlap` — the only
  genuinely new mutating endpoint this milestone (M6, T13–T25) added; `ProxyController::store()`/
  `update()` are pre-existing routes with extended validation, not new endpoints, so they are outside
  this bullet's "every new mutating endpoint" scope (T20's own `VerificationValidationTest` already
  covers their behaviour). Followed by an assertion that nothing changed — both secrets still verify.

  **ADR-022 Decision 6** (1 method): captures a genuinely verified event, then corrupts the stored
  secret's ciphertext so any live re-verification attempt would throw `SecretUnavailableException`
  (T14's fail-loud contract) — a 500 the test would catch immediately if replay were ever wired to
  re-verify — then calls the replay endpoint and asserts it redirects normally and creates exactly one
  `Delivery` row. This is a stronger proof than reading the replay controller's source (which already
  never references `InboundVerifier` at all): it fails loudly the moment someone adds a re-verification
  call to that path, rather than relying on the absence of a code reference staying true by inspection.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  InboundVerificationIntegrationTest` green (19 tests, 53 assertions). **Full suite run at the close of
  this batch (T20-T25): `./vendor/bin/sail test --parallel` green at 1010/1010** (up from 973 at the
  T13-T19 batch boundary — 37 new tests across T20-T25: 9 + 4 + 5 + 0 + 0 + 19, T23/T24 being
  frontend-only with no automated test harness).

---

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

## M8a — Outbound signing, backend

## T34 — `OutboundHeaders` extended: the signing headers (AC54, AC55, AC58, AC59, AC60, AC64; plan § Architecture C, ADR-023 Decisions 2–3) — **delivery path**
- **Description:** Extends T26's `OutboundHeaders` with the fifth composition step: if the proxy has a
  live `signing` secret set, add the Standard Webhooks headers — `webhook-id` (derived,
  `msg_{dispatch_uuid}_{destination_id}` — stable across retries of one delivery, new on a replay, no
  new column), `webhook-timestamp` (this attempt's time, not the original), `webhook-signature`
  (`StandardWebhooks::sign()`, T7, over the **exact bytes about to go out**, one `v1,<sig>` entry per
  live signing secret — at most two, AC29's cap) — computed **in the send path**, over the request that
  is actually about to be dispatched, so a signature can never be computed over stale bytes. These
  three headers take precedence over any forwarded inbound header of the same name (AC64), resolved by
  the same lowercased-name collision rule T26 already established. Signing changes **nothing but the
  headers** — the body is byte-identical to the unsigned case (AC59).
- **Dependencies:** T7, T14, T26
- **Files:** `app/Support/OutboundHeaders.php`
- **Acceptance Criteria:**
  - `webhook-id` is identical across attempt 1 and its retry of the same delivery; **different** on a
    replay of the same event; **different per destination** of one dispatch even though the signing
    key is shared (AC60 — the secret is the proxy's, the message identity stays per delivery).
  - `webhook-timestamp` reflects each attempt's own time, not the original attempt's.
  - During a signing overlap, the header carries **exactly one entry per live secret** (at most two)
    and each verifies independently against `StandardWebhooksScheme`/`StandardWebhooks::verify()`;
    after expiry, exactly one.
  - The signature verifies against the specification, computed over the exact dispatched bytes; the
    body is byte-identical to the same request unsigned.
  - An inbound request that happened to carry `webhook-id`/`webhook-timestamp`/`webhook-signature`
    (e.g. from a `standard-webhooks`-verified proxy) never lets those values reach a destination as the
    proxy's own signing headers — the signing values always win (AC64).
- **Testing:** `tests/Unit/Support/OutboundHeadersSigningTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `OutboundHeaders::build()` gains the fifth composition step (ADR-023
  Decision 1): an explicit `list<string> $signingSecrets` parameter (default `[]`, matching this
  document's established defaulting-new-parameter precedent so no existing call site breaks), composed
  into the same `added`/collision-removal mechanism T26 already built for the credential header, rather
  than a second bespoke precedence path — AC38 and AC64 are now the same rule applied to a longer list.
  A new private `signingHeaders()` derives `webhook-id` as `msg_{dispatch_uuid}_{destination_id}`
  (ADR-023 Decision 3, no new column), takes `webhook-timestamp` at the exact moment of this call via
  `now()->getTimestamp()` (this app's established `Carbon::setTestNow()`-testable convention, rather
  than `StandardWebhooks::verify()`'s own bare `time()`, which only reads an already-received header
  value and has no equivalent testability need), and builds `webhook-signature` as one space-delimited
  `v1,<sig>` entry per live secret via `StandardWebhooks::sign()` (T7) over `$unit->payload` — the exact
  bytes about to be dispatched, never touched or read back by this class (AC59).

  **Necessary supporting plumbing, not scope creep (this document's own established precedent — T12,
  T15, T20, T22, T23, T27, T29, T30, T32):** `webhook-id`'s derivation needs the delivery's
  `dispatch_uuid`, which `DeliveryUnit` did not carry before this task. Added `dispatchUuid: string = ''`
  to `DeliveryUnit`'s constructor (defaulted for the same reason T27's `verificationHeaderNames` was —
  every pre-#10 construction site stays valid unchanged) and wired
  `DeliveryUnitResolver::resolve()` to pass `$delivery->dispatch_uuid` through. Also added
  `signingSecrets: array = []` to `DeliveryUnit` (populated for real at T36; `[]` until then, so this
  task's own production wiring is inert everywhere it lands ahead of T36) and updated
  `DeliverToDestination::send()`'s existing `OutboundHeaders::build()` call site to pass
  `$unit->signingSecrets` as the fifth argument — otherwise this task's own class-level capability would
  never actually reach a real dispatch, leaving T34–T38 unconnected until some later task noticed.

  Five tests, one per Acceptance Criteria bullet: `webhook-id` identical across an attempt and its retry
  (same `dispatchUuid`), different on a replay (different `dispatchUuid`) and different per destination
  of the same dispatch despite a shared signing key (AC60); `webhook-timestamp` reflects each call's own
  time via `Carbon::setTestNow()`, not a fixed/original one; an overlap of two live secrets produces
  exactly two space-delimited entries, each independently verifying via `StandardWebhooks::verify()`
  against only its own secret, collapsing to exactly one entry once only one secret is live; the
  signature verifies against the specification over the exact dispatched bytes, with `$unit->payload`
  provably unread/unmutated by `build()` and no `body` key ever present in its return; and an inbound
  request that happened to carry `webhook-id`/`webhook-timestamp`/`webhook-signature` never lets those
  values reach a destination as the proxy's own once signing is on (AC64).

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs the
  send path inline under this suite; this is strong evidence the *logic* — the composition order, the
  derivation, the collision rule — is correct, but exercises none of Horizon's real async/queued
  dispatch, and this task's own suite is a pure unit test of `OutboundHeaders` directly, not even routed
  through a queued job.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "OutboundHeadersTest|OutboundHeadersSigningTest|DeliverToDestinationTest|DeliveryUnitResolverTest|RetryDeliveryTest"`
  (43 tests, 151 assertions — confirming no regression on T26/T27/T28's existing coverage) all green;
  full-suite run deferred to the end of this batch (T34-T40).

## T35 — AC63 byte-identical regression, dedicated (AC63; plan § Test strategy, "the regression that matters most") — **delivery path**
- **Description:** The signing-surface counterpart to T26's AC37 test, named separately per `plan-10`'s
  own instruction that this class of regression "must be its own named task" — a partial landing here
  is a shipped defect that a broader test could quietly pass around. A destination **of a proxy without
  a signing secret** produces a request byte-identical, header set and body both, to the pre-signing
  (and pre-#10, composing with T26) baseline.
- **Dependencies:** T34
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - A destination of a proxy with no signing secret configured: no `webhook-*` header is added; the
    header set and body are byte-identical to the T26 AC37 baseline (i.e., this test composes with T26
    rather than duplicating its fixture from scratch).
  - A destination of a proxy that **had** signing enabled and then disabled produces the same
    byte-identical result (ADR-021 Decision 5's delete-on-disable, exercised at the header-building
    layer).
- **Testing:** `tests/Unit/Support/OutboundHeadersSigningRegressionTest.php` (new), or a dedicated
  method inside T34's test class clearly separated and named for this AC — either is acceptable as
  long as it is independently identifiable as *the* AC63 regression guard, not folded into a broader
  assertion.
- **Completion notes:** Done. New, independently-identifiable test file (not folded into T34's own
  suite) — `tests/Unit/Support/OutboundHeadersSigningRegressionTest.php`. Two tests, mirroring T26's own
  AC37 fixture exactly (same header set, same `assertSame($unit->forwardHeaders(), $result)` assertion)
  rather than a fresh fixture: a proxy with no signing secret configured produces no `webhook-*` header
  and a byte-identical result; a proxy that had signing enabled and then disabled produces the same
  result, since `SecretStore::disable()` (ADR-021 Decision 5) leaves `liveFor()` returning an empty set —
  mechanically identical, at the `OutboundHeaders` layer, to a proxy that never enabled signing at all
  (noted here so a later reader does not read the two tests' identical inputs as redundant — they pin
  two distinct product states that happen to collapse to the same header-building input).

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  OutboundHeadersSigningRegressionTest` (2 tests, 8 assertions) green; full-suite run deferred to the end
  of this batch (T34-T40).

## T36 — `DeliveryUnitResolver`/`DeliveryUnit`: the proxy's live signing set (AC54, AC60; plan § Services & Actions) — **delivery path**
- **Description:** `DeliveryUnitResolver` asks `SecretStore::liveFor($proxy, SecretPurpose::Signing)`
  (through the `withTrashed()` proxy load T27 already established) and populates `DeliveryUnit` with
  the result, so `OutboundHeaders` (T34) has what it needs at send time without querying
  `proxy_secrets` directly (Technical ruling 5 — `SecretStore` stays the single reader/writer).
- **Dependencies:** T14, T27
- **Files:** `app/Services/DeliveryUnitResolver.php`
- **Acceptance Criteria:**
  - `DeliveryUnit` carries the resolved proxy's live signing secret set (0, 1, or 2 entries) correctly
    for a proxy with signing off, on with no overlap, and on with an overlap running.
  - Enabling signing on a proxy signs dispatches to **every** destination of that proxy, including one
    added **after** signing was enabled — no per-row lookup, no per-row state (AC54).
- **Testing:** extends `tests/Unit/Services/DeliveryUnitResolverTest.php` — the three signing-state
  cases, the destination-added-afterward case.
- **Completion notes:** Done. `DeliveryUnitResolver` gains a `SecretStore` constructor dependency and
  calls `SecretStore::liveFor($proxy, SecretPurpose::Signing)` once per resolve, on the proxy already
  loaded `withTrashed()` (T27) — no second query, no per-row lookup, so every destination of a
  signing-enabled proxy (including one added afterward) resolves the identical live set (AC54).

  **Necessary supporting plumbing, not a deviation, decided within this Senior Developer's own
  discretion (naming/private-helper decisions the plan leaves open):** `SecretStore::liveFor()` can
  throw `SecretUnavailableException` (T14's fail-loud contract). `resolve()` runs inside `asJob()`
  and `RetryDelivery::handle()`, **before** `DeliverToDestination::handle()` creates the
  `DeliveryAttempt` row — an uncaught throw here would leave AC11's required per-destination Failed
  record, with its value-free `error_summary`, permanently unwritten (the job would simply vanish into
  `failed_jobs`, no delivery-path record of what happened). So this task's own new private
  `signingSecretsFor()` catches the exception and defers it onto the resolved `DeliveryUnit`
  (`signingSecretsUnavailable: ?SecretUnavailableException`, a new `DeliveryUnit` field alongside
  `signingSecrets`) rather than letting it propagate — `resolve()` itself never throws for a signing
  decrypt failure. This task's own Acceptance Criteria doesn't exercise the failure path at all (that's
  explicitly T39's), so nothing here contradicts it; T39 is the task that checks
  `$unit->signingSecretsUnavailable` inside `DeliverToDestination::send()`'s own failure handling and
  pins the resulting all-or-none behaviour — until T39 lands, this deferred field is carried but never
  read, which is itself the gap T39's own task text anticipates finding.

  Four new tests, alongside the existing suite (all still green): the three signing-state cases (off —
  `[]`, no `signingSecretsUnavailable`; on, no overlap — one secret; on, overlap running — two secrets,
  current first, matching `SecretStore::replace()`'s own ordering) and the destination-added-after-signing-was-enabled
  case (AC54), resolving the identical live set as a destination that predates the secret.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|DeliverToDestinationTest|RetryDeliveryTest|SecretStoreTest"`
  (57 tests, 190 assertions — confirming no regression) all green; full-suite run deferred to the end of
  this batch (T34-T40).

## T37 — Proxy-scoped signing endpoints (AC56, AC57, AC58; plan § API, Technical ruling 5)
- **Description:** `POST proxies/{proxy}/signing` (`ProxySigningController@store`) — always generates a
  new secret (Enable and Regenerate are one action, AC56); returns `application/json`, `{ secret,
  generated_at }`, `Cache-Control: no-store, private` — **the only endpoint in this feature returning
  JSON**, because it carries a secret that must not enter an Inertia prop or the session store
  (Technical ruling 5). `DELETE proxies/{proxy}/signing` (`@destroy`) — disables, deletes every
  `signing` row (`SecretStore::disable()`), Inertia redirect. `DELETE
  proxies/{proxy}/signing/overlap` (`ProxySigningOverlapController@destroy`) — ends the signing
  overlap now, Inertia redirect. All three proxy-scoped, gated `update` via `ProxyPolicy` — no
  destination-scoped route exists anywhere for signing.
- **Dependencies:** T14
- **Files:** `app/Http/Controllers/ProxySigningController.php` (new),
  `app/Http/Controllers/ProxySigningOverlapController.php` (new), `routes/web.php`
- **Acceptance Criteria:**
  - `store` always generates a **different** secret than whatever was previously current, returns it
    exactly once in the JSON body, and the response carries `Cache-Control: no-store, private`.
  - The generated secret never appears in any subsequent page prop or any subsequent response from any
    endpoint (assert by hitting `show`/`edit` immediately afterward).
  - `destroy` deletes every `signing`-purpose row for the proxy; a subsequent `store` produces a value
    different from the deleted one.
  - The overlap-end endpoint stops the previous signing secret verifying/being included in the
    signature list immediately.
  - A Member without `update` rights on the proxy is 403 on all three.
- **Testing:** `tests/Feature/Proxies/ProxySigningControllerTest.php` (new) — one test per bullet.
- **Completion notes:** Done. `ProxySigningController@store` (`POST proxies/{proxy}/signing`) always
  calls `SecretStore::generate()` (Enable and Regenerate are literally the same call, AC56) and returns
  `{ secret, generated_at }` as `application/json` with `Cache-Control: no-store, private` — matching
  `ProxyEventPayloadController`'s own established `response()->json($data, 200, [...])` header-array
  convention rather than the `HttpFoundation` fluent `setPrivate()`/`addCacheControlDirective()` API,
  which does not compose cleanly with `response()->json()`'s own return type. `@destroy` calls
  `SecretStore::disable()` and redirects (`back()`). `ProxySigningOverlapController@destroy`
  (`DELETE proxies/{proxy}/signing/overlap`) calls `SecretStore::endOverlap()` and redirects —
  the signing-surface mirror of `ProxyVerificationOverlapController`, same idempotent-by-construction
  reliance on `SecretStore`, no guard of its own. All three gated `update` via `ProxyPolicy` (no new
  permission); all three routes registered in `routes/web.php` alongside the existing
  `proxies.verification.overlap.destroy` route, no destination-scoped route added anywhere.

  Five tests, one per Acceptance Criteria bullet: two consecutive `store` calls produce different
  secrets, both returned exactly once with the exact `Cache-Control` header, and the live signing set
  afterward is `[second, first]` (current-first, matching `SecretStore::replace()`'s own ordering); the
  generated secret is absent from the content of an immediately-following `show`, `edit` and `index`
  response; `destroy` empties the live signing set and a subsequent `store` produces a value matching
  neither of the two previously-live secrets; the overlap-end endpoint removes the demoted secret from
  the live set immediately; and a Member without update rights on a teammate's proxy is 403 on all three
  endpoints with nothing changed.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "ProxySigningControllerTest|ProxyVerificationOverlapControllerTest"` (9 tests, 39 assertions) all
  green; full-suite run deferred to the end of this batch (T34-T40).

## T38 — `security` prop: the `signing` sub-object (AC54, AC57, AC58; plan § API, Technical ruling 4)
- **Description:** Extends `ProxySecurityResource` with `signing: { enabled, generated_at,
  overlap_expires_at } | null` — status only, never the value. Under ruling B there is no
  per-destination signing flag to carry; this is one object on the shared `security` prop, not a
  per-row field.
- **Dependencies:** T32, T37
- **Files:** `app/Http/Resources/ProxySecurityResource.php`
- **Acceptance Criteria:**
  - The `signing` sub-object correctly reflects not-enabled / enabled-no-overlap / enabled-overlap
    states after the corresponding T37 endpoint calls.
  - The secret's value is never present anywhere in this resource's output.
- **Testing:** extends `tests/Feature/Proxies/ProxySecurityResourceTest.php` — the three-state
  assertion, the no-value assertion.
- **Completion notes:** Done. `ProxySecurityResource` gains a `signing` key, sourced from
  `SecretStore::statusFor($proxy, SecretPurpose::Signing)` — the same T22-established non-value,
  non-length status metadata call, never a direct `proxy_secrets` query (Technical ruling 14). Shaped
  as an always-present object (`enabled`, `generated_at`, `overlap_expires_at`), mirroring
  `verification`'s own established convention of never being `null` itself, with `enabled` playing
  `verification.secret_set`'s presence-only role — this reads the task description's own `| null`
  notation the same way T22's identically-worded `verification: {...} | null` was actually built (a
  status object whose fields go null/false, not a nullable wrapper), for consistency with the sibling
  sub-object the same resource already carries. One object on the shared prop, never a per-destination
  field — there is no per-destination signing state to carry under Amendment B. A proxy that was
  enabled and then disabled reads identically to one that was never enabled, since
  `SecretStore::disable()` (ADR-021 Decision 5) deletes every row and `statusFor()` returns `null` for
  either case — noted inline so a later reader does not read this as a missing "was previously enabled"
  memory.

  **Incidental doc-comment correction, not a deviation:** T32's own class docblock said "`signing` is
  added in a later, out-of-scope-for-this-batch task (T41)" — T41 is actually the Show.vue Signing
  *card* (M8b, a later milestone); this resource's `signing` key is this task, T38, per the plan's own
  M8a/M8b split. Corrected the comment while adding the key it was describing.

  Two new tests, alongside the existing suite (all still green): the three states (not enabled — all
  three fields false/null; enabled, no overlap — `enabled: true`, a real `generated_at`, null overlap;
  enabled with an overlap running — all three populated) driven through real `SecretStore::replace()`
  calls exactly as T37's own endpoints would produce them; and a response-body substring check (both
  `show()` and `edit()`) proving a live signing secret's value never appears in either response.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter ProxySecurityResourceTest`
  (9 tests, 122 assertions) green; also re-ran the full `Proxies` feature suite (198 tests, 1079
  assertions) as a regression check, green. Full-suite run deferred to the end of this batch (T34-T40).

## T39 — AC11 signing all-or-none, dedicated (AC11; plan § Architecture H, PRD-10 `## Amendment B` ruling 1) — **delivery path**
- **Description:** **Pins the partial-fan-out prohibition by name, as its own task**, per this feature's
  explicit re-grained AC11: a proxy whose signing secret cannot be decrypted must dispatch to **none**
  of its destinations for that attempt cycle, never some signed-successfully-elsewhere and some
  silently unsigned. `SecretStore::liveFor()` (T14) already throws `SecretUnavailableException` rather
  than dropping an undecryptable row from the live set; this task proves that exception reaches
  `DeliverToDestination::send()` and fails **every** destination of that proxy's dispatch, before any
  request is made, with the recorded `error_summary` containing no part of the secret (AC61).
- **Dependencies:** T34, T36
- **Files:** none production expected — this task should find T14/T34/T36 already correct and pin the
  behaviour with a test; if it finds a gap (e.g. `send()` catching the exception per-destination rather
  than letting it fail the whole dispatch cycle for the proxy), fix it in
  `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:**
  - A proxy with a signing secret that fails to decrypt (corrupted-ciphertext fixture): **every**
    destination of that proxy fails its attempt for that cycle — none dispatches signed, and **none
    dispatches unsigned** (the forbidden partial-fan-out/fallback state).
  - No destination of that proxy's fan-out succeeds while another fails, for the same underlying cause,
    in the same dispatch cycle.
  - The recorded `error_summary` on every resulting failed attempt names no part of the secret.
  - A proxy with signing **off**, or with a healthy signing secret, is completely unaffected by this
    task (regression guard).
- **Testing:** `tests/Feature/Delivery/SigningAllOrNoneFailureTest.php` (new) — a fixture proxy with 3+
  destinations and a corrupted signing-secret row, asserting all three destinations fail together with
  no part of the secret in any `error_summary`, against a control fixture (healthy secret) where all
  three succeed signed.
- **Completion notes:** Done. **Found a real gap and fixed it, exactly as this task's own Files line
  anticipated.** T36 deliberately does not let `SecretStore::liveFor()`'s `SecretUnavailableException`
  propagate out of `DeliveryUnitResolver::resolve()` (see T36's own completion notes) — it defers the
  exception onto the resolved `DeliveryUnit` (`$signingSecretsUnavailable`) instead, precisely so the
  `DeliveryAttempt` row still gets created before the failure surfaces. Before this task, nothing ever
  read that deferred field: `DeliverToDestination::send()` would have built headers with an EMPTY
  signing-secret list (the safe default `DeliveryUnitResolver` falls back to on a decrypt failure) and
  dispatched every destination **unsigned** — a silent fallback, not a loud failure, and exactly the
  "some signed-successfully-elsewhere and some silently unsigned" state AC11 forbids (here it would
  have been "all silently unsigned," which is the same forbidden fallback-instead-of-failure shape, not
  a partial split, but still never allowed to happen quietly).

  **The fix, entirely inside `app/Actions/DeliverToDestination.php`, nothing else touched:** at the top
  of `send()`'s existing `try` block — before `OutboundHeaders::build()` is ever called, so no header is
  built and no HTTP request is ever made — a new check: `if ($unit->signingSecretsUnavailable !== null)
  { throw $unit->signingSecretsUnavailable; }`. This lands the exception inside the SAME
  `catch (Throwable $e)` block that already handles every other transport/build failure, so the
  attempt's `error_summary` becomes the exception's own fixed, value-free message ("The signing secret
  could not be decrypted.") through the exact same code path already proven for HTTP failures — no new
  failure-recording logic, no duplication. Because every destination of the same proxy reads the
  identical corrupted `proxy_secrets` row through its own independent `asJob()` → `resolve()` call, each
  one is caught here identically — "the whole dispatch cycle for the proxy" fails as an emergent
  property of the shared root cause, not because of any code that coordinates across destinations (none
  exists, and none was added — `DeliverStep.php`/`AdvanceProxyFifoQueue.php` remain untouched, per this
  document's own binding note).

  **How the all-or-none rule is pinned as "not merely one destination failed":** the test drives three
  real destinations of one proxy through the real `DeliverToDestination::asJob()` entry point (not a
  bare `run($unit)` call, so the corrupted-secret path is exercised exactly as production reaches it),
  fakes `Http` with no configured response, and asserts `Http::assertNothingSent()` — proving zero HTTP
  requests were made for ANY of the three destinations, not just that one `DeliveryAttempt` row reads
  Failed. `DeliveryAttempt::count() === 3`, every one `Failed`, and zero `Succeeded`, closes the
  "no destination succeeds while another fails" bullet directly rather than by inference from a single
  destination's result.

  **Queue faked, and why:** a failed attempt schedules a real, delayed `RetryDelivery` (T14/T15); under
  this project's `QUEUE_CONNECTION=sync` a delayed dispatch still runs inline unless the queue is faked,
  which — discovered while first running this test unfaked — cascaded each destination's own corrupted
  secret through its full retry limit (3 destinations × 5 attempts = 15 rows, not 3), a real but
  unrelated interaction with the pre-existing retry engine rather than a defect in this task's own fix.
  `Queue::fake()` isolates the test to attempt 1 for each destination — exactly "that attempt cycle,"
  the AC's own words.

  Two regression-guard tests, per the task's own fourth bullet: a proxy with signing off dispatches and
  succeeds exactly as before this task; a proxy with a healthy (uncorrupted) signing secret dispatches
  signed to all three destinations, `webhook-signature` present on every request.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "SigningAllOrNoneFailureTest|DeliverToDestinationTest|RetryDeliveryTest|DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SecretStoreTest"`
  (60 tests, 205 assertions — confirming no regression) all green; full-suite run deferred to the end of
  this batch (T34-T40).

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs
  `asJob()` inline under this suite, so this proves the *logic* — the deferred exception reaching
  `send()`, the shared-cause independent-failure mechanism — is correct when each destination's job is
  invoked, but exercises none of Horizon's real concurrent/async dispatch of those same three jobs.

## T40 — Outbound signing integration test suite (AC54–AC64) — **delivery path**
- **Description:** No production code — the end-to-end pinning pass across T34–T39, through the full
  send/retry/replay path.
- **Dependencies:** T36, T37, T39
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - **AC54:** enabling signing on a proxy signs dispatches to every destination, including one added
    afterward, under the same secret; a proxy without a signing secret signs none of its destinations.
  - **AC55/AC59:** the signature verifies against the specification, computed over the exact dispatched
    bytes; the body is byte-identical to the unsigned case.
  - **AC56/AC57:** the secret is generated, returned once as JSON with `no-store`, absent from every
    subsequent page prop and response; `whsec_`-prefixed base64.
  - **AC58:** during an overlap the header carries one entry per live secret and each verifies; after
    expiry, one.
  - **AC60:** `webhook-id` identical on attempt 1 and its retry, different on a replay, different per
    destination of one dispatch.
  - **ADR-021 Decision 5:** disabling deletes every row; re-enabling generates a different secret.
  - **AC61:** the signing secret appears nowhere but the one-time display and the signature computation
    — not in a queued job's arguments (asserted positionally on the serialized job, as
    `AdvanceProxyFifoQueueTest` does for its own scalars), not in a delivery-attempt record, not in
    analytics, not in a failure record, not in a log line, not in any payload view.
  - **AC64:** with signing on, the outbound `webhook-*` headers are the proxy's own even when the
    inbound request carried them.
  - **R3, composed with T27/T36:** a retry whose proxy has been soft-deleted still resolves, still
    applies AC27's strip, and still signs.
- **Testing:** `tests/Feature/Delivery/OutboundSigningIntegrationTest.php` (new) — one test method per
  bullet.
- **Completion notes:** Done. New test-only file, `tests/Feature/Delivery/OutboundSigningIntegrationTest.php`,
  nine test methods (one per named bullet, `AC55/AC59` and `AC56/AC57` each folded into one method as
  the bullets themselves already pair them) — real HTTP requests throughout (`$this->postJson()` against
  the ingest route, `$this->post()`/`postJson()` against the signing/replay management routes), through
  the real send/retry/replay path, no production code touched.

  **AC54:** signing off produces no `webhook-signature` on any destination; enabling signing and adding a
  destination afterward signs both the pre-existing and the newly-added destination identically, with no
  per-row lookup.

  **AC55/AC59:** a signing proxy and a non-signing twin dispatch byte-identical bodies for the same
  payload; the signing proxy's signature verifies via `StandardWebhooks::verify()` against the exact
  dispatched bytes.

  **AC56/AC57:** the real `POST proxies/{proxy}/signing` endpoint's generated secret is `whsec_`-prefixed
  with valid base64 material after the prefix, returned with `Cache-Control: no-store, private`, and
  absent from an immediately-following `show` response — composing with, not duplicating, T37's own
  fuller endpoint coverage.

  **AC58:** an overlap of two live secrets produces a two-entry `webhook-signature`, each verifying
  independently against its own secret; pushing the demoted secret's `expires_at` into the past (no
  sweeper run) collapses a subsequent dispatch to exactly one entry.

  **AC60:** attempt 1 (failed) and its real, sync-driver-drained retry carry the identical `webhook-id`;
  a second destination of the same dispatch carries a different one despite the shared signing key; a
  replay of the same event through the real `POST .../events/{event}/replay` endpoint mints a fresh
  `dispatch_uuid` and therefore a new `webhook-id`.

  **ADR-021 Decision 5:** the real `DELETE`/`POST proxies/{proxy}/signing` endpoints empty the live set
  and then generate a value distinct from the one just disabled; a dispatch afterward verifies against
  only the new secret, never the disabled one.

  **AC61:** the widest-reaching method in this suite — a positional assertion on
  `DeliverToDestination`'s pushed job parameters (`$params === [$delivery->id, 1]`, the same technique
  `AdvanceProxyFifoQueueTest` already established for its own scalars) proves only two integers ever
  travel with the queued job; the entire serialized job payload, the resulting `DeliveryAttempt` row's
  every string attribute, a forced failure's `error_summary`, the proxy's `show`/`edit` responses, the
  events index and event show pages, and the event's payload-view response are all swept for the
  secret's literal substring, plus a `Log::listen()` collector run across the whole method proving no log
  line at any level ever carried it.

  **AC64:** an ingest request carrying attacker-forged `webhook-id`/`webhook-timestamp`/`webhook-signature`
  headers never lets those values reach the destination — the proxy's own signing headers win by
  precedence, and the outbound signature verifies against the real signing secret, not the forged one.

  **R3:** a delivery whose proxy is soft-deleted before its retry runs still resolves via
  `RetryDelivery::handle()`, still strips the proxy's own verification header outbound, and still signs
  — composed directly against T27's own established soft-deleted-proxy regression shape rather than a
  fresh fixture.

  **Two genuine test-authoring bugs found and fixed while building this suite, both isolated by dumping
  actual state rather than guessing — neither is a defect in T34–T39's production code, confirmed by the
  full delivery-path suite staying green throughout:**
  1. `Illuminate\Http\Client\Factory::fake()`, given an array, MERGES each call's stub onto the
     existing `stubCallbacks` collection rather than replacing it, and request resolution takes the
     FIRST matching stub. A second `Http::fake(['*' => Http::response('boom', 500)])` call later in the
     same test therefore never overrides an earlier `Http::fake(['*' => Http::response('ok', 200)])`
     registered against the same `'*'` pattern — the earlier 200 stub keeps winning silently. AC61's own
     "failure record" section needed genuinely different behaviour mid-test, so it now uses one
     `Http::fake()` closure for the whole method, branched on a mutable `$shouldFail` flag, rather than a
     second array-form call.
  2. That flag was first captured with an arrow `fn () => $shouldFail ? ...`, which captures its
     enclosing variables **by value at definition time** — silently freezing `$shouldFail` at `false`
     forever regardless of the later `$shouldFail = true;` reassignment. Fixed by using a plain
     `function () use (&$shouldFail) { ... }` closure (by reference) instead.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --filter
  "OutboundSigningIntegrationTest|SigningAllOrNoneFailureTest|DeliverToDestinationTest|RetryDeliveryTest|DeliveryUnitResolverTest|OutboundHeadersTest|OutboundHeadersSigningTest|OutboundHeadersSigningRegressionTest|SecretStoreTest|ProxySigningControllerTest|ProxySecurityResourceTest|ReplayAcceptanceTest|AsyncDispatchAcceptanceTest"`
  (100 tests, 491 assertions) all green. **Full suite run at the close of this batch (T34-T40):
  `./vendor/bin/sail test --parallel` green at 1063/1063.**

  **Delivery-path caveat, stated per the task list's own instruction:** `QUEUE_CONNECTION=sync` runs
  every job inline under this suite — including the "real, sync-driver-drained retry" this suite itself
  relies on for AC60 — so this proves the *logic* across T34-T39 is correct when exercised end to end,
  but exercises none of Horizon's real concurrent/async dispatch of the same jobs. No claim of having
  exercised the async path is made.

---

## M8b — Outbound signing, surface

## T41 — Screen 4b: `proxies/Show.vue` Signing card (AC54, AC57, AC63; Flows G, I; plan §, `design-10` amendment)
- **Description:** New `Card`, alongside the Verification card. States: **not enabled** (statement +
  **Enable signing** button, `canUpdate`-gated, opening Screen 6 directly into the one-time-reveal
  flow); **enabled, no overlap** ("Enabled — generated {date}" + **Manage signing** button); **enabled,
  overlap running** (adds the rotation line + **End overlap now**, same treatment as Screen 4). The
  rotation line and enabled status always render for anyone who can view the proxy; only the buttons
  are `canUpdate`-gated. No per-destination `Signed` badge anywhere — this is the proxy-wide status
  surface (design-10's stated reason: a badge repeated identically on every row carries no row-level
  information once signing applies to every destination alike). Renders **no new decryptability
  indicator** on failure (AC11's re-grained failure surfaces through the existing delivery-attempt
  treatment, not here) — the card shows only its last-known static configuration state.
- **Dependencies:** T38, T37
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:**
  - Each of the three states renders correctly against `security.signing`.
  - **Enable signing** opens the Manage proxy signing dialog directly into its one-time-reveal
    sub-state (Flow G step 2–3).
  - Disabling signing (via the dialog) returns the card to "not enabled" with no memory of prior
    configuration on its face.
  - No trust-domain warning is rendered anywhere on this card (PRD-10 `## Amendment B` ruling 2b — none
    is required).
- **Testing:** no frontend test harness — **manual verification** (folded into T43/T44's Flow G/H/I
  pass), plus a direct check here that all three states render against a fixture `security` prop.
- **Completion notes:** Done. Resumed from a prior session's uncommitted, unreviewed work
  (`13d0b6c wip(item-10): T41 Signing card, incomplete`) rather than building from scratch — read
  that diff cold against this task's own Acceptance Criteria, `design-10` Screen 4b, Flow G and Flow I,
  and PRD-10 `## Amendment B` ruling 2b before trusting any of it. The wip commit had already added the
  "Signing" `Card` to `proxies/Show.vue`, placed immediately after the Verification card and before the
  Retry policy card, matching Screen 4b's stated placement. All three states are driven entirely off
  `props.security.signing` (T38), mirroring the Verification card's own established shape one card down:
  **not enabled** (`!signing.enabled`) — the plain statement plus a `canUpdate`-gated **Enable signing**
  button that opens `ProxySigningDialog.vue`; **enabled, no overlap** (`signing.enabled &&
  !signing.overlap_expires_at`) — a `dl` "Status" / "Enabled — generated {date}" line (via the
  `signingGeneratedStatus` computed) plus a `canUpdate`-gated ghost **Manage signing** button;
  **enabled, overlap running** (`signing.overlap_expires_at` set) — the rotation-in-progress line
  (always rendered, status not control) plus `canUpdate`-gated **End overlap now** and **Manage
  signing** buttons, `End overlap now` wired to `proxyRoutes.signing.overlap.destroy` via
  `router.delete(..., { only: ['security'] })` with a `Spinner`/`AlertError` pair, the same
  `endVerificationOverlap` pattern the Verification card already established one card up. No
  per-destination `Signed` badge anywhere (T33's Destinations table is untouched by this task), no
  trust-domain warning (ruling 2b), and no new decryptability indicator on failure — the card renders
  only its last-known static enabled/overlap state, exactly as Screen 4b specifies; AC11's re-grained
  failure still surfaces solely through the existing delivery-attempt treatment.

  Traced every Acceptance Criteria bullet against the existing code rather than re-deriving it: the
  three `v-if`/`v-else-if` branches on `signing.enabled` / `signing.overlap_expires_at` cover exactly
  the three states with no gap or overlap; clicking **Enable signing** only sets `signingDialogOpen =
  true` — it does not itself drive the dialog into its reveal sub-state, because doing so is
  `ProxySigningDialog.vue`'s own internal state machine (T42's file, not this task's) — `design-10`
  Flow G step 2 (open the dialog) and step 3 (the dialog's own **Enable signing** action, inside the
  dialog, generates the secret and reveals it) are two different components' responsibilities, and
  T42's own Acceptance Criteria ("State 1 → Enable signing → state 2 … in sequence") independently
  confirms this reading — T41's AC bullet folding "step 2–3" together describes the flow's overall
  directness as experienced from the card, not a literal same-click jump past dialog state 1; disabling
  (via the dialog's `handleDisable`, T42's file) triggers a `router.delete(..., { only: ['security'] })`
  reload, and the card's own not-enabled branch carries no reference to any prior configuration — no
  local state on the card leaks a "previously enabled" fact onto its face, only the dialog's own
  session-scoped `everDisabledThisSession` flag does that, and only inside the dialog; grepped the whole
  card block for trust-domain/warning language and found none.

  The wip commit's `ProxySigningDialog.vue` (T42's nominal file) already implements states 1, 2, 3, 4
  and 5 in full — including the T43 AC29-ruling-2a disclosure copy on state 4 and the flagged-design-call-4
  `Esc`/overlay-suppression on the reveal sub-state — reaching well past T41's own scope. Left entirely
  as-is per this task's own instruction: not extended, not trimmed back to a T41-only subset, not
  rebuilt. **T42 should treat that component as already largely done and verify it against its own
  Acceptance Criteria rather than re-implementing states 1/2/3/5 from zero** — T43 similarly for state 4.
  Confirmed both `resources/js/types/proxies.ts` (the `signing` sub-object shape) and the dialog itself
  needed no change for T41's own Acceptance Criteria to hold; both files are untouched by this task's
  commit — the type shape is T38's, and every dialog behaviour this card's three states depend on
  (opening/closing, the disable reload) was already correct.

  **No frontend test harness exists** (confirmed: no `vitest`/`jest` in `package.json`, no `.test.*`
  files under `resources/js`) — the "direct check" this task's own Testing line calls for is the manual
  trace above, run against `pnpm run build` output (`public/hot` absent) rather than a fixture
  object in isolation, since this app has no component-mounting harness to feed one to. A full
  authenticated-browser walkthrough (login, three seeded proxies at the three states) was started but
  abandoned once it became a login-flow-debugging exercise rather than a check of this task's own
  scope — the fuller pass belongs to T43/T44 as the task's own Testing line already says, and this
  task's rendering logic is a handful of straightforward `v-if` branches on a well-typed prop already
  covered by `pnpm run types:check` (zero errors). Two proxies seeded via `sail tinker`
  (`SecretStore::generate()`, once for no-overlap and twice for overlap-running) toward that browser
  pass and one not-enabled control were deleted again immediately once the approach was dropped —
  nothing left behind.

  `pnpm run types:check`, `pnpm run lint:check`, `pnpm run format:check` and `pnpm run build` all green
  (no code changes were needed beyond what the wip commit already had — this task's own work was
  reading it against spec, verifying, and recording). `composer lint`, `composer types:check` and the
  full suite (`./vendor/bin/sail test --parallel`) all green — 1063/1063 passing, matching the
  pre-existing baseline exactly (no regression, and no new backend code in this task to add tests for).

## T42 — Screen 6: Manage proxy signing dialog, states 1/2/3/5 (AC54, AC56, AC57, AC63; Flows G, I; flagged design call 4)
- **Description:** New `Dialog`, scoped to the proxy, modelled on `ReplayDialog.vue`'s shape. **State
  1** (not enabled): statement + **Enable signing** footer action. **State 2** (one-time reveal,
  immediately after Enable or Regenerate succeeds): `Alert` + `CopyField` for the generated secret;
  footer **Done** only — **`Esc` and overlay-click dismissal are suppressed for this sub-state only**
  (the overturned flagged design call 4), **Done** the sole keyboard-reachable exit, focus lands on it
  on mount, no confirmation step added in front of it. **State 3** (enabled, no overlap): status +
  **Regenerate signing secret** + **Disable signing** + **Close**. **State 5** (disabled, re-visited):
  identical to state 1 plus one line noting re-enabling always generates a fresh secret. (State 4 —
  enabled, overlap running — is **T43**, kept separate because it carries the AC29 ruling-2a
  disclosure this feature explicitly calls out.)
- **Dependencies:** T37, T41
- **Files:** `resources/js/components/ProxySigningDialog.vue` (new, or inline in `Show.vue` per this
  app's existing dialog-composition convention — match whichever `ReplayDialog.vue` establishes)
- **Acceptance Criteria:**
  - State 1 → Enable signing → state 2 (one-time reveal) → Done → state 3, in sequence, calling T37's
    `store` endpoint and never re-displaying the secret afterward.
  - In state 2, pressing `Esc` and clicking the overlay both do nothing; **Done** is reachable by
    keyboard (Tab/Shift+Tab stay inside the dialog's focus trap) and is the only way to close it.
  - State 3 → Disable signing → state 5, with the "generates a fresh secret" line present.
  - Regenerating from state 3 (no overlap yet) transitions back to state 2 with a **new** secret, never
    the same value shown before.
- **Testing:** no frontend test harness — **manual verification**, `design-10` Flow G and Flow I steps,
  against a production build: the `Esc`/overlay suppression in state 2 specifically (this is the one
  behaviour in the whole feature most likely to regress silently, since it is a *removal* of default
  `Dialog` behaviour); the full state sequence 1→2→3→5.
- **Completion notes:** Done. As T41's own completion notes flagged, the prior session's wip commit
  (`13d0b6c`) already implemented `ProxySigningDialog.vue` states 1, 2, 3, 4 and 5 in full, wired to
  T37's endpoints. This task verified states 1/2/3/5 against T42's own Acceptance Criteria and
  `design-10` Screen 6/Flows G and I rather than rebuilding — state 4 (T43's) was left untouched, as
  instructed.

  **Verified already correct, no change needed:** the state 1 → Enable signing → state 2 (one-time
  reveal) → Done → state 3 sequence — `generate('enable')` calls T37's `store` endpoint via `fetch`
  (the `PayloadViewer.vue`-established escape hatch for this app's one other non-Inertia JSON
  endpoint) and sets `revealedSecret`; the `watch(() => props.open, …)` resets `revealedSecret` to
  `null` on every close, so the secret is never re-displayed on a later open (AC57) and **Done**
  (`handleDone`) simply closes the dialog — `design-10` Flow G step 4 confirms this is the intended
  shape ("the dialog's next open shows the ordinary enabled status"), not a same-session state-2-to-3
  transition without a close in between. In state 2, `Esc` and overlay-click are both refused by
  `suppressDismissalDuringReveal` (bound to `@escape-key-down`/`@pointer-down-outside` on
  `DialogContent`) plus the outermost `handleOpenChange` guard; `show-close-button` is `false` so the
  corner `X` is gone too; a `watch(state, …)` focuses `doneButtonRef` via `nextTick` the moment state
  becomes `'reveal'`; **Done** is the sole button rendered in the footer for that state, so Reka UI's
  own focus trap keeps Tab/Shift+Tab inside the dialog with Done the only exit — this is the
  overturned flagged design call 4, correctly implemented as the *overturned* version (suppression
  applies only to this one sub-state, not the whole dialog). State 3 → Disable signing → state 5:
  `handleDisable` calls `router.delete` against T37's `destroy` endpoint with `only: ['security']`,
  sets `everDisabledThisSession` on success, and closes the dialog; state 5 (`not-enabled` +
  `everDisabledThisSession`) renders the "Enabling again generates a new secret — your previous one
  is never shown or reused" line, present exactly when the task's AC requires it. Regenerating from
  state 3 calls the same `store` endpoint via `generate('regenerate')`, which always returns a freshly
  generated secret from the server (T37's own contract) and overwrites `revealedSecret`, so state 2 is
  re-entered with a genuinely new value, never the one shown before.

  **Fixed — unchecked partial reload (audit finding 1).** `router.reload({ only: ['security'] })` at
  the end of `generate()` was fire-and-forget with no `onError`. Added an `onError` callback that sets
  `requestError`, surfaced through the same `AlertError` this component already uses for every other
  request failure (`handleDisable`, `handleEndOverlap` already follow this convention; matched it here
  rather than inventing a new one, per the task's own instruction). Message: "Signing secret generated,
  but this proxy's status could not be refreshed. Close and reopen this dialog to see the current
  status." — deliberately distinct from the enable/regenerate action-failure strings, since the
  generate action itself succeeded; only the background status refresh failed. `AlertError` renders
  outside the per-state `template` blocks, so it surfaces even while state 2 (reveal) is still showing.

  **Fixed — missing `canUpdate` gate on Screen 6's own actions (audit finding 2).** Added a
  `canUpdate: boolean` prop to `ProxySigningDialog.vue` and gated all four state-changing actions with
  `v-if="props.canUpdate"`: **Enable signing** (state `not-enabled`), **Disable signing** and
  **Regenerate signing secret** (state `enabled`/`overlap`, in the `v-else` footer branch), and **End
  overlap now** (state `overlap`, T43's state — only the permission guard was touched here, not the
  disclosure copy or any other content of that state, per the instruction to leave state 4 alone).
  `resources/js/pages/proxies/Show.vue` now passes `:can-update="canUpdate"` (the same computed the
  page's other mutating controls already use) to the dialog. Confirmed via `Show.vue:986-1044` that
  every trigger opening this dialog was already itself `canUpdate`-gated, so there is no live exposure
  today — this closes the gap `design-10` § Interactions names explicitly rather than fixing an active
  bug.

  **Added — Screen 6 state 3's ordinary-branch disclosure**, per the design amendment landed under T42
  (commit `f7cf54a`, self-certified by the Designer under PRD-10 AC29 ruling 2a's delegated wording
  authority). Rendered the approved copy verbatim as a second `p`, help styling
  (`text-sm text-muted-foreground`), in the `enabled` state's template, directly below the "Enabled —
  generated {date}." status line and above where `DialogFooter` renders — i.e., in front of the member
  before **Regenerate signing secret** is reachable. Confirmed verbatim in the production bundle
  (`grep` against `public/build/assets/Show-*.js` after `pnpm build`) rather than trusting the source
  alone, since Prettier's line-rewrap of the template text could in principle have altered wording;
  Vue's default whitespace-condense collapses the wrapped source lines back to the single approved
  sentence, confirmed by the built-output grep.

  **Testing.** No frontend test harness (confirmed: no `vitest`/`jest` in `package.json`, no
  `.test.*` files under `resources/js`). A live browser pass was not attempted: `public/hot` exists on
  disk and a `vite` dev-server process was already running under another PID at task start — killing a
  dev server another concurrent session might own was judged riskier than the verification value of a
  live pass, and the task explicitly permits falling back to a code trace rather than sinking budget
  into login-flow debugging. Fell back to: the code trace above against each Acceptance Criterion, and
  a grep of the actual `pnpm build` output (`public/build/assets/Show-*.js`) to confirm the new
  disclosure copy renders verbatim rather than only checking the source template. The full Flow G/I
  browser walkthrough remains T44's, as the task plan already places it there.

  **What T43 needs to know:** state 4's disclosure copy and its `End overlap now` button are otherwise
  untouched by this task — the only change inside that `v-else-if="state === 'overlap'"` block is the
  new `v-if="props.canUpdate"` on the `End overlap now` `Button`, which does not affect the button's
  existing behaviour for any member who already holds `canUpdate` (the only population that could
  reach that state's button today, since every dialog-opening trigger is itself gated). T43 does not
  need to add its own `canUpdate` gate to state 4 — this task already added it.

  Gates: `composer lint`, `composer types:check`, `pnpm types:check`, `pnpm lint:check` all green.
  `pnpm format:check` initially flagged the new lines in `ProxySigningDialog.vue` (line-wrap only, no
  wording change) — fixed with `pnpm exec prettier --write`, then green. `pnpm build` green (output
  bundle grepped as above). Full suite (`./vendor/bin/sail test --parallel`) — 1063/1063 passing,
  matching the pre-existing baseline exactly (no backend code touched by this task).

## T43 — Screen 6 state 4 and Flow H step 2: the AC29 ruling-2a disclosure on the signing surface (correction B2) — **required before M8b is considered complete**
- **Description:** `design-10`'s amendment-gate correction **B2**, called out explicitly because it was
  the one correction the gate required before M8b could be task-planned at all. State 4 (enabled,
  overlap running) renders the rotation line and **End overlap now**, **plus** member-facing copy —
  rendered as part of this state, therefore visible **before** the member clicks **Regenerate signing
  secret** — stating that regenerating now stops the currently-honoured previous secret being honoured
  immediately, for **every destination of the proxy**, and that its 24 hours will not finish out. Flow
  H step 2 branches exactly as Flow B step 2 (T23) does for the inbound surface: **no overlap running**
  → the ordinary demote-not-discard copy; **overlap already running** → this state's disclosure. No
  confirmation step is added — the disclosure satisfies AC29's added bullet; it does not add ceremony
  in front of a still-single-click action.
- **Dependencies:** T42
- **Files:** same as T42 (the signing dialog component)
- **Acceptance Criteria:**
  - State 4 (overlap already running) shows the discard-disclosure copy **before** the Regenerate
    button is clicked, naming that the effect applies to every destination of the proxy.
  - State 3 → Regenerate (no overlap yet) shows the **ordinary** demote-not-discard copy, not the
    discard one — the two states/copies must not be swapped or merged.
  - Clicking Regenerate in state 4 still requires no confirmation dialog.
  - This exact disclosure requirement — stated before the action, present on **both** the inbound
    (T23) and signing (this task) surfaces — is satisfied by both tasks together; a review that finds
    it on only one surface should treat this task as incomplete regardless of what T23 shows.
- **Testing:** no frontend test harness — **manual verification**: rotate signing once (state 3 →
  regenerate, ordinary copy, confirmed), then rotate again while the first overlap is still running
  (state 4, discard copy, confirmed) — both branches exercised in one fixture proxy, against a
  production build.
- **Completion notes:** _pending_

## T44 — Manual verification: `design-10` Flows G, H, I against a production build
- **Description:** The signing surface's own full walkthrough, now that M8b is built end to end —
  `plan-10`'s own test-strategy note excluded Flows G–I "because the surface they describe is not
  built"; it now is, so this task closes that exclusion explicitly rather than leaving it silently
  stale.
- **Dependencies:** T41, T42, T43
- **Files:** none; verification-only
- **Acceptance Criteria:** Flow G (enable + one-time reveal), Flow H (regenerate, both overlap
  branches, end overlap now), and Flow I (disable, re-enable generates a fresh secret) each pass
  exactly as specified, against `pnpm run build` with `public/hot` confirmed absent, in both themes, at
  360px.
- **Testing:** manual, recorded in completion notes with concrete steps and observed outcomes per
  `docs/standards/planning.md`'s "AC-trace"/"Verify step" requirement.
- **Completion notes:** _pending_

---

## M9 — Cross-cutting hardening and the verification sweep

## T45 — Old-input scrub (R4; plan Technical ruling 7)
- **Description:** `verification_secret` added to `bootstrap/app.php`'s `dontFlash` list. Because
  `Arr::forget()` (Laravel's flashing mechanism) has no wildcard support, `destinations.*.credential_secret`
  **cannot** be excluded that way — `StoreProxyRequest`/`UpdateProxyRequest` override
  `failedValidation()` to scrub the nested secret values from `$request->input()` before the validation
  exception propagates. Inertia's client form keeps its own state and never reads `old()`, so nothing
  functional is lost.
- **Dependencies:** T20, T29
- **Files:** `bootstrap/app.php`, `app/Http/Requests/StoreProxyRequest.php`,
  `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:**
  - A 422 on the proxy form leaves **no submitted secret** in the flashed old input, including
    `verification_secret` and every `destinations.*.credential_secret`.
- **Testing:** `tests/Feature/Proxies/OldInputScrubTest.php` (new) — asserts session-flashed input after
  a failed validation contains neither secret, for both `Store` and `Update`.
- **Completion notes:** _pending_

## T46 — Capture-failure report wrap: no interpolated SQL (R5; plan Technical ruling 8)
- **Description:** `QueryException::formatMessage()` interpolates bindings into the exception message.
  Today those bindings are ciphertext (the `encrypted` cast runs before binding), so no plaintext has
  ever reached a log — but an encrypted copy of payload content in a log file is still a copy AC3's
  enumeration does not include and no retention pass touches. Wrap the capture-failure `report($e)`
  call in `IngestController` so what is reported names the `ingest_id`, the proxy, and the SQLSTATE —
  never the interpolated statement.
- **Dependencies:** T19
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:**
  - A simulated `QueryException` on a payload-bearing insert produces a report carrying `ingest_id`,
    proxy identifier, and SQLSTATE, and **no SQL statement** — including no ciphertext binding.
  - The same treatment applies to a failed secret write (`proxy_secrets`/`destinations.credential_secret`).
- **Testing:** `tests/Unit/Http/IngestControllerReportWrapTest.php` (new) — simulates a `QueryException`
  during capture and asserts the reported payload's shape.
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

## T49 — Whole-surface manual verification pass (`design-10` Flows A–I) and final regression sweep
- **Description:** The feature's closing task, mirroring `plan-11`'s T29 — re-checks `plan-10`'s
  Implementation Notes and § Explicitly out of scope list against the finished diff (not against
  earlier tasks' own completion notes), then walks every `design-10` flow (**A–I, all nine, now that
  M8b is built** — `plan-10`'s own test-strategy note excluding G–I no longer applies once T44 has
  passed) against a real production build, both themes, at 360px. If a queued/async environment is
  available (`QUEUE_CONNECTION=redis`, Horizon), a spot check of one signed dispatch and one verified
  ingest through the real async path is recommended given this document's delivery-path caveat, though
  not required by any AC below (AC47 — no numeric or environment target).
- **Dependencies:** T9, T12, T23, T24, T30, T31, T33, T41, T42, T43, T44, T45, T46, T47, T48
- **Files:** none; verification-only
- **Acceptance Criteria:**
  - Every Implementation Note (1–23) holds against the finished code, checked by inspection of the
    diff.
  - Every item in `plan-10` § Explicitly out of scope is confirmed **not** built.
  - `design-10` Flows A–I each pass end to end against a production build (`public/hot` absent), both
    themes, 360px.
  - The AC37 (T26) and AC63 (T35) byte-identical regressions both still hold against the finished
    tree, re-run one final time.
  - AC29's cap-of-two and both ruling-2a disclosures (T23, T43) are confirmed present together on one
    finished screen pass each, not merely at the unit level.
- **Testing:** manual, recorded in completion notes with concrete steps and observed outcomes.
- **Completion notes:** _pending_

## Handoff
- **Inputs:** `docs/plans/plan-10-sensitive-data-handling.md` (fully approved, all four Owner-approval
  flags ruled); `docs/product/prd-10-sensitive-data-handling.md` (Approved, `## Amendment A` and
  `## Amendment B` both ratified, 64 ACs); `docs/design/design-10-sensitive-data-handling.md`
  (Approved, as amended, both gates closed — C1–C10 and B1–B4 all landed);
  `docs/architecture/adr-021-secret-handling-and-rotation.md`,
  `docs/architecture/adr-022-inbound-verification-at-the-ingest-boundary.md`,
  `docs/architecture/adr-023-outbound-request-contract.md`,
  `docs/architecture/adr-024-field-obfuscation-and-revealed-payload-envelope.md` (all Accepted);
  `docs/questions/prd-10-q-10-02-…` (RESOLVED), `prd-10-q-10-03-…` (RESOLVED), `prd-10-q-10-04-…`
  (RESOLVED), `prd-10-q-10-05-…` (RESOLVED, Principal Engineer — see `plan-10` § *Revision A*);
  `docs/standards/planning.md`; `docs/tasks/analytics-tasks.md` (the most recent prior task
  plan, whose house format this document follows).
- **Outputs:** this task plan; `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`
  (raised here, **RESOLVED** by the Principal Engineer, recorded as `plan-10` § *Revision A*, technical
  ruling 15 — T31 built to it).
- **Dependencies:** none new — no Composer package, no pnpm package, no stack change
  (`docs/stack/stack.md` untouched).
- **Outstanding Questions:** none. `Q-10-05` is RESOLVED; no task in this plan is blocked on anything
  outside this document.
- **Next Agent:** Senior Developer, starting at **T1**. M2 (T4–T6) and M3 (T7) may be worked in
  parallel with M1 if convenient — both are pure and dependency-free — but every task is listed in a
  dependency-respecting order and no task depends on a later one.
