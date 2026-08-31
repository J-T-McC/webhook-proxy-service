# M2 — The four enforcement points

Implements plan-18 § Architecture. Answers Q-18-01 items 1 and 2 in code. **This milestone is a
no-op when it lands**, because T3 left every existing destination validated — which is deliberate:
the gate goes live before anything can be unvalidated.

**Do not** put a per-destination check into `ProcessIngestedWebhook`'s pause guard (line 77) or
`AdvanceProxyFifoQueue`'s claim guard (line 80). Those are per-proxy and are the wrong granularity.

## T4 — Gate delivery-row creation

- **Description:** Filter the `foreach ($proxy->destinations as $destination)` loop that creates
  `deliveries` rows to validated destinations only, using T2's scope. No row is created for a
  non-validated destination, so there is no unit of work to skip, hold or park.
- **Dependencies:** T2.
- **Files:** `app/Actions/ProcessIngestedWebhook.php`
- **AC-trace:** PRD-18 AC8 (queue-check), AC10, AC11, AC12.
- **Verify step:** ingest a webhook for a proxy with one validated and one unvalidated destination;
  exactly one `deliveries` row exists.
- **Testing:** in the existing `ProcessIngestedWebhook` test group — one validated and one
  unvalidated destination yields one row; all-unvalidated yields zero rows and **no**
  `delivery_attempts` record; the webhook event is still captured (AC12). Add a FIFO case proving the
  proxy settles as done rather than holding when every destination is unvalidated, and that the next
  event advances — this is the AC10 behaviour the pause work had to avoid and this feature wants.

## T5 — Gate the worker at send time

- **Description:** `DeliverToDestination` re-checks validation state before sending and resolves the
  delivery without an attempt when the destination is no longer validated. Needed because a
  destination can leave the validated state between row creation and send, via a URL edit (AC5) or a
  challenge expiring under a retry backoff.
- **Dependencies:** T2, T4.
- **Files:** `app/Actions/DeliverToDestination.php`
- **AC-trace:** PRD-18 AC8 (dispatch-gate), AC11.
- **Verify step:** create a delivery row, unvalidate its destination, run the job; no HTTP request
  leaves and no `delivery_attempts` row appears.
- **Testing:** in the `DeliverToDestination` group, with `Http::fake()` asserting nothing was sent.

## T6 — Exclude non-validated destinations from the retry sweep

- **Description:** `SweepDueRetries` re-dispatches from existing rows and never passes through T4, so
  it needs its own exclusion — mirroring the `paused_at` exclusion already at line 49.
- **Dependencies:** T2.
- **Files:** `app/Actions/SweepDueRetries.php`
- **AC-trace:** PRD-18 AC9.
- **Verify step:** an overdue retry whose destination is unvalidated is not picked up; one whose
  destination is validated still is.
- **Testing:** in the `SweepDueRetries` group, alongside the existing paused-proxy exclusion test.

## T7 — Refuse replay to a non-validated destination, with the reason

- **Description:** Replay pre-creates its own rows for a chosen subset and bypasses T4 entirely.
  AC9 requires the refusal to be **visible with a reason**, not a silent drop, in the same manner
  #15 makes replay unavailable while paused. Refuse in the controller and its policy.
- **Dependencies:** T2.
- **Files:** `app/Http/Controllers/ProxyEventReplayController.php`,
  `app/Policies/DestinationPolicy.php` (or the existing policy governing replay)
- **AC-trace:** PRD-18 AC9.
- **Verify step:** attempt a replay targeting an unvalidated destination; it is refused and the
  response carries the reason.
- **Testing:** in the replay controller's test group — refusal, the reason present, and that a mixed
  selection does not partially dispatch.
