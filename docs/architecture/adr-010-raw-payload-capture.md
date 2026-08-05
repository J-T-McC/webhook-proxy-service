# ADR-010: Durable raw-payload capture — storage entity and pre-dispatch placement

- **Status:** Accepted (Owner, 2026-08-04)
- **Author:** Principal Engineer
- **Date:** 2026-08-03
- **Feature:** prd-03-decoupled-upstream-response (pulls the capture half of #5 forward; serves #5, #6, #10, #11)

## Question
Feature #3 (Path A, Owner scope decision 2026-08-03) requires every incoming
webhook's raw payload to be **durably persisted before** the upstream response is
returned, in **both** proxy modes (R2 overridden). Two coupled decisions follow,
neither settled by an existing ADR: **(1)** what entity stores the raw payload —
consistent with #5's raw/dispatched separation (R2 build-ahead), ADR-003's
payload-free attempt records (no parallel path), and #10's later at-rest
encryption — and **(2)** *where in the request lifecycle* the capture write runs,
given ADR-005 will move pipeline dispatch async at #4.

## Decision
**(1) A dedicated, raw-only immutable entity `webhook_events`** stores the raw
incoming payload (bytes + method + inbound headers + content-type + byte size),
keyed by the **same `ingest_id`** the fan-out `delivery_attempts` already carry
(ADR-003). It holds **no dispatched/derived output** and **no retention/GC state**:
raw-only *by construction* (mirroring how `delivery_attempts` is payload-free by
construction), so #5 adds a separate dispatched-output store and its retention
lifecycle without re-modelling this table. The `'body' => 'encrypted'` cast lands at
**#3** per **Amendment B** (Owner decision 2026-08-04) — an additive cast, no shape
change; #10 layers the full sensitive-data policy on top (see Amendment B).

**(2) The capture write runs synchronously in the ingest handler, before the
response is returned and before pipeline dispatch — never as a pipeline step.**
Because ADR-005 flips `ProcessIngestedWebhook` from `::run` to `::dispatch` at #4,
any step inside that pipeline would execute *after* the response once dispatch is
async, breaking the capture-before-response guarantee (AC5). Capture is therefore a
first-class pre-dispatch action in `IngestController`. A capture-write failure
aborts with **HTTP 500** and dispatches nothing (AC6). This **supersedes** the
commented `CaptureRawStep // #5` in `PipelineFactory`'s enhanced-only front stage
as the home for *raw* capture; #5's *dispatched-output* capture may still be a
pipeline step (it is part of dispatch and need not precede the response).

## Alternatives
- **Raw capture as an (enhanced-only) pipeline step (the original build-ahead comment)** — breaks capture-before-response once #4 makes the pipeline async, and R2's override makes it mode-independent; rejected for raw capture.
- **Store the raw body on `delivery_attempts`** — violates ADR-003's payload-free invariant, couples a per-received-event payload to per-destination records (N copies), and reopens the sensitive-data concern in the analytics path; rejected.
- **A single `payloads` table discriminated by `kind` (raw|dispatched) now** — pulls #5's dispatched-output modelling forward prematurely and blurs the raw/immutable boundary; a raw-only table keeps separation structural and defers #5's shape to #5; rejected.
- **Add the raw body as a nullable column on `proxies`/reuse config** — a per-request fact is not proxy configuration; rejected.
- **Fire-and-forget/queued capture** — cannot guarantee the payload is committed before the 2xx (AC5) and would lose the event on worker failure — the exact data-loss gap #3 closes; rejected.
- **Add the `'body' => 'encrypted'` cast now at #3 (encrypt-at-rest immediately)** —
  **[ADOPTED — superseded this bullet's original "rejected" position via Amendment B,
  Owner decision 2026-08-04.]** The original analysis rejected this as the default; the
  Owner has since decided the confidentiality gain outweighs the concerns. For the
  record, those concerns and their resolution: (a) it *picks* the Laravel app-layer /
  APP_KEY scheme before #10 owns key management, rotation, per-team/tier policy,
  field-level obfuscation, and verification-token standards (V2) — Owner accepts this
  lock-in as a non-concern, since re-keying to any scheme later is a full backfill
  regardless and is an accepted FUTURE task (artisan command + queued re-encryption
  job, **not built at #3** — see Amendment B); (b) it covers only `body`, leaving
  `headers` (which #10 owns) plaintext — Owner accepts body as the priority floor now,
  headers deferred to #10; (c) it couples payload durability to APP_KEY lifecycle
  because Laravel encrypts with the current key and only *decrypts* with
  `APP_PREVIOUS_KEYS`, never re-encrypting existing rows — this is now controlled by the
  binding operational guard in Amendment B (a prior key is never dropped from
  `APP_PREVIOUS_KEYS` until the re-encryption job has rehashed every row to the current
  key).

## Reasoning
- **AC5/AC6 + ADR-005:** the only placement that guarantees "committed before the
  response" and survives the #4 async flip is a synchronous write in the handler,
  ahead of dispatch. Capture failure → 500 (not the configured 2xx) preserves the
  data-loss guarantee: success is never acknowledged for an uncaptured event.
- **AC8/AC9 + ADR-003:** sharing the existing `ingest_id` correlator (not a new
  key) is what makes raw capture and the payload-free attempt records *coexist
  without a parallel path* — one received event, one payload home, one outcome
  home, joined by `ingest_id`. #6 replay reads the raw row; #11 aggregates the
  attempt rows; neither duplicates the other.
- **R2 build-ahead / #5 / #10:** a raw-only, immutable, retention-state-free table
  is the minimal shape that lets #5 attach retention/GC and a dispatched-output
  store, and lets the `body` `encrypted` cast land at #3 (Amendment B) with #10
  layering the rest of the sensitive-data policy — each an additive change, no
  re-model. Faithful `method` + `headers` + `content_type` + raw `body` capture is
  what a faithful #6 replay and #8 mapping later require.

## Impact
- **Easier:** #5 = add a dispatched-output store + GC keyed on `webhook_events.created_at`; #6 = replay re-dispatches from the raw row (join on `ingest_id`), reading the decrypted body transparently via the cast; #10 = layer the remaining sensitive-data policy (headers, field-level obfuscation, per-team/tier key policy, verification-token V2) on top of the #3 body cast — no shape change; #11 = size/volume metrics already present (`byte_size`, captured pre-encryption).
- **Constrained:** `webhook_events` is raw-only and immutable — never store dispatched/derived output or mutate a captured row here (that is #5's separate concern). The ingest handler must capture (committed) **before** dispatching `ProcessIngestedWebhook`; when #4 makes dispatch async, dispatch only after the capture transaction commits.
- **Security-sensitive / Owner flag (updated by Amendment B):** raw request **bodies** are encrypted at rest at #3 via the `'body' => 'encrypted'` cast (Owner decision 2026-08-04). **Inbound `headers` remain plaintext at rest until #10** — the Owner accepts this: body is the priority; header handling (and the rest of the sensitive-data policy) is #10's scope. The at-rest encryption is bound by the operational key-rotation guard in Amendment B.
- **Data-model change / Owner flag:** introduces a new table and (via the plan) two new `proxies` columns; requires Owner approval as a data-model change.
- Supersedes the `CaptureRawStep // #5` placeholder comment in `PipelineFactory` for raw capture; the Senior Developer updates that comment to point at the handler-level capture.

## Amendment A — interim at-rest encryption (2026-08-04, Owner question) — SUPERSEDED

> **SUPERSEDED by Amendment B (Owner decision, 2026-08-04).** Amendment A's position
> ("encryption scheme stays with #10; defer the `'body' => 'encrypted'` cast; offer it
> only as an Owner-electable stopgap conditioned on not rotating APP_KEY") is **no
> longer operative.** The Owner has since decided to add the body cast at #3
> unconditionally. Retained below for history; do not act on it — follow Amendment B.

~~Position unchanged: the **encryption scheme stays with #10**, not #3. The bare
`'body' => 'encrypted'` cast is deliberately *not* added at #3 for the reasons in the
Alternatives bullet — chiefly that it pre-empts #10's key/rotation/field-level design,
is `body`-only (headers stay plaintext until #10 regardless), and binds every captured
payload's readability to APP_KEY lifecycle with no re-encryption command, converting a
confidentiality risk into a data-loss risk on the data #3 must never lose. The Owner
*may* elect to add the cast at #3 as a stopgap, only if paired with a commitment not to
rotate APP_KEY until #10 ships a re-encryption command; absent that, defer to #10.~~

## Amendment B — body encrypted at rest at #3 (2026-08-04, Owner decision)

The Owner has decided to **add the Laravel `'body' => 'encrypted'` cast to the
`webhook_events` body column as part of Feature #3.** Payloads are encrypted at rest
immediately; the earlier "defer to #10" position (Amendment A and the original
Alternatives rejection) is superseded. This reverses the prior deferral to #10.

**Scope of this decision (the floor, not the ceiling):**
- **#3 encrypts the `body` only.** This is the *floor*. **Inbound `headers` remain
  plaintext at rest until #10** — the Owner accepts this explicitly; it is not a silent
  gap. Body is the priority now.
- **#10 is NOT descoped.** #10 still owns the **full** sensitive-data policy:
  field-level obfuscation/redaction, sensitive-header handling, verification tokens V2,
  and per-plan/per-team key policy. #3's body cast is the *minimum*; #10 layers the rest
  on top without re-modelling the table.

**Lock-in is a non-concern (Owner):** committing the Laravel app-layer / APP_KEY scheme
now does not trap us. If the scheme must change or be re-keyed later, a simple **artisan
command + queued job** re-encrypts existing rows. That command/job is an **accepted
FUTURE task — it is NOT built at #3.** #3 ships only the cast.

**CRITICAL operational guard (binding):** Laravel's `encrypted` cast encrypts with the
current `APP_KEY` and **decrypts only with keys present in `APP_PREVIOUS_KEYS`; it never
re-encrypts existing rows automatically.** Therefore:

> **RULE:** A prior `APP_KEY` must **NEVER** be dropped from `APP_PREVIOUS_KEYS` until
> the re-encryption job has rehashed **every existing row** to the current `APP_KEY`.

Dropping a still-in-use key = permanently undecryptable payloads = the exact
accept-and-lose data-loss failure Feature #3 exists to prevent. This guard is mandatory
until the future re-encryption command/job exists and has completed a full pass.

**Column type confirmation:** the `encrypted` cast serializes to a **base64-encoded JSON
envelope** (`{"iv","value","mac","tag"}`, ASCII), larger than the plaintext (base64 +
envelope + block padding, ~35%+ overhead). The specified **`LONGBLOB`** body column
holds this ciphertext correctly — it is binary-safe and its ~4 GiB capacity comfortably
absorbs the expanded envelope even at the ADR-006 ingest body cap. **No column-type
change is required.** (`byte_size` continues to record the *plaintext* received size,
captured before the cast encrypts.)

**In transit / logs unaffected:** TLS still protects transit; the plan already keeps the
raw body and token out of logs/APM/analytics (coding.md never-log list). This decision
closes the at-rest window for `body` specifically.
