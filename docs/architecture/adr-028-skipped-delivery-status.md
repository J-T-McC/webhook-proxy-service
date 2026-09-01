# ADR-028: `Skipped` as a terminal delivery status

- **Status:** **Accepted — Project Owner, 2026-08-31.**
- **Date:** 2026-08-31
- **Author:** Principal Engineer
- **Feature:** roadmap item #18, destination validation
- **Amends:** **ADR-015 Decision 1**, which defines the `deliveries.status` lifecycle as
  `pending` / `retrying` / `succeeded` / `failed` with the last two terminal. That enumeration gains
  a fifth case. Nothing else in ADR-015 changes: transitions are still compare-and-set keyed on the
  prior status, and a zero-row CAS still means another settler won.

## Question

PRD-18 AC8 requires the delivery gate to hold at the worker as well as at delivery-row creation,
because a destination can lose validated state after its row exists — a URL edit under AC5, or a
7-day challenge expiring while the delivery waited on a retry backoff.

AC11 requires that a skip is **not** a delivery failure: it creates no attempt record and counts as
nothing in item #11's measures. The existing lifecycle has no status that means "resolved, nobody
was asked". Every terminal option available said something untrue.

## Decision

**Add `DeliveryStatus::Skipped`, terminal.** A delivery whose destination is not validated at send
time transitions `pending`/`retrying` → `skipped` by the same compare-and-set every other transition
uses. No `delivery_attempts` row is written, because no attempt is made. No `DeliveryExhausted` or
`DeliveryFailed` event fires.

`isTerminal()` returns true for it, which is what lets the FIFO completion check settle the line
rather than holding it — the check counts non-terminal deliveries, and a skipped one must not be
among them.

## Alternatives

**Mark the delivery `failed`.** Rejected: it contradicts AC11 directly. It would count against the
success rate in `DeliveryStatistics`, fire `DeliveryExhausted`, and show the member a terminal
failure for a destination that was never contacted — inviting them to debug a delivery problem that
does not exist.

**Leave the delivery `pending`.** Rejected: `pending` is non-terminal, so the FIFO completion check
would hold the line behind a delivery that can never settle. This is precisely the parked-queue
failure AC10 exists to prevent and that Q-18-01 item 2 was raised to avoid.

**Delete the delivery row.** Rejected: it destroys the record that the event was processed and the
destination was considered, and it would race the compare-and-set transitions other settlers may be
running against the same row.

**Do nothing and rely on the queue-check alone.** Rejected: the row-creation gate cannot see a state
change that happens after the row exists, which is the entire case this decision covers.

## Reasoning

The status column already carries the answer to "what happened to this delivery", and "nobody was
asked" is a real answer that was missing from the vocabulary. Encoding it as a distinct terminal
state keeps every existing mechanism correct without special cases: the FIFO check counts
non-terminal rows and skipped is terminal, so the line advances; `DeliveryStatistics` filters
positively on `succeeded` and `failed`, so skipped is excluded from both the numerator and the
denominator of every rate without a single query changing.

The narrowness of the case is not an argument against naming it. It is rare precisely because the
row-creation gate catches almost everything, which means when it does occur it will be confusing —
and a status that says what happened is the cheapest possible explanation.

## Impact

- **`deliveries.status` gains `skipped`.** An enum column change on a table holding live data; no
  existing row is rewritten, since nothing was skipped before this shipped.
- **`DeliveryStatistics` needs no change.** Its filters are positive, so skipped deliveries are
  absent from success and failure rates alike (PRD-18 AC42).
- **The frontend gains a badge state.** `resources/js/data/proxyDeliveryStates.ts` is the declared
  single source of truth for per-destination status display and must stay in sync with the PHP enum.
- **No event fires for a skip.** `DeliveryExhausted` and `DeliveryFailed` both keep meaning what
  they meant; anything subscribing to them is unaffected.
