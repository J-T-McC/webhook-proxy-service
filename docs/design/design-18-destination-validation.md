# Design Spec: Destination validation

- **Status:** Draft — pending Product Manager approval.
- **Author:** Designer
- **PRD:** `docs/product/prd-18-destination-validation.md` (**Approved** by the Project
  Owner, 2026-08-31)
- **Approved by / date:** Pending.

> **Scope note.** #18 adds **one new public page** (the confirmation page, seen by the
> destination operator, no account) and extends **three existing surfaces**: **(1)** the
> destination create/edit form (`DestinationRows.vue`, inside `ProxyForm.vue`) gains a
> read-only validation status per existing row and a before-you-save URL-change warning;
> **(2)** the proxy Show page's Destinations table (`DestinationsCard.vue`) gains a
> **Validation** column carrying the state, the last-send outcome, and the Validate
> action; **(3)** the proxy Show page's header gains a compact count badge when the
> proxy has destinations that are not Validated (AC33). **No other surface changes.**
> The proxies Index page is unchanged — it shows no destination detail today and #18
> adds none to it, stated explicitly per the responsibility to cover every surface
> including "not shown here."

## Overview
A team member adding or editing a destination sees nothing new to configure — validation
is not a setting, it is a consequence. The moment a destination is saved with a URL, the
product sends a validation challenge to that URL by itself, and the destination's row
everywhere it appears — the create/edit form, the proxy's Destinations table — carries
one of four plain-language states: **Unvalidated**, **Pending**, **Expired**, or
**Validated**, each stating what happens next and whose move it is. A **Validate**
button sits with every non-Validated destination, available any time except while a rate
limit is cooling down, in which case the button gives way to the reason and the exact
time it clears. Editing a destination's URL warns, before the member saves, that doing so
returns it to Unvalidated and cancels any outstanding link. A proxy with any destination
that is not Validated says so plainly near the top of its Show page, because a proxy
quietly skipping one of three destinations looks healthy everywhere else. The single
highest-stakes screen in this feature is not inside the product at all: the destination
operator — a stranger with no account, arriving cold from whatever channel carried the
challenge — lands on a plain, minimal, unbranded-beyond-the-team-name confirmation page
that states exactly what is being asked and what happens if they agree, and asks for one
deliberate click. That page has no idea who it is talking to and is written for someone
who has never heard of this product.

## Decisions carried forward from the UX Direction (not re-litigated here)
Restated so this spec's choices read as consequences, not inventions. The PRD's
`## UX Direction` names these as **not the Designer's to decide**, and this spec honours
every one of them without exception:
- The validation link is never displayed anywhere inside the product, to any member, ever
  (AC24). No screen in this spec renders it, logs it visibly, or offers a "copy link"
  affordance for a member to use.
- Opening the link approves nothing; a deliberate, explicit confirmation submitted from
  the confirmation page is the only route to Validated (AC25).
- Approving requires no account and no team membership (AC26).
- A challenge is dispatched automatically on create and on URL change, with no separate
  "send now" step required for the ordinary path (AC15).
- URL edits are permitted, never blocked — the member is warned of the cost, not stopped
  (AC5).
- Validation state is visible to every member who can view the destination, not only its
  creator (AC31).
- There is no manual override of validation state anywhere, by anyone — no revoke, no
  admin force-validate, no re-validation timer (AC3, AC6).

## Scope boundaries (confirmed, not designed here)
Restated so this spec reads as complete against every AC, not only the UI-bearing ones:
- **AC1, AC4, AC7 — the state machine itself.** Which four states exist, how Expired is
  derived, and which actions leave state untouched are backend facts with no additional
  surface beyond the visible treatment this spec gives each state (Screens 1–2).
- **AC3, AC6, AC38 — no override surface exists, anywhere, by design.** There is no
  "Mark validated" control for an Owner/Admin, no "Revoke" control on a Validated
  destination, and no expiry or re-validation timer shown for one. Their absence is
  deliberate and this spec adds none of them.
- **AC8, AC9, AC10, AC11, AC12 — enforcement is a property of the delivery path, not the
  interface.** Nothing here renders a "this event was skipped" record, because AC11
  creates none to render. A replay attempted against a non-Validated destination is
  unavailable with the reason given, in the same manner `ReplayDialog.vue` already
  disables/limits action per destination; the exact mechanics of that treatment are the
  Principal Engineer's (Q-18-01 already names the enforcement points as technical). This
  spec fixes only that the reason shown names validation, not a generic failure.
- **AC13 — configuration is not gated.** No control in this spec is disabled, hidden, or
  behaves differently because a destination is not Validated. A non-Validated
  destination's URL, method, and credential remain fully editable, and the destination
  remains fully deletable, through the exact same controls a Validated one has.
