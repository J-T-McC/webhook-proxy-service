# ADR-021: Secret handling — a `proxy_secrets` relation for the rotating secrets, columns for the one that does not rotate, and recoverable encryption throughout

> **Written to two Project Owner rulings given directly on 2026-08-27, after PRD-10 was approved
> and after the design gate closed. One of them contradicts the approved PRD and the approved
> design spec, and resolving that is the Product Manager's, not mine.**
>
> - **Ruling A — the storage model.** The Owner asked that a **table with a relation**, rather than
>   a fixed pair of columns, be presented as the way to hold a rotating secret: "*We can have 1, 2,
>   3.. relations. There can be an expiration timestamp that is set on an existing token when a new
>   token is created. When we retrieve the tokens, we can retrieve non expired tokens … we can
>   expand tokens vertically since we know the header can hold multiple. They will naturally expire
>   and be excluded.*" Presented here as the **recommended** option, with the alternatives and the
>   honest costs, for the Owner to rule on at the gate — not treated as pre-approved.
> - **Ruling B — the grain of outbound signing.** "*A proxy has one outgoing secret that can be
>   used for all destinations. We can rotate so the header contains multiple secrets until one or
>   more expires, but that is proxy level.*" **PRD-10 AC54–AC64 and `design-10` Screens 5 and 6 and
>   Flows G–I are written as per-destination signing.** This ADR is written to the Owner's ruling,
>   not to the stale text, and the conflict is routed to the Product Manager at
>   **`docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`**. Neither
>   document is edited here.

- **Status:** **Proposed — pending Project Owner approval.** This ADR is **Owner-approval flag 2**
  of `docs/plans/plan-10-sensitive-data-handling.md`, and the Owner is asked to choose between two
  fully-specified storage models, not to approve a direction. It is security-sensitive: it decides
  that every secret item #10 introduces is stored so the application can read it back rather than
  hashed, where those secrets live, and how long a replaced one survives.
- **Author:** Principal Engineer
- **Date:** 2026-08-27
- **Feature:** prd-10-sensitive-data-handling (AC1, AC10, AC11, AC26, AC29, AC30, AC33, AC34,
  AC36, AC54, AC56, AC57, AC58, AC61, AC62) — **as modified by the Owner's ruling B**, see the
  banner above and `Q-10-04`.
- **Companions:** ADR-022 (inbound verification, the first consumer of the verification secrets) ·
  ADR-023 (the outbound request contract, the consumer of the destination credential and the
  proxy's signing secrets) · ADR-010 Amendment B (the binding `APP_PREVIOUS_KEYS` rule this ADR
  widens) · ADR-014 (the payload columns already behind the at-rest floor, and the `json`-cannot-be-
  encrypted lesson) · ADR-018 (the resolution-time gate pattern reused for `verification_scheme`) ·
  ADR-015 Decision 5 (the delayed-job + sweeper shape reused for expiry)
- **Supersedes:** nothing. No position of any Accepted ADR is reversed or narrowed by this ADR.

## Question

PRD-10 introduces three kinds of secret where the system previously had one
(`proxies.ingest_token`). **Under the Owner's ruling B the grain of the third has moved**, and the
three are no longer alike:

| Secret | Whose | Grain | Rotates? | On the wire |
|---|---|---|---|---|
| **Verification secret** (AC26) | the upstream provider's | **proxy** | **Yes** (AC29) — several honoured at once | nothing; we verify against it |
| **Signing secret** (AC56, **ruling B**) | **ours**, generated | **proxy** — one outgoing secret used for every destination | **Yes** (AC58) — several presented at once, the specification's signature header carries a list | one `v1,<sig>` entry per live secret |
| **Destination credential** (AC30) | the destination operator's | **destination** | **No** — AC29 excludes it by name | one header, exactly one value |

Each must carry the at-rest floor (AC1, AC34, AC57), must never be redisplayed after saving (AC26,
AC33, AC57 — with the single one-time-display exception), and must fail loudly rather than silently
when it cannot be read (AC11).

Five questions follow, none of which PRD-10 answers.

