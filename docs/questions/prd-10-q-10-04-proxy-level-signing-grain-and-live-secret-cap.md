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
- **Status:** Open
- **Raised:** 2026-08-27

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

*(Unanswered.)*
