# ADR-027: Destination validation state, grandfathering, and the guarded challenge send

- **Status:** **Accepted — Project Owner, 2026-08-31.** Both open decisions approved as proposed:
  three stored states with Expired derived, and the resolve-check-pin challenge send with redirects
  refused. Decision 2 was never open — it is PRD-18 AC30, approved with the PRD.
- **Date:** 2026-08-31
- **Author:** Principal Engineer
- **Feature:** roadmap item #18, destination validation
- **Plan:** `docs/plans/plan-18-destination-validation.md`
- **Supersedes / amends:** nothing. **Narrows** the fan-out contract stated at roadmap #1 and carried
  by PRD-01, from every configured destination to every validated one — the narrowing itself was
  approved with PRD-18 on 2026-08-31 and is recorded here because it is the reason this ADR exists.

## Question

**Two** decisions in #18 are hard to reverse and need the Owner rather than Principal Engineer
self-certification: how validation state is stored on a table holding live data, and how an outbound
request to an as-yet-untrusted URL is made safe. A third matter — what happens to the destinations
that already exist — is recorded below as decision 2 but was **already approved with PRD-18 AC30**
and is not open.

## Decision

**1. Validation state is stored on `destinations` as three states, with the fourth derived.**
`validation_state` holds `unvalidated`, `pending` or `validated`, alongside `validated_at`,
`validation_challenge_sent_at`, `validation_challenge_expires_at` and `validation_nonce`. PRD-18
AC1's fourth state, Expired, is derived — state is `pending` and the expiry has passed — rather than
written by a scheduled sweeper. Single-use links come from the nonce, which every fresh challenge
replaces, making the previous link inert without a revocation list. The link itself is a Laravel
temporary signed URL: no token table, no generated secret, no hand-rolled expiry check.

**2. Every destination that exists when the migration runs is set to `validated`.** **Already
approved — this is PRD-18 AC30, which the Owner approved with the PRD on 2026-08-31, and it is
recorded here for completeness rather than re-raised as a decision.** The exemption decays: any
grandfathered destination whose URL is later edited returns to `unvalidated` under AC5.

**3. The challenge send resolves, validates and pins.** The host is resolved once; the send is refused
if any returned address is loopback, private, link-local, unique-local or cloud-metadata; and the
connection is pinned to the validated address via cURL's `CURLOPT_RESOLVE`. Redirects are refused
rather than followed. The guard fails closed if the transport cannot pin. This applies to validation
sends only — ordinary delivery is untouched.

## Alternatives

**For decision 1 — a separate `destination_validations` table.** Rejected: it buys an audit trail of
past challenges that no acceptance criterion asks for, and it puts the enforcement gate behind a join
on the hottest path in the product, the delivery-row creation loop. A column keeps the gate a
`where`.

**For decision 1 — storing `expired` as a fourth state.** Rejected: it needs a scheduled job to write
it, and a job that falls behind leaves a destination displaying as pending after its challenge died.
Deriving it is always correct and needs no machinery.

**For decision 2 — forcing every existing destination to revalidate.** Rejected by the Product
Manager and approved as rejected by the Owner: it converts a security improvement into a production
outage, stopping delivery on destinations teams already depend on until somebody at each receiving
end happens to click a link.

**For decision 3 — validating the URL at save time.** Rejected: it is the DNS-rebinding hole. A
hostname that resolves to a public address when the form is submitted can resolve to a private one
when the request is made. Only binding the check to the connection closes it.

**For decision 3 — re-running the check on each redirect hop.** Rejected in favour of refusing
redirects outright. A validation challenge has no legitimate reason to be redirected, and refusing
means there is never a second connection whose address went unchecked.

## Reasoning

The feature exists to stop the product being usable as a relay to arbitrary hosts. The awkward part
is that the validation send is itself a request to an arbitrary host — the vector, performed by the
mitigation. Decision 3 is therefore the load-bearing one: without pinning, the feature adds an
attack surface roughly equal to the one it removes.

Decisions 1 and 2 are chosen for the same reason, which is that the enforcement gate must stay cheap
and always correct. It runs on every ingested webhook, in the loop that creates delivery rows, and
anything that makes it a join or leaves it reading a stale column would be paid for on the hottest
path in the product.

The whole mechanism is first-party where first-party exists, per the Owner's standing preference:
signed URLs for the link, the `RateLimiter` facade for the send limits, the existing action and
pipeline seams for everything else. `OutboundAddressGuard` is the only custom security code, and it
is custom because the framework has no equivalent.

## Impact

- **`destinations` gains five columns** and every existing row is written once. Live data.
- **The fan-out contract narrows.** A configured destination that is not validated receives nothing.
  The technical design is to identify which PRD-01 criteria state the old contract and carry them
  forward as a list, per PRD-18's § Consequences for approved documents.
- **Two new public unauthenticated routes**, both signature-gated, reachable by somebody with no
  account. This is a new exposure class for the product and the reason #18 ran in the pipeline lane.
- **No new dependencies and no change to `docs/stack/stack.md`.**
- **AC23 was narrowed and is settled.** It now covers the layers this application controls. The Owner
  ruled on 2026-08-31, closing `docs/questions/prd-18-q-18-02-ac23-log-guarantee.md`; PRD-18 AC23 is
  amended in place with the reasoning. The signed-URL shape stands and the confirmation page needs no
  JavaScript. AC24, the property that makes the feature meaningful, was never in question.