1. **Hashed or recoverable?** `proxies.ingest_token_hash` is a SHA-256 hash and is this project's
   only existing precedent for storing a presented credential. Does that posture work here?
2. **A relation or columns** — and if a relation, **which of the three kinds live in it**?
3. **What mechanism makes rotation true**, including the early-end action, a further rotation inside
   an existing window, and a delayed job that never runs?
4. **Does an N-row model reach past AC29**, which caps live secrets at two and says "there is no
   third slot"?
5. **What makes "never redisplayed" structural** rather than a rule every future surface must
   remember, given the AC57 one-time display is a real exception that has to reach a browser?

## Decision

### (1) Every secret is stored recoverably encrypted, through Laravel's `encrypted` cast. Nothing here is hashed.

Hashing is not available to this feature. Two of the three secrets are **HMAC key material** —
`standard-webhooks` inbound verification (AC52) and outbound signing (AC55) both compute
`HMAC-SHA256` over the request, which needs the key itself — and the third is **presented verbatim**
on the wire (AC30), which also needs the value. Only one of the four uses in the feature
(`shared-secret` inbound comparison, AC51) could be satisfied by a hash, and giving one scheme of
one capability a different storage posture would leave the codebase with two answers to "how is a
secret stored here" and a migration path between them that nothing needs.

`proxies.ingest_token` is the exact precedent and already holds both postures for one value:
`ingest_token_hash` (`BINARY(32)`, hashed, for the O(1) inbound lookup) **and** `ingest_token`
(`text`, cast `encrypted`, so the URL can be displayed). #10's secrets need only the second half —
none is ever looked up *by* its value; each is read from a row already resolved.

The **cast**, not a hand-rolled `Crypt::encryptString()` call, is binding. Three reasons, the last
not obvious:

- It is the house pattern (`proxies.ingest_token`, `webhook_events.body`, `.headers`,
  `dispatched_payloads.body`).
- It puts encryption at exactly one point per column, so no write path can forget it.
- **It keeps plaintext out of the query bindings.** The cast runs at attribute-set time and
  `Model::performInsert()` binds `$this->getAttributes()`, so the bound value is already ciphertext.
  Laravel's `QueryException::formatMessage()` interpolates bindings into the exception message
  (`Str::replaceArray('?', $bindings, $sql)` — verified in vendor), and that message reaches
  `failed_jobs.exception`, Horizon's own 7-day failed-job record, and the log. A hand-encrypting
  controller that assigned a plaintext attribute anywhere would put a secret into all three. Same
  property is what makes AC5 hold for payload content today — see
  `docs/questions/prd-10-q-10-02-at-rest-payload-copy-inventory.md`.

### (2) **RECOMMENDED — the rotating secrets live in a `proxy_secrets` relation; the credential, which does not rotate, stays as columns on `destinations`.**

This is the Owner's ruling A, applied — and applied to exactly the secrets it fits, which the
ruling's own framing makes possible: **under ruling B both rotating secrets are proxy-level**, so
one table with one concrete `proxy_id` foreign key holds both. No polymorphic owner, no second
table, no nullable-FK pair.

**`proxy_secrets` — one row per live or superseded secret, per purpose, per proxy.** Full column
definitions, foreign keys and delete behaviour are enumerated in `plan-10` § *Data Model*, which
carries the Owner's data-model gate; the shape that matters here is:

- `purpose` — a backed enum, `verification` or `signing`. A third proxy-level secret later is one
  enum case and no migration.
- `value` — `text`, cast `encrypted`. One column, one encryption boundary, for every rotating
  secret in the system.
- `is_current` — **nullable boolean**, `true` for the live secret and `NULL` for a superseded one,
  under `UNIQUE(proxy_id, purpose, is_current)`. MySQL and SQLite both ignore NULLs in a unique
  index, so this is a portable partial-unique constraint meaning **at most one current secret per
  purpose per proxy** — enforced by the database rather than by the write path.
- `expires_at` — `NULL` while current; set to `now() + overlap` at the moment the row is superseded.
- `created_at` — the "changed {date}" / "generated {date}" every surface shows (AC26, AC33, AC57).

