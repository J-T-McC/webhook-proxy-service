# ADR-008: Inbound header-forwarding policy to destinations (safe allowlist)

- **Status:** Accepted (Project Owner, 2026-07-30) — **two properties amended by ADR-023**
  (Accepted, ratified by the Project Owner's approval of PRD-10, 2026-08-27). The decision itself —
  the safe allowlist — stands whole and operative; see the inline pointers below and ADR-023
  § *Positions amended*. **This is an amendment, not a supersession.**
  - **Two further properties are proposed for supersession by ADR-025** (`Proposed`, 2026-08-28,
    pending Project Owner approval) — the strip of the five provider signature header names, and the
    § *Reasoning* sentence that groups those signatures with `Cookie` and `Authorization`. The
    allowlist policy is again untouched, and every other entry in the strip list stands verbatim on
    grounds ADR-025 restates in full. See the inline pointers at **P3** and **P4** below and ADR-025
    § *Positions superseded*.
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** walking-skeleton (Roadmap #1 / PRD-01 AC7/AC8); referenced by
  `docs/plans/plan-01-walking-skeleton.md` (ingest → delivery)

## Question
PRD-01 AC7/AC8 require the received webhook's **body** to be replayed to each
destination, but do not specify which inbound **request headers** reach those
destinations. `DeliveryUnit::forwardHeaders()` must decide what the fan-out sends.
Forwarding too much leaks the sender's credentials/host state and can confuse or
compromise destinations; forwarding too little breaks payload interpretation (e.g.
`Content-Type`). The Project Owner has ruled on the policy; this ADR records it.

## Decision
Forward the **inbound request headers to each destination EXCEPT a stripped
sensitive set** (a "safe allowlist" — deny-list a known-dangerous set, forward the
remainder). At item #1, `DeliveryUnit::forwardHeaders()`:

- **Strips (never forwards):**
  - `Host` — the destination's own host must be used, never the inbound one (a
    forwarded `Host` is a request-smuggling / routing hazard; consistent with the
    ADR-006 Host-header guard).
  - **Hop-by-hop headers** — `Connection`, `Keep-Alive`, `Proxy-Authenticate`,
    `Proxy-Authorization`, `TE`, `Trailer`, `Transfer-Encoding`, `Upgrade` (RFC
    7230 §6.1), plus `Content-Length` (recomputed by the outbound HTTP client for
    the body actually sent).
  - `Cookie` — inbound session/cookie state must not cross to destinations.
  - Inbound `Authorization` — the sender's credential to *us* is not the
    destination's credential; forwarding it leaks a secret to third parties.
  - Inbound **webhook signature / verification headers** — provider signatures
    (e.g. `Stripe-Signature`, `X-Hub-Signature` / `X-Hub-Signature-256`,
    `X-Signature`, `X-Webhook-Signature` and equivalents) are computed over the
    original body for the original recipient; they are meaningless-to-misleading at
    a destination and can leak verification material. Outbound signing is item #10,
    not #1.

    > **[P3 — PROPOSED supersession by ADR-025 (`Proposed`, 2026-08-28, pending Owner approval).]**
    > The five provider signature header names are removed from `DeliveryUnit::STRIPPED_HEADERS` and
    > forwarded, so a recipient holding the provider's secret can verify the original signature with
    > that provider's own library. Two premises in this bullet are corrected there: a provider
    > signature header carries an HMAC **digest**, not key material, so it discloses nothing; and at
    > a destination that does hold the provider's secret it is not meaningless but the only means of
    > verification available. **The one signature-shaped header that does carry key material — a
    > `shared-secret` verification header, whose value is the member's own secret — is stripped per
    > proxy under PRD-10 AC27 and stays stripped**, which is what makes the change safe.

- **Forwards everything else, including `Content-Type`** — preserving the payload's
  media type so destinations interpret the replayed body correctly (AC8), along with
  other benign descriptive headers (custom `X-*` event/type/id headers, `Accept`,
  `User-Agent`, etc.).

No signature or verification header is **added** by the proxy at #1 (that is #10 /
V2). The stripped set is defined as a **maintained constant list** so #10 and later
items can extend it without touching the fan-out logic.

