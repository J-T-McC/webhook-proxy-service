# PRD: Decoupled upstream response (with always-on raw capture)

- **Status:** Approved
- **Author:** Product Manager
- **Date:** 2026-08-03
- **Approved by / date:** Project Owner, 2026-08-03
- **Backlog item:** Roadmap #3 (`docs/product/roadmap.md`)
- **Scope note (2026-08-03):** Feature #3 was expanded beyond the approved
  roadmap line by a Project Owner scope decision this session (**Path A**). The
  roadmap line covers only the decoupled, user-defined upstream response; the
  Owner has added **always-on durable capture of the raw incoming payload before
  the upstream response is returned**, and has **overridden Resolved Decision R2**
  so that raw capture is no longer enhanced-mode-only. See
  *Owner-approved scope change* below. This is a post-approval scope refinement of
  a single item, handled the same way #1's fan-out and #8's map-selection
  revisions were.

## Feature
A proxy returns a user-defined status code and response body to the upstream
sender immediately — resolved from proxy configuration, never from any downstream
delivery outcome (per ADR-004) — and every incoming webhook's raw payload is
durably persisted **before** that response is returned, in both simple and
enhanced modes.

## Problem
Two gaps sit on top of the walking skeleton (#1):

1. **No user-defined response.** Item #1 returns a fixed default (`202 Accepted`,
   ADR-004) and promises no particular upstream contract. Upstream senders often
   require a specific acknowledgement (a given status code and/or body) to
   consider the webhook accepted; the proxy cannot yet provide one.
