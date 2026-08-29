# Task Plan: Sensitive data handling — item #10

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-10-sensitive-data-handling.md` (**fully approved** — Principal
  Engineer self-certified, and all four Owner-approval flags ruled by the Project Owner,
  2026-08-27: flag 1, the `proxy_secrets` table plus six columns, approved exactly as enumerated
  (the fixed-column alternative not taken); flag 2, ADR-021, approved as the recommendation; flag 3,
  ADR-022, approved; flag 4, ADR-024, approved) **plus `## Revision A`** (2026-08-27, Principal
  Engineer, no Owner gate — technical ruling 15, the `destinations.*.remove_credential` transport,
  answering `Q-10-05`; purely additive, no existing ruling/gate/milestone/ADR reopened)
- **PRD:** `docs/product/prd-10-sensitive-data-handling.md` (Approved, Project Owner, 2026-08-27; 64
  acceptance criteria, `## Amendment A` **and** `## Amendment B` both ratified whole, nothing
  renumbered — Amendment B re-grains outbound signing to the proxy and settles AC29's cap of two)
- **Design:** `docs/design/design-10-sensitive-data-handling.md` (**Approved, as amended** — the
  original design gate, 2026-08-27, Product Manager, ten required corrections C1–C10; **and** the
  amendment gate, 2026-08-27, Product Manager, four required corrections B1–B4, all landed. The
  amendment gate's own record governs where it and the original spec body differ.)
- **Questions:** `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md` (**RESOLVED**,
  Principal Engineer) · `docs/questions/prd-10-q-10-03-credential-removal-and-secret-field-primitive.md`
  (**RESOLVED**, Designer) · `docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`
  (**RESOLVED**, PRD-10 `## Amendment B`) · `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`
  (**RESOLVED**, Principal Engineer, 2026-08-27 — a sibling boolean, `destinations.*.remove_credential`,
  recorded as `plan-10` § *Revision A*, technical ruling 15; T31 is unblocked)
- **Authority (ADR-025):** `docs/architecture/adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md`
  (Status **ACCEPTED** — Project Owner approval of both gated decisions recorded 2026-08-28. Decision
  1, provider-signature header pass-through, approved as unconditional, not member-opt-in. Decision 2,
  the outbound signing header rename, approved with the rename landing before item #10 merges to
  `main`. Decision 3 carried no gate and is out of scope for this document.) Committed on branch
  `docs/adr-025-outbound-header-policy`, **not yet merged onto `feat/item-10-sensitive-data` or
  `main`** — merge or cherry-pick that file onto this branch before **T50** below is implemented, so
  the document this task plan cites as its Authority actually exists here. **Decision 1's boundary is
  itself superseded by ADR-026 Decision A below** — the five-name reduction stands, unconditional, as
  originally approved; ADR-026 widens it to seven names. The task that was going to carry Decision 1,
  **T51**, is superseded before being built — see its own entry below — and **T55** carries the wider
  reduction instead.
- **Authority (ADR-026):** `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`
  (Status **ACCEPTED** — Project Owner, 2026-08-28, both rulings the Owner's own, quoted verbatim in
  the ADR and recorded rather than proposed there. Committed at `38ac603` on this branch,
  `feat/item-10-sensitive-data` — no merge/cherry-pick step needed, unlike ADR-025 above. Supersedes
  ADR-022 in full; supersedes ADR-025 Decision 1 in part, per the note above; supersedes one premise of
  ADR-023 Decision 2 and one clause of ADR-023 Decision 5; retires two ADR-008 strip bullets.) Decision
  B (inbound verification removed) is carried out at **T52–T54**; Decision A (the wider outbound strip
  reduction) at **T55**; the Decision 4 migration and the `SecretPurpose::Verification` case removal
  are both inside **T54**, landed together per the ADR's own ordering constraint.
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against the plan/PRD-10/design-10 at review time). **M10** (T50, T55)
  below is added against ADR-025/ADR-026 on the same no-further-Owner-gate basis — the Owner gate is
  already recorded on each ADR itself, every decision ruled by name. **M11** (T52–T54) below is added
  against ADR-026 Decision B on the same basis — ADR-026's own Owner-approval-flags section carries no
  outstanding item, including for the data-model change T54 carries out, which that section states is
  approved by the words of the Owner's ruling.

> **Scope / conventions.** Every task traces to `plan-10` and PRD-10's ACs (AC1–AC64, both
> amendments) or a named plan technical ruling, **with two named exceptions**: **M10** (T50, T55)
> traces to `docs/architecture/adr-025-outbound-header-policy-signature-pass-through-and-signing-header-names.md`
> and `docs/architecture/adr-026-inbound-verification-removal-and-minimal-outbound-header-strip.md`,
> and **M11** (T52–T54) traces to ADR-026 alone — both Accepted by the Project Owner, 2026-08-28, after
> `plan-10` was certified and after M1–M9 below were already task-planned, so neither is or could be
> one of `plan-10`'s own named milestones. Both are appended the same way item #11's own M8 was
> appended to `plan-11` after that plan's own certification: a later Owner-approved decision, broken
> down into tasks without reopening or renumbering anything already task-planned. Sequencing otherwise
> follows the plan's own milestones verbatim (**M1–M9**, with M8 split into **M8a** backend and **M8b**
> surface exactly as the plan names them), each mapped to a contiguous task range below: **M1 data
> model** (T1–T3) → **M2 obfuscation engine, no surface** (T4–T6) → **M3 Standard Webhooks primitive,
> no surface** (T7) → **M4 revealed-payload envelope** (T8–T9) → **M5 sensitive-fields configuration**
> (T10–T12) → **M6 `SecretStore`** (T13–T15; **T16–T25 superseded and removed by ADR-026 Decision B**
> — see each task's own status note, and M11 below for the removal itself) → **M7 outbound credential**
> (T26–T33; T26/T27 narrowed by ADR-026, see their own notes) → **M8a outbound signing, backend**
> (T34–T40) → **M8b outbound signing, surface** (T41–T43; **T44 withdrawn, folded into T49** — see its
> own entry) → **M11 inbound verification removal, ADR-026 Decision B** (T52–T54) → **M10 outbound
> header policy corrections, ADR-025 Decision 2 and ADR-026 Decision A** (T50, then **T55** — **T51
> superseded before being built**, see its own entry) → **M9 cross-cutting hardening and final
> regression sweep** (T45 narrowed, T46–T48, T49 narrowed and folding in T44's scope). No task depends
> on a later task **in build order**. **55 task numbers issued, T1–T55: nine (T16–T21, T23–T25) are
> superseded-and-removed in full; three more (T22, T26, T27) are partially superseded, the surviving
> part of each still live; two (T44, T51) are withdrawn without ever having been built; forty-four
> tasks are live** — T1–T15, T22, T26–T43, T45–T50, T52–T55.
>
> **Numbers are stable identifiers, not build order, from T50 onward.** Every task through T49 is
> numbered in the order it is built, and that held without exception through this document's first
> forty-nine tasks. ADR-026 breaks it once, on purpose: **T52, T53 and T54 must be built before T50**,
> even though their numbers are higher, because ADR-026 was decided after ADR-025 was already
> task-planned as M10 and this document does not renumber an already-numbered task (T44–T51) to keep a
> later decision's arrivals in numeric step — the same principle M10's own original addition already
> established for T50/T51 against M1–M9. **Read the milestone list above, or a task's own Dependencies
> line, for build order — never the numeric sequence of the task IDs once past T49.**
>
> **All four Owner-approval flags are ruled and `design-10`'s amendment gate has closed with its four
> corrections landed** (`docs/status.md` item #10), so — unlike `plan-10`'s own sequencing note,
> written before the amendment gate closed — **no milestone here is gate-blocked, M8b included.**
> `plan-10`'s own instruction that "M8b must not be broken down until `Q-10-04` is answered and
> `design-10` is revised" is satisfied: `Q-10-04` is RESOLVED by PRD-10 `## Amendment B`, `design-10`
> carries the revision, and the amendment gate's correction **B2** (the AC29 ruling-2a disclosure on
> the signing surface) is broken down at **T43**, as a dedicated task, exactly as the amendment gate's
> "What this approval unblocks" section requires before M8b is task-planned.
>
> **The one task this document had blocked on an open question is now unblocked.**
> `docs/design/design-10-…`'s amendment-gate correction **B3** required Screen 3's new **Remove
> credential** control to reach the server as a signal distinguishable from an ordinary blank Replace
> field, and explicitly left the transport to the Principal Engineer — a decision no approved
> `plan-10` text made at the time, because the control did not exist when the plan was certified.
> Raised as `docs/questions/prd-10-q-10-05-destination-credential-removal-signal-transport.md`,
> directed to the Principal Engineer, blocking **T31 only**. **RESOLVED, 2026-08-27**: a sibling
> boolean per destination row, `destinations.*.remove_credential` — `credential_secret` keeps exactly
> one meaning ("a new value, or absent"), never a second, sentinel one. Recorded as `plan-10` §
> *Revision A*, technical ruling 15. T31 below is written to that ruling.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan level 7) green,
> and `./vendor/bin/sail test` green with its own tests included (`CLAUDE.md`,
> `docs/standards/planning.md`). Frontend tasks (T9, T12, T30, T31, T33, T41, T42, T43, T52) —
> **T23 and T24 dropped from this list, withdrawn by ADR-026, see their own status notes** —
> additionally require `pnpm lint:check`, `pnpm types:check` and `pnpm format:check` green, plus the
> manual-verification steps named on the task (no frontend test harness exists — backlog **T31** on
> `docs/tasks/walking-skeleton-tasks.md`, a different T31 than this document's own; see the note on
> this document's T31 below).
>
> **Tasks that touch the delivery path — flagged because `QUEUE_CONNECTION=sync` makes the automated
> suite weak evidence there.** This project's test suite runs the queue synchronously
> (`docs/stack/stack.md`), so a green suite proves the *logic* `DeliverToDestination::send()`,
> `DeliveryUnitResolver` and the header-building pure classes execute correctly when called, but it
> exercises none of the concurrency, ordering or worker-crash behaviour `AdvanceProxyFifoQueue` and
> `app/Actions/DeliverStep.php` are actually responsible for in production (Horizon, real async
> workers). No task in this plan edits `AdvanceProxyFifoQueue` or `DeliverStep.php` — `plan-10` §
> *Architecture G* states FIFO composition is untouched — but the following tasks change code that
> those two files call into on every real dispatch, and their green-suite result should be read as
> "the logic is correct," not as "the async path was exercised end to end": **T27** (`DeliveryUnitResolver`'s
> `withTrashed()` proxy load), **T28** (`DeliverToDestination::send()` calling `OutboundHeaders` for
> the credential), **T34** (`OutboundHeaders` extended with signing headers, computed in the send
> path), **T35** (the AC63 byte-identical regression, exercised through `send()`), **T36**
> (`DeliveryUnitResolver`/`DeliveryUnit` carrying the proxy's live signing set), **T39** (the AC11
> signing all-or-none behaviour, exercised through `send()`), **T40** (the outbound signing
> integration suite, which drives retries and replays through the same job path), **T53** (removing
> the verification header-names parameter from that same `OutboundHeaders`/`DeliverToDestination`/
> `DeliveryUnitResolver` call chain, ADR-026 Decision B), and **T55** (`DeliveryUnit::STRIPPED_HEADERS`,
> read on every dispatch, ADR-026 Decision A). Manual verification
> against a real queued dispatch (Horizon, `QUEUE_CONNECTION=redis`) is recommended at **T49** if it
> is available in the environment, though it is not required by any single task's Acceptance Criteria
> — `plan-10` asserts no numeric or environment-specific target here (AC47).
>
> **Binding constraints carried through the tasks below, named once and traced to where each
> lands, per `plan-10`'s technical rulings and Implementation Notes — none is stylistic:**
> 1. **Signing is proxy-level, not per-destination** (PRD-10 `## Amendment B` ruling 1; `plan-10`
>    Technical ruling 13). One `signing`-purpose secret per proxy, shared by every destination that
>    proxy dispatches to, including one added afterward, rotated at the proxy grain. No task anywhere
>    in M8a/M8b adds a per-destination signing column, toggle, or rotation state — landed at T34–T44.
> 2. **AC11's signing clause is proxy-wide fail-loud, not per-destination** (PRD-10 `## Amendment B`
>    ruling 1). A proxy whose signing secret cannot be decrypted dispatches to **none** of its
>    destinations for that attempt — partial fan-out (some destinations signed, some silently
>    unsigned) is exactly the state this criterion forbids. Pinned by a dedicated test at **T39**,
>    not folded into the general outbound-signing suite (T40), because a partial-fan-out regression
>    is exactly the kind of defect a broad test can quietly pass around.
> 3. **AC29's cap of two live secrets per purpose per proxy, and the ruling-2a "before save"
>    disclosure of the immediate discard, apply on both surfaces this feature has** (PRD-10 `##
>    Amendment B` ruling 2 and 2a). The cap itself is `SecretStore`'s write-path property, pinned at
>    **T14**. The disclosure copy is a **frontend** obligation, present **before** the member commits
>    to the rotation, on **both** the inbound verification surface (Screen 1 / Flow B step 2 — **T23**)
>    and the outbound signing surface (Screen 6 state 4 / Flow H step 2 — **T43**, correction B2). A
>    task that lands the branch on only one surface is incomplete against this constraint.
> 4. **The destination credential is unchanged and stays per destination** (AC31, AC33; untouched by
>    either amendment). No task in this list adds an overlap, a "previous" state, or any rotation
>    language to the credential surface — T26, T29, T30, T31 build a single-valued, immediately-replaced
>    secret with no such state, and T31's Remove-credential control (ruling 15's
>    `destinations.*.remove_credential` boolean) stays inside that same immediate, non-rotating model.
> 5. **`SecretStore` is the single reader and writer of `proxy_secrets`** (`plan-10` Technical ruling
>    14). No task outside T14/T15 issues a query against that table directly; `InboundVerifier` (T18),
>    the signing endpoints (T37) and the resolver (T27/T36) all go through `SecretStore`.
> 6. **`OutboundHeaders` is the only place an outbound header set is built** (`plan-10` Implementation
>    Note 3). T26 creates it for the credential and the verification-header strip; T34 is the only
>    other task that may add to it (the signing headers). No other task builds or mutates an outbound
>    header array.
> 7. **The raw ingest body is read exactly once** and passed to both `InboundVerifier` and
>    `WebhookEventCapture` — landed at **T19**, which is also the only task permitted to change how
>    `IngestController` reads the request body.
> 8. **A present-but-empty secret field never clears a stored secret**, on every write-only field this
>    feature has (verification secret, credential, and the N/A case for the product-generated signing
>    secret, which is never typed at all) — carried into T20, T23, T29, T30's Acceptance Criteria
>    individually rather than asserted once, because each is a separate form field and a separate
>    regression surface.
> 9. **Superseded by ADR-026 — kept for history, replaced by the rule below.** This constraint
>    originally read: "ADR-025's two corrections are outbound-only; inbound is untouched, and a
>    global `webhook-` find-and-replace across the codebase is the wrong implementation of either.
>    `StandardWebhooksScheme`'s own request-header reads and `DeliveryUnitResolver`'s AC27
>    verification-header map keep the Standard Webhooks specification names ... verbatim, because
>    those are the names an inbound sender actually transmits — pinned by name at T50. The AC27
>    per-proxy verification-header strip inside `OutboundHeaders::build()` (T26) stays exactly as
>    built; it is the reason removing five provider-signature names from
>    `DeliveryUnit::STRIPPED_HEADERS` is safe, not a coincidence — pinned by name at T51." **Both
>    premises are gone**: inbound verification itself is removed (ADR-026 Decision B, T52–T54), so
>    there is no `StandardWebhooksScheme`, no AC27 verification-header map, and no AC27 strip left to
>    stay unchanged or to lean on for safety. **The operative rule now:** `DeliveryUnit::STRIPPED_HEADERS`
>    is safe at its Decision-A width (**T55**) because no member can configure an inbound verification
>    header any more, not because of a strip this constraint used to point at. A global `webhook-`
>    find-and-replace remains the wrong way to implement **T50** — that half of this constraint
>    survives even though its inbound-preservation half does not; the rename still touches exactly one
>    production file.
>
> **Scope discipline (`plan-10` §§ Explicitly out of scope / Out of Scope) — do NOT build in this
> feature:** any third verification scheme, IP allow-listing, mutual TLS, or free-form verification
> configuration (AC50); value-pattern secret detection (AC14); partial disclosure of an obfuscated
> value (AC16); a per-field reveal for any role, or any new permission (AC20, AC28); a team-level
> sensitive-field list (AC13); any field-level treatment of a non-JSON payload (AC22); a header
> display surface (AC41/AC42); application-key rotation/re-encryption tooling or per-team keys (AC44,
> AC45); cleaning up secrets already embedded in `destinations.url` (AC39); a byte ceiling on payload
> parsing or any new member-facing state for one (Technical ruling 9, R1); a per-destination signing
> secret, toggle, or rotation state, or raising AC29's cap above two (both named `## Amendment B`
> exclusions); any analytics, counter, or notification for a rejected inbound request (AC46); any
> change to retention, GC, holds, retry, replay, processing mode, the mode attribute, FIFO ordering,
> or #11's figures and indexes; any second payload read surface, export, download, share path, cache
> or archive (AC3). **Added by ADR-026:** any inbound verification scheme, header, secret, surface, or
> configuration of any kind — the capability is removed from the product, not made optional; do not
> reintroduce any part of it while building T45–T55, however convenient a stray reference might look.

