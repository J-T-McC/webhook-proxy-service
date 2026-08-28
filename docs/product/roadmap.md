# Product Roadmap: Webhook Proxy Service

Status: Approved by Project Owner on 2026-07-30
Owner-facing author: Product Manager
Last updated: 2026-08-27
Revised 2026-07-30: item #1 broadened to include fan-out per Project Owner
decision — old item #2 (fan-out) merged into #1; backlog renumbered from 15 to
14 items. Approval status retained; this is a post-approval scope change.
Revised 2026-07-30: added forward-compatibility Build-ahead notes per Project
Owner. Approval status retained.
Revised 2026-07-30: refined the mapping item (#8) and the R3 resolved decision
per Project Owner insight — a proxy holds multiple maps, one selected per
incoming event by a key/value condition, with a global/default map; the
resulting structure is still applied uniformly to all destinations. This is
conditional map SELECTION, not conditional destination routing (which remains
out of scope). Approval status retained; design-ahead only, nothing built now.
Revised 2026-08-03: **item #2 trimmed and reframed** per Project Owner
direction. Team-membership mechanics (invite by email with a role,
accept/decline, change a member's role, remove a member) are already delivered
by the starter kit boilerplate (`TeamRole`, `TeamPolicy`, `TeamPermission`,
`TeamMemberController`, `TeamInvitationController`, `InviteMemberModal.vue`,
`teams/Edit.vue`) and are no longer #2's scope. The genuine gap #2 now targets:
those roles only gate team-administration actions today — the product's actual
resource, proxies, is authorized purely by team membership
(`app/Policies/ProxyPolicy.php`), with no permission distinction between roles.
#2 is now specifically a **permission-based** (not role-literal), **team-scoped**
authorization model that governs proxy actions (view/create/update/delete),
with the existing roles as permission bundles. See
`docs/product/prd-02-role-based-collaboration.md`. Approval status of the
roadmap as a whole is retained; this is a post-approval scope refinement of a
single item, matching how #1's fan-out and #8's map-selection revisions were
handled.
Revised 2026-08-27: **item #10 widened to cover outbound authentication** per
Project Owner ruling on
`docs/questions/prd-10-q-10-01-outbound-destination-authentication.md` (RESOLVED,
approved 2026-08-27). #10's approved line covers webhooks coming **in**; the Owner
ruled that a destination credential this service presents when it dispatches
belongs in the same item rather than becoming its own line. Approval status
retained; this is a post-approval scope change of a single item, made by the Owner,
matching how #1 and #2 were handled. **Also pending at #10, and not yet ratified:**
`docs/product/prd-10-sensitive-data-handling.md` `## Amendment A` adds **outbound
request signing** — the reverse direction, where a destination verifies that a
dispatch came from this service — as AC54–AC64. That is a further widening of #10
and it takes effect only if the Owner approves that PRD; it is named here so a
reader of this line knows it is in flight, not so it is treated as settled.
Revised 2026-08-27: **item #15 added — pause and resume dispatch**, per Project
Owner ruling that it is its own roadmap item rather than #10 scope, on the ground
that it has value well beyond secret rotation (destination outages, maintenance
windows). Backlog grows from 14 items to 15. Approval status retained; the item was
added by the Owner, and `docs/product/prd-15-pause-and-resume-dispatch.md` is Draft
and awaits the Owner's approval separately.

> This is a **prioritized feature backlog**, not a set of PRDs. Each line names a
> feature and states the single outcome a user or the system gains once it is
> done. There are no acceptance criteria, task breakdowns, or implementation
> detail here. Every item traces to `vision.md`; nothing is invented or
> embellished. Anything that would block writing a PRD is captured under
> [Open Questions](#open-questions).
>
> **Sequencing principle:** the list is ordered so each item builds on the ones
> before it. Item #1 is the smallest end-to-end slice — a walking skeleton — that
> proves the product works: a webhook goes in and a webhook goes out. Later items
> layer on collaboration, enhanced mode, mapping, storage, retries, analytics,
> and notifications.
>
> Because a single capability is often slow-rolled across several items, each
> item below also carries a **Build-ahead note** naming the later item(s) that
> slice must be designed to accommodate. This exists so the right seams and
> extension points are left in place up front, rather than brute-forcing the
> minimal thing now and refactoring an earlier item when the next one lands. The
> notes stay at the requirements/sequencing level; where a seam is genuinely an
> architecture decision it is handed to the Principal Engineer rather than
> resolved here.
>
> **PRDs will be written one item at a time, item #1 first, and only after the
> Project Owner approves this roadmap.**

## Backlog

1. **Walking skeleton: ingest → fan-out delivery** — A user (within a team) can
   create a proxy with one ingest URL and one or more destinations, and an
   incoming webhook posted to the ingest URL is delivered over HTTP(S) — via POST
   or PUT only — to every configured destination. All destinations of a proxy
   receive the same payload structure. The data model is designed for many
   destinations per proxy from the first commit, so there is no
   single-destination assumption to refactor out of. The Laravel starter kit
   already provides registration and teams, so #1 does not stand up teams from
   scratch; instead, all data, entities, and ERDs are team-scoped for data
   ownership from the first commit. *(Vision: "Ingest and fan out"; "Simple
   proxy"; Problem: "Fan-out". Reuses the starter-kit auth/teams boilerplate noted
   in Known Constraints. Resolves R1, R3, and V1.)*
   **Build-ahead note:** This ingest→fan-out flow is the spine every later item
   extends, so it must be shaped so queued dispatch (#4), retry/replay of a
   delivery (#6), and the enhanced-mode pipeline steps — storage (#5) and mapping
   (#8) — slot into the ingest→deliver flow without reworking it (Principal
   Engineer to fix the approach; the vision's "pipeline-oriented architecture" is
   this seam). Each delivery attempt must record its outcome from the first commit
   so analytics (#11) is built from real attempt records rather than reconstructed
   later. The proxy must carry the simple/enhanced mode concept from day one so
   the #7 toggle is a state change and #5's enhanced-only storage is a gate, not a
   re-model. The upstream-response path must not assume it waits on downstream
   success, so the decoupled response (#3) is later configuration, not a rewrite.
   Team-scoping (R1) already applies to every entity here.

2. **Role-based collaboration (permission-gated proxy authorization)** — Team
   ownership of data and team-membership mechanics (invite, accept/decline,
   change a member's role, remove a member) are already baked in at #1 / the
   starter kit boilerplate, so this item is **not** about introducing team
   ownership or rebuilding membership mechanics. It is specifically about
   closing the gap where those roles currently govern only team-administration
   actions: a team-scoped **permission** model — never a direct role check —
   that gates proxy actions (view / create / update / delete), with the
   existing Owner/Admin/Member roles acting as bundles of permissions. *(Vision:
   Target Users; "Teams are first-class from day 1". Depends on #1. Resolves R1.
   Trimmed and reframed 2026-08-03 per Project Owner direction — see revision
   note above and `docs/product/prd-02-role-based-collaboration.md`.)*
   **Build-ahead note:** The permission model must be able to govern actions
   introduced by every later item — mapping edits (#8), replay (#6), storage and
   mode configuration (#5, #7), notification opt-outs (#13) — so permissions are
   defined against proxy actions in general, not hard-wired to today's four
   (view/create/update/delete) (Principal Engineer to fix the approach, including
   which mechanism — Laratrust, Spatie laravel-permission, or Jetstream-native
   permissions — implements team-scoped permission storage/checks).

3. **Decoupled upstream response** — A proxy returns a user-defined status code
   and response body to the upstream sender immediately, independent of whether
   any downstream delivery succeeds. *(Vision: "Decoupled upstream response".
   Depends on #1.)*
   **Build-ahead note:** The immediate, user-defined upstream response is the same
   seam #4 relies on: once dispatch moves to a queue, the response must still not
   block on delivery outcome. Build the response independent of dispatch so #4 can
   make dispatch asynchronous without touching the response path. The vision notes
   this response is "handled securely," which #10 later hardens.

4. **Queued processing (FIFO & Async)** — Deliveries are dispatched through a
   queue that supports both FIFO and asynchronous processing modes. *(Vision:
   "Processing / dispatching"; "Pipeline-oriented architecture". Depends on #1.)*
   **Build-ahead note:** The queue is where retry/backoff (#6) attaches and where
   storage capture (#5) and analytics events (#11) hook in, so dispatch must
   expose per-attempt steps rather than a single fire-and-forget send (Principal
   Engineer to fix the approach — this is the vision's pipeline architecture). The
   FIFO/Async design must accommodate a later scalable queue/streaming choice
   beyond Redis (V3) and as-yet-unset throughput targets (V8).

5. **Payload storage & retention** — When storage is enabled (enhanced mode
   only, not simple proxy mode), raw incoming payloads are captured and stored for
   inspection and debugging, with team-level retention starting at 30 days and a
   garbage collector that removes expired payloads. The raw input is captured and
   never mutated; the dispatched output is saved separately. *(Vision: "Payload
   storage". Depends on #1; benefits from #4. Resolves R2 — storage sits in
   enhanced mode, so the #5/#7 boundary holds.)*
   **Build-ahead note:** The storage shape must keep the raw captured input
   separate from — and immutable relative to — the dispatched output (R2) from the
   start, so replay (#6) can re-dispatch the raw payload and sensitive-data
   handling (#10) can encrypt at rest and obfuscate fields without re-modeling
   (Principal Engineer to fix the approach). Storage must be gated by the mode
   concept present since #1 so #7 only surfaces the toggle. Retention/GC is
   team-level and may later become a subscription-tier (V5) or storage-region (V6)
   lever — leave that extension point. Stats must not be derived from expiring
   payloads; analytics (#11) reads the attempt records emitted since #1/#4.

6. **Retry & replay** — Failed deliveries are retried with a configurable backoff
   strategy, and a user can manually replay a stored payload to specific
   destinations or to all of them. *(Vision: "Retry / replay". Depends on #4 and
   #5. Resolves R4.)*
   **Build-ahead note:** Retry outcomes and manual replays must emit the same
   delivery-attempt records/events introduced at #1 so failure alerts (#13) and
   analytics (#11) consume them without a parallel path. Replay target selection —
   specific destinations or all (R4) — must reuse the #1 fan-out destination
   model, not a separate one.

7. **Enhanced-mode toggle** — A proxy can be switched between simple proxy and
   enhanced mode, where enhanced mode enables mapping, storage, and retry
   strategy. *(Vision: "Mode toggle". Depends on #5 and #6.)*
   **Build-ahead note:** The toggle governs which pipeline steps run (mapping #8,
   storage #5, retry #6); because #1 already carries the mode concept and #4
   exposes pipeline steps, this item wires steps to the mode rather than
   re-modeling either. Enhanced mode must stay extensible to later steps — mapping
   (#8), multi-format ingestion (#9), change detection (#12) — consistent with the
   vision's pipeline direction (Principal Engineer owns step composition).

8. **Payload mapping / reshaping** — A user can reshape an incoming JSON payload
   into the structure the proxy's destinations expect through a no-code editor with
   autocomplete and validation. A single proxy can hold **multiple maps**, because
   one ingest URL commonly receives many different payload structures (e.g. Stripe
   sends `charge.succeeded`, `invoice.paid`, and other event types to one URL).
   One map is **selected per incoming event** by matching a key against a specific
   value (e.g. a `type == "CHARGE"` field selects that event's map), with support
   for a **global/default map** that applies when no condition matches. Whichever
   map is selected for a given event, every destination of that proxy receives the
   same reshaped payload for that event. *(Vision: "Payload mapping / reshaping";
   Problem: "Payload reshaping". Depends on #7. Resolves R3. Refined 2026-07-30 per
   Project Owner — conditional map selection, see revision note in header.)*
   **Build-ahead note:** Mapping is per-proxy but no longer one map per proxy — a
   proxy owns a set of maps, and per event exactly one map is chosen (a
   key/value-matched map, else the global/default) and applied to produce one
   reshaped payload for all destinations (R3), consistent with #1's same-structure
   fan-out. This is conditional map SELECTION (which reshaping map to apply), NOT
   conditional destination routing — sending different events to different
   destinations stays out of scope (see Notes on Scope Boundaries). Capture the
   proxy's known/expected incoming structure as a first-class thing here — not just
   a transform — so multi-format ingestion (#9) can feed XML/form-encoded input as
   JSON into the same editor and change detection (#12) can compare against it
   (Principal Engineer to fix the approach). The map-selection mechanism (the
   matching key/value condition and the global/default fallback) is a new seam to
   leave room for; its exact precedence/fallback rules and matching syntax are Open
   Questions to settle at this item's PRD (see M1, M2). Test payloads (#14) will
   exercise this mapping and its selection.

9. **Multi-format ingestion** — A proxy can accept XML and form-encoded incoming
   payloads and present them as JSON for mapping. *(Vision: "Payload mapping /
   reshaping" — "Accept XML and form-encoded incoming payloads, but visualize
   them as JSON". Depends on #8.)*
   **Build-ahead note:** XML and form-encoded input must be normalized to the same
   JSON representation the mapping editor (#8) and change detection (#12) already
   use, so there is no second mapping or comparison path. (Vision: "visualize them
   as JSON.")

10. **Sensitive data handling** — Stored payloads are encrypted, known and
    user-defined sensitive fields are visually obfuscated, and incoming webhooks
    can be verified with a token at an MVP level. *(Vision: "Sensitive data
    handling". Depends on #5.)*
    **Build-ahead note:** Encryption and field obfuscation apply to the
    raw+dispatched payloads defined at #5, and user-defined sensitive fields extend
    a known-field default — so #5's storage/display path must leave room for
    encryption-at-rest and field-level obfuscation (Principal Engineer to fix the
    approach). Incoming verification tokens sit on the #1 ingest path as a
    pre-processing step and are gated by the token-standards question (V2).

11. **Analytics / stats** — Users can see success and failure counts and drill
    down per webhook, with stats kept separately from retained payloads so they
    remain long-lived and trendable. *(Vision: "Analytics / stats". Depends on
    #4; dashboard detail deferred — see Open Question V7.)*
    **Build-ahead note:** Stats are built from the delivery-attempt records emitted
    since #1/#4 and kept separate from retained payloads (which expire under #5
    retention) so they stay long-lived and trendable — this is exactly why #1 must
    emit attempt records rather than have #11 reconstruct them later. Dashboard
    scope stays deferred (V7) and throughput targets unset (V8).

12. **Change detection** — The system detects when an incoming payload's
    structure changes from what a proxy expects. *(Vision: "Change detection".
    Depends on #8.)*
    **Build-ahead note:** Change detection compares the incoming structure against
    the expected/known structure captured at #8 (and normalized by #9), and must
    emit an event the notifications system (#13) consumes — a result event, not
    just UI state.

13. **Notifications (in-app & email)** — Users receive in-app notifications and,
    for urgent events, email as well, with per-channel opt-out. *(Vision:
    "Notifications". Depends on #12 for change-detection alerts; usable earlier
    for delivery-failure alerts once #6 exists.)*
    **Build-ahead note:** Notifications consume events already emitted by #6
    (delivery failure) and #12 (change detection); the per-channel opt-out and the
    urgent-both / non-urgent-in-app rule must be general across event types, so
    dispatch keys off event severity rather than two hard-coded sources.

14. **Test payloads** — A user can submit a test payload to a proxy endpoint to
    exercise its configuration. *(Vision: "Test payloads". Depends on #1; more
    useful after #8.)*
    **Build-ahead note:** A test payload must run through the same ingest→pipeline
    path as a real webhook — exercising mapping (#8), storage (#5), and delivery —
    rather than a separate mock path, reusing the #1 spine.

15. **Pause and resume dispatch** — A user can pause dispatch for a proxy and
    resume it later, so that work stops going out to destinations while a
    destination is down, under maintenance, or being reconfigured, and then drains
    in order when it is resumed. **Ingest never pauses**: incoming webhooks are
    still accepted, still answered under #3, and still captured, because the
    product's zero-data-loss policy holds and a member who wants ingestion stopped
    pauses the third party at source instead. *(Added 2026-08-27 per Project Owner
    ruling — its own item, not #10 scope, because its value is independent of secret
    rotation. Depends on #4 for the dispatch mechanism and #6 for retry; interacts
    with #5's retention window. See
    `docs/product/prd-15-pause-and-resume-dispatch.md`.)*
    **Build-ahead note:** Pause is a **dispatch-side** state, so it must be visible
    to every mechanism that can start work on a proxy — the FIFO advancer, the FIFO
    sweeper's idle-proxy nudge, and the due-retry sweeper — rather than being
    enforced at a single call site that the schedulers bypass (Principal Engineer to
    fix the approach; named as an Open Question at this item's PRD). **Ordering on
    resume is already free and must not be re-engineered**: FIFO order derives from
    the atomic claim in the advancer, not from timing, so a paused proxy drains in
    order however long it was paused. Paused events keep aging under #5's retention
    window like any other, which is a consequence the member is told about before
    they pause, not one they discover on resume.

## Notes on Scope Boundaries

Carried from the vision's **Explicitly Out of Scope** so they are not mistaken
for backlog gaps: no workflow-builder / iPaaS / Zapier-style UI; no conditional
routing, scripting, or external lookups in the MVP; no payment / billing; and no
enterprise or non-technical audience. The "pipeline-oriented architecture" the
vision describes is an architectural direction for the Principal Engineer, not a
user-facing backlog item, and so does not appear as its own line above.

## Open Questions

These must be resolved before the relevant PRD can be written. Items with a
technical dimension are for the Principal Engineer; product/scope items are for
the Project Owner. The vision's existing eight open questions still stand and are
referenced by number where they gate a backlog item.

**Carried forward from the vision (still open):**

- V2. **Webhook verification-token standards** — which standards at MVP? Gates
  #10. *(Vision Open Question 2.)*
- V3. **Scalable queue/streaming choice beyond Redis** — gates the design of #4.
  *(Vision Open Question 3.)*
- V4. **Capture-even-if-API-offline architecture** — likely post-MVP; may reshape
  #5. *(Vision Open Question 4.)*
- V5. **Retention as a subscription-tier lever** — future; may extend #5. *(Vision
  Open Question 5.)*
- V6. **Storage regions and possible Postgres for ingestion** — future; touches #5.
  *(Vision Open Question 6.)*
- V7. **Detailed analytics / dashboard scope** — deferred; must be settled before
  #11's PRD. *(Vision Open Question 7.)*
- V8. **Throughput / latency / delivery-success targets** — none set; affects #4
  and #11. *(Vision Open Question 8.)*

**Still open (for the Principal Engineer):**

- R5. **Ingest-URL generation & security** — How are per-proxy ingest URLs
  created and protected (uniqueness, secrets)? *(Technical — for the Principal
  Engineer; gates #1.)*

**Still open (to settle at the #8 mapping PRD):**

- M1. **Map-selection precedence & fallback** — When a proxy holds multiple maps,
  what are the exact precedence and fallback rules for choosing one per event? For
  example: does the global/default map apply only when no conditional map matches,
  and what happens if more than one conditional map matches? *(Raised 2026-07-30
  by Project Owner insight; must be settled before #8's PRD. Not answered here.)*
- M2. **Map-selection matching syntax** — How is the selecting condition expressed
  (e.g. the key path and value-match semantics for something like
  `type == "CHARGE"`)? *(Raised 2026-07-30 by Project Owner insight; must be
  settled before #8's PRD. Not answered here.)*

## Resolved Decisions

Answered by the Project Owner on 2026-07-30 and folded into the backlog items
above. Retained here so the decisions are not lost.

- R1. **Team model in item #1** — The Laravel starter kit already provides
  registration and teams, so #1 does not stand up teams from scratch. However, all
  data, entities, and ERDs must be team-scoped for data ownership from the first
  commit, so #1 is team-scoped from the start. Consequently #2 is not about
  introducing team ownership (baked in at #1) but specifically about role-based
  collaboration: inviting users, view/add/modify roles, and removing access.
  *(Applied to #1 and #2.)*
- R2. **Raw capture in simple mode** — Raw payloads are captured only when storage
  is enabled (enhanced mode), not in simple proxy mode. When storage is enabled,
  the raw input is captured and then the dispatched output is saved separately; the
  raw input is never mutated. Confirms storage sits in enhanced mode, so the #5/#7
  boundary holds. *(Applied to #5.)*
- R3. **Mapping vs. fan-out granularity** — Mapping is per-proxy, not
  per-destination. All destinations of a proxy receive the same payload structure.
  *(Applied to #1 and #8.)*
  *Refined 2026-07-30 (Project Owner):* "one mapping per proxy" becomes "one map
  SELECTED per event." A proxy holds multiple maps; per incoming event exactly one
  map is chosen — either a map whose key/value condition matches (e.g.
  `type == "CHARGE"`) or a global/default map when no condition matches — and that
  single selected map is applied uniformly to all destinations. R3 still holds in
  spirit: all destinations of a proxy receive the same resulting structure for a
  given event. This is conditional map selection, not conditional destination
  routing (which remains Out of Scope per the vision). *(Applied to #8.)*
- R4. **Replay target selection** — When replaying a stored payload, the user can
  choose specific destinations or all of them. *(Applied to #6.)*
- V1. **Outgoing delivery format/transport** — Outbound delivery supports HTTP(S)
  POST and PUT only. No other transports or methods. *(Applied to #1; gated #1.)*
