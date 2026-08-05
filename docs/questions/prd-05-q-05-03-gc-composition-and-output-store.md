# Question Q-05-03: GC composition with #4 dispatch, retention bookkeeping vs. the immutable raw entity, the output store's home, and the Owner gate

- **Status:** RESOLVED — answered by the Principal Engineer (2026-08-05) in
  `docs/plans/plan-05-payload-storage-retention.md`, `docs/architecture/adr-012-payload-retention-and-garbage-collection.md`,
  and `docs/architecture/adr-013-dispatched-output-store.md`.
  **Amended 2026-08-05 (Amendment A):** parts of the original answer are **SUPERSEDED** by the
  Project Owner's same-date ruling — see **§ Amendment A (2026-08-05) — superseded answers** at the
  foot of this document. Superseded: **(ii)** in full, **(iii)**'s header note and its
  `cascadeOnDelete`/`NOT NULL` body detail, and the parts of **(i)** and **(iv)** named there.
  Original wording is retained below unaltered; **read every section against the amendment.**
- **Raised by:** Product Manager (inline in PRD-05 § Open Questions)
- **Owner (must answer):** **Principal Engineer** — technical; the PRD explicitly defers
  mechanism, scheduling, batching, and delete strategy to technical design
- **Raised:** 2026-08-05
- **Resolved:** 2026-08-05
- **Gates:** Technical design only. Never gated requirement approval — PRD-05 was Approved
  by the Project Owner on 2026-08-05 with this question open.
- **Source:** PRD-05 AC8, AC11, AC12, AC15; ADR-010 (raw-only immutable capture, Amendment B);
  ADR-011 (FIFO claim state, dispatch-by-reference); ADR-003 (payload-free attempt records);
  `CLAUDE.md` approval gates.

## Question (as raised in PRD-05)
Confirm, at #5 technical design, that:
- **(i)** an expiry-driven delete pass composes with **ADR-011**'s FIFO ordering/claim state
  and with Async in-flight jobs such that **AC8** holds and no in-flight event is lost;
- **(ii)** retention state and GC bookkeeping can exist **without mutating** the raw-only
  immutable `webhook_events` entity (ADR-010) — **AC11**;
- **(iii)** where the dispatched-output store lives so that #8 mapping and #10's
  obfuscation/encryption policy attach additively (**AC12**, **AC15**) and the #3 `encrypted`
  at-rest floor is preserved with no plaintext copy;
- **(iv)** whether the resulting change is a **data-model change** requiring the Owner
  approval gate in `CLAUDE.md` at plan time.

## Answer

### (iv) — answered first, because it gates the rest: **YES, the Owner gate is tripped.**
Four distinct triggers, each listed with its exact ask under
**plan-05 → Handoff → Owner-approval flags (✋)**:
1. **ADR-012** — the retention/GC architecture.
2. **ADR-013** — the dispatched-output store.
3. **Data-model change** — one new table, `dispatched_payloads`. Additive only: **no** new
   column, index, or backfill on any existing table, and deliberately **no**
   `teams.retention_days` column (the Owner is asked to ratify that shape choice too).
4. **Irreversible + security-sensitive** — (a) scheduled **permanent, unrecoverable** hard
   deletion of customer payload content, enabled by default for all teams, no opt-in, no
   recovery path (first destructive run ~2026-09-03; the deploy-time run deletes nothing);
   (b) a **second at-rest copy** of payload content extends ADR-010 Amendment B's binding
   `APP_PREVIOUS_KEYS` rule to a **second table**.

**Not tripped:** no new Composer/pnpm dependency, no stack change, no change to any existing
table or index. **V6 (Postgres for ingestion) is not reopened** — #5's volume is bounded by
the very GC #5 introduces.

### (i) GC vs. ADR-011 FIFO claim state and Async in-flight jobs (AC8)
Eligibility is a conjunction of four **named holds** evaluated inside the selection query
(ADR-012 Decision 3). A payload is collectable only when all are clear:

