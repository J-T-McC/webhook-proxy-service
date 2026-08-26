# Question Q-07-01: Mode-switch consequences — stored enhanced data, saved enhanced config, switching freedom

- **Status:** RESOLVED
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(business/product decision — no vision,
  roadmap, PRD, ADR, or prior ruling states what a mode **downgrade** does to data
  and configuration already created under enhanced mode)*
- **Raised:** 2026-08-21
- **Gates:** **BLOCKING** for PRD-07 approval — AC13, AC14 and AC17 of
  `docs/product/prd-07-enhanced-mode-toggle.md` take their concrete behaviour from
  this answer, and the Designer cannot write the downgrade disclosure copy
  (UX Direction) without it. Nothing else in PRD-07 depends on it.
- **Source:** Roadmap #7 — "A proxy can be switched between simple proxy and
  enhanced mode"; ADR-002 — "#7 becomes a validated state transition on one
  column"; PRD-05 AC11/AC12 (dispatched-output store, erasure lifecycle);
  PRD-06 AC2 (per-proxy retry policy, enhanced-only); `docs/design/design-06-retry-replay.md`
  Flow F steps 4–5 (in-form clearing of retry fields on Enhanced → Simple).

## Context
#7 turns an attribute that has existed since #1 into a user-meaningful switch. The
**upgrade** direction (Simple → Enhanced) is uncontroversial: enhanced-only steps
begin running for subsequent events, nothing existing is affected.

The **downgrade** direction (Enhanced → Simple) is not settled anywhere:

