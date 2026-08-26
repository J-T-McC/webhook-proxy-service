# Question Q-07-03: How does a preserved retry policy reach an upgrade save, given AC14(b)'s no-dormant-values rule?

- **Status:** **RESOLVED** *(Product Manager, 2026-08-25 — **Option A**. PRD-07 amended in
  place: see § Answer and `docs/product/prd-07-enhanced-mode-toggle.md` § **Amendment A**.)*
- **Raised by:** Principal Engineer *(discovered while resolving Q-07-02; the mechanism is
  mine, but the clause that has to give is PRD text and is not mine to reinterpret)*
- **Owner (must answer):** **Product Manager** *(requirement scope — which of two PRD-07
  clauses binds. The Designer then renders the answer in design-07; see § Downstream.)*
- **Raised:** 2026-08-25
- **Gates:** **Non-blocking** for ADR-018 and for most of #7's technical design. **Blocks**
  (a) the persistence/presentation section of `plan-07` and (b) the Mode-control flow in
  `design-07` — the Designer needs the answer to specify what the Edit form shows and does when
  a member switches a Simple proxy back to Enhanced.
- **Source:** `docs/product/prd-07-enhanced-mode-toggle.md` AC12, AC14 (lead sentence + (b)),
  AC16; `docs/questions/prd-07-q-07-01-mode-switch-consequences.md` answer (b) and its
  consequences (1) and (3); `docs/design/design-06-retry-replay.md` Flow F;
  `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md` Decision 3/4;
  code as merged in PR #8 — `app/Http/Resources/ProxyResource.php:49-50`,
  `resources/js/pages/proxies/ProxyForm.vue:58-93,128-144`,
  `app/Http/Requests/UpdateProxyRequest.php:54-55`.

## Context
Everything else in AC14 is mechanically settled and recorded in Q-07-02 and ADR-018:

- **(a) Simple always resolves the system default** — solved at resolution time; a dormant value
  cannot govern behaviour. No ambiguity.
- **Preservation on a downgrade** — solved by *not writing* the two columns on a `mode = simple`
  save. No ambiguity.
- **(b) no dormant value on Show/Index/event/delivery surfaces** — solved by emitting the
  effective (resolved) value or `null`. No ambiguity.

One step does not close: **the upgrade save**. AC14's lead sentence requires the saved policy to
be "in force again with its previous values … **without the member re-entering anything**".
AC14(b) requires that while the proxy is Simple, "no read surface — Show, index, **form**, event
or delivery surface, **or any response shaped for one** — presents its persisted retry-policy
values". The Edit form is the only place an upgrade is performed, and it is named in both.

Concretely, with the code as merged: `ProxyController::edit()` hands `ProxyResource` to the
form, which seeds its state from those props (`ProxyForm.vue:64-65`); the Flow F watcher clears
the retry fields only on Enhanced → Simple (`:88-93`); and the submit transform sends `null` for
an empty field (`:136-143`). So:

- If the props **do** carry the preserved values, a member who toggles Mode to Enhanced sees the
  preserved values in the revealed fields, can adjust them, and saves — restoration happens by
  round-trip, and the form is truthful about what will be in force. But the values were shipped
  in the Edit response of a proxy that is currently Simple.
- If the props **do not** carry them (the literal reading of AC14(b)), the revealed fields show
  the "System default" sentinel and the save submits `null` — which, under the normal meaning of
  `null` ("use the system default", PRD-06 AC2), **overwrites the preserved values with NULL**.
  Restoration is then destroyed by the very save that performs the upgrade, unless the server
  ignores what the form submitted on an upgrade — in which case the form told the member
  "System default" and the system did something else, which AC12 forbids.

This is not a design preference; the two clauses cannot both hold literally on one save. The
Principal Engineer will not pick which PRD clause yields.

## Question
**Which reading binds on the Edit form's retry fields for a proxy that is currently Simple and
carries a dormant policy?**

