# Design Spec: Sensitive data handling

- **Status:** Draft
- **Author:** Designer
- **PRD:** `docs/product/prd-10-sensitive-data-handling.md` (**APPROVED** by the
  Project Owner, 2026-08-27, as amended — `## Amendment A` ratified whole; 64
  acceptance criteria)
- **Approved by / date:** —

> **Scope note.** #10 adds **no new page and no new navigation entry.** It extends
> four existing surfaces: **(1)** the proxy create/edit form (`ProxyForm.vue`) gains
> a **Verification** section (inbound scheme + secret, AC23–AC29, AC51–AC53), a
> **Sensitive fields** section (AC12–AC22), and a **Credential** subsection inside
> each destination row (AC30–AC39); **(2)** the proxy **Show** page gains a
> **Verification** card (status + the AC29 end-it-now action) and two small status
> badges plus a **Manage signing** action on the existing Destinations table
> (AC54–AC64); **(3)** a new **Manage destination signing** dialog, reached from that
> action, is where a destination's signing secret is generated, regenerated, shown
> once (AC57), and rotated (AC58); **(4)** the event detail page's existing
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
- The destination signing secret is displayed **exactly once**, at generation, and
  never again (AC57).
- The verification scheme list is closed to exactly two values, presented as a
  selection, never a free-form or described configuration (AC23).
- The rotation overlap is a **fixed 24 hours**, not a member setting, in both
  directions it applies to — the verification secret and the destination signing
  secret (AC29, AC58). The destination credential has **no** overlap at all (AC29's
  closing clause) and this spec's destination-credential surface (Screen 3) is
  written so nothing on it implies one — no "previous", no countdown, no "honoured
  until" language anywhere near it.
- Obfuscation discloses nothing about a value's length (AC16).

