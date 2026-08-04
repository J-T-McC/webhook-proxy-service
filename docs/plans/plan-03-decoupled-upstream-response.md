# Technical Plan: Decoupled upstream response (with always-on raw capture) — item #3

- **Status:** Approved (Principal-Engineer self-certified) — **except** the ADR-010
  data-model / security decision, which is **flagged for Project Owner approval**
  (see Owner-approval flags). Sections depending on ADR-010 are contingent on that
  approval.
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-03-decoupled-upstream-response.md` (Approved — Project Owner, 2026-08-03)
- **Approved by / date:** Principal Engineer, 2026-08-03 (ADR-010 pending Owner)

## Overview
Item #3 extends the item-#1 ingest spine with two orthogonal changes, both landing
in the existing ingest hot path and the existing proxy management form — no new
surface. **(1) User-defined upstream response:** two new nullable `proxies` columns
(`response_status`, `response_body`) are read by the already-present
`ResponseResolver` (ADR-004) to return a config-driven 2xx status + body; when
unconfigured (NULL) the resolver keeps returning `202 Accepted`, so every existing
#1 proxy inherits the default with no backfill (AC1–AC4, AC3 no-surprise). **(2)
Always-on durable raw capture:** a new raw-only immutable entity `webhook_events`
stores each incoming payload; the `IngestController` writes it **synchronously,
before the response is returned and before pipeline dispatch**, in both proxy modes,
returning `HTTP 500` if the write fails (AC5–AC9, ADR-010). Delivery stays
fire-and-forget (#1); the response never reads delivery outcome (ADR-004) and
capture never touches the payload-free attempt records (ADR-003). The `body` carries the
`'body' => 'encrypted'` cast from #3 (encrypt at rest immediately, Owner decision
2026-08-04 / ADR-010 Amendment B). Both raw capture and the fan-out
`delivery_attempts` share the one `ingest_id` correlator, so #6 replay, #5 retention,
#10's full sensitive-data policy (headers, redaction, key policy), and #11 analytics
attach later without re-modelling (AC8, AC10, AC11). This plan invents no requirements; body
size/content-type constraints deferred to the PE (Q-03-04) are set below.

## Architecture

The seams are already in place from #1 (ADR-004 `ResponseResolver`, ADR-005 dispatch
timing, ADR-003 attempt records). #3 fills the `ResponseResolver` body and inserts a
pre-dispatch capture step; it does **not** re-shape the pipeline.

**Ingest hot path (`IngestController`), revised order (AC5/AC6, ADR-004/005/010):**
1. Resolve proxy by SHA-256 token hash; `abort(404)` on miss (unchanged, ADR-006).
2. Mint one `ingest_id` (uuid); read the raw body.
3. **Capture** the raw payload durably via `WebhookEventCapture` (synchronous,
   committed). On any failure → `abort(500)` and dispatch nothing (AC6).
4. Resolve the response from proxy config via `ResponseResolver` (AC1–AC4; never
   reads delivery outcome or attempt records — ADR-004).
5. Dispatch `ProcessIngestedWebhook::run($ctx)` (the same `ingest_id`); `::run`
   inline at #3, `::dispatch` at #4 — capture already committed before this point.
6. Return the resolved response — only reached when capture succeeded (AC5).

**Why capture is here, not in the pipeline (ADR-010).** `ProcessIngestedWebhook`
goes async at #4 (ADR-005), so a capture step inside it would run *after* the
response, breaking AC5. Capture is therefore a first-class pre-dispatch step in the
handler, mode-independent. The commented `CaptureRawStep // #5` in
`PipelineFactory`'s enhanced-only front stage is superseded for raw capture (see
Implementation Notes); #5's *dispatched-output* capture may still be a pipeline step.

**No parallel path (AC9, ADR-003).** `webhook_events` (payload home) and
`delivery_attempts` (payload-free outcome home) are joined only by the shared
`ingest_id`. Capture neither reads, replaces, nor duplicates attempt records; the
response never reads either.

## Data Model

Two changes, both requiring Owner approval as a data-model change (see flags). MySQL
8.0 / InnoDB. Migrations are additive and reversible (`down()` for local dev only;
forward-only in prod per architecture standards). **No destructive backfills.**

### `proxies` — add response configuration (AC1–AC4; executes ADR-004)
New migration `add_response_config_to_proxies_table`:

