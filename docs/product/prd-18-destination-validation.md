# PRD: Destination validation

- **Status:** **Approved by the Project Owner, 2026-08-31.** Three things were put to the Owner
  specifically rather than riding along with an ordinary requirements sign-off. All three were
  approved as written:
  1. **§ Consequences for approved documents** — this PRD **narrows the fan-out contract** stated at
     roadmap **#1** ("an incoming webhook posted to the ingest URL is delivered … to every
     configured destination"). Under AC8 delivery goes to every **validated** destination. This is
     the one place where approving this PRD changes the meaning of an already-approved one.
  2. **AC30 grandfathers live data.** Every destination that exists at ship becomes validated by a
     one-time migration. The alternative — forcing revalidation — stops delivery on destinations
     teams already depend on. The Product Manager settled it (see § Product Manager rulings), but it
     is a decision taken against production data and the Owner should see it named.
  3. **This is a security-posture change and a data-model change**, which is why it runs in the
     pipeline lane rather than as a brief.
- **Author:** Product Manager
- **Date:** 2026-08-31
- **Approved by / date:** Project Owner, 2026-08-31
- **Backlog item:** Roadmap **#18**, added 2026-08-31 by the Project Owner. **#16 and #17 are
  consumed** — PRD-16 was withdrawn and design-17 shipped — so this item is **#18**.
- **Depends on:** **#1 (Done)** — the fan-out delivery this gates, and the destination record it
  attaches to. **#2 (Done)** — the permission model AC44 reuses. **#4 (Done)** — the queued dispatch
  that carries one of the two enforcement points (AC8). **#6 (Done)** — retry and replay, both of
  which AC9 gates, and replay is the recovery path AC10 names. **#10 (Done)** — the stored
  destination credential AC17 deliberately does not send. **#15 (Done)** — the pause enforcement
  points AC8 mirrors, and the pause semantics AC36 distinguishes itself from.
- **A note on citing #15.** #15 shipped in the brief lane. `docs/briefs/pause-and-resume-dispatch.md`
  is the authority for shipped pause behaviour; `docs/product/prd-15-pause-and-resume-dispatch.md`
  is retained as **superseded, unmaintained background** (`docs/status.md`, row 15). Where this PRD
  cites a PRD-15 criterion number it is citing that background for its reasoning, not asserting it
  as shipped behaviour. The Principal Engineer should read the brief.
- **Build-ahead status:** written against shipped code only. **#8 and #12 are on hold indefinitely
  and #9 is cancelled** (Owner, 2026-08-29), so no criterion here depends on any of them (AC45).
- **Next gate: the Designer.** `## UX Direction` is present, so a PM-approved `design-18` is a
  prerequisite for Technical Design.

## Feature
A destination must **prove it is willing to receive traffic** before the proxy will fan out to it.
The system dispatches a validation challenge to the destination's own URL; that challenge carries a
link; somebody must open the link and confirm. Until then the destination is not validated and the
proxy does not deliver to it.

## Definitions
Fixed vocabulary. Every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Validation state** | A per-destination state with exactly four values — **Unvalidated**, **Pending**, **Expired**, **Validated** (AC1). A property of the destination, not of a proxy, an event or a delivery. |
| **Validated** | The only state in which the proxy delivers to the destination (AC8). Reached only by a completed approval through a live challenge link (AC3). |
| **Validation challenge** | One outbound request the system makes to the destination's own URL, carrying the validation link and nothing else of substance (AC17). Not a delivery, not a retry, not a replay — therefore **not a Dispatch** as #15 defines the term (AC36). |
| **Validation link** | The URL carried in the challenge. A **bearer credential**: possession of it is the sole authority to approve (AC26). Unguessable, single-use, expiring, never logged, never shown inside the product (AC22, AC23, AC24, AC28). |
| **Approval** | The act that moves a destination to Validated: opening the link, landing on a confirmation page, and submitting an explicit confirmation from it (AC25). Opening the link alone approves nothing. |
| **Validation send** | One dispatch of a validation challenge, whether triggered by the Validate action or automatically on store (AC14, AC15). Subject to the address-range refusal and the rate limit (AC20, AC21). |
| **Skip** | What happens to work aimed at a non-Validated destination: it is resolved without dispatching and never delivered later (AC10). Deliberately **not** the hold that pause performs. |

