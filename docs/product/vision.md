# Product Vision: Webhook Proxy Service

Status: Draft (pending Project Owner approval)
Owner-facing author: Product Manager
Last updated: 2026-07-30

> This is a **vision document**, not a PRD and not a feature spec. It describes
> what the product is, who it serves, and how we will judge success. It contains
> no acceptance criteria, task breakdowns, or implementation detail. Every
> statement here traces to the product interview; anything unresolved is captured
> under [Open Questions](#open-questions).

## Overview / What This Product Is

A SaaS-style web service where users register and create **webhook proxies**. A
proxy ingests an incoming webhook and fans it out to multiple destination
endpoints, with optional payload reshaping, storage, retries, and visibility
into what happened.

The product is built and run internally first, but it is designed, presented,
and treated as a serious SaaS platform. Teams are first-class from day one: a
lead developer invites role-based users to collaborate on webhook
configurations.

At a high level, users choose between two modes:

- **Simple proxy** — ingest and fan out with minimal processing.
- **Enhanced mode** — adds payload mapping, payload storage, and retry strategy.

## Problem

Few good solutions exist for this need. Developers today either pay a lot for a
third-party service or build the capability themselves. The goal is a
cost-efficient yet feature-rich alternative.

Existing solutions tend to reinvent the wheel and often lack **replay, stats,
and visibility**. Some "blindly send" downstream with no insight into delivery
attempts or timings.

The core pain has two roughly equal parts:

1. **Fan-out** — delivering one incoming webhook to many destinations.
2. **Payload reshaping** — transforming the incoming payload into the structure
   each destination expects.

Reliability and visibility sit alongside these as the supporting concerns.

Representative situations that spark the need:

- A developer wiring up integrations across services.
- A company migrating between SaaS tools, where existing ingestion endpoints
  expect a payload structure that the new provider no longer matches.

## Target Users

- **Small-to-medium development teams.** Teams are first-class from day 1: a lead
  dev invites users with roles (view / add / modify) and can remove access.
- **Technically inclined, but served with a no-code / low-code experience.** This
  includes a JSON editor with autocomplete driven by a known incoming structure,
  and validation of pasted/raw JSON against known structures.
- Users who need the product to be **failure-resistant**: unexpected properties
  are handled gracefully rather than causing errors, and users can be informed
  when an incoming structure change is detected.

**Not target users (for now):**

- Huge enterprises (a later audience).
- Non-technical business users.

Even though these groups are out of scope initially, the product should be
**architected for scalability from the start**.

## What It Must Do (High Level)

- **Ingest and fan out.** Ingest a webhook and deliver it to multiple endpoints.
- **Mode toggle.** Simple proxy vs. enhanced mode (mapping, payload storage,
  retry strategy).
- **Payload mapping / reshaping.** Pure JSON-to-JSON for the MVP, through a
  no-code editor with autocomplete and validation. Accept XML and form-encoded
  incoming payloads, but visualize them as JSON.
- **Retry / replay.** With configurable backoff strategies.
- **Decoupled upstream response.** Return success upstream even if a downstream
  delivery fails, with a user-defined response body and status code, handled
  securely.
- **Payload storage.** For inspection, debugging, and replay — especially useful
  when the upstream sender is primitive. Retention is team-level, starting at
  30 days, with a garbage collector, and possibly tied to future subscription
  tiers.
- **Analytics / stats.** Decoupled from retained payloads so they can be
  long-lived and trendable: successes, failures, and per-webhook drill-down.
  (Dashboard detail is deferred to planning.)
- **Sensitive data handling.** Encrypt stored payloads; visually obfuscate known
  sensitive properties (e.g. password, token, credit card) with user-defined
  additions; support incoming webhook verification tokens at an MVP level.
- **Change detection.** Detect changes in the incoming structure and notify.
- **Notifications.** In-app and email, with per-channel opt-out. Non-urgent
  notifications are in-app only; urgent ones use both channels.
- **Test payloads.** Users can submit a test payload to their endpoint.
- **Processing / dispatching.** Support both FIFO and Async processing, which
  shapes the queue design.
- **Pipeline-oriented architecture.** The processing leans toward a pipeline of
  steps so new steps can be added more easily (workflow-builder-like in spirit)
  without building a workflow builder now.

## Explicitly Out of Scope

For now, the product does **not** include:

- A workflow builder / iPaaS / Zapier-style UI.
- Conditional routing, scripting, or external lookups in the MVP (possible later
  if the pipeline architecture allows).
- Payment / billing in the MVP (it is an internal tool; Stripe or similar only if
  it goes public).
- Huge enterprises or non-technical users as an audience.

## How We'll Know It's Succeeding

Success is measured through **retention and product-health signals**, not
business or revenue metrics yet.

The headline success signal is **no lost data / no missed webhooks**:

- When storage is enabled, the raw payload is always captured.
- No mapping, processing, or code error should fail before capture.
- Desired but likely post-MVP: capture even if our own API is offline.

There are no hard targets set yet, but **throughput and processing scalability
matter**.

## Known Constraints

- **Stack:** Laravel + Vue.js via Inertia, using the Laravel starter-kit auth and
  teams boilerplate.
- **Data & infrastructure:** MySQL (fine for the MVP), Redis for the MVP queue,
  hosted on AWS via Laravel Forge.
- **Queue evolution:** Kafka and other more scalable queue/streaming options are
  open for suggestion.
- **Build model:** Built by the owner using Claude sub-agents and skills. It is a
  learning project with no timeline or deadline, but it is to be approached as a
  serious SaaS platform — the informal build model must not lower the ambition.
- **Compliance:** No additional compliance requirements today.
- **Budget:** No budget currently; the owner wants to host a demo.

## Open Questions

These are unresolved or deferred. They must not be treated as settled facts.
Items with a technical dimension are for the Principal Engineer; product/scope
items are for the Project Owner.

1. **Outgoing delivery format/transport** — HTTP(S) POST only, or other
   transports? (Not confirmed.)
2. **Webhook verification-token standards** — which standards to support at MVP
   (existing standards to be reviewed).
3. **Scalable queue/streaming choice beyond Redis** — Kafka-with-Laravel and
   alternatives; open for recommendation.
4. **Capture-even-if-API-offline architecture** — desired, likely post-MVP.
5. **Retention as a subscription-tier lever** — a future consideration.
6. **Storage regions and possible Postgres for ingestion** — to revisit in the
   future.
7. **Detailed analytics / dashboard scope** — deferred to planning.
8. **Specific throughput / latency / delivery-success targets** — none set yet.
