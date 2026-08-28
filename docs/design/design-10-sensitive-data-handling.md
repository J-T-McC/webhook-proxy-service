# Design Spec: Sensitive data handling

- **Status:** **Approved at the original design gate — with ten required corrections
  (C1–C10)** — **and the amendment approved at a second gate, with four further required
  corrections (B1–B4).** The original gate's approval,
  its ten corrections, its four flagged-design-call rulings and its five non-blocking
  notes all stand and are recorded, unrewritten, at § Approval record (design gate).
  **`## Amendment` (below, at the end of this document) re-grains outbound signing from
  per destination to per proxy**, per PRD-10 `## Amendment B` (Project Owner ruling,
  2026-08-27), and adds one disclosure to the inbound rotation surface (PRD-10 `##
  Amendment B` ruling 2a). **The amendment was approved by the Product Manager on
  2026-08-27 with corrections B1–B4** — see § Approval record — amendment gate
  (2026-08-27), the second, separate gate entry at the very end of this document. Two
  further items are answered in the amendment's own pass, both resolving
  `docs/questions/prd-10-q-10-03-credential-removal-and-secret-field-primitive.md`: a
  **Remove credential** control is added to Screen 3, and the write-only secret field's
  primitive question is settled (plain `Input type="password"` stands, on a corrected
  ground). **Where the amendment and the original spec body conflict, the amendment
  governs**, the same rule the original approval record set for its own corrections.
  **`## Amendment — Screen 6 state 3's ordinary-branch disclosure` (2026-08-28, below,
  at the very end of this document) adds member-facing copy to Screen 6 state 3 and a
  matching Flow H step 2 cross-reference — purely additive, self-certified by the
  Designer under the wording authority PRD-10 AC29 ruling 2a delegates; it reopens
  nothing already approved.**
  **A second, separate amendment — `## Amendment — inbound verification withdrawn
  (2026-08-28)` (below, at the very end of this document) — withdraws Screen 1, Screen 4,
  and Flows A, B and C in full**, per `docs/architecture/adr-026-inbound-verification-
  removal-and-minimal-outbound-header-strip.md` (Accepted, Project Owner, 2026-08-28)
  Decision B and § *Documents*, which routes the withdrawal to the Designer through the
  Product Manager. It re-points Screen 3's shape reference away from the withdrawn Screen
  1, restates correction B2 for its one surviving surface, and corrects Flow G step 5's
  outbound signing header names. **This amendment is written and awaits Product Manager
  re-approval**, the same delegated gate the amendment above went through; until then it
  governs where it conflicts with the spec body, exactly as the first amendment's own rule
  states, and every other approval recorded above is unchanged by it.
- **Author:** Designer
- **PRD:** `docs/product/prd-10-sensitive-data-handling.md` (**APPROVED** by the
  Project Owner, 2026-08-27, as amended — `## Amendment A` **and** `## Amendment B`,
  both ratified whole; 64 acceptance criteria, nothing renumbered)
- **Approved by / date:** **Product Manager, 2026-08-27, at the original gate — and
  Product Manager, 2026-08-27, at the amendment gate (corrections B1–B4, § Approval
  record — amendment gate).** The original gate's record below is unchanged and describes
  what that gate considered.
  Verified against PRD-10's 64 acceptance criteria as they stood before Amendment B:
  every UI-bearing criterion traces to a screen, state or flow; the UX Direction is
  honoured, including all seven of its "not the Designer's to decide" rulings; **no path
  anywhere in this spec reads a stored secret back**; AC29's exclusion of the destination
  credential from the rotation overlap is respected without exception; and the claim
  that the proxies Index and the events list pages are unchanged holds against the
  criteria. See **§ Approval record (design gate)** at the end of this document for the
  coverage trace, the rulings on the four flagged design calls, the ten required
  corrections, the five non-blocking notes, and the three items carried forward to the
  Principal Engineer — **all unchanged by, and pre-dating, the amendment below.** **The
  amendment itself has not yet been approved** — see `## Amendment` and the Handoff's
  Next Agent note.

> **Scope note.** #10 adds **no new page and no new navigation entry.** It extends
> four existing surfaces: **(1)** the proxy create/edit form (`ProxyForm.vue`) gains
> a **Verification** section (inbound scheme + secret, AC23–AC29, AC51–AC53), a
> **Sensitive fields** section (AC12–AC22), and a **Credential** subsection inside
> each destination row (AC30–AC39); **(2)** the proxy **Show** page gains a
> **Verification** card (status + the AC29 end-it-now action) **and** a **Signing**
> card, its proxy-grain sibling, with its own **Manage signing** action (AC54–AC64)
> — the Destinations table gains only the **Credential** status badge, because
> signing is no longer a per-row fact once it applies to every destination of the
> proxy alike *(Amendment — re-grained to the proxy, per PRD-10 `## Amendment B`,
> Project Owner ruling of 2026-08-27; see `## Amendment — outbound signing
> re-grained to the proxy` at the end of this document)*; **(3)** a new **Manage
> proxy signing** dialog, reached from that action, is where the proxy's one
> signing secret is generated, regenerated, shown once (AC57), and rotated (AC58)
> for every destination that proxy dispatches to; **(4)** the event detail page's
> existing
> `PayloadViewer` (design-06 Flow C) is extended so a revealed payload's sensitive
> field **values** — never names, never structure — are obfuscated (AC15–AC22). The
> **proxies Index page and the events list page are unchanged by #10** — neither
> shows a secret, a verification scheme, or payload content today, and #10 adds none
> of those to either (stated explicitly per the responsibility to cover every
> surface, including "not shown here"). No control anywhere in this spec is gated on
> in-flight delivery state, mirrors no per-event or per-destination "mode" pattern,
> and introduces no new permission (AC20, AC28) — every addition below is reached
> through the existing proxy read/update/replay permissions exactly as the surfaces
> it extends already are.

## Decisions carried forward from the UX Direction (not re-litigated here)
Restated so this spec's choices read as consequences, not inventions. The PRD's
`## UX Direction` names these as **not the Designer's to decide**, and this spec
honours every one of them without exception:
- Obfuscation survives the whole-payload reveal (AC18) — the mask that AC25 lifts is
  the *whole-payload* mask; field obfuscation is never lifted, by anyone, ever.
- There is no per-field reveal, for any role (AC20).
- No member-supplied secret is ever redisplayed — the verification secret (AC26)
  and the destination credential (AC33).