- **Option A — the Edit form pre-fills the preserved values; AC14(b) is scoped to surfaces that
  present a value *as though it applied* (Principal Engineer's recommendation).** The Edit form
  is a **write** surface describing a *prospective* configuration, not a read surface asserting
  what is in force. Mechanically: `ProxyResource` stops emitting the raw columns for every read
  surface (Show, Index, and anything shaped from it), and the raw values reach only the Edit
  page's form prop. The retry section stays hidden while Mode = Simple and reveals the preserved
  values when the member selects Enhanced; the client nulls both fields on submit whenever
  `mode === 'simple'`, so `prohibited_if:mode,simple` still holds and a Simple save can never
  change them. *Cost:* the preserved values are present in the Edit page's props while the proxy
  is Simple — a literal breach of AC14(b)'s "form … or any response shaped for one", which this
  option asks you to scope. *Gain:* AC14 is satisfied exactly as written, the member can adjust
  the policy in the same save as the upgrade, the form never claims anything untrue, and the
  server keeps one simple write rule.
- **Option B — the props never carry them; restoration happens server-side on the upgrade
  save.** `ProxyResource` never emits the raw columns while Simple. On a Simple → Enhanced save
  the server **ignores** any submitted retry values and restores the dormant ones. To keep AC12
  true, design-07 must then replace the retry fields on that one save with a statement of what
  will happen (e.g. "this proxy has a saved retry configuration; it will apply again when you
  save — you can change it afterwards"), and the member needs a **second** save to change the
  policy. *Cost:* an upgrade-and-tune becomes two saves; new copy and a new form state for the
  Designer; the "ignore what was submitted" rule is a wart in the write path. *Gain:* AC14(b)
  holds to the letter — a dormant value never leaves the server.
- **Option C — something else you specify** (including narrowing AC14's restoration promise,
  which would reopen the Owner's Q-07-01(b) ruling and should be routed accordingly).

Two things this question does **not** ask and must not be read as reopening: whether a dormant
policy may govern behaviour (it may not — settled, AC14(a)/ADR-018), and whether Show/Index/event
surfaces may display dormant values (they may not — settled, AC14(b), unaffected by either
option).

## Impact if unresolved
`plan-07` can specify the resolution gate, the composition gate, the switch-safety guarantees and
the don't-write-on-simple rule, but must leave the Edit-form prop shape and the upgrade write
rule in draft — the two sections that decide whether AC14's restoration promise is actually
delivered. `design-07` cannot finalise the Mode control's behaviour on the Edit form, because
what the retry fields show at the moment of an upgrade differs entirely between the options.
No other PRD-07 acceptance criterion is affected.

## Downstream
Whichever option is chosen, `design-07` renders it: Option A needs no new copy (the revealed
fields carry the preserved values and design-06 Flow F is untouched — its clearing rule governs
the Enhanced → Simple direction only); Option B needs a new in-form state and its copy. The
answer also fixes one line of `plan-07`'s write rule and one line of `ProxyResource`'s shape.

## Answer
- **Answered By:** Product Manager *(as the Project Owner's proxy for requirement scope,
  per `CLAUDE.md`; no new business decision is taken here — see § Why this is not the
  Owner's to re-decide)*
- **Answered:** 2026-08-25

### Ruling — **Option A.** The Edit form pre-fills the preserved values; AC14(b) binds **read** surfaces.

AC14's lead sentence binds as written: a preserved policy is **in force again with its
previous values, without the member re-entering anything**, on the single save that
performs the upgrade. AC14(b) is **scoped** — it governs every surface that presents a
value **as the policy in force**, and the proxy **edit form** is not one of them. PRD-07
is amended in place to say so (**Amendment A**); nothing else in AC14 moves.

**What now binds, as requirements — the mechanism remains the Principal Engineer's:**

1. **Read surfaces: unchanged, and still absolute.** While a proxy is Simple, no surface
   that states what governs that proxy — Show, Index, the events and delivery surfaces,
   and any response shaped for one of them — may carry or present a persisted
   retry-policy value. Those surfaces present the policy **actually in force**: the
   system default. This is AC14(b) as ruled by the Owner (Q-07-01(b) consequence 1) and
   as already bound by ADR-018 Decision 4. **Not narrowed by one word.**
2. **The edit form is a write surface, and its subject is the prospective configuration.**
   The proxy create/edit form describes *what will be true if you save this*, not what is
   true now. It may therefore receive the proxy's persisted retry values irrespective of
   the proxy's current mode, so that an upgrade can restore them.
3. **While Mode reads Simple in the form, no retry-policy value may be rendered.** The
   retry section stays absent, exactly as `design-06` Flow F already specifies. A dormant
   value that is present in the page's data but never rendered presents nothing, and
   presenting is what AC14(b) forbids.
4. **When the member selects Enhanced in the form, the preserved values are shown, and
   showing them is truthful.** At that moment they *are* what will govern the proxy on
   save — precisely the AC12 standard ("what is displayed is what is actually in force"),
   read forward one save as a write surface requires. The member may accept them or tune
   them **in the same save** as the upgrade.
5. **A save made with Mode = Simple can never change the persisted values** — it neither
   overwrites nor clears them. Preservation is a property of the save, not of the form's
   good behaviour.
6. **The carve-out is exactly one payload and no more.** Only the create/edit form's own
   data may carry a dormant value, and only for a member who already holds the update
   permission AC5 requires — the one person who could set those values anyway. Any other
   response that grows a dormant retry value is a breach of AC14(b), not an extension of
   this ruling.
7. **Nothing here touches behaviour.** AC14(a) and ADR-018 are untouched: a Simple proxy
   resolves the fixed system default regardless of what its columns hold, and a dormant
   value governs nothing, ever. This ruling is about who may see a value and when, not
   about what obeys it.

### Rationale

- **AC14(b)'s "form" was never the Owner's word.** The Owner's Q-07-01(b) ruling states
  the constraint as: *"The Show page and any read surface **must not** present them while
  the proxy is simple."* The enumeration "Show, index, **form**, event or delivery
  surface, **or any response shaped for one**" is the Product Manager's rendering of that
  ruling into AC14(b) — and it over-reached, sweeping a write surface into a rule the
  Owner wrote about read surfaces. Correcting the PM's own rendering is squarely the PM's
  to do; it reverses no Owner decision. That is why this is Option A and not Option C.
- **Option A is the only option that delivers the Owner's ruling; Option B delivers its
  wording while defeating its purpose.** The Owner chose preservation over the PM's
  recommendation to discard, for a stated reason: *"an accidental downgrade must not
  silently destroy tuned configuration, and the setting stays as reversible in effect as
  it is in appearance."* Under Option A a member re-upgrades, sees their old values, and
  is done in one save. Under Option B the member sees "System default", is told in new
  copy that something they cannot see will come back, saves, and must then save a second
  time to change it — restoration by assurance rather than by sight. The setting is then
  reversible in effect but *not* in appearance, which is the half of the Owner's sentence
  Option B gives up.
- **Option B endangers AC12; Option A does not.** Option B requires the server to ignore
  what the form submitted on the upgrade save. The form would show a value ("System
  default") that is not what the save produces — the form telling the member one thing
  while the system does another is the exact failure AC12 exists to prevent. Answering an
  AC12/AC14(b) tension by creating a fresh AC12 breach is not a resolution.
- **What AC14(b) protects is not endangered by Option A.** The harm the clause exists to
  prevent is a member believing a value governs their proxy when it does not — reading
  "8 attempts" on a Simple proxy and trusting it. Under Option A no surface, at any
  moment, presents a value as though it applied: while Simple, nothing is rendered; once
  Enhanced is selected, the value shown is the one that will apply. The clause's purpose
  survives intact; only its accidental over-reach into an unrendered form payload is
  removed.
- **The cost is named and accepted.** A Simple proxy's preserved values travel in the
  edit form's data. They are never displayed while the form reads Simple, they reach only
  a member with the update permission, and the Principal Engineer assessed the exposure
  as security-neutral (Q-07-02(5): two clamped, inert scalars, no secret, payload, or
  header). That is a smaller cost than a two-save upgrade and a form that misstates its
  own save.
- **The simpler write path is a genuine tiebreaker, not the reason.** One write rule —
  Simple saves omit the columns, Enhanced saves write what was submitted — is what
  ADR-018 Decision 3 already records. Option A needs nothing added to it; Option B needs
  an "ignore the submission" exception. Had the requirement pointed the other way this
  would not have decided it, but it does not point the other way.

### Why this is not the Owner's to re-decide
Option C — narrowing AC14's restoration promise — would reopen the Owner's Q-07-01(b)
ruling and is not the Product Manager's to take. It is **not** taken. The restoration
promise stands exactly as the Owner ruled it: kept on downgrade, inert while Simple,
in force again with its previous values on a return to Enhanced, without re-entry. This
answer changes only the Product Manager's own enumeration of where a dormant value may
not be *presented* — an over-broad rendering of the Owner's words, corrected so the
Owner's ruling can actually be delivered. No requirement the Owner stated is narrowed,
and no requirement the Owner did not state is invented.

### Downstream
- **PRD-07** amended in place — **Amendment A**, `docs/product/prd-07-enhanced-mode-toggle.md`.
  Status stays **Approved**; AC14(b) and AC12's closing sentence are amended and tagged
  **(A)**; no criterion is renumbered, added, or removed.
- **`design-07`** — the spec's Screen 1 restore-on-upgrade behaviour (Flow B step 3, and
  the *Interaction — dormant-value restore on upgrade* block) is **correct as written**
  and its ⚠ Q-07-02 dependency (2) is discharged: the edit payload does carry the
  persisted values. Its Screen 2 dependency is discharged the other way — under ADR-018
  Decision 4 the **Show** payload must not carry them, so the client-side gate specified
  there is not the enforcement point. Returned to the Designer as a required correction
  at the design gate (see the design-07 approval note).
- **`plan-07`** — the Edit-form data shape and the upgrade write rule are unblocked.
  `RetryPolicy`'s gate, `prohibited_if:mode,simple`, and the don't-write-on-Simple rule
  are unchanged by this answer, as ADR-018 Decision 3 anticipated. Worth pinning by test:
  a Simple proxy carrying a dormant policy, opened in Edit and switched to Enhanced,
  saves with its previous values and no re-entry (AC14 lead sentence); and no non-edit
  response carries a dormant value (AC14(b)).
