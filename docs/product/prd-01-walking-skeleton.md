# PRD: Walking skeleton — ingest -> fan-out delivery

- **Status:** Draft (pending Project Owner approval)
- **Author:** Product Manager
- **Date:** 2026-07-30
- **Revised:** 2026-07-30 — scope broadened to include fan-out (delivery to one
  or many destinations) per Project Owner decision; previously single-destination
  only. Still Draft, pending Project Owner approval.
- **Approved by / date:** _Pending — Project Owner_
- **Backlog item:** Roadmap #1 (`docs/product/roadmap.md`)

## Feature
A team member can create a simple-proxy that receives a webhook at one ingest
URL and delivers it, unchanged, to a collection of one or more destination
endpoints over HTTP(S) using POST or PUT only.

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
  configured on that proxy over HTTP(S).
- Each destination receives the same incoming payload structure (per resolved
  decision R3 — fan-out delivers one payload structure to all destinations).
- Outbound delivery is restricted to HTTP(S) POST or PUT only (per resolved
  decision V1); no other methods or transports.
- Delivery to each destination is independent: one destination failing does not
  block delivery to the others. Delivery is fire-and-forget in this slice — no
  retry or replay of failed deliveries.
- All proxy data and entities are team-scoped for data ownership from the first
  commit (per resolved decision R1), reusing the Laravel starter-kit auth/teams
  boilerplate rather than rebuilding it. The data model natively supports many
  destinations per proxy.
- The proxy runs in **simple proxy mode** only — minimal processing, no mapping,
  no storage, no retry.

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

## Acceptance Criteria
1. An authenticated user who belongs to a team can create a proxy by specifying
   one or more destination endpoint URLs.
2. A created proxy has exactly one ingest URL and a collection of one or more
   destinations. A proxy with zero destinations cannot be created; adding
   additional destinations is supported.
3. Outbound delivery specifies the HTTP method used, restricted to POST or PUT;
   no other method can be selected or used.
4. A team member can view a list of their team's proxies and, for each, see the
   ingest URL and every configured destination.
5. Every proxy, its ingest URL, and its destinations are owned by a team. A user
   can only view or manage proxies belonging to a team they are a member of.
6. Creating or managing a proxy requires an authenticated user in a team;
   unauthenticated requests cannot create or view proxies.
7. When a webhook is posted to a proxy's ingest URL, the service delivers the
   received payload to every configured destination of that proxy over HTTP(S)
   using the configured method (POST or PUT).
8. Each destination receives the same payload structure as the incoming webhook.
9. Delivery to each destination is independent: if delivery to one destination
   fails, deliveries to the other destinations still proceed. Failed deliveries
   are not retried or replayed in this item (fire-and-forget).
10. Outbound delivery uses HTTP(S) POST or PUT only. No other HTTP method and no
    non-HTTP transport is used.
11. The proxy operates in simple proxy mode: the incoming payload is delivered
    without mapping/reshaping, without being stored, without retry or replay,
    without analytics, and without notifications.
12. **(Depends on Open Question R5)** Each proxy's ingest URL is unique per proxy
    and is not guessable. The exact, testable definition of "unique" and "not
    guessable" is pending the resolution of R5 and must be finalized before this
    criterion can be objectively verified.

## Out of Scope
Explicitly excluded from item #1. Each points to the later roadmap item that
owns it:

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
- **Analytics / stats** — roadmap #11.
- **Change detection** — roadmap #12.
- **Notifications (in-app & email)** — roadmap #13.
- **Test payloads** — roadmap #14.
- **Building teams / registration from scratch** — provided by the Laravel
  starter-kit boilerplate; not rebuilt here.
- **Throughput / latency / delivery-success targets** — none are set (Vision
  Open Question V8); no performance targets are asserted for the walking
  skeleton.

## Open Questions
- **R5 — Ingest-URL generation & security** (unresolved; technical; for the
  Principal Engineer) — see `docs/questions/prd-01-walking-skeleton-r5-ingest-url.md`.
  How per-proxy ingest URLs are created and protected (uniqueness, secrecy).
  This question **gates item #1**, and Acceptance Criterion 12 (ingest-URL
  uniqueness/secrecy) is not objectively testable until it is resolved.

## Handoff
- **Inputs:** `docs/product/vision.md`, `docs/product/roadmap.md` (item #1;
  resolved decisions R1, R2, R3, V1), Project Owner roadmap approval (2026-07-30)
  and the 2026-07-30 Project Owner decision to broaden item #1 to include
  fan-out.
- **Outputs:** this PRD.
- **Dependencies:** Laravel starter-kit auth/teams boilerplate (registration and
  teams are reused, not rebuilt).
- **Outstanding Questions:** R5 — ingest-URL generation & security — must be
  resolved by the Principal Engineer before implementation; gates Acceptance
  Criterion 12.
- **Next Agent:** Designer *(item #1 has user-facing UI: proxy creation with one
  or more destinations and the proxy/ingest-URL/destinations listing)*. The
  Principal Engineer must resolve R5 in parallel, as it gates implementation.