- **Already-stored enhanced-only data.** An enhanced proxy stores a dispatched
  output per received event (PRD-05 AC12). PRD-05 authorises exactly **one**
  erasure trigger — retention expiry (AC5, AC11: "captured-and-unaltered →
  erased") — and #7 has no mandate to invent a second. But a user who turns
  enhanced mode *off* may reasonably expect the data it produced to go away, and
  the Owner has ruled on erasure semantics before (PRD-05 Amendment A). The
  Product Manager will not decide this by inference.
- **Saved enhanced-only configuration.** An enhanced proxy may carry a saved retry
  policy (attempt limit + backoff strategy, PRD-06 AC2). `design-06` settled the
  **in-form, in-session** behaviour (hidden fields clear to their default sentinel;
  switching back does not restore in-session values) but says nothing about what
  happens to a value **already persisted** on a proxy that is then saved as Simple,
  nor whether a later switch back to Enhanced should restore it.
- **Switching freedom.** Nothing states whether switching is unrestricted. #4's
  `processing_mode` set no restriction and #6 introduced none; ADR-002 frames #7 as
  a plain validated state transition. The Product Manager's reading is that it is
  unrestricted, but events **in flight** at the moment of a switch make this worth
  confirming rather than assuming.

## Question

**(a) Downgrade and already-stored enhanced-only payload content.** When an
enhanced-mode proxy is switched to simple mode, what happens to the **dispatched
output** payload content already stored for its past events (PRD-05 AC12)?

- **Option A — retain; retention expiry remains the only eraser (PM
  recommendation).** Existing dispatched outputs stay until their normal 30-day
  window elapses and are then erased by the existing expiry pass; the proxy simply
  stops producing new ones. *Basis:* PRD-05 AC11 defines a single lifecycle with
  one destruction trigger; introducing a second, user-triggered one is new
  retention behaviour that #7's roadmap line does not claim. Also the least
  surprising for a user who downgrades by mistake and switches straight back.
  *Consequence:* an enhanced-produced output can outlive the proxy's enhanced
  state by up to 30 days.
- **Option B — erase on downgrade.** The switch immediately erases the stored
  dispatched-output content for that proxy's events, in place, marking them
  cleaned per PRD-05 AC21. *Basis:* "turning it off removes what it made" is the
  more intuitive privacy reading. *Consequence:* a new erasure trigger, an
  irreversible destructive side effect of an otherwise reversible setting, and a
  change to PRD-05's stated lifecycle — which would need recording against PRD-05.
- **Option C — something else the Owner names.**

**(b) Downgrade and saved enhanced-only configuration.** What happens to a proxy's
**persisted** retry policy (attempt limit + backoff strategy) when it is saved as
simple mode?

- **Option A — discarded (PM recommendation).** The stored policy is cleared; the
  proxy falls back to the fixed system default (5 attempts, exponential — PRD-06
  AC2), and switching back to Enhanced starts unconfigured. *Basis:* consistent
  with `design-06` Flow F (nothing stale may be submitted for a simple proxy, and
  prior values are not restored) and with PRD-06 AC2 — a simple-mode proxy has no
  retry configuration at all, so keeping a dormant one is a contradiction the UI
  would have to hide.
- **Option B — preserved dormant and restored.** The value is kept but inert while
  the proxy is simple, and reappears if the proxy returns to Enhanced. *Basis:*
  kinder to accidental downgrades. *Consequence:* a simple proxy silently holds
  enhanced configuration that has no effect, and the Show page must not display it.
- **Option C — something else the Owner names.**

**(c) Switching freedom — confirm or restrict.** Is switching mode unrestricted:
either direction, at any time, any number of times, including while the proxy has
events queued, retrying, or in flight?

- **Option A — unrestricted (PM recommendation).** No cooldown, no
  drain-before-switch requirement, no one-way transition. *Basis:* ADR-002 frames
  #7 as a validated state transition on one column; no upstream document imposes a
  restriction; PRD-07 AC10/AC11 already require that no in-flight event is lost or
  errored across a switch.
- **Option B — restricted**, in a way the Owner names (e.g. blocked while
  deliveries are outstanding).

## Impact if unresolved
PRD-07 cannot be approved. AC13 (what a downgrade does to stored enhanced-mode
data), AC14 (what it does to saved enhanced-mode configuration) and AC17 (whether
switching is unrestricted) are not concretely testable, and the Designer cannot
write the downgrade-disclosure copy the UX Direction requires — a disclosure that
must state a consequence we have not yet decided. Every other part of PRD-07 — the
governed-step set, mode-independent guarantees, in-flight safety, honest
presentation, extensibility — is unaffected by any of the options.

## Answer
- **Answered By:** Project Owner
- **Answered:** 2026-08-21

**(a) Stored enhanced-only payload content — Option A: retain; retention expiry
remains the only eraser.** A downgrade erases nothing. Existing dispatched outputs
live out their normal 30-day window and are erased by the existing expiry pass; the
proxy simply stops producing new ones. PRD-05's single-erasure-trigger lifecycle is
unchanged and needs no amendment. Accepted consequence: an enhanced-produced output
can outlive the proxy's enhanced state by up to 30 days.

**(b) Saved enhanced-only configuration — Option B: preserved dormant and restored.**
*(Owner ruling; differs from the PM recommendation of Option A.)* A proxy's persisted
retry policy (attempt limit + backoff strategy) is **kept** when the proxy is saved as
simple, remains **inert** while the proxy is simple — a simple proxy always resolves
the fixed system default (5 attempts, exponential; PRD-06 AC2) regardless of persisted
values — and **reappears** if the proxy returns to enhanced mode. Rationale: an
accidental downgrade must not silently destroy tuned configuration, and the setting
stays as reversible in effect as it is in appearance.

Consequences the Owner accepts, which PRD-07 must carry:
1. A simple-mode proxy may hold retry-policy values that have no effect. The Show
   page and any read surface **must not** present them while the proxy is simple —
   showing dormant values would violate PRD-07's truthful-presentation ACs.
2. Nothing may resolve retry behaviour from persisted columns without first checking
   mode; simple mode always yields the system default.
3. `design-06` Flow F's **in-form, in-session** behaviour is unchanged and not in
   conflict: hidden fields still clear to their default sentinel in the form, and
   in-session values are still not restored. This ruling governs **persistence**
   only.

**(c) Switching freedom — Option A: unrestricted.** Either direction, at any time,
any number of times, including while the proxy has events queued, retrying, or in
flight. No cooldown, no drain-before-switch, no one-way transition. In-flight safety
remains specified independently by PRD-07 AC10/AC11, and mixed treatment across a
switch stays a normal outcome rather than a fault.

**Downstream:** PRD-07 AC13 (a), AC14 (b) and AC17 (c) become concretely testable;
the Designer can now write the downgrade-disclosure copy — noting that under (a)+(b)
the disclosure states that nothing is deleted and configuration is kept but inactive.
No PRD-05 amendment is required. The mode-gated resolution rule in (b)(2) is a
technical composition concern for the Principal Engineer (see Q-07-02).
