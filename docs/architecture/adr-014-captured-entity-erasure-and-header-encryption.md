# ADR-014: Captured-entity changes for erase-in-place retention — nullable payload columns, encrypted headers, explicit cleaned state (partially supersedes ADR-010)

- **Status:** **Accepted — Project Owner, 2026-08-05.** Owner sign-off covers the schema change to
  the existing `webhook_events` table (nullable payload columns, encrypted headers, explicit cleaned
  state), the extension of at-rest encryption coverage, and the **partial supersession of two named
  positions of ADR-010** (Accepted, Owner 2026-08-04).
- **Author:** Principal Engineer
- **Date:** 2026-08-05
- **Feature:** prd-05-payload-storage-retention (§ Amendment A — Owner ruling 2026-08-05)
- **Relationship to ADR-010:** **partially supersedes** ADR-010 — two named positions only.
  ADR-010 otherwise stands, Accepted and operative. See § Positions superseded.
- **Answers:** Q-05-04 (i) and (ii) — `docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md`

## Question
PRD-05 Amendment A (Owner ruling, 2026-08-05) requires retention to **erase payload content in
place and retain the captured event record** (AC11), that record to carry an **explicit cleaned
state** (AC21), and captured **headers to be encrypted at rest and erased by the same pass**
(AC22). Every one of those lands on `webhook_events` — the table ADR-010 ratified as *raw-only,
immutable, retention-state-free*, whose Impact section says "never … mutate a captured row here"
and whose Amendment B says "**inbound `headers` remain plaintext at rest until #10** — the Owner
accepts this explicitly."

Two questions follow. **(a)** What shape does the captured entity take under erase-in-place?
**(b)** How is the reversal of an **Accepted, Owner-approved** ADR recorded, given
`docs/standards/documentation.md`: *"A change that **reverses or replaces** an already-Accepted
decision is a **new ADR that supersedes** the old one, never an in-place rewrite of ratified
history."*

## Decision

**(1) This ADR is the superseding instrument; ADR-010 is amended by pointer, not rewritten.**
A new ADR — not an `## Amendment C` on ADR-010 — because both changes **reverse** ratified
positions rather than extend them, which is exactly the case documentation.md reserves for a
superseding ADR. ADR-010 keeps its file, its Accepted status, and its full text; its Status line
gains "**two positions superseded by ADR-014**" and the two positions gain an inline pointer.
Nothing ratified is deleted or reworded. Supersession is **partial and enumerated** — ADR-010
remains the operative decision for the capture entity, the pre-dispatch synchronous placement,
the shared `ingest_id` correlator, the `body` `encrypted` cast, the `LONGBLOB` column type, and
Amendment B's binding `APP_PREVIOUS_KEYS` guard.

**(2) Positions superseded — exactly two, both by the Owner's 2026-08-05 ruling.**

| ADR-010 position | Verbatim | Superseded to |
|---|---|---|
| **P1 — Impact / Constrained** | "`webhook_events` is raw-only and immutable — never store dispatched/derived output or **mutate a captured row** here" | **Narrowed, not removed.** Immutability binds **while payload content is retained** (PRD-05 AC11 first half — unchanged and still absolute). The **expiry pass** may mutate exactly three columns of a captured row: `body`, `headers`, `payload_cleaned_at`. No other writer, path, or column is authorised. "Never store dispatched/derived output here" is **not** superseded — that stays ADR-013's table. |
| **P2 — Amendment B, scope of the floor** | "**#3 encrypts the `body` only.** … **Inbound `headers` remain plaintext at rest until #10** — the Owner accepts this explicitly" | **Reversed.** Captured headers are encrypted at rest at **#5** (PRD-05 AC22a). Amendment B's *rest* — the floor-not-ceiling framing, the lock-in-is-a-non-concern position, the binding key guard, and "#10 is NOT descoped" — is **unchanged and extended** to cover the header column. |

**Owner's reasoning, preserved verbatim in substance (PRD-05 § Amendment A):** ADR-010's
immutability constraint exists to prevent **alteration** of captured payload data, not to prevent
its **cleanup**; deleting the record and nulling the payload reach the same security outcome, and
treating the delete as compliant while treating the null as a violation is incoherent because
**the delete is the larger mutation**. Nulling also targets payload content at the right
granularity now that it lives in two stores. The migration is trivial — **there is no production
data to protect**.

**(3) `webhook_events` schema — three changes, one new index.** MySQL 8.0 / InnoDB.

