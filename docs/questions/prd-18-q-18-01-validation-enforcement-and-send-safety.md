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
- **Status:** **OPEN.**

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
