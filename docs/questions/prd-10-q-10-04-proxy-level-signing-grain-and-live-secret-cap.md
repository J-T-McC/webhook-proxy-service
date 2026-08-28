# Q-10-04: Outbound signing is proxy-level, not per destination — and does AC29's two-secret cap still hold?

- **Feature:** sensitive data handling (item #10)
- **Requested By:** Principal Engineer, while writing `docs/plans/plan-10-sensitive-data-handling.md`
  and ADR-021/022/023/024
- **Directed To:** **Product Manager** (a PRD-10 amendment). **The Designer is downstream of it**,
  not a recipient of this document — see § *What the Designer needs, afterwards*.
- **Required By:** before the outbound-signing **surface** is built. **The backend is not blocked**
  — `plan-10` builds it to the Owner's ruling now, and only the frontend milestone waits.
- **Priority:** High for item 1 (an Owner ruling contradicts two approved documents, and the
  contradiction is currently only recorded in a plan and three ADRs). Medium for item 2.
- **Status:** **RESOLVED — Product Manager, 2026-08-27.** Both items answered, and rendered into
  `docs/product/prd-10-sensitive-data-handling.md` **`## Amendment B`**. **Item 1:** the Project
  Owner's ruling is recorded as requirements — outbound signing is **per proxy**; AC54 rewritten,
  AC58/AC63 revised, AC60 confirmed, § Definitions renames the term to **proxy signing secret**.
  **Item 2:** **AC29's cap of two stands** — ruled by the Product Manager as requirements author,
  re-worded rather than re-policied, with one bullet added. **One thing is deliberately still open,
  and it is not a question to anybody here:** `## Amendment B` itself **awaits Project Owner
  approval**, as any PRD change does. The Designer's revision waits on that approval; **M8a and
  every other milestone do not** — `plan-10` is already written to the ruling. See § Answer.
- **Raised:** 2026-08-27
- **Resolved:** 2026-08-27

## Question

Two items, both arising from Project Owner rulings given directly on 2026-08-27, **after** PRD-10
was approved and **after** the design gate closed. Neither is a technical question and neither is
mine to settle.

### Item 1 — Outbound signing is per **proxy**, not per **destination**. PRD-10 says per destination.

The Project Owner's words, unaltered:

> I want to ensure that the PE doesn't think each destination can have its own signing secret. A
> proxy has one outgoing secret that can be used for all destinations. We can rotate so the header
> contains multiple secrets until one or more expires, but that is proxy level.

**PRD-10 AC54–AC64 and `design-10` are both written as per-destination signing.** The ruling
displaces them. **Please amend PRD-10** so the approved text and the ruling agree, and so the
Designer has an amended criterion to revise `design-10` against.

**What the ruling changes, and what it leaves alone.** Nothing about the *scheme*, the *secret's
ownership*, the *one-time display*, the *bytes*, or the *message identity* moves — only the grain.

| PRD-10 element | As approved | Under the ruling |
|---|---|---|
| § Definitions, **Destination signing secret** | "the **per-destination** secret this service generates and signs its dispatches with … a destination may have neither, either or both (AC54)" | A **per-proxy** secret. The name itself is now misleading — "signing secret" or "proxy signing secret" |
| § Feature item 5 | "**A destination** can verify that a dispatch came from us" | Unchanged in intent; the secret it verifies against is the proxy's |
| § Problem gap 5 | unchanged in substance | unchanged |
| § Users, **Destination** | "can verify the request's signature to establish that this service sent it" | unchanged |
| § User Stories | "I want **my destination** to be able to prove that a webhook it received came from my proxy" | unchanged in intent |
| § UX Direction point 5 | "The **destination** signing secret (AC56) is displayed once, at generation" | The **proxy's** signing secret. The rest of the point — one chance to read it, optimise for actually capturing it — is unaffected |
| **AC54** | "A **destination** may be configured so that this service signs its dispatches. Signing is **per destination**, optional, and off by default … a destination may have neither a credential nor signing, either one, or both" | **A proxy** may be configured so that this service signs its dispatches to **every** destination. Still optional, still off by default, still **independent of AC30–AC39** — but the "either one, or both" phrasing is now about a proxy-level setting and a destination-level one |
| **AC55** | the `standard-webhooks` scheme, one implementation both directions | **unchanged** |
| **AC56** | "The product generates the signing secret. A member cannot supply one." | **unchanged**, at proxy grain |
| **AC57** | displayed exactly once at generation, write-only thereafter | **unchanged in substance.** The screen it is displayed on moves |
| **AC58** | "Regeneration rotates under AC29 … every dispatch in the interim carries a signature under **both** secrets" | **unchanged in substance**, at proxy grain. See item 2 on "both" |
| **AC59** | byte-identical body; signing adds headers only | **unchanged** |
| **AC60** | "**`webhook-id` identifies the delivery**" | **unchanged, and worth confirming explicitly.** The *secret* becomes proxy-level; the *message id* stays per delivery. Two destinations of one dispatch still receive different `webhook-id`s, signed with the same key |
| **AC61** | the signing secret appears nowhere but its one-time display and the signature computation | **unchanged** |
| **AC62** | configuration, not payload content; retention never erases it | **unchanged** |
| **AC63** | "Existing **destinations** are unaffected. A **destination without signing** produces a byte-identical outbound request" | "**A destination of a proxy without signing**". The guarantee is unchanged; its subject moves |
| **AC64** | signing headers take precedence over forwarded inbound headers of the same name | **unchanged** |
| § Out of Scope, "A member-supplied signing secret — AC56" | unchanged | unchanged |
| § Amendment A, the "Outbound signing" row and § *What changed in this PRD* | describe AC54–AC64 as added | the added block's grain changes; the history should not be rewritten, only the criteria |

**One substantive consequence the amendment should be ruled with in view, rather than inherited.**
Under the ruling a proxy's fan-out becomes **one trust domain**: every destination operator holds
the same secret, so any one of them can verify — and forge — traffic addressed to any other
destination of that proxy. The per-destination model does not have that property. It is recorded in
ADR-023 Decision 4 rather than argued here, and it is the only thing in the ruling that is a
trade-off rather than a simplification. The Owner may well have weighed it; PRD-10 should say so
either way, because a Reviewer will ask.

### Item 2 — AC29 caps live secrets at two. The Owner's storage direction contemplates more. Which governs?

The same session produced a second ruling, on how a rotating secret is stored:

> We can have 1, 2, 3.. relations. There can be an expiration timestamp that is set on an existing
> token when a new token is created. When we retrieve the tokens, we can retrieve non expired tokens
> … we can expand tokens vertically since we know the header can hold multiple. They will naturally
> expire and be excluded.

**AC29 as approved says the opposite of "1, 2, 3..":**

> **At most two secrets are ever held for one purpose.** Saving a replacement makes it the current
> secret and demotes the existing one to the **previous** secret. A further replacement inside the
> overlap demotes the new one in turn and **the oldest is discarded immediately** — there is no
> third slot.

The Owner's sentence describes a **capability** ("we can", "we can expand"), and AC29 states a
**policy**. Those are compatible, and `plan-10` and ADR-021 are written to treat them as compatible:
**the storage model is general and the behaviour is narrow.** The storage permits three or more with
no migration; `SecretStore::replace()` deletes already-superseded rows before demoting the current
one, so at most two rows exist at any instant and AC29 is literally true — including its "held", not
merely its "honoured". Both read paths loop the live set and assume no number, so raising the cap
later changes one line and no consumer, no schema and no test of the read path.

**The question is whether that is the intended reading.** Concretely: **does AC29's "at most two …
there is no third slot" stand as approved, or should it be amended to permit N live secrets bounded
only by their expiry?** Until you rule, #10 ships two.

If AC29 changes, two of its other clauses need a look at the same time, because they are written for
exactly two: *"Saving a replacement … demotes the existing one to the **previous** secret"* (singular)
and *"A further replacement inside the overlap demotes the new one in turn and the oldest is
discarded immediately"*. Neither is wrong under N, but neither reads naturally.

## Context

**Why this is a question rather than a design change.** `CLAUDE.md` reserves requirement changes to
the Product Manager and the Project Owner, and forbids me reinterpreting an approved PRD. The Owner
gave both rulings directly and mid-flight; I have written the plan and the ADRs to them, because
designing against text I now know to be wrong would be worse — but the *paperwork* is yours, and a
Reviewer reading PRD-10 AC54 literally against what ships would otherwise find a divergence with no
record behind it.

**What is already written to the ruling, so you can see the blast radius.** All of it points at this
document.

- `docs/plans/plan-10-sensitive-data-handling.md` — § *Architecture C*, § *Data Model*, § *API*,
  § *Milestones* and the Owner-approval flags.
- `docs/architecture/adr-021-secret-handling-and-rotation.md` — a banner at the top, and the whole
  storage model, which is *simpler* under the ruling: both rotating secrets are proxy-level, so one
  `proxy_secrets` table with one concrete `proxy_id` foreign key holds them, and the destination
  credential — which does not rotate — stays as columns on `destinations`.
- `docs/architecture/adr-023-outbound-request-contract.md` — a banner, Decision 1's composition,
  Decision 4's signature list, and the trust-domain consequence above.

**Nothing is edited in PRD-10 or `design-10`.** Both remain approved as written.

**No implementation exists.** #10 has not been broken down into tasks and no code has been written,
so the amendment costs nothing beyond the documents.

## What the Designer needs, afterwards

**Downstream of your amendment, not a parallel request.** `design-10` designs signing per
destination throughout, and the surface moves from the Destinations table to the proxy. The elements
that go stale are, exhaustively:

- **§ Scope note**, items (2) and (3) — the "two small status badges plus a **Manage signing**
  action on the existing Destinations table", and "a new **Manage destination signing** dialog,
  reached from that action".
- **Flow G** — "Enable **a destination's** signing and capture the one-time secret", all five steps,
  including step 1's per-row button and its "only available once a destination is saved and has an
  id" rule (which was the stated reason signing lives on Show rather than in the Edit form — that
  reason **survives** the re-grain, because the modal one-time reveal still cannot live in an
  unsaved form).