| Hold | Predicate | Covers |
|---|---|---|
| **H1** expired | `webhook_events.created_at <= now() - windowFor(team)` | AC1–AC3, AC7 |
| **H2** FIFO ordering | no `fifo_dispatches` row for the event with `status <> 'settled'` | queued/pending/claimed under FIFO |
| **H3** in-flight delivery | no `delivery_attempts` row for the event's `ingest_id` with `status = 'dispatched'` | in flight under Async |
| **H4** pre-dispatch horizon | if the event has **zero** attempt rows, it must be older than `retention.dispatch_horizon_minutes` | captured but pipeline job not yet started |

H2 reads FIFO's own published claim state; H3 reads the crash-safe `dispatched` row Async
writes **before** each HTTP call (ADR-003); H4 bounds the only window that leaves no durable
record — inert while the 30-day window dwarfs a ~60-minute horizon, and load-bearing only if
V5 ever configures a short window.

**GC cannot stall or reorder a FIFO line.** It deletes only `settled` ordering rows, and
only conditionally (`WHERE status = 'settled'` in the `DELETE` itself). The advancer scans
and locks `pending` / `claimed` rows under `(proxy_id, status, webhook_event_id)` — a
**disjoint index range** — and settled rows never participate in `MIN(webhook_event_id)` over
the pending set, so ordering, the claim, the lease, and the sweeper's idle-proxy scan are all
untouched. The existing **restrict** FK on `fifo_dispatches.webhook_event_id` is kept
deliberately: a re-claim race makes the event delete **fail loudly** and skip that event for
the run, rather than silently orphaning ordering state. Neither side holds a lock across a
network call, and GC transactions are per-event and short.

Only one consumer needs the raw row after capture — the pipeline entry
(`ProcessIngestedWebhook` rebuilds its context from it, ADR-011 Decision 3). Per-destination
Async jobs carry their payload in the `DeliveryUnit` and never read `webhook_events`, so the
hold set has to protect exactly one hop, which H2–H4 do.

### (ii) Retention bookkeeping without mutating `webhook_events` (AC11)
**There is no bookkeeping.** Expiry is **derived** — `created_at + windowFor(team)` — from two
columns the row already carries, scanned on the existing `(team_id, created_at)` index. No
`expires_at`, no retention flag, no tombstone, no soft delete, on any table. GC's only write
to `webhook_events` is the `DELETE`, so AC11 holds **by construction** rather than by
discipline. The window itself is a **team-keyed resolver**,
`RetentionPolicy::windowFor(Team $team)`, not a column and not a constant — today 30 days for
every team (AC2, AC3), and the single method a V5 tier or V6 region lever changes, per
Q-05-02(a)'s "changes only where the value comes from, not the storage or GC model."

AC10's *expired state* is likewise derived, not stored: an `ingest_id` with surviving
`delivery_attempts` but no `webhook_events` row **is** "expired", because ADR-010 guarantees
every accepted event was captured before dispatch. `StoredPayloadLookup` returns
Available / Expired / Unknown from that derivation.

**V4 inheritance:** both anchors — `created_at` and `team_id` — are intrinsic to the captured
event row and derived from neither the HTTP ingest request nor its context, so a future
offline-capture path that yields a `webhook_events` row is retained and collected with no
change. `created_at` (durable-custody time) is also the anchor a sender or edge buffer cannot
influence; if V4 later needs sender-receipt semantics, that is a one-line anchor swap inside
`RetentionPolicy`.

### (iii) Where the dispatched-output store lives (AC12, AC13, AC15)
A new **`dispatched_payloads`** table — one row per received event, enhanced mode only,
holding the dispatched **body** and nothing that varies per destination:
`UNIQUE(webhook_event_id)` (AC13 + write idempotency),
`webhook_event_id` FK **`cascadeOnDelete`** (AC6 removal becomes structural — GC cannot leave
an output behind), `body` `LONGBLOB` with the `'body' => 'encrypted'` cast (the AC15 floor,
identical scheme to ADR-010 Amendment B — no plaintext copy, no second scheme), and
`byte_size` recorded as the **plaintext** length pre-cast.