- **AC17, AC18, AC19, AC20 — the challenge payload, redirect policy, and address
  refusal.** These are outbound request properties the member never sees a control for.
  Their one surfacing point is the failed-send reason text (Screens 1–2, AC35), and
  that text never names an internal mechanism (no "SSRF", no "loopback address" jargon) —
  see Screens & States for the exact copy.
- **AC21 — rate limiting.** No new settings screen; the three limiters are fixed defaults
  with no member-facing configuration. Their only surface is the Validate action's
  unavailable state (Screens 1–2).
- **AC22, AC23 — link lifetime and log exclusion.** No UI renders the token or the raw
  link at any point, on any screen, in this spec, including the confirmation page (which
  renders only what the link resolved to, never the link's own value).
- **AC30 — the grandfathering migration.** A one-time, invisible data migration. No
  screen distinguishes a grandfathered Validated destination from one validated through
  the ordinary flow — Validated reads identically either way, per AC30's own intent that
  the exemption not set a visible precedent.
- **AC36 — validation sends during a paused proxy.** No pause-specific copy anywhere in
  this spec. The Validate action and the destination's state read identically whether the
  proxy is paused or not, because AC36 makes them unrelated facts; a paused proxy's
  existing "Paused since" badge and a destination's validation badge coexist without
  either qualifying the other.
- **AC37, AC39, AC43 — no auto-confirm, no per-destination pause, no bulk anything.**
  Confirmed absent. Adding several destinations at once sends several separate
  challenges and shows several separate rows, each with its own state and its own
  Validate action — there is no "Validate all."
- **AC40 — no address-rule change to ordinary delivery.** Nothing in this spec implies
  ordinary dispatch is newly restricted; the address refusal is named only as a possible
  validation-send failure reason (AC20, AC35).
- **AC41, AC42 — no notifications, no analytics.** No banner, badge, or count appears
  outside the surfaces this spec names, and none of them is a notification: they render
  only while the member is looking at the proxy or the destination, exactly as today's
  "Paused since" badge does. No approval-rate, time-to-validate, or other new #11 measure
  is added anywhere.
- **AC44 — no new permission.** Every mutating control this spec adds (Validate, and the
  confirmation page's Approve action, which is not permission-gated at all per AC26) is
  reached through the existing destination-update permission or through no permission at
  all — never a new one.
- **AC45 — nothing here depends on #8, #9, #12, #13 or #14.** No screen in this spec
  assumes payload mapping, multi-format ingestion, change detection, notifications, or
  test payloads.

## User Flows

### Flow A — Create a proxy with one or more destinations
*(User story: "add a destination and have it ask for approval by itself, so setting one
up is one step and not two.")*
1. Member fills in **New proxy** as today, including one or more destination rows
   (`DestinationRows.vue`). The Destinations fieldset carries one new help line (Screen 1)
   stating that each destination must be approved by whoever runs it before it receives
   anything, and that this happens automatically on save.
2. Member submits. The server creates the proxy and its destinations and, for each,
   attempts a validation send (AC15) before redirecting to the proxy's **Show** page.
3. On **Show**, each destination's row in the Destinations table (Screen 2) reads
   **Pending** if its automatic send succeeded, or **Unvalidated** with a stated reason
   if it did not (rate limit or a connection failure, AC15/AC18) — there is no separate
   toast or flash message; the row's own state area is the one place this is ever said,
   whether the send was automatic or later triggered by Validate.
4. Because at least one destination is not yet Validated, the proxy's header carries the
   count badge (Screen 3) and no traffic reaches any of those destinations (AC8) until
   each is approved.

### Flow B — Edit an existing destination's URL
*(User stories: "correct a mistyped destination URL by editing it"; "editing a URL must
tell the member what it costs before they save.")*
1. Member opens **Edit** on a proxy with an existing, Validated destination. The
   Destinations fieldset (Screen 1) shows that row's current validation status, read-only,
   next to its URL and Method fields.
2. Member changes the URL field's value. The instant it differs from the value the row
   was loaded with, a warning appears under that row (Screen 1, State: URL dirtied) —
   its wording depends on the row's current state, because the real cost differs: a
   Validated destination is about to stop receiving traffic; a Pending one is about to
   have its outstanding link cancelled; an Unvalidated or Expired one has nothing live to
   lose.
3. Member saves the whole form. The server returns this destination to Unvalidated,
   voids whatever challenge was outstanding, and attempts a fresh validation send to the
   new URL (AC5, AC15) — this destination's row now reads exactly as a freshly created
   one would (Flow A step 3).
4. **Reverts the URL before saving:** typing the original value back removes the warning;
   nothing about the destination's state changes, because nothing is sent until save.

### Flow C — Send (or resend) a validation challenge with Validate
*(User story: "an explicit Validate action is available whenever the destination is not
Validated" — AC14; "Validate is a button, not a wizard" — UX Direction point 7.)*
1. From the proxy's **Show** page, the member finds a non-Validated destination's row in
   the Destinations table (Screen 2) and clicks **Validate**.
2. The request is sent immediately — no dialog, no confirmation step. If the destination
   was Pending, this click voids the still-outstanding link (AC16); the button's own
   caption already said this permanently while Pending (Screen 2), so nothing new needs
   confirming at the moment of the click.
3. On success, the row updates in place to **Pending**, with the fresh challenge's sent
   time and expiry shown (Screen 2). On a send failure (AC18), the row stays or returns to
   **Unvalidated** with the specific reason shown (Screen 2, State: send failed).
4. **Already Validated:** no Validate button exists on this row at all — there is nothing
   to send and no control that would do anything if clicked (AC3, AC6).

### Flow D — Validate is unavailable because of a rate limit
*(User story: "a member who hits [a rate limit] needs to be told when they can try
again, not given a dead button.")*
1. Member clicks **Validate** (or triggers an automatic send via Flow A/B) while a limit
   applies to this destination or this team (AC21).
2. The row shows no clickable Validate control. In its place, a muted line names which
   limit was reached in plain language and the exact time it clears (Screen 2, State:
   rate-limited) — never a disabled button with no explanation, and never a silent no-op.
3. Once the stated time passes, the next view of this page (or the row's own state after
   a background refresh, if the Principal Engineer's plan includes one — not assumed
   here) shows the Validate button restored.

### Flow E — A validation challenge goes unanswered until it expires
*(User stories: "see plainly that my destination is waiting on somebody to approve it";
"Expired means nobody acted in time and a fresh challenge is needed.")*
1. A destination sits **Pending** past its challenge's 7-day lifetime (AC22) with no
   approval.
2. Its row now reads **Expired** (Screen 2) — stated as an ordinary outcome, not an
   error: the challenge reached the destination (that is how it became Pending in the
   first place), nobody acted in time, and a fresh challenge is the only remedy (AC4,
   AC22).
3. Member clicks **Validate** (Flow C) to send a new one; there is no "extend" or
   "resend the same link" concept (AC22) — a fresh send is the only route back to
   Pending.

### Flow F — The destination operator opens the link and approves
*(User stories: "asked before a stranger's proxy starts posting to my URL"; "the
approval link... require[s] a deliberate confirmation.")*
1. Somebody at the destination — who has no account, no team membership, and no other
   contact with the product (AC26) — receives the challenge (by whatever channel their
   own systems surface it) and opens the link in a browser.
2. They land on the confirmation page's **Review** state (Screen 4), which states the
   team's name, the exact destination URL being approved, and what approving causes —
   nothing else (AC27). Opening the link changes nothing (AC25).
3. They click the page's single action, **Approve this destination** — a real form
   submission (POST), never a link or an auto-triggered action (AC25).
4. The destination becomes Validated immediately, and the confirmation page's **Approved**
   state (Screen 4) confirms it plainly. The proxy begins delivering to it from the next
   event onward — never an event that arrived earlier (AC29, AC10).
5. **Never opens the link at all:** nothing happens; the destination stays Pending until
   the challenge expires (Flow E) or a member intervenes.

### Flow G — The destination operator opens a link that no longer works
*(User story: "opening the link... approve[s] nothing... a spent, voided or expired link
... is not presented as an error the holder caused.")*
1. Somebody opens a link whose challenge has already been approved, superseded by a
   newer send, cancelled by a URL edit, or has expired — or a link that does not match
   any challenge the product recognises at all (mistyped, truncated, tampered).
2. They land on one of three terminal states instead of Review — **Already approved**,
   **Link expired**, or **Link not valid** (Screen 4) — never a generic error page, and
   never told they did anything wrong.
3. Each terminal state ends the interaction with no further action available and no
   account to return to; the copy says so.

### Flow H — A member notices a proxy is not delivering to everything
*(User stories: "see plainly that my destination is waiting on somebody"; "the proxy
surface must not let an unvalidated destination hide.")*
1. Member opens a proxy's **Show** page. If any of its destinations is not Validated, a
   count badge sits in the page header alongside the existing Mode/Processing/Paused
   badges (Screen 3).
2. Scrolling to the Destinations table (Screen 2), each affected row's own state and
   caption explain exactly why: waiting on a person (Pending), waiting on a fresh send
   (Expired), or never having received one that reached its host (Unvalidated).
3. Member acts from there — clicking Validate on a row, or fixing a URL that clearly
   never reached anyone (Edit, Flow B) — without ever having had to infer a validation
   problem from a delivery figure that looks merely quiet.

## Screens & States

### Screen 1 — Destination form (Create / Edit) — validation status and URL-change warning (extends `DestinationRows.vue`)

**Placement.** Inside each destination row's existing bordered block, between the
URL/Method/Remove line and the Credential collapsible — validation is read about the row
before its credential is configured, mirroring the order a member reasons in ("is this
even going to receive traffic" before "what does it authenticate with").

**Fieldset-level help line (new, always visible, once per Destinations section, not per
row):**
```
p (help) "The webhook is delivered to every destination below."          (existing)
p (help) "Each one must be approved by whoever runs it before it receives
   anything — we send a validation link to its URL automatically when you save."
```

**Per-row validation status — existing rows only.** A brand-new row added this session
(`row.id` absent) shows nothing here: nothing has been saved yet for AC15 to have acted
on. An existing row shows a compact, read-only line — no input, no control other than the
warning it may carry:
```
div.flex.items-center.gap-2
  Badge (state — see Screen 2's state table for variant/icon/label, shared exactly)
  span.text-sm.text-muted-foreground  {{ state caption — see Screen 2 }}
```
This is **read-only display, not a Validate button** — Validate is reached only from the
Show page's Destinations table (Screen 2). The two places would otherwise show the same
control twice while editing an unrelated field (URL, Method, Credential) is in progress
and unsaved, which risks a member reading a Validate click as part of the draft they are
about to discard on Cancel. Signing's own equivalent action (`SigningCard.vue`'s
**Manage signing** / **End overlap now**) lives on the Show page for the same reason —
this spec follows that precedent rather than inventing a second one.

**URL-change warning — appears the instant the row's URL field differs from the value it
was loaded with, disappears the instant it matches again.** Wording depends on the row's
*currently persisted* state (not on anything already changed elsewhere in the form this
session):
| Current state | Warning shown under the row |
|---|---|
| Validated | Alert (default variant, `Info` icon): "Saving this new URL stops delivery to this destination — it returns to Unvalidated and must be approved again at the new address before events resume." |
| Pending | Alert (default variant, `Info` icon): "Saving this new URL cancels the link already sent to the current address. A new one goes to the new address instead." |
| Unvalidated / Expired | Plain muted line, no Alert box: "This destination will need to be validated at the new address after you save." |
| New row this session | none — nothing exists yet to warn about. |
A Validated row's warning is styled with the full `Alert` treatment (bordered box) because
it is the one case with a live consequence; the other two are a single muted sentence,
matching this app's existing convention of reserving the bordered `Alert` for the
Verification/Signing overlap disclosures (design-10 Screens 1/6) and using plain help text
for lower-stakes notices elsewhere on this form.

**Accessibility of the warning:** the warning `div` carries an `id`, and the URL input's
`aria-describedby` includes that id whenever the warning is showing (in addition to any
existing error id) — a screen-reader user tabbing into the URL field hears the consequence
before typing, not only sighted users reading the line that appears beneath it.

### Screen 2 — Destination row on the proxy Show page (extends `DestinationsCard.vue`)

**Placement.** A new **Validation** column, between the existing **Destination** column
and **Delivered %** — validation is a precondition for the delivery figures beside it to
mean anything, so it reads first.

```
TableHead "Validation"   (new, inserted after Destination, before Delivered %)
...
TableCell
  div.flex.flex-col.gap-1
    div.flex.items-center.gap-2
      Badge (icon + label — state table below)
    p.text-xs.text-muted-foreground  {{ state caption }}
    Button v-if="showsValidateAction" variant="ghost" size="sm" @click="validate(destination)"
      Validate
    p.text-xs.text-muted-foreground v-else-if="rateLimited"  {{ rate-limit caption }}
```

**The four states — badge, caption, and action, exactly as shown, reused verbatim on
Screen 1:**

| State | Badge (variant / icon / label) | Caption | Validate action |
|---|---|---|---|
| Unvalidated, never sent | `outline` / `Circle` / "Unvalidated" | "No challenge sent yet." | Button, enabled |
| Unvalidated, last send failed | `outline` / `Circle` / "Unvalidated" | "Last send failed — {reason}." | Button, enabled (unless rate-limited — see below) |
| Pending | `waiting` / `Clock` / "Pending" | "Responded {http_status}. Waiting for someone at this address to approve — expires {expires_at}." | Button, enabled; caption below it always reads: "Sending again cancels the current link." |
| Expired | `outline` / `History` / "Expired" | "Expired {expires_at} — nobody approved in time. Send a new one." | Button, enabled |
| Validated | `moved` / `Check` / "Validated" | "Approved {approved_at}. Receiving events." | **No button at all** — nothing to send, nothing to undo (AC3, AC6). |

**Captions shortened by Owner ruling, 2026-09-01.** The wording above replaces a
longer set that ran to three and four lines inside a table cell and drove rows to
roughly 310px. AC34 reserves wording and presentation to the Designer and freezes
only the obligation — *"that each state carries this is not"* — and the Owner had
already dropped the Designer gate for this item, so the copy was theirs to cut. Every
fact each criterion requires survives: Pending still names who must act and by when
(AC34) and the status the destination returned (AC35); a failed send still names its
reason (AC35); Expired still says nobody approved in time and what to do next;
Validated still says it is receiving events. What went was the restatement of what the
badge beside the caption already says, and the send timestamp, which told a member
nothing the expiry did not. `{sent_at}` is consequently no longer interpolated into
any caption.

**Failure-reason copy (AC18, AC20, AC35) — plain language, never implementation jargon:**
- Connection could not be made at all (DNS failure, refused connection, timeout):
  "could not reach this address"
- Refused before sending, per AC20 (loopback/private/link-local/cloud-metadata address):
  "this address can't be used for validation" — never named as an internal-address rule;
  the member's remedy either way is the same (fix the URL), so the copy does not
  distinguish the reason beyond this.
- A redirect was returned (AC19 — not followed): "this address redirected elsewhere,
  which validation doesn't follow"

**Rate-limited state (AC21, Flow D) — replaces the Validate button entirely, never a
disabled button with no text:**
```
p.text-xs.text-muted-foreground
  "Try again {reset_time} — {limit description} was reached."
```
`{limit description}` is one of three fixed strings, in the order the limiters are
checked: "the once-per-5-minutes limit for this destination", "today's send limit for
this destination", "today's send limit for this team". Whichever limiter is tightest at
the moment governs which line shows — only one line ever renders, never a stacked list of
all three limits' status.

**Last-send outcome is folded into the state caption, not a separate line (UX Direction
point 3).** A destination cannot be Pending or Expired without its most recent send having
reached the host successfully (AC2) — so "arrived, nobody's opened it" only ever needs
saying for Pending/Expired, and "never arrived" only ever needs saying for Unvalidated
after a failed attempt. The table above already encodes this; no destination state ever
needs both phrasings shown together.

**Empty state.** A proxy with zero destinations cannot occur (`DestinationRows.vue`
enforces at least one row); nothing further to design here.

**Loading/busy state.** Clicking Validate disables that row's button and shows a `Spinner`
inside it (matching `SigningCard.vue`'s `End overlap now` busy treatment) until the
request settles; the row does not disable any of its other controls (Credential, delete)
while a validation send is in flight — sending a challenge and editing configuration are
unrelated actions and neither should block the other.

**Permission gating.** The Validate button is gated on the same `canUpdate` computed
Screen 3 (of design-10) already established for this page's other mutating controls —
`permissions.canUpdateProxy && (proxy.is_creator || permissions.canUpdateAnyProxy)`. A
member without update rights sees the same badge and caption, with no button — read-only,
never a control that would 403 if clicked (AC44, reusing the existing destination-update
permission, no new one).

### Screen 3 — Proxy Show page — not-all-validated indicator (extends `Show.vue`)

**Placement.** One new `Badge` in the page's existing header badge row (`Name` · `Mode` ·
`Processing` · `Paused since`), rendered only when at least one of the proxy's
destinations is not Validated (AC33):
```
Badge v-if="unvalidatedCount > 0" variant="waiting"
  Clock (icon)
  {{ unvalidatedCount }} destination{{ unvalidatedCount === 1 ? '' : 's' }} not yet validated
```
**Wording rule:** never "skipped" and never "failing" — those read as delivery or pause
language, which AC32 requires this stay distinct from. "Not yet validated" is the same
phrase every row already uses, so the header badge and the table row it summarises never
disagree in vocabulary. The count covers Unvalidated, Pending, and Expired together — all
three are "not Validated," and the header badge's job is to say *that traffic is
incomplete*, not to break down which of the three reasons applies; the Destinations table
(Screen 2) is where a member reads which destination and why.

**No separate banner or Alert box duplicates this on the page.** The Destinations table
sits a short scroll below the header on every proxy this page renders (it is one of the
page's standing cards, always present), and each affected row already carries its own
full explanation and its own Validate action — a second, page-level Alert repeating the
same fact in prose would either go stale independently of the table or exactly restate it.
One glanceable badge at the top, one detailed, actionable row per destination below: no
third copy of the same fact.

**Coexistence with Paused.** A paused proxy and a not-all-validated proxy show both
badges side by side when both are true — they describe different things (traffic held vs.
permission incomplete) and neither implies the other, exactly as AC36 states they are
unrelated facts.

**All destinations Validated:** the badge does not render at all — no "All destinations
validated" positive badge is added, matching this app's existing convention of a header
badge appearing only to flag something needing attention (mirrors "Paused since" appearing
only when paused, never a "Running" badge when not).

### Screen 4 — The validation link confirmation page (new; public, no account, no navigation)

**Layout.** Reuses `AuthLayout.vue` → `AuthSimpleLayout.vue` verbatim — the same centred,
logo-topped, single-card shell already used for Login/Forgot password/Reset password. No
modification to the layout component itself. This is a deliberate reuse, not a new visual
language: the destination operator has never seen this product, and the plainest, most
familiar-feeling shell this app already has is the right one, not a bespoke marketing page
that would read as more elaborate — and more phishing-shaped — than the moment calls for
(UX Direction point 5: "it must not look like a phishing page").

**What every state on this page discloses, and nothing more (AC27) — stated once, holds
for all five states below:** the team's name; for the Review state only, the exact
destination URL and what approving causes. **Never shown, on any state:** any member's
identity, any team membership fact, any other destination the team has, any proxy
configuration, any event content, or the validation link/token itself.

**State 1 — Review (initial landing on a live, unspent, unexpired link; GET only, changes
nothing — AC25):**
```
title: "Approve this destination?"
description: "{TeamName} uses this service to relay webhook events to the address
   below. Approving lets it start receiving traffic."

dl
  dt "Team"       / dd {{ TeamName }}
  dt "Destination" / dd {{ destination_url }}   (font-mono, break-all — matches
                                                   DestinationsCard's own URL treatment)

p "If you approve, this address starts receiving webhook traffic from {TeamName}
   immediately. If you don't recognise this team or this address, you can safely
   ignore this page — nothing happens unless you click Approve below."

Form method="post"
  Button type="submit"  "Approve this destination"
```
There is no secondary "Decline" or "Reject" control. The PRD names no such action (AC25
covers only approval and inaction), and ignoring the page already achieves the same
outcome the challenge expiring would; inventing a decline path here would be adding a
control no user story or acceptance criterion asks for.

**State 2 — Approved (this submission just succeeded):**
```
title: "Destination approved"
description: "This address now receives webhook traffic from {TeamName}. You can
   close this page — nothing further is needed here."
```
No further action, no link elsewhere in the product (there is nowhere for this visitor to
go — they have no account).

**State 3 — Already approved (the link's challenge was already spent by an earlier,
successful approval):**
```
title: "Already approved"
description: "This destination was already approved and is receiving traffic from
   {TeamName}. There's nothing more to do."
```
Framed identically in tone to State 2 — a reassurance, never an error (AC28: "not
presented as an error the holder caused").

**State 4 — Link expired (the challenge's 7-day lifetime elapsed, *or* this link was
voided by a newer challenge or a URL edit — AC5, AC16, AC22):**
```
title: "This link has expired"
description: "This validation link is no longer active. If {TeamName} still needs
   this destination approved, ask them to send a new one."
```
**Designer ruling:** "expired" and "voided" render as the same screen with the same
copy. To the person holding the link, both mean exactly the same thing — this link no
longer works, and the remedy is identical (ask the team to send a fresh one) — and
distinguishing them would either disclose that *something else happened* on a proxy this
visitor has no relationship to, or require inventing a difference in blame ("expired" vs.
"cancelled") that AC28 explicitly rules out ("not... the holder's error"). This uses one
of this screen's four required outcome states for both cases rather than adding a fifth.

**State 5 — Link not valid (the token does not resolve to any challenge the product
recognises — mistyped, truncated, tampered, or simply never existed):**
```
title: "Link not valid"
description: "This link doesn't match a validation request we can find. Check the
   link you were given, or ask the team to send a new one."
```
Discloses **no team name** — unlike States 2–4, there is no resolved challenge here to
name a team from, and inventing one would be either wrong or a guess. Copy is identical
regardless of *why* the token failed to resolve (malformed, unknown, or belonging to a
destination that no longer exists) — distinguishing those would turn this page into an
oracle for probing which tokens are "real," exactly the property AC22's "unguessable"
requirement exists to protect.

**State transitions.** GET on the link renders whichever of States 1/3/4/5 the link's
current, actual status is — GET never mutates anything (AC25), so a bookmarked or
revisited link always reflects the live truth, not a cached first impression. POST (the
Approve action) is only ever submitted from State 1, but the server re-checks status at
POST time, not just at the earlier GET: if the link expired, was voided, or was spent by
someone else in the seconds between this visitor loading Review and clicking Approve, the
response renders State 3 or State 4 exactly as if they had freshly navigated there — never
a distinct "something went wrong" error screen. Five templates total (Review + four
outcomes) cover both the GET and the POST path completely.

## Components
Reused, unmodified, from the existing app:
- `Badge` (`components/ui/badge`) — `outline`, `waiting`, and `moved` variants, all
  already in use elsewhere (design-06, design-10, design-11's Credential badge). No new
  variant is introduced; the constrained two-hue status system (`waiting` = amber, `moved`
  = teal) already distinguishes "in progress" from "resolved," and `outline` covers both
  states that are neither, differentiated by icon and label text rather than colour
  (Accessibility, below).
- `Alert` / `AlertDescription` (`components/ui/alert`) — `default` variant only; this
  feature never renders the `destructive` variant, because no state here is an error
  (AC4, AC28).
- `Button`, `Spinner`, `Card`, `Table`/`TableHead`/`TableCell` — unchanged, existing.
- `AuthLayout.vue` / `AuthSimpleLayout.vue` — reused verbatim for Screen 4; no prop or
  markup change.

New, introduced by this spec:
- **Four icons**, imported from `@lucide/vue` for the first time by this feature:
  `Circle` (Unvalidated), `Clock` (Pending), `History` (Expired), `Check` (Validated) —
  paired one-to-one with the state badges (Screen 2) so state is never carried by the
  badge's colour alone (Accessibility).
- **No new page-chrome component.** Screen 4 is a new Inertia page component, but it
  introduces no new layout, dialog, or interaction primitive — it is a page built entirely
  from `AuthSimpleLayout.vue` plus a plain `dl`, `p`, and `Form`, the same primitives
  `ForgotPassword.vue` already assembles.
- **No new confirmation dialog.** Screen 2's Validate action fires immediately, with no
  `ConfirmDialog` — consistent with `SigningCard.vue`'s `End overlap now`, which the PRD
  itself cites as the precedent this feature should follow (UX Direction point 7: "the
  same treatment #15 gives an unavailable replay," and generally, this app's standard of
  reserving a confirm step for destructive or hard-to-reverse actions; Validate is neither
  — it can be repeated freely and voiding a link is stated permanently in the Pending
  caption rather than asked about per click).

## Interactions
- **Validate (Screen 2):** single click, immediate `router.post` (or equivalent), no
  confirmation. Button shows a `Spinner` and disables only itself while in flight (not the
  rest of the row). On success the row's badge/caption update in place (`preserveScroll`,
  matching `useProxyActions.ts`'s existing pattern for `endSigningOverlap`). On a send
  failure, the row reflects the new Unvalidated failure-reason caption — the request
  itself does not "fail" from the member's point of view (a failed *send* is still a
  successful *response* to their click, per AC18's own framing that a send failure is not
  an application error).
- **URL-change warning (Screen 1):** pure client-side reactivity, no request — compares
  the row's live input value to its mount-seeded value on every keystroke. Never confirms
  or blocks submission; it is disclosure, not a gate (AC5).
- **Rate-limited state (Screen 2):** the button is removed from the tab order entirely
  when rate-limited (not merely `disabled` with no text) — replaced by the explanatory
  line, which is itself not interactive. This avoids a focusable control that does nothing
  when activated.
- **Confirmation page Approve (Screen 4):** a genuine HTML form POST. This is a hard rule,
  not a stylistic preference: AC25's entire reason for requiring a POST is that a GET
  auto-fires from link scanners and mail-preview fetchers, so the Approve action must
  never be reachable by any GET, prefetch, or `<a>`-styled-as-button — it is a `<form>`
  with a submit `Button`, exactly as `ForgotPassword.vue` already builds its own POST
  action. No auto-submit on load, no JS timer that submits it, under any circumstance.
- **Confirmation page's outcome states (2–5):** contain no interactive element beyond
  whatever the page-level chrome (browser back button, etc.) provides — no buttons, no
  links back into the product, because there is nowhere in the product for this visitor to
  go.
- **Keyboard:** every control in this spec is a standard `Button`, `Badge` (non-
  interactive), or form `input`/`Form` — no custom widget, so keyboard behaviour is the
  browser/shadcn default throughout. The Review screen's Approve button is reachable by
  Tab and activates on Enter/Space exactly like any other submit button on this app's
  existing auth pages.

## Accessibility
- **State is never carried by colour alone (explicit requirement).** Every validation
  state pairs a distinct icon with a distinct text label, on every surface it appears
  (Screens 1, 2, 3): `Circle`/"Unvalidated", `Clock`/"Pending", `History`/"Expired",
  `Check`/"Validated". A member using a high-contrast mode or with colour-vision
  deficiency reads the icon and the word, never the badge's background hue, to tell the
  four states apart — this is also how Expired stays visually distinguishable from a
  destructive/failure treatment despite AC4 ruling out red/error styling for it (an
  `outline` badge with a "History" glyph, not merely "the same grey box as Unvalidated
  with different words," is why the two remain distinct at a glance and not only on
  close reading).
- **Screen 1's URL-change warning is wired to the field it warns about**, via
  `aria-describedby` on the URL input pointing at the warning's own `id` whenever it is
  showing (in addition to any validation-error id already present) — a screen-reader user
  hears the consequence when focusing the field, not only sighted users who happen to
  glance below it.
- **Screen 2's Validate button carries an accessible name that includes the destination**,
  e.g. `aria-label="Validate https://example.com/webhook"`, matching this table's existing
  convention for `Remove destination {n}` and `Replace credential for {url}` — a screen
  reader user tabbing through several rows' worth of identical "Validate" buttons can tell
  them apart without visual context.
- **Screen 3's header badge is not `aria-hidden`** — it is read in the normal document
  flow alongside the existing Mode/Processing/Paused badges, so a screen-reader user
  scanning the page header hears "2 destinations not yet validated" exactly where a
  sighted user sees it, not only on scrolling to the table.
- **Screen 4 needs no authentication-aware accessibility treatment** (no user menu, no
  team switcher, no sidebar) because `AuthSimpleLayout.vue` already omits all of that for
  every page that uses it; this spec inherits that, unmodified. The page's `title`/
  `description` props (rendered as `h1`/`p` by the layout) give every state a proper
  heading landmark for a screen-reader user landing fresh with no other page context —
  critical here, since this visitor has no navigation to fall back on if the heading
  fails to orient them.
- **Contrast.** The `outline` badge variant already meets this app's existing contrast bar
  (used throughout design-06/010/011); the `waiting`/`moved` variants are the same tokens
  already shipped. No new colour token is introduced by this spec.

## Responsive Behavior
- **Screen 1 (form):** the new validation status line and the URL-change warning are
  block-level elements inside the row's existing `grid` — they stack full-width below the
  URL/Method/Remove line on every breakpoint, exactly as the Credential collapsible
  already does; no new responsive behaviour is introduced.
- **Screen 2 (Destinations table):** the new Validation column follows the table's
  existing horizontal-scroll behaviour on narrow viewports (`Table`'s existing overflow
  handling) rather than collapsing to a card list — consistent with how the Credential
  badge and the Delivered/Attempt/Latency columns already behave on this table today.
  **Amended 2026-09-01.** This originally said the column's multi-line content "sets a
  natural minimum column width", and that was wrong: under the browser's automatic table
  layout the destination URL is one long unbreakable token and wins the width negotiation
  outright, leaving the Validation column 117px of 1148px and wrapping its caption to
  eight lines. The table now declares its column widths and a minimum table width, so the
  URL truncates — which it always could, given a bounded cell — and the caption gets the
  room it needs. No truncation is applied to the caption text, since AC34/AC35's whole
  point is that this text be read, not hinted at.
- **Screen 3 (header badge):** wraps with the other header badges in the existing
  `flex flex-wrap` treatment (matches Mode/Processing/Paused today) — no new behaviour.
- **Screen 4 (confirmation page):** inherits `AuthSimpleLayout.vue`'s existing
  `max-w-sm`, centred, single-column responsive behaviour verbatim — already mobile-first,
  since this page is disproportionately likely to be opened on a phone (an operator
  reading a challenge notice away from their desk). No destination URL is ever so long
  that a `break-all` wrap (already specified in Screen 4's markup) fails to keep it inside
  the card's width on a narrow viewport.

## Open Questions
None. Every UX question this feature raises is settled either by the PRD's `## UX
Direction` (restated at the top of this spec, not re-litigated) or by a Designer decision
recorded in place above (the Expired/voided merge at Screen 4 State 4; the Validate
action's placement on the Show page rather than the form; the header-badge-plus-table-row
treatment for AC33, with no third, redundant banner). Q-18-01, the PRD's own open
question, is technical and addressed to the Principal Engineer; it gates technical design,
not this spec, and nothing in this document depends on it being answered a particular way
— every screen here reads correctly regardless of *where* the queue-check/dispatch-gate
enforcement points end up living.

## Handoff
- **Inputs:** `docs/product/prd-18-destination-validation.md` (UX Direction, Users, User
  Stories, Acceptance Criteria, Handoff) · `docs/design/design-10-sensitive-data-handling.md`
  (structure and reused patterns: the write-only-field shape's *absence* of relevance here,
  the Show-page mutating-action-lives-on-Show precedent, the `canUpdate` gate, the
  `Alert`/`Badge` variant vocabulary) · `resources/js/components/DestinationRows.vue`,
  `resources/js/components/proxies/DestinationsCard.vue`, `resources/js/pages/proxies/Show.vue`,
  `resources/js/pages/proxies/ProxyForm.vue`, `resources/js/types/proxies.ts` (the
  destination and proxy surfaces this spec extends, read as they exist today) ·
  `resources/js/layouts/AuthLayout.vue` / `AuthSimpleLayout.vue`,
  `resources/js/pages/auth/ForgotPassword.vue` (the confirmation page's reused shell) ·
  `resources/js/components/proxies/SigningCard.vue`, `resources/js/composables/useProxyActions.ts`
  (the immediate-action-with-busy-state precedent Screen 2's Validate follows) ·
  `resources/js/components/ReplayDialog.vue` (the unavailable-action precedent named by
  the PRD for AC9/AC21's "reason given" treatment).
- **Outputs:** this design spec.
- **Dependencies:** PRD-18 (Approved, Project Owner, 2026-08-31). No design dependency on
  any other in-flight feature.
- **Outstanding Questions:** none.
- **Next Agent:** Product Manager, for the design-gate approval against PRD-18. On
  approval: Principal Engineer, for technical design — Q-18-01 (already open, PRD-raised)
  gates that step, not this one.

