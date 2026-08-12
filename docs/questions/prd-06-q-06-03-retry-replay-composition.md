# Question Q-06-03: Retry/replay composition with FIFO, retention holds, and record shape (technical)

- **Status:** RESOLVED — Principal Engineer, 2026-08-12, at #6 technical design
  (`docs/plans/plan-06-retry-replay.md`; ADR-015/016/017, all Proposed — the
  Owner-gated changes item (3) asked to be surfaced are flagged there in full)
- **Raised by:** Product Manager
- **Owner (must answer):** Principal Engineer *(technical feasibility/design —
  the Product Manager does not resolve these; if any PRD-06 requirement proves
  infeasible as stated, it returns to the Product Manager as a requirement
  question, not a silent design change)*
- **Raised:** 2026-08-12
- **Gates:** **Non-blocking** for PRD-06 requirement approval; gates **technical
  design** for feature #6.
- **Source:** `docs/product/prd-06-retry-replay.md` (AC2, AC6, AC11, AC12, AC17,
  AC18); ADR-005 (retry attaches "on the Action's job"; FIFO guardrails
  (c)/(d)); ADR-011 (Impact: "#6 attaches retry/backoff + dead-letter … adds a
  `dead_lettered` `fifo_dispatches` status"; dispatch-by-reference is "the #6
  replay shape"); ADR-012 (Decision 4: "#6 attaches replay/dead-letter holds to
  the same list"); ADR-014 (Decision 7 cleaned-signal guard); ADR-003 (Impact:
  replays "reuse the same record shape (add a nullable `replay_of_id` later)").

## Context
The ADRs left #6-shaped seams open deliberately. This question asks the
Principal Engineer to confirm, at #6 technical design, that PRD-06's
requirements land on those seams as anticipated — and to surface the
Owner-gated changes early.

## Question
Confirm at technical design:

1. **FIFO composition (PRD-06 AC6).** Retry/backoff attaches at the
   ADR-005/ADR-011 seams such that: a retrying FIFO head holds only its own
   proxy's line and only for its backoff; the sweeper/lease mechanism does not
   reap a legitimately-retrying (backoff-waiting) head as orphaned; on attempt
   exhaustion the terminal state (ADR-011's anticipated dead-letter status) is
   excluded from the lowest-pending scan so the line advances; Async retries
   serialize nothing.
2. **Retention holds and the cleaned guard (AC17, AC18).** The
   scheduled-retry and in-flight-replay holds register **additively** on
   ADR-012's named hold list (H0–H4 + #6's), re-asserted inside the erase
   statement per ADR-012 Decision 1; every #6 read path (replay eligibility,
   replay dispatch, the received-events surface) guards on `payload_cleaned_at`
   per ADR-014 Decision 7 — never on `body IS NULL`. Confirm a terminal
   (dead-lettered) delivery holds nothing (AC18) and that no retry policy the
   Q-06-01 caps permit can outlast the retention window.
3. **Record shape and data-model gates (AC2, AC12).** How replay traceability
   is modeled (ADR-003's reserved nullable `replay_of` seam — one record
   stream, replays distinguishable and traceable to the original event) and how
   the per-proxy retry policy persists (new `proxies` columns or otherwise).
   Identify every resulting **data-model change** so the CLAUDE.md Owner
   approval gate is presented at plan time, not discovered at review (the
   ADR-011/ADR-014 precedent).
4. **Replay dispatch path (AC11).** Replay-as-new-dispatch composes with
   ADR-011 Decision 3 dispatch-by-reference (re-dispatch from the retained
   `webhook_events` row through the current pipeline/config) without new
   machinery, and joins a FIFO proxy's line as newest work without disturbing
   the single-advancer's order key.

5. **Reveal mechanism for the masked payload viewer (added 2026-08-12 at the
   design gate; raised by the Designer in
   `docs/design/design-06-retry-replay.md` § Open Questions, recorded here by
   the Product Manager).** Whether the masked payload's real content is
   (a) included in the page's initial payload and merely hidden until Reveal,
   or (b) fetched only on the explicit Reveal action. PRD-06 AC25 (mask by
   default, explicit reveal, all-or-nothing) is satisfied either way; the
   Designer recommends (b) as the stronger defense-in-depth posture (content
   never present client-side unless explicitly requested). The mechanism is
   the Principal Engineer's call at technical design; any read path chosen
   remains bound by PRD-05 AC16 and PRD-06 AC14/AC22.

Mechanism, scheduling, and storage choices are the Principal Engineer's; none
are resolved here.

## Impact if unresolved
Does not block PRD-06 approval. Blocks #6 technical design certification; item
(3) in particular determines which Owner gates the plan must carry.

## Answer
**Principal Engineer, 2026-08-12.** All four seams land as the ADRs anticipated, with one
deliberate deviation (the `dead_lettered` status is **not** adopted — see (1)). Full design:
`docs/plans/plan-06-retry-replay.md`; decisions of record: **ADR-015** (retry mechanism),
**ADR-016** (FIFO composition; partial supersession of ADR-011), **ADR-017** (replay dispatch +
payload read surface) — all **Proposed, awaiting Project Owner approval**. No PRD-06 requirement
proved infeasible as stated; nothing returns to the Product Manager.

