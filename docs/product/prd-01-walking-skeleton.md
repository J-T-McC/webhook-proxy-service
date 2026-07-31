# PRD: Walking skeleton — ingest -> fan-out delivery

- **Status:** Approved
- **Author:** Product Manager
- **Date:** 2026-07-30
- **Revised:** 2026-07-30 — scope broadened to include fan-out (delivery to one
  or many destinations) per Project Owner decision; previously single-destination
  only. Still Draft, pending Project Owner approval.
- **Revised:** 2026-07-30 — corrected AC11 and scope: delivery analytics
  (payload-free, per-destination delivery-attempt records) are **always** captured
  in item #1 — in simple proxy mode, decoupled from payload storage — per the
  Project Owner decision resolving
  `docs/questions/prd-01-attempt-records-vs-storage.md`. AC11 previously and
  wrongly said item #1 delivers "without analytics." Still Draft, pending Project
  Owner approval.
- **Revised:** 2026-07-30 — Open Question R5 (ingest-URL generation & security)
  resolved by the Principal Engineer in
  `docs/architecture/adr-006-ingest-url-generation-security.md`. AC12 is now
  concretely testable; R5 removed from Open Questions. Only two non-blocking
  Owner preferences remain open. Still Draft, pending Project Owner approval.
- **Revised:** 2026-07-30 — **Approved by the Project Owner.** Status set to
  Approved. The two remaining Open Questions (TLS enforcement layer;
  plaintext-token fallback) are Owner **preferences** and are explicitly
  **Deferred (non-blocking)** — they do not gate design or build and can be
  resolved at implementation time. No scope or requirement content changed.
- **Revised:** 2026-07-30 — Designer question
  `docs/questions/prd-01-design-manage-scope.md` resolved by the Project Owner.
  Item #1 now explicitly includes **edit and delete** of proxies and their
  destinations: AC2 and AC6 clarified and new **AC16** added. Mode-selector note
  added — the Enhanced option may be selectable in a partial state and needs no
  "coming soon"/disabled gating (pre-MVP partial state is permitted); the Simple
  path is what item #1's ACs verify. Scope of the create/list/view/deliver
  behaviour is unchanged.
- **Revised:** 2026-07-30 — **HTTPS-only security constraint** ruled by the
  Project Owner and recorded as a product-wide invariant (see *Product-wide
  invariant: HTTPS-only*). AC3 now validates destination-URL **scheme**
  (`https://` required, `http://`/missing-scheme rejected) alongside the existing
  POST/PUT method rule; new **AC17** requires non-HTTPS ingest requests to be
  rejected; AC7, AC10, the Feature line, and the Goals transport bullets are
  tightened from "HTTP(S)" to "HTTPS". Enforcement layer (edge vs. app) remains
  an implementation detail for the technical plan.
- **Approved by / date:** Project Owner — 2026-07-30
- **Backlog item:** Roadmap #1 (`docs/product/roadmap.md`)

## Feature
A team member can create a simple-proxy that receives a webhook at one ingest
URL and delivers it, unchanged, to a collection of one or more destination
endpoints over HTTPS using POST or PUT only.

## Problem
The product's core value — fan-out and payload reshaping — sits on top of a base
capability that does not yet exist: a webhook coming in one URL and going out to
its destinations. Nothing today proves this end-to-end path works. Item #1
builds that smallest end-to-end slice (a "walking skeleton"): one webhook in,
delivered out to every configured destination, owned by a team. Fan-out is part
of the product's core problem (delivering one incoming webhook to many
destinations), so the skeleton is designed for one-or-many destinations from the
first commit — there is no single-destination version to refactor out of later.
Every later capability layers on top of this slice.

## Goals
- A team member can create a proxy that has exactly one ingest URL and a
  collection of one or more destination endpoints.
- A webhook posted to a proxy's ingest URL is delivered to every destination
  configured on that proxy over HTTPS.
- Each destination receives the same incoming payload structure (per resolved
  decision R3 — fan-out delivers one payload structure to all destinations).
- Outbound delivery is restricted to HTTPS with POST or PUT only (POST/PUT per
  resolved decision V1; HTTPS-only per the Project Owner security ruling of
  2026-07-30); no other methods or transports. Destination URLs must be `https://`
  (see AC3 and *Product-wide invariant: HTTPS-only*).
- Delivery to each destination is independent: one destination failing does not
  block delivery to the others. Delivery is fire-and-forget in this slice — no
  retry or replay of failed deliveries.