2. **Accepted-but-lost events.** Once we return success upstream, the sender's
   system considers the event delivered and will not resend it. Today (#1),
   delivery is fire-and-forget and nothing durably stores the incoming payload in
   simple proxy mode, so if the proxy acknowledges an event and dispatch then
   fails, the event is gone — we have told the provider it succeeded but cannot
   retry. That is data loss that directly contradicts the product's
   failure/reliability goals. Roadmap R2 originally deferred raw capture to
   enhanced-mode-only (#5), which leaves this gap open for every simple-mode proxy.

Item #3 closes both: it lets a proxy define its own immediate response, and it
guarantees the raw payload is durably captured **before** that response is sent,
so a later replay (#6) is always possible and no accepted event is lost.

## Goals
- A team member can configure the status code (restricted to 2xx) and response body
  a proxy returns to upstream senders. Configuration is optional; an unconfigured
  proxy returns a `202 Accepted` default (inherited by existing #1 proxies).
- The configured response is returned immediately, resolved from proxy
  configuration, and is never derived from — and never waits on — downstream
  delivery outcome (ADR-004).
- Every incoming webhook's raw payload is durably persisted before the upstream
  response is returned, in **both** simple proxy mode and enhanced mode.
- The captured raw input is kept separate from, and immutable relative to, any
  dispatched/derived output (per R2's build-ahead separation), so #6 replay can
  re-dispatch the raw payload and #10 can encrypt/obfuscate without re-modeling.
- Only the **capture** half of #5's storage is pulled forward. Retention windows
  and garbage collection stay at #5; retry, manual replay, and configurable
  backoff stay at #6.

## Owner-approved scope change (2026-08-03): raw capture always; R2 overridden
Recorded here so the decision is not lost. **Owner:** tysonmccarney. **Date:**
2026-08-03 (this session). **Type:** Owner-approved, post-approval scope
refinement of roadmap item #3.

- **Path A chosen.** Feature #3 = decoupled user-defined response (status + body,
  per ADR-004) **PLUS** always-capture the raw incoming payload durably **before**
  returning the upstream response.
- **Roadmap Resolved Decision R2 is OVERRIDDEN.** R2 (`docs/product/roadmap.md`,
  Resolved Decisions; applied to #5) said raw payloads are captured **only** in
  enhanced mode. The Owner's new decision: **the raw payload is ALWAYS stored,
  including in simple proxy mode.**
- **Owner's rationale (verbatim intent):** if we accept a webhook, return success
  upstream, then dispatch fails, we have told the provider's system it succeeded
  but cannot retry — data loss that violates our failure/reliability goals.
  Durable capture-before-response closes that gap.
- **Scope of the override:** the override changes only the **capture** dimension of
  R2 (raw capture is now mode-independent). The rest of R2's build-ahead intent is
  **preserved and must be respected**: the raw captured input stays separate from —
  and immutable relative to — the dispatched output, so replay (#6),
  sensitive-data handling (#10), and #5's own storage/retention work do **not**
  need re-modeling. Only capture is pulled forward from #5; retention/GC is not.
- **What did not change:** retention windows + garbage collection remain at #5;
  retry, manual replay, and configurable backoff remain at #6 (which depends on the
  queue, #4). #3 only guarantees the payload is durably stored so a **later** #6
  replay is possible.

## Users
- **Team member** — configures a proxy's upstream response (status + body) and
  benefits from durable capture of every incoming webhook.
- **Upstream sender** — a system actor (external service) posting webhooks to an
  ingest URL; receives the immediate, user-defined response. Not a registered user.

## User Stories
- As a team member, I want to configure the status code and response body my proxy
  returns, so upstream senders get the acknowledgement they require regardless of
  downstream delivery.
- As an upstream sender, I want an immediate, well-defined response when I post a
  webhook — independent of whether downstream delivery succeeds — so I am not
  blocked on the proxy's fan-out.
- As a team member, I want every incoming webhook durably captured before my proxy
  acknowledges it, so that if downstream delivery later fails there is a stored
  payload to replay (roadmap #6) and no accepted event is lost.
- As the product (system), I want the upstream response resolved from proxy
  configuration and never from delivery outcome, so async dispatch (#4) needs no
  change to the response path (ADR-004).

## UX Direction *(minor UI on existing forms)*
The only user-facing addition is response configuration on the **existing proxy
create/edit form**: a status-code input and a response-body input. The experience
must make clear that this response is returned **immediately and independently of
delivery success** — it is an acknowledgement contract for the upstream sender,
not a report of downstream results. Direction only; field layout, states, and
components are the Designer's if a Designer handoff is warranted (see Handoff).
Durable capture is a system behavior with no direct UI in this item.

## Acceptance Criteria
1. **User-defined response returned.** When a proxy has a user-defined response
   status and response body configured, an incoming webhook to its ingest URL
   receives exactly that status code and that body.
2. **Response decoupled from delivery.** The upstream response is resolved from
   proxy configuration and is never derived from — and never waits on — any
   downstream delivery outcome or delivery-attempt record (ADR-003, ADR-004). A
   downstream delivery failure does not change the status or body returned
   upstream; the ingest handler does not read attempt records to build the
   response.
3. **Default response when unconfigured (Q-03-03).** Response configuration is
   **not** mandatory. When a proxy has no user-defined response configured, an
   incoming webhook receives a default of **`202 Accepted`**. This applies equally
   to proxies created under #1 that carry no response configuration — they inherit
   the `202 Accepted` default, matching ADR-004's current default, so there is no
   migration surprise. (`202` = "accepted, outcome not yet known", the correct
   semantic for decoupled/async delivery; `204` is deliberately not used because it
   implies a completed success the proxy does not guarantee.)
4. **Configured status must be 2xx (Q-03-04).** A user-configurable response status
   code is restricted to the **2xx** range. A non-2xx configured status is rejected
   (or ignored) and never returned upstream. Body constraints (size / content-type)
   are deferred to the Principal Engineer at design (Q-03-04) and are not asserted
   here.
5. **Capture-before-response ordering.** The raw incoming payload is durably
   persisted **before** the upstream response is returned. The response is
   returned to the upstream sender only after the raw payload has been committed to
   durable storage.
6. **Capture-write failure returns 500 (Q-03-01).** If the durable pre-response
   capture write fails, the ingest handler returns **`HTTP 500`** to the upstream
   sender and **never** returns a success response. This 500 is a system-emitted
   error, distinct from any user-configured (2xx) response: the configured response
   is returned only when the raw payload has been durably committed. This preserves
   the data-loss guarantee — success is never acknowledged for an event that was not
   captured.
7. **Capture in both modes.** Raw-payload capture happens for **both** simple
   proxy mode and enhanced mode; capture is unconditional on the proxy's mode. This
   overrides roadmap Resolved Decision R2 per the Owner scope change above.
8. **Raw/dispatched separation preserved.** The captured raw input is stored
   separately from, and is immutable relative to, any dispatched or derived output
   (per R2's build-ahead separation). Capture never mutates the raw input, so a
   later #6 replay can re-dispatch the raw payload and #10 can encrypt/obfuscate
   without re-modeling the storage shape.
9. **No parallel path.** Raw-payload capture is a distinct concern from the
   payload-free delivery-attempt records of ADR-003; it does not replace,
   duplicate, or read those records, and the two coexist without a parallel
   ingest-or-storage path.
10. **Scope boundary — delivery unchanged.** Delivery to destinations remains
    fire-and-forget as in #1 (the queue is #4). #3 changes only the upstream-response
    contract and adds durable capture. Retry, manual replay, and configurable
    backoff are **not** introduced (they remain #6, dependent on #4). #3 only
    guarantees the raw payload is durably stored so a later #6 replay is possible.
11. **Scope boundary — retention unchanged (Q-03-02).** Retention windows and
    garbage collection are **not** introduced here; they remain at #5. Simple-mode
    captures are intended to share #5's planned **30-day** default retention (later
    adjustable by plan type). Until #5's GC ships, captured payloads accumulate
    unbounded; this interim unbounded-growth trade-off is an accepted, known
    follow-up (see Open Questions Q-03-02) and is **not** a #3 blocker.

## Out of Scope
Each points to the roadmap item that owns it.

- **Retention windows + garbage collection of stored payloads** — roadmap #5. Only
  the capture half of #5's storage is pulled forward; retention/GC is not.
- **Retry, manual replay, and configurable backoff** — roadmap #6 (depends on the
  queue, #4). #3 guarantees only durable capture so a later replay is possible.
- **Queued / async dispatch** — roadmap #4. Delivery stays fire-and-forget (#1);
  #3's response is already decoupled so #4 needs no response-path change (ADR-004).
- **Encryption at rest / sensitive-field obfuscation / incoming verification
  tokens** — roadmap #10. #3 leaves the captured raw payload's separation intact so
  #10 can add these without re-modeling.
- **Enhanced-mode toggle / mapping** — roadmap #7 / #8. Capture is mode-independent
  but the mode toggle and reshaping are unchanged here.
- **Analytics / stats over captures** — roadmap #11; unchanged.

## Open Questions
Question IDs Q-03-0x. The four Owner-facing items (Q-03-01…Q-03-04) were answered
by the Project Owner on **2026-08-03** and are **Resolved** below; their answers are
reflected in the Acceptance Criteria. The remaining technical item (Q-03-05) is for
the Principal Engineer and does not block the Owner approving the requirements, only
the start of technical design.

- **Q-03-01 (Owner) — Capture-failure behavior. RESOLVED (2026-08-03).** If the
  durable pre-response capture write fails, the ingest handler returns **`HTTP 500`**
  to the upstream sender and does **not** return success. This is a system-emitted
  error, distinct from the user-configured response. Reflected in AC6. Rationale:
  returning success for an uncaptured event would reopen the exact data-loss gap #3
  closes.
- **Q-03-02 (Owner) — Retention of simple-mode captures. RESOLVED (2026-08-03).**
  Simple-mode captures share #5's planned **30-day** default retention, later made
  adjustable by plan type. In the interim window before #5's GC ships, captured
  payloads grow unbounded; this trade-off is an accepted, known follow-up (tracked
  toward #5), **not** a #3 blocker. Reflected in AC11.
- **Q-03-03 (Owner) — Default / unconfigured response. RESOLVED (2026-08-03).**
  Response configuration is **not** mandatory. The default when unconfigured is
  **`202 Accepted`** (chosen over `204`: `202` = "accepted, outcome not yet known",
  the correct semantic for decoupled/async delivery; `204` implies a completed
  success not guaranteed). This applies to existing #1 proxies too — they inherit the
  `202` default, matching ADR-004's current default, so there is no migration
  surprise. Reflected in AC3.
- **Q-03-04 (Owner, minor) — Validation of the user-defined response. RESOLVED
  (2026-08-03).** User-configurable status codes are restricted to the **2xx** range;
  a non-2xx configured status is rejected/ignored and never returned. Body
  constraints (size / content-type) are **deferred to the Principal Engineer** at
  design. Reflected in AC4.
- **Q-03-05 (Principal Engineer, technical) — Storage-shape ownership vs #5 and
  ingest-path composition.** Does #3 define the raw-payload storage entity now
  (pulling #5's capture half forward), and if so how does it stay consistent with
  #5's planned raw/dispatched-output separation (R2 build-ahead), align with
  ADR-003's payload-free attempt records (no parallel path, AC6), and leave room
  for #10's encryption/obfuscation — so #5/#6/#10 do not re-model? Separately,
  confirm that inserting a **synchronous durable write before the response** (AC3)
  composes with ADR-004's `ResponseResolver` seam and ADR-005's queue-dispatch
  abstraction without coupling the response to delivery. Feasibility/latency of the
  synchronous capture is a technical judgment, not resolved here.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#3 and its build-ahead note; Resolved
  Decision R2 — now overridden per the Owner scope change above; #4/#5/#6 scope
  boundaries), `docs/product/vision.md` ("Decoupled upstream response"),
  `docs/architecture/adr-004-upstream-response-decoupling.md` (ResponseResolver;
  response resolved from config, before/independent of dispatch; handler must not
  read attempt records),
  `docs/architecture/adr-003-delivery-attempt-records-and-events.md` (payload-free
  attempt records; no parallel path),
  `docs/architecture/adr-005-queue-dispatch-abstraction.md`,
  `docs/product/prd-01-walking-skeleton.md` (ingest → fan-out spine this extends),
  Project Owner scope decision (2026-08-03, this session) choosing Path A and
  overriding R2.
- **Outputs:** this PRD.
- **Dependencies:** Roadmap #1 (Done) — ingest handler, `ResponseResolver` seam
  (ADR-004), and proxy model already exist as the extension point. #3 does not
  depend on #4 (queue) or #5 (retention); it pulls only the capture half of #5's
  storage forward.
- **Outstanding Questions:** Q-03-01, Q-03-02, Q-03-03, Q-03-04 — **Resolved by
  Project Owner 2026-08-03** and folded into the Acceptance Criteria; no longer
  blocking. Q-03-05 (Principal Engineer — does not block requirement approval, gates
  technical design) remains open and non-blocking.
- **Next Agent:** Principal Engineer. The substance is backend — the decoupled
  response wiring (ADR-004) and durable capture-before-response — and it carries
  the technical open question (Q-03-05). The only user-facing change is adding a
  status-code field and a response-body field to the existing proxy create/edit
  form using existing form patterns; if the technical design or the Owner surfaces
  a genuine new UI/UX need beyond standard field additions, route back to the
  Product Manager for a Designer handoff.
