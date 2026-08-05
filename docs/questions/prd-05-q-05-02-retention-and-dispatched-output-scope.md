# Question Q-05-02: Two #5 scope confirmations — retention configurability, and when the dispatched-output store lands

- **Status:** RESOLVED — **both defaults confirmed** (Project Owner, 2026-08-05)
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(both are scope/cost decisions; the
  Product Manager has settled each from existing documents and is asking for
  confirmation, not a new decision)*
- **Raised:** 2026-08-05
- **Resolved:** 2026-08-05 — by Project Owner decision (see Answer below)
- **Gates:** **Nothing.** PRD-05 proceeds with the stated defaults (AC2, AC3, AC12).
  Either answer changes requirement wording only, not the shape of #5, and neither
  blocks PRD-05 approval or #5 design.
- **Source:** Roadmap #5 line and build-ahead note; roadmap Open Question **V5**;
  Resolved Decision **R2**; vision "Payload storage"; PRD-03 Owner answer **Q-03-02**
  (2026-08-03).

## (a) Retention: fixed 30 days, no user control at #5?

**Product Manager's settled default (PRD-05 AC2, AC3):** the retention window is
**30 days for every team**, expressed as a **team-level** property, with **no
user-facing control** to change it at #5.

**Derived from:**
- Vision: "Retention is team-level, **starting at 30 days**, with a garbage
  collector, and **possibly tied to future subscription tiers**."
- Roadmap V5: "Retention as a subscription-tier lever — **future**; may extend #5."
- Owner answer Q-03-02 (2026-08-03): simple-mode captures share "#5's planned
  **30-day** default retention, **later made adjustable by plan type**" — later, and
  by *plan*, not by user today.
- Billing/payment is explicitly out of scope in the vision, so a tier lever has
  nothing to attach to yet.

**What is kept open regardless:** making the window a **team-level property** rather
than a hard-coded constant means a later tier (V5) or region (V6) lever changes only
where the value comes from — not the storage or GC model. That is the extension
point #5's build-ahead note asks for.

**Confirm or override:** should a team admin be able to set their team's retention
window (e.g. shorter than 30 days for sensitive data, longer for debugging) at #5?
If yes, this becomes a new requirement **and** a user-facing control — which would
also interact with Q-05-01 (a PRD with any UI routes to the Designer). Default if no
answer: fixed 30 days, no control.

## (b) Dispatched-output store: at #5 as the roadmap says, or defer to #8?

**Product Manager's settled default (PRD-05 AC12, AC13):** the dispatched-output
store lands **at #5**, gated to enhanced mode, exactly as the approved roadmap line
states — "The raw input is captured and never mutated; **the dispatched output is
saved separately**" — and as R2's non-overridden half requires.

**The trade-off worth naming:** payload **mapping is #8**. Until #8 exists, the
dispatched output is identical in content to the raw input, so storing it separately
at #5 roughly **doubles stored payload volume** (before compression/overhead) for no
user-visible difference — while #5's whole point elsewhere is bounding storage.

**Arguments for keeping it at #5 (the default):**
- It is what the Owner-approved roadmap line for #5 says, and R2's build-ahead
  separation is one of the seams #5 exists to establish.
- #10 (encryption/obfuscation) and #6 (replay) both assume the raw/dispatched
  separation exists; establishing it once here avoids a later re-model.
- The 30-day GC bounds the duplication.

**Arguments for deferring it to #8:**
- Zero information is lost by deferring while output ≡ input.
- Storage cost and at-rest exposure of a second copy of payload content start
  immediately for no present benefit.

**Confirm or override.** Default if no answer: dispatched-output store lands at #5
per the roadmap.

## Impact if unresolved
None on PRD-05 approval or #5 design — the defaults above are what PRD-05 asserts.
Answering (a) "yes, make it configurable" or (b) "defer to #8" would require the
Product Manager to revise the affected acceptance criteria and re-request approval.

## Answer
**Project Owner, 2026-08-05 — BOTH DEFAULTS CONFIRMED.** The Product Manager's
settled positions are accepted as written; no requirement is overridden and no
acceptance criterion changes.

**(a) Retention — CONFIRMED as drafted.** The window is **fixed at 30 days for
every team**, expressed as a **team-level** property, with **no user-facing
control** at #5 (PRD-05 AC2, AC3). No team admin setting, no per-proxy or per-plan
value, no shorter/longer override. V5 (retention as a subscription-tier lever)
stays **deferred** exactly as the roadmap labels it; because AC3 makes the window a
team-level property rather than a constant applied at the payload, a later tier or
region lever changes only where the value comes from, not the storage or GC model.
No UI is added by this answer, so it does not interact with Q-05-01's routing.

**(b) Dispatched-output store — CONFIRMED at #5.** The enhanced-mode
dispatched-output store lands **at #5**, per the approved roadmap line and R2's
non-overridden half (PRD-05 AC12, AC13). The **trade-off is acknowledged and
accepted**: until #8 mapping exists the dispatched output is identical in content
to the raw input, so the store roughly **duplicates stored payload volume** for no
immediate user-visible difference. Accepted because the raw/dispatched separation
is one of the seams #5 exists to establish — #6 replay and #10
obfuscation/encryption both assume it — and the 30-day GC bounds the duplication.
The at-rest protection floor (AC15) applies to the duplicate copy.

This question is closed; PRD-05 asserts these defaults as approved requirements.