**Invariant, pinned by test:** `is_current IS NOT NULL` ⟺ `expires_at IS NULL`. The two columns
never disagree; `is_current` exists to carry the unique index and to name which row is current on a
surface, `expires_at` exists to answer "is this one still honoured".

**Retrieval is the Owner's sentence, verbatim:** the live set for a purpose is
`WHERE proxy_id = ? AND purpose = ? AND (expires_at IS NULL OR expires_at > NOW())`, ordered
current-first. Inbound, a request verifies if it verifies against **any** of them (AC29). Outbound,
the `webhook-signature` header carries **one `v1,<sig>` entry per member of the set** (AC58) — which
is what the Owner means by "the header contains multiple secrets until one or more expires", and it
asks nothing of the receiver beyond the specification it already implements. **A secret leaves the
live set by data, at its own expiry instant, with no branch and no sweeper involved.**

**The unique index is the only index**, and its `(proxy_id, purpose)` prefix serves every read the
feature makes.

**The destination credential does not go in this table, and forcing it in would be wrong.** It is
per destination, not per proxy; it does not rotate — AC29 excludes it by name, because a request
carries exactly one credential value and there is nothing on the wire for an overlap to mean; and
it therefore has nothing to stack vertically and nothing to expire. Every property the relation buys
is a property the credential does not have. It stays as three columns on `destinations`
(`credential_header_name`, `credential_secret` cast `encrypted`, `credential_set_at`), single-valued,
replaced immediately.

**What stays as columns and why, so the split reads as a rule rather than a compromise:**

