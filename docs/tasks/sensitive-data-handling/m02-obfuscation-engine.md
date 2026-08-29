> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

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
