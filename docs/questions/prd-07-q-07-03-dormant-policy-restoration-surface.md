# Question Q-07-03: How does a preserved retry policy reach an upgrade save, given AC14(b)'s no-dormant-values rule?

- **Status:** OPEN
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
- **Answered By:**
- **Answered:**

*(To be completed by the Product Manager.)*
