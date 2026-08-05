# Question Q-05-04: Feasibility of AC22 header encryption and the AC21 cleaned-state signal

- **Status:** **RESOLVED — answered by the Principal Engineer (2026-08-05).** Both items are
  **feasible as stated**; **no requirement returns to the Product Manager.** Decisions recorded in
  `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md`,
  `docs/architecture/adr-012-payload-retention-and-garbage-collection.md`,
  `docs/architecture/adr-013-dispatched-output-store.md`, and
  `docs/plans/plan-05-payload-storage-retention.md`.
- **Raised by:** Product Manager (from PRD-05 **Amendment A**, Project Owner ruling 2026-08-05)
- **Owner (must answer):** **Principal Engineer** — both items are technical feasibility; the
  Product Manager states the requirement and does not resolve mechanism
- **Raised:** 2026-08-05 · **Resolved:** 2026-08-05
- **Gates:** **Technical design only.** Does **not** gate requirement approval — PRD-05 is Approved
  (Owner, 2026-08-05) and amended under the same-date Owner ruling.
- **Source:** PRD-05 § Amendment A; AC11, AC21, AC22 (and AC5–AC10, AC12, AC15 as amended);
  ADR-010 + Amendment B; ADR-008 (inbound header-forwarding policy); ADR-012, ADR-013.

## Context
The Owner ruled on 2026-08-05 that retention **nulls payload content in place and keeps the captured
event record**, that the cleaned state is **explicitly signalled** rather than derived from a missing
record, and that **captured headers are encrypted at rest and cleared by the same expiry pass**.
PRD-05 states these as AC11, AC21 and AC22. Mechanism is deliberately unspecified.

Two feasibility items follow that the Product Manager will not decide.

## Question

**(i) Does encrypting captured inbound headers at rest (AC22a) disturb any existing or planned
consumer of those headers?** Named candidates: #3's synchronous capture path (ADR-010 — capture must
still commit before the upstream response); ADR-008's inbound header-forwarding policy at delivery
time; a future #6 replay that re-dispatches from the captured record; anything that would need to
filter, match, index, or aggregate on header values. If any consumer requires headers to remain
queryable at rest, say so — that is a requirement conversation, not a design workaround.

**(ii) Does the AC21 cleaned-state signal plus in-place erasure (AC11) create any interaction with
#4's dispatch state or the AC8 in-flight holds that the previous delete-based design did not have?**
The prior design (ADR-012) leaned on the record's **absence** as both the expired signal and the
removal mechanism, and on a restrict FK failing loudly on a re-claim race. With the record retained,
both of those properties change shape. AC8 is unchanged as a requirement.

## Answer

### (i) Header encryption — **FEASIBLE. No consumer is disturbed. One column-type change is mandatory.**

**Every consumer enumerated, from the code, not assumed.**

| Consumer | Path | Effect of `'encrypted:array'` |
|---|---|---|
| #3 capture write | `IngestController.php:52` `$request->headers->all()` → `WebhookEventCapture::capture()` → `WebhookEvent::create(['headers' => …])` | **None.** One in-process `encrypt()` on a write that already encrypts `body`. No extra I/O, no query. ADR-010's capture-before-response guarantee untouched. |
| `content_type` derivation | `WebhookEventCapture::contentTypeFrom()` (`:45`) | **None** — it reads the **in-memory** `$headers` argument, before the model cast. See "bonus finding". |
| Queued pipeline entry | `ProcessIngestedWebhook.php:39` `headers: $event->headers` | **None.** Eloquent attribute read; the cast decrypts transparently. |
| ADR-008 forwarding | `DeliverStep.php:46` → `DeliveryUnit::forwardHeaders()` (`:68`) | **None.** `array_filter` over the in-memory array against the constant `DeliveryUnit::STRIPPED_HEADERS`. **Zero SQL.** ADR-008 needs no amendment. |
| Outbound send | `DeliverToDestination.php:113` `Http::withHeaders($unit->forwardHeaders())` | **None** — operates on the already-decrypted array. |
| Body-limit middleware | `EnforceIngestBodyLimit.php:23` | **None** — reads the **request** header bag, not the database. |
| Future #6 replay | rebuilds from the captured row via the model | **None** — same transparent cast. Must additionally honour the AC21 guard (see (ii)). |