- The **proxy** signing secret is displayed **exactly once**, at generation, and
  never again (AC57). *(Amendment — renamed and re-grained from "destination
  signing secret"; see `## Amendment — outbound signing re-grained to the proxy`.)*
- The verification scheme list is closed to exactly two values, presented as a
  selection, never a free-form or described configuration (AC23).
- The rotation overlap is a **fixed 24 hours**, not a member setting, in both
  directions it applies to — the verification secret and the **proxy** signing
  secret (AC29, AC58), **both at proxy grain**. The destination credential has
  **no** overlap at all (AC29's closing clause) and this spec's
  destination-credential surface (Screen 3) is written so nothing on it implies
  one — no "previous", no countdown, no "honoured until" language anywhere near
  it. *(Amendment — the signing secret's grain changed; the credential's exclusion
  did not.)*
- A rotation started while a previous secret is already honoured states, **before
  save**, that the oldest secret stops being honoured immediately (AC29's added
  bullet) — this binds the inbound verification surface (Screen 1) explicitly;
  see `## Amendment`. *(Amendment — added.)*
- Obfuscation discloses nothing about a value's length (AC16).

## Scope boundaries (confirmed, not designed here)
Restated so this spec reads as complete against every AC, not only the UI-bearing
ones:
- **AC1–AC10 — the at-rest guarantee and D2's discharge.** Encryption at rest, the
  closed set of payload stores, the by-reference queue argument, failed-job
  diagnosability, and the key-lifecycle rule are all system properties with no
  surface. Nothing in this spec renders a store name, an encryption state, or a
  "your secret is encrypted" indicator anywhere — the guarantee is structural, not
  displayed (mirroring how PRD-05's own at-rest floor has never had a UI element).
- **AC11 has a surface (C7) — it is not grouped with AC1–AC10 above.** An
  undecryptable verification secret, destination credential, or **proxy** signing
  secret makes the affected operation fail **visibly**, and that failure
  surfaces through the delivery-attempt error treatment `design-06` already
  ships (for a credential or signing-secret failure) or the verification-rejection
  path (AC25, for a verification-secret failure). **#10 adds no new surface for
  it** — no dedicated error card, no "secret undecryptable" banner anywhere in
  this spec; the existing attempt-history and rejection treatments are where it
  appears. The one binding rule: **the rendered failure must never name the
  secret itself** (AC35, AC61 — a destination credential or signing secret
  "appears nowhere but the outbound request" / "its one-time display and the
  signature computation"). This does not conflict with **AC49**'s bar on
  obfuscating delivery-attempt error summaries — the summary itself stays
  unobfuscated exactly as design-06 renders it; the secret is kept out of the
  message text at the source, never masked after the fact.
  *(Amendment — re-grained: the signing failure is proxy-wide, not per row.)*
  **A proxy whose signing secret cannot be decrypted fails dispatch to every
  destination of that proxy, not to one** — AC11 as amended forbids the partial
  fan-out that a per-destination failure would otherwise produce. Concretely:
  every destination of that proxy gets its own failed delivery-attempt entry,
  through the same existing attempt-history treatment named above, and there is
  no destination of that proxy that keeps dispatching signed traffic while
  another fails — the failure is uniform across the proxy's whole fan-out, not a
  per-row state a member has to compare row-by-row to notice. **This is a
  rendering consequence of an existing surface, not a new one**: #10 still adds
  no dedicated "signing secret undecryptable" banner or indicator; the
  proxy-wide extent of the failure is what a member already sees by reading
  every destination's attempt history at once, and nothing in this spec singles
  one destination row out as though the others were unaffected.
- **AC14 — obfuscation matches by field name only, never by value.** No
  card-number-shaped heuristic, no entropy warning, no "this looks like a secret"
  suggestion is offered anywhere this spec touches the Sensitive fields editor
  (Screen 2) or the payload viewer (Screen 7).
- **AC22 — non-JSON payloads get no field-level claim.** Screen 7's obfuscated
  rendering applies only when the stored payload parses as JSON; every other
  payload keeps today's design-06 whole-payload mask/reveal exactly as it ships,
  with no field-level treatment layered on and no message implying one was
  attempted.
- **AC38, AC43, AC64 — header precedence and strip-list rules.** The credential
  header taking precedence over a forwarded inbound header of the same name, the
  verification headers' strip, and the signing headers' precedence are all
  dispatch-time backend behaviour with nothing to render — no header preview, no
  "headers sent" list anywhere in this app today, and #10 adds none.
- **AC39 — `destinations.url`-embedded secrets are left exactly as found.** No
  detection, no warning badge, no migration prompt. A destination whose URL happens
  to carry a token in its query string is indistinguishable, on every screen this
  spec touches, from one that does not.
- **AC40–AC42 — header display stays out of scope.** No screen in this app displays
  a captured header today (ADR-017) and #10 introduces no header viewer.
- **AC44, AC45 — no application-key rotation tooling, no per-team key policy.**
  Neither has a UI-bearing consequence.
- **AC46 — no analytics, counter, or notification for a rejected inbound request.**
  A verification failure produces no `webhook_events` row (AC25), so there is
  nothing for the Events list, the Dashboard, or any future notification surface to
  show, and this spec adds no placeholder, badge, or count for it anywhere. This is
  named because it is the real cost the UX Direction itself flags (point 7): the
  Verification section (Screen 1) compensates the only way it can, by being explicit
  about exactly what the sender must send, so a member's own inspection is the
  debugging path.
- **AC47 — no numeric target** (throughput, latency, verification overhead) is
  asserted or displayed anywhere.
- **AC48 — nothing here depends on #8 or #9.** The Sensitive fields editor (Screen
  2) and the payload viewer (Screen 7) are written entirely against the JSON the
  system already stores today; neither assumes a canonical representation, a
  mapping step, or any #8/#9 concept.
- **AC49 — obfuscation touches stored payload content only.** The ingest URL
  (`CopyField` on Show and Index), destination URLs, delivery-attempt error
  summaries, and every #11 analytics figure are unchanged by this spec — none of
  them is payload content and none of them gains an obfuscation treatment.
- **The proxies Index page (`Index.vue`) is unchanged.** It shows Name, Mode,
  Processing, Ingest URL, and Actions — no secret, no verification state, no
  destination detail. #10 adds a column to none of it.
- **The events list page (`events/Index.vue`) is unchanged.** Its Payload/Delivery
  badges describe retention and delivery state, not field-level content; #10 has
  nothing to add there — obfuscation is a property of a *revealed* payload, which
  only the detail page (Screen 7) ever shows.

## Overview
A team member configuring a proxy now sees three additions to the familiar
create/edit form, in the order a request actually flows: a **Verification**
section, right after Processing, where they can require an incoming request to
prove itself under `standard-webhooks` or a plain `shared-secret` before anything
is captured — framed by what their sender already does, not by protocol names, and
stating exactly what the sender must send so a rejected request is debuggable
without a log; a **Sensitive fields** section, showing the product's fixed default
list (password, token, credit card) alongside the member's own per-proxy additions,
which they can add to or trim freely; and, inside each destination row, an optional
**Credential** the product presents on every dispatch to that destination, entered
once and never shown again. Every secret in these sections behaves the same
way — type it, save it, see that it is *set* and when it last changed, never see it
again, replace it at will — and the form says so before the member ever tries to
check. On the proxy's **Show** page, a new **Verification** card mirrors that
status read-only, and is where a rotation overlap becomes visible as a period
("your previous secret is still honoured until …") with an explicit **End overlap
now** action for the member who needs to kill a leaked secret before the fixed
24 hours run out. A new **Signing** card sits alongside it, because signing is a
property of the *proxy* — one secret, generated by the product, used to sign
every dispatch to every destination that proxy has, including one added later —
and its **Manage signing** action opens a dedicated dialog: the *only* place in
this feature a secret is ever shown to the member, and this is the member's one
chance to copy it before it is gone for good, before they have to reconfigure
every receiver that proxy dispatches to. *(Amendment — re-grained from a
per-destination surface to this proxy-level one; see `## Amendment` at the end
of this document.)* The existing Destinations table gains only the **Credential**
status badge — a per-row **Signed** badge would say the same thing on every row
once signing is on, so it is not shown there. Everywhere else in the app that
already shows a stored payload — the single masked/revealed viewer #6 built — now
obfuscates sensitive field **values** the instant it renders them, leaving field
**names** and the payload's structure fully visible, so a member can still see that
a `password` field exists and where, just never what it holds.

## User Flows

### Flow A — Configure a proxy's inbound verification (create or edit)

> **WITHDRAWN — 2026-08-28.** Inbound verification is removed from the product in full,
> per `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-
> strip.md` (Accepted, Project Owner, 2026-08-28) Decision B and § *Documents*. This flow
> describes nothing to build. It is retained below, unedited, as the record of what the
> design gate at § *Approval record (design gate)* approved — history, not a live flow.
> See `## Amendment — inbound verification withdrawn (2026-08-28)` at the end of this
> document.

*(User stories: "require verification on my ingest URL"; "point the product at the
secret my sender issued me and have it verified as that specification says.")*
1. Member opens **New proxy** or **Edit proxy**. The **Verification** section
   (Screen 1) sits below **Processing**, above **Retry policy**.
2. **Scheme** defaults to **Not required** — identical to every proxy today
   (AC24). No secret field renders in this state.
3. Member selects **"My sender already implements Standard Webhooks"**
   (`standard-webhooks`) or **"My sender sends a shared secret in a header"**
   (`shared-secret`) — framed by what the sender does (UX Direction point 6), the
   underlying value is the closed AC23 token.
4. The scheme's own fields and its "what your sender must send" statement appear
   (Screen 1). Member pastes the secret **issued by their upstream provider** —
   #10 never generates this one (AC26) — and, for `shared-secret` only, names the
   header their sender will send it in.
5. Member submits with the rest of the form. On success, this proxy now rejects
   any request that fails verification, before capture (AC25) — nothing else about
   the proxy's history, destinations, or ingest URL changes.
6. **Changes mind before saving:** switching **Scheme** back to **Not required**
   discards the in-session entry; nothing is sent.

### Flow B — Replace (rotate) a verification secret

> **WITHDRAWN — 2026-08-28.** Same authority and reasoning as Flow A's withdrawal marker
> above. Retained below, unedited, as history — including correction C5 and the AC29
> ruling-2a disclosure this flow's step 2 added, both of which the design gate and the
> amendment gate recorded against this flow when it was still live. See `## Amendment —
> inbound verification withdrawn (2026-08-28)` at the end of this document.

*(User story: "rotate a secret without coordinating the exact moment with the
other side, because the old one keeps working for a stated period.")*
1. Member opens **Edit** on a proxy that already has a verification secret set.
   The Verification section shows the scheme (fixed, not editable without first
   returning to Not required) and, in place of a blank secret field, a collapsed
   status line: **"Secret set — changed {date}"** plus a **Replace** control
   (Screen 1's write-only pattern, identical in shape to Screen 3's destination
   credential).
2. Member clicks **Replace** → a blank secret field appears (never pre-filled;
   AC26 forbids redisplaying the current value), together with a help line
   stated **before save** (C5), and its wording depends on whether a rotation
   is already running for this proxy's verification secret:
   - **No overlap currently running (the ordinary case):** *"Your current
     secret keeps working for 24 hours after you save this, so you can update
     your sender without a coordinated cutover. To stop it early — for
     example if it's been leaked — use End overlap now on this proxy's page
     after saving."*
   - **An overlap is already running (this proxy already has a previous
     secret being honoured) — *(Amendment — added. PRD-10 `## Amendment B`
     ruling 2a; see `## Amendment` at the end of this document.)*:** the line
     is replaced with *"You already have a previous secret from your last
     rotation, still honoured until {timestamp}. Saving a new secret now
     stops that previous secret being honoured immediately — its 24 hours do
     not finish out."* AC29's two-slot rule discards the oldest secret the
     instant this save lands, so the promise made at the first rotation would
     otherwise be broken silently by this second one; this line is what makes
     it said **before** the member commits to it, not discovered afterward on
     Show.
   Either way, this is the one point in the form where the member decides to
   rotate, so it is the one point that has to carry the consequence, not the
   Show page after the fact (UX Direction point 8).
3. Member types the new secret and saves.
4. On save, the new secret becomes current and the previous one is demoted, not
   discarded — both are honoured inbound for the fixed 24 hours the step-2 help
   text already named (AC29); the period and an **End overlap now** action
   become visible on **Show** (Flow C), because ending it early is a status a
   member checks and acts on there, not a thing they configure a duration for
   here. **A compromised current secret is two steps, now connected by this
   copy:** Replace it here, then End overlap now on Show — without step 2's
   line, a member had no way to know the second step existed.
5. **Cancels before saving:** clicking Replace and then navigating away, or
   toggling back without submitting, changes nothing server-side — identical to any
   other unsaved form field.

### Flow C — View verification status and end a rotation overlap early

> **WITHDRAWN — 2026-08-28.** Same authority and reasoning as Flow A's withdrawal marker
> above. Retained below, unedited, as history. See `## Amendment — inbound verification
> withdrawn (2026-08-28)` at the end of this document.

*(User stories: "see that two secrets are currently honoured and when the older
one stops"; "a member may end an overlap early... without it, the only way to kill
a leaked secret before 24 hours is a second rotation.")*
1. From a proxy's **Show** page, the member sees the **Verification** card
   (Screen 4): the scheme in force, the header name (`shared-secret` only,
   AC26 — it stays visible because the sender has to be configured to match it),
   and the secret's status — **Set — changed {date}**.
2. **While a rotation overlap is running** (because Flow B just happened — a
   signing rotation elsewhere on this same proxy is irrelevant here; this card
   is inbound-only) (N5), an additional line reads: **"A rotation is in
   progress — your previous secret
   is still honoured until {timestamp}."** An **End overlap now** button sits
   beside it.
3. Member clicks **End overlap now** → confirms nothing further (this is a
   safety-tightening action, not a destructive one — see *Interactions*); the
   previous secret stops being honoured immediately and is erased (AC29), and the
   card's rotation line disappears on the next render.
4. **No overlap running:** the card shows only the plain status line from step 1
   — no button, no period language, because there is nothing to end.
5. **Scheme is Not required:** the card states that plainly — **"No verification
   required — this ingest URL accepts any request."** — matching the proxy's
   actual, unconfigured-by-default behaviour (AC24).

### Flow D — Manage a proxy's sensitive-field list
*(User stories: "passwords, tokens and card numbers... hidden when I look at it";
"add my own field names... because the product cannot know that my vendor calls
it `ssn_last4`.")*
1. On **Create** or **Edit**, the member sees the **Sensitive fields** section
   (Screen 2): the fixed default list — every literal name the product matches,
   not a three-word summary — rendered as plain, non-removable badges, so a
   member can tell at a glance whether a name like `cvv` or `api_key` is already
   covered (AC12, C4).
2. Below it, the proxy's own additions render as removable badges. An **Add field
   name** input plus button (or Enter) appends a new one; a badge's **×** removes
   it. Removing an addition never touches the default list (AC13).
3. This section renders identically whether the proxy is Simple or Enhanced —
   obfuscation is not mode-gated (nothing in AC12–AC22 mentions Mode).
4. Saving persists the additions for this proxy only (AC13, per-proxy grain);
   the very next view of any of this proxy's payloads reflects the new list
   (AC19 — retroactive, because nothing is rewritten).

### Flow E — View an event's payload with sensitive values obfuscated
*(User stories: "hidden when I look at it, so debugging a delivery does not put a
customer's secret on my screen"; "obfuscation... never as empty, missing, corrupt,
or cleaned.")*
1. Member opens a **retained** event and clicks **Reveal payload** exactly as
   today (design-06 Flow C, unchanged trigger and unchanged whole-payload mask
   default).
2. The revealed payload renders (Screen 7): every field **name** and the
   payload's full structure are visible; every value whose field name matches a
   sensitive-field rule (product default or this proxy's own addition, matched
   case-insensitively, at any depth — AC14) renders as a fixed, non-informative
   placeholder instead of its real value. A non-sensitive value renders exactly as
   received.
3. Clicking **Hide payload** re-masks the whole thing, exactly as today — there is
   no intermediate state where structure is shown but the whole-payload mask is
   still up (the whole-payload mask and field obfuscation are independent layers;
   Screen 7 states the interaction precisely).
4. **No field is ever individually revealed** — the placeholder carries no click
   target, no button, no affordance of any kind (AC20). The member's only remedy
   for a field they want to see is to remove its name from this proxy's list
   (Flow D) — entirely in their hands, and stated as such in the placeholder's
   accessible description.
5. **Non-JSON payload:** nothing changes from design-06 — the whole-payload
   mask/reveal behaves exactly as it does today, with no field-level claim made
   (AC22).
6. **Cleaned or not-captured event:** unchanged from design-06 — a cleaned
   event's muted "expired" line and a not-captured event's muted line are payload
   states, not obfuscation, and neither is ever confusable with an obfuscated
   value sitting inside a *retained*, revealed payload (AC21) — the two states
   never render on the same screen at the same time.

### Flow F — Configure or replace a destination's credential
*(User stories: "give a destination a credential, so I stop having to paste it
into the destination URL.")*
1. On **Create** or **Edit**, each destination row in the existing repeatable
   fieldset (`DestinationRows.vue`) gains a collapsed **Credential** disclosure
   (Screen 3), defaulting **open** if a credential is already set for that row and
   **collapsed** ("Add credential") otherwise — so a proxy with many destinations
   and few credentials stays scannable.
2. Expanding an unconfigured row shows **Header name** (text, defaults to
   `Authorization`) and **Secret value** (write-only entry). Both are
   member-supplied (AC30); the header name stays visible after saving (it is not
   the secret), the value never will be (AC33).
3. Member fills both (or leaves the row's Credential collapsed and unconfigured
   — entirely optional, AC30) and saves with the rest of the form.
4. **Replacing an existing credential:** the disclosure opens to a status line
   — **"Credential set — changed {date}"** — plus a **Replace** control, the same
   write-only pattern as Screen 1. Clicking it reveals the header-name field
   (pre-filled with the current name, since it is not secret) and a blank secret
   field. Saving replaces the credential **immediately** — no overlap, no
   "previous" state, ever (AC29's exclusion, PRD-10's own explicit carve-out).
5. **A brand-new destination row added this session** behaves identically — it
   simply has nothing to show as "already set" yet.
6. **Removing a destination row** (existing trash-icon control, unchanged)
   removes its credential along with everything else about that row — no separate
   confirmation, matching how removing a row already discards its URL/method with
   no extra step.

### Flow G — Enable a proxy's outbound signing and capture the one-time secret
*(User stories: "prove that a webhook it received came from my proxy... my
receiver can reject everything else"; "see the signing secret once... and never
again afterwards." Amendment — re-grained from a per-destination flow to this
proxy-level one; see `## Amendment` at the end of this document. Pre-amendment
this flow opened from a per-row action on the Destinations table and was scoped
to one destination throughout — see § Approval record for what that gate
considered.)*
1. On a proxy's **Show** page, a new **Signing** card (Screen 4b) sits alongside
   the Verification card. **Not yet enabled** (every proxy today, AC63) shows a
   single statement ("This proxy does not sign its dispatches yet") and an
   **Enable signing** button.
2. Clicking it opens the **Manage proxy signing** dialog (Screen 6), scoped to
   the **proxy**, not to any one destination. Its default state mirrors the
   card's not-yet-enabled statement and repeats the **Enable signing** action.
3. Member clicks **Enable signing** → the product generates the secret
   immediately (AC56 — generation is the only way one exists; nothing is typed).
   The dialog transitions to its **one-time reveal** state: the secret in a
   read-only, copyable field, a plain statement that this is the only time it will
   ever be shown, and a **Done** button that only closes the dialog after the
   member has had the field in front of them — it does not auto-close on copy.
4. Member copies the secret (into their receiver's configuration, outside this
   app) and clicks **Done**. The dialog's next open shows the ordinary **enabled**
   status (Screen 6, default state) — never the secret again, under any
   circumstance or role (AC57).
5. From this point, every dispatch to **every destination this proxy has** —
   original, retry, and replay alike — carries the `WebhookProxy-Id`,
   `WebhookProxy-Timestamp` and `WebhookProxy-Signature` headers, in the Standard
   Webhooks value format, under this one secret *(Amendment — corrected
   2026-08-28; these are the emitted header names per ADR-025 Decision 2,
   standing under ADR-026 — only the value format is the Standard Webhooks one,
   the names are this product's own; see `## Amendment — inbound verification
   withdrawn (2026-08-28)`)* (AC54, AC55, AC60); nothing else about any of those
   requests changes (AC59). **A destination added to this proxy afterward is
   covered immediately, with no separate per-destination enable step** (AC54) —
   there is nothing to turn on per row.

### Flow H — Regenerate a proxy's signing secret, and end its overlap early
*(User story: "rotate a secret without coordinating the exact moment... the old
one keeps working for a stated period" — the outbound half of Flow B/C, AC58.
Amendment — re-grained to the proxy; see `## Amendment`.)*
1. Member opens **Manage signing** from the proxy's **Signing** card (Screen 4b)
   on an already-enabled proxy. The dialog's default state shows
   **"Enabled — generated {date}"** and two actions: **Regenerate signing
   secret** and **Disable signing**.
2. Clicking **Regenerate signing secret** immediately generates a new one (same
   AC56 rule — there is no "type a replacement" path for this secret, ever) and
   transitions to the same one-time reveal state Flow G step 3 describes. What
   happens to the *previously* current secret depends on whether an overlap is
   already running for this proxy's signing secret — the same branch Flow B
   step 2 makes for the inbound direction, because AC29's added bullet (ruling
   2a) binds "a replacement **or a regeneration**" alike:
   - **No overlap currently running (the ordinary case) — *(Amendment — the
     copy citation added; see `## Amendment — Screen 6 state 3's ordinary-branch
     disclosure` at the end of this document.)*:** Screen 6 state 3 states this
     **before** the member clicks **Regenerate signing secret** (see Screen 6,
     state 3, for the exact copy) — the previous secret is demoted, not
     discarded — both are honoured **outbound**, for every destination this
     proxy has, for the same fixed 24 hours (AC58); every dispatch in that
     window, to every one of the proxy's destinations, carries a signature
     under both, per the specification's own space-delimited list, asking
     nothing extra of any receiver.
   - **An overlap is already running (this proxy already has a previous
     signing secret being honoured) — *(Amendment — added; B2.)*:** Screen 6
     state 4 states this **before** the member clicks **Regenerate signing
     secret** (see Screen 6, state 4, for the exact copy) — clicking it
     discards the currently-honoured previous secret immediately, for
     **every destination of this proxy**, rather than letting its 24 hours
     finish out. No confirmation step is added; the disclosure is the
     requirement, not a ceremony in front of a single-click action (§
     Interactions).
3. Once acknowledged (**Done**), the dialog's default state and the Signing card
   both show the rotation line exactly as Screen 4 does for the inbound
   direction: **"A rotation is in progress — your previous secret is still
   honoured until {timestamp}"** plus an **End overlap now** button.
4. Clicking **End overlap now** stops the previous secret being honoured for
   **every destination of this proxy**, immediately, with no further confirmation
   (same reasoning as Flow C step 3 — this narrows exposure, it does not destroy
   anything the member is relying on going forward).

### Flow I — Disable a proxy's signing
*(Falls out of AC54's "optional... off by default" — a two-way toggle implies a
way back to off. Not separately named by an AC beyond that; see Open Questions.
Amendment — re-grained to the proxy; see `## Amendment`.)*
1. From the **Manage signing** dialog's enabled state (or the Signing card
   directly, Screen 4b), member clicks **Disable signing**.
2. Signing stops applying to **every destination of this proxy** immediately —
   their dispatches revert to byte-identical, unsigned requests (mirroring
   AC63's "existing destinations" behaviour, now stated of the proxy rather than
   of one row). No overlap, no confirmation step (non-destructive: nothing
   stored is exposed or lost by this action).
3. **Re-enabling later** always generates a fresh secret and re-runs Flow G's
   one-time reveal in full — the product never resurfaces or reuses a
   previously-generated secret, because AC57 already forbids displaying it again,
   so there is nothing to resurrect it as. Stated explicitly in the dialog's
   disabled-state copy so a member does not expect their receivers' old
   configuration to keep working without updating it — **every** receiver that
   proxy dispatches to, not one.

## Screens & States

### Screen 1 — Create / Edit Proxy form — Verification section (extends `ProxyForm.vue`)

> **WITHDRAWN — 2026-08-28.** Per `docs/architecture/adr-026-inbound-verification-removal-
> and-minimal-outbound-header-strip.md` (Accepted, Project Owner, 2026-08-28) Decision B and
> § *Documents*, inbound verification is removed from the product in full and this section
> no longer describes anything to build. It is retained below, unedited, as the record of
> what the design gate at § *Approval record (design gate)* approved and corrected (C1, C5)
> — history, not a live surface. Screen 3's write-only-shape reference, which this section
> originated, is re-pointed at Screen 3's own restatement rather than at this withdrawn
> section — see `## Amendment — inbound verification withdrawn (2026-08-28)` at the end of
> this document, which also restates correction B2 for its one surviving surface now that
> this screen's half of it is gone.

Placement, in the form's existing section order:
```
Details
  Name
  Mode
  Processing
Verification        (NEW — this spec)
Retry policy         (design-06, unchanged — renders only when Mode = Enhanced)
Response
Sensitive fields     (NEW — this spec, Screen 2)
Destinations         (design-01/06, extended — Screen 3)
```
Verification sits directly after Processing and before Retry policy: verification
gates whether a request is ever captured at all, which happens before retry policy
has anything to govern — the section order follows the pipeline order.
*(Flagged design call 1 — placement is reversible.)*

```
Label "Verification" (legend)
p (help) "Require an incoming request to prove it's really from your sender
   before anything is captured. Off by default — existing proxies are unaffected."
Select v-model="form.verification_scheme"
  SelectItem value="none"                 → Not required (default)   (N2 — the
    underlying Select primitive rejects an empty-string item value, so
    "none" is the sentinel; `form.verification_scheme` normalises it to/from
    whatever the backend's "not required" representation is on submit/mount,
    the same kind of sentinel translation the Retry-policy fieldset already
    does for its own off-state)
  SelectItem value="standard-webhooks"    → My sender already implements Standard Webhooks
  SelectItem value="shared-secret"        → My sender sends a shared secret in a header

[— conditional on scheme —]

v-if scheme === 'shared-secret':
  Label "Header name" for="verification_header_name"
  Input id="verification_header_name" placeholder="X-Signature"
  p (help) "The header your sender sends the secret in. Case-sensitive as your
     sender configures it."
  Label "Secret value" for="verification_secret"
  [write-only field — see States below]
  p (help) "The exact value your sender will send in that header."

v-if scheme === 'standard-webhooks':
  Label "Secret value" for="verification_secret"
  [write-only field — see States below]
  p (help) "The secret your sender issued you for this integration. This
     product never generates it for you — paste the value they gave you."
     (Amendment — reworded from "The signing secret …"; NB1. Under the
     pre-amendment grain this sat a page away from anything else called a
     signing secret; it now sits one card away from Screen 4b's Signing
     card, where "signing secret" means the product's own, so the inbound
     copy drops the word to remove the collision. The specification's own
     term for this value is unaffected — this is member-facing copy, not
     the scheme's vocabulary.)
  div (static, always visible under this scheme)
    p "Your sender must send these three headers on every request:"
    ul
      li "webhook-id"
      li "webhook-timestamp"
      li "webhook-signature — one or more HMAC-SHA256 signatures, base64-encoded,
          space-delimited"
    p "Requests whose webhook-timestamp is more than {tolerance} from the current
       time are rejected, per the Standard Webhooks specification."
```

**Copy source for `{tolerance}`.** The five-minutes figure is the *specification's*
tolerance, not a value #10 invents (AC53) and the specification, not this product,
may change it. The copy interpolates the same value the backend actually enforces
rather than a hand-typed "5 minutes" — the same single-source discipline design-07
established for its default-attempt-limit copy (`ProxyForm.vue`'s
`defaultAttemptLimit`).

**Write-only secret field — shared shape (AC26, and reused verbatim for Screen 3's
credential and Screen 6's signing secret display).** Two states:
- **Unset** (nothing saved yet, or `scheme` freshly changed to one that has never
  held a secret): a plain `Input type="password" autocomplete="off"` (N3), **not**
  `PasswordInput.vue`'s show/hide toggle. *(Q-10-03 item 2, answered here rather
  than left open: `PasswordInput.vue` does exist in this app — N3's claim that no
  password-input precedent exists was wrong, and this spec no longer relies on
  that claim as its reason.)* **Ruled: plain `Input type="password"` stands, on a
  narrower ground than N3 originally gave.** A reveal toggle would breach nothing
  by itself — this field only ever holds what the member just typed, never a
  value read back from storage — but every secret in this feature is deliberately
  designed to behave identically ("type it, save it, see that it is *set*, never
  see it again"), and a Show toggle on the entry field would sit oddly two
  screens away from an inert `[Hidden]` token that exists precisely because
  AC20 forbids revealing anything. One idiom for the whole feature is worth more
  than a convenience on one field. `autocomplete="off"` (the same value
  `ForgotPassword.vue` already uses for a non-login field) stops a browser's
  password manager from offering the member's own login password into a
  verification secret, credential, or signing-secret field that has nothing to
  do with signing in; nothing else about it needs styling beyond the input
  treatment every other text field already has.
- **Set** (editing a proxy with a stored secret for the current scheme): a
  collapsed line, not an input — **"Secret set — changed {date}"** — plus a
  **Replace** button (`variant="ghost"`, small). Clicking it swaps the line for a
  blank `Input type="password" autocomplete="off"` (never pre-filled — there is nothing to pre-fill
  it *with*, since the value was never sent back to the client in the first
  place). A second click on a "cancel replace" affordance (or simply not touching
  the field before submit) leaves the stored secret untouched — the field being
  present-but-empty must **not** submit as "clear the secret"; see *Interactions*.

**Screen 1's instance of this shape carries one addition the shared shape does
not (C5): once Replace is clicked, a help line under the new blank field
discloses the 24-hour overlap before the member saves, and, if a rotation is
already running, discloses the immediate discard instead** *(Amendment —
the second branch added, PRD-10 `## Amendment B` ruling 2a)* — see Flow B
step 2 for the exact copy and reasoning. Screen 3's credential reuses this
shape verbatim **without** that line, because AC29 excludes the destination
credential from any overlap; Screen 6's signing-secret display is a different
sub-state (generation, not replace-in-place) and carries its own overlap
disclosure at Screen 6 states 2 and 4.

**States.**
| Scheme | Fields shown | Status line (edit, already set) |
|---|---|---|
| Not required (default) | none | — |
| `shared-secret`, unset | Header name, Secret value (input) | — |
| `shared-secret`, set | Header name (visible, editable), Secret value (collapsed status + Replace) | "Secret set — changed {date}" |
| `standard-webhooks`, unset | Secret value (input) + static "what your sender must send" block | — |
| `standard-webhooks`, set | Secret value (collapsed status + Replace) + the same static block | "Secret set — changed {date}" |

**Switching scheme clears the in-session, unsaved secret field** — the same data
operation `design-07`/`design-06` already apply to the Retry-policy fieldset on a
Mode change: a hidden field can never carry a stale value into submit.
**Switching scheme does not touch what is already persisted** until the form is
actually saved — exactly the distinction `ProxyForm.vue`'s existing code comments
already draw between in-session typed values and mount-seeded persisted ones.

### Screen 2 — Create / Edit Proxy form — Sensitive fields section (NEW)
Placement: after **Response**, before **Destinations** (closing the "what this
proxy does with a request" set of sections before the destination list, which has
always been the form's last section).

```
Label "Sensitive fields" (legend)
p (help) "Values in these fields are hidden wherever this proxy's stored payloads
   are shown. This never changes what's stored or what's delivered — see a
   payload's Reveal to check."

div "Always hidden"
  Badge (secondary, no ×) v-for name in defaultSensitiveFieldNames
    {{ name }}
p (help, smaller) "Case and separators don't matter — password, Password and
   pass_word are all this same name."

div "Also hidden for this proxy"
  Badge (outline, ×) v-for addition       — e.g. "ssn_last4 ×"
  [empty: no badges, no placeholder box — matches the Response card's
   "no empty bordered box" precedent]

Label "Add field name" for="sensitive-field-add" (sr-only if visually redundant)
Input id="sensitive-field-add" placeholder="e.g. ssn_last4"
Button "Add" (or Enter key)
```

**This renders the actual default list, not three category labels (C4).**
`defaultSensitiveFieldNames` is a single-sourced value the backend enforces — one
badge per literal entry, iterated, never three hardcoded words — the same
discipline Screen 1's `{tolerance}` interpolation already establishes in this
spec (`defaultAttemptLimit` precedent, `resources/js/data/
proxyRetryBackoffStrategies.ts`). AC12's stated reason for requiring the list be
displayed is that "a hidden default list makes AC13 unusable — the member cannot
know what is already covered"; three category words do not answer whether
`cvv`, `pwd`, `secret` or `api_key` is already matched, and a literal
enumeration does. **The content of that list — how many entries, which spelling
and separator variants of password/token/credit-card ship at MVP — is fixed at
technical design**, exactly as `{tolerance}`'s numeric value is; this spec fixes
only that Screen 2 must render it **completely and literally**, never summarized
back down to the three base words. A long list wraps in the existing
`flex flex-wrap` badge row (§ Responsive Behavior) rather than truncating or
collapsing behind a "show more" — a partially-shown default list would recreate
exactly the problem this correction fixes.

**No enable/disable obfuscation control exists anywhere on this section (N4).**
Obfuscation is always on for every proxy (AC19); there is no switch, toggle, or
setting to turn it off, and nothing in this section's copy or layout implies one
— matching the UX Direction's "no 'enable obfuscation' toggle to forget."

**States.**
- **No additions yet:** the "Also hidden for this proxy" area shows nothing but
  the input/Add control — no "none yet" placeholder text, matching the app's
  existing convention of omitting empty-state filler where the input itself makes
  the emptiness self-evident (`DestinationRows.vue`'s add-row pattern has no such
  filler either).
- **Adding:** typing a name and pressing Enter (or clicking Add) appends a new
  removable badge and clears the input; focus stays on the input so a member can
  keep adding without re-clicking (mirrors `DestinationRows.vue`'s add-row focus
  behaviour in spirit, though that flow moves focus to the new row's own field —
  here there is no per-badge field to move to).
- **Removing:** clicking a badge's × removes it from the in-session list; nothing
  is sent until the form saves (identical semantics to `form.destinations` array
  mutation already in `ProxyForm.vue`).
- **Duplicate/empty entry:** client-side, a blank or whitespace-only entry is not
  added; a name that already exists in this proxy's own additions is not
  duplicated (silently — no error toast, matching this app's low-ceremony
  treatment of a no-op). Whether a name that already matches the **default** list
  is also rejected client-side, or simply accepted as a harmless no-op addition,
  is left to implementation — either is correct because AC12/AC13 never conflict
  (a name can be in both lists with no different effect).
- **Validation error (server-side, e.g. a length cap on a field name):** renders
  through the existing `InputError` pattern under the Add control, exactly like
  any other field on this form.

### Screen 3 — Create / Edit Proxy form — Destinations fieldset, Credential subsection (extends `DestinationRows.vue`)
Each destination row keeps its existing shape (URL input, Method select, remove
button) and gains one new block underneath, inside the same bordered row `div`:

```
div.grid.gap-2.rounded-md.border.p-3   (existing row, unchanged)
  [URL input] [Method select] [Remove button]   (existing, unchanged)
  Collapsible                                     (NEW)
    CollapsibleTrigger as-child
      Button variant="ghost" size="sm"
        ChevronDown/ChevronRight (rotates open/closed, existing Collapsible affordance)
        {{ hasCredential ? 'Credential: set' : 'Add credential' }}
    CollapsibleContent
      [the write-only secret-field shape this feature uses throughout — unset: a plain,
       never-pre-filled `Input type="password"`; set: a collapsed status line plus a
       Replace control; a present-but-empty field never submits as "clear the secret" —
       applied to two fields: (Amendment — re-pointed 2026-08-28; this shape originated
       at the now-withdrawn Screen 1 and is restated in full at `## Amendment — inbound
       verification withdrawn (2026-08-28)`, § *Screen 3's re-pointed reference*.)]
      Label "Header name" / Input (default "Authorization", visible + editable always)
      Label "Secret value" / [write-only field — unset: Input type="password";
        set: "Credential set — changed {date}" + Replace + Remove credential]
      p (help) "Sent verbatim on every dispatch to this destination — the
         product adds no scheme prefix (e.g. enter "Bearer abc123" yourself if
         your destination expects one)."
```

**Remove credential — answers Q-10-03 item 1.** *(Q-10-03, `docs/questions/
prd-10-q-10-03-credential-removal-and-secret-field-primitive.md`, answered here
rather than left open.)* The **set** status line gains a second, ghost-variant
button beside **Replace**: **Remove credential**. AC30 makes the credential
optional, and today the only way back to "no credential" was deleting the whole
destination row, which also discards its URL, method and delivery-attempt
history — too large a cost for undoing one optional field, and inconsistent
with signing's own explicit **Disable signing** affordance one flow over.
Clicking **Remove credential** clears the header name back to its default and
the secret status back to **unset**, in-session; nothing is sent to the server
until the form saves — the same save-time semantics every other field on this
form already has, not an immediate action, and no confirmation dialog is added
(nothing stored is exposed by the removal, and the credential can always be
re-entered — the same standard `docs/standards/design.md` sets for every other
non-destructive action in this spec).

**Removal is an explicit signal, never an empty field** *(Amendment — added;
B3)*. § Interactions rules that "a present-but-empty secret field must not
submit as 'clear the secret'" — that rule protects a member who opens Replace,
changes their mind, and leaves the field blank; it must not be read backwards
as also covering **Remove credential**, which is a distinct, deliberate control
the member chooses on its own, not a blank field arrived at by inaction.
**Clicking Remove credential and leaving a Replace field blank must never be
indistinguishable to the form**, however the two are eventually carried to the
server — that transport is the Principal Engineer's call, not specified here;
what this spec fixes is that the two states have to stay distinguishable
end to end, because collapsing them would silently turn every abandoned
Replace into an unintended removal.

**Default expand state.** A row whose destination already has a credential set
opens **expanded** by default (the member's most likely reason to open this row
is to check or replace it); an unconfigured row opens **collapsed**, keeping a
proxy with many destinations and few credentials scannable. *(Flagged design call
2 — a reversible density decision.)*

**No rotation language anywhere in this block.** AC29 explicitly excludes the
destination credential from the overlap rule — there is exactly one credential
value on the wire at any time, and replacing it is immediate. This block never
says "previous", never shows a countdown, never offers an "end overlap" control
— the write-only pattern here is identical in *shape* to the write-only pattern
this feature uses throughout (unset: plain input; set: collapsed status + Replace)
but different in *consequence*, and the copy says so plainly ("Replacing takes
effect on the next dispatch — there's no transition period."). *(Amendment —
re-pointed 2026-08-28; this comparison named Screen 1 before Screen 1 was
withdrawn — see `## Amendment — inbound verification withdrawn (2026-08-28)`.)*

**States (per row).**
| Row state | Trigger label | Content |
|---|---|---|
| New row, this session | "Add credential" | Header name (Authorization), blank Secret value |
| Existing, no credential | "Add credential" | same as above |
| Existing, credential set | "Credential: set" (expanded by default) | Header name (editable), "Credential set — changed {date}" + Replace + Remove credential |
| Replace clicked | "Credential: set" | Header name (pre-filled), blank Secret value |
| Remove credential clicked (in-session, before save) | "Add credential" | Header name resets to default (Authorization), blank Secret value — same as an unconfigured row |
| **Removal saved** *(Amendment — added; B3)* | "Add credential" | Header name (Authorization), blank Secret value — indistinguishable from "Existing, no credential" once the save round-trips; the removal is complete, not merely staged |

**Removing a row** removes its Credential block with it — no separate prompt,
identical to how removing a row already discards its URL/method silently.

**Permission gating on the Show page.** Every mutating control this spec adds to the
Show page — Screen 4's **End overlap now**, Screen 4b's **Enable signing**, **Manage
signing** and **End overlap now**, and every state-changing action inside Screen 6
(Enable signing, Regenerate signing secret, Disable signing, End overlap now) — is
gated on the same `canUpdate` computed
`resources/js/pages/proxies/Show.vue` already uses for its **Edit** button:
```
canUpdate = permissions.canUpdateProxy && (proxy.is_creator || permissions.canUpdateAnyProxy)
```
This is a reuse of the existing gate, not a new permission (AC28) — a Member viewing a
teammate's proxy without update rights sees the same read-only status lines and badges
this spec already renders, with no control that would 403 if clicked. The Create/Edit
form (Screens 1–3) needs no separate statement: it is only ever reached by a member who
already holds `canUpdate`, so nothing there is newly exposed.

### Screen 4 — Proxy Show — Verification card (NEW)

> **WITHDRAWN — 2026-08-28.** Same authority and reasoning as Screen 1's withdrawal marker
> above. Retained below, unedited, as history. See `## Amendment — inbound verification
> withdrawn (2026-08-28)` at the end of this document.

Placement: alongside the existing Retry policy card, after Destinations (the same
card-stack order the Show page already uses — pipeline configuration cards, then
the destination-facing ones). Uses the same `Card` / `dl`/`dt`/`dd` shape every
other Show-page card already uses (design-03's pattern, its third-plus reuse).

```
Card
  h2 "Verification"
  p (help) "Whether this proxy requires an incoming request to prove it's from
     your expected sender before anything is captured."
  [state block — see States]
```

**States.**
- **Not required (default — every proxy today):**
  `p` "No verification required — this ingest URL accepts any request."
- **`shared-secret`, no overlap:**
  ```
  dl
    dt "Scheme" / dd "Shared secret"
    dt "Header" / dd "X-Signature"           (the member-chosen name)
    dt "Secret" / dd "Set — changed Aug 20, 2026"
  ```
- **`standard-webhooks`, no overlap:**
  ```
  dl
    dt "Scheme" / dd "Standard Webhooks"
    dt "Secret" / dd "Set — changed Aug 20, 2026"
  ```
- **Either scheme, overlap running** — an additional row/line and an action:
  ```
  p "A rotation is in progress — your previous secret is still honoured until
     Aug 21, 2026, 10:03 AM."
  Button v-if="canUpdate" variant="outline" "End overlap now"
  ```
  The rotation line itself always renders — it is status, visible to anyone who can
  view this proxy. **The button is `canUpdate`-gated** (see the note above Screen 4):
  a member without update rights sees the same line with no action to take, matching
  how this app already treats a read-only viewer elsewhere.
  On click: `Spinner` while in flight, button re-disables; on success the line
  and button disappear (the card's next render shows the plain "Set" state); on
  failure, an inline error renders below the button (same treatment as any other
  request-level failure elsewhere in this app, e.g. `ReplayDialog`'s
  `AlertError`).

No loading/error states beyond the page-level ones `design-01` already
specifies — this card renders fields already on the Show payload, no independent
fetch except the End-overlap-now action itself.

**Unchanged by this amendment.** Screen 4 is the **inbound** verification card
only; it never renders anything about outbound signing. The two used to be
easy to conflate because both rotate under AC29, but they are separate cards
rendering separate state — see Screen 4b immediately below.

### Screen 4b — Proxy Show — Signing card (NEW) *(Amendment — added; displaces the
per-destination surface Screens 5 and 6 carried pre-amendment. See `## Amendment`
at the end of this document.)*
Placement: alongside the Verification card (Screen 4), in the same card-stack
position (pipeline/security cards, grouped together, before the destination-facing
Destinations table). Same `Card` shape as every other Show-page card.

```
Card
  h2 "Signing"
  p (help) "Whether this proxy signs its dispatches so every destination it
     sends to can verify the request really came from this proxy."
  [state block — see States]
```

**States.**
- **Not enabled (default — every proxy today, AC63):**
  ```
  p "This proxy does not sign its dispatches yet."
  Button v-if="canUpdate" "Enable signing"
  ```
  Clicking **Enable signing** opens the **Manage proxy signing** dialog (Screen 6)
  directly into its one-time-reveal flow (Flow G).
- **Enabled, no overlap:**
  ```
  dl
    dt "Status" / dd "Enabled — generated Aug 20, 2026"
  Button v-if="canUpdate" variant="ghost" "Manage signing"
  ```
- **Enabled, overlap running** — the same rotation line and action Screen 4 uses
  for the inbound direction, because AC29 is one rule applied at proxy grain in
  both directions:
  ```
  p "A rotation is in progress — your previous secret is still honoured until
     Aug 21, 2026, 10:03 AM."
  Button v-if="canUpdate" variant="outline" "End overlap now"
  Button v-if="canUpdate" variant="ghost" "Manage signing"
  ```
  **The rotation line and the "Enabled — generated …" status always render for
  anyone who can view the proxy** — this is status, not a control. **`Manage
  signing` and `End overlap now` are `canUpdate`-gated** (see the note above
  Screen 4), matching every other mutating control this spec adds to Show.
- **Disabled after having been enabled once:** identical to "Not enabled" — the
  card carries no memory of a prior configuration on its face; that history is
  what the dialog's own disabled-state copy (Screen 6, state 5) exists to state
  when the member re-opens it.

**This card is proxy-wide, and it says so structurally rather than in a
warning.** There is exactly one Signing card per proxy, not one per destination
and not a badge repeated on every row — the same status is true of every
destination this proxy has, so it is stated once, where the setting lives
(AC54). **No trust-domain warning is added here** — PRD-10 `## Amendment B`
ruling 2b rules explicitly that none is required, and this spec follows that
ruling rather than second-guessing it.

**Failure state (AC11, re-grained).** If this proxy's signing secret cannot be
decrypted, the card renders **no dedicated error of its own** — #10 still adds
no new surface for this failure (see § Scope boundaries' AC11 bullet). What the
card must **not** do is imply a state that is not true: it continues to show
whatever its last-known enabled/overlap status was, because that status is
static configuration data, not a live read of decryptability. **The failure
itself is visible where every dispatch attempt already renders its outcome** —
every destination of this proxy's attempt history, in the existing design-06
treatment, all failing together, because AC11 forbids a partial fan-out. A
member investigating why *every* destination of a signing-enabled proxy has
stopped delivering is not left to notice this one row at a time.

### Screen 5 — Proxy Show — Destinations table, extended (Credential badge only)
*(Amendment — the per-row **Signed** badge and the per-row **Manage signing**
action are removed by this amendment; the heading is renamed to match. See
`## Amendment` for what this section looked like pre-amendment.)*
Extends the existing Destinations table (design-11 Screen 3) with no new column
and no new action — one small status badge inline with the existing Destination
cell content.

```
TableCell   (Destination — existing cell, extended)
  Badge outline {{ destination.httpMethod }}       (existing)
  span.font-mono {{ destination.url }}              (existing)
  Badge v-if="destination.hasCredential" outline "Credential"   (NEW)

TableCell   (Actions — existing cell, unchanged by #10)
  Badge v-if="destination.isDeleted" secondary "Deleted"        (existing)
  Button variant="ghost" size="sm" as-child                     (existing)
    Link "View events"
```

**Why no `Signed` badge here.** Signing is a property of the **proxy**, not of
any one destination (AC54) — once enabled, every destination this proxy has
receives signed dispatches, including one added afterward, with no per-row
enable step and no per-row rotation state. A badge repeated identically on
every row of the table would say the same true thing on each one, which
carries no information a member could act on at the row level: there is
nothing to turn on or off per destination, so there is nothing for the row to
distinguish. The proxy-level fact belongs on the proxy-level surface — Screen
4b's **Signing** card — not on this table. *(This is the correction Q-10-04's
answer named explicitly: "under a proxy-level secret a per-row `Signed` badge
says the same thing on every row.")*

**The `Credential` badge is unaffected by this amendment** — the destination
credential stays per destination (AC31) exactly as before, so it remains the
one badge this table renders, exactly as designed pre-amendment.

**Why a badge, not a new column, for `Credential`.** The table already carries
four data columns plus Actions (design-11); a fifth column for one boolean
would crowd a table that is already dense on narrow viewports. A badge that
renders only when true (the same "absence is the compliance" idiom the
`Deleted` badge already uses) keeps the row scannable and costs nothing when it
does not apply — which is every destination today (AC37: existing destinations
are unaffected, so this is the common case at ship time). *(Flagged design
call 3 — its second binding condition, that the badge stays an inert status
indicator, is unaffected by removing `Signed`; see § Approval record, which is
not rewritten by this amendment.)*

**No `Credential` action here** — a destination's credential is edited only from
the proxy's **Edit** form (Screen 3), matching the existing precedent that this
table has never carried a per-row "Edit" action; editing a destination's own
fields (URL, method) is already Edit-form-only. The `Credential` badge is a status
indicator, not a button.

**States:** unchanged loading/error handling from the existing table (design-11);
this is a presentation-only extension of data already on the Show payload plus
the one boolean that remains.

### Screen 6 — Manage proxy signing dialog (NEW)
*(Amendment — renamed and re-grained from "Manage destination signing dialog";
see `## Amendment` at the end of this document. Every state below is unchanged
in shape from the pre-amendment version; only the scope — proxy, not
destination — moves.)*
Triggered by Screen 4b's **Enable signing** or **Manage signing** action, scoped
to the **proxy**, never to one of its destinations. Modelled directly on
`ReplayDialog.vue`'s shape (plain `Dialog`, not `AlertDialog` — nothing here is
destructive; see *Interactions*).

```
Dialog
  DialogHeader
    DialogTitle "Signing for {proxy.name}"
    DialogDescription "Lets every destination this proxy dispatches to verify
      that a dispatch really came from this proxy, using the same Standard
      Webhooks specification this product can also verify incoming requests
      under. One secret is used for all of this proxy's destinations,
      including any added later."
  [state block — see States]
  DialogFooter
    DialogClose as-child → Button variant="ghost" "Close"
    [state-dependent primary action(s) — see States]
```

**States.**
1. **Not enabled (default for every proxy today, AC63):**
   ```
   p "This proxy does not sign its dispatches yet."
   ```
   Footer primary action: **Enable signing** (`Spinner` while generating).

2. **One-time reveal** (immediately after Enable signing *or* Regenerate signing
   secret succeeds — same sub-state both times):
   ```
   Alert (info-styled, TeamInvitationAlert.vue precedent)
     AlertTitle "Copy this now"
     AlertDescription "This is the only time this secret will ever be shown.
       Configure every destination's receiver with it before you close this
       dialog — the product cannot show it to you again."
   CopyField :value="secret" copy-label="Copy signing secret"
     announcement="Signing secret copied to clipboard"
   ```
   Footer primary action: **Done** (closes the dialog; does *not* auto-close on
   copy — the member must take the explicit step, per the UX Direction's "the
   experience must optimise for the member actually captures it before leaving
   the screen"). No **Close** (Cancel-style) affordance is offered in *this*
   sub-state — only **Done** — so a member cannot dismiss the one-time reveal by
   habit without it registering as the deliberate acknowledgement it is.
   **`Esc` and overlay-click are also suppressed for the duration of this
   sub-state only** (design-gate ruling 4, overturning the flagged call this spec
   originally left permissive): **Done** is the one and only way out of the
   one-time reveal, keyboard or pointer. Three conditions bound the suppression —
   (a) it applies to this sub-state alone; states 1, 3, 4 and 5 keep Reka UI's
   default `Esc`/overlay-click dismissal, unchanged from every other `Dialog` in
   this app; (b) **Done** stays keyboard-reachable (focus lands on it when the
   sub-state mounts and Tab/Shift+Tab keep it inside the dialog's existing focus
   trap), so this is a deliberate exit, not a keyboard trap under WCAG 2.1.2; (c)
   no confirmation step is added in front of **Done** — the tightening removes
   the accidental exits, it does not add ceremony to the intended one.

3. **Enabled, no overlap:**
   ```
   p "Enabled — generated Aug 20, 2026."
   p (help, same paragraph group as the footer actions) "Regenerating keeps your current
      secret working for the next 24 hours, for every destination this proxy has, so you
      don't need a coordinated cutover. To stop it early — for example if it's been
      leaked — use End overlap now, which appears here and on the Signing card once you
      regenerate."
   ```
   Footer: **Regenerate signing secret** (secondary) + **Disable signing**
   (`variant="ghost"`) + **Close**.
   **The added help line is member-facing copy, not designer commentary**
   *(Amendment — added; see `## Amendment — Screen 6 state 3's ordinary-branch
   disclosure` at the end of this document)*: it renders as part of this state, so
   it is in front of the member **before** they click **Regenerate signing secret**,
   the same ordinary-case branch Flow B step 2 states for the inbound surface,
   scaled to the signing surface's proxy-wide fan-out. It connects this step to
   **End overlap now** exactly as Flow B step 4 credits Screen 1's C5 note with
   connecting Replace to End overlap now on the inbound surface — without this
   line, a member regenerating for the first time had no way to know that action
   existed. **No confirmation step is added**: the disclosure satisfies the
   requirement, and § Interactions' single-click rule for Enable / Regenerate /
   Disable is unchanged.

4. **Enabled, overlap running** (after a Regenerate, until 24 hours pass or
   ended early):
   ```
   p "Enabled — generated Aug 20, 2026."
   p "A rotation is in progress — your previous secret is still honoured until
      Aug 21, 2026, 10:03 AM."
   Button variant="outline" "End overlap now"
   p (help, same paragraph group as the footer actions) "Regenerating again now
      will stop that previous secret being honoured immediately, for every
      destination this proxy has — its 24 hours will not finish out."
   ```
   Footer: **Regenerate signing secret** + **Disable signing** + **Close**.
   **The added help line is member-facing copy, not designer commentary**
   *(Amendment — added; B2)*: it renders as part of this state, so it is in
   front of the member **before** they click **Regenerate signing secret**,
   satisfying AC29's added bullet (ruling 2a) for the signing surface exactly
   as Screen 1's C5 note already does for the inbound one. Regenerating again
   while an overlap is already running is still allowed — AC29's two-slot rule
   discards the oldest immediately, which **is** the documented remedy for a
   compromised secret discovered mid-overlap — and this line is what makes
   that consequence said rather than merely true. **No confirmation step is
   added**: the disclosure satisfies the requirement, and § Interactions'
   single-click rule for Enable / Regenerate / Disable is unchanged.

5. **Disabled (re-visited after Flow I):** identical to state 1, with one line
   added: *"Enabling again generates a new secret — your previous one is never
   shown or reused."* (Flow I step 3's stated behaviour, made visible here so a
   member doesn't assume their receivers' old configuration still applies —
   **every** receiver this proxy dispatches to, not one.)

**Loading/error.** Every state-changing action (Enable, Regenerate, Disable, End
overlap now) disables its own button and shows `Spinner` for the duration of the
request, mirroring `ReplayDialog.vue`'s `form.processing` convention exactly. A
request-level failure renders through the same `AlertError`-style inline region
`ReplayDialog.vue` already uses, above the footer; the dialog stays open and the
prior state is retained (nothing is assumed to have partially applied).

### Screen 7 — Event detail — Payload card, sensitive-value obfuscation (extends `PayloadViewer.vue`)
No change to the **masked** (default) state or the **Reveal**/**Hide** toggle
mechanics — design-06's Flow C is untouched. The change is entirely inside the
**revealed** state, and only for a payload that parses as JSON (AC22).

**Revealed, JSON payload:** the payload renders pretty-printed — **a consequence of
parsing the stored payload in order to walk it and obfuscate sensitive values,
confined to this path only, not a requirement AC15 itself makes (C9).** AC15's
structure clause is satisfied by field names and structure staying visible, not by
reformatting; the reformatting is what parsing-to-obfuscate produces as a
side-effect, not a separate goal. Field names and non-sensitive values render
exactly as received, and every sensitive value is replaced by a fixed,
distinctly-styled inline token:

```
[…structure, e.g.…]
{
  "customer": {
    "email": "jane@example.com",
    "password": [Hidden]        ← scalar sensitive value
  },
  "payment": {
    "token": [Hidden]           ← object value: replaced whole. Its own sub-keys
                                    (card number, expiry, cvv, whatever it holds)
                                    never render — the object is not walked into.
  },
  "amount": 4200
}
```

**A sensitive field's entire value is replaced by one token, whatever its type —
objects and arrays included (C6, ruled by the Product Manager as requirements
author).** If a sensitive field's value is an object or an array rather than a
scalar, the whole value becomes a single `[Hidden]` token; the product never walks
into it to obfuscate its members individually and never renders any part of its
sub-structure. Grounds: AC16 bars disclosing anything about an obfuscated value,
and an object's own keys and shape are exactly that kind of disclosure. AC15's
structure guarantee is satisfied one level up — the member still sees that the
field exists and where it sits in the *payload's* structure — not by exposing
what is inside a value already ruled hidden.

**The token's string is `[Hidden]`, fixed (C8) — not left to implementation.**
AC21 makes this string load-bearing: it must never read as empty, missing,
corrupt or, above all, cleaned, and `[Hidden]` satisfies that directly by naming
the state rather than leaving a blank or a generic placeholder. It renders as an
inline, visually distinct span — muted background, same treatment family as a
`Badge` but inline with running text rather than block-level — and is **inert**:
no click handler, no focus stop beyond what the surrounding text already has,
because AC20 forbids any reveal of it, individually, ever.

**The accessible description distinguishes a default match from this proxy's own
addition (C3).** AC20's stated remedy — removing the name from Sensitive fields —
exists only for a member's own AC13 addition; AC12 forbids removing or editing a
default, so offering that remedy for a `password`/`token`/credit-card match would
promise an impossible action. The token therefore carries one of two
descriptions, chosen by **which list matched** for that value — a per-value data
point the revealed-payload endpoint must return, carried forward to the Principal
Engineer (§ Approval record, *Carried forward to the Principal Engineer*, item 1):
- **Default match:** "Hidden — this field's name matches a product default
  (password, token, or credit card). It can't be removed from Sensitive fields."
- **This proxy's own addition:** "Hidden — this field's name matches an addition
  to this proxy's Sensitive fields list. Remove the name from Sensitive fields to
  stop hiding it."

Both satisfy the UX Direction's "must read as deliberately hidden by a rule you
can inspect" (point 2); the only difference is whether a remedy exists to name.

**The description is exposed as visually-hidden text paired with a native
`title`, not `aria-label` alone (N1).** A bare `aria-label` on a `span` carrying
no interactive or landmark role is not reliably exposed by every assistive
technology — the same objection this spec already raises against a `title`-only
attribute applies to an `aria-label`-only one. The token therefore carries
**both**: the native `title` attribute (for pointer/tooltip users) and an
`sr-only` text node holding the same string inside the span's own accessible
content (for assistive technology, independent of `aria-label` support). See
§ Accessibility for the wiring.

**Fixed-width, not value-shaped (AC16).** The token's rendered width, character
count, and presence are **constant** regardless of the real value's length, type,
or emptiness — it is the same token whether the real value was a
twelve-character password, an empty string, or an object with a dozen keys, and
it renders identically for a string, a number, a boolean, an object or an array.

**Revealed, non-JSON payload:** unchanged from design-06 — the existing raw
`whitespace-pre-wrap` monospace block, no field-level treatment, no `[Hidden]`
tokens anywhere (AC22).

**Payload data shape — Principal Engineer's call, not designed here.** Whether
the revealed endpoint (`PayloadViewer.vue`'s `fetch(props.url)`) returns
pre-rendered, obfuscation-safe markup, or a structured field list the client
walks to build the `[Hidden]` spans itself, is a technical decision folded into
the mechanism the Principal Engineer already owns for this endpoint (the
fetch-on-reveal shape ADR-017 established). That data shape now has to carry two
things fixed by this approval, not just one: **C6's** whole-value replacement for
an object or array sensitive value, and **C3's** per-value default-vs-addition
flag. This spec specifies the **outcome** — a distinctly-styled, accessible,
inert token in place of each sensitive value regardless of its type, carrying the
correct one of the two descriptions above, structure and non-sensitive content
otherwise untouched, pretty-printed as a consequence of the parse — and leaves the
transport to technical design, the same way design-06 folded its own
reveal-mechanism note into Q-06-03 rather than asserting a mechanism itself.

**States (Payload card, complete):**
| Payload state | What renders |
|---|---|
| Retained, masked (default) | unchanged design-06 masked block |
| Retained, revealed, JSON | pretty-printed structure; sensitive values (any type, including objects/arrays) render as a single `[Hidden]` token each (this spec) |
| Retained, revealed, non-JSON | unchanged design-06 raw block, no field treatment |
| Cleaned | unchanged design-06 muted "expired" line |
| Not captured | unchanged design-06 muted line |

## Components
| Role | Component | Status |
|---|---|---|
| Verification scheme select | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Reused, unchanged shape |
| Verification/credential/signing secret entry | `Input type="password"` | **Deliberate choice, not want of a precedent** *(Amendment — corrected; B1)*: `PasswordInput.vue` exists and wraps `Input` with a show/hide toggle, so a precedent for masked entry is available. Plain `Input type="password"` is used anyway, so every secret in this feature keeps one write-only idiom — type it, save it, see that it is *set*, never see it again — rather than a reveal toggle on this field sitting oddly beside the inert `[Hidden]` token elsewhere in this feature. No new primitive either way |
| Write-only status + Replace | plain text + `Button variant="ghost"` | Reused primitives, new small pattern (first use, repeated at Screens 1, 3) |
| Sensitive-field default/addition badges | `Badge` (`secondary` no-×, `outline` with ×) | Reused, unchanged variant set |
| Sensitive-field add control | `Input` + `Button` | Reused, same add-row idiom as `DestinationRows.vue` |
| Destination credential disclosure | `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` | Reused — already-established precedent (design-06's attempt-history use) |
| Verification card | `Card`, `dl`/`dt`/`dd` | Reused, same pattern as Retry policy / Response cards |
| Signing card *(Amendment — added; proxy-grain sibling of the Verification card)* | `Card`, `dl`/`dt`/`dd` | Reused, same pattern as the Verification card |
| End overlap now | `Button variant="outline"` + `Spinner` | Reused |
| Destination status badge (Credential only — `Signed` removed by this amendment) | `Badge variant="outline"` | Reused, same idiom as the existing `Deleted` badge |
| Manage proxy signing dialog shell *(Amendment — renamed from "Manage destination signing")* | `Dialog`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose` | Reused (non-destructive-dialog pattern, `ReplayDialog.vue` precedent) |
| One-time secret reveal | `CopyField` | **Reused outside its original context** — first use for a value other than the ingest URL; same props shape (`value`, `copy-label`, `announcement`) |
| One-time reveal notice | `Alert`, `AlertTitle`, `AlertDescription` | Reused (design-07's first `AlertTitle` use precedent, now a second) |
| Obfuscated value token | new inline `span` (muted background, `title` attribute + `sr-only` text node) | **New small composition**, built entirely from existing tokens — no new `ui/*` primitive, same shape as design-06's `PayloadViewer` masked-block treatment |
| Dialog action feedback | `Spinner`, inline `AlertError`-style region | Reused (`ReplayDialog.vue` precedent) |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**
`Collapsible` and `CopyField` are both reused beyond their first application
context; `Input type="password"` is a new *usage* of an existing primitive, not a
new component.

## Interactions
- **No secret field anywhere in this spec is ever pre-filled with a real value.**
  The unset → set → replace cycle (Screens 1, 3, 6) is the single write-only idiom
  this whole feature uses; an implementer building a second version of it
  anywhere is a defect against this spec.
- **A present-but-empty secret field must not submit as "clear the secret."**
  `ProxyForm.vue`'s submit `transform()` already normalises several fields this
  way (response/retry sentinels); the same discipline applies here — a Replace
  block left empty and never touched sends nothing for that field, not an empty
  string, so the stored secret survives an accidental Replace-then-cancel.
- **Neither "End overlap now" action is gated behind a confirmation dialog.**
  Both narrow exposure rather than destroy anything a member depends on going
  forward (the *replacement* secret keeps working either way) — consistent with
  this app's standing rule (`docs/standards/design.md`) that `AlertDialog` is
  reserved for destructive actions, and with how `design-07`'s non-destructive
  downgrade also skipped a confirm step.
- **Enable / Regenerate / Disable signing are all single-click, no confirmation**
  — for the same reason: nothing is deleted, nothing already dispatched is
  altered, and the one genuinely unrecoverable moment (losing the shown secret)
  is mitigated by the one-time reveal's own **Done**-gated flow, not by a
  separate confirm step in front of it.
- **The Sensitive fields Add/remove controls have no server round-trip** until
  the form is saved — purely in-session array mutation, identical to
  `form.destinations`'s existing behaviour.
- **The Destination credential Collapsible mounts/unmounts state exactly like
  the Retry-policy fieldset's Mode gating** — collapsing it does not clear an
  in-session typed value (unlike the Mode case), because a member toggling the
  disclosure open/closed while inspecting several rows should not lose what they
  just typed in one of them.
- **No interaction in this spec is conditioned on in-flight delivery state**
  (queued, retrying, mid-replay) — mirroring `design-07`'s AC17 treatment; #10
  adds no such gate either.

## Accessibility
- **Every write-only secret field** (Screens 1, 3): `Label for=`/`id` association;
  `aria-describedby` linking help + error, identical wiring to every other field
  on this form; the collapsed "Set — changed {date}" status line is real text
  content, read by assistive technology exactly as sighted users see it (no
  icon-only or colour-only cue).
- **Replace buttons** carry a discernible accessible name naming what they
  replace where ambiguous in isolation (`aria-label="Replace verification
  secret"`, `aria-label="Replace credential for {url}"`) — the icon-only/
  ambiguous-target rule this app already applies to Delete/Remove buttons.
  **Remove credential** (Screen 3, Q-10-03 item 1) carries the same treatment:
  `aria-label="Remove credential for {url}"`.
- **The obfuscated value token** (Screen 7) carries a `title` **and** an `sr-only`
  text node inside the span holding the same string, not an `aria-label` (N1) — a
  bare `aria-label` on a non-interactive, non-landmark `span` is not reliably
  exposed by every assistive technology, the same objection this spec raises
  against a `title`-only attribute. The string itself is one of two, per C3: a
  default-list match names the rule with no removal offered ("Hidden — this
  field's name matches a product default (password, token, or credit card). It
  can't be removed from Sensitive fields."); this proxy's own addition offers the
  removal remedy ("Hidden — this field's name matches an addition to this
  proxy's Sensitive fields list. Remove the name from Sensitive fields to stop
  hiding it."). It is not focusable and not interactive, consistent with AC20:
  nothing here should announce as "button" or "link" to a screen-reader user,
  because there is nothing to activate.
- **Sensitive-field badges:** each removable addition's × carries
  `aria-label="Remove {name} from sensitive fields"`; the add `Input` has a
  programmatically associated `Label` (visually present, not placeholder-only).
- **Manage signing dialog:** `DialogTitle` + `DialogDescription` present per Reka
  UI's requirement; the one-time reveal's `CopyField` reuses its existing
  `aria-live="polite"` copy announcement verbatim. Focus trap and
  return-to-trigger on close are Reka UI defaults, relied on as everywhere else
  in this app.
- **"End overlap now" actions** (Screens 4, 4b, 6): standard button semantics; their
  disabled-while-in-flight state pairs with the existing `Spinner`
  `role="status"` convention.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline; every
  interactive pattern here is an already-vetted Reka UI primitive
  (`Select`, `Collapsible`, `Dialog`) composed the same way this app already
  composes them.

## Responsive Behavior
- **Verification section fields:** same `w-full sm:w-64`/`sm:w-32` conventions
  as every other enum/short field on this form — full-width below `sm`, fixed
  above, never the reverse.
- **Sensitive-field badges** wrap naturally in a `flex flex-wrap` row at any
  width; the Add input/button pair goes full-width below `sm` matching the
  form's existing field convention.
- **Destination credential disclosure:** the `Collapsible` content stacks its two
  fields vertically at any width (it already sits inside a narrow bordered row);
  no bespoke breakpoint handling needed.
- **Manage signing dialog:** Reka UI's default responsive `Dialog` sizing, same
  as `ReplayDialog.vue` — no bespoke handling; the one-time `CopyField` wraps its
  input/button pair exactly as the Ingest URL card's does today.
- **Destinations table's new badges:** inline with the existing Destination cell
  content, wrapping with it inside the table's existing horizontally-scrollable
  container (no responsive stacking variant, per `docs/standards/design.md`).
- **Minimum supported width:** 360px, the standing default — no feature-specific
  override.

## Open Questions
None blocking. The four flagged design calls raised at first submission are now
**ruled** — see `## Approval record (design gate)` § *Rulings on the four flagged
design calls* for the reasoning — and are restated here only as a resolved log,
not as open items:

1. **Verification section placement — after Processing, before Retry policy**
   (Screen 1). **Accepted as designed.**
2. **Destination credential's Collapsible default-expand rule** (Screen 3): open
   by default only when already set, collapsed otherwise. **Accepted as designed**,
   with a binding condition already folded into Screen 3 above: the collapsed
   trigger label itself must carry *set / not set*.
3. **Destinations-table status badges, not new columns** (Screen 5). **Accepted as
   designed**, with two binding conditions already folded into Screen 5 above: each
   badge carries text, never colour or icon alone, and `Credential` stays a status
   indicator rather than an action. *(Amendment — the `Signed` badge this ruling
   also covered is removed by this amendment, because signing is now a proxy-grain
   fact and would say the same thing on every row; the `Credential` badge and both
   binding conditions stand unchanged. See `## Amendment` at the end of this
   document.)*
4. **The one-time signing-secret reveal permits `Esc`/overlay-click dismissal**
   (Screen 6, state 2). **Overturned — both are now suppressed for that sub-state
   only**, with **Done** as the sole, keyboard-reachable exit and no confirmation
   step added; see Screen 6 state 2 above, which is written to the overturned
   ruling.

**One implementation-level note for the Principal Engineer** (not a UX
ambiguity — a technical "how," folded rather than raised as a new question
document): the exact data shape the revealed-payload endpoint returns for a JSON
payload with sensitive values obfuscated (Screen 7) — pre-rendered
obfuscation-safe markup, or a structured field list the client renders itself.
The UX outcome (pretty-printed structure, a distinctly-styled inert `[Hidden]`
token per sensitive value, nothing else changed) is fully specified above and
holds either way; the transport is the Principal Engineer's call at technical
design, exactly as `PayloadViewer.vue`'s original fetch-on-reveal shape was
(design-06's own folded note, resolved into ADR-017 Decision 6).

**Also not designed here, by construction rather than oversight** (so a later
reader does not mistake an absence for an omission): a "disable verification"
confirmation of any kind (Flow A step 6 treats it as a plain field change, the
same low-ceremony precedent `design-07`'s AC17 already set for a *more*
consequential mode switch); and whether a proxy's disabled signing secret
is erased from storage immediately or simply stops being applied (Flow I step 2)
— a storage-lifecycle detail with no user-facing difference either way, since
AC57 already forbids ever displaying it again regardless. *(Amendment — updated
from "a destination's" to "a proxy's"; the question itself is unchanged by the
re-grain.)*

## Handoff
- **Inputs:** `docs/product/prd-10-sensitive-data-handling.md` (Approved, as
  amended — esp. `## UX Direction`, AC1–AC64, `## Amendment A` **and**
  `## Amendment B`, both approved by the Project Owner, 2026-08-27);
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`
  (RESOLVED — the outbound-credential shape AC30–AC39 render);
  `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md` (RESOLVED,
  Principal Engineer, technical — nothing in this spec depended on its answer);
  `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`
  (RESOLVED by PRD-10 `## Amendment B` — the ruling this design amendment
  renders); `docs/questions/prd-10-q-10-03-credential-removal-and-secret-field-
  primitive.md` (RESOLVED by the Designer in this same pass — see Screen 3's
  Remove-credential control and Screen 1's write-only-primitive note);
  `docs/architecture/adr-021-secret-handling-and-rotation.md`,
  `docs/architecture/adr-023-outbound-request-contract.md` (Accepted — the
  proxy-grain storage and signature-list shape this amendment's screens render
  against); `docs/design/design-06-retry-replay.md`
  (`PayloadViewer.vue`'s fetch-on-reveal mask/reveal mechanics, extended not
  rebuilt at Screen 7; the Collapsible/attempt-history precedent Screen 3
  reuses); `docs/design/design-07-enhanced-mode-toggle.md` (the write-only
  disclosure-timing precedent, the mount-seeded-vs-in-session-typed distinction
  Screen 1's scheme-switch clearing follows, `AlertTitle`'s first use);
  `docs/design/design-11-analytics.md` (the Destinations table Screen 5 extends,
  and its no-verdict/status-badge idioms); `docs/design/design-04-queued-
  processing.md` (create/edit form section-ordering convention); `docs/standards/
  design.md` (`AlertDialog`-for-destructive rule underlying every non-destructive
  `Dialog` choice in this spec; write-only/secret-field baseline);
  `resources/js/pages/proxies/{ProxyForm,Show,Create,Edit,Index}.vue`,
  `resources/js/pages/proxies/events/{Show,Index}.vue`,
  `resources/js/components/{DestinationRows,PayloadViewer,CopyField,
  ReplayDialog,TeamInvitationAlert,AlertError}.vue`,
  `resources/js/components/ui/{alert,alert-dialog,badge,card,collapsible,
  dialog,select,input,button,spinner}/*`, `resources/js/types/proxies.ts`,
  `resources/js/data/proxyRetryBackoffStrategies.ts` (the single-source-copy
  precedent Screen 1's `{tolerance}` interpolation follows) — current
  implementation and existing components studied for this spec.
- **Outputs:** this design spec.
- **Dependencies:** no new npm dependency, icon library, or `ui/*` primitive.
  `Input type="password"` is a new *usage* of the existing `Input` primitive, not
  an addition. `CopyField` and `Collapsible` are reused beyond their originating
  context, not added.
- **Outstanding Questions:** None blocking. Four flagged, reversible design calls
  from the original gate, ruled — see § Approval record. One technical transport
  note folded for the Principal Engineer, resolved at technical design rather
  than gating this one (mirroring `design-06`'s Q-06-03 precedent). **Q-10-02**
  RESOLVED. **Q-10-04** RESOLVED by PRD-10 `## Amendment B` and rendered into
  this amendment. **Q-10-03** RESOLVED in this same pass. Nothing is open.
- **Next Agent:** **Product Manager**, to re-approve this amendment against
  PRD-10 `## Amendment B` (design gate, delegated per `CLAUDE.md`) — this
  revision changes material the Product Manager already approved once (the
  signing surface's grain, and the AC29 second-rotation disclosure), so it goes
  back through the same gate rather than being treated as self-certified. On
  re-approval, hands to the **Principal Engineer**, whose `plan-10` is already
  written to this ruling and needs nothing further from this amendment beyond
  the screens it revises.
  **Done (original gate): approved 2026-08-27 with corrections C1–C10 — see
  § Approval record (design gate) below, which records what that gate
  considered and is not rewritten by this amendment.** **Done (amendment
  gate): the amendment (outbound signing re-grained to the proxy, per
  `## Amendment B`) was approved by the Product Manager on 2026-08-27 with
  four required corrections, B1–B4 — see § Approval record — amendment gate
  (2026-08-27) at the very end of this document. B1–B4 land under that
  approval and do not come back through the gate; B2 must be applied before
  `plan-10`'s M8b is broken down into tasks.** Next: the **Principal
  Engineer**, whose `plan-10` is already written to this ruling.
  **A further amendment, `## Amendment — inbound verification withdrawn
  (2026-08-28)` at the very end of this document, is written and awaits
  Product Manager re-approval** — per
  `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-
  header-strip.md` (Accepted, Project Owner, 2026-08-28) § *Documents*, which
  routes the withdrawal of Screen 1, Screen 4 and Flows A, B and C to the
  Designer through the Product Manager. **Next Agent for this amendment:
  Product Manager.**

## Approval record (design gate)

**Approved by: Product Manager · 2026-08-27 · with ten required corrections (C1–C10).**

All ten corrections land **under this approval**. None requires re-approval: each is
stated concretely enough below for the Designer to land it and for a Reviewer to check
it against the finished surface. Until they land, this record governs where it conflicts
with the spec body.

### Coverage verified against PRD-10's 64 acceptance criteria

Every criterion with a user-facing surface was traced to a screen, state or flow in this
spec, and the spec was checked in the other direction for requirements it invents that
the PRD does not carry.

**Covered as designed.** AC12 and AC13 (the fixed default list and the member's
per-proxy additions, together in one place, subject to **C4**) · AC14 (name matching,
case-insensitive, at any depth, with the absence of every value-shaped heuristic stated
rather than assumed) · AC15 (values obfuscated, names and structure left visible) ·
AC16 (the token is fixed-width and identical for a string, a number, a boolean, an empty
value and a twelve-character password) · AC17 (the Screen 2 help copy states that
nothing stored and nothing delivered changes) · AC18 (field obfuscation is never lifted
by the whole-payload reveal) · AC19 (retroactive on the next view, and no
enable-obfuscation toggle exists, subject to note N4) · AC20 (the token is inert — no
click target, no focus stop, no role that announces as actionable) · AC21 (subject to
**C3**; see also the note on empty and missing values below) · AC22 (the non-JSON path
is design-06's, unchanged, with no field-level claim attempted) · AC23, AC24 and AC51
(a three-item selector over a closed list, defaulting to Not required, framed by what
the sender does rather than by algorithm names) · AC26 (write-only, never generated by
us, header name visible under `shared-secret`; subject to **C5**) · AC29 (the overlap
rendered as a period with a real expiry timestamp and an End-overlap-now action, on both
Screen 4 and Screen 6) · AC30, AC31, AC32 and AC33 (per-destination header name plus
write-only value, `Authorization` default, sent verbatim with no scheme prefix added) ·
AC37 and AC63 (badges render only when true, so an untouched destination is
indistinguishable from today) · AC52 (the three headers, the space-delimited signature
list, HMAC-SHA256 and base64 all stated in the sender-facing copy) · AC53 (see below) ·
AC54, AC55 and AC56 (per-destination, off by default, independent of the credential, one
scheme shared with inbound, generated only — there is no type-a-replacement path
anywhere) · AC57 (a single reveal sub-state, reached only at generation, with the
one-time nature stated on screen; strengthened by ruling 4) · AC58 (regeneration rotates
under AC29, both signatures on the wire, end-early available) · AC64 (Screen 6's state 4
correctly allows regenerating during a running overlap, which is AC29's own documented
remedy for a secret compromised mid-overlap).

**AC53's `{tolerance}` is called out for praise rather than correction.** Interpolating
the value the backend actually enforces, instead of hand-typing "5 minutes", is exactly
what AC53 requires — the tolerance is the specification's and the specification may
change it. The `defaultAttemptLimit` precedent is the right one to have reached for.

**Held by construction, verified rather than accepted.** AC20's "no new permission"
holds — the token is inert and no role gates it. **AC28's does not hold by construction
on the new Show-page controls, and that is C2.**

**No invented requirements found.** Everything this spec adds beyond the PRD's own
language — the section ordering, the badge idiom, the Collapsible, the dialog shape, the
`{tolerance}` interpolation — is design detail in service of a stated criterion. Nothing
adds a payload store, an export path, a new permission, a per-field reveal, a
value-pattern heuristic, a third verification scheme, or a rotation overlap where AC29
excludes one.

### Write-only means write-only — verified, and clean

Checked specifically, because a readback would have been a blocking correction rather
than a nit. **No path in this spec reads a stored secret back.** Screen 1's and Screen
3's *set* states are status lines that were never populated client-side, and the spec
says so in the right terms ("there is nothing to pre-fill it *with*, since the value was
never sent back to the client in the first place"). Screen 3 pre-fills only the **header
name**, which AC26 and AC33 keep visible deliberately because the sender and the
destination have to be configured to match it. Screen 6 shows a secret only in the AC57
generation sub-state, and states 3, 4 and 5 never do. Flow I step 3 correctly rules out
resurrecting a previously generated secret when signing is re-enabled.

### AC29's rotation overlap — the exclusion is respected without exception

Screen 3's **"No rotation language anywhere in this block"** is correct and is the right
way to evidence a negative: no "previous", no countdown, no "honoured until", no
end-overlap control, and copy that states replacement is immediate. Nothing elsewhere in
the spec implies a credential overlap — Screen 5's `Credential` badge is a bare status
indicator, and the two places that do render overlap language (Screen 4, Screen 6) are
the two AC29 actually governs.

### The unchanged pages — the claim holds

**The proxies Index page and the events list page really are unchanged by #10**, and the
reasoning is not merely "nothing was added". A verification rejection creates no
`webhook_events` row (AC25), so under AC46 there is nothing for the events list to show.
Its Payload badge describes retention state, while obfuscation is a property of a
*revealed* payload and therefore exists only on the detail page. AC49 bars obfuscating
the ingest URL, which is the only #10-adjacent value the Index renders. No criterion in
PRD-10 requires either page to change.

### The stated non-coverage — confirmed genuine, with one exception

Each boundary was checked for being a real absence rather than a gap wearing a label.
**AC1–AC10** are system properties with no surface; the spec is right that the at-rest
guarantee has never had a UI element and should not acquire one. **AC14's and AC22's
value-matching** exclusions are genuine absences. **AC38, AC43 and AC64** are
dispatch-time header behaviour with nothing to render — this app displays no outbound
header set anywhere. **AC39** is correctly left as found. **AC40–AC42** stand on
ADR-017's record that no surface displays a captured header. **AC44–AC50** carry no
UI-bearing consequence, and AC50's closed list is evidenced positively by the selector
having exactly two schemes plus Not required.

**The one exception is AC11 — see C7.** It is placed in "no surface" and it has one.

### Rulings on the four flagged design calls

**1. Verification section placement — after Processing, before Retry policy. ACCEPTED.**
The pipeline-order reasoning holds: verification decides whether a request is captured at
all, which precedes anything retry policy governs. There is a second reason the spec does
not give and which should be recorded, because it makes the placement more than a
preference: **Retry policy renders only when Mode = Enhanced.** A Verification section
placed after it would change vertical position whenever Mode changed. Above it, the
section is positionally stable in both modes, which matters for a section a member
returns to in order to check a secret's status.

**2. Destination credential Collapsible default-expand — open when set, collapsed
otherwise. ACCEPTED.** The density trade-off is the right one: a proxy with many
destinations and few credentials stays scannable, and the member's likely reason to open
a configured row is to check or replace it. **Binding condition:** the collapsed trigger
label must itself carry the state — `Credential: set` against `Add credential`, as
drafted — so that AC33's *set / not set* is never hidden behind a disclosure. Only the
*changed* date may sit inside the collapsed content.

**3. Credential / Signed as inline badges rather than new Destinations-table columns.
ACCEPTED.** AC37 and AC63 make "neither credential nor signing" the universal case at
ship time, so two columns that are empty for every existing destination would give the
feature a prominence no criterion asks for, on a table design-11 already made dense. The
`Deleted` badge is the established idiom for exactly this. The #11 comparison the spec
raises does not carry: delivery and attempt success are *figures* a member reads across
rows, whereas these are booleans whose absence is the norm. **Two binding conditions:**
each badge carries text, never colour or icon alone; and the `Credential` badge stays an
inert status indicator rather than becoming an action, as the spec already specifies.

**4. The one-time signing-secret reveal permitting `Esc` / overlay-click dismissal.
OVERTURNED — suppress both.** The spec's own reason for withholding a `Close` affordance
in that sub-state — *"so a member cannot dismiss the one-time reveal by habit without it
registering as the deliberate acknowledgement it is"* — applies verbatim to an
overlay-click, which is the **more** common accidental dismissal of the two. Removing
`Close` while leaving `Esc` and overlay-click open is internally inconsistent: it guards
the deliberate exit and leaves the habitual ones. The supporting argument, that the
secret has already been shown so a dismissal costs nothing, conflates *had a chance to
copy* with *copied*; UX Direction point 5 names this as the one place in the feature that
must optimise for the member **actually capturing** the value, and AC57 means a stray
keystroke destroys a value nobody can recover. **Done** is the only exit from the
one-time reveal sub-state.

**Three conditions on the suppression.** (a) It is scoped to the one-time reveal
sub-state only — states 1, 3, 4 and 5 keep Reka UI's default dismissal, which the rest
of this app relies on. (b) **Done** must stay keyboard-reachable, so this is a
deliberate exit and not a keyboard trap (WCAG 2.1.2). (c) No confirmation step is added
in front of **Done**; the tightening replaces the accidental exits, it does not add
ceremony to the intended one.

### Ten required corrections, returned to the Designer

**(C1) Member-facing copy carries a roadmap item number.** Screen 1's
`standard-webhooks` help text reads *"The signing secret your sender issued you for this
integration. **#10 does not generate this** — paste the value they gave you."* A member
has no idea what `#10` is. Say that the product never generates this secret, without the
document reference. Worth a sweep of the other new copy for the same class of leak.

**(C2) The new mutating controls on the Show page are not permission-gated.** Screen 4's
**End overlap now**, Screen 5's **Manage signing**, and every state-changing action inside
Screen 6 (Enable, Regenerate, Disable, End overlap now) change proxy configuration, but
the only gate the spec states is Screen 5's `v-if="!destination.isDeleted"`. **AC28**
requires configuration to be gated by the existing proxy update permission *including the
Member ownership rule* (Q-02-01, ADR-009 Amendment A2.2), and AC30 and AC54 are proxy
configuration under the same gate. The scope note already claims this gating ("reached
through the existing proxy read/update/replay permissions"); the screens have to state
it, or the claim and the screens disagree. **The gate to reuse already exists** —
`resources/js/pages/proxies/Show.vue` computes

```
canUpdate = permissions.canUpdateProxy && (proxy.is_creator || permissions.canUpdateAnyProxy)
```

and gates the existing Edit button on it. Without this, a Member viewing a teammate's
proxy sees controls that will 403.

**(C3) The obfuscated token promises a remedy that does not exist for a default-list
match.** The token's accessible description ends *"Remove the name from Sensitive fields
to stop hiding it."* **AC12 forbids removing or editing a default**, so for a value
hidden by `password`, `token` or a credit-card spelling, that instruction is impossible
to follow. AC20's grounds only ever offered the removal remedy for the member's **own**
additions under AC13. Either distinguish the two cases — the description naming a
product default rule with no removal offered, against this proxy's own addition with the
removal remedy stated — or drop the removal promise and describe the rule without it.
Either is acceptable; promising an impossible action is not.

**(C4) Screen 2 displays three category labels, not the list.** The section renders
**Password**, **Token** and **Credit card** as badges plus a note that common spellings
and separators are matched automatically, with two examples. But AC12's default list
matches a wider set of names than three, and **AC12's stated reason for requiring the
list be displayed is that "a hidden default list makes AC13 unusable — the member cannot
know what is already covered."** A member wondering about `cvv`, `pwd`, `secret` or
`api_key` cannot tell from three category labels whether they need to add it. UX
Direction point 3 puts the same question in the member's own words. Render the names the
product actually matches.

**(C5) The Replace path discloses nothing about the 24-hour overlap it starts.** Flow B
step 4 states outright that *"Nothing in this form states that"* and defers the period to
the Show page. That is precisely the failure UX Direction point 8 names — *"a rotation
that looks instantaneous when it is not will produce members who update their sender late
and never learn that they were covered."* The Replace path must state, **at the point of
replacement and before the save**, that the previous secret stays honoured for a fixed 24
hours and that ending it early is available on the proxy's Show page. This also closes a
path the spec currently leaves unconnected: a member whose *current* inbound secret is
compromised must Replace it and then End overlap now, or the compromised secret keeps
verifying for a day. Two steps, and nothing on either screen links them.

**(C6) Screen 7 does not say what renders when a sensitive field's value is an object or
an array.** The example shows only a scalar. **Ruled here as the requirements author,
in PRD-10's own terms:** a sensitive field's **entire value** is replaced by one token,
whatever its type, **objects and arrays included**. The grounds are AC16 — an obfuscated
value discloses nothing about itself, and rendering a sensitive object's sub-structure
discloses a great deal about it. AC15's structure guarantee is satisfied by the member
still seeing that the field exists and where it sits; AC15 protects the payload's
structure, not the internals of a value that was ruled hidden. State this on Screen 7 so
an implementer does not have to guess between hiding the subtree and walking into it.

**(C7) AC11 is placed in "no surface" and it has one.** The scope-boundaries list puts
AC1–AC11 together as system properties with nothing to render. AC11 is different: an
undecryptable destination credential or signing secret makes the **dispatch fail
visibly**, and that failure surfaces through the delivery-attempt error treatment
design-06 already ships. The correction is to state that #10 adds **no new surface** for
it — the existing attempt-history treatment is where it appears — and that the rendered
failure must not name the secret (**AC35**, **AC61**). Note while landing this that
**AC49 forbids obfuscating delivery-attempt error summaries**, so the answer is keeping
the secret out of the message, never masking it after the fact.

**(C8) The obfuscated token's exact string is delegated to implementation.** Screen 7
says "exact wording is implementation's within this constraint". Copy is the Designer's,
and **AC21 makes this particular string load-bearing** — it must not read as empty,
missing, corrupt or, above all, cleaned. Fix the string in the spec. This follows the
house rule the `design-11` gate set for its three reserved strings: a string an AC
constrains is named in the design, not left to an implementer.

**(C9) Pretty-printing is attributed to AC15, which does not require it.** Screen 7
introduces pretty-printed rendering of the revealed JSON payload and cites AC15's
structure clause as the reason. AC15 does not ask for reformatting. **Ruled here as the
requirements author: reformatting is a *consequence* of parsing the payload in order to
obfuscate it, not a requirement, and it is accepted in scope on that basis, confined to
the JSON path** (the non-JSON path stays design-06's raw block, as the spec already
says). Restate it that way rather than as something AC15 demands. **Recorded for the
Principal Engineer and, if he wants it, the Owner:** the narrowing of PRD-06 AC25 the
Owner accepted on 2026-08-27 was *"the full payload with sensitive values obfuscated"* —
under this spec a JSON payload also comes back **re-serialised**, so the revealed view is
no longer byte-faithful to what arrived. This is a display-only consequence of an
already-accepted narrowing and it does not reopen the gate; it is named here so nobody
discovers it at review.

**(C10) Encoding damage through the body of the document — REPAIRED 2026-08-27.** Large
stretches rendered UTF-8 punctuation as though it had been decoded as Windows-1252:
mojibake in place of the em-dash, en-dash, ellipsis, right-arrow, left-arrow and the
multiplication sign, across Flows A–I, Screens 1–7 and § Interactions. The final count
was 184 em-dashes, 11 multiplication signs and 15 other characters, 189 lines in all.
The header block and the Handoff were clean, so the corruption was localised rather than
whole-file. Everything under `docs/` is read as evidence long after the conversation that
produced it, so it was worth repairing. **It carried no judgement and was byte corruption
rather than prose, so it was repaired mechanically — each damaged line re-encoded as
Windows-1252 and decoded as UTF-8, reversing the original mis-decode — rather than
retyped.** It is recorded as a correction so the trail is complete.

### Five non-blocking notes

- **(N1)** An `aria-label` on a non-interactive `span` is not reliably exposed by
  assistive technology — the same objection the spec correctly raises against a
  `title`-only attribute applies to a bare `aria-label` on a generic role.
  Visually-hidden text, or an element with a role that takes an accessible name, is
  safer. The requirement is unchanged; only the mechanism is unreliable.
- **(N2)** `SelectItem value=""` for **Not required** is not usable with the underlying
  Select primitive, which rejects an empty-string item value. A sentinel is needed.
  Implementation-level, recorded so it is not discovered at build time.
- **(N3)** The write-only `Input type="password"` fields should suppress password-manager
  autofill, or a member's browser will offer their login password into a verification
  secret field.
- **(N4)** Screen 2 should state the **absence** of any enable/disable obfuscation
  control, per UX Direction point 1's "no 'enable obfuscation' toggle to forget". This
  spec already uses the "absence is the compliance" idiom well in several places; AC19
  deserves the same treatment.
- **(N5)** Flow C step 2's parenthetical is garbled mid-sentence ("or a signing rotation
  elsewhere on this same proxy is irrelevant—this card is inbound-only").

### Accepted as designed, though the Designer did not flag it

**Signing is managed from the Show page while the credential is managed from the Edit
form.** The asymmetry is defensible and the spec supplies the reason: signing *generates*
a secret and needs a one-time modal reveal, which cannot live inside an unsaved form, and
Flow G step 1 already states that the action only exists once a destination is saved and
has an id. Recorded here so a later reader does not mistake it for an inconsistency.

Also accepted without a ruling being needed: Screen 2's placement after Response and
before Destinations, and Screen 4's placement in the Show page's existing card stack.

### Carried forward to the Principal Engineer

Three items, recorded so they are inherited rather than rediscovered at technical design.

1. **The C3 data point.** Distinguishing a default-list match from this proxy's own
   addition — if the Designer takes that branch of C3 — requires the revealed-payload
   endpoint to say **which list matched**, per obfuscated value. That is a data-shape
   consequence, and it belongs with the transport question below rather than being
   invented at build time.
2. **The spec's own folded transport note.** The data shape the revealed-payload endpoint
   returns for an obfuscated JSON payload — pre-rendered obfuscation-safe markup against
   a structured field list the client walks — is correctly left to technical design. The
   **UX outcome** is fixed by this approval and holds either way: pretty-printed
   structure, an inert distinctly-styled token per sensitive value, field names and
   non-sensitive values untouched, and **C6's whole-value rule for objects and arrays**.
3. **The `canUpdate` gate C2 must reuse.** It already exists at
   `resources/js/pages/proxies/Show.vue` and is the gate the Edit button uses. C2 is a
   reuse, not a new mechanism, and introduces no new permission — AC28 stands.

Unrelated and unchanged by this gate: **Q-10-02** remains open to the Principal Engineer,
and nothing in this spec depends on its answer.

## Amendment — outbound signing re-grained to the proxy (2026-08-27)

**Ruling amended:** PRD-10 `## Amendment B`, Project Owner ruling of 2026-08-27:
**outbound signing is per proxy, not per destination.** One signing secret per proxy,
shared by every destination that proxy dispatches to (including one added afterward),
rotated at the proxy level. Rendered into PRD-10 as AC54 (rewritten), AC58, AC60
(confirmed), AC63 and § Definitions; routed to the Designer via
`docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`
(RESOLVED). **The destination credential (AC33) is unchanged and stays per
destination** — this amendment does not touch it anywhere.

**Date:** 2026-08-27.

**Author of this amendment:** Designer, in response to PRD-10 `## Amendment B` and the
Q-10-04 answer's § *What the Designer needs, afterwards*.

**Status of this amendment: APPROVED by the Product Manager on 2026-08-27, with four
required corrections (B1–B4).** It went through the same delegated design gate the
original spec used, and that gate's ruling is recorded separately at
**§ Approval record — amendment gate (2026-08-27)** at the end of this document.
**The original approval record above (§ Approval record (design gate))
is retained exactly as it was written and is not rewritten by this amendment** — it
records what that gate considered, which was the pre-amendment, per-destination
design, per the standing rule `design-11`'s gate set and `docs/standards/
documentation.md` (retain history; never rewrite a ruling silently).

### What changed, section by section

| Section | Change |
|---|---|
| § Scope note | Item (2) rewritten: the Show page gains a **Signing** card, its own proxy-grain sibling of the Verification card, rather than badges/an action on the Destinations table; item (3) renamed to the **Manage proxy signing** dialog |
| § Overview | The signing paragraph rewritten to describe a proxy-level **Signing** card and dialog; the Destinations table now gains only the **Credential** badge |
| § Decisions carried forward from the UX Direction | "Destination signing secret" renamed **proxy** signing secret throughout; a new bullet added for AC29's ruling-2a disclosure |
| § Scope boundaries, AC11 bullet | Extended: an undecryptable **proxy** signing secret fails dispatch to **every** destination of that proxy, not one — AC11's fail-loudly rule at proxy grain, forbidding partial fan-out. Stated as a rendering consequence of the existing attempt-history surface, not a new one |
| Flow B, step 2 (Screen 1) | **Added** (PRD-10 `## Amendment B` ruling 2a): the Replace help copy now branches on whether a rotation is already running for this proxy's verification secret, and if so states, before save, that saving now discards the currently-honoured previous secret immediately |
| Screen 1, write-only shared shape | The C5 addition's description extended to name the new branch; the primitive note also answers Q-10-03 item 2 (kept below) |
| Flow G | Rewritten — "Enable a proxy's outbound signing", opened from the new Signing card (Screen 4b), not from a per-row Destinations-table action |
| Flow H | Rewritten — "Regenerate a proxy's signing secret"; the overlap and its end-early action are proxy-wide |
| Flow I | Rewritten — "Disable a proxy's signing"; disabling stops signed dispatch to every destination of the proxy at once |
| Screen 4 | Unchanged in content; one line added noting it is the **inbound**-only card and that outbound signing now has its own sibling card (Screen 4b) |
| **Screen 4b (NEW)** | Added — the proxy-grain **Signing** card on Show, mirroring Screen 4's states (not enabled / enabled / overlap running), gated the same way, and stating the AC11 proxy-wide failure consequence rather than a per-row one |
| Screen 5 | Renamed and narrowed to "Destinations table, extended (Credential badge only)" — the per-row **Signed** badge and the per-row **Manage signing** action are removed; the `Credential` badge and its surrounding text are unchanged |
| Screen 6 | Renamed **Manage proxy signing dialog** (from "Manage destination signing dialog"); `DialogTitle` and `DialogDescription` re-scoped to the proxy; all five states unchanged in shape, including flagged design call 4's ruling that **Done** is the sole keyboard-reachable exit from the one-time reveal, which binds unchanged at the new scope; copy referring to "your destination's receiver" pluralised to "every destination's receiver" |
| § Components | Two rows updated (dialog shell renamed; the destination status badge row narrowed to Credential only) and one row added (the Signing card) |
| § Accessibility | The "Replace buttons" bullet extended to cover the new **Remove credential** button (Q-10-03 item 1) |
| § Accessibility | The "End overlap now" actions bullet's screen list extended to include Screen 4b — Screens 4, 4b, 6 *(Amendment — corrected; B4, this row was misattributed to § Interactions)* |
| § Open Questions | Item 3's resolved-log entry annotated to note the `Signed` badge's removal; the storage-lifecycle note's "a destination's disabled signing secret" corrected to "a proxy's" |
| § Handoff | Inputs, Outstanding Questions and Next Agent all updated — Q-10-02, Q-10-04 and Q-10-03 recorded RESOLVED, ADR-021/ADR-023 added as inputs, and the Next Agent note states this amendment awaits Product Manager re-approval |
| Header block (Status, PRD, Approved by/date) | Rewritten to distinguish the original gate's approval (retained, unrewritten) from this amendment (written, unapproved) |

**Not changed, and deliberately so:** Screens 1–3 and 7 in substance (Screen 1 gains
only the ruling-2a branch above; Screen 3 gains only the Q-10-03 answers below, both
additive); every flow not named above (A, C, D, E, F); every AC55–AC57, AC59, AC61,
AC62, AC64 UI consequence, none of which the grain ruling touches; the whole of
§ Approval record (design gate), which is history and is not rewritten.

### The AC11 proxy-grain failure state, rendered

**AC11 changed behaviour, not only a term** (PRD-10 `## Amendment B`): a proxy whose
signing secret cannot be decrypted must not fall back to dispatching unsigned to *any*
of its destinations — partial fan-out, where some destinations of a proxy keep getting
signed traffic while others silently stop, is exactly the state fail-loudly exists to
prevent. This spec renders that at **proxy grain**, not as a per-row error:

- **No new dedicated surface is added** — #10's existing rule holds: an undecryptable
  secret's failure appears through design-06's existing delivery-attempt error
  treatment, and the rendered failure never names the secret (AC35, AC61).
- **What is new is the extent, stated explicitly in § Scope boundaries and on Screen
  4b:** the failure is uniform across every destination the proxy has, not isolated to
  one row. A member is not left to notice this by comparing rows one at a time — every
  destination of a signing-enabled proxy fails together, because they all draw on the
  one secret that cannot be decrypted.
- **The Signing card (Screen 4b) does not invent a live decryptability indicator** — it
  continues to show its last-known configuration state, because that state is static
  configuration, not a per-request read. The failure surfaces where every dispatch
  attempt already renders its own outcome, which is where a member already looks to
  diagnose *any* delivery failure.

### Q-10-03 — resolved in this same pass, not left for the amendment to carry

Both items are the Designer's own decisions (component and affordance choices, not
requirement questions, as the question document itself says) and are answered in full
in this pass — **nothing in Q-10-03 needs a Product Manager or Owner decision**:

1. **A "Remove credential" control is added** to Screen 3's expanded disclosure, beside
   Replace, resolving the affordance gap the question raised. Save-time, no
   confirmation, per `docs/standards/design.md`.
2. **The plain `Input type="password"` stands**, on a corrected ground: not because no
   `PasswordInput.vue` precedent exists (it does — N3's original claim was wrong and is
   left as history, not rewritten), but because one write-only idiom across the whole
   feature outweighs a reveal toggle's convenience on one field.

`docs/questions/prd-10-q-10-03-credential-removal-and-secret-field-primitive.md` is
marked RESOLVED accordingly.

## Approval record — amendment gate (2026-08-27)

**Approved by: Product Manager · 2026-08-27 · with four required corrections (B1–B4).**

This is a **second, separate gate entry**, for the amendment `## Amendment — outbound signing
re-grained to the proxy (2026-08-27)` only. **The first gate entry — § Approval record (design
gate), with its ten corrections C1–C10, its four flagged-call rulings and its five notes — is
history and is not rewritten by this one.** It records what that gate considered, which was the
pre-amendment, per-destination design.

The four corrections below land **under this approval**. None of them reopens a ruling, and none
requires the amendment to come back through this gate: each is stated concretely enough for the
Designer to apply it without me present and for a Reviewer to check it against the finished
surface. Until they land, this record governs where it conflicts with the amendment or the spec
body.

**What was checked, and against what.** The amendment was read against PRD-10 as approved with
`## Amendment A` and `## Amendment B` (64 acceptance criteria, nothing renumbered), against
Amendment B's four rulings, and against `docs/plans/plan-10-sensitive-data-handling.md`, which is
fully approved and was not reopened.

### Check 1 — the proxy-grain sweep is complete

Every place the spec described signing at destination grain now describes it at proxy grain, and
the sweep reaches further than the three surfaces the ruling names.

- **Screen 5** is narrowed to the `Credential` badge alone. The per-row **Signed** badge and the
  per-row **Manage signing** action are gone, and the section states positively why a per-row badge
  would carry no information a member could act on at the row level (AC54: no per-destination
  enable, secret or rotation state). The `Credential` badge is untouched, which is correct — AC31
  keeps the credential per destination.
- **Screen 6** is re-scoped to the proxy in its title, its description and all five states, and
  the one-time reveal's copy is pluralised to "every destination's receiver", which is the right
  reading of AC57 under the widened loss UX Direction point 5 now names.
- **Screen 4b** is a genuine proxy-grain sibling of the Verification card rather than a relabelled
  row control: one card per proxy, the same three states, the same `canUpdate` gate the first gate
  required at C2.
- **Flows G, H and I** all open from the proxy's Signing card, and each states the fan-out
  consequence in its own terms — Flow G that a destination added afterwards is covered with no
  per-row step, Flow H that one rotation is one overlap for every destination, Flow I that
  disabling stops signed dispatch to all of them at once.
- **The three further places the Designer reports sweeping check out:** the Overview prose now
  describes a proxy-level Signing card and says why the table carries no `Signed` badge; § Decisions
  carried forward renames the secret and re-grains the rotation bullet while leaving the
  credential's exclusion visibly untouched; and the § Scope boundaries AC11 bullet is extended as
  described in Check 2 below.
- **No stale per-destination signing language survives in the live spec.** The remaining
  occurrences of the old grain are all in § Approval record (design gate), where they are history
  and must stay, and in the amendment's own retained quotations of what was superseded.

**Ruling 2b is followed rather than second-guessed.** Screen 4b adds no trust-domain warning and
says so explicitly, which is what Amendment B ruled. The card's scope is legible from where the
control lives, which is the ground the ruling gave.

### Check 2 — the AC11 failure state is rendered at proxy grain

**Satisfied.** AC11 as amended is a behaviour change, not a term change, and the spec renders it as
one. The § Scope boundaries AC11 bullet and Screen 4b's failure-state paragraph both state that a
proxy whose signing secret cannot be decrypted fails dispatch to **every** destination of that
proxy, that no destination keeps dispatching signed traffic while another fails, and that the
member is therefore not left to infer the fault by comparing rows one at a time. The spec is also
right to add no new surface: C7 at the first gate ruled that AC11 surfaces through design-06's
existing delivery-attempt error treatment, and the amendment changes the **extent** of the failure
without inventing an indicator for it. Screen 4b's refusal to render a live decryptability
indicator is correct — the card shows static configuration, and a card that claimed to know
decryptability would be asserting a per-request read it does not have.

**This does not contradict `plan-10`.** The plan's own AC11 verification line — "an undecryptable
credential or signing secret fails the attempt **without sending**, and the recorded
`error_summary` contains no part of the secret" — produces exactly the per-destination failed
attempt entries, uniformly across the fan-out, that the spec describes a member reading.

### Check 3 — the discard is stated before the save on Screen 1 / Flow B step 2

**Satisfied for the inbound verification surface.** Flow B step 2 branches on whether a rotation is
already running for this proxy's verification secret, and the second branch states, in member-facing
copy attached to the Replace path, that saving a new secret now stops the previous secret being
honoured immediately and that its 24 hours do not finish out. Screen 1's C5 note carries the same
branch. That is AC29's added bullet honoured where the member commits to the rotation, not
discovered afterwards on Show, which is what ruling 2a asked for.

**It is not satisfied on the signing surface, and that is correction B2 below.** AC29's added
bullet binds "a replacement **or a regeneration**", and regeneration is the signing word.

### Check 4 — the Q-10-03 answers invent no requirement PRD-10 does not carry

**Neither answer invents a requirement. Both stand.**

1. **The Remove credential control on Screen 3 is an affordance for a state PRD-10 already
   defines, not a new capability.** AC30 makes the credential **optional**; AC33 specifies a surface
   that shows **set / not set**; AC37 describes the behaviour of a destination that has no
   credential. "No credential" is therefore a state the approved requirements carry, and a control
   that returns a destination to it is design detail in the Designer's own authority. It changes no
   dispatch semantics beyond AC32 and AC37, introduces no permission (AC28 is untouched), and adds
   no rotation, overlap or previous-value state that AC29's exclusion of the credential forbids. The
   Designer's own ground — that the only route back was deleting the whole destination row, which
   also discards its URL, method and delivery-attempt history — is sound, and the asymmetry it
   removes against signing's explicit **Disable signing** is real.
2. **`Input type="password"` over `PasswordInput.vue` is a component choice and nothing more.** It
   asserts no requirement in either direction. Note that the ground the Designer now gives — one
   write-only idiom across the whole feature, rather than the absence of a precedent — is the
   honest one, and correcting a wrong ground in place while leaving the superseded claim visible as
   history is the right handling. The consequence for § Components is correction B1.

### Check 5 — nothing in the amendment contradicts `plan-10`

**No contradiction found.** `plan-10` is fully approved and was not reopened.

- Technical ruling 13 withholds precisely the surfaces this amendment redesigns — Screen 5's per-row
  badge and action, Screen 6's dialog, and Flows G, H and I — and builds the backend to the
  proxy-level ruling. The amendment lands on that boundary exactly.
- The plan's proxy-scoped **generate / disable / end-overlap** endpoints, with `store` serving as
  both Enable and Regenerate, match Flows G, H and I's action set with nothing left over and nothing
  missing.
- ADR-021 Decision 5 (disabling deletes every signing row; re-enabling generates a different
  secret) matches Flow I step 3's rule that a previous secret is never resurfaced or reused.
- AC29's cap of two, which Amendment B ruling 2 settles as a requirement, matches the plan's
  Technical ruling 14 and its R7 test; Screen 6 state 4's allowance of a second regeneration inside
  an overlap is the remedy AC29 itself names.
- **The Remove credential control does not conflict with the plan either.** `plan-10` § Out of
  Scope names the removal affordance, records that `design-10` designed none, and states that
  "whatever `Q-10-03` answers is additive". This approval therefore does not force the control into
  any milestone: **where it lands is the Principal Engineer's call**, and correction B3 exists so
  that the requirement it has to satisfy is stated rather than inferred.

### Four required corrections, returned to the Designer

**(B1) § Components restates the claim the amendment itself corrected.** The row
*"Verification/credential/signing secret entry | `Input type="password"` | **New usage** — no prior
`type="password"` precedent in this app"* contradicts the Screen 1 ruling added in this same pass,
which states that `PasswordInput.vue` does exist and that N3's no-precedent claim was wrong.
`resources/js/components/PasswordInput.vue` renders `Input` with `:type="showPassword ? 'text' :
'password'"`, so the precedent is real. Correct the row so it states the precedent exists and that
the plain `Input type="password"` is chosen deliberately — one write-only idiom across the whole
feature, per Screen 1 — rather than for want of a precedent. Do not restate the wrong claim
anywhere else; N3 itself stays as written, because it is history.

> **[Correction B2 — restated for one surface, 2026-08-28.]** This correction required the
> AC29 ruling-2a disclosure on **two** surfaces: the inbound verification surface (Screen 1,
> satisfied by Flow B step 2 and Screen 1's C5 note) and the signing surface (Screen 6 state
> 4, Flow H step 2, required by B2 below). **The inbound surface is withdrawn with Screen 1**
> — see `## Amendment — inbound verification withdrawn (2026-08-28)` at the end of this
> document, per `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-
> header-strip.md` (Accepted, Project Owner, 2026-08-28) § *Documents*: "Its signing half
> (Screen 6 state 4, Flow H step 2) stands. Its inbound half goes with Screen 1." **The
> requirement now has exactly one surface**, and B2's own text below, describing Screen 6
> state 4 and Flow H step 2 — unchanged in substance since this gate — is that surface's
> complete and sufficient discharge. **A review, or a task, that finds this disclosure on
> the signing surface alone and does not find a second, inbound surface carrying it must not
> treat that as incomplete.** No second surface exists for it to appear on: AC29's inbound
> half is withdrawn along with the surface it would have bound. This is stated explicitly
> because B2's own wording below — "on **both** surfaces" — and any downstream instruction
> written against it before this withdrawal (including a task requiring the disclosure be
> found on two surfaces before being called complete) predates ADR-026 and must be read
> against this restatement, not against its own now-superseded premise.

**(B2) AC29's ruling-2a disclosure is missing from the signing surface, where the amendment moved
that surface.** AC29's added bullet binds "the surface that begins a replacement **or a
regeneration** while a previous secret is still honoured", and ruling 2a says in terms that it
applies to the signing surface as well as to the inbound one. Today Screen 6 state 4 renders the
rotation line, then offers **Regenerate signing secret** in the footer with no member-facing
statement that clicking it discards the currently-honoured previous secret immediately; Flow H step
2 states only the ordinary case ("The previous secret is demoted, not discarded"), which is false
when an overlap is already running. The spec's only acknowledgement of the discard is the
parenthetical in Screen 6 state 4, which is prose addressed to the reader of this document, not
copy addressed to the member, and it therefore does not satisfy the bullet. Required:
- **Screen 6 state 4** carries member-facing copy, rendered as part of that state and therefore
  visible **before** the member clicks **Regenerate signing secret**, stating that regenerating now
  stops the currently-honoured previous secret being honoured immediately, that its 24 hours do not
  finish out, and — because the grain makes this larger than it was — that this applies to every
  destination of the proxy at once. Wording is yours; that it is said before the action commits is
  not.
- **Flow H step 2** branches on whether an overlap is already running, mirroring Flow B step 2's two
  branches, so the flow and the screen agree.
- **No confirmation step is added.** § Interactions' single-click rule for Enable / Regenerate /
  Disable stands; AC29's bullet asks for disclosure, not ceremony. Because a signing regeneration is
  an immediate action rather than a form save, "before the save" means before the action is invoked.

**(B3) Screen 3 must state that removing a credential is an explicit signal, never an empty
field.** § Interactions rules that "a present-but-empty secret field must not submit as 'clear the
secret'", and the new **Remove credential** control resets the row to unset in-session and takes
effect at save. The two rules now meet on the same field, and an implementer is left to reconcile
them. State on Screen 3 that **Remove credential** is a distinct, explicit removal the form carries
in its own right, and that a blank secret field never means removal under any circumstance. The
transport for that signal is the Principal Engineer's, not yours; the requirement that the two be
distinguishable is what this correction fixes. While landing it, add the missing **post-save** row
to Screen 3's states table — after a saved removal the row reads as an unconfigured one (trigger
back to "Add credential", header name back to `Authorization`) — so the table covers the removal
round-trip and not only the pre-save state.

**(B4) The amendment's § What changed table misattributes one edit.** Its § Interactions row claims
the "End overlap now" bullet's screen list was extended to include Screen 4b. § Interactions'
bullet carries no screen list — it reads "Neither 'End overlap now' action is gated behind a
confirmation dialog" — and the screen-list edit landed in § Accessibility instead. Fix it in either
direction: extend the § Interactions bullet to name Screens 4, 4b and 6 and reword "Neither" so it
cannot be read as "there are two surfaces", or correct the change table to name § Accessibility.
The change table is the amendment's own account of itself and is read as evidence long after this
gate.

### Three non-blocking notes

- **(NB1)** Screen 1's `standard-webhooks` help copy calls the inbound secret *"The signing secret
  your sender issued you for this integration"*. Under the old grain that phrase sat a page away
  from anything called a signing secret; it now sits one card away from Screen 4b's **Signing**
  card, where "signing secret" means the product's own. The copy is not wrong — it is the
  specification's term for the sender's secret — but "the secret your sender issued you for this
  integration" would remove the collision at no cost. Your call.
- **(NB2)** § Open Questions still records, as not designed here, whether a proxy's disabled signing
  secret is erased from storage or merely stops being applied. **ADR-021 Decision 5 answers it** —
  disabling deletes every signing row — and this amendment already lists ADR-021 as an input. The
  note remains harmless because it correctly says the difference is not user-facing; annotate it
  when the section is next touched.
- **(NB3)** Not the Designer's, mine: **PRD-10's Status block still carries the pre-approval bullet
  reading "`## Amendment B` — AWAITING PROJECT OWNER APPROVAL"**, which contradicts § Amendment B's
  own recorded approval of 2026-08-27 and the Status block's own opening line. That is a defect in
  the PRD, not in this spec, and it is corrected there rather than here.

### What this approval unblocks

**M8b — the outbound signing surface — is unblocked for Task Planning**, which was the only
milestone `plan-10` withheld pending this gate. **B2 must be applied before M8b is broken down**,
because it adds member-facing copy and a flow branch that a task has to carry; the other three
corrections do not gate M8b. B3 concerns Screen 3, which belongs to M7's territory, and `plan-10`
already treats the removal affordance as additive, so the Principal Engineer decides where it
lands. Nothing here reopens `plan-10`, PRD-10, or any ADR.

## Amendment — Screen 6 state 3's ordinary-branch disclosure (2026-08-28)

**What this settles.** An audit of task **T43**'s inherited implementation found that Screen 6
state 3 (the "enabled, no overlap" state a member sees before clicking **Regenerate signing
secret**) carried no member-facing copy at all — only the "Enabled — generated {date}." status
line. Correction **B2** (`## Approval record — amendment gate (2026-08-27)`) required member-facing
copy on Screen 6 state **4** — the branch where an overlap is already running — and it named only
that branch, because that is the branch PRD-10 AC29 ruling 2a's letter binds ("the surface that
begins a replacement or a regeneration **while a previous secret is still honoured**"). B2 did not
ask for the ordinary branch, and this design spec never added it: Flow H step 2's first bullet
described the ordinary case in behavioural prose only (what happens outbound), never as quoted
member-facing copy, unlike Flow B step 2's ordinary bullet on the inbound surface, which is quoted
copy throughout.

**The gap, and why it is a real one rather than a scope question.** Flow B step 4 states in terms
that Screen 1's step-2 ordinary-case copy is what connects a member's two available actions on the
inbound surface: "A compromised current secret is two steps, now connected by this copy: Replace it
here, then End overlap now on Show — without step 2's line, a member had no way to know the second
step existed." The same two-step shape exists on the signing surface — **Regenerate signing
secret**, then **End overlap now** — and until this amendment, nothing connected them there. A
member regenerating their signing secret for the first time was told nothing about the 24-hour
overlap starting or about **End overlap now** existing at all, which is the exact defect Flow B's
own reasoning describes for the surface it was written about. Task **T43**'s own Acceptance
Criteria already anticipated this: its second bullet reads "State 3 → Regenerate (no overlap yet)
shows the **ordinary** demote-not-discard copy, not the discard one" — a requirement the approved
spec gave the developer no wording to satisfy.

**Ruling: outcome 1 — Screen 6 state 3 gains the ordinary-case copy.** Screen 6 deliberately
carrying no ordinary branch (outcome 2) is rejected: it would leave T43's own AC2 uncloseable
exactly as the audit found, and it would leave the signing surface without the connective copy Flow
B's own approved reasoning treats as necessary on the inbound surface it was written for. The
signing surface's copy is scaled to what B2 already established for its discard branch — proxy-wide,
"for every destination this proxy has" — because the overlap this copy describes has no per-row
version to fall back on, unlike the inbound one Flow B step 2 was written about.

**Decision authority.** Wording is the Designer's under PRD-10 AC29 ruling 2a ("Wording and
placement are the Designer's"), which binds "a replacement or a regeneration" alike and is not
limited to the discard branch. This amendment adds no requirement, changes no acceptance criterion,
and reopens no ruling; it completes a disclosure the PRD, the amendment-gate correction, and T43's
own Acceptance Criteria already required, using authority already delegated to this role. It is
therefore landed the same way `plan-10`'s **Revision A** landed technical ruling 15 — purely
additive, self-contained under the role's own delegated authority, with no new Product Manager or
Project Owner ruling sought and none required.

**Date:** 2026-08-28.

**Author of this amendment:** Designer, in response to an audit finding on T43's inherited
implementation.

**Status of this amendment: self-certified by the Designer under the wording authority PRD-10 AC29
ruling 2a delegates.** It does not reopen `## Amendment — outbound signing re-grained to the proxy`,
its amendment gate, or either of the two design gates recorded in this document; both stand exactly
as written. If a downstream reviewer or the Product Manager wants this copy re-checked against the
PRD, that is additional scrutiny the amendment invites, not a gate it requires to take effect —
correction B2 already required this state to carry a disclosure, and this amendment supplies the
half of it B2's own text did not name.

### The exact copy, and where it renders

Screen 6 state 3's block now reads, in full:

```
p "Enabled — generated Aug 20, 2026."
p (help, same paragraph group as the footer actions) "Regenerating keeps your current
   secret working for the next 24 hours, for every destination this proxy has, so you
   don't need a coordinated cutover. To stop it early — for example if it's been
   leaked — use End overlap now, which appears here and on the Signing card once you
   regenerate."
```

The new line is a second `p`, in the same help-paragraph group as the footer actions that state 4's
existing disclosure already uses — directly below the "Enabled — generated {date}." status line and
above `DialogFooter`, so it renders before the member can reach **Regenerate signing secret**.

### What changed, section by section

| Section | Change |
|---|---|
| Screen 6, state 3 | **Added**: the ordinary-case help line above, in the same paragraph-group position state 4's existing disclosure already established |
| Flow H, step 2, first bullet | **Added**: a cross-reference to Screen 6 state 3's exact copy, mirroring how the second bullet already cross-references Screen 6 state 4 — the flow and the screen now agree, matching Flow B step 2's two-branch shape on the inbound surface |

**Not changed, and deliberately so:** Screen 6 state 4 and its existing discard-disclosure copy
(unchanged, still the copy correction B2 required); Flow H steps 1, 3 and 4; Flow I; every other
screen, flow and state in this document; PRD-10; `plan-10`; and both prior approval records, which
remain history and are not rewritten.

### For the Task Planner

**T43's AC2** — "State 3 → Regenerate (no overlap yet) shows the ordinary demote-not-discard copy,
not the discard one — the two states/copies must not be swapped or merged" — already anticipated
this copy and needs no wording change: the copy AC2 requires now exists, verbatim, at Screen 6 state
3 above. **No Task Planner correction is required.** T43's own completion notes are still `_pending_`
and should be closed against the copy this amendment adds, not against different wording.

## Amendment — inbound verification withdrawn (2026-08-28)

**Ruling amended:** the Project Owner's ruling of 2026-08-28, rendered in
`docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
(Accepted, Project Owner, 2026-08-28), Decision B: **inbound verification is removed from the
product in full** — no verification scheme, no verification secret, no verification header name,
no rejection path, no verification surface. The Owner's own words, quoted in ADR-026 § *The
product position this ADR renders*: "We are going to remove everything related to validating
incoming webhooks that we already added. Columns, code, etc. We are no longer validating when
ingesting, just fanning." ADR-026 § *Documents* routes the design consequence to the Designer,
through the Product Manager:

> Screen 1 (the proxy form's Verification section) and Screen 4 (the Show page's Verification
> card) are withdrawn in full.
>
> Flow A (configure a proxy's inbound verification), Flow B (replace a verification secret) and
> Flow C (view verification status and end a rotation overlap early) are withdrawn in full.
>
> Screens 2, 3, 4b, 5, 6 and 7 and Flows D, E, F, G, H and I are unaffected. Screen 3's credential
> subsection cites Screen 1 as its shape precedent and needs a new reference; the behaviour it
> describes does not change.
>
> The design gate's correction B2, which required the AC29 ruling-2a disclosure on both
> surfaces, now has one surface. Its signing half (Screen 6 state 4, Flow H step 2) stands. Its
> inbound half goes with Screen 1.

This amendment renders exactly that, and nothing more. It also carries one factual correction
found in the same audit that produced ADR-026: Flow G step 5's outbound signing header names,
which were stale against ADR-025 Decision 2.

**Date:** 2026-08-28.

**Author of this amendment:** Designer, in response to ADR-026 § *Documents*.

**Status of this amendment: WRITTEN, awaiting Product Manager re-approval** — the same delegated
design gate the two amendments above went through (`CLAUDE.md`: "design → product-manager").
**The original design gate's record and the amendment gate's record above are both retained
exactly as written and are not rewritten by this amendment** — they record what each of those
gates considered, and neither considered this withdrawal, per `docs/standards/documentation.md`
(retain history; never rewrite a ruling silently). **Where this amendment and the spec body
conflict, this amendment governs**, the same rule the first amendment's own header set for
itself.

### What changed, section by section

| Section | Change |
|---|---|
| Screen 1 | **WITHDRAWN in full.** Marked in place at its heading, dated 2026-08-28, naming ADR-026 Decision B as authority. Inbound verification has no live surface; nothing in Screen 1 is to be built. Retained below, unedited, as the record of what the design gate at § *Approval record (design gate)* approved and corrected (C1, C5). |
| Screen 4 | **WITHDRAWN in full**, marked in place at its heading, dated 2026-08-28, same authority. Retained below, unedited. |
| Flow A | **WITHDRAWN in full**, marked in place at its heading, dated 2026-08-28. |
| Flow B | **WITHDRAWN in full**, marked in place at its heading, dated 2026-08-28. Retained below, unedited, including correction C5 and the AC29 ruling-2a disclosure its step 2 added — both are history now, not a live requirement. |
| Flow C | **WITHDRAWN in full**, marked in place at its heading, dated 2026-08-28. |
| Screen 3 | Two references to Screen 1 as the write-only secret field's shape precedent — the `CollapsibleContent` block's bracketed note and the "No rotation language anywhere in this block" paragraph's shape comparison — are re-pointed to a self-contained statement of the same shape. **The behaviour Screen 3 describes does not change**, exactly as ADR-026 states; only the cross-reference moves. See § *Screen 3's re-pointed reference* below. |
| Correction B2 (`## Approval record — amendment gate`) | Gains a pure-insertion pointer immediately above it, restating that the disclosure this correction required on two surfaces now has one. See § *Correction B2, restated for one surface* below. |
| Flow G step 5 | Corrected: the copy read "carries the Standard Webhooks signature headers"; the header **names** are this product's own (`WebhookProxy-Id`, `WebhookProxy-Timestamp`, `WebhookProxy-Signature`, per ADR-025 Decision 2, standing under ADR-026) and only the **value format** remains the Standard Webhooks one. See § *The Flow G step 5 correction* below. |

**Not changed, and deliberately so:** Screens 2, 4b, 5, 6 and 7, and Flows D, E, F, G (beyond the
one line above), H and I, per ADR-026 § *Documents*: "unaffected." Every AC12–AC22, AC30–AC39 and
AC54–AC64 UI consequence stands exactly as the amendment gate above left it. **Screen 6 state 4
and Flow H step 2 — the signing half of correction B2 — are untouched in substance**; only their
status as the *sole* surviving surface for AC29's ruling-2a disclosure is newly true, and that is
recorded at the B2 pointer, not by editing their own text. Lines 534–548, the inbound copy listing
the Standard Webhooks header names a sender must send, are **not** edited by this amendment — they
sit inside Screen 1, which is withdrawn as a whole, so no line-level rename inside it is warranted;
an audit that attributed a rename to those lines was mistaken, and this amendment does not repeat
that mistake.

### Why Screens 1 and 4 and Flows A, B and C are marked withdrawn rather than deleted

`docs/standards/documentation.md` and this document's own established practice — the original
design gate's ten corrections, the amendment gate's four, and `plan-10`'s `## Revision A` — all
retain history rather than rewrite it, so that an approval record stays readable as evidence of
what was actually considered and approved at the time. Deleting Screen 1, Screen 4, Flow A, Flow B
and Flow C outright would make § *Approval record (design gate)*'s coverage trace, its ten
corrections (C1, C2 and C5 in particular, which are about these surfaces) and its carried-forward
items unreadable against a spec that no longer contains what they describe. Marking each withdrawn
in place, dated, with the authority named, preserves that readability while making unmistakably
clear that none of it is to be built. This mirrors ADR-026's own house style for the ADRs it
partially supersedes — a pure-insertion pointer at the affected passage, the source document
otherwise untouched.

### Screen 3's re-pointed reference

ADR-026 names this specifically: "Screen 3's credential subsection cites Screen 1 as its shape
precedent and needs a new reference; the behaviour it describes does not change." Two passages in
Screen 3 cited Screen 1 by name for the shape of its write-only secret field:

- The `CollapsibleContent` block's bracketed note, which read "[same write-only shape as Screen
  1's secret field, applied to two fields:]". It now states the shape directly — unset: a plain,
  never-pre-filled `Input type="password"`; set: a collapsed status line plus a **Replace**
  control; a present-but-empty field never submits as "clear the secret" — with a dated
  parenthetical pointing here for the full history.
- The "No rotation language anywhere in this block" paragraph's closing comparison, which read
  "the write-only pattern here is identical in *shape* to Screen 1's but different in
  *consequence*". It now names the shape itself (unset: plain input; set: collapsed status +
  Replace) rather than pointing at withdrawn material, with the same dated parenthetical.

Both are the same shape Screen 1 originated and Screen 3 already rendered concretely in its own
`CollapsibleContent` block (`Input type="password"` unset, `"Credential set — changed {date}"` +
Replace + Remove credential set); only the cross-reference moves from a now-withdrawn screen to a
self-contained statement. **The behaviour is unchanged**, exactly as ADR-026 states.

### Correction B2, restated for one surface

`## Approval record — amendment gate (2026-08-27)` correction **B2** required the AC29 ruling-2a
disclosure — that a replacement or a regeneration started while a previous secret is already
honoured says so, before the action, in member-facing copy — on **two** surfaces: the inbound
verification surface (Screen 1, satisfied by Flow B step 2 and Screen 1's C5 note) and the signing
surface (Screen 6 state 4, Flow H step 2, which B2 itself required and which this spec already
carries). **The inbound surface is withdrawn with Screen 1.** ADR-026 § *Documents* states this
plainly: "Its signing half (Screen 6 state 4, Flow H step 2) stands. Its inbound half goes with
Screen 1."

**The requirement now has exactly one surface**, and Screen 6 state 4 together with Flow H step 2
— unchanged since the amendment gate, per the table above — is its complete and sufficient
discharge. **A review, or a task, that finds this disclosure on the signing surface alone and does
not find it on a second, inbound surface must not treat that as incomplete or as a regression.**
There is no second surface for it to appear on; none exists for AC29's inbound half to bind,
because AC29's inbound half is withdrawn with it. This is stated explicitly, at this length,
because the original correction's own wording — "on **both** surfaces" — and any downstream
instruction written against it before this withdrawal (including a task requiring the disclosure
be found on two surfaces before being treated as complete) predates this amendment and must be
read against this restatement, not against its own now-superseded premise. The pure-insertion
pointer placed directly above correction B2 in `## Approval record — amendment gate (2026-08-27)`
carries this same restatement in short form, at the point a reader is most likely to meet it.

### The Flow G step 5 correction

Flow G step 5 read: "every dispatch to **every destination this proxy has** — original, retry, and
replay alike — carries the Standard Webhooks signature headers under this one secret." This
conflates the specification the *value format* still follows with the *header names*, which
ADR-025 Decision 2 renamed to `WebhookProxy-Id`, `WebhookProxy-Timestamp` and
`WebhookProxy-Signature` before this amendment, and which ADR-026 confirms stands unchanged. It now
reads: "... carries the `WebhookProxy-Id`, `WebhookProxy-Timestamp` and `WebhookProxy-Signature`
headers, in the Standard Webhooks value format, under this one secret." No other word in Flow G
changes. This correction was found in the same audit that produced ADR-026, and ADR-026 itself
notes the audit mis-cited its location as design-10 lines 534–548 (Screen 1's inbound copy, which
lists the header names a *sender* must send under `standard-webhooks` and is being withdrawn
regardless); the actual passage is Flow G step 5, corrected here, and lines 534–548 are left
untouched — see the note under § *What changed, section by section* above.

### Handoff for this amendment

**Next Agent: Product Manager**, to re-approve this amendment against ADR-026 (design gate,
delegated per `CLAUDE.md`) — the same gate the two amendments above went through. **Outstanding
Questions:** none; this amendment answers no open question and raises none — it renders ADR-026's
routing instruction directly, with no requirement gap and no feasibility doubt. On re-approval,
nothing further is owed to the Principal Engineer beyond the screens and flows this amendment
withdraws or corrects; `plan-10` and `docs/tasks/sensitive-data-handling-tasks.md` are ADR-026's
own concern, ruled there and not by this amendment.
