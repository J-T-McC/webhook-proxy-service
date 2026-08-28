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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