- Delivery analytics are always captured: for every delivery, the service records
  one payload-free delivery-attempt record per destination, capturing the delivery
  outcome and status, from the first commit. This capture happens in simple proxy
  mode and does **not** depend on payload storage (there is no payload storage in
  item #1); the attempt record does not contain the webhook body.
- All proxy data and entities are team-scoped for data ownership from the first
  commit (per resolved decision R1), reusing the Laravel starter-kit auth/teams
  boilerplate rather than rebuilding it. The data model natively supports many
  destinations per proxy.
- The proxy runs in **simple proxy mode** only — minimal processing, no mapping,
  no storage, no retry.

## Product-wide invariant: HTTPS-only (in and out)
Per the Project Owner security ruling of 2026-07-30, **all webhook traffic — both
incoming ingest and outgoing delivery — must use HTTPS**. This is a cross-cutting
product invariant, not a walking-skeleton-only rule: every later roadmap item that
ingests a webhook or delivers to a destination inherits it. Concretely:

- **Incoming:** the ingest endpoint accepts requests only over HTTPS; non-HTTPS
  (plaintext HTTP) ingest requests are rejected (AC17). Rationale: the ingest URL
  carries a bearer token, so a plaintext request would leak it.
- **Outgoing:** destination URLs must have an `https://` scheme, validated and
  rejected at create and edit time (AC3a); outbound delivery uses HTTPS only
  (AC7, AC10).

The **enforcement mechanism/layer** (edge termination and redirect vs. a thin
app-layer assertion) is an implementation detail for the technical plan, not a
requirement dimension — see the *Open Questions* note on the TLS enforcement
layer.

**Flagged for the vision/roadmap owner (not edited here):** because this
invariant is cross-cutting, it should also be recorded in
`docs/product/vision.md` (e.g. under *Known Constraints*) and/or
`docs/product/roadmap.md` so downstream PRDs inherit it explicitly. This also
partially settles Vision Open Question #1 ("Outgoing delivery format/transport —
HTTP(S) POST only, or other transports?") on the transport dimension: transport
is **HTTPS-only**. Per role boundaries the Product Manager has not edited the
vision or roadmap; this is a flag for the Project Owner / vision owner.

## Users
- **Team member** — a registered user who belongs to a team (via the starter-kit
  auth/teams boilerplate); creates and views proxies for that team.
- **Upstream sender** — a system actor (an external service) that posts webhooks
  to a proxy's ingest URL. Not a registered user of this product.

## User Stories
- As a team member, I want to create a proxy with one or more destination
  endpoint URLs, so that I get an ingest URL I can hand to an upstream sender and
  every configured destination receives the webhook.
- As a team member, I want to specify the HTTP method (POST or PUT) used to
  deliver to a destination, so that each destination receives the request it
  expects.
- As a team member, I want to see my team's proxies, each proxy's ingest URL, and
  its configured destinations, so that I can configure the upstream sender to
  post to it and confirm where it fans out.
- As a team member, I want proxies and their data owned by my team, so that team
  data ownership is in place from the first commit.
- As an upstream sender, when I post a webhook to a proxy's ingest URL, I want it
  delivered to every one of that proxy's configured destinations, so that each
  downstream system receives the event.
- As the product (system), for every delivery attempt to a destination, I want to
  record its outcome as a payload-free attempt record from the first commit, so
  that analytics (roadmap #11) is built from real attempt records rather than
  reconstructed later (Vision: "Analytics / stats").

## Acceptance Criteria
1. An authenticated user who belongs to a team can create a proxy by specifying
   one or more destination endpoint URLs.
2. A created proxy has exactly one ingest URL and a collection of one or more
   destinations. A proxy with zero destinations cannot be created; adding
   additional destinations is supported both during creation and after the proxy
   exists (post-creation add/remove — see AC16). A proxy must always retain at
   least one destination.
3. Every destination URL is valid at both create time and edit time (AC16),
   verified by two independent rules:
   - **3a. HTTPS-only scheme.** The destination URL's scheme must be `https`. A
     URL with an `http://` scheme, any non-`https` scheme, or no scheme at all is
     rejected and the proxy/destination is not saved. Testable — *invalid:*
     `http://example.com/hook`, `ftp://example.com/hook`, `example.com/hook`
     (missing scheme); *valid:* `https://example.com/hook`.
   - **3b. Method POST or PUT.** Outbound delivery specifies the HTTP method used,
     restricted to POST or PUT; no other method can be selected or used.
4. A team member can view a list of their team's proxies and, for each, see the
   ingest URL and every configured destination.
5. Every proxy, its ingest URL, and its destinations are owned by a team. A user
   can only view or manage proxies belonging to a team they are a member of.
6. Creating or managing a proxy requires an authenticated user in a team;
   unauthenticated requests cannot create or view proxies. "Managing" includes
   editing a proxy (its name and its destinations) and deleting a proxy or an
   individual destination after creation — see AC16.
7. When a webhook is posted to a proxy's ingest URL, the service delivers the
   received payload to every configured destination of that proxy over HTTPS
   using the configured method (POST or PUT).
8. Each destination receives the same payload structure as the incoming webhook.
9. Delivery to each destination is independent: if delivery to one destination
   fails, deliveries to the other destinations still proceed. Failed deliveries
   are not retried or replayed in this item (fire-and-forget).
10. Outbound delivery uses HTTPS with POST or PUT only. No non-HTTPS transport
    and no other HTTP method is used (destination URLs are HTTPS-only per AC3a).
11. The proxy operates in simple proxy mode: the incoming payload is delivered
    without mapping/reshaping, without the payload being stored, without retry or
    replay, and without notifications. Delivery analytics are still captured —
    see AC13–AC15 — because analytics capture does not depend on payload storage.
12. **(R5 resolved — see ADR-006)** Each proxy's ingest URL is unique and not
    guessable, verifiable by the following observable outcomes:
    - **12a. Unique.** No two proxies ever have the same ingest URL; creating
      proxies always yields distinct ingest URLs. Uniqueness is guaranteed by the
      system (no duplicate URL can exist).
    - **12b. Not guessable.** The ingest URL carries a high-entropy, opaque token
      and embeds no team, proxy, or other identifier that would let a URL be
      derived or enumerated from another proxy's URL.
    - **12c. Unknown/invalid token rejected.** A request to an ingest URL whose
      token is unknown or invalid receives a `404` response, with no indication
      of whether any proxy exists.
    - **12d. Viewable.** A team member can view the full ingest URL (including its
      token) for a proxy belonging to their team at any time (supports AC4).
13. For every delivery, the service records exactly one delivery-attempt record
    per destination. Each attempt record captures at least the delivery outcome
    (e.g. success or failure) and the destination's response status, and
    identifies the proxy and destination it belongs to.
14. Delivery-attempt records are captured in simple proxy mode, from the first
    commit, for both successful and failed deliveries. Capture does not require
    enhanced mode or payload storage to be enabled (there is no payload storage
    in item #1).
15. A delivery-attempt record contains no webhook payload body (payload storage
    is roadmap #5). Attempt records are team-scoped and queryable: a team member
    can only access attempt records for proxies belonging to a team they are a
    member of.
16. **(Manage — resolved by `docs/questions/prd-01-design-manage-scope.md`)** A
    team member can manage an existing proxy that belongs to their team:
    - **16a. Edit proxy.** Edit the proxy's name and edit its destinations
      (add a destination, remove a destination, and change a destination's URL
      and HTTP method — POST or PUT only per AC3).
    - **16b. Minimum-destination invariant.** An edit cannot leave a proxy with
      zero destinations; removing the last remaining destination is not permitted
      (a proxy always retains at least one destination — see AC2).
    - **16c. Delete destination.** Delete an individual destination from a proxy,
      subject to 16b.
    - **16d. Delete proxy.** Delete a proxy in its entirety (including its
      destinations).
    - **16e. Team-scoped.** All of the above are restricted to proxies belonging
      to a team the user is a member of (per AC5); a user cannot edit or delete
      another team's proxy or its destinations.
17. **(Ingest HTTPS-only — Project Owner security ruling of 2026-07-30)** The
    ingest endpoint accepts requests only over HTTPS. A non-HTTPS (plaintext
    HTTP) request to an ingest URL is rejected and the webhook is **not**
    delivered to any destination. Rationale: the ingest URL carries a bearer
    token, so a plaintext request would leak it. This criterion is satisfied so
    long as no non-HTTPS ingest request is ever accepted; the enforcement
    layer — edge termination/redirect vs. a thin app-layer assertion — is an
    implementation detail for the technical plan and is not part of this
    criterion.

## Mode selector (item #1)
Per the Project Owner decision resolving
`docs/questions/prd-01-design-manage-scope.md`: the proxy's `simple`/`enhanced`
mode (ADR-002) may be surfaced as a selector at creation. Because this is pre-MVP,
partially-functional code is allowed to exist unpolished and un-gated — so the
Enhanced option **may** be selectable and **may** persist an `enhanced` value even
though enhanced-mode behaviours (mapping #8, storage #5, retry #6) are not yet
functional. A "coming soon"/disabled treatment of the Enhanced option is **not
required**. The acceptance bar for item #1 remains the correctness of the
**Simple**-mode behaviour defined in AC1–AC15; the selector adds no new
Simple-path requirement. (UI presentation of the selector is the Designer's to
decide.)

## Out of Scope
Explicitly excluded from item #1. Each points to the later roadmap item that
owns it. Payload storage, mapping, retry/replay, and notifications are deferred
largely because they depend on payload storage, which is not part of item #1
(roadmap #5). Delivery-analytics **capture** does not depend on payload storage,
so — per the Project Owner decision of 2026-07-30 — it is **in scope** from the
first commit (see Goals and Acceptance Criteria 13–15) and is no longer excluded
here.

- **Role-based collaboration** — invites, view/add/modify roles, and removing
  access are roadmap #2. Team *ownership* of data is in scope; team *role
  management* is not.
- **Decoupled upstream response** — a user-defined status code and response body
  to the upstream sender are roadmap #3. Item #1 does not promise any particular
  upstream response contract.
- **Queued processing (FIFO & Async)** — roadmap #4.
- **Payload storage & retention / raw capture** — no payload is stored in item
  #1; storage is enhanced-mode only, roadmap #5 (per resolved decision R2).
- **Retry & replay** — roadmap #6. Item #1 fan-out is fire-and-forget per
  destination; failed deliveries are not retried or replayed.
- **Enhanced-mode toggle** — roadmap #7; item #1 is simple proxy mode only.
- **Payload mapping / reshaping** — roadmap #8. All destinations receive the same
  incoming payload structure in item #1; per-proxy reshaping comes later.
- **Multi-format ingestion (XML, form-encoded)** — roadmap #9.
- **Sensitive data handling / incoming verification tokens** — roadmap #10
  (and Vision Open Question V2).
- **Analytics dashboards / stats presentation** — the success/failure counts,
  per-webhook drill-down, and stats views that *consume* attempt records are
  roadmap #11 (dashboard scope deferred per Vision Open Question V7). Note: the
  always-on *capture* of payload-free delivery-attempt records is **in scope** for
  item #1 (Acceptance Criteria 13–15) and does not depend on payload storage; #11
  later reads these records rather than reconstructing them.
- **Change detection** — roadmap #12.
- **Notifications (in-app & email)** — roadmap #13.
- **Test payloads** — roadmap #14.
- **Building teams / registration from scratch** — provided by the Laravel
  starter-kit boilerplate; not rebuilt here.
- **Throughput / latency / delivery-success targets** — none are set (Vision
  Open Question V8); no performance targets are asserted for the walking
  skeleton.

## Open Questions
R5 (ingest-URL generation & security) is **RESOLVED** — see
`docs/architecture/adr-006-ingest-url-generation-security.md` and the annotated
resolution in `docs/questions/prd-01-walking-skeleton-r5-ingest-url.md`. AC12 is
now objectively testable and R5 no longer gates item #1.

The following two points are **Project Owner preferences only** and are
**DEFERRED (non-blocking)**. They do **not** gate design or build of item #1 —
the recommended design in ADR-006 proceeds without them being decided — and
either may be resolved at implementation time.

- **TLS enforcement layer** — **Deferred (non-blocking).** The *requirement* that
  ingest be HTTPS-only is now settled: the Project Owner's 2026-07-30 security
  ruling makes HTTPS-only a product-wide invariant (AC17). What remains deferred
  is only the enforcement *placement* — application layer vs. load-balancer
  termination/redirect (the Owner's steer is "both edge termination/redirect **and**
  a thin app-layer assert"). Placement does not gate design or build and is an
  implementation detail for the technical plan; it can be resolved at
  implementation time.
- **Plaintext-token fallback** — **Deferred (non-blocking).** Whether the simpler
  plaintext-token storage fallback is acceptable to the Owner, versus the
  recommended hash-lookup-plus-encrypted-at-rest approach. Both satisfy AC12; the
  choice is an Owner preference on defence-in-depth that can be resolved at
  implementation time.

## Handoff
- **Inputs:** `docs/product/vision.md`, `docs/product/roadmap.md` (item #1;
  resolved decisions R1, R2, R3, V1), Project Owner roadmap approval (2026-07-30)
  and the 2026-07-30 Project Owner decision to broaden item #1 to include
  fan-out.
- **Outputs:** this PRD.
- **Dependencies:** Laravel starter-kit auth/teams boilerplate (registration and
  teams are reused, not rebuilt).
- **Outstanding Questions:** None blocking. R5 (ingest-URL generation & security)
  is resolved by ADR-006; AC12 is now testable. Two Owner preferences (TLS
  enforcement layer; plaintext-token fallback) are **Deferred (non-blocking)** —
  see Open Questions; neither gates design or build and both can be resolved at
  implementation time.
- **Next Agent:** Designer *(item #1 has user-facing UI: proxy creation with one
  or more destinations and the proxy/ingest-URL/destinations listing)*.
