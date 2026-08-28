---
name: plan-and-adr-house-format
description: How this project's technical plans and ADRs deviate from the dev-team plugin templates, and how the Owner gate is carved out of PE self-certification
metadata:
  type: reference
---

Technical plans live at `docs/plans/plan-NN-<slug>.md` (not the template's
`<feature-slug>-plan.md`); ADRs at `docs/architecture/adr-NNN-<slug>.md`. Both start from the
plugin templates but the house format adds two things the template lacks, established by
plan-03/plan-04 and expected by the Task Planner and Reviewer:

- **A `## Test strategy` section** between Implementation Notes and Handoff, with tests grouped
  by AC and named per acceptance criterion. Not in the plugin template.
- **`Handoff → Owner-approval flags (✋)`** — a numbered list of exactly what needs Project
  Owner sign-off, each item stating its precise shape (e.g. the verbatim column definition).

**Self-certification is partial, and the Status line says so.** The PE writes
`Status: Approved (Principal-Engineer self-certified) — **except** <the flagged items>`, and the
Next Agent line reads "Task Planner — after Owner approval of items N". Never certify a plan
whole when it carries a flag. Triggers observed so far: any ADR, any new table/column/index,
any irreversible operation, any new at-rest copy of sensitive data. Data-model changes are
**never** PE-self-certified (see [[project-structure]]).