> **[P1 — AMENDED by ADR-023 (Accepted, ratified by the Project Owner's approval of
> PRD-10, 2026-08-27). Not a supersession.]** The signing headers arriving at #10 are
> the extension this paragraph and this ADR's Impact section already forecast. What
> goes **beyond** the forecast is PRD-10 **AC30's destination credential** — a
> member-named header that is neither a signature nor a verification header.
>
> **[P2 — AMENDED by ADR-023. Not a supersession.]** The constant list remains, and
> its contents are unchanged. But PRD-10 **AC27** strips the proxy's own inbound
> verification headers, and under `shared-secret` that name is member-chosen, so the
> effective strip set is now **the constant plus a per-request set resolved from the
> proxy**. This ADR anticipated the constant growing; it did not anticipate a dynamic
> component. `DeliveryUnit::STRIPPED_HEADERS` is deliberately **not** extended with
> the three `webhook-*` names — that would change what an unsigned destination
> receives and breach PRD-10 AC63. See ADR-023 Decisions 1, 2 and 5.

## Alternatives
- **(a) Safe allowlist — forward-all-except-stripped-sensitive-set — CHOSEN.**
  Maximises fidelity (destinations receive the sender's descriptive headers) while
  removing the known-dangerous set; the tradeoff is that a *newly introduced*
  sensitive header is forwarded until added to the strip list.
- **(b) Strict allowlist — forward only `Content-Type` (+ a tiny explicit set),
  drop everything else.** Safest by default, but silently drops sender headers many
  integrations rely on (event type/id, idempotency keys), lowering fidelity;
  rejected by the Owner.
- **(c) Forward-everything verbatim (including body-only + all headers).** Leaks
  `Host`/`Cookie`/`Authorization`/signatures and hop-by-hop framing headers to
  third parties; unsafe; rejected.
- **(d) Body-only, no headers except a generated `Content-Type`.** Breaks
  passthrough of legitimate descriptive headers and forces the proxy to infer media
  type; rejected.

## Reasoning
- AC8 requires the payload to arrive interpretable, which requires `Content-Type`
  passthrough; a body-only policy fails that without inference.
- The stripped set is precisely the headers that are either **transport-scoped**
  (hop-by-hop, `Host`, `Content-Length`) or **secret/authenticator material scoped
  to the inbound leg** (`Cookie`, `Authorization`, provider signatures). Removing
  exactly these prevents credential leakage and framing confusion while leaving the
  sender's descriptive headers intact for destination logic.

  > **[P4 — PROPOSED supersession by ADR-025 (`Proposed`, 2026-08-28, pending Owner approval), in
  > the grouping only.]** `Cookie` and `Authorization` carry credentials and stay stripped for
  > exactly the reason stated here. Provider signatures carry a digest rather than a credential and
  > are removed from this category; the rest of the sentence, and every strip that rests on it,
  > stands.
- A deny-list (option a) matches the walking skeleton's fidelity posture — replay
  the webhook faithfully — while the explicit strip list keeps the security-relevant
  removals auditable and extensible for #10 (outbound signing) and #5 (payload
  handling).

## Impact
- **Easier:** #10 attaches outbound signing/verification by *adding* headers after
  `forwardHeaders()` and extending the strip list for any new inbound-signature
  formats — no change to `DeliverStep`/`DeliverToDestination` structure.
- **Constrained / carried forward:** the strip list is a **security control** and
  must be kept current — a newly emerging sensitive inbound header is forwarded
  until added, so the list is reviewed when new providers/verification schemes are
  onboarded. Header matching must be **case-insensitive** (HTTP header names are
  case-insensitive).
- **Reversibility:** senders may come to depend on forwarded headers, so tightening
  the policy later (e.g. moving toward a strict allowlist) is a behaviour change for
  integrators — hence this is recorded as an ADR rather than an implementation
  detail.
- **Approval gate:** this ADR is **Proposed**. The Owner has decided the *policy*
  (option a); the ADR document itself still awaits the standard Project Owner
  approval before it is Accepted.