| Column | Type | Notes |
|---|---|---|
| `response_status` | UNSIGNED SMALLINT, **nullable**, no default | NULL = unconfigured → resolver returns `202`. When set, constrained to 2xx by validation (AC4). |
| `response_body` | TEXT, **nullable**, no default | NULL = no body. Size-capped by validation (see Validation). |

- **Existing-proxy inheritance (AC3, no surprise).** Both columns are nullable with
  **no schema default**; existing #1 rows are NULL = unconfigured, and the resolver
  maps NULL → `202 Accepted`. The 202 default is owned by the resolver (single
  source, ADR-004), not written into the schema, so it can never drift from ADR-004.
  No backfill (aligns with the ADR-009 no-fabricate-historical-data precedent).
- `#[Fillable]` on `Proxy` extends to `response_status`, `response_body`. Casts:
  `'response_status' => 'integer'` (nullable). No cast needed on `response_body`.

### `webhook_events` — raw-only immutable capture (AC5–AC9, ADR-010)
New table + `WebhookEvent` model. **Raw-only and immutable by construction** — no
dispatched/derived output, no retention/GC columns, no soft delete (retention/GC is
#5). Immutable fact table, so FKs use the default (restrict) `constrained()` like
`delivery_attempts`; `team_id`/`proxy_id` are set explicitly on the (team-unscoped)
ingest path.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → teams, `constrained()` (restrict) | team-scoped/queryable for #5/#6/#11; set explicitly from `$proxy->team_id` (no current team on ingest). |
| `proxy_id` | FK → proxies, `constrained()` (restrict) | the receiving proxy. |
| `ingest_id` | uuid, **UNIQUE** | the **same** correlator carried by `delivery_attempts` (ADR-003); one row per received webhook. UNIQUE also makes capture idempotent under a future at-least-once #4 replay of the ingest action. |
| `method` | string(7) | POST\|PUT as received — for faithful #6 replay. |
| `headers` | JSON | inbound headers as received — for faithful #6 replay / #8 mapping. Sensitive-header handling is #10. |
| `content_type` | string, nullable | denormalized inbound Content-Type; convenience for #6/#8/#11. |
| `body` | **LONGBLOB** | raw received bytes, immutable (R2). Carries the `'body' => 'encrypted'` cast at **#3** (ADR-010 Amendment B): the cast serializes a base64-JSON envelope that LONGBLOB holds correctly — binary-safe, ~4 GiB capacity absorbs the ~35% encryption overhead even at the ADR-006 ingest body cap. No column-type change. Sized to hold the ADR-006 ingest body cap (see Risks). |
| `byte_size` | UNSIGNED INT | captured body size; cheap now, useful for #5 quota / #11 volume metrics. |
| `received_at` | timestamp | capture time. |
| `created_at` / `updated_at` | timestamps | `created_at` is the key #5's 30-day GC will filter on (AC11). |
| indexes | `UNIQUE(ingest_id)`, `(team_id, created_at)`, `(proxy_id, created_at)` | correlation + #5 GC / #11 read patterns; cheap now. |

- **Model.** `WebhookEvent` uses `BelongsToCurrentTeam` (for future team-scoped
  #5/#6/#11 reads), `belongsTo(Proxy)`; casts `body => 'encrypted'` (ADR-010
  Amendment B — encrypt at rest at #3; `headers` stay plaintext until #10),
  `headers => 'array'`, `received_at => 'datetime'`, `byte_size => 'integer'`.
  `byte_size` records the **plaintext** received size (set before the cast encrypts).
  `#[Fillable]`: `team_id`, `proxy_id`,
  `ingest_id`, `method`, `headers`, `content_type`, `body`, `byte_size`,
  `received_at`. **No `SoftDeletes`** (retention/GC is #5). **No** dispatched-output
  or response columns.

## API

No route changes. The public ingest endpoint (`POST|PUT /ingest/{token}`) keeps its
guards (`EnsureIngestIsSecure`, `EnforceIngestBodyLimit`, `throttle:ingest`); the
management surface keeps its resource routes.

### Ingest response contract (AC1–AC6, ADR-004)
- **Configured:** `ResponseResolver::resolve($proxy)` returns
  `response($body, $status)` where `$status = $proxy->response_status ?? 202` and
  `$body = $proxy->response_body ?? ''`, with `Content-Type: text/plain; charset=utf-8`
  when a body is present. Any combination resolves gracefully: both set → exactly
  that (AC1); neither set → `202 Accepted`, empty (AC3); only one set → that value +
  the other's default.
- **Resolved from config only (AC2).** The resolver reads proxy columns and nothing
  from delivery outcome or `delivery_attempts`; the handler passes only `$proxy`.
- **Capture-failure 500 (AC6).** A system-emitted `HTTP 500` from the handler when
  the capture write throws — distinct from any user-configured 2xx (the 2xx is only
  reachable after a committed capture). Not produced by the resolver.

### Management form props (minor UI on the existing create/edit form)
`ProxyResource` gains `response_status` (int|null) and `response_body` (string|null)
so the shared Create/Edit form pre-fills them. `Proxies/Create` and `Proxies/Edit`
add a **status-code input** and a **response-body input** (PRD UX Direction — two
fields, existing form patterns). The form copy must convey that this response is
returned **immediately and independently of delivery** (an acknowledgement
contract, not a delivery report). This stays within standard field additions; if the
Owner/PE surfaces a genuinely new UI need (e.g. a content-type selector — see
Validation), route back to the Product Manager for a Designer handoff rather than
expanding UI here.

## Services
- **`ResponseResolver`** (existing) — replace the fixed-202 body with the
  config-driven resolution above (AC1–AC4). Still pure, HTTP-knowledge-free, reads
  only the proxy. This is the *only* change to the response path (ADR-004 holds).
- **`WebhookEventCapture`** (new Service, `App\Services`) — `capture(Proxy $proxy,
  string $ingestId, string $method, array $headers, string $rawBody): WebhookEvent`.
  Writes one committed `webhook_events` row (explicit `team_id` from the proxy,
  `byte_size = strlen($rawBody)`, `content_type` derived from `$headers`,
  `received_at = now()`). **A Service, not an Action, deliberately** — it must never
  be `::dispatch`ed; capture is inherently synchronous and pre-dispatch (ADR-010).
- **`IngestController`** (existing) — insert the capture call + 500 handling in the
  revised order above; mint `ingest_id` once and pass it to both the capture and the
  `PipelineContext`.
- **`ProcessIngestedWebhook` / `PipelineFactory` / `DeliverStep` / delivery** —
  **unchanged.** Delivery stays fire-and-forget (#1); the payload still flows
  in-memory via `PipelineContext` (`webhook_events` is the durable replay source #6
  reads later, not a read dependency of #3 delivery).

## Validation

Response configuration is optional and server-authoritative via the existing
`StoreProxyRequest` / `UpdateProxyRequest` (both get the same rules):

- `response_status` — `['nullable','integer','between:200,299']`. A non-2xx value is
  **rejected** (AC4); NULL/absent is allowed (AC3, unconfigured).
- `response_body` — `['nullable','string','max:<cap>']` where the cap is
  `config('ingest.response_body_max_bytes')`, **default 8192 (8 KiB)** — see below.
- Existing `name` / `mode` / `destinations.*` rules unchanged.

**Body size / content-type constraints (Q-03-04, set by the PE):**
- **Size cap: 8 KiB (8,192 bytes), config-driven** (`ingest.response_body_max_bytes`,
  new key, same config-driven pattern as ADR-006's caps). Rationale: the configured
  response is a fixed **acknowledgement** (a token, a short JSON ack, or a
  challenge-echo like Slack/Facebook verification), never a data channel; 8 KiB is
  ample and bounds abuse/row size. TEXT column comfortably holds it.
- **Content-Type: `text/plain; charset=utf-8`** for any configured body; the empty
  202 default keeps current (no body) behavior. **No content-type field is added** —
  the PRD scoped the UI to two fields (status + body), and adding a selector is new
  UI. This means a JSON ack is returned labelled `text/plain`; upstream senders
  almost always gate only on the 2xx status, so this is a deliberate, low-risk
  constraint. If a provider is later found to require a JSON/other content-type ack,
  that is a scoped follow-up via a Product-Manager/Designer handoff, **not** silently
  added here.

## Risks
1. **Synchronous capture adds one DB insert to the ingest hot path before the
   response.** Bounded, single indexed insert; acceptable posture (no throughput/
   latency targets — V8 unset), same tradeoff class as plan-01 Risk 1. #4's async
   dispatch does not move capture (it must stay pre-response), so capture latency is
   inherent to the AC5 guarantee. **Non-blocking.**
2. **`headers` plaintext at rest until #10; body encrypted at #3 under a
   key-rotation guard (ADR-010 Amendment B).** Per the Owner decision (2026-08-04),
   `webhook_events.body` gets the `'body' => 'encrypted'` cast at **#3** — bodies are
   encrypted at rest immediately. Two residual items remain: (a) inbound **`headers`
   stay plaintext at rest until #10** (Owner accepts — body is the priority; headers
   and the rest of the sensitive-data policy are #10's scope); and (b) a **binding
   operational guard** — Laravel's `encrypted` cast decrypts only with keys in
   `APP_PREVIOUS_KEYS` and never re-encrypts existing rows automatically, so **a prior
   `APP_KEY` must never be dropped from `APP_PREVIOUS_KEYS` until a future
   re-encryption job has rehashed every row to the current key.** Dropping a still-in-
   use key = permanently undecryptable payloads (the exact data-loss #3 prevents). The
   re-encryption artisan command + queued job is an accepted **future** task, **not
   built at #3**. **Owner-flagged (security).**
3. **Unbounded growth until #5 GC + large-body interaction (AC11 accepted).** Every
   webhook is now stored, in both modes, with no GC until #5. The ADR-006 ingest
   body cap is a deliberately high placeholder (50 MB), so a `webhook_events` row can
   in principle be very large; `LONGBLOB` accommodates the cap, but storage growth is
   real. AC11 accepts this interim trade-off; **recommend the ADR-006 cap be
   risk-tuned before public MVP** (already an open ADR-006 item). **Non-blocking flag.**
4. **Capture failure returns 500 → the proxy is unavailable during a DB outage.**
   This is the correct trade per AC6 (a sender that gets a 500 retries; we never
   accept-and-lose). Called out so it is a chosen behavior, not a surprise. **Non-blocking.**
5. **`ingest_id` uniqueness under future #4 at-least-once dispatch.** `UNIQUE(ingest_id)`
   makes capture idempotent if the ingest action is ever re-run for the same event;
   at #3 (one synchronous capture per request) collisions cannot occur. Recorded for
   #4. **Non-blocking.**

## Dependencies
- **No new packages.** Uses Eloquent, Laravel migrations, `Http` client, existing
  config pattern, `lorisleiva/laravel-actions` (already adopted, ADR-007). Stays
  within `docs/stack/stack.md` — **no stack change**.
- **ADR-010** (Proposed, this plan) — raw-capture entity + pre-dispatch placement;
  gates the capture half of the plan.
- **ADR-004** (Accepted) — response resolved from config, before/independent of
  delivery; this plan executes its `response_status`/`response_body` provision.
- **ADR-003** (Accepted) — payload-free attempt records; capture must not create a
  parallel path (satisfied via the shared `ingest_id`).
- **ADR-005** (Accepted) — dispatch async at #4; the reason capture is pre-dispatch.
- **ADR-006** (Accepted) — ingest token lookup + body cap (unchanged; the body cap
  bounds captured row size).
- PRD-03 (Approved 2026-08-03).

## Implementation Notes
- **Order in `IngestController` is load-bearing:** capture (committed) → resolve →
  dispatch → return. Never dispatch or return the 2xx before the capture commits
  (AC5/AC6). When #4 makes `ProcessIngestedWebhook` async, still dispatch only
  **after** the capture write commits (plain insert auto-commits; if wrapped in a
  transaction, dispatch `afterCommit`).
- Mint **one `ingest_id`** per request and pass it to both `WebhookEventCapture` and
  `PipelineContext` — the single correlator shared with `delivery_attempts` (AC9). Do
  not introduce a second key.
- `webhook_events` is **raw-only and immutable**: never write dispatched/derived
  output or update a captured row here (that is #5's separate store). No
  `SoftDeletes`, no retention columns (that is #5).
- Set `team_id`/`proxy_id` **explicitly** from the resolved proxy — the ingest path
  is team-unscoped (no current team), mirroring `DeliverToDestination`.
- Capture-failure path: catch the write `Throwable`, `report()` it, `abort(500)` —
  do **not** leak internals, do **not** log the raw body/token, and dispatch nothing.
- `ResponseResolver` reads only `$proxy` columns — it must **never** read delivery
  outcome or `delivery_attempts` (ADR-004). Keep it HTTP-knowledge-free and pure.
- Update the `PipelineFactory` `CaptureRawStep // #5` comment to point at the
  handler-level `WebhookEventCapture` (raw capture moved out of the pipeline per
  ADR-010); leave #5's dispatched-output capture note as a pipeline step.
- Keep the plaintext token and raw body out of logs/APM/analytics on the ingest path
  (ADR-006 addendum) — the raw body now lives only in `webhook_events`.
- Pint (`composer lint`) + PHPStan L7 (`composer types:check`) green; short
  Conventional-Commit messages with context in list items (CLAUDE.md).

## Test strategy
Backend PHPUnit (`./vendor/bin/sail test`), `Http::fake()` for delivery. Map to ACs:

**Response resolution (feature, ingest path):**
- Configured status + body → the ingest response is exactly that status and body
  (AC1); with `Content-Type: text/plain; charset=utf-8`.
- `response_status` NULL (or absent) → `202 Accepted`, empty body; an existing/#1
  proxy with no config inherits `202` (AC3, no surprise).
- Response is identical regardless of delivery outcome — fake one destination to
  500/throw; the ingest status/body are unchanged (AC2, ADR-004). Assert the resolver
  reads no attempt records.
- 2xx validation: a non-2xx `response_status` is rejected on store/update with a
  `response_status` error; NULL accepted (AC4). Body over the 8 KiB cap rejected.

**Capture (feature, ingest path):**
- A valid ingest writes exactly one `webhook_events` row with the raw body,
  `method`, `headers`, `content_type`, `byte_size`, and the **same `ingest_id`** as
  the request's `delivery_attempts` (AC5, AC8, AC9). Assert in **both** simple and
  enhanced mode (AC7).
- **Ordering (AC5):** the row exists before the response is returned — assert capture
  is committed (e.g. simulate a post-capture failure / assert row present on a
  successful 2xx). Assert `ProcessIngestedWebhook` is not dispatched when capture
  fails (below).
- **Capture failure → 500 (AC6):** force the capture write to throw (mock/DB error);
  assert `HTTP 500`, **no** `webhook_events` row committed, **no** delivery attempted
  (`Http::assertNothingSent()` / no `delivery_attempts`), and the configured 2xx is
  **not** returned.
- **No parallel path (AC9, ADR-003):** `delivery_attempts` remain payload-free (no
  body column touched); capture neither reads nor writes attempt records; both share
  `ingest_id`.
- **Raw immutability (AC8):** the captured `body` equals the received bytes and is
  never mutated by delivery (delivery reads `PipelineContext.payload`, not the row).

**Management form (feature, Inertia):**
- Create/update persist `response_status`/`response_body`; edit pre-fills them;
  `ProxyResource` exposes them. Existing proxies show NULL (unconfigured).

**Unit:**
- `ResponseResolver::resolve()` for the four config combinations (both/neither/each).
- `WebhookEventCapture::capture()` builds the row with explicit `team_id`,
  `byte_size`, derived `content_type`, shared `ingest_id`.

## Handoff
- **Inputs:** Approved PRD-03; ADR-003/004/005/006 (Accepted); ADR-010 (Proposed,
  this plan); plan-01 + current ingest code (`IngestController`, `ResponseResolver`,
  `ProcessIngestedWebhook`, `PipelineFactory`, `Proxy`, `DeliverToDestination`).
- **Outputs:** this plan; ADR-010.
- **Dependencies:** none new; within-stack. ADR-010 must be Owner-approved before the
  capture + data-model work starts.
- **Outstanding Questions:** none block the response half. **Q-03-05 resolved** in
  this plan + ADR-010 (see below). The only gate is **Owner approval of ADR-010**
  (data-model + security), flagged below.
- **Owner-approval flags (✋):**
  1. **ADR-010** — new `webhook_events` table + capture placement (data-model change;
     stores raw payloads at rest). Requires Owner approval.
  2. **New `proxies` columns** `response_status` / `response_body` — data-model change
     to an existing table (pre-decided in principle by ADR-004; nullable + no
     backfill, existing rows inherit `202`). Approve alongside ADR-010.
  3. **Security:** raw **bodies** are encrypted at rest at #3 via the
     `'body' => 'encrypted'` cast (Owner decision 2026-08-04, ADR-010 Amendment B).
     Two residuals for the Owner to acknowledge: inbound **`headers` remain plaintext
     until #10**, and the **binding `APP_PREVIOUS_KEYS` key-rotation guard** (never drop
     a prior `APP_KEY` until the future re-encryption job has rehashed every row).
  No new dependencies and no stack change.
- **Next Agent:** Task Planner (after Owner approval of ADR-010). The one UI change
  (two form fields on the existing create/edit form) uses standard field patterns;
  escalate to the Product Manager only if a content-type selector or other new UI is
  requested.