- **Flow H** — "Regenerate **a destination's** signing secret, and end its overlap early".
- **Flow I** — "Disable **a destination's** signing", including step 3's "re-enabling always
  generates a fresh secret".
- **Screen 5** — the per-row **`Signed`** badge and the per-row **Manage signing** button, and the
  rule that the button is absent for a soft-deleted destination. Under a proxy-level secret a
  per-row `Signed` badge says the same thing on every row, which is the part that most obviously
  needs the Designer rather than a mechanical edit. **The `Credential` badge is unaffected** — the
  credential stays per destination.
- **Screen 6** — the whole "**Manage destination signing** dialog", including its
  `DialogTitle "Signing for {METHOD} {url}"` and all five states. Its **flagged call 4 ruling
  survives intact** — the one-time reveal must still suppress `Esc` and overlay dismissal, with
  **Done** the sole keyboard-reachable exit — it simply applies wherever the reveal now lives.
- **§ Components**, the dialog-shell, one-time-reveal `CopyField` and `Alert` rows; **§ Interactions**,
  the "Enable / Regenerate / Disable signing are all single-click" bullet; **§ Accessibility**, the
  "Manage signing dialog" bullet.
- **§ Approval record** — flagged design call 3's second binding condition names the `Signed` badge;
  correction **C2**'s list of controls to `canUpdate`-gate names Screen 5's **Manage signing** and
  every action inside Screen 6. **C2's requirement is unaffected** — the same gate applies to the
  same actions wherever they render. The historical approval record itself should not be rewritten,
  per the standing rule `design-11`'s gate set.