Also house convention: plans that rule on something the PRD/design left silent get an explicit
named ruling section (plan-04's "Mid-flight mode change — technical ruling") stating why it stays
inside the upstream artifact's assumptions and therefore does **not** route back upstream.

**When a plan carries no outstanding flag**, still write the `Owner-approval flags (✋)` section —
list the gate struck through with its Accepted date (plan-07), never omit the heading, and add a
short *Why no new ADR* paragraph walking each candidate against the ADR bar. The Status line then
reads "Approved (Principal-Engineer self-certified) — **in full**", and the Certification paragraph
says explicitly that there is no carve-out. Where a design spec is approved *with required
corrections held by the Designer*, the plan is written against the **approval note** (which governs
over the spec body) and says so in its header and certification.

**When a plan *does* carry flags** (plan-08 — the first since plan-04): say so in the **Status line
itself**, not only in the Handoff ("self-certified **except** the two items at Owner-approval flags"),
enumerate the whole data-model change set in **one clearly-marked § Data Model block** so the Owner
rules on it at once, and add the mirror of the no-flag case: a short *Why an ADR was warranted here
when the previous item needed none* paragraph. Always pair a data-model gate with an explicit
**"Explicitly *not* in the change set"** list (existing tables/indexes/enum values/backfill, verified
item by item) and a security assessment attached to that gate — the additive-only claim is what makes
the gate a single reversible decision. Certification then names exactly what the Owner must approve
and states that everything else needs no further sign-off; Next Agent reads "Task Planner — **after**
Owner approval of items N".

**Flags without an ADR is a real shape** (plan-11): a plan can carry Owner gates — a new dependency,
an index-only data-model change — and still warrant **no ADR**, because the gates *are* the decision
record and an ADR would restate them. When that happens, invert the no-flag case's paragraph:
*"Why no ADR was warranted here, **when the previous item needed one**"*, walking each candidate
against the bar, **then** walk every ADR the feature touches one by one and say explicitly that it
needs no amendment (imprecise-but-not-contradicted wording — e.g. ADR-003's unimplemented
"own lifecycle" — is *not* grounds to supersede an Accepted ADR). Also useful in the flags case:
sequence **§ Milestones** so the gate-blocked ones are named, and say how many are *not* blocked, so
a pending gate doesn't stall the Task Planner.

**Where a design spec's flagged calls are *contingencies whose trigger the PM assigned to the PE***,
the plan must **pull them explicitly** — name the call, state which branch it resolves to, and state
the user-visible consequence (a caption that now renders nothing, a label that must *not* say
"approximation") so the Designer and Reviewer don't infer it. Same for a feasibility question that
pre-approves a fallback: say precisely where the fallback is taken and where it is not.

**When an Owner amendment lands mid-design** (the PRD "Amendment A" pattern), the house response is
a `## Revision A — what the ruling changed here` table (*prior position → now*) at the top of every
artifact it touches, the plan included. Rules observed: revise a **Proposed** ADR in place (nothing
ratified is being rewritten); reverse an **Accepted** ADR only via a **new superseding ADR** with an
enumerated *Positions superseded* table — partial supersession, so the old ADR keeps its file,
status and full text and gains inline `[Pn — SUPERSEDED by ADR-NNN]` blockquotes. When the
superseding ADR is still only **Proposed** at plan time, annotate the Accepted ADR anyway but
phrase it `[Pn — PROPOSED supersession by ADR-NNN (pending Owner approval)]` (plan-05 annotated
ADR-010 while ADR-014 was Proposed; plan-06 did the same to ADR-011 for ADR-016). A RESOLVED question
doc gets an appended `## Amendment A — superseded answers` table, never an edit to its answer. ACs
are never renumbered. The Owner-flag list is restated **in full** in the plan (it is the single place
the Owner reads it) and grows as the amendment ripples.

**`## Revision A` also covers the PE's *own* post-certification ruling** — a question doc from a
downstream agent that a fully-approved plan cannot answer as written (plan-11 / `Q-11-04`: the design
required a per-day drill-through the plan's three-parameter resolver had no mechanism for). Same shape
as the Owner cases — Revision table at the top, new ruling appended (never renumbered), the affected
prior ruling rewritten in place with an *(Amended YYYY-MM-DD — Revision A)* parenthetical, dated
`### Re-certification at Revision A` below the original Certification — with two differences that
matter: the re-certification names **plan authority under the delegated gate, not an Owner ruling, and
says none was sought**, and it states explicitly that **no already-ruled Owner flag reopens**. Put the
"why this needs no Owner gate and no ADR" walk **inside the new ruling** (against `CLAUDE.md`'s
major-decision list item by item), then add one line for it to the existing *Why no ADR* list.
Where the ruling has a user-visible shape the upstream spec constrains, resolve it by **reusing the
approved template with a new value** rather than authoring copy — that is what keeps it off the
Designer's desk, and say so in the ruling.

**Revision letters keep going, and a later one goes *above* the earlier** (plan-11 gained `## Revision B`
carrying a **PM amendment** — PRD `Amendment B` — onto an already-implemented plan). Shape: revision
sections **most recent first** at the top with a line saying the earlier one is unchanged; a new
`Revised:` clause in the header bullets; new rulings **appended by number** (11, 12, 13) with
*(Added YYYY-MM-DD — Revision B)*; earlier rulings amended **in place** with *(Amended … — Revision B)*;
a **new milestone appended** (M8), never renumbered, plus an explicit line on how it orders against
existing ones; a dated `### Re-certification at Revision B` **below** Revision A's. Two things this
shape adds over Revision A's: say in the re-certification that **carrying a PM amendment is not making
a requirement** (the PM ruled the obligation and assigned the mechanism), and when the amendment
creates **new task material** that fits no existing task, name the split in § *Handoff* and route the
Task Planner rather than letting the Senior Developer infer it. Also: when a binding property the
amendment states turns out to be **already false in the certified plan**, say so plainly ("this
divergence pre-dates the amendment") and fix it in a numbered ruling — that is the walk being checkable
rather than trusted.

**A defect-driven ADR with no PRD behind it is a legitimate shape** (ADR-020 — a latent FIFO
correctness defect found while configuring Horizon, no feature, no plan). Differences from the
feature case: the `Feature:` header bullet says "none" and names how it surfaced; there is **no
plan** (inventing `plan-NN` for a bug would be wrong — CLAUDE.md routes bugs flat to the Senior
Developer, whose `docs/fixes/` record is written on implementation, not by the PE); the whole
implementation change set goes in § *Impact* under a "Code — the complete change set" heading
precise enough for the Senior Developer, plus a tests subsection naming which existing tests are
**superseded rather than weakened**. Two additions worth reusing: lead with a numbered,
plain-language **restatement of the guarantee the system is meant to provide**, written so the
Owner can confirm or correct it rather than infer it from mechanism; and where a candidate fix
would change that guarantee, say so and **stop** — a requirements change is not the PE's to make
even when the Owner's own sentence appears to invite it. Also: when the ADR only *amends* a prior
Accepted ADR's meaning rather than reversing a position, annotate it inline as a **PROPOSED
amendment, explicitly "not a supersession"**, and keep it out of the *Positions superseded* table's
supersession column.

**`## Revision A` on a still-*Proposed* ADR, driven by an Owner requirement that arrived in
response to a gate** (ADR-020: the Owner refused to accept a flagged exposure and asked whether it
could be removed instead). House says revise a Proposed ADR *in place* — do that **and** add the
Revision table anyway, because the Owner has already read the first version and needs to see what
moved. Distinctive moves this shape needs: **quote the Owner's requirement verbatim** as a
blockquote (it is the bar being ruled against, and paraphrase loses the qualifier — "long **term**
store" was the whole hinge); **append** new decisions by number rather than renumbering, and amend
the decisions they interact with in place with a *(Amended … — Revision A)* pointer; where a prior
§ Alternatives bullet is now adopted, **mark it `[ADOPTED — this bullet's original "rejected"
position is superseded …]` and keep the wrong reasoning visible with the correction** (the ADR-010
Amendment B precedent) rather than deleting it; and when the new decision **withdraws** an Owner
gate, say "withdrawn, nothing replaces it" explicitly — a silently shorter flag list reads like an
oversight. Where two Owner requirements pull against each other, say so in one line up front and
show the chosen option satisfies both, rather than defending each separately. Finally: if the
Owner's phrasing turns on a qualifier, **enumerate what falls inside and outside it in a table**
(which stores are "long-term") rather than asserting compliance.

**An ADR takes `## Revision B` too, and acceptance and a new ruling can land in the same edit**
(ADR-020: the Owner approved the gate *and* asked a mechanism question in one message). Shape:
newest revision section **above** the earlier one with a line saying the earlier is unchanged; the
Status bullet becomes `Accepted — Project Owner, <date>` with the revisions listed as sub-bullets
under it; the `## Owner-approval flags (✋)` heading is **kept, never deleted**, with the item
struck through and its approval date — a vanished flag list reads like an oversight; and every
inline `[Pn — PROPOSED supersession …]` annotation on the ADRs it touches is rewritten to
`[Pn — SUPERSEDED by ADR-NNN (Accepted, Project Owner <date>)]`, an amendment to
`[Decision n — AMENDED by …]`. Grep the ADR afterwards for `pending Owner approval` / `until this
ADR is Accepted` — those phrases hide in the Positions-superseded prose.

**When an Owner suggests a mechanism and it turns out to work but still lose, say so in those
words.** ADR-020 Decision 9: "the trait is not broken here, and the ruling does not rest on
pretending it is." Enumerate what it *does* satisfy first (verified in vendor, item by item),
correct any premise in the question that is factually wrong rather than answering around it, and
only then give the reasons it loses — preferring reasons that will still read as reasons in a year
(it removes nothing, it re-opens an inconsistency, it moves failure handling outside code we own,
it forecloses a seam) over vendor quirks. An alternative that loses on architecture reads very
differently later from one that loses on a library detail.

**When the Owner defers the detail of a principle ("we can define rules later"), record the
principle and say what is missing.** Do not pick the threshold; state plainly that the boundary
needs a number to be usable as a test, and that the number is the Owner's at the point a concrete
case exists. Then show the refinement changes no existing entry, so the deferral costs nothing now.

**An Owner ruling landing *mid-design*, after both upstream gates closed, that contradicts the
approved PRD and design spec** (plan-10: the Owner re-grained outbound signing from per-destination
to per-proxy after PRD-10 approval and after the design gate). The house response is **not** a
Revision section — the plan is not yet certified, so it is written to the ruling in the first place.
Five moves that made it checkable: a **banner at the head of every affected artifact** quoting the
ruling verbatim and naming the question doc; **build the backend to the ruling and STOP at the
surface**, because a displaced screen is the Designer's and is downstream of a PM amendment; **split
the milestone** so the blocked half (`M8b`) is the only thing waiting and says what blocks it — a
question doc, not an Owner gate; a **question document to the Product Manager enumerating exhaustively
what goes stale**, AC by AC and screen by screen, with an "unchanged, and worth confirming explicitly"
column for the things that only *look* affected; and name the **one substantive trade-off** the
amendment should be ruled with in view (here: a proxy's fan-out becomes one trust domain) rather than
letting the PM inherit it. Never edit the PRD or the design spec.

**When the Owner *directs* a mechanism mid-flight, present it as a CHOICE at the gate, not as
pre-approved** (plan-10 flag 2: the Owner asked for a secrets table instead of columns). Write the
directed option as **RECOMMENDED** with the honest costs, enumerate the rejected alternative in full
so a `no` is immediately buildable, and say in the flag "the alternative ruling available to the
Owner" (plan-11 flag 1's shape). **Couple the flags explicitly** — "flag 1's change set is the
recommended model; a `no` on flag 2 substitutes the alternative at § Data Model" — so the Owner is not
approving a schema whose premise they may reject. Also: when a directed model **does not fit one
member of the set**, say so and keep that member out (the non-rotating credential stayed as columns)
rather than forcing all three in.

**"The storage model is general, the behaviour is narrow" is a reusable resolution** when an Owner's
direction implies more capability than an approved AC permits (AC29 capped live secrets at two; the
Owner's "1, 2, 3.. relations" implied more). Write the resolution down explicitly as the resolution,
make the *write path* enforce the AC so it stays literally true, make both *read paths* assume no
number so raising the cap later is one line, and **route the "should the cap change?" question to the
PM** rather than widening an approved criterion on an inferred reading.

**A *purely additive* `## Revision A` is the right shape when the plan is approved and nothing in it
becomes false** (plan-10 / `Q-10-05`: a design-gate correction assigned the PE a wire shape the plan
predated). Differences from the amend-in-place cases: say **"purely additive: no existing ruling,
gate, milestone, ADR or approval is altered"** in the new `Revised:` header bullet *and* under the
Revision table; carry the § *Validation*, § *Test strategy* and § *Implementation Notes* material
**inside** the Revision section as explicitly-labelled *additions* ("the section above is unchanged"),
so the certified sections are never edited; and where an existing **§ Explicitly out of scope** bullet
is now false, **append an inline `*(No longer out of scope — superseded by § Revision A ruling N …)*`
annotation and keep the bullet's own words**, saying they still record why the plan withheld it at
certification. Close with a **Task material** paragraph naming what the unblocked task now needs
(extra Files entries, test items) so the Task Planner is routed rather than left to infer.
**Reject an alternative on failure direction where you can** — "a lost boolean costs a second click,
a collapsed absent-vs-empty distinction destroys a secret" outlives any ergonomics argument — and
when a vendor claim cannot be verified (package not installed in the tree), **say so and make the
argument not depend on it** rather than asserting it.

**A *feasibility study* is a legitimate fourth PE artifact, and it is neither a plan nor an ADR**
(`docs/architecture/prd-16-template-model-feasibility.md` — the Owner asked for proof a proposed model
works *before* approving the Draft PRD). Filed in `docs/architecture/` beside the ADRs, named
`prd-NN-<slug>-feasibility.md`, and the Status line must say in its first words that it is
informational: not a decision, not a plan, no ADR, approves nothing, reinterprets nothing. Writing one
is **not** planning against an unapproved PRD, and saying so explicitly in the header is what keeps it
from reading that way. Moves that made it useful: quote the Owner's request **verbatim** up front so
the study is checkable against what was asked; restate the model under test as a **table of axes with
AC numbers**, then say plainly what would count as *failure*; carry a **per-item confidence marker**
(High/Medium) with an explicit "what still needs verifying against the live source" clause, and state
once, loudly, that illustrative values are not real test vectors — a confidently wrong example is worse
than a marked-uncertain one; give the **negative results equal weight and their own Part**, one block
each naming *which axis* is violated and what supporting it would cost; and give a coverage **count
plus the caveats that stop it being over-read** (convenience sample, not weighted by traffic, "the
misses are conspicuous") rather than a bare percentage. Every gap found is routed as a **numbered
question to the Product Manager**, listed in the study so the Owner can see what they would be
approving *with* — the question docs themselves are raised at handoff after approval, mirroring the
PRD's own posture on its open questions. Close with an explicit **§ What this study does not do**.
**An ADR that supersedes *in part* two Accepted ADRs at once, with no PRD behind it** (ADR-025 — an
Owner product ruling changed what the outbound header set should contain). Shape that worked: a
`## The product position this ADR renders` section directly under the header, quoting the ruling once
as the authority for reversing ratified positions and enumerating its consequences — **that is a
settled position, not a chronology**, and it is what licenses the supersession. Then a
`## Positions superseded` table with a row per position, each **named (P3, P4, "Decisions 3 and 4"),
quoted verbatim, and given its replacement**, plus a **"Not superseded, and named because each looks
as though it should be"** list underneath — that list is what stops a reader over-reading the change.
Where a superseded bullet's *premise* is wrong (not just its conclusion), correct the premise
explicitly ("a signature header carries a digest, not a key"): a Reviewer can apply a corrected
premise to a header they have never seen, which a conclusion does not give them. The superseded ADRs
get **inline `[Pn — PROPOSED supersession by ADR-NNN (pending Owner approval)]` blockquotes plus one
sub-bullet under their Status line** — verify with `git diff --numstat` that those edits are
**pure insertions, zero deletions**, which is the checkable form of "additive pointer, not a rewrite".

**An approved plan gets a `## Pointer to ADR-NNN (Proposed)` section, not a `## Revision`, when
nothing in it changes yet.** Say "this is a pointer, not a revision" in the first line, list the
passages that *would* go stale, state that each remains accurate as certified, and add **no
re-certification** — a revision letter implies a ruling moved, and none has while the ADR is Proposed.

**When a decision's literal value is the Owner's but its principle is yours, ship the principle and
mark the value with a find-and-replace placeholder** (`<BRAND>`). The ADR stays complete and
committable, the flag says "a value they supply, not a decision to ratify", and one `sed` closes it.
When the value arrives, **fold it in as decided and delete the placeholder scaffolding** — a
settled document should not narrate that it was once pending. Same for an option the Owner floats
and then withdraws (a configurable header prefix): remove it from § *Alternatives* entirely rather
than recording it as considered-and-rejected, but **keep the neighbouring rejection that prevents a
real defect** (member-configurable per-proxy names would reintroduce the very collision the decision
removes). The test is whether a future reader could re-propose something harmful, not whether the
option was ever discussed.

**A "time-critical before merge" decision earns its own `#### Sequencing` subsection**, stating what
the change costs before the merge (nothing) and after (a breaking change), why no mechanism softens
it (no wire version negotiation, no notification surface, no record of who is affected), and that
**the sequencing is part of what the Owner is approving** — not a scheduling note appended to it.

**Full supersession of an Accepted ADR, on an Owner ruling that withdraws a whole capability**
(ADR-026 retiring ADR-022's inbound verification, and reducing the strip list ADR-025 had ruled hours
earlier). Distinct from partial supersession in several ways worth reusing. **Supersede by name and
keep the file** — the retired ADR gets a `SUPERSEDED IN FULL by …` sub-bullet under its Status line
saying "keeps its file, its Accepted status and its full text as the record of what was built and
why". **Then check what cites it**: ADR-023 said the signature construction "is stated once, in
ADR-022 Decision 4, and is not restated here", so retiring ADR-022 would orphan outbound signing's
own signed-content definition — the fix is a `CARRIED FORWARD into ADR-NNN Decision N, which is now
its normative record` pointer on that decision, a full restatement in the new ADR, and a
re-pointing bullet on the citing ADR. **Where a new decision *contains* an earlier one rather than
reversing it, say so in those words** ("its outcome is contained rather than reversed") and
supersede only its **boundary** and its **safety argument** — ADR-025's "the per-proxy strip is what
makes this safe" became void while its five-name removal stood, and that premise correction is the
one a Reviewer most needs. **Confirm the surviving decisions explicitly**: a reader of a
partly-superseded ADR needs "Decisions 2 and 3 stand, whole and operative" as a sentence, and where
a superseded neighbour makes a surviving decision's argument *stronger*, say that too.
**Rule what stays at the level of the method, not the class**, with a table of member → what depends
on it, when a shared service is purpose-parameterised and a grep-driven removal would gut it.
**Rule the migration on "rows are not schema"**: an in-place edit of an unmerged migration converges
no database that already ran it, and cannot express a data deletion at all, so a new migration wins
even on an unmerged branch; reject expand-and-contract by name when nothing reads the columns
concurrently; and write `down()` **honest rather than symmetric** — enumerate what it restores (a
shape) and what it cannot (the values, the secrets, the code), because a symmetric-looking `down()`
invites somebody to believe a rollback recovers configuration. **Say that discarded work was correct
when it was built** — naming completed tasks as built-and-removed rather than as gaps is what lets
the Reviewer read the diff as a deletion rather than an omission. And when the Owner's ruling names
the gated thing in its own words ("Columns, code, etc."), record `Owner-approval flags (✋): none
outstanding`, quote the phrase, and enumerate precisely what "columns" resolved to plus an
"explicitly *not* in the change set" list — the gate is discharged, not skipped.

**The same `## Revision A` shape also covers an Owner ruling landing *post-review*** (plan-07, on a
review Major routed back to the PE because a standing plan ruling forbade the fix). Differences from the
mid-design case: a **plan** ruling is rewritten **in place** with an *(Amended YYYY-MM-DD — Revision A)*
parenthetical — only ratified ADRs get the supersession-by-new-file treatment; the Revision table's rows
name the *review finding* each closes; the original `### Certification` block is **kept** and a dated
`### Re-certification at Revision A` appended below it, naming the authorising Owner ruling; a new
milestone is appended (M7) rather than renumbering; and alternatives the Owner considered and rejected
are recorded **by name**, so they are not re-proposed. Two things a re-ruling must state explicitly even
when the answer is "nothing changes": whether each affected **ADR decision** needs amending (walk them
one by one — "considered, not overlooked" is what the Reviewer checks for), and whether any approved
**copy** needs changing (if it does, that is the PM's call — route it, never rewrite it).