| Change | From | To | Why |
|---|---|---|---|
| `body` | `LONGBLOB NOT NULL` | `LONGBLOB NULL` | Erasure target (AC6, AC11). `MODIFY`; value-preserving. |
| `headers` | `json NOT NULL`, cast `'array'` | `MEDIUMTEXT NULL`, cast **`'encrypted:array'`** | AC22a (encrypted) + AC22b (erasure target). **Type change is mandatory, not cosmetic** — see Reasoning. |
| `payload_cleaned_at` | — | `TIMESTAMP NULL` (new column, after `byte_size`) | The AC21 cleaned-state signal. |
| index | — | `(team_id, payload_cleaned_at, created_at)` (new) | GC selection; see Reasoning. |

`method`, `content_type`, `byte_size`, `received_at`, `ingest_id`, `team_id`, `proxy_id`,
`created_at` are **retained through erasure** — AC6's permitted non-content descriptors, with
`content_type` explicitly ruled retained by the Owner.

**(4) The cleaned state is a nullable timestamp, never an inferred absence.**
`payload_cleaned_at IS NULL` ⇒ **retained**; `NOT NULL` ⇒ **cleaned**; no `webhook_events` row for
an `ingest_id` ⇒ **never captured**. AC21's three states map one-to-one, and **no consumer may
infer "cleaned" from `body IS NULL`** — the timestamp is the only signal. A timestamp rather than
a boolean: same storage, records *when*, and makes the erase write self-evidencing.
`App\Enums\StoredPayloadState { Retained, Cleaned, NeverCaptured }` names the three states once;
`App\Services\StoredPayloadLookup` is the only resolver.

**(5) The `encrypted:array` cast is transparent to every existing consumer; no consumer reads
headers in SQL.** Verified against the codebase — full findings in Q-05-04 and § Reasoning. Every
read of `webhook_events.headers` goes through the Eloquent attribute
(`ProcessIngestedWebhook.php:39`); ADR-008's forwarding filter is an in-memory
`array_filter` over the constant `DeliveryUnit::STRIPPED_HEADERS` and never touches the database.
No `whereJson*`, `JSON_EXTRACT`, `where('headers', …)`, header index, or header aggregate exists
in `app/`, `database/`, or `tests/`. **ADR-008 is undisturbed and is not amended by this ADR.**

**(6) `content_type` survives header erasure for free.** `WebhookEventCapture::contentTypeFrom()`
already denormalises `Content-Type` into its own column **before** the cast runs. AC6's ruling that
content type is retained while the header collection is erased therefore needs no new code and no
exception to the erasure — the existing denormalisation is what makes it true.

**(7) The reader guard is on the signal, not the value.** Any consumer that rebuilds a
`PipelineContext` from a captured row must check `payload_cleaned_at !== null` **before** reading
`body`/`headers`, and abort without delivering. Guarding on `body === null` is forbidden: it makes
"erased" and "empty body" indistinguishable, and the failure mode of getting it wrong is
**silently dispatching an empty payload to every destination** — strictly worse than the
delete-era failure mode (a loud `ModelNotFoundException`). This is the single most important
consequence of moving from delete to erase.