`plan-10` § *Milestones* already isolates the signing **frontend** as the only work that waits on
this; the signing backend, the credential, verification and obfuscation are all unblocked by it.

## Impact if unresolved

Two documents that the Reviewer, the Task Planner and the Senior Developer all read as authoritative
say something the Project Owner has ruled against, and the only record of the ruling is a banner in
a plan and three ADRs. The predictable failures, in order of likelihood:

1. **The Designer revises `design-10` against AC54 as written** and re-approves a per-destination
   surface, because the design gate reads the PRD, not the plan.
2. **The Reviewer opens a Major** against a proxy-level implementation for breaching AC54, AC63 and
   the design spec — correctly, on the documents in front of them.
3. **A later item inherits the wrong grain.** #13 (notifications) and #14 (test payloads) both touch
   dispatch; either could reasonably assume a per-destination signing secret exists.

Item 2 is quieter but has the same shape: `SecretStore` will carry a cap of two enforced by a test
whose justification is an AC that may be about to change, and nobody will know whether the cap is a
requirement or an artefact.

## Answer

**Product Manager, 2026-08-27.** Rendered into `docs/product/prd-10-sensitive-data-handling.md`
**`## Amendment B`**, which is the authoritative text; this is the answer and the reasoning, not a
second copy of the criteria.

### Item 1 — the grain. Recorded as the Owner's ruling, not re-decided.

**PRD-10 is amended so the approved text and the ruling agree. Outbound signing is per proxy.** The
amendment does what this document asked for and follows its table, with three departures worth
naming.