| Lives as a column | On | Because |
|---|---|---|
| `verification_scheme` | `proxies` | It is **configuration, not a secret**, and it is the resolution-time gate (Decision 5). Keeping it on the row means the ingest hot path can skip the secrets query entirely for a proxy with verification off. |
| `verification_header_name` | `proxies` | Configuration, deliberately visible (AC26), and **single-valued across a rotation** — a rotation replaces the secret, never the header a sender is configured to use. Putting it on the secret row would let two live rows disagree about which header to read. |
| `credential_header_name`, `credential_secret`, `credential_set_at` | `destinations` | The credential does not rotate (AC29's carve-out). |
| `sensitive_fields` | `proxies` | Not a secret at all — member-typed field **names**, which AC15 requires to stay visible. |

### (3) Rotation is one write, and expiry is data — with a delayed job and a daily sweeper for hygiene only.

`App\Services\SecretStore` is the single writer and the single reader of `proxy_secrets`.

**Replacement**, in one transaction: delete every already-superseded row for that
`(proxy_id, purpose)`; set the current row `is_current = NULL, expires_at = now() + overlap`; insert
the new row `is_current = true, expires_at = NULL`. That is AC29's whole ruling in one operation —
"saving a replacement makes it the current secret and demotes the existing one", and "a further
replacement inside the overlap … the oldest is discarded immediately".

**`App\Support\RotationOverlap::HOURS = 24`, a class constant, not config.** AC29 fixes it and says
it is not configurable; an env key would be a value the product could tune, which is the thing AC29
rules out. Same reasoning as ADR-022's tolerance constant.

**Ending an overlap early (AC29)** deletes every superseded row for that `(proxy_id, purpose)`. One
statement, idempotent.

**Expiry needs no mechanism to be correct.** The live-set predicate is evaluated at every use, so a
secret stops being honoured at its expiry instant whether or not anything has deleted the row. That
is the property the Owner's model buys and it is strictly better than the column model's, where
"honoured" and "erased" were two separate things that had to be kept in step.

**Erasure still happens promptly, because AC29 says the previous secret is *erased*, not merely
ignored.** `ExpireProxySecrets`, dispatched delayed by the overlap with **scalar arguments only**
(`proxyId`, `purpose`), deletes rows whose `expires_at <= now()`; a daily
`secrets:purge-expired` command is the liveness net for a lost or dropped delayed job. This is
ADR-015 Decision 5's shape reused, and here both bodies are a single `DELETE` — neither can extend a
window, and neither is load-bearing for correctness.

### (4) The relation is general; **AC29's cap of two is not lifted here.** That is a requirements question and it is routed, not decided.

The schema permits three or more live secrets with no migration — which is one of the four things
ruling A is buying, and it is worth having. **PRD-10 AC29 as approved caps it at two**: "At most two
secrets are ever held for one purpose … there is no third slot." The Owner's own words point the
other way ("1, 2, 3.. relations"; "until **one or more** expires"), but they describe a capability
rather than state a policy, and widening an approved acceptance criterion on an inferred reading is
not the Principal Engineer's to do.

**Resolution, written down rather than left to be inferred: the storage model is general and the
behaviour is narrow.** `SecretStore::replace()` deletes already-superseded rows before demoting the
current one, so **at most two rows exist for a purpose at any instant** and AC29 is literally true —
including its "held", not merely its "honoured". Nothing in the read path assumes two: the inbound
verifier loops the live set and the outbound signer emits one entry per member, so raising the cap
later is a change to one line of `SecretStore` and to no consumer, no schema and no test of the read
path.

Whether the cap should be raised is asked at
**`docs/questions/prd-10-q-10-04-proxy-level-signing-grain-and-live-secret-cap.md`**, item 2,
alongside ruling B's grain change. Until the Product Manager rules, #10 ships two.

### (5) Presence and the scheme column are the enabled states, and the two rotating purposes differ on what "off" does.

- **Signing: a proxy has signing on when it has a live `signing` secret.** There is no
  `signing_enabled` column, so the states "enabled with no secret" and "disabled with a secret"
  cannot exist. Disabling **deletes every `signing` row for the proxy**, current and superseded.
  `design-10` left this storage-lifecycle detail to technical design and observed there is no
  user-facing difference either way — because its Flow I step 3 already rules that re-enabling
  always generates a fresh secret and never resurfaces the old one (AC57 forbids displaying it
  again, so there is nothing to resurrect it *as*). Deleting is therefore free of user-visible
  consequence and strictly reduces how long a secret exists.
- **Verification: `proxies.verification_scheme IS NULL` means not required, and turning it off does
  NOT delete the stored secrets.** The scheme column alone gates them — a **resolution-time gate in
  the single resolver**, which is ADR-018 Decision 1's rule for per-proxy configuration consulted
  outside the pipeline, applied verbatim. This is the opposite of the signing ruling and the
  difference is not an inconsistency: the signing secret is regenerable by the member in one click,
  whereas the verification secret is **issued by somebody else** (AC26), so deleting it silently
  sends the member back to their provider. Destroying configuration a member did not ask to destroy
  is the failure `review-07`'s Major was about, and PRD-07 AC14's dormant-retry-policy precedent
  points the same way. **A dormant verification secret is still a secret**: same cast, same key rule,
  same fail-loud rule, never redisplayed.

### (6) Write-only is enforced where the value would have to cross a boundary, not by convention.

1. **No Eloquent resource, Inertia prop or page payload may carry a secret value.** `ProxySecret` is
   never serialized to a resource. The status surfaces emit set / not set, `created_at`, the live
   set's expiry instants, and the header **name** — never a value and never a length. This matters
   more with a relation than with columns: an accidental `->with('secrets')` on a resource-bound
   query would otherwise ship every live secret to the browser. **`ProxySecret` therefore declares
   `$hidden = ['value']` as a second, independent guard**, so even a careless `toArray()` cannot
   emit it.
2. **No submitted secret may be flashed as old input.** Laravel's validation handler redirects with
   `Arr::except($request->input(), $dontFlash)`, and with `SESSION_DRIVER=database` that writes the
   submitted plaintext into the `sessions` table. `Arr::forget()` supports dot notation but **not
   wildcards** (verified in vendor), so `destinations.*.credential_secret` cannot be excluded by a
   `dontFlash` entry; the nested keys must be scrubbed from the request before the exception
   propagates. `plan-10` § *Implementation Notes* names the mechanism and § *Test strategy* pins it.
3. **The one-time display (AC57) is a JSON XHR response, never an Inertia prop and never a flash.**
   Inertia keeps page props in browser history state, and `Inertia::flash` writes through the
   session store. Both are durable copies of a secret in places nothing erases. The generate and
   regenerate endpoint returns `application/json` with `Cache-Control: no-store, private` and the
   dialog reads it directly — the posture ADR-017 Decision 6 adopted for payload content ("never
   resident in props/DOM/history state unless a user explicitly requested it"), for the same reason.

### (7) A secret that cannot be decrypted fails the operation loudly (AC11), through one exception type with a fixed message.

`App\Exceptions\SecretUnavailableException` carries a fixed, value-free message naming **which**
secret failed and nothing else.

| Call site | On decrypt failure |
|---|---|
| Inbound verification (`IngestController`, pre-capture) | **HTTP 500**, reported. Never 401 — that tells the sender their secret is wrong when ours is unreadable — and never the proxy's configured 2xx, which would be the silent authentication bypass AC11 exists to prevent. Nothing captured, nothing dispatched. |
| Destination credential or a proxy signing secret (`DeliverToDestination::send()`) | The attempt **fails**, before any request is made. Never dispatch without the credential; never dispatch unsigned. It surfaces through design-06's existing attempt-history treatment (design-10 correction C7) with the fixed message as the `error_summary`; the secret is kept out of the text at source, never masked after the fact (AC49 forbids obfuscating attempt error summaries). |

**One live secret failing to decrypt fails the operation** — it is not silently dropped from the set
so the others carry on. A partial signature list would be indistinguishable, to us and to the
receiver, from a completed rotation.

### (8) No secret ever enters a queued job, a failure record, an attempt row, an analytics figure or a log line — and that is structural.

The delivery job carries `(deliveryId, attemptNumber)` and nothing else (ADR-020 Decision 7), and
`DeliveryUnitResolver` builds the unit **on the worker**, from rows. Every secret this feature adds
is read after the queue boundary, in the process that uses it, so there is no serialization step for
it to leak through — AC35 and AC61 need no mechanism of their own. **This only holds because ADR-020
already moved delivery to by-reference**; had attempt 1 still carried a `DeliveryUnit`, adding a
credential to it would have put a secret in Redis, in `failed_jobs.payload` and in Horizon's second
7-day store. Whoever changes the delivery job's arguments must re-check this ADR, not only ADR-020.

## Alternatives

### A. Fixed columns on the owning rows — the model this ADR replaced, enumerated so the Owner can take it

Three columns per rotating purpose on the owning table — `<purpose>_secret`,
`<purpose>_previous_secret`, `<purpose>_previous_expires_at` — plus the set-at timestamps. Under
ruling B that is **six columns on `proxies`** (verification ×3, signing ×3) and three on
`destinations`, against the recommended model's one table plus two columns on `proxies` and three on
`destinations`.

**What it is better at, honestly:** the ingest hot path and the delivery path each cost **zero extra
queries**, because the secret arrives inside a row the caller already holds; "at most two" is
structural rather than enforced by a write path; and there is no new table, no new model and no new
foreign key for the Owner to approve.

**What it is worse at:** five encrypted columns instead of one, so AC44's deferred re-encryption
tooling has five places to walk instead of one; the "what about three" question is answered only by
"add two more columns and a migration"; expiry has to be a branch (`previous_expires_at > now()`)
evaluated separately at every read site rather than a property of the row set; and the column names
carry the purpose, so a third proxy-level secret is a schema change rather than an enum case.
**Rejected**, but fully specified — `plan-10` § *Data Model* enumerates it as the alternative change
set so a `no` on the recommended model is immediately buildable.

### B. Other shapes considered and rejected

- **A polymorphic `secrets` table** (`secretable_type`/`secretable_id`) holding all three kinds.
  Rejected on two grounds. It **loses the foreign key** to the owner, which this schema keeps
  everywhere and deliberately (`fifo_dispatches.webhook_event_id` is RESTRICT precisely so a bad
  delete fails loudly), and this codebase has **no polymorphic relation anywhere** today. More
  importantly it is no longer needed: ruling B makes both rotating secrets proxy-level, so a single
  concrete `proxy_id` FK covers them, and the credential — the only destination-level secret — does
  not belong in a rotation table at all.
- **Two concrete tables, `proxy_secrets` and `destination_secrets`.** Rejected for the same reason:
  after ruling B, `destination_secrets` would hold exactly one non-rotating, single-valued row per
  destination, which is a table standing in for three columns.
- **One table with nullable `proxy_id` and `destination_id` and a CHECK that exactly one is set.**
  Rejected: MySQL 8.0.16+ would enforce the CHECK, but Blueprint has no helper for it so it needs
  raw DDL, and the shape exists only to accommodate a member (the credential) that should not be in
  the table.
- **Put the credential in `proxy_secrets` anyway, with a zero-length overlap.** Rejected: it is
  per **destination**, and the table's key is `proxy_id`. Forcing it in would mean a nullable
  `destination_id` on a table that otherwise has none, to hold a value that never stacks and never
  expires — the coordinator's instruction was to say when a kind does not fit rather than force all
  three in, and this is that kind.
- **Hash the `shared-secret` value (SHA-256, constant-time compare on the hash), as
  `ingest_token_hash` does.** Works for `shared-secret` alone. Rejected: the other three uses need
  key material or the value itself, so this gives one of four uses a different storage posture, a
  second rotation code path, and a migration between them the moment a member switches scheme — for
  no reduction in the system's overall exposure.
- **An external secret manager (KMS/Vault).** Rejected as out of scope and out of stack:
  `docs/stack/stack.md` records no cloud provider and no deployment target, AC45 rules out per-team
  key policy, and PRD-05 settled the at-rest floor as the application key. **Not rejected on merit** —
  AC1's backend-agnostic wording already admits one, and this ADR is what would be superseded.
- **A `signing_enabled` boolean beside the signing secrets.** Rejected: two states that cannot be
  reached legitimately, and a second thing to keep in step with the first. See Decision 5.
- **Delete the verification secrets when the scheme is set back to "Not required".** Rejected: it
  destroys configuration the member did not ask to destroy, and the value came from a third party.
  Cheap to add later if the Product Manager ever wants it, because the resolver gate is already the
  only thing consulted.
- **Make the 24-hour overlap a config key.** Rejected: AC29 fixes it and says not configurable.
- **Deliver the one-time signing secret as an Inertia flash prop.** Rejected: it writes the value
  into the session store and into browser history state.
- **Encrypt by hand in the controller rather than through the cast.** Rejected: see Decision 1's
  third reason.

## Reasoning

- **Ruling B is what makes ruling A clean.** With signing per destination, "a table related to
  proxies" would have covered one of two rotating secrets and something else would have been needed
  for the other. With signing per proxy, **both** rotating secrets are proxy-level, one concrete
  foreign key holds them, and the only secret left out is the one that has no rotation to model.
  The two rulings compose into a smaller design than either would have produced alone, and that is
  worth recording because it is not obvious from either sentence in isolation.
- **Expiry-by-data is a genuine correctness improvement, not only tidiness.** In the column model
  "still honoured" and "still stored" were two facts kept in step by a read-time branch and a
  sweeper. In the relation they are one fact: a row is in the live set or it is not. The failure
  mode the column model had — a sweeper that runs while a read site forgot its branch — cannot be
  expressed here.
- **The hashing question is settled by what the schemes compute, not by preference**, and writing it
  down matters more than the answer: "why is this secret not hashed like a password?" is the first
  question a reviewer will ask, and without the record the likely outcome is somebody hashing one of
  them and breaking `standard-webhooks` when a real integration is onboarded.
- **AC10 is the cost side and it gets cheaper under the recommended model.** ADR-010 Amendment B's
  binding rule — never drop a prior key until every encrypted value has been re-encrypted under the
  current one — today spans four columns across three tables (`webhook_events.body`, `.headers`,
  `dispatched_payloads.body`, `proxies.ingest_token`). The recommended model takes it to **six
  columns across five tables** (`+ proxy_secrets.value`, `+ destinations.credential_secret`); the
  column alternative would take it to **nine across four**. AC44 defers the re-encryption tooling
  and states the cost; this is where the widened list lives so that tooling has something to walk.
  A further gain worth naming: `proxy_secrets` rows are tiny and cheap to iterate, unlike
  `webhook_events.body` at up to 50 MiB.
- **Fail-loud is asymmetric on purpose.** For payload content, ADR-014 Decision 7's failure mode was
  dispatching an empty payload. For a secret it is *authenticating nothing while appearing to* —
  which is why the inbound branch returns 500 rather than 401, and why the outbound branch refuses
  to send rather than sending unsigned. AC11 names the receiver that correctly rejects unsigned
  traffic seeing it arrive unsigned.

## Impact

- **Data-model change (Owner-gated ✋):** one new table, `proxy_secrets`, plus two columns on
  `proxies` and three on `destinations` — enumerated exactly, with types, nullability, indexes,
  foreign keys and delete behaviour, in `plan-10` § *Data Model*, which carries the gate. Additive
  only; no existing row is touched and every new column is NULL on every existing row, which is
  exactly AC24/AC37/AC63. **Rollback is one `dropIfExists` and two `dropColumn` calls — and is
  destructive to every stored secret**, which cannot be recovered afterwards. Stated here rather
  than left in the migration.
- **Security-sensitive (Owner-gated ✋):** three new kinds of secret at rest, the widened
  `APP_PREVIOUS_KEYS` surface, and one new egress carrying a secret to a browser (the AC57 one-time
  display).
- **Performance, named rather than discovered:**
  - **Ingest hot path:** **one additional indexed query, and only for a proxy with verification
    configured.** `verification_scheme` staying a column is what buys that — for the overwhelming
    majority of proxies the branch is a NULL check on a row already in memory and the secrets table
    is never touched, so AC24's "behaves exactly as it does today" is true at the query-count level
    and not only behaviourally.
  - **Delivery path:** **one additional indexed query per attempt**, unconditional, returning the
    proxy's live signing secrets. On a path that then makes an outbound HTTPS request with a
    15-second timeout, that is noise; it is named because it is a real change from zero. The
    credential costs nothing extra — it arrives on the `Destination` row the resolver already loads.
  - **Show page:** one `whereIn` for the proxy's destinations' credential presence, and the proxy's
    own live sets come with the page's existing proxy load.
- **Easier:** a third proxy-level secret is one enum case, no migration and no new columns. The
  re-encryption tooling AC44 defers has one table and one column to walk for every rotating secret in
  the system. Rotation, early-end and expiry are one implementation shared by both purposes.
- **Constrained:**
  - **`SecretStore` is the only reader and the only writer of `proxy_secrets`.** A second one is a
    review finding, not a refactor — the same single-resolver discipline `RetryPolicy`,
    `StoredPayloadLookup` and `RetentionPolicy` hold.
  - **`ProxySecret` is never serialized into a resource, prop or DTO**, and declares
    `$hidden = ['value']` as a second guard (Decision 6.1).
  - **`ApplyTeamScope` does not register `TeamScope` on `ProxySecret`**, deliberately: the ingest
    path is team-unscoped and has no current team, so a global scope would break the read. Every
    query reaches the table through an already-scoped proxy, and the `team_id` column exists for
    consistency with every other domain table and for a future audit query — **not** as an enforced
    scope. This is the same posture `Delivery` and `WebhookEvent` already have, and it is the trap
    plan-11 recorded.
  - **The delivery job's arguments must stay scalar** (ADR-020 Decision 9, extended to secrets by
    Decision 8).
  - **No submitted secret may be flashed as old input** (Decision 6.2).
  - **`RETENTION_DAYS`, `queue:prune-failed --hours 168` and Horizon's 10080-minute failed trim are
    unaffected** — no secret is payload content (AC36, AC62), and retention never erases one.
  - **AC29's cap of two is a property of `SecretStore::replace()`, not of the schema.** It is pinned
    by test, and raising it is the Product Manager's (Decision 4, `Q-10-04`).
- **Within stack:** MySQL, Eloquent casts and relations, Laravel migrations, the existing queue and
  scheduler. **No new dependency, no stack change.** `docs/stack/stack.md` is untouched.
