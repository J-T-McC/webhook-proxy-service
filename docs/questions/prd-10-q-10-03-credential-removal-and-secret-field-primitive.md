# Q-10-03: Removing (not replacing) a destination credential, and the write-only secret field's primitive

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Principal Engineer, while writing `docs/plans/plan-10-sensitive-data-handling.md`
- **Directed To:** **Designer**
- **Required By:** before the destination-credential surface (design-10 Screen 3) is built — but
  **non-blocking**: `plan-10` builds to `design-10` exactly as approved, and both answers are
  additive whichever way they go.
- **Priority:** Low
- **Status:** **RESOLVED — Designer, 2026-08-27.** Item 1: a "Remove credential" ghost
  button is added beside Replace (Screen 3). Item 2: the plain `Input type="password"`
  stands, on a narrower ground than N3 originally gave. See § Answer.
- **Raised:** 2026-08-27
- **Resolved:** 2026-08-27

## Question

Two items. Neither is a requirement question and neither is a UX ambiguity I could resolve by
reading the spec more carefully — the first is a surface that is absent, and the second is a factual
premise in the spec that turns out not to hold.

### Item 1 — There is no affordance to *remove* a destination credential, only to replace it.

`design-10` **Flow F** and **Screen 3** give a destination credential three states: not set, set,
and replace. Signing, by contrast, has an explicit **Disable signing** (Flow I) that turns the
capability off. A member who has entered a credential and later wants the destination to receive
**no** credential header has no path: replacing it substitutes one value for another, and the only
way to end up with none is to remove the whole destination row (Flow F step 6), which also discards
its URL, its method and its delivery history association.

AC30 makes the credential **optional** ("A destination **may** carry an optional credential"), so a
destination with none is a legitimate configured state that a member can reach on the way in but not
on the way back out.

**Is that intended, or is a remove affordance wanted?** Three shapes exist in the app already, so
this is a choice among precedents rather than new invention: a `×` on the collapsed
`Credential: set` trigger; a "Remove credential" ghost button inside the expanded disclosure beside
**Replace**; or nothing at all, stated as a deliberate absence in § *Open Questions* the way the spec
already records its other deliberate absences.

If a control is added, one technical note so the answer can be complete: **removal is a save-time
change like every other field on this form**, not an immediate action — it would clear the
credential when the member saves the proxy, exactly as removing a destination row does today. It
needs no confirmation dialog under `docs/standards/design.md`'s rule, since nothing stored is
exposed and the credential can be re-entered.

**What the plan does meanwhile:** it builds Screen 3 exactly as approved — not set, set, replace, and
no removal — and records the gap in § *Explicitly out of scope for this plan* so it is a stated
absence rather than an oversight.

### Item 2 — Non-blocking note **N3** says there is no `type="password"` precedent in this app. There is.

`design-10` § *Screen 1* justifies the write-only secret field this way:

> a plain `Input type="password" autocomplete="off"` (N3) — chosen because **there is no existing
> password-input precedent in this app to follow** and this is the standard semantic for a
> masked-entry field

**`resources/js/components/PasswordInput.vue` exists.** It wraps the same `Input` primitive, toggles
between `type="password"` and `type="text"`, and renders an eye / eye-off button inside the field
with `aria-label="Show password"` / `"Hide password"` and `tabindex="-1"`. It is used by the auth
and settings forms.

The premise being wrong does not make the conclusion wrong — a plain masked `Input` may still be the
right call here — but the choice was made on the basis that no precedent existed, and one does, so
it is yours to re-make rather than mine to quietly resolve either way.

**The substantive difference is a Show toggle**, and it is worth being explicit that it breaches
nothing: AC26, AC33 and AC57 forbid **redisplaying a stored** secret, and this field is only ever
populated by in-session typing — the value was never sent to the client, so a Show toggle reveals
only what the member has just typed. Against that: `design-10`'s own framing is that every secret in
this feature behaves identically ("type it, save it, see that it is *set*, never see it again"), and
a reveal affordance on the entry field, however harmless, sits oddly beside a `[Hidden]` token two
screens away that is inert precisely because AC20 forbids revealing anything.

**Which primitive should the write-only secret field use — the plain `Input type="password"` Screen 1
specifies, or `PasswordInput.vue`?** The question applies identically to all three write-only fields:
the verification secret (Screen 1), the destination credential (Screen 3), and any future one.