1. **The term is renamed, not only re-grained.** § Definitions' **Destination signing secret**
   becomes **Proxy signing secret**. This document called the old name "now misleading" and it is
   right; leaving it would have left every later reader deriving the grain from a criterion body
   instead of from the vocabulary the PRD says every criterion uses exactly. **AC10, AC11 and AC44
   change only because of the rename.** The rename is mine, not the Owner's.
2. **AC54 carries the trust-domain consequence in the criterion itself**, not only in the amendment
   prose. Your § Item 1 said "PRD-10 should say so either way, because a Reviewer will ask" — this
   is that. What it does **not** do is claim the Owner weighed it, because the Owner did not say so
   and inventing that record would be worse than leaving the question visible. `## Amendment B`
   § *The trade-off in ruling 1* puts it in front of the Owner at the amendment's own approval gate
   and states plainly that wanting per-destination isolation reverses the ruling rather than
   adjusting it.
3. **AC11's signing clause changed more than a noun.** Per destination it read as one destination
   failing; at proxy grain an undecryptable signing secret means the proxy dispatches to **none** of
   its destinations. That is the correct reading of fail-loudly under the new grain and it matches
   `plan-10` § *Architecture H* and ADR-021 Decision 7, but it is a behavioural widening rather than
   a wording change, so it is called out rather than folded in.

**Everything else in your table is applied as you wrote it**, including the three "unchanged" rows
that are easiest to get wrong: **AC55** (one implementation, both directions), **AC57** (displayed
exactly once — only the screen it lives on moves), and **AC60**, which is now *explicitly confirmed*
in the criterion rather than left silent: the secret is the proxy's, the message identity stays per
delivery, and two destinations of one dispatch still receive different `webhook-id`s signed with the
same key. **AC30–AC39 are untouched and stay per destination.**

### Item 2 — RULED: AC29's cap of two stands. This one is mine, and it is not what the storage permits.

**The cap of two is re-affirmed for both purposes, at proxy grain. AC29 is re-worded, not
re-policied.** Full grounds are in `## Amendment B` ruling 2; the five that carry it, in short:

1. **The Owner's words are already satisfied by two.** Two live secrets *are* "multiple" in the
   header, and "until one or more expires" is true of a two-member set. The "1, 2, 3.." sentence
   answered a **storage** question — ADR-021 records it as ruling A on exactly that basis — and a
   capability of the store is not a policy about how many secrets a member gets. Reading it as one
   would be inferring a requirement the Owner did not state, which is the thing this document is
   itself careful not to do.
2. **The approved requirement set is written to two outside AC29, and was approved whole.** UX
   Direction point 8: "the member must be able to see that **two secrets** are currently honoured and
   when the **older** one stops being honoured." Uncapping turns that into a list of unknown length
   with an expiry each — a materially different screen, with nothing asking for it.
3. **Uncapping removes a stated remedy.** AC29 names "replacing twice removes it at once" as the
   remedy for a compromised secret. Under N, a second rotation does not remove it; it lives out its
   own 24 hours and **End overlap now** becomes the only remedy. Two remedies to one, for the exact
   failure the criterion exists to handle.
4. **Item 1 strengthens the case rather than weakening it.** At proxy grain the signature list goes
   to every destination on every dispatch, and each extra live secret is another key that can forge
   traffic to the whole fan-out for a full 24 hours. Two is a bounded header and a bounded exposure.
5. **The asymmetry favours the cap.** Your own § Item 2 and `plan-10` Technical ruling 14 record that
   raising it later costs one line of the write path and no schema, consumer, read-path test or
   member-facing state. Lowering it later takes away behaviour members have relied on.

**The argument for uncapping is real and is answered rather than dismissed.** A member told at T0
that the previous secret works until T0+24, who rotates again at T1 inside that window, has that
promise broken early — for every destination of the proxy at once, under item 1's grain. **That is
why AC29 gains a bullet rather than why the cap falls:** the surface that begins a replacement or a
regeneration while an overlap is running must state that the oldest secret stops being honoured
immediately, before the save. One conditional line of copy against a screen, a wider exposure window
and a lost remedy.

**Your two flagged clauses.** Both were written for exactly two and both are kept, because the cap is
kept — "demotes the existing one to the **previous** secret" (singular) and "a further replacement
inside the overlap … the oldest is discarded immediately" now read naturally rather than merely
being not-wrong. What did change is the leading sentence: **"at most two secrets *exist* for one
purpose on one proxy at any instant"**, with *exist* distinguished from *are honoured*, plus an
explicit statement that this is a requirement about behaviour and dictates no storage shape. That is
your own resolution — *the storage model is general and the behaviour is narrow* — stated in the
requirement rather than only in the plan, so nobody has to reconstruct it from `SecretStore`.

