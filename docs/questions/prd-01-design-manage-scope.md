# Question: PRD-01 item #1 UI management scope + mode-selector behaviour

- **Status:** RESOLVED — answered by the Project Owner, 2026-07-30.
- **Raised by:** Designer
- **Owner (must answer):** Product Manager *(requirement scope of PRD-01)*
- **Raised:** 2026-07-30
- **Resolved:** 2026-07-30 — Project Owner decisions recorded below; PRD-01
  updated accordingly (AC2, AC6, and a new AC16). See "Answers" section.
- **Gates:** Two sections of the item #1 design spec
  (`docs/design/design-01-walking-skeleton.md`) — the proxy detail/edit surface
  and the create-form mode control. Does **not** gate the create / list / view
  screens, which proceed on confirmed ACs.
- **Source:** `docs/product/prd-01-walking-skeleton.md` AC2, AC6, AC11 and the
  Designer task brief.

## Context
The item #1 UI clearly covers **create a proxy**, **list a team's proxies**, and
**view a proxy** (ingest URL + destinations + mode). Two points in the PRD are
ambiguous about UI scope and need a requirement decision — I will not reinterpret
them myself.

## Question 1 — Is any post-creation *management* (edit/delete) in item #1 scope?
- AC2 says: *"A proxy with zero destinations cannot be created; adding additional
  destinations is supported."* Is "adding additional destinations is supported"
  satisfied **entirely by the add-a-row control during creation**, or does item #1
  also require an **edit-existing-proxy** screen where a team member adds/removes
  destinations after the proxy exists?
- AC6 refers to *"Creating or managing a proxy."* Does "managing" at item #1
  include: editing a proxy's name, editing/adding/removing destinations after
  creation, or deleting a proxy / a destination? Or is item #1 strictly
  create + view + list, with edit/delete deferred?

Impact on design: if edit/delete are in scope I will spec an edit form (reusing
the create form) plus a destructive-action confirmation modal; if not, the detail
screen is read-only and I will mark edit/delete as a later attach point only.

## Question 2 — Mode selector behaviour when only simple mode functions at #1
- The proxy carries a `simple`/`enhanced` mode from day one (ADR-002), and the
  task brief says a mode selector should be present. But AC11 restricts item #1 to
  **simple proxy mode**, and every enhanced-mode behaviour (mapping #8, storage
  #5, retry #6) is out of scope. So at item #1, choosing "enhanced" would persist a
  mode value with **no observable behavioural difference** from simple.
- Should the create form: **(a)** show both options but render "Enhanced" as
  disabled / "coming soon" (selectable value = simple only), or **(b)** allow
  selecting and persisting "enhanced" now even though it behaves identically to
  simple at #1, or **(c)** omit the selector at #1 and default every proxy to
  simple?

Impact on design: this changes the create form's mode control state and the copy
shown next to it. My provisional design assumes **(a)** — selector present, simple
is default and the only functional choice, enhanced shown disabled with a "coming
soon (enhanced mode)" hint — pending your decision.

## Impact if unresolved
Non-blocking for the create/list/view screens. Blocks only the edit/delete surface
and the final mode-control state in the design spec; those sections stay Draft
until answered.

## Answers (Project Owner, 2026-07-30)

### Answer to Question 1 — YES, edit and delete are in item #1 scope
Item #1 **supports edit AND delete** of proxies and their destinations. This
confirms that:
- AC2 "adding additional destinations is supported" includes **post-creation**
  add/remove of destinations on an existing proxy (not only the add-a-row control
  during creation).
- AC6 "managing a proxy" includes editing a proxy's name, editing/adding/removing
  its destinations after creation, and **deleting** a proxy or an individual
  destination.

Design consequence (informational, not a design decision by the PM): the edit and
destructive-action surfaces the Designer described are in scope. The Designer owns
the actual UI.

### Answer to Question 2 — Enhanced option MAY be selectable in a partial state
The mode selector does **not** need a "coming soon"/disabled treatment purely
because Enhanced mode is incomplete. Pre-MVP, partially-functional code is
allowed to exist and is not polished or gated — nothing is released until MVP.
So the Enhanced option MAY be selectable and MAY persist an `enhanced` value even
though enhanced behaviours (mapping #8, storage #5, retry #6) are not yet
functional. Keep the selector functional-minimum. Provisional design option (a)
(Enhanced shown disabled / "coming soon") is therefore **not required**; options
(a) and (b) are both acceptable, with (b) — selectable Enhanced in a partial
state — explicitly permitted.

**What still matters for item #1:** correctness of the AC behaviour for the
**Simple** path. The Simple-mode acceptance criteria (delivery, fan-out,
analytics capture, etc.) remain the bar for item #1; the mode selector's presence
or partial Enhanced state does not add or remove any Simple-path requirement.

## Resolution
Recorded in PRD-01 (`docs/product/prd-01-walking-skeleton.md`), 2026-07-30:
- AC2 clarified so "adding additional destinations" explicitly covers
  post-creation add/remove.
- AC6 clarified so "managing" explicitly includes edit and delete of proxies and
  destinations.
- New AC16 added stating a team member can edit a proxy (name and destinations)
  and delete a proxy or a destination after creation, subject to the AC2 minimum
  of at least one destination and to team-ownership scoping (AC5).
- A note added recording that the mode selector may offer a selectable Enhanced
  option in a partial state and needs no "coming soon"/disabled gating; the Simple
  path is what item #1's ACs verify.

No new Open Question raised; no technical/UI decision made by the PM.