**What the plan does meanwhile:** it builds to the spec as written — plain
`Input type="password" autocomplete="off"`, recorded at `plan-10` § *Implementation Notes* 21 with a
pointer here. N3's `autocomplete="off"` requirement holds either way; `PasswordInput.vue` does not
set it, so adopting it would mean passing the attribute through.

## Context

- `design-10` is **approved at the design gate** (Product Manager, 2026-08-27) with corrections
  C1–C10. `docs/standards/documentation.md` and `CLAUDE.md` both put a correction to an approved
  artifact with its owning role, not with whoever notices it, and forbid the Principal Engineer
  redesigning UI in a plan. Hence a question rather than a plan ruling.
- Neither item touches an acceptance criterion. Item 1 is an absent affordance for an optional
  capability; item 2 is a component choice.
- **Neither blocks anything.** `plan-10` § *Milestones* M7 (the credential surface) is buildable as
  approved today, and either answer lands as an additive change to the same screen.
- Unrelated and **not** part of this document: the outbound-signing grain, which the Project Owner
  re-ruled on 2026-08-27 and which is routed to the **Product Manager** at
  `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`. That one does
  reach `design-10`, but only downstream of a PRD amendment.

## Impact if unresolved

Low, and bounded in both cases. Item 1 ships a capability a member can turn on and not off, which is
a small usability gap and a slightly larger security one — a member who believes they have removed a
credential and has only replaced it still has a secret on the wire. Item 2 ships a masked field
without a Show toggle, which is defensible and reversible; the cost of leaving it is that the next
person to read N3 will believe something about the codebase that is not true.

## Answer

**Designer, 2026-08-27.** Both items are design-detail decisions within the Designer's
authority (`CLAUDE.md`: "design → product-manager" is the approval gate, not the decision
itself; neither item touches an acceptance criterion, as this document already notes).
Rendered into `docs/design/design-10-sensitive-data-handling.md` directly — Screen 3 and
its States table for item 1, Screen 1's write-only shared shape for item 2 — this is the
answer and the reasoning, not a second copy of the design text.

### Item 1 — a remove affordance is added.

**Ruled: a "Remove credential" ghost button, beside Replace, inside the expanded
disclosure.** Of the three shapes this document offered, this one was chosen over a `×`
on the collapsed trigger (which would remove a secret from a control whose whole point is
to stay collapsed and unremarkable) and over stating the absence as deliberate (which this
document itself already doubted, correctly: AC30 makes the credential optional, and a
capability a member can turn on but never fully off is a real gap, not a considered
boundary). It also brings the credential surface into line with signing's own explicit
**Disable signing** affordance, which this document flagged as the asymmetry worth
noticing.

Per the technical note already in this document: **removal is a save-time change**, like
every other field on this form, not an immediate action, and needs no confirmation dialog
under `docs/standards/design.md`'s rule — nothing stored is exposed and the credential can
be re-entered. `plan-10`'s stated gap ("Screen 3 exactly as approved — not set, set,
replace, and no removal") is now closed by this answer; the Principal Engineer's build
should follow the amended Screen 3 rather than the plan's placeholder note.

### Item 2 — the plain `Input type="password"` stands, but not for the reason given.

**Ruled: keep the plain masked `Input`, not `PasswordInput.vue`'s show/hide toggle.** The
premise this document corrected was wrong — `PasswordInput.vue` exists — and the original
N3 note is left as a historical record of what the design gate considered rather than
rewritten (per `docs/standards/documentation.md` and the standing rule `design-11`'s gate
set). The conclusion is re-reached on a narrower ground: every secret in this feature is
deliberately built to one idiom — type it, save it, see that it is *set*, never see it
again — and a reveal toggle on the entry field, however harmless here (this field only
ever holds what the member just typed, never a value read back from storage, so AC26/
AC33/AC57's no-readback rule is not at stake either way), would sit oddly two screens away
from an inert `[Hidden]` token that exists precisely because AC20 forbids revealing
anything. One consistent idiom for the whole feature outweighs a convenience on one field.
`autocomplete="off"` remains required regardless of which primitive is used, as this
document already noted; the plain `Input` already carries it and needs no change.

### What this answer does not do

It does not touch any acceptance criterion, does not reopen the design gate (both changes
are additive to the approved surface, in the same way `plan-10` already treats this
document as non-blocking), and does not require Principal Engineer or Product Manager
re-approval beyond the ordinary review that follows any design revision.