**So `SecretStore`'s cap is a requirement, not an artefact**, and the test pinning it (`plan-10`
§ *Test strategy*, R7 — three consecutive rotations leave exactly two rows) is testing AC29 and can
say so. **Nothing in `plan-10`, ADR-021 or ADR-023 needs to change for item 2**; #10 ships two
because two is the requirement, not because the amendment had not landed.

### AC33 is not touched, and this is the explicit statement of that

**AC29's exclusion of the destination credential stands exactly as approved.** The credential is
still per destination (AC31), still outside the rotation overlap, still replaced immediately and
single-valued, and every ground AC29 gives for excluding it — presented rather than verified or
computed, one credential value per request, nothing on the wire for an overlap to mean — survives
the grain ruling untouched, because none of them depends on where the *signing* secret lives.
**Neither ruling above reaches AC33.** `design-10` Screen 5's `Credential` badge and Screen 3's
credential surface are unaffected for the same reason.

### Two further calls I made, flagged so the Owner can strike either

- **A member-facing warning about the shared trust domain is not required** (`## Amendment B` ruling
  2b). No stated requirement asks for one; the control now lives on the proxy, so its scope is
  legible from where it sits. Flagged rather than absorbed because it is security-shaped.
- **The AC29 bullet added by ruling 2a touches the inbound verification surface too**, not only the
  signing surface this document is about — see the Designer note below.

### What the Designer now has to amend — your list, plus three things it did not name

**Everything in § *What the Designer needs, afterwards* stands and is adopted.** The Designer works
from that list; it is not restated here. Three additions, found while amending:

1. **§ Overview** (the prose walkthrough) describes "The existing Destinations table gains two small
   status badges (Credential, Signed) and a **Manage signing** action that opens a dedicated dialog"
   and calls it "the *only* place in this feature a secret is ever shown". Stale in the same way
   Screens 5 and 6 are.
2. **§ Decisions carried forward from the UX Direction** restates "The **destination** signing secret
   is displayed exactly once" and "the rotation overlap … in both directions it applies to — the
   verification secret and the **destination** signing secret". These restate PRD-10's UX Direction,
   which Amendment B revises, so they move with it.
3. **§ Scope boundaries**' AC11 bullet names "destination signing secret" three times and now also
   describes a *narrower* failure than AC11 as amended: an undecryptable signing secret fails
   dispatch to **every** destination of the proxy, not one.

**And one obligation that is genuinely new rather than stale**, because it comes from ruling 2a
rather than from the grain: **Flow B step 2 / Screen 1 — the inbound verification rotation surface —
needs the second-rotation statement.** Its C5 help line today states the 24-hour promise and says
nothing about a further rotation discarding the oldest secret. This is a small addition to a surface
your list correctly left alone, and it is the one place where Amendment B reaches past signing.
**Sequencing it is not mine**: the addition is additive copy, so whether M6 waits for it or it lands
with the same design revision is the Principal Engineer's and the Task Planner's call.

**Unchanged from your list, restated only because they are the two easiest to lose:** Screen 5's
**`Credential`** badge stays (the credential is still per destination), and flagged design call 4's
ruling — the one-time reveal suppresses `Esc` and overlay dismissal, **Done** the sole
keyboard-reachable exit — binds wherever the reveal now lands. **`design-10`'s historical approval
record is not to be rewritten**, per the standing rule `design-11`'s gate set, which your document
already cites.

### What this answer does not do

- **It does not approve anything.** `## Amendment B` awaits the Project Owner, like any PRD change.
  This answer is the Product Manager's ruling on requirements; it is not the Owner's consent to them.
- **It does not edit `plan-10`, ADR-021, ADR-022, ADR-023, ADR-024 or `design-10`.** All are
  approved or Accepted, and `plan-10` is already written to ruling 1. **Nothing in the amended
  requirements needs anything `plan-10` does not already support** — checked against § *Architecture
  C*, § *Data Model*, § *API*, Technical rulings 13 and 14 and § *Test strategy*, including the
  ruling-B test line and R7's three-rotations-two-rows assertion, which now pins AC29 directly.
- **It does not renumber anything.** `Q-10-01`, this document, `docs/status.md`, `plan-10` and four
  ADRs cite these AC numbers; the count is still 64 and no criterion moved.
- **It does not reopen `## Amendment A`**, its history, or the V2 ruling.
