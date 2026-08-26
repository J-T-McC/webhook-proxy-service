# Question Q-06-01: Retry — mode gating, configurable dimensions, defaults

- **Status:** RESOLVED — **(a) Option B; (b) confirmed as proposed** (Project
  Owner, 2026-08-12)
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(business/product decision — no
  vision, roadmap, PRD, or prior ruling states these values)*
- **Raised:** 2026-08-12 · **Resolved:** 2026-08-12
- **Gates:** **BLOCKING** for PRD-06 approval *(cleared 2026-08-12)* — AC1 and
  AC2 of `docs/product/prd-06-retry-replay.md` take their concrete values from
  this answer; a Reviewer cannot verify them until it is given.
- **Source:** Roadmap #6 — "Failed deliveries are retried with a configurable
  backoff strategy" (unqualified); Vision — "**Retry / replay.** With
  configurable backoff strategies" and "**Enhanced mode** — adds payload
  mapping, payload storage, and **retry strategy**"; Roadmap #7 — enhanced mode
  "enables mapping, storage, and retry strategy".

## Context
Two upstream statements pull in different directions and neither sets numbers:

- The **vision's enhanced-mode trio** and roadmap **#7** place "retry strategy"
  among the capabilities enhanced mode enables — implying retry (or at least its
  configurability) is enhanced-gated, like #5's dispatched-output store, dormant
  until #7 surfaces the toggle. (#7 depends on #6, so building it now and
  surfacing it at #7 is the intended sequence either way.)
- The roadmap **#6 line itself** is unqualified: "Failed deliveries are
  retried…" — no mode condition.

Separately, "configurable backoff strategies" names configurability but not the
dimensions, the offered strategies, the defaults, or any cap. Per its role the
Product Manager does not invent these values.

## Question

**(a) Mode gating — which proxies get automatic retry?**

- **Option A — retry is enhanced-mode only (PM recommendation).** A simple-mode
  proxy keeps today's single-attempt behavior; automatic retry and its
  configuration exist only for enhanced-mode proxies (gated on the existing
  ADR-002 mode attribute; the toggle UI remains #7). *Basis:* the most direct
  reading of the vision's enhanced trio and the #7 line; consistent with how #5
  gated the output store. *Consequence:* retry is dormant in the UI until #7 —
  which immediately follows and depends on #6.
- **Option B — a fixed system default retry for all proxies; per-proxy
  configurability enhanced-only.** Simple proxies gain a non-configurable
  baseline retry (reliability for everyone); the *strategy* (the configurable
  part) stays the enhanced-mode benefit. *Basis:* reads "retry strategy" in the
  trio as the configurability, not retry itself; honors the unqualified #6 line.
- **Option C — retry and configurability in both modes.** Weakest fit with the
  vision trio; listed for completeness, not recommended.

**(b) Configurable dimensions, offered strategies, defaults, caps.**
The PM proposes the following shape **for confirmation or correction — these
numbers are proposals, not derived requirements**:

- Per-proxy knobs: **attempt limit** and **backoff strategy** — nothing else at
  #6.
- Offered strategies at MVP: a small fixed set — e.g. **exponential** (default)
  and **fixed interval**. (More strategies can be added later; "configurable
  backoff strategies" is plural, so at least two seems intended.)
- Defaults, when retry applies and the user has configured nothing: e.g.
  attempt limit **5**, exponential backoff.
- A **system cap** on the attempt limit (e.g. **10**) and on total backoff
  span, so no policy can retry unboundedly or hold payload erasure hostage —
  PRD-06 AC18 requires the schedule bounded well inside the 30-day retention
  window regardless of the numbers chosen.

The Owner may confirm, adjust any value, or name a different shape entirely.

## Impact if unresolved
PRD-06 cannot be approved: AC1 (who gets retry) and AC2 (what is configurable,
defaults, caps) are not concretely testable, and the Designer cannot scope the
retry-policy form fields (UX Direction). Everything else in PRD-06 — replay,
retention interplay, FIFO bounding — is unaffected by any of the options.

## Answer
- **Answered By:** Project Owner
- **Answered:** 2026-08-12

**(a) Mode gating — Option B: baseline retry for all proxies.** Every proxy —
simple and enhanced mode — gains automatic retry. Simple-mode proxies get a
**fixed, non-configurable system-default policy**; per-proxy configurability
(attempt limit, backoff strategy) is **enhanced-mode only**. The system default
applies wherever the user has configured nothing (including enhanced-mode
proxies with no explicit policy).

**(b) Config shape — confirmed as proposed.**
- Per-proxy knobs: **attempt limit** and **backoff strategy** only — nothing
  else at #6.
- Offered strategies at MVP: **exponential (default)** and **fixed interval**.
- Defaults (the system-default policy of (a)): attempt limit **5**,
  **exponential** backoff.
- System caps: attempt limit **10**; total backoff span **bounded well inside
  the 30-day retention window** (PRD-06 AC18 stands — no policy may hold
  payload erasure hostage).

Rendered into PRD-06 AC1 and AC2 (and the UX Direction's retry-policy
configuration note). This question is closed; it no longer blocks PRD-06
approval.