**The headers-in-SQL check — the specific concern raised.** Searched `app/`, `database/`, and
`tests/` for `whereJson*`, `JSON_EXTRACT`, `where('headers'`, `headers->`, and any index or
aggregate on the column. **Result: none exist.** Outside the migration
(`2026_08_04_000002_create_webhook_events_table.php:30`) and the factory
(`WebhookEventFactory.php:32`), every appearance of `webhook_events.headers` is an Eloquent
attribute access. Nothing filters, matches, indexes, joins, or aggregates on header values in SQL.
Encryption makes the column opaque to SQL and **no consumer notices**.

Test-side reads, all cast-transparent: `WebhookEventCaptureTest:28` (`$fresh->headers`),
`WebhookEventTest:81` `test_headers_round_trip_as_an_array`, `WebhookEventCaptureAcceptanceTest:77-78`
(`$event->headers['content-type']`), `ProcessIngestedWebhookTest:32` (create). All keep passing;
`WebhookEventTest` gains an at-rest assertion mirroring the existing body test.
`FifoDispatchTest:76` (asserts `fifo_dispatches` has no `headers` column) is unaffected.

**The one hard blocker, and it is a migration not a redesign.** `webhook_events.headers` is a MySQL
**`json`** column. MySQL validates `json` values on write, and the Laravel `encrypted` cast emits a
base64 envelope that is **not valid JSON** — every capture would fail with error 3140
(`Invalid JSON text`). The column type **must** change: `json NOT NULL` → **`MEDIUMTEXT NULL`**
(nullable is required by AC22b anyway). This is a schema change to an existing table and therefore an
Owner gate item (ADR-014, listed in plan-05). It is not a workaround and changes no requirement; the
Owner's ruling that "the migration is trivial — there is no production data" is exactly the condition
that makes it cheap. Because existing rows hold plaintext JSON the new cast cannot decrypt, the
migration **drops and re-adds** the column — destructive to captured headers in existing local/CI
databases only.

**Bonus finding — AC6's content-type ruling is already satisfied by existing code.** AC6 retains
`content_type` as a format descriptor while erasing the header collection. `WebhookEventCapture`
already denormalises `Content-Type` into its own column at capture time, before the cast. So
content-type survives both encryption and erasure with **no new code and no exception carved into the
erasure**. Standing constraint recorded in ADR-014: it must stay denormalised — deriving it from
`headers` at read time would break after erasure.