## Problem

1. **The product will send an HTTP request to any URL a user types, with no evidence that the
   owner of that URL wants it.** A destination today is a `url` and an `http_method` and nothing
   else. Anyone with an account can point a proxy at any host on the internet and have the product
   deliver traffic to it on their behalf. The product becomes the attacker's user agent: the
   requests carry the product's source address and reputation, not the user's.
2. **Fan-out multiplies the problem by design.** One ingested webhook becomes N outbound requests,
   and the product retries failures (#6). A proxy configured with the same victim URL on several
   destinations is a traffic amplifier that a user builds with the supported interface, using no
   feature in an unintended way.
3. **A typo is indistinguishable from an attack, and both are silent.** A destination pointed at
   the wrong host produces no signal to anyone: not to the team, who see deliveries leaving, and not
   to the host receiving traffic it never asked for. Nothing in the product asks the receiving end
   whether it consented.
4. **Nothing today ties a destination to a human who can vouch for it.** Team permissions govern
   who may configure a destination (#2), which is a statement about the *sender*. No mechanism makes
   a statement about the *receiver*. Consent has to come from the receiving side or it is not
   consent.

## Goals
- No destination receives webhook traffic until somebody on the receiving side has affirmatively
  agreed to it.
- The mechanism that establishes consent does not itself become the abuse vector it exists to close:
  the challenge is fixed and small, follows no redirects, refuses internal address space, and is
  hard rate-limited per destination and per team.
- Consent is proved by the receiving side, never asserted by the sending side. No member can
  validate their own destination from inside the product.
- Enforcement is a property of the delivery path, not of the interface. A user who never opens the
  product still cannot get traffic to an unvalidated destination.
- Teams already using the product are not interrupted by the release, and the exemption that buys
  that decays rather than persisting (AC30).
- A destination waiting on validation reads as *waiting on somebody*, never as broken or failing.

## Users
- **Team member** — adds destinations, triggers validation, and waits on somebody else to approve.
  Is often not the person who can approve.
- **Team Owner / Admin** — the same, without the Member ownership limit on configuration changes
  (Q-02-01 / ADR-009 Amendment A2.2).
- **Destination operator** — the person who runs the receiving endpoint and opens the link. **May
  have no account and no team membership** (AC26). This PRD introduces them as a user of the
  product; they were not one before.
- **Any host on the internet** — the party this feature protects. Not a user of the product and has
  no relationship with it; the feature exists so the product does not act against them.
- **The product (system)** — holds the validation state and must honour it at every point that can
  start work on a destination, not only where a member triggers something.

## User Stories
- As the operator of an endpoint, I want to be asked before a stranger's proxy starts posting to my
  URL, so that a service I never signed up for cannot be pointed at me.
- As a team member, I want to add a destination and have it ask for approval by itself, so that
  setting one up is one step and not two.
- As a team member, I want to see plainly that my destination is waiting on somebody to approve it,
  so I chase the right person instead of debugging a delivery problem that does not exist.
- As a team member whose colleague added a destination, I want to see its validation state too, so
  nobody spends an afternoon on a silence that has a one-click cause.
- As a team member, I want to correct a mistyped destination URL by editing it, so that fixing a
  typo does not mean deleting the destination and rebuilding its configuration.
- As the operator of an endpoint, I want the approval link to require a deliberate confirmation, so
  that my company's mail scanner cannot approve traffic on my behalf by fetching a link.
- As a team already running proxies, I want the release not to stop my deliveries, so that a
  security improvement is not an outage.

## UX Direction
Direction only. Screens, states, components and copy belong to the Designer (`design-18`).

**There are two audiences and they must not be designed as one.** Everything inside the product is
for the team member, who can configure but cannot approve. The confirmation page is for the
destination operator, who can approve but has no account, no context, and no other page in the
product. It must stand entirely on its own.

**What the experience optimises for, in priority order.**

1. **A destination waiting on validation must read as waiting on a person, never as failing.** The
   member's first question is always "is this broken?" and the answer must be visible without
   clicking. Validation state must not be confusable with a delivery state (PRD-06 AC16) or with a
   paused proxy — those describe traffic; this describes permission.
2. **Each state must say what happens next and who has to do it.** Four states, four different
   answers: Unvalidated has had no challenge sent; Pending is waiting on a human at the destination
   and has a deadline; Expired means nobody acted in time and a fresh challenge is needed; Validated
   is done. "Pending" that does not convey *somebody at your destination must open a link, by this
   date* has failed this requirement.
3. **The member must be able to tell "the challenge never arrived" from "nobody opened it".** These
   have completely different remedies — fix the URL, versus find the person. AC35 requires the last
   send's outcome be visible; the interface is what makes it useful.
4. **The proxy surface must not let an unvalidated destination hide.** A proxy delivering to two of
   its three destinations looks healthy from every existing view. Where a proxy is presented it must
   be apparent that not all of its destinations are receiving traffic (AC33).
5. **The confirmation page is the single most important screen in this feature and it is seen by
   someone who has never heard of the product.** It must say what is being approved, who is asking,
   and what will happen — in one screen, with one deliberate action, and no account required. It
   must not look like a phishing page, and it must not disclose anything about the team beyond its
   name (AC27). A spent, voided or expired link lands here too and must be told so plainly, as an
   ordinary outcome rather than as the holder's error.
6. **Editing a URL must tell the member what it costs before they save.** Saving a new URL stops
   delivery to that destination until it is validated again (AC5). That is a consequence the member
   cannot undo by pressing back, so it is stated before the save, not discovered afterwards.
7. **Validate is a button, not a wizard.** The action is one click and the result is immediate
   feedback about what was sent and what is now expected of whom. When the rate limit blocks it
   (AC21), the affordance is unavailable **with the reason and the time it clears given** — the same
   treatment #15 gives an unavailable replay, never a silent no-op.

**Not the Designer's to decide, because they are ruled here:** that the validation link is never
displayed anywhere inside the product (AC24); that opening the link cannot approve and a deliberate
confirmation is required (AC25); that approval requires no login (AC26); that a challenge is
dispatched automatically on create and on URL change (AC15); that URL edits are permitted rather
than blocked (AC5); that validation state is visible to every member who can view the destination
and not only to its creator (AC31); and that there is no manual override, anywhere, by anyone
(AC3, AC6).

## Acceptance Criteria

> **Numbering is append-only**, following the house rule set by PRD-05 and PRD-11.

### The validation state

1. **A destination has exactly one validation state at all times, from a fixed set of four:**
   Unvalidated, Pending, Expired, Validated. The names are fixed vocabulary and every criterion
   below uses them exactly.
2. **A destination is created Unvalidated** and becomes **Pending** when a validation challenge has
   been dispatched to it successfully (AC18 defines successfully).
3. **Exactly one transition produces Validated:** an approval completed through a live validation
   link (AC25). **There is no other route.** No member action inside the product, no administrative
   override, no team-owner override, no support override, and no inference from a destination
   having responded successfully to anything.
4. **Pending becomes Expired** when the challenge's lifetime (AC22) elapses without approval.
   Expired is a normal outcome, not an error and not a delivery failure. *(Whether Expired is stored
   or derived from the challenge's lifetime is the Principal Engineer's; the requirement is that a
   member can tell it apart from Unvalidated — AC34.)*
5. **Changing a destination's URL returns it to Unvalidated and voids any outstanding challenge
   immediately.** A voided link can never afterwards approve the destination. **URL edits are not
   blocked.** *(Project Owner ruling, 2026-08-31: blocking edits only pushes users to
   delete-and-recreate — the same work with a worse audit trail.)*
6. **No other transition exists.** No manual un-validation or revoke control, no expiry of a
   Validated destination, no periodic re-validation, and nothing that moves a destination backwards
   except AC5. *(A per-destination stop-delivery control is per-destination pause, ruled out at
   PRD-15 AC15. Deleting the destination is the existing way to stop traffic to one target.)*
7. **Validation state is a property of the destination and nothing else changes it.** Changing the
   proxy's mode, pausing or resuming the proxy, changing the destination's HTTP method or its
   credential, and soft-deleting and restoring the destination all leave it exactly as it was.

### Enforcement — the delivery path, not the interface

8. **The proxy does not deliver to a destination that is not Validated, and this holds at both the
   queue-check and the dispatch-gate** — the same two points where pause enforcement already holds.
   **Enforcement at only one of them does not satisfy this criterion, and a block in the interface
   alone does not satisfy it at all**, because the fan-out path is reached without the interface.
   *(Project Owner ruling, 2026-08-31. Where those two points are is named as Q-18-01 and
   deliberately not specified here.)*
9. **Every dispatch form is gated**: the original fan-out delivery, an automatic retry that comes
   due (PRD-06 AC1), and a manual replay (PRD-06 AC9). A replay to a non-Validated destination is
   **unavailable with the reason given**, in the same manner #15 makes replay unavailable while
   paused — it is not queued and it does not silently do nothing.
10. **Work for a non-Validated destination is skipped, not held.** It is resolved without
    dispatching, it does not park the work behind it, and **a destination that becomes Validated
    later does not receive events that arrived while it was not Validated.** Replay (PRD-06 AC9) is
    the existing mechanism for sending them after the fact and no new one is added. *(Holding is
    pause semantics. A per-destination hold was ruled out at PRD-15 AC15, and an unbounded hold
    would keep payloads alive past the retention window — the "a retry policy can never make a
    payload immortal" principle recorded at PRD-06 AC18 and relied on by PRD-15.)*
11. **A skip is not a delivery failure.** No delivery attempt record is created, because no attempt
    was made and ADR-003 records are per attempt. Nothing counts as a failure in #11's measures, and
    no destination is presented as failing because it is unvalidated.
12. **A proxy's other destinations are unaffected.** Fan-out to the proxy's Validated destinations
    proceeds normally, and **ingest never depends on any destination's validation state**: a webhook
    arriving at a proxy whose destinations are all unvalidated is accepted, answered under #3, and
    captured exactly as it is today. *(The zero-data-loss position the Owner stated on 2026-08-27
    and PRD-15 AC2 records.)*
13. **Configuration is not gated.** A non-Validated destination can still be viewed, edited,
    credentialled and deleted. Validation gates delivery and nothing else.

### The validation challenge

14. **An explicit Validate action is available whenever the destination is not Validated**, to any
    member who may update the destination (AC44). It dispatches a validation challenge.
15. **A challenge is also dispatched automatically on store** — when a destination is created, and
    again whenever its URL changes. *(Product Manager ruling; the Owner left this open. Reasoning in
    § Product Manager rulings.)* If the rate limit (AC21) prevents the automatic send, **the
    destination is still created or saved**, no challenge is sent, and the member is told that none
    was sent and when they can send one.
16. **At most one live challenge exists per destination.** Dispatching a new one voids any
    outstanding one immediately, and the voided link can never approve.
17. **The validation payload is fixed, small, and identical in shape for every destination and every
    send.** It carries the validation link and the minimum needed to identify what is asking. It
    carries **no event content, no team data beyond the team name shown on the confirmation page, no
    member identity, no user-supplied content of any kind, and nothing an operator can vary.** It is
    sent to the destination's URL using the destination's configured HTTP method. **It does not
    carry the destination's stored credential** (#10), because sending a stored secret to a URL that
    has not yet proved anything is the exact class of leak this feature exists to prevent — and a
    URL edit (AC5) would otherwise make an automatic send into an exfiltration path.
18. **A send succeeds when the destination returns any HTTP response.** A non-2xx response is not a
    send failure: the request reached the host, so a human there can still find the link. A
    connection-level failure — DNS failure, refused connection, timeout, or a refusal under AC20 —
    is a send failure; the destination stays Unvalidated and the member is told why (AC35).
19. **No redirects are followed on a validation send.** A redirect response is an outcome to be
    reported, never a hop to be taken.
20. **Loopback, private, link-local and cloud-metadata addresses are refused before any request
    leaves**, whether the URL names a literal address or a hostname that resolves to one. A refusal
    is a send failure (AC18). **Consequence, stated rather than hidden:** a destination at such an
    address can never be validated and therefore never receives traffic (AC8). This costs a hosted
    deployment nothing legitimate, because such an address is not reachable from the product's hosts
    anyway — reaching one is the abuse, not the use.
21. **Validation sends are hard rate-limited, per destination and per team.** Defaults: at most
    **one send per destination per 5 minutes**, at most **10 sends per destination per rolling 24
    hours**, and at most **100 sends per team per rolling 24 hours**. Automatic sends (AC15) count
    against the same limits. When a limit is reached the member is told which one and when it
    clears. *(Figures are Product Manager defaults. They may be tightened on the Principal
    Engineer's advice; they may not be raised without the Project Owner. Without a resend limit the
    Validate button is a spam cannon aimed at any host on the internet — Owner ruling, 2026-08-31.)*

### The validation link — a bearer credential

22. **The link is unguessable, single-use, and expires 7 days after its challenge was dispatched.**
    *(7 days is a Product Manager ruling; reasoning in § Product Manager rulings.)*
23. **The link and its token never appear in any log, delivery attempt record, analytics record,
    error report or support output that this application controls.** *(Amended by Project Owner
    ruling, 2026-08-31, closing Q-18-02. As originally written this criterion said "any log" without
    qualification, which is not deliverable for a token carried in a URL: the web server access log,
    anything in front of the application, and the recipient's own infrastructure all record URLs, and
    the last of those is not a layer this project operates. The Owner ruled the residual risk
    acceptable — the link is HTTPS in transit, single-use, and 7-day-limited; the recipient holding it
    is the feature working rather than a leak; and anyone reading this application's access logs
    already has production access. The alternative, carrying the secret in the URL fragment so it is
    never transmitted to any server, was rejected because it forces a hard JavaScript dependency on
    the one page reached cold by somebody with no account, and buys nothing against a threat that
    matters. **AC24 is the property that makes this feature prove anything and is untouched.**)*
24. **The link is never displayed to any member anywhere inside the product.** The only copy in
    existence is the one that was sent to the destination. **This is the load-bearing security
    property of the whole feature**: a member who could read the link could approve their own
    destination without the destination's cooperation, and the feature would prove nothing.
25. **Opening the link approves nothing.** It lands on a confirmation page and changes no state.
    Approval requires an explicit, deliberate confirmation submitted from that page — never a plain
    retrieval of the link. *(Project Owner ruling, 2026-08-31: link scanners, mail preview fetchers
    and corporate security proxies fetch links automatically; an approve-on-load link would be
    approved by machines.)*
26. **Approving requires no account and no team membership.** The token is the sole credential. The
    approver is the destination's operator, who in the ordinary case has never heard of the product.
27. **The confirmation page discloses the minimum needed to make an informed decision:** the
    destination URL being approved, the name of the team asking, and what approving causes — that
    this proxy will begin delivering webhook traffic to that URL. It discloses **no team membership,
    no member identity, no other destination, no proxy configuration and no event content.**
28. **Single use, and repeats are safe and quiet.** After a successful approval the link is spent. A
    later opening of a spent, voided or expired link says so plainly, approves nothing, changes
    nothing, is not presented as an error the holder caused, and reveals nothing beyond AC27.
29. **Approval takes effect immediately and only forwards.** The destination becomes Validated and
    the proxy delivers to it from the next event onward. **Approval never causes an earlier event to
    be delivered** (AC10).

### Existing data

30. **Every destination that exists at ship is grandfathered to Validated by a one-time migration.**
    No team's delivery stops because of this release. **The exemption decays**: a grandfathered
    destination whose URL is later edited returns to Unvalidated under AC5 and must be validated
    like any other. *(Product Manager ruling; reasoning in § Product Manager rulings.)*

### Visibility

31. **Validation state is shown wherever a destination is presented, to every team member who can
    view it** — not only to the member who created it. Validation is a team-visible property, for
    the same reason pause is: the member who finds the silence is usually not the member who caused
    it.
32. **Validation state is distinct from every other state the product shows.** It must not be
    confusable with a delivery state — delivered, retrying, terminally failed (PRD-06 AC16) — nor
    with a paused proxy. Those describe traffic; this describes permission.
33. **A proxy with at least one non-Validated destination says so where the proxy is presented**,
    because a proxy fanning out to two of its three destinations looks healthy in every view that
    exists today.
34. **Each state tells the member what it means and what is expected of whom next** — including,
    for Pending, that somebody at the destination must open a link and by when. *(Wording and
    presentation are the Designer's; that each state carries this is not.)*
35. **The outcome of the most recent validation send is visible** — delivered, with the response
    status the destination returned, or failed, with the reason — so a member can tell "the
    challenge never arrived" from "nobody has opened it". These have different remedies.

### Relationship to pause (#15)

36. **A validation challenge is not a Dispatch as #15 defines it, and validation sends are permitted
    while a proxy is paused.** Pause stops event traffic; it does not freeze configuration, and
    preparing a destination during a maintenance window is precisely when a member needs to. A
    destination can be validated while its proxy is paused; nothing is delivered to it until the
    proxy is resumed. *(#15's definition of Dispatch covers original fan-out delivery, automatic
    retry and manual replay. A challenge is none of the three and carries no event content, so
    nothing is narrowed here — this is stated because it looks like a contradiction and is not.)*

### Scope boundaries

37. **No auto-confirm fast path in #18.** A destination that echoes the challenge token back in its
    response body is **not** validated automatically. Deferred with reasoning in § Product Manager
    rulings; a candidate for its own roadmap item.
38. **No manual revoke, no expiry of a Validated destination, no periodic re-validation** — AC6.
39. **No per-destination pause.** PRD-15 AC15 stands. Validation is a consent gate, not a
    stop-delivery control, and must not be repurposed as one.
40. **No change to the address rules for ordinary delivery.** AC20 refuses internal address space on
    **validation sends**. Ordinary dispatch is untouched; the practical effect on newly added
    destinations follows from AC8, and grandfathered destinations (AC30) keep delivering until their
    URL changes.
41. **No notifications** that a destination is awaiting validation, that a challenge is about to
    expire, or that one has expired — **#13**. **This is a real cost and it is stated, not glossed:**
    a member who does not look sees nothing until they look. The remedy belongs to #13 and is not
    designed here.
42. **No analytics for validation.** No approval-rate figure, no time-to-validate measure, and no
    new #11 measure. Skips create no records (AC11), so #11's existing measures are unchanged.
43. **No bulk validation and no bulk approval.** One destination at a time. Adding several
    destinations dispatches several challenges (AC15), each approved separately.
44. **No new permission.** Triggering a validation send is gated by the existing destination update
    permission, including the Member ownership rule (Q-02-01, ADR-009 Amendment A2.2). Approving is
    not permission-gated at all (AC26).
45. **Nothing here depends on #8, #9, #12, #13 or #14, and nothing here pre-empts them.** #8 and #12
    are on hold indefinitely and #9 is cancelled (Owner, 2026-08-29).

## Product Manager rulings

Five questions the Project Owner raised without settling, or left to the Product Manager as proxy.
Each is settled here with its reasoning, so the reasoning is not lost and is not re-litigated.

- **Automatic dispatch on store — yes, on create and on URL change (AC15).** A destination that is
  created but not usable, with no prompt, is a trap: the member's next action is to wonder why
  nothing is being delivered. Automatic dispatch makes the ordinary path one step instead of two,
  and makes the URL-edit path symmetrical with the create path so a member never has to remember a
  second action. The abuse concern that argues for a manual-only send is not answered by manual-only
  anyway — the Validate button sends to an arbitrary URL either way. It is answered by AC17
  (fixed, small payload), AC19 (no redirects), AC20 (address refusal) and AC21 (rate limit), all of
  which govern automatic and manual sends identically.
- **Existing destinations — grandfather to Validated (AC30).** There is live data. Forcing
  revalidation would stop delivery on every destination in production until somebody at each one
  opened a link, turning a security improvement into an outage caused by the release. Existing
  destinations have also already been receiving traffic without complaint, which is weak but real
  evidence of willingness. The feature's purpose is to gate outbound traffic to targets the product
  has never confirmed — a target already receiving is not that. The exemption is not permanent: it
  decays as URLs are edited (AC5), and it is a one-time migration rather than a standing rule, so it
  sets no precedent for any later destination.
- **Challenge lifetime — 7 days, and a fresh challenge is the only remedy (AC22, AC4, AC14).** The
  person who must open the link is usually not the person who triggered the send, and often not on
  the same team or in the same company. Routing a request to that person can take days; a 24-hour
  window would expire most of the time for organisational reasons rather than security ones, and
  every expiry costs a resend to an external host — the traffic AC21 exists to limit. Seven days
  spans a weekend and an absence. The risk it buys is bounded by the link being single-use,
  unguessable, never logged (AC23), never displayed in the product (AC24), voided by any newer
  challenge (AC16), and voided by any URL change (AC5). On expiry the destination shows Expired and
  the member sends a fresh challenge with the Validate action; there is no separate resend concept
  and no way to extend a challenge in place.
- **Auto-confirm fast path — deferred, not in #18 (AC37).** It is genuinely attractive and it keeps
  the security property, but it is a second approval path with its own surface: what counts as an
  echo, how much of a response body is read, what happens on a partial or malformed match, and what
  a destination that echoes by accident implies. It also only helps destinations whose operator
  controls the receiving code, so the manual path in AC25–AC29 has to exist regardless — the fast
  path is pure addition, never a replacement. Adding it later moves a destination from Pending to
  Validated by one more route and changes no state, no enforcement point and no other criterion, so
  deferring costs nothing structural. Shipping it now would double the security surface of the
  release that introduces the security posture.
- **The states — four, and no more (AC1, AC4, AC6).** Unvalidated and Validated are implied by the
  Owner's description. **Pending earns its place** because "the challenge is out and somebody has to
  act" is the state a member spends the most time in and the only one where the next action belongs
  to someone else; collapsing it into Unvalidated would hide who is being waited on. **Expired earns
  its place** because it carries diagnostic information Unvalidated does not: the challenge did
  leave, the host did answer, and no human acted in time. That distinguishes an organisational
  problem from a configuration problem, and they have different remedies. **Revoked does not earn
  its place**: nothing in the Owner's description asks for one, and a control that returns a working
  destination to unvalidated is per-destination pause under another name — explicitly ruled out at
  PRD-15 AC15 (AC39). A **send-failed** state does not earn its place either: a failed send is an
  outcome of an action, not a condition of the destination, and AC35 makes it visible without
  inventing a fifth state.

## Consequences for approved documents

Recorded so nothing is narrowed silently. **No document is edited by this PRD.** The change takes
effect only if the Owner approves it.

- **The fan-out contract is narrowed.** Roadmap **#1** states that an incoming webhook "is delivered
  over HTTP(S) … to every configured destination", and PRD-01 carries the corresponding criteria.
  Under **AC8** delivery goes to every **Validated** destination; a configured destination that is
  not validated is skipped (AC10). This is the intended change and it is the reason #18 is a
  security-posture change rather than an addition.
- **Which PRD-01 criteria are affected is for the technical design to identify** and to carry
  forward as a list; it is not asserted here from memory. If any of them turns out to say something
  this PRD cannot compose with, that returns to the Product Manager as a requirement question, not
  as a silent design change.
- **The zero-data-loss position is untouched.** Ingest is unaffected (AC12): a webhook is accepted,
  answered under #3, and captured no matter what state any destination is in. #18 never rejects an
  inbound request.
- **Retention is untouched.** #18 adds no hold, no extension and no exemption. Because skipped work
  is resolved rather than held (AC10), a destination that is never validated cannot keep a payload
  alive — which is what makes AC10's skip-not-hold ruling load-bearing rather than a detail.
- **#15's pause semantics are untouched** (AC36), **#6's retry policy, terminal state and replay
  semantics are untouched** (AC9 changes only what they are permitted to target), **#11's measures
  are untouched** (AC42), and **#2's permission model gains nothing** (AC44).

## Out of Scope
Each names where it goes, or why nothing owns it yet.

- **Auto-confirm by token echo in the destination's response** — AC37. Deferred deliberately; a
  candidate for its own roadmap item, not a gap.
- **Manual revoke, validation expiry, and periodic re-validation** — AC38.
- **Per-destination pause** — AC39; PRD-15 AC15.
- **Applying the internal-address refusal to ordinary delivery** — AC40. Not ruled by the Owner and
  not invented here; it would change shipped behaviour for grandfathered destinations and is its own
  decision.
- **Notifying anybody about a destination awaiting or failing validation** — AC41; **#13**.
- **Any analytics view of validation** — AC42; **#11**.
- **Bulk validation or bulk approval** — AC43.
- **Verifying that a destination can handle real traffic** — validation proves consent, not
  capability. A validated destination that later starts failing is #6's problem, unchanged.
- **Any new permission, and any account requirement for the approver** — AC44, AC26.
- **Anything depending on payload mapping (#8), multi-format ingestion (#9), change detection (#12),
  notifications (#13) or test payloads (#14)** — AC45.

## Open Questions

- **Q-18-01 (Principal Engineer — technical) — OPEN, raised by this PRD. Gates technical design;
  non-blocking for requirement approval.** Doc:
  `docs/questions/prd-18-q-18-01-validation-enforcement-and-send-safety.md`. Four items: where the
  queue-check and dispatch-gate enforcement points are and how both are bound (AC8); how a skipped
  unit of work is resolved without parking the FIFO queue behind it (AC10 — the failure mode
  ADR-019 already identified in a different form); how the internal-address refusal is made safe
  against a hostname that resolves differently between check and send (AC20); and how the link is
  kept out of every log when it necessarily travels as a URL (AC23). **These are named as requiring
  technical design and are deliberately not specified here.** If any finding contradicts a criterion
  in this PRD, that returns to the Product Manager as a requirement question.
- **No question is owed to the Project Owner.** Every product decision in this PRD is either an
  Owner ruling of 2026-08-31 recorded verbatim in substance, or a Product Manager call recorded with
  its reasoning in § Product Manager rulings. The items that need the Owner are not questions but
  ratifications, listed in the Status block.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (**#1** — the fan-out contract AC8 narrows; **#18** to be
  added on approval) · `docs/status.md` (feature table — #15 shipped in the brief lane; #8 and #12
  on hold, #9 cancelled) · `docs/briefs/pause-and-resume-dispatch.md` (the shipped pause behaviour
  AC8 mirrors and AC36 distinguishes itself from) ·
  `docs/product/prd-15-pause-and-resume-dispatch.md` (**superseded background** — AC2, AC3, AC14,
  AC15 cited for reasoning only) · `docs/product/prd-06-retry-replay.md` (AC1, AC9, AC16, AC18 —
  retry, replay, delivery states, the payload-immortality principle) ·
  `docs/product/prd-02-role-based-collaboration.md` + `docs/architecture/adr-009-proxy-permission-mechanism.md`
  (the permission AC44 reuses) · `docs/architecture/adr-003-delivery-attempt-records-and-events.md`
  (the per-attempt records AC11 relies on) · `docs/product/prd-10-sensitive-data-handling.md` (the
  stored destination credential AC17 declines to send) ·
  `database/migrations/2026_07_30_000002_create_destinations_table.php` and
  `app/Models/Destination.php` (the destination as it exists today) ·
  `docs/standards/documentation.md`.
- **Outputs:** this PRD · `docs/questions/prd-18-q-18-01-validation-enforcement-and-send-safety.md`
  (**OPEN**, Principal Engineer).
- **Dependencies:** **#1, #2, #4, #6, #10, #15 — all Done.** **#18 does not depend on #8, #9, #12,
  #13 or #14, and must not pre-empt them.**
- **Outstanding Questions:** **Q-18-01** — Principal Engineer, technical, non-blocking for
  requirement approval.
- **Next Agent:** **Designer.** `## UX Direction` is present, so under the mechanical routing rule a
  PM-approved `design-18` is a prerequisite for Technical Design — no exceptions. **The Designer
  must not start before the Project Owner has approved this PRD**, because the confirmation page
  (AC25–AC28) is seen by a user who has no other contact with the product, and its content depends
  on the Owner ratifying the fan-out narrowing and the grandfathering of live data.