## Scope boundaries (confirmed, not designed here)
Restated so this spec reads as complete against every AC, not only the UI-bearing
ones:
- **AC1–AC11 — the at-rest guarantee and D2's discharge.** Encryption at rest, the
  closed set of payload stores, the by-reference queue argument, failed-job
  diagnosability, and the key-lifecycle rule are all system properties with no
  surface. Nothing in this spec renders a store name, an encryption state, or a
  "your secret is encrypted" indicator anywhere — the guarantee is structural, not
  displayed (mirroring how PRD-05's own at-rest floor has never had a UI element).
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
("your previous secret is still honoured until â€¦") with an explicit **End overlap
now** action for the member who needs to kill a leaked secret before the fixed
24 hours run out. The existing Destinations table gains two small status badges
(Credential, Signed) and a **Manage signing** action that opens a dedicated dialog:
the *only* place in this feature a secret is ever shown to the member, because the
product generates the destination's signing secret itself and this is the member's
one chance to copy it before it is gone for good. Everywhere else in the app that
already shows a stored payload — the single masked/revealed viewer #6 built — now
obfuscates sensitive field **values** the instant it renders them, leaving field
**names** and the payload's structure fully visible, so a member can still see that
a `password` field exists and where, just never what it holds.

## User Flows

### Flow A â€” Configure a proxy's inbound verification (create or edit)
*(User stories: "require verification on my ingest URL"; "point the product at the
secret my sender issued me and have it verified as that specification says.")*
1. Member opens **New proxy** or **Edit proxy**. The **Verification** section
   (Screen 1) sits below **Processing**, above **Retry policy**.
2. **Scheme** defaults to **Not required** â€” identical to every proxy today
   (AC24). No secret field renders in this state.
3. Member selects **"My sender already implements Standard Webhooks"**
   (`standard-webhooks`) or **"My sender sends a shared secret in a header"**
   (`shared-secret`) â€” framed by what the sender does (UX Direction point 6), the
   underlying value is the closed AC23 token.
4. The scheme's own fields and its "what your sender must send" statement appear
   (Screen 1). Member pastes the secret **issued by their upstream provider** â€”
   #10 never generates this one (AC26) â€” and, for `shared-secret` only, names the
   header their sender will send it in.
5. Member submits with the rest of the form. On success, this proxy now rejects
   any request that fails verification, before capture (AC25) â€” nothing else about
   the proxy's history, destinations, or ingest URL changes.
6. **Changes mind before saving:** switching **Scheme** back to **Not required**
   discards the in-session entry; nothing is sent.

### Flow B â€” Replace (rotate) a verification secret
*(User story: "rotate a secret without coordinating the exact moment with the
other side, because the old one keeps working for a stated period.")*
1. Member opens **Edit** on a proxy that already has a verification secret set.
   The Verification section shows the scheme (fixed, not editable without first
   returning to Not required) and, in place of a blank secret field, a collapsed
   status line: **"Secret set â€” changed {date}"** plus a **Replace** control
   (Screen 1's write-only pattern, identical in shape to Screen 3's destination
   credential).
2. Member clicks **Replace** â†’ a blank secret field appears (never pre-filled;
   AC26 forbids redisplaying the current value).
3. Member types the new secret and saves.
4. On save, the new secret becomes current and the previous one is demoted, not
   discarded â€” both are honoured inbound for a fixed 24 hours (AC29). Nothing in
   this form states that; the period becomes visible on **Show** (Flow C), because
   rotation is a status a member checks, not a thing they configure a duration for.
5. **Cancels before saving:** clicking Replace and then navigating away, or
   toggling back without submitting, changes nothing server-side â€” identical to any
   other unsaved form field.

### Flow C â€” View verification status and end a rotation overlap early
*(User stories: "see that two secrets are currently honoured and when the older
one stops"; "a member may end an overlap early... without it, the only way to kill
a leaked secret before 24 hours is a second rotation.")*
1. From a proxy's **Show** page, the member sees the **Verification** card
   (Screen 4): the scheme in force, the header name (`shared-secret` only,
   AC26 â€” it stays visible because the sender has to be configured to match it),
   and the secret's status â€” **Set â€” changed {date}**.
2. **While a rotation overlap is running** (Flow B just happened, or a signing
   rotation elsewhere on this same proxy is irrelevantâ€”this card is inbound-only),
   an additional line reads: **"A rotation is in progress â€” your previous secret
   is still honoured until {timestamp}."** An **End overlap now** button sits
   beside it.
3. Member clicks **End overlap now** â†’ confirms nothing further (this is a
   safety-tightening action, not a destructive one â€” see *Interactions*); the
   previous secret stops being honoured immediately and is erased (AC29), and the
   card's rotation line disappears on the next render.
4. **No overlap running:** the card shows only the plain status line from step 1
   â€” no button, no period language, because there is nothing to end.
5. **Scheme is Not required:** the card states that plainly â€” **"No verification
   required â€” this ingest URL accepts any request."** â€” matching the proxy's
   actual, unconfigured-by-default behaviour (AC24).

### Flow D â€” Manage a proxy's sensitive-field list
*(User stories: "passwords, tokens and card numbers... hidden when I look at it";
"add my own field names... because the product cannot know that my vendor calls
it `ssn_last4`.")*
1. On **Create** or **Edit**, the member sees the **Sensitive fields** section
   (Screen 2): the fixed default list â€” **Password**, **Token**, **Credit card**
   â€” rendered as plain, non-removable badges, with a line noting common spellings
   and separators are matched automatically (AC12).
2. Below it, the proxy's own additions render as removable badges. An **Add field
   name** input plus button (or Enter) appends a new one; a badge's **Ã—** removes
   it. Removing an addition never touches the default list (AC13).
3. This section renders identically whether the proxy is Simple or Enhanced â€”
   obfuscation is not mode-gated (nothing in AC12â€“AC22 mentions Mode).
4. Saving persists the additions for this proxy only (AC13, per-proxy grain);
   the very next view of any of this proxy's payloads reflects the new list
   (AC19 â€” retroactive, because nothing is rewritten).

### Flow E â€” View an event's payload with sensitive values obfuscated
*(User stories: "hidden when I look at it, so debugging a delivery does not put a
customer's secret on my screen"; "obfuscation... never as empty, missing, corrupt,
or cleaned.")*
1. Member opens a **retained** event and clicks **Reveal payload** exactly as
   today (design-06 Flow C, unchanged trigger and unchanged whole-payload mask
   default).
2. The revealed payload renders (Screen 7): every field **name** and the
   payload's full structure are visible; every value whose field name matches a
   sensitive-field rule (product default or this proxy's own addition, matched
   case-insensitively, at any depth â€” AC14) renders as a fixed, non-informative
   placeholder instead of its real value. A non-sensitive value renders exactly as
   received.
3. Clicking **Hide payload** re-masks the whole thing, exactly as today â€” there is
   no intermediate state where structure is shown but the whole-payload mask is
   still up (the whole-payload mask and field obfuscation are independent layers;
   Screen 7 states the interaction precisely).
4. **No field is ever individually revealed** â€” the placeholder carries no click
   target, no button, no affordance of any kind (AC20). The member's only remedy
   for a field they want to see is to remove its name from this proxy's list
   (Flow D) â€” entirely in their hands, and stated as such in the placeholder's
   accessible description.
5. **Non-JSON payload:** nothing changes from design-06 â€” the whole-payload
   mask/reveal behaves exactly as it does today, with no field-level claim made
   (AC22).
6. **Cleaned or not-captured event:** unchanged from design-06 â€” a cleaned
   event's muted "expired" line and a not-captured event's muted line are payload
   states, not obfuscation, and neither is ever confusable with an obfuscated
   value sitting inside a *retained*, revealed payload (AC21) â€” the two states
   never render on the same screen at the same time.

### Flow F â€” Configure or replace a destination's credential
*(User stories: "give a destination a credential, so I stop having to paste it
into the destination URL.")*
1. On **Create** or **Edit**, each destination row in the existing repeatable
   fieldset (`DestinationRows.vue`) gains a collapsed **Credential** disclosure
   (Screen 3), defaulting **open** if a credential is already set for that row and
   **collapsed** ("Add credential") otherwise â€” so a proxy with many destinations
   and few credentials stays scannable.
2. Expanding an unconfigured row shows **Header name** (text, defaults to
   `Authorization`) and **Secret value** (write-only entry). Both are
   member-supplied (AC30); the header name stays visible after saving (it is not
   the secret), the value never will be (AC33).
3. Member fills both (or leaves the row's Credential collapsed and unconfigured
   â€” entirely optional, AC30) and saves with the rest of the form.
4. **Replacing an existing credential:** the disclosure opens to a status line
   â€” **"Credential set â€” changed {date}"** â€” plus a **Replace** control, the same
   write-only pattern as Screen 1. Clicking it reveals the header-name field
   (pre-filled with the current name, since it is not secret) and a blank secret
   field. Saving replaces the credential **immediately** â€” no overlap, no
   "previous" state, ever (AC29's exclusion, PRD-10's own explicit carve-out).
5. **A brand-new destination row added this session** behaves identically â€” it
   simply has nothing to show as "already set" yet.
6. **Removing a destination row** (existing trash-icon control, unchanged)
   removes its credential along with everything else about that row â€” no separate
   confirmation, matching how removing a row already discards its URL/method with
   no extra step.

### Flow G â€” Enable a destination's signing and capture the one-time secret
*(User stories: "prove that a webhook it received came from my proxy... my
receiver can reject everything else"; "see the signing secret once... and never
again afterwards.")*
1. On a proxy's **Show** page, the Destinations table's Actions column gains a
   **Manage signing** button per **live** (non-deleted) destination â€” absent for
   a deleted one, since nothing is dispatched to it any more.
   *(Only available once a destination is saved and has an id â€” a row just added
   in the current create/edit session, not yet submitted, shows no such action
   anywhere, because there is nothing yet to attach signing to.)*
2. Clicking it opens the **Manage destination signing** dialog (Screen 6), scoped
   to that one destination. **Not yet enabled** shows a single statement ("This
   destination does not verify dispatches from us yet") and an **Enable signing**
   button.
3. Member clicks **Enable signing** â†’ the product generates the secret
   immediately (AC56 â€” generation is the only way one exists; nothing is typed).
   The dialog transitions to its **one-time reveal** state: the secret in a
   read-only, copyable field, a plain statement that this is the only time it will
   ever be shown, and a **Done** button that only closes the dialog after the
   member has had the field in front of them â€” it does not auto-close on copy.
4. Member copies the secret (into their receiver's configuration, outside this
   app) and clicks **Done**. The dialog's next open shows the ordinary **enabled**
   status (Screen 6, default state) â€” never the secret again, under any
   circumstance or role (AC57).
5. From this point, every dispatch to this destination â€” original, retry, and
   replay alike â€” carries the Standard Webhooks signature headers (AC55, AC60);
   nothing else about the request changes (AC59).

### Flow H â€” Regenerate a destination's signing secret, and end its overlap early
*(User story: "rotate a secret without coordinating the exact moment... the old
one keeps working for a stated period" â€” the outbound half of Flow B/C, AC58.)*
1. Member opens **Manage signing** on an already-enabled destination (Screen 6).
   The dialog's default state shows **"Enabled â€” generated {date}"** and two
   actions: **Regenerate signing secret** and **Disable signing**.
2. Clicking **Regenerate signing secret** immediately generates a new one (same
   AC56 rule â€” there is no "type a replacement" path for this secret, ever) and
   transitions to the same one-time reveal state Flow G step 3 describes. The
   previous secret is demoted, not discarded: both are honoured **outbound** for
   the same fixed 24 hours (AC58) â€” every dispatch in that window carries a
   signature under both, per the specification's own space-delimited list, asking
   nothing extra of the receiver.
3. Once acknowledged (**Done**), the dialog's default state shows the rotation
   line exactly as Screen 4 does for the inbound direction: **"A rotation is in
   progress â€” your previous secret is still honoured until {timestamp}"** plus an
   **End overlap now** button (Screen 6).
4. Clicking **End overlap now** stops the previous secret being honoured
   immediately, with no further confirmation (same reasoning as Flow C step 3 â€”
   this narrows exposure, it does not destroy anything the member is relying on
   going forward).

### Flow I â€” Disable a destination's signing
*(Falls out of AC54's "optional... off by default" â€” a two-way toggle implies a
way back to off. Not separately named by an AC beyond that; see Open Questions.)*
1. From the **Manage signing** dialog's enabled state, member clicks **Disable
   signing**.
2. Signing stops applying to this destination's dispatches immediately â€” they
   revert to byte-identical, unsigned requests (mirroring AC63's "existing
   destinations" behaviour). No overlap, no confirmation step (non-destructive:
   nothing stored is exposed or lost by this action).
3. **Re-enabling later** always generates a fresh secret and re-runs Flow G's
   one-time reveal in full â€” the product never resurfaces or reuses a
   previously-generated secret, because AC57 already forbids displaying it again,
   so there is nothing to resurrect it as. Stated explicitly in the dialog's
   disabled-state copy so a member does not expect their receiver's old
   configuration to keep working without updating it.

## Screens & States

### Screen 1 â€” Create / Edit Proxy form â€” Verification section (extends `ProxyForm.vue`)
Placement, in the form's existing section order:
```
Details
  Name
  Mode
  Processing
Verification        (NEW â€” this spec)
Retry policy         (design-06, unchanged â€” renders only when Mode = Enhanced)
Response
Sensitive fields     (NEW â€” this spec, Screen 2)
Destinations         (design-01/06, extended â€” Screen 3)
```
Verification sits directly after Processing and before Retry policy: verification
gates whether a request is ever captured at all, which happens before retry policy
has anything to govern â€” the section order follows the pipeline order.
*(Flagged design call 1 â€” placement is reversible.)*

```
Label "Verification" (legend)
p (help) "Require an incoming request to prove it's really from your sender
   before anything is captured. Off by default â€” existing proxies are unaffected."
Select v-model="form.verification_scheme"
  SelectItem value=""                    â†’ Not required (default)
  SelectItem value="standard-webhooks"    â†’ My sender already implements Standard Webhooks
  SelectItem value="shared-secret"        â†’ My sender sends a shared secret in a header

[â€” conditional on scheme â€”]

v-if scheme === 'shared-secret':
  Label "Header name" for="verification_header_name"
  Input id="verification_header_name" placeholder="X-Signature"
  p (help) "The header your sender sends the secret in. Case-sensitive as your
     sender configures it."
  Label "Secret value" for="verification_secret"
  [write-only field â€” see States below]
  p (help) "The exact value your sender will send in that header."

v-if scheme === 'standard-webhooks':
  Label "Secret value" for="verification_secret"
  [write-only field â€” see States below]
  p (help) "The signing secret your sender issued you for this integration.
     #10 does not generate this â€” paste the value they gave you."
  div (static, always visible under this scheme)
    p "Your sender must send these three headers on every request:"
    ul
      li "webhook-id"
      li "webhook-timestamp"
      li "webhook-signature â€” one or more HMAC-SHA256 signatures, base64-encoded,
          space-delimited"
    p "Requests whose webhook-timestamp is more than {tolerance} from the current
       time are rejected, per the Standard Webhooks specification."
```

**Copy source for `{tolerance}`.** The five-minutes figure is the *specification's*
tolerance, not a value #10 invents (AC53) and the specification, not this product,
may change it. The copy interpolates the same value the backend actually enforces
rather than a hand-typed "5 minutes" â€” the same single-source discipline design-07
established for its default-attempt-limit copy (`ProxyForm.vue`'s
`defaultAttemptLimit`).

**Write-only secret field â€” shared shape (AC26, and reused verbatim for Screen 3's
credential and Screen 6's signing secret display).** Two states:
- **Unset** (nothing saved yet, or `scheme` freshly changed to one that has never
  held a secret): a plain `Input type="password"` â€” chosen because there is no
  existing password-input precedent in this app to follow and this is the
  standard semantic for a masked-entry field; nothing about it needs styling
  beyond the input treatment every other text field already has.
- **Set** (editing a proxy with a stored secret for the current scheme): a
  collapsed line, not an input â€” **"Secret set â€” changed {date}"** â€” plus a
  **Replace** button (`variant="ghost"`, small). Clicking it swaps the line for a
  blank `Input type="password"` (never pre-filled â€” there is nothing to pre-fill
  it *with*, since the value was never sent back to the client in the first
  place). A second click on a "cancel replace" affordance (or simply not touching
  the field before submit) leaves the stored secret untouched â€” the field being
  present-but-empty must **not** submit as "clear the secret"; see *Interactions*.

**States.**
| Scheme | Fields shown | Status line (edit, already set) |
|---|---|---|
| Not required (default) | none | â€” |
| `shared-secret`, unset | Header name, Secret value (input) | â€” |
| `shared-secret`, set | Header name (visible, editable), Secret value (collapsed status + Replace) | "Secret set â€” changed {date}" |
| `standard-webhooks`, unset | Secret value (input) + static "what your sender must send" block | â€” |
| `standard-webhooks`, set | Secret value (collapsed status + Replace) + the same static block | "Secret set â€” changed {date}" |

**Switching scheme clears the in-session, unsaved secret field** â€” the same data
operation `design-07`/`design-06` already apply to the Retry-policy fieldset on a
Mode change: a hidden field can never carry a stale value into submit.
**Switching scheme does not touch what is already persisted** until the form is
actually saved â€” exactly the distinction `ProxyForm.vue`'s existing code comments
already draw between in-session typed values and mount-seeded persisted ones.

### Screen 2 â€” Create / Edit Proxy form â€” Sensitive fields section (NEW)
Placement: after **Response**, before **Destinations** (closing the "what this
proxy does with a request" set of sections before the destination list, which has
always been the form's last section).

```
Label "Sensitive fields" (legend)
p (help) "Values in these fields are hidden wherever this proxy's stored payloads
   are shown. This never changes what's stored or what's delivered â€” see a
   payload's Reveal to check."

div "Always hidden"
  Badge (secondary, no Ã—) "Password"
  Badge (secondary, no Ã—) "Token"
  Badge (secondary, no Ã—) "Credit card"
p (help, smaller) "Common spellings and separators are matched automatically
   (e.g. card_number, cardNumber) â€” case doesn't matter."

div "Also hidden for this proxy"
  Badge (outline, Ã—) v-for addition       â€” e.g. "ssn_last4 Ã—"
  [empty: no badges, no placeholder box â€” matches the Response card's
   "no empty bordered box" precedent]

Label "Add field name" for="sensitive-field-add" (sr-only if visually redundant)
Input id="sensitive-field-add" placeholder="e.g. ssn_last4"
Button "Add" (or Enter key)
```

**States.**
- **No additions yet:** the "Also hidden for this proxy" area shows nothing but
  the input/Add control â€” no "none yet" placeholder text, matching the app's
  existing convention of omitting empty-state filler where the input itself makes
  the emptiness self-evident (`DestinationRows.vue`'s add-row pattern has no such
  filler either).
- **Adding:** typing a name and pressing Enter (or clicking Add) appends a new
  removable badge and clears the input; focus stays on the input so a member can
  keep adding without re-clicking (mirrors `DestinationRows.vue`'s add-row focus
  behaviour in spirit, though that flow moves focus to the new row's own field â€”
  here there is no per-badge field to move to).
- **Removing:** clicking a badge's Ã— removes it from the in-session list; nothing
  is sent until the form saves (identical semantics to `form.destinations` array
  mutation already in `ProxyForm.vue`).
- **Duplicate/empty entry:** client-side, a blank or whitespace-only entry is not
  added; a name that already exists in this proxy's own additions is not
  duplicated (silently â€” no error toast, matching this app's low-ceremony
  treatment of a no-op). Whether a name that already matches the **default** list
  is also rejected client-side, or simply accepted as a harmless no-op addition,
  is left to implementation â€” either is correct because AC12/AC13 never conflict
  (a name can be in both lists with no different effect).
- **Validation error (server-side, e.g. a length cap on a field name):** renders
  through the existing `InputError` pattern under the Add control, exactly like
  any other field on this form.

### Screen 3 â€” Create / Edit Proxy form â€” Destinations fieldset, Credential subsection (extends `DestinationRows.vue`)
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
      [same write-only shape as Screen 1's secret field, applied to two fields:]
      Label "Header name" / Input (default "Authorization", visible + editable always)
      Label "Secret value" / [write-only field â€” unset: Input type="password";
        set: "Credential set â€” changed {date}" + Replace]
      p (help) "Sent verbatim on every dispatch to this destination â€” the
         product adds no scheme prefix (e.g. enter "Bearer abc123" yourself if
         your destination expects one)."
```

**Default expand state.** A row whose destination already has a credential set
opens **expanded** by default (the member's most likely reason to open this row
is to check or replace it); an unconfigured row opens **collapsed**, keeping a
proxy with many destinations and few credentials scannable. *(Flagged design call
2 â€” a reversible density decision.)*

**No rotation language anywhere in this block.** AC29 explicitly excludes the
destination credential from the overlap rule â€” there is exactly one credential
value on the wire at any time, and replacing it is immediate. This block never
says "previous", never shows a countdown, never offers an "end overlap" control
â€” the write-only pattern here is identical in *shape* to Screen 1's but different
in *consequence*, and the copy says so plainly ("Replacing takes effect on the
next dispatch â€” there's no transition period.").

**States (per row).**
| Row state | Trigger label | Content |
|---|---|---|
| New row, this session | "Add credential" | Header name (Authorization), blank Secret value |
| Existing, no credential | "Add credential" | same as above |
| Existing, credential set | "Credential: set" (expanded by default) | Header name (editable), "Credential set â€” changed {date}" + Replace |
| Replace clicked | "Credential: set" | Header name (pre-filled), blank Secret value |

**Removing a row** removes its Credential block with it â€” no separate prompt,
identical to how removing a row already discards its URL/method silently.

### Screen 4 â€” Proxy Show â€” Verification card (NEW)
Placement: alongside the existing Retry policy card, after Destinations (the same
card-stack order the Show page already uses â€” pipeline configuration cards, then
the destination-facing ones). Uses the same `Card` / `dl`/`dt`/`dd` shape every
other Show-page card already uses (design-03's pattern, its third-plus reuse).

```
Card
  h2 "Verification"
  p (help) "Whether this proxy requires an incoming request to prove it's from
     your expected sender before anything is captured."
  [state block â€” see States]
```

**States.**
- **Not required (default â€” every proxy today):**
  `p` "No verification required â€” this ingest URL accepts any request."
- **`shared-secret`, no overlap:**
  ```
  dl
    dt "Scheme" / dd "Shared secret"
    dt "Header" / dd "X-Signature"           (the member-chosen name)
    dt "Secret" / dd "Set â€” changed Aug 20, 2026"
  ```
- **`standard-webhooks`, no overlap:**
  ```
  dl
    dt "Scheme" / dd "Standard Webhooks"
    dt "Secret" / dd "Set â€” changed Aug 20, 2026"
  ```
- **Either scheme, overlap running** â€” an additional row/line and an action:
  ```
  p "A rotation is in progress â€” your previous secret is still honoured until
     Aug 21, 2026, 10:03 AM."
  Button variant="outline" "End overlap now"
  ```
  On click: `Spinner` while in flight, button re-disables; on success the line
  and button disappear (the card's next render shows the plain "Set" state); on
  failure, an inline error renders below the button (same treatment as any other
  request-level failure elsewhere in this app, e.g. `ReplayDialog`'s
  `AlertError`).

No loading/error states beyond the page-level ones `design-01` already
specifies â€” this card renders fields already on the Show payload, no independent
fetch except the End-overlap-now action itself.

### Screen 5 â€” Proxy Show â€” Destinations table, extended (Credential/Signed badges + Manage signing action)
Extends the existing Destinations table (design-11 Screen 3) with no new column
â€” two small status badges inline with the existing Destination cell content, and
one new Actions-column button.

```
TableCell   (Destination â€” existing cell, extended)
  Badge outline {{ destination.httpMethod }}       (existing)
  span.font-mono {{ destination.url }}              (existing)
  Badge v-if="destination.hasCredential" outline "Credential"   (NEW)
  Badge v-if="destination.isSigningEnabled" outline "Signed"    (NEW)

TableCell   (Actions â€” existing cell, extended)
  Badge v-if="destination.isDeleted" secondary "Deleted"        (existing)
  Button variant="ghost" size="sm" as-child                     (existing)
    Link "View events"
  Button v-if="!destination.isDeleted" variant="ghost" size="sm"  (NEW)
    @click="openSigningDialog(destination)"
    "Manage signing"
```

**Why badges, not new columns.** The table already carries four data columns plus
Actions (design-11); a fifth and sixth column for two booleans would crowd a table
that is already dense on narrow viewports. A badge that renders only when true
(the same "absence is the compliance" idiom the `Deleted` badge already uses)
keeps the row scannable and costs nothing when neither applies â€” which is every
destination today (AC37, AC63: existing destinations are unaffected, so this is
the common case at ship time). *(Flagged design call 3.)*

**No `Credential` action here** â€” a destination's credential is edited only from
the proxy's **Edit** form (Screen 3), matching the existing precedent that this
table has never carried a per-row "Edit" action; editing a destination's own
fields (URL, method) is already Edit-form-only. The `Credential` badge is a status
indicator, not a button.

**`Manage signing` is absent for a deleted destination** â€” nothing is dispatched
to it any more, so there is nothing for signing to apply to (mirrors why the
`Replay`/credential-editing affordances are similarly withheld from a destination
no longer reachable through the live form).

**States:** unchanged loading/error handling from the existing table (design-11);
this is a presentation-only extension of data already on the Show payload plus
the two new booleans.

### Screen 6 â€” Manage destination signing dialog (NEW)
Triggered by Screen 5's **Manage signing** button, scoped to one destination.
Modelled directly on `ReplayDialog.vue`'s shape (plain `Dialog`, not
`AlertDialog` â€” nothing here is destructive; see *Interactions*).

```
Dialog
  DialogHeader
    DialogTitle "Signing for {METHOD} {url}"
    DialogDescription "Lets this destination verify that a dispatch really came
      from this proxy, using the same Standard Webhooks specification this
      product can also verify incoming requests under."
  [state block â€” see States]
  DialogFooter
    DialogClose as-child â†’ Button variant="ghost" "Close"
    [state-dependent primary action(s) â€” see States]
```

**States.**
1. **Not enabled (default for every destination today, AC63):**
   ```
   p "This destination does not verify dispatches from us yet."
   ```
   Footer primary action: **Enable signing** (`Spinner` while generating).

2. **One-time reveal** (immediately after Enable signing *or* Regenerate signing
   secret succeeds â€” same sub-state both times):
   ```
   Alert (info-styled, TeamInvitationAlert.vue precedent)
     AlertTitle "Copy this now"
     AlertDescription "This is the only time this secret will ever be shown.
       Configure your destination's receiver with it before you close this
       dialog â€” the product cannot show it to you again."
   CopyField :value="secret" copy-label="Copy signing secret"
     announcement="Signing secret copied to clipboard"
   ```
   Footer primary action: **Done** (closes the dialog; does *not* auto-close on
   copy â€” the member must take the explicit step, per the UX Direction's "the
   experience must optimise for the member actually captures it before leaving
   the screen"). No **Close** (Cancel-style) affordance is offered in *this*
   sub-state â€” only **Done** â€” so a member cannot dismiss the one-time reveal by
   habit without it registering as the deliberate acknowledgement it is.
   *(Flagged design call 4 â€” whether the dialog's outer `Esc`/overlay-click
   dismissal should also be suppressed during this sub-state, forcing **Done** as
   the only way out, is a defensible tightening; left permissive here â€” `Esc`
   still closes it â€” because Reka UI's default behaviour is relied on everywhere
   else in this app and the secret was already shown in full by the time this
   sub-state renders, so an accidental `Esc` costs nothing the member didn't
   already have a chance to copy.)*

3. **Enabled, no overlap:**
   ```
   p "Enabled â€” generated Aug 20, 2026."
   ```
   Footer: **Regenerate signing secret** (secondary) + **Disable signing**
   (`variant="ghost"`) + **Close**.

4. **Enabled, overlap running** (after a Regenerate, until 24 hours pass or
   ended early):
   ```
   p "Enabled â€” generated Aug 20, 2026."
   p "A rotation is in progress â€” your previous secret is still honoured until
      Aug 21, 2026, 10:03 AM."
   Button variant="outline" "End overlap now"
   ```
   Footer: **Regenerate signing secret** + **Disable signing** + **Close**
   (regenerating again while an overlap is already running is allowed â€” AC29's
   two-slot rule discards the oldest immediately, which **is** the documented
   remedy for a compromised secret discovered mid-overlap).

5. **Disabled (re-visited after Flow I):** identical to state 1, with one line
   added: *"Enabling again generates a new secret â€” your previous one is never
   shown or reused."* (Flow I step 3's stated behaviour, made visible here so a
   member doesn't assume their receiver's old configuration still applies.)

**Loading/error.** Every state-changing action (Enable, Regenerate, Disable, End
overlap now) disables its own button and shows `Spinner` for the duration of the
request, mirroring `ReplayDialog.vue`'s `form.processing` convention exactly. A
request-level failure renders through the same `AlertError`-style inline region
`ReplayDialog.vue` already uses, above the footer; the dialog stays open and the
prior state is retained (nothing is assumed to have partially applied).

### Screen 7 â€” Event detail â€” Payload card, sensitive-value obfuscation (extends `PayloadViewer.vue`)
No change to the **masked** (default) state or the **Reveal**/**Hide** toggle
mechanics â€” design-06's Flow C is untouched. The change is entirely inside the
**revealed** state, and only for a payload that parses as JSON (AC22).

**Revealed, JSON payload:** the payload renders pretty-printed (indentation and
line breaks make structure legible, per AC15's "must still be able to see the
payload's structure"), field names and non-sensitive values exactly as received,
and every sensitive value replaced by a fixed, distinctly-styled inline token:

```
[â€¦structure, e.g.â€¦]
{
  "customer": {
    "email": "jane@example.com",
    "password": [Hidden]        â† styled span, not the literal word rendered plainly
  },
  "amount": 4200
}
```

The `[Hidden]` token (exact wording is implementation's within this constraint:
short, never implies emptiness or an error) renders as an inline, visually
distinct span â€” muted background, same treatment family as a `Badge` but inline
with running text rather than block-level â€” carrying a native `title`/accessible
description: **"Hidden â€” this field's name matches a sensitive-field rule for
this proxy. Remove the name from Sensitive fields to stop hiding it."** This
directly satisfies the UX Direction's "must read as deliberately hidden by a rule
you can inspect" (point 2) rather than a bare placeholder string, and it is
**inert** â€” no click handler, no focus stop beyond what the surrounding text
already has, because AC20 forbids any reveal of it, individually, ever.

**Fixed-width, not value-shaped (AC16).** The token's rendered width, character
count, and presence are **constant** regardless of the real value's length, type,
or emptiness â€” it is the same token whether the real value was a
twelve-character password or an empty string, and it renders identically for a
string, a number, or a boolean sensitive value.

**Revealed, non-JSON payload:** unchanged from design-06 â€” the existing raw
`whitespace-pre-wrap` monospace block, no field-level treatment, no `[Hidden]`
tokens anywhere (AC22).

**Payload data shape â€” Principal Engineer's call, not designed here.** Whether
the revealed endpoint (`PayloadViewer.vue`'s `fetch(props.url)`) returns
pre-rendered, obfuscation-safe markup, or a structured field list the client
walks to build the `[Hidden]` spans itself, is a technical decision folded into
the mechanism the Principal Engineer already owns for this endpoint (the
fetch-on-reveal shape ADR-017 established). This spec specifies the **outcome** â€”
a distinctly-styled, accessible, inert token in place of each sensitive value,
structure and non-sensitive content otherwise untouched, pretty-printed for
legibility â€” and leaves the transport to technical design, the same way design-06
folded its own reveal-mechanism note into Q-06-03 rather than asserting a
mechanism itself.

**States (Payload card, complete):**
| Payload state | What renders |
|---|---|
| Retained, masked (default) | unchanged design-06 masked block |
| Retained, revealed, JSON | pretty-printed structure; sensitive values `[Hidden]` (this spec) |
| Retained, revealed, non-JSON | unchanged design-06 raw block, no field treatment |
| Cleaned | unchanged design-06 muted "expired" line |
| Not captured | unchanged design-06 muted line |

## Components
| Role | Component | Status |
|---|---|---|
| Verification scheme select | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Reused, unchanged shape |
| Verification/credential/signing secret entry | `Input type="password"` | **New usage** â€” no prior `type="password"` precedent in this app; standard semantic choice, no new primitive |
| Write-only status + Replace | plain text + `Button variant="ghost"` | Reused primitives, new small pattern (first use, repeated at Screens 1, 3) |
| Sensitive-field default/addition badges | `Badge` (`secondary` no-Ã—, `outline` with Ã—) | Reused, unchanged variant set |
| Sensitive-field add control | `Input` + `Button` | Reused, same add-row idiom as `DestinationRows.vue` |
| Destination credential disclosure | `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` | Reused â€” already-established precedent (design-06's attempt-history use) |
| Verification card | `Card`, `dl`/`dt`/`dd` | Reused, same pattern as Retry policy / Response cards |
| End overlap now | `Button variant="outline"` + `Spinner` | Reused |
| Destination status badges (Credential/Signed) | `Badge variant="outline"` | Reused, same idiom as the existing `Deleted` badge |
| Manage signing dialog shell | `Dialog`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose` | Reused (non-destructive-dialog pattern, `ReplayDialog.vue` precedent) |
| One-time secret reveal | `CopyField` | **Reused outside its original context** â€” first use for a value other than the ingest URL; same props shape (`value`, `copy-label`, `announcement`) |
| One-time reveal notice | `Alert`, `AlertTitle`, `AlertDescription` | Reused (design-07's first `AlertTitle` use precedent, now a second) |
| Obfuscated value token | new inline `span` (muted background, `title` attribute) | **New small composition**, built entirely from existing tokens â€” no new `ui/*` primitive, same shape as design-06's `PayloadViewer` masked-block treatment |
| Dialog action feedback | `Spinner`, inline `AlertError`-style region | Reused (`ReplayDialog.vue` precedent) |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**
`Collapsible` and `CopyField` are both reused beyond their first application
context; `Input type="password"` is a new *usage* of an existing primitive, not a
new component.

## Interactions
- **No secret field anywhere in this spec is ever pre-filled with a real value.**
  The unset â†’ set â†’ replace cycle (Screens 1, 3, 6) is the single write-only idiom
  this whole feature uses; an implementer building a second version of it
  anywhere is a defect against this spec.
- **A present-but-empty secret field must not submit as "clear the secret."**
  `ProxyForm.vue`'s submit `transform()` already normalises several fields this
  way (response/retry sentinels); the same discipline applies here â€” a Replace
  block left empty and never touched sends nothing for that field, not an empty
  string, so the stored secret survives an accidental Replace-then-cancel.
- **Neither "End overlap now" action is gated behind a confirmation dialog.**
  Both narrow exposure rather than destroy anything a member depends on going
  forward (the *replacement* secret keeps working either way) â€” consistent with
  this app's standing rule (`docs/standards/design.md`) that `AlertDialog` is
  reserved for destructive actions, and with how `design-07`'s non-destructive
  downgrade also skipped a confirm step.
- **Enable / Regenerate / Disable signing are all single-click, no confirmation**
  â€” for the same reason: nothing is deleted, nothing already dispatched is
  altered, and the one genuinely unrecoverable moment (losing the shown secret)
  is mitigated by the one-time reveal's own **Done**-gated flow, not by a
  separate confirm step in front of it.
- **The Sensitive fields Add/remove controls have no server round-trip** until
  the form is saved â€” purely in-session array mutation, identical to
  `form.destinations`'s existing behaviour.
- **The Destination credential Collapsible mounts/unmounts state exactly like
  the Retry-policy fieldset's Mode gating** â€” collapsing it does not clear an
  in-session typed value (unlike the Mode case), because a member toggling the
  disclosure open/closed while inspecting several rows should not lose what they
  just typed in one of them.
- **No interaction in this spec is conditioned on in-flight delivery state**
  (queued, retrying, mid-replay) â€” mirroring `design-07`'s AC17 treatment; #10
  adds no such gate either.

## Accessibility
- **Every write-only secret field** (Screens 1, 3): `Label for=`/`id` association;
  `aria-describedby` linking help + error, identical wiring to every other field
  on this form; the collapsed "Set â€” changed {date}" status line is real text
  content, read by assistive technology exactly as sighted users see it (no
  icon-only or colour-only cue).
- **Replace buttons** carry a discernible accessible name naming what they
  replace where ambiguous in isolation (`aria-label="Replace verification
  secret"`, `aria-label="Replace credential for {url}"`) â€” the icon-only/
  ambiguous-target rule this app already applies to Delete/Remove buttons.
- **The obfuscated value token** (Screen 7) carries a `title` **and** an
  `aria-label` with the same text (a `title`-only attribute is not reliably
  exposed to every assistive technology) â€” "Hidden â€” this field's name matches a
  sensitive-field rule for this proxy." It is not focusable and not
  interactive, consistent with AC20: nothing here should announce as "button" or
  "link" to a screen-reader user, because there is nothing to activate.
- **Sensitive-field badges:** each removable addition's Ã— carries
  `aria-label="Remove {name} from sensitive fields"`; the add `Input` has a
  programmatically associated `Label` (visually present, not placeholder-only).
- **Manage signing dialog:** `DialogTitle` + `DialogDescription` present per Reka
  UI's requirement; the one-time reveal's `CopyField` reuses its existing
  `aria-live="polite"` copy announcement verbatim. Focus trap and
  return-to-trigger on close are Reka UI defaults, relied on as everywhere else
  in this app.
- **"End overlap now" actions** (Screens 4, 6): standard button semantics; their
  disabled-while-in-flight state pairs with the existing `Spinner`
  `role="status"` convention.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline; every
  interactive pattern here is an already-vetted Reka UI primitive
  (`Select`, `Collapsible`, `Dialog`) composed the same way this app already
  composes them.

## Responsive Behavior
- **Verification section fields:** same `w-full sm:w-64`/`sm:w-32` conventions
  as every other enum/short field on this form â€” full-width below `sm`, fixed
  above, never the reverse.
- **Sensitive-field badges** wrap naturally in a `flex flex-wrap` row at any
  width; the Add input/button pair goes full-width below `sm` matching the
  form's existing field convention.
- **Destination credential disclosure:** the `Collapsible` content stacks its two
  fields vertically at any width (it already sits inside a narrow bordered row);
  no bespoke breakpoint handling needed.
- **Manage signing dialog:** Reka UI's default responsive `Dialog` sizing, same
  as `ReplayDialog.vue` â€” no bespoke handling; the one-time `CopyField` wraps its
  input/button pair exactly as the Ingest URL card's does today.
- **Destinations table's new badges:** inline with the existing Destination cell
  content, wrapping with it inside the table's existing horizontally-scrollable
  container (no responsive stacking variant, per `docs/standards/design.md`).
- **Minimum supported width:** 360px, the standing default â€” no feature-specific
  override.

## Open Questions
None blocking this spec's approval. Four flagged, reversible design-level calls
for the Product Manager's design-gate attention (each independently reversible,
matching the `design-06`/`design-07`/`design-08` precedent for flagging
non-blocking calls), plus one technical note folded for the Principal Engineer
rather than raised as a separate question document (mirroring how design-06
folded its own reveal-mechanism note into Q-06-03):

1. **Verification section placement â€” after Processing, before Retry policy**
   (Screen 1). Read from the pipeline order (verification gates capture, which
   precedes anything retry policy governs) rather than stated anywhere in the
   PRD. If the Product Manager reads the UX Direction as calling for a different
   position, this is a same-shaped, low-risk move.
2. **Destination credential's Collapsible default-expand rule** (Screen 3): open
   by default only when already set, collapsed otherwise. A density/legibility
   trade-off, not PRD-stated; reversible without touching anything else in this
   spec.
3. **Destinations-table status badges, not new columns** (Screen 5): `Credential`
   and `Signed` render as inline badges beside the existing Destination cell
   rather than as two new table columns, to avoid crowding an already-dense
   table. If the Product Manager judges these deserve first-class column billing
   (matching how #11 gave delivery/attempt success their own columns), the swap
   is additive and independently reversible.
4. **The one-time signing-secret reveal permits `Esc`/overlay-click dismissal**
   (Screen 6, state 2) rather than forcing **Done** as the only exit. Reka UI's
   default dismissal is relied on everywhere else in this app; the secret has
   already been fully shown by the time this sub-state renders, so an accidental
   dismissal costs the member nothing they hadn't already had a chance to copy.
   If the Product Manager judges the UX Direction's "optimise for the member
   actually captures it" priority calls for suppressing default dismissal here
   specifically, that is a self-contained, low-risk tightening.

**One implementation-level note for the Principal Engineer** (not a UX
ambiguity â€” a technical "how," folded rather than raised as a new question
document): the exact data shape the revealed-payload endpoint returns for a JSON
payload with sensitive values obfuscated (Screen 7) â€” pre-rendered
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
consequential mode switch); and whether a destination's disabled signing secret
is erased from storage immediately or simply stops being applied (Flow I step 2)
â€” a storage-lifecycle detail with no user-facing difference either way, since
AC57 already forbids ever displaying it again regardless.

## Handoff
- **Inputs:** `docs/product/prd-10-sensitive-data-handling.md` (Approved, as
  amended â€” esp. `## UX Direction`, AC1â€“AC64, `## Amendment A`);
  `docs/questions/prd-10-q-10-01-outbound-destination-authentication.md`
  (RESOLVED â€” the outbound-credential shape AC30â€“AC39 render);
  `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md` (OPEN,
  Principal Engineer, technical, non-blocking for this gate â€” nothing in this
  spec depends on its answer); `docs/design/design-06-retry-replay.md`
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
  precedent Screen 1's `{tolerance}` interpolation follows) â€” current
  implementation and existing components studied for this spec.
- **Outputs:** this design spec.
- **Dependencies:** no new npm dependency, icon library, or `ui/*` primitive.
  `Input type="password"` is a new *usage* of the existing `Input` primitive, not
  an addition. `CopyField` and `Collapsible` are reused beyond their originating
  context, not added.
- **Outstanding Questions:** None blocking. Four flagged, reversible design calls
  above for the Product Manager's design-gate review; one technical transport
  note folded for the Principal Engineer, resolved at technical design rather
  than gating this one (mirroring `design-06`'s Q-06-03 precedent). Q-10-02
  remains open to the Principal Engineer, unrelated to and non-blocking for this
  spec.
- **Next Agent:** **Product Manager**, to approve this spec against PRD-10
  (design gate, delegated per `CLAUDE.md`). On approval, hands to the
  **Principal Engineer** for technical design, which also settles Q-10-02 and
  the transport note above.
