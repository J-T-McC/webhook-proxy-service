# Question Q-06-02: Replay — surface content, mode gating, permission bundle

- **Status:** RESOLVED — **(a) Owner-supplied third option: masked content +
  reveal; (b) Option A; (c) Option A** (Project Owner, 2026-08-12)
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(scope and access decisions — the
  Q-05-01 ruling deferred the surface here without settling its content; no
  document states replay's mode gating or its permission mapping)*
- **Raised:** 2026-08-12 · **Resolved:** 2026-08-12
- **Gates:** **BLOCKING** for PRD-06 approval *(cleared 2026-08-12)* — AC9 (b),
  AC14 (c), and the UX Direction's surface scope (a) in
  `docs/product/prd-06-retry-replay.md` take their values from this answer.
- **Source:** `docs/questions/prd-05-q-05-01-payload-inspection-surface.md`
  (RESOLVED, Owner 2026-08-05 — Option B); Roadmap #6 + R4; Vision — "Payload
  storage. For inspection, debugging, and replay"; PRD-02 / Q-02-01 (permission
  bundles, Member ownership rule); ADR-009 (anticipates a
  `proxy:replay`-style permission case).

## Context
Three sub-decisions, bundled because all shape the same surface and action.

**(a) Surface content.** Q-05-01's ruling: "#6 needs a stored-payload selection
surface regardless… The viewing surface lands with **#6, after or alongside
#10's obfuscation**." #10 is not built and lands after #6 in the backlog. The
ruling's own rationale for rejecting a viewer at #5 — a viewer before #10
renders **unobfuscated secrets** (passwords, tokens, card data) on screen —
applies identically to a viewer at #6. "After or alongside" leaves open whether
#6 ships content rendering now or only the selection surface.

**(b) Replay mode gating.** Raw capture is **mode-independent** (Owner's R2
capture override, 2026-08-04) and #5 retention applies in both modes (PRD-05
AC4), so a simple-mode proxy's payloads are retrievable within the window —
replay is *possible* in both modes. But the vision groups replay under payload
storage, which as a *feature* is enhanced-mode; nothing states which reading
governs the replay action.

**(c) Replay permission bundle.** PRD-02's model requires replay to be a
permission, never a role check; ADR-009 anticipated the case. Q-02-01 (Owner,
2026-08-03) set the existing pattern: all three roles hold full CRUD, with
Member **ownership-limited** on update/delete (own records only). No document
states which bundles hold replay or whether the ownership rule applies to it.

## Question

**(a)** Does #6's received-events surface render stored payload **content**?

- **Option A — descriptors and state only (PM recommendation).** The surface
  shows non-content descriptors (received time, size, content type), the
  retained/cleaned state, and per-destination delivery state — enough to pick
  an event and replay it. **No payload content is rendered.** Content viewing
  arrives with/after #10's obfuscation, exactly as the Q-05-01 rationale argued.
- **Option B — full content viewer at #6.** Renders raw (and enhanced-mode
  dispatched) payload content now, accepting that everything displays
  unobfuscated until #10 — the exposure the Owner declined at #5.

**(b)** Is replay available for **both proxy modes** or **enhanced-mode only**?

- **Option A — both modes (PM recommendation).** The payload exists and is
  guaranteed retrievable in both modes (Owner's own R2 override + PRD-05 AC4/
  AC7); replay is the recovery action over that guarantee, and the vision's
  enhanced trio names "retry strategy", not replay. Also the only option that
  makes any of #6 user-reachable before #7 ships the mode toggle.
- **Option B — enhanced-only.** Groups replay with storage-as-a-feature;
  consequence: with retry likely enhanced-gated too (Q-06-01a) and no toggle
  until #7, all of #6 would be user-invisible until #7.

**(c)** Which role bundles hold the replay permission?

- **Option A — all three roles, no ownership limit (PM recommendation).**
  Matches the Owner's deliberately permissive full-grid Q-02-01 ruling; the
  ownership rule was applied to *destructive/altering* actions (update/delete),
  and replay alters no record — it re-sends traffic the proxy was built to send.
- **Option B — all three roles, Member ownership-limited** (Member replays only
  on proxies they created — the update/delete pattern applied to replay).
- **Option C — Owner and Admin only.**

The Owner may answer each part independently or name different shapes.

## Impact if unresolved
PRD-06 cannot be approved (AC9/AC14 not testable) and the Designer cannot scope
the surface — (a) decides whether the surface is a list or a viewer, the single
largest design variable in #6. Retry (Q-06-01) is unaffected by this question.

## Answer
- **Answered By:** Project Owner
- **Answered:** 2026-08-12

**(a) Surface content — a third option, neither A nor B as framed: the surface
renders payload content, obfuscated by default with an explicit reveal.** The
received-events surface **does** render stored payload content, but behind a
**whole-payload mask** — content hidden by default with an explicit user reveal
action, "similar to view password"; activating the reveal exposes the **full
raw payload**. Owner clarification on follow-up: this is a whole-payload mask
only — **no field-level secret detection and no per-field obfuscation**, which
remain **#10 scope**, untouched. The reveal is available to **anyone who can
view the surface** (the existing proxy **read** permission); **no distinct
reveal permission** is introduced.

**(b) Replay mode gating — Option A: both modes.** Replay is available for
simple-mode and enhanced-mode proxies alike.

**(c) Replay permission — Option A: all three roles hold the replay
permission, with no Member ownership limit.**

Rendered into PRD-06: AC9 (both modes), AC14 (permission mapping), new AC25
(masked viewer + reveal), amended AC22 (scope boundary vs. #10), and the
rewritten UX Direction (list + masked viewer with reveal toggle). This question
is closed; it no longer blocks PRD-06 approval.
