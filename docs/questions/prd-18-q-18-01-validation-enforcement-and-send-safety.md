# Q-18-01: Where is validation enforced, how is skipped work resolved, and how is the validation send made safe?

- **Feature:** destination validation (item #18)
- **Requested By:** Product Manager (raised by `docs/product/prd-18-destination-validation.md`)
- **Directed To:** **Principal Engineer**
- **Required By:** Before `plan-18`. **Non-blocking for requirement approval** of PRD-18 — the
  criteria stand whatever the mechanisms turn out to be — but nothing can be built until these are
  ruled.
- **Priority:** High. Items 1 and 2 each independently defeat a criterion the whole feature rests
  on, and items 3 and 4 are the two ways the feature could become the vulnerability it exists to
  close.
- **Status:** **ANSWERED by the Principal Engineer, 2026-08-31.** See § Answers at the foot of this
  document. Item 1 returned a finding that contradicts the question's own premise, and item 4
  returned a finding that contradicts AC23 — the latter is escalated to the Product Manager as
  `docs/questions/prd-18-q-18-02-ac23-log-guarantee.md`.

## Why these are questions rather than requirements

PRD-18 states four product positions and deliberately stops there. Each below names a mechanism the
Product Manager can see must exist but must not choose.

## The four items

### 1. The two enforcement points (AC8)

**AC8 requires that a non-Validated destination receives nothing, enforced at both the queue-check
and the dispatch-gate** — the same two points where pause enforcement already holds. The Project
Owner ruled that a block in the interface alone is not a block, because the fan-out path is reached
without the interface.

The Product Manager is naming the requirement, not locating the points. **Identify both, confirm
they are the right two, and confirm no third path can start work on a destination.** #15's pause
work already surveyed this ground — see `docs/briefs/pause-and-resume-dispatch.md` — and the same
scheduler-driven mechanisms that had to see a pause presumably have to see a validation state. If
the answer is that pause's enforcement points are not sufficient for a per-**destination** gate
(pause is per proxy), say so: that is exactly the finding this question exists to surface.

### 2. How a skipped unit of work is resolved without parking the queue (AC10)

**AC10 rules that work for a non-Validated destination is skipped, not held** — resolved without
dispatching, never delivered later, and it must not park the work behind it.

How that resolution is represented is the Principal Engineer's. The failure to avoid is the one
ADR-019 already identified in a different form: a short-circuited step that parks the FIFO queue
with no age escape. A skipped destination must not become a permanently unresolvable unit of work
holding a proxy's ordering behind it, and it must not create a delivery attempt record (AC11).

### 3. Address-range refusal against a hostname that resolves differently between check and send (AC20)

**AC20 requires that loopback, private, link-local and cloud-metadata addresses are refused before
any request leaves, whether the URL names a literal address or a hostname that resolves to one.**

The product requirement is the refusal. The mechanism is not, and the hard part is not the address
list: a hostname checked at validation time and connected to a moment later can resolve to two
different addresses. **Rule how the check and the connection are bound together**, and state
whether the same guarantee is available for the redirect refusal (AC19) or whether refusing to
follow redirects at all is what makes AC19 sufficient.

### 4. Keeping the validation link out of every log when it necessarily travels as a URL (AC23, AC24)

**AC23 requires the link and its token appear in no application log, delivery record, analytics
record, error report or support output. AC24 requires that the link is never displayed to any member
inside the product** — that second one is the load-bearing security property, because a member who
can read the link can approve their own destination and the feature proves nothing.

A URL is the one thing infrastructure logs by default, at every layer. **Rule how the token travels
and where it is carried** so that AC23 holds against the framework's request logging, the exception
handler, and anything sitting in front of the application. If the answer constrains how the link can
be shaped, say so — the shape is yours, the properties in AC22 (unguessable, single-use, 7-day
expiry) are the requirement.

## What is not being asked

- **Whether the gate exists.** It does; AC8 is settled.
- **Whether skipped work could instead be held.** It could not; AC10 rules it out with reasoning,
  and holding would reintroduce the payload-immortality problem PRD-06 AC18 names.
- **Whether a member could be given an override.** No; AC3 admits exactly one route to Validated.

**If any finding here contradicts a criterion in PRD-18, that returns to the Product Manager as a
requirement question, not as a silent design change.**

---

## Answers — Principal Engineer, 2026-08-31

### 1. The two enforcement points — the question's premise is wrong, and that is the finding

**Pause's enforcement points are not the right two for #18, because pause is per-proxy and
validation is per-destination.** The question anticipated this possibility and asked for it to be
said plainly, so: it is the case.

Pause is enforced at `app/Actions/ProcessIngestedWebhook.php:77` (Async modes only) and at the FIFO
claim guard in `app/Actions/AdvanceProxyFifoQueue.php:80`. Both ask "may this proxy dispatch at
all". Neither can express "this destination, but not that one", and pushing a per-destination test
into either would be answering the wrong question at the wrong granularity.

The correct two points for validation are different, and the codebase already puts them where they
need to be:

- **Point one — delivery-row creation.** `ProcessIngestedWebhook` creates one `deliveries` row per
  live destination in the `foreach ($proxy->destinations as $destination)` loop at line 94. This is
  the only place in the application where destination selection for an original dispatch happens.
  Filtering that loop to validated destinations is the queue-check AC8 asks for. `DeliverStep`
  (`app/Actions/DeliverStep.php:41`) then iterates `deliveries` rows and never touches
  `$proxy->destinations`, so no row means no work — not skipped work, no work.
- **Point two — `DeliverToDestination`, on the worker, at send time.** The row-creation check is not
  sufficient on its own because a destination can leave the validated state between row creation and
  send: AC5 returns a destination to unvalidated on a URL edit, and a 7-day challenge can expire
  while a delivery sits on the queue or waits on a retry backoff. This is the dispatch-gate.

**Third paths that can start work on a destination, all of which must also be gated:**

- `SweepDueRetries` (`app/Actions/SweepDueRetries.php`) re-dispatches overdue retries from existing
  `deliveries` rows, so it never passes through point one. It must exclude deliveries whose
  destination is not validated, in the same manner it already excludes paused proxies at line 49.
- Replay, via `ProxyEventReplayController`, pre-creates its own `deliveries` rows for a chosen
  destination subset and bypasses the line-94 loop entirely (the loop is gated on
  `$dispatchUuid === $ingestId`). AC9 requires replay to a non-validated destination to be
  unavailable with the reason given, so the refusal belongs in the controller and its policy, not
  only in the worker.
- `SweepStalledFifoDispatches` re-drives existing rows and inherits point two's protection.

So the honest count is **two enforcement points plus two additional entry paths**, not two points.
The plan carries all four.

### 2. How a skipped unit resolves without parking FIFO — the existing machinery already does it

Because point one is *row creation*, a skipped destination produces **no row at all**. There is no
unit of work to resolve, nothing to hold, and nothing to park behind. AC11 is satisfied without
special handling: `DeliveryAttempt` records are created per attempt by `DeliverToDestination`, and
an attempt never happens.

The FIFO case deserves a specific note, because `ProcessIngestedWebhook` carries a comment at lines
70-75 warning that returning with zero deliveries created makes
`AdvanceProxyFifoQueue::settleOrHold()` read "no non-terminal deliveries" and settle the row as
done. For pause that behaviour would be silent data loss, which is why pause is deliberately scoped
away from FIFO there. **For validation that behaviour is exactly what AC10 asks for**: a proxy whose
destinations are all unvalidated settles as done rather than holding, the queue advances, and the
event is never delivered retroactively. The mechanism the pause work had to avoid is the mechanism
this feature wants. No new machinery is required.

One consequence needs stating rather than hiding: when every destination is skipped, the event's
status is still flipped to `Dispatched` at `ProcessIngestedWebhook:111`, because that write is
gated on the dispatch being the original one and not on any row having been created. An event that
reached no destination will therefore read as dispatched. This is consistent with AC11 (a skip is
not a failure) and AC12 (ingest is unaffected), and design-18's Screen 3 not-all-validated
indicator is what tells a member the real story. Flagged so it is a known consequence rather than a
surprise found in review.

### 3. Address refusal against a hostname that resolves differently between check and send

Checking a hostname and then connecting to it is two separate resolutions, and an attacker who
controls the DNS record can return a public address to the check and a private one to the
connection. Validating the URL string, or even resolving it and validating the result, does not
close this — it is the classic DNS-rebinding shape.

**Ruling: resolve once, validate every address returned, then pin the connection to the address
that was validated.** Concretely, resolve the host to its full address set, refuse the send if any
returned address falls in a loopback, private, link-local, unique-local or cloud-metadata range,
and then issue the request with the connection pinned to the validated address via cURL's
`CURLOPT_RESOLVE`, which the HTTP client exposes through Guzzle's `curl` option map. The socket
then connects to the address that was checked, not to whatever the resolver returns a moment later.
Check and connection are bound because they are the same address by construction.

**Refusing redirects outright is what makes AC19 sufficient**, and the two rulings are linked. A
redirect is a fresh connection to a fresh host, and pinning does not extend to it; re-running the
resolve-validate-pin cycle per hop would be the only alternative and it buys nothing, because the
validation challenge has no legitimate reason to be redirected. `allow_redirects => false` on the
validation send, with a redirect response treated as a failed validation rather than followed, means
there is never a second connection whose address was not checked.

Scope reminder from AC20 and AC40: this applies to **validation sends only**. Ordinary delivery is
untouched, so grandfathered destinations at private addresses keep working until their URL changes.
The stated consequence stands — a new destination at a private address can never be validated.

### 4. Keeping the link out of every log — partially achievable, and AC23 as written is not

The application-layer half is achievable and the plan carries it: the token never enters
`Log::` context, never lands on a `deliveries` or `delivery_attempts` record, never enters analytics,
and is excluded from exception-handler context so a stack trace carrying the request URL does not
export it.

**The rest of AC23 cannot be delivered for a token that travels in a URL, and no framework feature
changes that.** AC23 requires the link and its token to appear in no log at any layer. A URL is
logged by the web server access log, by anything sitting in front of the application, and by the
recipient's own infrastructure the moment the challenge arrives — which is the one layer this
project does not operate at all. Laravel's signed URLs put the signature and expiry in the query
string; Fortify's password reset puts its token in the path. Both are logged wherever a URL is
logged. This is not a defect in those features, it is what a bearer-token-in-a-URL is.

There is exactly one shape that would satisfy AC23 literally: carry the identifier in the URL and
the secret in the **fragment**, which browsers never transmit to any server, then have the
confirmation page read the fragment in JavaScript and submit it in the POST body. It genuinely
works, and it costs a hard JavaScript dependency on a page that is otherwise reachable without one,
on the one screen in the product seen by a person with no account.

**This is escalated to the Product Manager as a requirement question rather than settled here**, per
this document's own instruction that a finding contradicting a criterion returns as a requirement
question. See `docs/questions/prd-18-q-18-02-ac23-log-guarantee.md`. The plan is written against the
signed-URL shape and marks the affected section as provisional pending that answer; the choice
changes the confirmation page and nothing else, so it does not block the rest of the work.