1. **FIFO composition (AC6) — confirmed.** Retry attaches at the ADR-005/011 seams via a new
   per-dispatch `deliveries` state entity (ADR-015) and one new `fifo_dispatches` status,
   **`awaiting_retry`** (ADR-016): the advancer, finding non-terminal deliveries after its run,
   transitions its claim `claimed → awaiting_retry` (lease cleared) and does not self-dispatch —
   the head holds **only its own proxy's line**, exactly for its backoff. The sweeper **cannot**
   reap a legitimately-retrying head by construction: its reaper touches only `claimed` rows
   past a lease, and `awaiting_retry` carries no lease. On exhaustion the head **settles** —
   ADR-011's anticipated `dead_lettered` status is deliberately **not adopted** (event-level
   dead-letter is ambiguous under fan-out; it duplicates `deliveries.status = 'failed'`, the
   AC4 terminal fact; and under ADR-012's H2 a never-settling status would immortalize the
   payload, violating AC18). `settled` is already excluded from the lowest-pending scan, so the
   line advances — the PRD-04 AC10 bound closes. Async retries are per-delivery **delayed,
   by-reference jobs** sharing no key, lock, or line: they serialize nothing.
2. **Retention holds and the cleaned guard (AC17, AC18) — confirmed.** One new hold, **H5**,
   registers additively on ADR-012's named list *inside the shared `applyHolds()` builder*, so
   it is re-asserted in the erase `UPDATE`'s own `WHERE` per ADR-012 Decision 1 with no extra
   work: an event is held while any delivery is `retrying`, or `pending` within the existing
   dispatch horizon (the H4 shape, so a lost first-attempt job cannot make a payload immortal).
   A **terminal (exhausted or succeeded) delivery holds nothing** — the next GC pass erases
   normally (AC18). Every #6 read/dispatch path guards on **`payload_cleaned_at`**, never
   `body IS NULL` (ADR-014 Decision 7): the list/detail/payload endpoints via the
   `StoredPayloadState` mapping; the replay endpoint via an **in-transaction `lockForUpdate`
   re-check** before inserting delivery rows (race-free against the GC compare-and-set); the
   retry executor via an explicit guard that terminalizes cleanly with zero sends; plus the
   existing pipeline-entry guard. The Q-06-01 caps bound every expressible schedule at
   **≈ 32.6 h** worst case — two orders of magnitude inside the 30-day window; a guard test
   pins `RetryPolicy::worstCaseSpan()` against `RetentionPolicy`'s window.
3. **Record shape and data-model gates (AC2, AC12).** Replay traceability lands
   **structurally, one grain up from ADR-003's sketch**: a new `deliveries` table (one row per
   dispatch × destination) carries `kind ∈ {original, replay}` (explicit, never inferred),
   `dispatch_uuid` (grouping one replay action's fan-out), and `webhook_event_id` (trace to the
   original event); attempts chain via a new `delivery_id` FK. One record stream — no
   `replay_of_id` attempt column is needed, and attempt rows keep their ADR-003 shape. The
   per-proxy retry policy persists as **two nullable `proxies` columns**
   (`retry_attempt_limit`, `retry_backoff_strategy`; NULL = system default; always NULL on
   simple-mode proxies), resolved solely by `App\Services\RetryPolicy`. **Data-model changes
   carrying the CLAUDE.md Owner gate — surfaced at plan time, all flagged in plan-06's Handoff:**
   (a) new `deliveries` table; (b) the two `proxies` columns; (c) `delivery_attempts` +
   `delivery_id` and the **replacement** of the ADR-011 unique index with
   `UNIQUE(delivery_id, attempt_number)` (replay reuses attempt 1 per `(ingest_id,
   destination_id)`); (d) `fifo_dispatches` + `dispatch_uuid` (UNIQUE, replacing
   `UNIQUE(webhook_event_id)`) and the appended `awaiting_retry` enum value. Items (c)/(d)
   partially supersede three enumerated ADR-011 positions — carried by ADR-016 as the
   superseding instrument, per the ADR-014 precedent.
4. **Replay dispatch path (AC11) — confirmed, no new machinery.** A replay is
   `ProcessIngestedWebhook::dispatch($ingestId, $replayUuid)` — the same by-reference entry
   rebuilding the context from the retained `webhook_events` row, the same pipeline, the same
   steps — with the dispatch scoped by pre-created `deliveries` rows for the chosen
   destinations (`DeliverStep` iterates the dispatch's rows). On a FIFO proxy the replay is one
   more ordering row whose fresh **row `id` is the order key** (ADR-016) — it joins the line as
   the newest work without disturbing the single-advancer's claim/lease/scan mechanics (only
   the scan's ORDER BY column changes, provably order-identical for all capture-created rows).
   The upstream response path is never traversed (ADR-004 preserved structurally).
5. **Reveal mechanism (AC25) — option (b), fetch-on-reveal,** adopting the Designer's
   recommendation (ADR-017 Decision 6). The Inertia pages never receive `body`/`headers` in
   props; Reveal fetches `GET /proxies/{proxy}/events/{event}/payload`, gated by the existing
   proxy **read** permission (PRD-05 AC16; no distinct reveal permission — AC14/AC22),
   responding `text/plain; charset=utf-8` + `X-Content-Type-Options: nosniff` +
   `Cache-Control: no-store, private`, rendered client-side as text only, access-logged
   identifiers-only, **410 Gone** for a cleaned event (lifecycle, never error) and 404 for
   never-captured. Grounds: content is never client-resident without an explicit user action
   (AC25's intent, strengthened); captured bodies run to the ADR-006 cap and must not ship in
   every page visit; and content egress concentrates on one auditable endpoint for #10 to build
   on.