## Alternatives
- **`## Amendment C` on ADR-010 instead of a new ADR** — documentation.md reserves in-place amendment for *additive* changes that do not reverse the decision (the ADR-009 Amendment A precedent). Both P1 and P2 reverse Owner-ratified positions. Rejected as a silent rewrite of ratified history.
- **Fully supersede ADR-010 (`Status: Superseded by ADR-014`)** — ADR-010's entity choice, pre-dispatch placement, `ingest_id` correlator, body cast, column type, and key guard all remain operative and are re-ratified by nothing here. Marking the whole ADR superseded would orphan those decisions and invalidate every citation from plan-03, ADR-011, ADR-012, and ADR-013. Rejected in favour of enumerated partial supersession.
- **Keep `headers` as `json` and encrypt into it** — MySQL validates `json` columns on write; the `encrypted` cast emits a base64 envelope that is not valid JSON, so every capture would fail with MySQL error 3140 (`Invalid JSON text`). **Not a preference — a hard blocker.** Rejected.
- **`TEXT` instead of `MEDIUMTEXT`** — `TEXT` (64 KiB) holds ~48 KiB of plaintext after the ~35% envelope overhead, comfortably above any front-end header cap; but the margin is a silent-truncation cliff on a column that now also carries the confidentiality floor. `MEDIUMTEXT` costs nothing (off-page storage either way). Rejected.
- **A `payload_cleaned` boolean, or an `expired` enum state** — same storage as a timestamp, less information, and no answer to "when". Rejected.
- **Reuse `updated_at` as the cleaned signal** — an implicit signal on a column any future writer can touch; AC21 requires an explicit one. Rejected.
- **A separate `payload_cleanups` sidecar table holding the cleaned marker** — keeps `webhook_events` column-count-stable but puts the AC21 signal somewhere AC21 says it must not be (the record's own signal), adds a join to the hot guard path in (7), and re-introduces the bookkeeping row ADR-012 rejected. Rejected.
- **Erase headers but leave them plaintext while retained (AC22b only)** — halves the ruling and leaves the exposure Amendment B deferred. The Owner ruled both halves. Not ours to split.
- **Encrypt headers but keep a plaintext searchable projection** — no consumer needs one (Decision 5); a projection would be a second, less-protected copy of header content, contrary to AC15. Rejected.
- **Drop the whole `webhook_events` table and recreate it** — larger blast radius than three `ALTER`s for the same end state; the FKs from `fifo_dispatches` would need dropping and rebuilding. Rejected.

## Reasoning
- **The `json` → `MEDIUMTEXT` change is forced by the storage engine, not by taste.** This is the
  only part of AC22 that was ever at risk of being infeasible, and it is not — it is a
  column-type migration on a table holding no production data, which is precisely the condition
  the Owner named as making the migration trivial.
- **The new index is a property of the pass, not a growth strategy.** Under delete, a collected
  row left the table, so each run's scan set was self-limiting. Under erase-in-place, cleaned rows
  stay inside the `(team_id, created_at <= cutoff)` range forever, so every subsequent run would
  re-scan every row it has ever cleaned. `(team_id, payload_cleaned_at, created_at)` turns the
  selection into a seek over **uncleaned** rows only, keeping one run's cost proportional to what
  it will actually erase. This asserts **no** cap, prune, roll-up, or numeric target — D1 stays
  out of scope and is not re-raised.
- **Header encryption costs one in-process `encrypt()` on the capture write**, alongside the body
  encryption already there. No extra I/O, no extra round trip; #3's capture-before-response
  guarantee (ADR-010 Decision 2) is untouched, and the ingest hot path gains no query.
- **The key-lifecycle surface grows from one column to three** (`webhook_events.body`,
  `webhook_events.headers`, `dispatched_payloads.body` across two tables). Amendment B's binding
  rule applies unchanged to all three. New consequence to state plainly: with headers encrypted,
  losing a key breaks **header forwarding** as well as the body — but since the body is already
  undeliverable in that scenario, it adds no new failure *class*, only new scope for the future
  re-encryption command (still ADR-010's accepted FUTURE task, still not built here).
- **Erase-in-place strictly improves the auditability of the residual H4 risk** (ADR-012). Under
  delete, an event erased in the narrow pre-dispatch window simply vanished. Under erase, the
  record survives with `payload_cleaned_at` set — an expired state that is representable (AC10)
  and inspectable, instead of an absence.

## Impact
- **Easier:** AC10/AC21 need no derivation — one column answers "was this cleaned?" exactly, so
  `StoredPayloadLookup` stops inferring state from `delivery_attempts` and #6 inherits an exact
  three-state contract. #10 layers header **policy** onto a column that already has the at-rest
  floor, with no shape change (the same "floor now, policy later" arrangement Amendment B set).
- **Constrained:**
  - The expiry pass is the **only** writer permitted to mutate a captured row, and only
    `body`, `headers`, `payload_cleaned_at`. Any other mutation of a captured row remains
    forbidden by ADR-010 P1 as narrowed here.
  - **Never guard on `body === null`; always on `payload_cleaned_at`** (Decision 7).
  - `headers` is no longer queryable in SQL. Any future need to filter, match, index, or
    aggregate on header values is a **requirement conversation with the Product Manager**, not a
    design workaround — it would require reversing AC22a or adding a #10-owned classified
    projection.
  - `content_type` must stay denormalised at capture; deriving it from `headers` at read time
    would break after erasure.
- **Data-model change (Owner-gated ✋):** `webhook_events` — `body` → NULL-able; `headers` → `MEDIUMTEXT NULL`
  with cast `'encrypted:array'`; new `payload_cleaned_at TIMESTAMP NULL`; new index
  `(team_id, payload_cleaned_at, created_at)`. The `headers` step **drops and re-adds** the column
  rather than `MODIFY`-ing it: existing rows hold plaintext JSON that the new cast cannot decrypt,
  and the Owner has ruled there is no production data to protect. **Destructive to captured
  headers in any existing local/CI database.** Migrations here already require MySQL (the #3
  migration uses a raw `ALTER … LONGBLOB`); these follow the same precedent and the same constraint.
- **Security-sensitive (Owner-gated ✋):** at-rest protection is **extended** to captured headers
  (reverses an Owner-accepted position, P2), and Amendment B's binding `APP_PREVIOUS_KEYS` guard
  now spans **three columns across two tables**.
- **Reverses an Accepted ADR (Owner-gated ✋):** P1 and P2 above. The Owner made the ruling; this
  ADR is the record of it and is what the Owner ratifies.
- **Within stack:** MySQL 8.0, Eloquent casts, Laravel migrations. No new dependency, no stack
  change. V6/Postgres not reopened.