**New consequences, none blocking.**
- Key-lifecycle surface grows from one column to **three across two tables**
  (`webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`). ADR-010 Amendment B's
  binding `APP_PREVIOUS_KEYS` rule applies unchanged to all three; the future re-encryption command
  (still ADR-010's accepted FUTURE task) must cover all three.
- Losing a key now breaks **header forwarding** as well as the body. No new failure *class* — the
  body is already undeliverable in that scenario — but the scope is wider. Owner-flagged.
- Column sizing: `MEDIUMTEXT` rather than `TEXT`. `TEXT` (64 KiB) would hold ~48 KiB of plaintext
  after the ~35% envelope overhead, above any realistic front-end header cap, but the margin is a
  silent-truncation cliff on a column that now carries the confidentiality floor. `MEDIUMTEXT` costs
  nothing.

**No requirement returns to the Product Manager.** Standing constraint recorded instead: headers are
no longer queryable in SQL, so any *future* need to filter, match, index, or aggregate on header
values is a requirement conversation (it would mean reversing AC22a or adding a #10-owned classified
projection) — never a quiet design workaround.

### (ii) Cleaned state + in-place erasure vs. #4 dispatch state and the AC8 holds — **FEASIBLE. Four interactions change; net effect is a simpler and safer design.**

**New hazard — and it is the important one.** Under delete, a racing reader hit
`ProcessIngestedWebhook`'s `firstOrFail()` and failed **loudly**. Under erase, the row is still there
with `body = NULL`, so a reader that checks the wrong thing would build a `PipelineContext` with an
empty body and **silently dispatch an empty payload to every destination** — worse than the failure it
replaces. Ruling (ADR-014 Decision 7, binding): **guard on `payload_cleaned_at !== null`, never on
`body === null`**, abort before `DeliverStep`, log `payload.expired` (identifiers only), return
cleanly (AC10). This is why AC21's "explicitly signalled, never inferred" is a **correctness**
requirement here, not a presentation one.

**The restrict-FK safety net is gone, and is replaced by something stronger.** Under delete, a
re-claim race between selection and delete was caught by the restrict FK on
`fifo_dispatches.webhook_event_id` making the delete fail. An `UPDATE` involves no such constraint.
Replacement: the erase is a **compare-and-set** — a single conditional `UPDATE` carrying
`payload_cleaned_at IS NULL` plus the H1–H4 predicates in its own `WHERE` clause (ADR-012 Decision 1).
Zero rows affected ⇒ a hold reappeared ⇒ skip. The holds are now re-asserted **atomically inside the
mutating statement** rather than trusted from an earlier `SELECT`, which closes the select→act gap
more directly than the FK ever did. The FK itself is retained for referential integrity only and must
no longer be cited as an AC6/AC8 guarantee.

**GC and #4 now share zero written tables.** The deletion design had to delete the `settled`
`fifo_dispatches` row first (restrict FK). Erase-in-place deletes nothing, so that conditional delete
— and with it the whole disjoint-index-range argument, the lock-contention analysis, and the re-claim
race — is **unnecessary**. GC writes only `webhook_events` and `dispatched_payloads`; it *reads*
`fifo_dispatches` and `delivery_attempts` and writes neither. AC8 composition is strictly stronger
than before. (Consequence: `fifo_dispatches` rows are never pruned — record growth, deferred concern
**D1**, out of scope; not re-raised.)

**Two code changes the delete design required are now unnecessary.**
- `AdvanceProxyFifoQueue` — **no change at all.** Its `$claimed->webhookEvent->ingest_id` dereference
  (`:42`) can no longer meet a missing event, and `ProcessIngestedWebhook` returning early on a cleaned
  payload still lets it settle the row and self-dispatch, so the line advances. The planned
  settle-and-advance patch is dropped.
- `ProcessIngestedWebhook` — **keeps `firstOrFail()`.** An absent row is now genuinely a bug
  (never-captured), never expiry. It gains only the cleaned-state guard above.

**H1–H4 re-derived — all four still necessary; one added.**
- **H0 (new)** `payload_cleaned_at IS NULL` — idempotence, and keeps a run's work bounded to rows it
  will actually erase. Trivially true under delete (a collected row was gone).
- **H1 expired** — unchanged.
- **H2 FIFO** — **still mechanically necessary**: a `pending`/`claimed` row means the pipeline entry
  will still read this row's `body`/`headers`. This is the hop AC8 protects.
- **H3 in-flight Async** — **still necessary, for a changed reason.** It no longer protects a read
  (per-destination jobs carry their bytes in the `DeliveryUnit` and never re-read the event), but AC8
  states the requirement in terms of *outstanding dispatch*, and marking an event cleaned while its
  deliveries are still landing would publish a false AC21 state. Necessary by criterion and
  consistency, not by loss risk — stated so no one later "optimises" it away.
- **H4 pre-dispatch horizon** — **still necessary**: the captured-but-job-not-started window is the
  one window H2/H3 cannot see.

**Sufficiency.** Exactly one hop reads a captured row after capture: the pipeline entry (ADR-011
Decision 3). FIFO ⇒ always preceded by a non-settled `fifo_dispatches` row (H2). Async ⇒ either before
any attempt row exists (H4, within the horizon) or after the hop already happened. A job that already
read the row holds plaintext in memory and is unaffected by a concurrent erase. **H4's residual gap is
unchanged in size and improved in consequence:** an Async job queued longer than the horizon could
still find its payload gone — under delete it threw and the event vanished; under erase it logs,
returns cleanly, and the record survives marked cleaned, i.e. an auditable expired state instead of an
absence.

**AC12's atomicity is now explicit, not structural.** `cascadeOnDelete` never fires when nothing is
deleted, so "no window in which one survives the other" is guaranteed by erasing both stores in **one
transaction** (ADR-012 Decision 6), not by the FK (ADR-013 Revision A).

**One subtlety worth naming.** With ADR-013's option-B nullable body, `dispatched_payloads.body IS NULL`
means either "output identical to input" or "erased". The parent's `payload_cleaned_at` disambiguates
it; the invariant is binding and `StoredPayloadLookup` is the only resolver (ADR-013 Decision 3).

**No requirement returns to the Product Manager.**

## Impact if unresolved
None remaining — resolved. Had either item been infeasible, the amended criterion would have returned
to the Product Manager to be re-derived with the Owner.

## Where the decisions live
- `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md` — (i) in full, the
  `payload_cleaned_at` signal, the reader guard, and the partial supersession of ADR-010.
- `docs/architecture/adr-012-payload-retention-and-garbage-collection.md` — (ii): the compare-and-set
  erase, the re-derived H0–H4 holds, and what deletion-era apparatus is dropped.
- `docs/architecture/adr-013-dispatched-output-store.md` — the NULL-body disambiguation invariant.
- `docs/plans/plan-05-payload-storage-retention.md` — the composed design, test strategy, and the full
  Owner-approval flag list.