It is written by **`CaptureDispatchedStep`**, occupying the seam `PipelineFactory` has
reserved since #1 — inside the `ProxyMode::Enhanced` front stage, **after** the reserved
`// #8 MapStep` slot and **immediately before** `DeliverStep`. Consequences:
- **#8 attaches additively:** inserting `MapStep` ahead of it makes the stored output the
  mapped payload with no change here.
- **#10 attaches additively:** the same `encrypted` cast on the same two body columns; #10
  layers key/obfuscation policy without a shape change.
- **AC14/AC18 hold structurally:** in simple mode the step is not in the pipeline at all, so
  no output row can exist — no storage toggle, no flag, no UI.
- **Nothing is sent that was not first recorded:** because the step precedes `DeliverStep`, a
  write failure aborts before any HTTP send — loss-free and duplicate-free on redelivery,
  mirroring ADR-010's "no 2xx without a committed capture."

**Headers and method are deliberately not stored.** Both vary per destination (ADR-008 filter,
`destinations.http_method`), so neither is a per-event fact under AC13/R3 — and inbound headers
remain plaintext at rest until #10, a surface AC15 says is "unchanged and **not widened** by
this item." A second plaintext header copy would widen it. #10 may add header storage under its
own policy, additively.

## Impact if unresolved
None remaining — resolved. Had it stayed open, technical design could not have proceeded:
(i)–(iii) determine the data model and the GC mechanism, and (iv) determines which parts of
the plan the Principal Engineer may self-certify.

## Amendment A (2026-08-05) — superseded answers
PRD-05 § Amendment A (Project Owner ruling, 2026-08-05) changed premises this answer rested on:
retention now **erases payload content in place and retains the captured record** (AC11), the
cleaned state is **explicitly signalled** (AC21), captured **headers are encrypted at rest and
erased by the same pass** (AC22), and the dispatched output is **cleared by that same pass** rather
than by a cascade (AC12). The question itself is unchanged and stays RESOLVED; the answer is
re-stated below where the ruling overtook it. **History retained — nothing above is rewritten.**