---

## Milestones

Physical order below is the plan's own order — note **M11 and M10 precede M9**,
per ADR-026 § *Sequencing and build order*. File prefixes follow that order, not the
milestone labels.

| Milestone | Subject | Tasks | File | State |
|---|---|---|---|---|
| **M1** | Data model | T1–T3 | [`m01-data-model.md`](m01-data-model.md) | Complete |
| **M2** | Obfuscation engine, no surface | T4–T6 | [`m02-obfuscation-engine.md`](m02-obfuscation-engine.md) | Complete |
| **M3** | Standard Webhooks primitive, no surface | T7 | [`m03-standard-webhooks-primitive.md`](m03-standard-webhooks-primitive.md) | Complete |
| **M4** | Revealed-payload envelope | T8–T9 | [`m04-revealed-payload-envelope.md`](m04-revealed-payload-envelope.md) | Complete |
| **M5** | Sensitive-fields configuration surface | T10–T12 | [`m05-sensitive-fields-configuration.md`](m05-sensitive-fields-configuration.md) | Complete |
| **M6** | `SecretStore` and inbound verification | T13–T25 | [`m06-secretstore-inbound-verification.md`](m06-secretstore-inbound-verification.md) | Complete |
| **M7** | Outbound credential | T26–T33 | [`m07-outbound-credential.md`](m07-outbound-credential.md) | Complete |
| **M8a** | Outbound signing, backend | T34–T40 | [`m08-outbound-signing-backend.md`](m08-outbound-signing-backend.md) | Complete |
| **M8b** | Outbound signing, surface | T41–T44 | [`m09-outbound-signing-surface.md`](m09-outbound-signing-surface.md) | Complete |
| **M11** | Inbound verification removal (ADR-026 Decision B) | T52–T54 | [`m10-inbound-verification-removal.md`](m10-inbound-verification-removal.md) | Complete |
| **M10** | Outbound header policy corrections (ADR-025 D2; ADR-026 D A) | T50–T55 | [`m11-outbound-header-policy.md`](m11-outbound-header-policy.md) | Complete — T51 superseded by T55, not built |
| **M9** | Cross-cutting hardening and final regression sweep | T45–T49 | [`m12-hardening-regression-sweep.md`](m12-hardening-regression-sweep.md) | In progress — T45–T48 done, **T49 outstanding** |
