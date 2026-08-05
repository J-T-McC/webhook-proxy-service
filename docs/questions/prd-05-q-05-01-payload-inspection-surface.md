# Question Q-05-01: Does #5 include a user-facing payload-inspection surface?

- **Status:** RESOLVED — **Option B** (Project Owner, 2026-08-05)
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(scope decision — no vision, roadmap,
  PRD, or ADR states which item delivers the payload-viewing surface)*
- **Raised:** 2026-08-05
- **Resolved:** 2026-08-05 — by Project Owner decision (see Answer below)
- **Gates:** **BLOCKING** for PRD-05 approval *(cleared 2026-08-05)*. The answer
  decides whether Feature #5 has a user-facing surface, and therefore whether it
  must clear the **Designer** gate before Technical Design (mechanical routing
  rule: a PRD carrying a UX Direction section routes to the Designer, no
  exceptions).
- **Source:** Roadmap #5 (`docs/product/roadmap.md`) — payloads are "captured and
  stored **for inspection and debugging**"; Vision — "**Payload storage.** For
  inspection, debugging, and replay — especially useful when the upstream sender is
  primitive."

## Context
Roadmap #5 and the vision both name **inspection** as the purpose of payload
storage. Neither says **which roadmap item delivers the surface a user inspects
payloads through.** Searching `docs/` produces nothing that settles it:

- #5's own line and build-ahead note describe storage shape, retention, GC, and
  seams — no UI.
- #6 (retry & replay) needs a surface where a user picks a stored payload and
  replays it to some/all destinations (R4, resolved) — that is the first place a
  stored-payload list is unavoidable.
- #11 (analytics) is success/failure counts and per-webhook drill-down, and is
  explicitly kept **separate** from retained payloads.
- #10 (sensitive data handling) — which **visually obfuscates known and
  user-defined sensitive fields** — depends on #5 and therefore lands **after** it.

Because nothing implies an answer, the Product Manager will not invent a UI
requirement. PRD-05 is drafted with **no UI** (Option B) pending this answer.

## Question
Does Feature #5 deliver a user-facing surface for viewing stored payloads?

**Option A — inspection surface in #5.** #5 ships storage + retention + GC **and** a
read-only surface where a team member can see a proxy's recent received events and
view the stored payload content (raw, and dispatched output for enhanced-mode
proxies), team-scoped and gated by the existing proxy read permission.
*Consequences:* PRD-05 gains a UX Direction section and inspection acceptance
criteria; #5 must clear the **Designer** gate before Technical Design; #5 becomes a
larger slice.

**Option B — storage/retention/GC only (Product Manager's recommendation).** #5
ships the lifecycle and the retrievability guarantee with **no UI at all**. The
viewing surface lands with **#6**, where a stored-payload list is required anyway
for replay — or with a later slice the Owner names.
*Consequences:* PRD-05 has no UX Direction and routes straight to the Principal
Engineer; #5 delivers a system-level outcome (bounded storage, guaranteed
retrievability window) rather than a visible one; "inspection and debugging" is
realised one item later.

## Why the Product Manager recommends Option B
- **#10 lands after #5.** A payload viewer at #5 shows **unobfuscated** payload
  content — passwords, tokens, card data — because field-level obfuscation is #10's
  scope and #10 depends on #5. Option A therefore either ships a viewer that
  displays secrets in plaintext in the UI, or drags part of #10 forward.
  (Note: at-rest **body** encryption already exists from #3 per ADR-010 Amendment B;
  that protects storage, not what a viewer renders on screen.)
- **#6 needs a stored-payload surface regardless.** Building a viewer at #5 and then
  a replay-selection surface at #6 risks two surfaces or a rebuild; #6 is one item
  away.
- **#5 is still a real slice without UI.** It closes the unbounded-growth gap PRD-03
  AC11 knowingly deferred, and gives #6 a stated retrievability guarantee to build
  on.
- **Nothing is lost, only sequenced.** Payloads are stored either way; Option B only
  defers *when* they become visible.

## Impact if unresolved
PRD-05 cannot be approved and #5 cannot start: the answer determines the PRD's
content (UX Direction + inspection ACs or not) and its routing (Designer vs.
Principal Engineer). Everything else in PRD-05 — retention, GC, dispatched-output
storage, V4/V5/V6 — is settled and unaffected by either answer, apart from AC16
(team-scoped, permission-gated access), which applies to any read path Option A
would add.

## Answer
**Project Owner, 2026-08-05 — OPTION B, the Product Manager's recommendation
accepted as written.** Feature #5 delivers **no user-facing payload-inspection
surface**. It ships payload storage, the 30-day retention window, garbage
collection, and the within-window retrievability guarantee only.

The rationale is accepted as argued: **#10 field obfuscation depends on #5 and
therefore lands after it**, so a payload viewer shipped at #5 would render
**unobfuscated** secrets on screen (#3's at-rest body encryption protects storage,
not what a viewer displays); and **#6 needs a stored-payload selection surface
regardless**, so building a viewer at #5 risks two surfaces or a rebuild one item
later. Nothing is lost, only sequenced — payloads are stored either way. The
viewing surface lands with **#6**, after or alongside #10's obfuscation.

**Consequences, now binding on PRD-05:**
- PRD-05 carries **no UX Direction section** and adds no UI.
- **No Designer gate** applies to Feature #5.
- Under the mechanical routing rule, PRD-05 routes to the **Principal Engineer**
  (carrying Q-05-03).
- AC16 (team-scoped, permission-gated access) stands as the standing constraint on
  any read path, whenever one is introduced.

This question is closed. PRD-05's approval is no longer blocked by it; the PRD
still awaits Project Owner approval separately.