| Original answer | State | Now |
|---|---|---|
| **(ii)** "There is no bookkeeping… Expiry is **derived**… No `expires_at`, no retention flag, no tombstone, no soft delete, on any table. **GC's only write to `webhook_events` is the `DELETE`**" | **SUPERSEDED in full** | AC21 requires an explicit cleaned state on the record. One column is added: **`payload_cleaned_at TIMESTAMP NULL`** (ADR-014). GC's write is a conditional in-place **`UPDATE`** setting `body = NULL, headers = NULL, payload_cleaned_at = NOW()` — never a delete. *Eligibility* is still derived (`created_at + windowFor(team)`); the *cleaned fact* is now stored. AC11 holds on its amended reading: immutability binds while content is **retained**, and this pass is the only authorised mutator of a captured row, writing only those three columns. |
| **(ii)** "AC10's *expired state* is derived… an `ingest_id` with surviving `delivery_attempts` but no `webhook_events` row **is** 'expired'" | **SUPERSEDED** | Read `payload_cleaned_at`. `StoredPayloadLookup` returns `Retained \| Cleaned \| NeverCaptured` (AC21's three states) from the signal, not from an absence. **No consumer may infer "cleaned" from `body IS NULL`** (ADR-014 Decision 7) — the failure mode of getting this wrong is silently dispatching an empty payload. |
| **(ii)** team-keyed `RetentionPolicy::windowFor(Team)`; no `teams.retention_days`; V4 inheritance via `created_at` + `team_id` | **STANDS** | Unchanged (ADR-012 Decision 2). |
| **(i)** H1–H4 hold set, and that only the pipeline entry re-reads the captured row | **STANDS**, re-derived | All four remain necessary under erase-in-place, plus **H0** (`payload_cleaned_at IS NULL`). H3's justification changes from "protects a read" to "AC8 states the requirement in terms of outstanding dispatch, and a false cleaned state would be published" — see Q-05-04 (ii). |
| **(i)** GC deletes only `settled` `fifo_dispatches` rows; the **disjoint index range** argument; the **restrict FK** kept as the fail-loud net against a re-claim race | **SUPERSEDED — all three were deletion-era apparatus** | Nothing is deleted, so GC **never touches `fifo_dispatches` at all**; the conditional delete and the disjoint-range argument are unnecessary, replaced by the stronger claim that GC and #4 share **zero written tables**. The restrict FK is retained for referential integrity only and is **no longer an AC6/AC8 guarantee**. Its role passes to a **compare-and-set**: the holds are re-asserted inside the erase `UPDATE`'s own `WHERE` clause; zero rows affected ⇒ skip (ADR-012 Decision 1). |
| **(iii)** `dispatched_payloads` location, `UNIQUE(webhook_event_id)`, `encrypted` cast, plaintext `byte_size`, `CaptureDispatchedStep` before `DeliverStep`, structural mode gate, nothing-sent-unrecorded | **STANDS** | Unchanged (ADR-013 Decisions 1, 4, 5). |
| **(iii)** `body` `LONGBLOB **NOT NULL**`, written on every enhanced-mode event | **SUPERSEDED** | Owner's option B: `body` is **NULL-able**, written **only when the dispatched bytes differ from the captured raw bytes**; NULL means "output == input". `PipelineContext` already carries both operands (`payload` `:18`, `rawBody` `:33`), so the test is a byte comparison with no new plumbing. Divergence arrives at **#9** (format normalization, seam at `PipelineFactory.php:27`), not only at #8. |
| **(iii)** "`cascadeOnDelete` makes AC6 removal **structural** — GC cannot leave an output behind" | **SUPERSEDED** | Vestigial: nothing is deleted, so the cascade never fires on expiry. AC12 is now an **explicit** requirement of the pass — both stores are erased in **one transaction** (ADR-012 Decision 6). The FK is retained for orphan prevention on any future delete path only. |
| **(iii)** headers not stored because they "vary per destination" **and** because AC15 says the plaintext header surface is "not widened" | **SUPERSEDED as reasoning; conclusion survives on new grounds** | The AC15 ground is **void** (AC22 encrypts headers). The variance ground is **false for headers** as the code stands — `DeliverStep.php:46` passes the same `$ctx->headers` to every destination and `DeliveryUnit::forwardHeaders()` filters with a constant list, so the forwarded set is identical per destination; variance holds for **`method`** only. Headers stay out on re-derived grounds: redundancy (derivable from the retained captured headers plus a constant), a third encrypted column under the same key guard for zero new information, and #10 owning header policy and any header surface. See ADR-013 § Alternatives. |
| **(iv)** Owner gate tripped; four triggers; "**no change to any existing table or index**" | **SUPERSEDED — the gate list grew from four to seven** | Now also: a **schema change to the existing `webhook_events` table** (`body` → NULL-able; `headers` `json` → `MEDIUMTEXT NULL` with an `'encrypted:array'` cast; new `payload_cleaned_at`; new index `(team_id, payload_cleaned_at, created_at)`), **ADR-014** as a third ADR, and **at-rest encryption coverage extended to captured headers** — which reverses an Owner-accepted position in ADR-010 Amendment B. The irreversible-operation flag stands, reshaped from "hard deletion" to "in-place erasure": equally unrecoverable. Current list in plan-05 § Handoff → Owner-approval flags (✋). |

**Also raised by the ruling:** **Q-05-04** (`docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md`)
— RESOLVED 2026-08-05, both items feasible, no requirement returned to the Product Manager.

## Where the decisions live
- `docs/architecture/adr-012-payload-retention-and-garbage-collection.md` — (i), (ii), and the
  irreversible-operation flag. **Revised for Amendment A** (see its § Revision A).
- `docs/architecture/adr-013-dispatched-output-store.md` — (iii) and the data-model flag.
  **Revised for Amendment A** (see its § Revision A).
- `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md` — **new**: the
  `webhook_events` schema change, header encryption, the `payload_cleaned_at` signal, and the
  enumerated partial supersession of ADR-010.
- `docs/questions/prd-05-q-05-04-header-encryption-and-cleaned-state.md` — the header-consumer
  audit and the erase-vs-delete interaction analysis.
- `docs/plans/plan-05-payload-storage-retention.md` — the composed design, test strategy, and
  the full Owner-approval flag list for (iv).
