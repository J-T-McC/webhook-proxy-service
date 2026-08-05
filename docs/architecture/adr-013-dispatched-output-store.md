# ADR-013: Dispatched-output store — `dispatched_payloads` with a divergence-gated nullable body

- **Status:** **Accepted — Project Owner, 2026-08-05.** Owner sign-off covers the data-model change
  (new `dispatched_payloads` table) and the second at-rest copy of payload content, including the
  extension of the `APP_PREVIOUS_KEYS` key-lifecycle rule across both payload stores.
- **Author:** Principal Engineer
- **Date:** 2026-08-05 · **Revised:** 2026-08-05 (PRD-05 **Amendment A**, Owner ruling — see § Revision A)
- **Feature:** prd-05-payload-storage-retention (realizes R2's non-overridden half; serves #6, #8, #9, #10)
- **Depends on:** ADR-012 (the pass that erases this store) · ADR-014 (the parent record's cleaned signal)

## Revision A — what the Owner's 2026-08-05 ruling changed here
| Prior decision | Now |
|---|---|
| `body` `LONGBLOB **NOT NULL**`, written on every enhanced-mode event | **Option B (Owner's decision): `body` is NULL-able and written only when the dispatched bytes differ from the captured raw bytes.** NULL means "output == input". |
| AC6/AC12 removal is **structural** via `cascadeOnDelete` from the raw record | **Vestigial.** Nothing is deleted on expiry, so the cascade never fires; AC12 is now an **explicit requirement of the expiry pass** (ADR-012 Decision 6). FK retained for orphan prevention only. |
| Rejecting "also store dispatched headers" on **two** grounds: AC15's "plaintext surface not widened" + per-destination variance | **The AC15 ground is void** (AC22 encrypts headers) **and the variance ground does not hold for headers** — see § Alternatives. The rejection **survives on re-derived grounds**; the old reasoning must not be cited. |
| Divergence framed as beginning at **#8** (mapping) | **Corrected (Owner): divergence begins at #9.** Multi-format ingestion normalizes XML and other formats to JSON at the `NormalizeStep` seam already reserved at `PipelineFactory.php:27`; an XML input diverges from its dispatched JSON output before any mapping rule exists. |
| Placement, mode gate, idempotency, before-`DeliverStep` ordering | **Unchanged.** |

## Question
PRD-05 AC12/AC13 require an **enhanced-mode** proxy to store the payload it actually **dispatched**,
separately from and without altering the raw input while retained (AC11, ADR-010/ADR-014), associated
with the **same received event**, **one per received event** (R3 — no per-destination variance). AC15
requires the #3 at-rest floor with **no less-protected and no plaintext copy**. AC12 as amended
requires the output's content to be erased by the **same expiry pass**, with **no window in which one
survives the other**. AC14/AC18 forbid any storage toggle beyond the mode attribute; AC19 forbids any
transformation at #5. Q-05-03 (iii) asks where this store lives so #8 and #10 attach additively.

## Decision

**(1) A new table `dispatched_payloads`,** one row per received event, **enhanced mode only**,
holding the dispatched **body** and nothing that varies per destination:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → `teams`, `constrained()` (restrict) | team-scoped like every sibling; set explicitly on the worker path (no current team). |
| `proxy_id` | FK → `proxies`, `constrained()` (restrict) | |
| `webhook_event_id` | FK → `webhook_events`, `constrained()->cascadeOnDelete()`, **UNIQUE** | association to the received event (AC12); UNIQUE enforces one-per-event (AC13) and makes the write idempotent under at-least-once redelivery. **Cascade is orphan prevention for any future delete path — it is *not* the AC12/AC6 mechanism any more** (Revision A). |
| `body` | **`LONGBLOB NULL`** (raw `ALTER`, as `webhook_events`) | cast `'body' => 'encrypted'` — the AC15 floor, identical scheme to ADR-010 Amendment B. **NULL is meaningful — see Decision 2.** |
| `byte_size` | `unsignedInteger` | **plaintext** dispatched size, always recorded (also when `body` is NULL, where it equals the raw `byte_size`). Non-content descriptor; survives erasure (AC6). Serves #11. |
| `dispatched_at` | timestamp | when the pipeline produced the output. |
| `created_at` / `updated_at` | timestamps | |
| indexes | `UNIQUE(webhook_event_id)`, `(team_id, created_at)` | parity with siblings; serves a future team-scoped read (AC16). |

No `headers`, no `method`, no retention/GC state of its own, no soft delete.

**(2) The body is written only on divergence; NULL means "output == input" (Owner's decision).**
`CaptureDispatchedStep` compares the bytes it is about to hand `DeliverStep` against the captured
raw bytes and stores the body **only when they differ**:

```
$diverged = $ctx->payload !== $ctx->rawBody;   // PipelineContext.php:18 vs :33
```

`PipelineContext` already carries both — `rawBody` (readonly, `:33`) and `payload` (mutable, `:18`),
seeded equal at `:36`. The test is therefore a byte comparison with **no context change, no new
plumbing, and no extra query**. The row is still created for every enhanced-mode event (AC12/AC13
one-per-event, and #11 gets `byte_size` on both sides); only the content column is conditional.

**(3) NULL is disambiguated by the parent's cleaned signal, never by itself.** `body IS NULL` has two
possible meanings — "identical to the raw input" and "erased on expiry". They are resolved by
`webhook_events.payload_cleaned_at` (ADR-014 Decision 4), which is the **single explicit signal**
for the whole event including its output (PRD-05 AC12: "the cleaned state of AC21 covers the event
as a whole, including its output"). Binding invariant:

> **`dispatched_payloads.body IS NULL` is read only in the context of its parent event's
> `payload_cleaned_at`.** Cleaned ⇒ the output content is erased. Not cleaned ⇒ the output was
> byte-identical to the raw input, which is retained and *is* the answer. No consumer reads the
> column directly; `App\Services\StoredPayloadLookup` is the only resolver.

Consequence, stated rather than papered over: **after erasure, whether the output had diverged is
not recoverable.** No acceptance criterion requires it, and no consumer exists — a cleaned event has
no body to compare on either side, and #6 cannot replay it. A `diverged` flag to preserve that fact
is deliberately **not** added (see Alternatives).

**(4) Written by `CaptureDispatchedStep`, an enhanced-only pipeline step placed immediately before
`DeliverStep`.** *(Unchanged.)* It occupies the seam `PipelineFactory` has reserved since #1
(`// $steps[] = CaptureDispatchedStep::make(); // #5`, `PipelineFactory.php:32`) inside the
`ProxyMode::Enhanced` front stage — **after** both reserved transform seams,
`// $steps[] = NormalizeStep::make(); // #9` (`:27`) and `// $steps[] = MapStep::make(); // #8`
(`:31`). The mode gate is **structural**: in simple mode the step is not in the pipeline at all, so
no row can exist (AC12, AC14). It runs on the worker, after the upstream response — raw capture's
before-response guarantee is ADR-010's and is untouched. It calls `$next($ctx)` unchanged and
**never mutates** `$ctx->payload` or `$ctx->rawBody` (AC11, AC19).

**(5) Nothing is sent that was not first recorded.** *(Unchanged.)* Because the step precedes
`DeliverStep`, a write failure aborts the pipeline **before any HTTP send** and the job fails cleanly
for queue redelivery — loss-free (nothing was delivered) and duplicate-free (the UNIQUE key makes
the re-write idempotent; ADR-011 (4) makes delivery idempotent). Mirrors ADR-010's "no 2xx without a
committed capture" as "no dispatch without a recorded dispatched output."

**(6) Erasure is explicit, in the same transaction as the raw erasure.** ADR-012 Decision 6 nulls
this table's `body` inside the same transaction that erases the parent, so AC12's "no window in
which one survives the other" holds by atomicity rather than by cascade. This table carries **no
cleaned column of its own** — the event's signal covers it (Decision 3).

**(7) The store's value does not depend on #8.** Divergence arrives at **#9** (format normalization:
XML/form/other → JSON) and again at **#8** (mapping) and potentially **#10** (obfuscation applied to
the dispatched copy). Pre-#9, every dispatched body is identical to its raw input and every row
carries `body = NULL` — the store costs one row and no payload bytes, and the moment any transform
seam is filled it starts recording real divergence with no schema or code change here.

## Alternatives
- **`body NOT NULL`, always written (this ADR's own prior decision)** — settled by the Owner on 2026-08-05 in favour of option B. Not reopened; recorded in § Revision A. Its cost was the one Q-05-02(b) accepted: a byte-identical second copy of every enhanced-mode payload, doubling stored payload volume *and* the erasure/key surface, for zero information until a transform seam is filled.
- **Also store the dispatched headers.** Rejected — but **on re-derived grounds; the previous reasoning no longer holds and must not be cited.**
  - *Dead ground 1 — "AC15 says the plaintext header surface is not widened."* **Void.** AC22 encrypts captured headers, so a stored dispatched header set would inherit the same floor and widen no plaintext surface.
  - *Dead ground 2 — "headers vary per destination (ADR-008 filter)."* **False as the code stands.** `DeliverStep.php:46` passes the same `$ctx->headers` to every `DeliveryUnit`, and `DeliveryUnit::forwardHeaders()` filters it with the constant `STRIPPED_HEADERS` — the forwarded set is **identical for every destination**. The variance argument holds for **`method`** (`destinations.http_method`, `DeliverStep.php:45`), not for headers.
  - *Surviving grounds.* **(a) Redundancy:** the dispatched header set is a pure deterministic function of the captured headers and a constant list — storing it duplicates content that is already retained, adding a **third** encrypted column under the same `APP_PREVIOUS_KEYS` guard and a third erasure target for zero new information. **(b) AC13/R3 still bites the useful version:** the only genuinely per-dispatch header facts are the ones that *do* vary — outbound signing (#10) and anything keyed to `destinations.http_method` — and recording those means N rows per event, which AC13 forbids at #5. **(c) Scope:** AC22 moved exactly one slice of #10 (at-rest encryption + expiry). #10 keeps sensitive-header **policy** and owns any header **surface**; adding a dispatched-header store here would be #5 pre-empting it. **Conclusion: the rejection survives, for different reasons.** When #9 rewrites `Content-Type` or #10 adds per-destination signing, a header record becomes non-derivable and genuinely per-destination — that is #9's/#10's additive change under their own policy, not #5's.
- **Add a `diverged` boolean so the divergence fact survives erasure** — bookkeeping with no consumer: a cleaned event has neither body to compare, #6 cannot replay it, and no AC references it. It would also be the second signal about one event's cleaned lifecycle, competing with the AC21 one. Rejected.
- **Omit the row entirely when the output is identical** — loses `byte_size` and `dispatched_at` for #11, makes "enhanced-mode event with no output row" ambiguous with "row not yet written / step failed", and breaks AC13's one-row-per-event as a checkable invariant. Rejected; the Owner's ruling nulls the column, not the row.
- **Column(s) on `webhook_events`** — mutates the captured entity beyond the three columns ADR-014 authorises, and re-couples input and output that AC12 requires separable. Rejected.
- **One `payloads` table discriminated by `kind` (raw|dispatched)** — would migrate the live `webhook_events` table ADR-010 deliberately kept raw-only; a second table honours that deferral. Rejected.
- **Store the dispatched payload on `delivery_attempts`** — violates ADR-003's payload-free invariant, stores N copies per event, reopens sensitive data in the analytics path. Rejected.
- **Capture inside `DeliverToDestination` (per destination)** — N rows per event, contradicting AC13/R3. Rejected.
- **A pipeline-tail step after `DeliverStep`** — records what was *intended* only after sends have happened, and records nothing when a send path aborts. Rejected; before-delivery placement is what makes (5) hold.
- **Place the step before the `// #9 NormalizeStep` seam** — would record the pre-normalization bytes, i.e. the raw input, making the store permanently equal to `webhook_events.body` and the divergence test permanently false. Rejected; the step must sit after **all** transform seams.
- **Store the body in plaintext and defer encryption to #10** — a less-protected copy; violates AC15. Rejected.
- **Add `webhookEventId` to `PipelineContext`** so the step need not look the event up — ripples through every construction site and widens the ADR-001 envelope for one consumer. Rejected; the step resolves the event by the UNIQUE-indexed `ingest_id` (one O(1) lookup per enhanced-mode event).
- **Defer the store to #8** — settled by the Owner (Q-05-02(b), 2026-08-05). Not reopened — and the #9 framing (Decision 7) removes the premise that its value waits on #8 at all.

## Reasoning
- **The body is exactly the per-event invariant.** `DeliverStep` passes one `$ctx->payload` to every
  destination while `method` varies per destination — storing the body alone makes AC13/R3 true by
  construction, and the body is what #9 and #8 will change.
- **Divergence-gating costs one string comparison and removes the interim duplication entirely.**
  Both operands are already in the context (`:18`, `:33`); no query, no new field, no context
  mutation. Pre-#9 the store holds zero payload bytes, so Q-05-02(b)'s accepted "roughly doubling
  stored payload volume" trade-off **does not materialise at all** until a transform seam is filled —
  a strict improvement on what the Owner accepted, achieved without changing the requirement.
- **AC12 moved from structural to explicit, and that is the honest place for it.** `cascadeOnDelete`
  guaranteed AC6 only because a delete happened; with the parent retained there is no delete to
  cascade, so a guarantee left resting on the FK would be silently false. ADR-012's single-transaction
  erasure is the guarantee; the FK is downgraded to what it actually still does.
- **#8, #9 and #10 attach additively.** #9 inserts `NormalizeStep` and #8 `MapStep` *before* this step
  in the same reserved front stage, so the stored output automatically becomes the normalized/mapped
  payload with no change here — and the divergence gate starts writing bodies on the same day, with
  no migration. #10 layers key/obfuscation policy onto the same `encrypted` cast across the same
  columns.
- **AC14/AC18 are honoured by placement, not by a flag** — `PipelineFactory`'s existing
  `ProxyMode::Enhanced` branch (ADR-002). #5 adds no storage switch and no UI.

## Impact
- **Easier:** #9/#8 become observable without re-modelling (input and output independently
  identifiable, and the store *only* holds bytes once they actually differ); #6 replay can
  distinguish received from sent; #10 has one uniform at-rest story across both stores; #11 gets
  `byte_size` on both sides regardless of divergence.
- **Constrained:**
  - `dispatched_payloads` holds the dispatched **body** only — not a headers store, not a
    per-destination record, not an outcome store (`delivery_attempts` remains that, payload-free).
  - It carries **no retention state**; its lifecycle is its parent event's (ADR-012).
  - **`body IS NULL` may never be interpreted without the parent's `payload_cleaned_at`**
    (Decision 3). No direct column reads outside `StoredPayloadLookup`.
  - `CaptureDispatchedStep` must stay **after** the #9 and #8 seams and **before** `DeliverStep`,
    and must never mutate the context.
- **Data-model change / Owner flag (✋):** new table `dispatched_payloads` with the exact shape above.
  Additive only — no change to any existing table from *this* ADR (the `webhook_events` changes are
  ADR-014's), and no backfill (existing #3/#4 events simply have no output row, which is the correct
  historical fact).
- **Security-sensitive / Owner flag (✋):** a **second at-rest copy of payload content** under the
  same Laravel `encrypted` / `APP_KEY` scheme — now written only on divergence, so today it holds no
  bytes at all, but the surface exists from day one. With ADR-014's header encryption, **Amendment B's
  binding `APP_PREVIOUS_KEYS` rule spans three columns across two tables** (`webhook_events.body`,
  `webhook_events.headers`, `dispatched_payloads.body`); the future re-encryption job must cover all
  three.
- **Within stack:** no new dependency, no stack change. Eloquent, migrations, the native `Pipeline`,
  and `lorisleiva/laravel-actions` `AsObject` (ADR-007), exactly as `DeliverStep` does.
