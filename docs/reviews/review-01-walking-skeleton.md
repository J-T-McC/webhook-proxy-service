# Review: Walking skeleton — ingest → fan-out delivery (item #1)

- **Reviewer / date:** Reviewer — 2026-07-31
- **Scope:** Branch `feat/item-01-walking-skeleton` (PR #1), 32 commits T1–T30 (plus
  toolchain/status commits). Diff `git diff main...feat/item-01-walking-skeleton`.
- **Inputs verified:** PRD-01 (Approved), plan-01 (Accepted), design-01 (Approved),
  tasks T1–T30 (Approved, completion notes present on every task), ADR-001…008
  (Accepted), CLAUDE.md commands. Ran `composer lint`, `composer types:check`,
  `./vendor/bin/sail test`.

## Summary
The implementation is complete, faithful to the PRD, technical plan, and ADRs, and
well tested. Every PRD acceptance criterion is implemented and backed by at least one
test with meaningful assertions; the priority areas (team-scoping cross-team 404s,
HTTPS-only in and out, soft-delete + attempt retention, min-1-destination invariant,
ingest 404 on unknown/soft-deleted token, payload-free independent fan-out, ingest URL
built from config not Host) are each directly asserted. Verification is green:
`composer lint` (Pint) passed, `composer types:check` (PHPStan L7) 0 errors,
`./vendor/bin/sail test` **181 passed / 627 assertions**. No blockers and no majors
found. Findings are minor/nit follow-ups only, most of them already flagged as
non-blocking by the plan/tasks. Recommendation: **Approve with follow-ups**. (Note:
`pnpm/npm run build` is not runnable in this sandbox — Node 21 vs required ≥22 — so
the compiled frontend bundle was not exercised; per the task guidance this is an
env-only limitation, and Vue SFCs are validated by vue-tsc + eslint per the T30/T26–T29
notes. See finding 7.)

## AC coverage

| AC | Implemented | Test evidence | Verdict |
|---|---|---|---|
| AC1 create proxy w/ ≥1 destination | `ProxyController@store` + `StoreProxyRequest` | `ProxyStoreTest` (persist + destinations + team_id) | Pass |
| AC2 exactly-one ingest URL, ≥1 destination, always retain ≥1 | `store` transaction + `min:1` + update/destroy guards | `ProxyStoreTest`, `ProxyRequestValidationTest`, `DestinationDestroyTest` | Pass |
| AC3a HTTPS-only destination scheme | `destinations.*.url => url:https` | `ProxyRequestValidationTest` (http:// + scheme-less rejected, https accepted) on Store+Update | Pass (see nit 6: `ftp://` not asserted directly) |
| AC3b POST/PUT only | `http_method => in:POST,PUT`, `HttpMethod` enum | `ProxyRequestValidationTest` (DELETE rejected) | Pass |
| AC4 list + view ingest URL + destinations | `index`/`show`, `Proxies/Index`+`Show` | `ProxyIndexShowTest` | Pass |
| AC5 team ownership / only own team | `TeamScope` global scope + route binding + `ProxyPolicy` | `TeamScopingTest`, `ProxyIndexShowTest::cross_team_show_404`, `DestinationDestroyTest::cross_team_404` | Pass |
| AC6 auth required to manage | `{current_team}` group `['auth','verified',EnsureTeamMembership]` | `ProxyIndexShowTest::guests_redirected` | Pass |
| AC7 deliver to every destination over HTTPS w/ method | `DeliverStep` + `DeliverToDestination` (Http) | `IngestFanOutTest::fans_out_one_per_live_destination` | Pass |
| AC8 same payload structure | body forwarded unchanged; `Content-Type` preserved | `IngestFanOutTest` (body === rawBody; Content-Type forwarded) | Pass |
| AC9 independent fan-out, fire-and-forget | per-destination `try/catch` in `DeliverToDestination`; loop not aborted | `IngestFanOutTest::one_failing_does_not_prevent_others` | Pass |
| AC10 HTTPS + POST/PUT only outbound | destination `url:https` + `HttpMethod` enum on the wire | `IngestFanOutTest` (POST/PUT asserted per destination) | Pass |
| AC11 simple mode, no mapping/storage/retry, but capture | `PipelineFactory` = `[DeliverStep]`; no payload table | `IngestFanOutTest::simple_mode_..._stores_no_payload` | Pass |
| AC12a unique ingest URL | `BINARY(32)` single-col UNIQUE + regenerate-on-collision | `ProxyTest` (unique index), `ProxyStoreTest::two_creates_distinct`, `IngestTokenServiceTest` (forced collision regenerates) | Pass |
| AC12b not guessable / no embedded id | 256-bit base64url token; path is `/ingest/{token}` only | `IngestTokenServiceTest` (entropy/URL-safety) | Pass |
| AC12c unknown/invalid token → 404 | `abort_if(null,404)` no disclosure | `IngestControllerTest::unknown_token_404` | Pass |
| AC12d viewable by owning team | `Proxy::ingestUrl()` from decrypted token | `ProxyIndexShowTest` (ingest_url present) | Pass |
| AC13 one attempt/destination, outcome+status+ids | `DeliveryAttempt` write per unit | `IngestFanOutTest::exactly_one_attempt...` | Pass |
| AC14 capture success+failure in simple mode | `dispatched`→`succeeded`/`failed` | `IngestFanOutTest` (success + 500 + failure) | Pass |
| AC15 payload-free, team-scoped, queryable | no body column; `team_id`; `TeamScope` | `IngestFanOutTest` (no `payload` col / no `webhook_payloads`), `DeliveryAttemptTest` (schema) | Pass |
| AC16a edit name+destinations (add/remove/change) | `edit`/`update` reconciliation | `ProxyUpdateTest` | Pass |
| AC16b min-1 invariant on edit + delete | `update` ≥1-live guard; `DestinationController` last-live 422 | `ProxyUpdateTest` (zero-live rejected), `DestinationDestroyTest` (422) | Pass |
| AC16c delete single destination (soft) | `DestinationController@destroy` | `DestinationDestroyTest` (soft-delete, other survives) | Pass |
| AC16d delete proxy (soft cascade) | `ProxyController@destroy` | `ProxyDestroyTest` (proxy+destination soft-deleted; token 404s) | Pass |
| AC16e team-scoped manage | route binding + policy | `DestinationDestroyTest::cross_team_404`, `ProxyIndexShowTest::cross_team_show_404` | Pass |
| AC17 ingest HTTPS-only | `EnsureIngestIsSecure` (`isSecure()` → 403) | `IngestControllerTest::non_https_rejected` (403 + nothing sent) | Pass |

Attempt-retention across delete (Owner ruling 1) and soft-deleted-token-no-longer-ingests
are additionally proven by `ProxyDestroyTest` (attempt count intact, distinct hash after
soft-delete, ingest 404). Header-forwarding allowlist (ADR-008) is proven end-to-end
(`IngestFanOutTest::header_forwarding`) and in isolation (`DeliveryUnitTest`, mixed-case).

## Plan / ADR conformance
- Native `Illuminate\Pipeline\Pipeline` + laravel-actions shape: `ProcessIngestedWebhook`
  (`AsAction`), `DeliverStep` (`AsObject`, first-party `PipelineStep`), `DeliverToDestination`
  (`AsAction`) — all invoked with `::run` only; no `::dispatch`/`onQueue`/`configureJob`/FIFO
  present. Conforms (ADR-005/007).
- No LATER scaffolding built: `PipelineFactory::stepsFor()` returns exactly `[DeliverStep]`
  for both modes; enhanced/mapping/retry/payload-storage are commented seams only. Conforms.
- `UNIQUE(ingest_token_hash)` is a single-column `BINARY(32)` index, not composite with
  `deleted_at`; ingest lookup strips only `TeamScope`, keeps `SoftDeletes` (no `withTrashed()`).
  Conforms (ADR-006, Owner ruling 1).
- Header allowlist matches ADR-008 exactly (Host, hop-by-hop + Content-Length, Cookie,
  inbound Authorization, provider signatures; Content-Type preserved; case-insensitive; no
  header added). Conforms.
- 202 resolved before/independent of delivery (`ResponseResolver`, `IngestController`);
  ingest route registered outside the web group (CSRF-exempt, no session). Conforms
  (ADR-004).

## Security
- Token: encrypted at rest (`encrypted` cast), `BINARY(32)` SHA-256 hash for lookup, never
  logged; rate-limiter keys on the hash, not the plaintext; ingest URL built from
  `config('ingest.url')`, never the request Host (asserted with a spoofed Host on both the
  ingest path and the management props). Good.
- CSRF exemption is scoped only to the ingest route (`routes/ingest.php`, outside the web
  group); management routes remain under `web`+`auth`. Good.
- Team authorization on every management action via `TeamScope` binding + `ProxyPolicy`
  (`viewAny`/`create`/`view`/`update`/`delete`); cross-team ids 404 before the policy runs.
  Good.

## Findings
| # | Severity | Location | Finding |
|---|---|---|---|
| 1 | Minor | `app/Actions/DeliverToDestination.php:28` | Delivery timeout is a hardcoded `const TIMEOUT_SECONDS = 15`, not config-driven. Acceptable for the skeleton (no latency targets), but should become config before real destinations are onboarded. Flagged for assessment by the task brief. |
| 2 | Minor | `app/Http/Middleware/EnsureIngestIsSecure.php:25`; no `TrustProxies` config found in `bootstrap/app.php`/`app/` | The app-layer HTTPS assert uses `$request->isSecure()`. Behind a TLS-terminating load balancer, unless trusted proxies + `X-Forwarded-Proto` are configured, `isSecure()` is false and **every** ingest request would 403. No trusted-proxy configuration exists in the repo. Ops follow-up: configure `TrustProxies` before any deployment behind an LB. Documented in the code comment; not a code defect for item #1 (no deploy target). |
| 3 | Minor | `app/Http/Controllers/ProxyController.php` (and `DestinationController`) | The `{current_team}`-prefix binding workaround (`string $current_team` as the first controller param before the bound model) is carried across all bound methods. It works and is tested, but is tech debt vs. a cleaner global Team/route binding. Recommend revisiting when #2 (roles) touches this surface. Flagged for assessment by the task brief. |
| 4 | Minor | `app/Http/Middleware/EnforceIngestBodyLimit.php:23-24` | Body-size guard trusts the client `Content-Length` header when present (falling back to `strlen(getContent())` only when absent). A sender can under-declare `Content-Length`. Acceptable given the deliberately-high placeholder cap (50 MB) and that PHP bounds the readable body, but note it when the cap is risk-tuned before MVP. |
| 5 | Minor | `config/ingest.php:31,44` | Ingest body cap (50 MB) and per-token rate limit (6000/min) are deliberately-high placeholders per the Owner decision — recorded here as a standing "revisit before MVP/public exposure" follow-up (not a defect; the values are documented as provisional). |
| 6 | Nit | `tests/Feature/Proxies/ProxyRequestValidationTest.php` | AC3a lists `ftp://example.com/hook` as an invalid example; the tests assert `http://` and scheme-less rejection but not `ftp://`. The `url:https` rule covers it structurally; a direct assertion would close the AC3a example set. |
| 7 | Nit | `resources/js/**` (build) | `pnpm/npm run build` was not runnable in this environment (Node 21 vs required ≥22); the compiled bundle is unverified here. Vue SFCs pass `vue-tsc` + eslint (T26–T30 notes). Run `npm run build` under Node ≥22 before merge to confirm the production bundle compiles (env fix, not a code fix). Do not gate approval on this alone. |
| 8 | Nit | `tests/Feature/Proxies/*` | Cross-team 404 is directly asserted for `show` and destination `destroy`; `edit`/`update`/`destroy(proxy)` rely on the same proven `TeamScope` route-binding path but lack their own dedicated cross-team assertion. A belt-and-suspenders test per verb would fully close AC16e. |

## Owner review comments (PR #1) — resolved 2026-07-31
Addressed on-branch after the initial review: (A) authorization moved to
controller Policies via auto-discovered `ProxyPolicy`, FormRequest `authorize()`
→ `true`, manual gate registration removed; (B) HTTP `Response::HTTP_*` constants
over magic numbers; (C) Inertia props via `ProxyResource`/`DestinationResource`;
(D) `Proxy::make($validated)` (larastan `noModelMake` disabled); (E)
`Rule::enum(...)` for `mode`/`http_method`; (F) `TeamScope` fail-closed
(`team_id = current_team_id ?? 0`) for authenticated team-less users + test.
Suite green (182). Owner accepted `make()` and the auth-only scope of (F).

### Future follow-up (Owner idea, not scheduled)
- **Middleware-initiated team scope (opt-in per route).** Consider moving
  `TeamScope` from an always-on global model scope to a scope applied by a
  route middleware, so the set of routes it governs is chosen explicitly (rather
  than globally applied + explicitly stripped via `withoutGlobalScope` on system
  paths). Design decision for the Principal Engineer — natural to fold in when
  item #2 (role-based collaboration) touches the team-authorization surface.

## Recommendations
- None of the findings block approval. Findings 1–8 are all Minor/Nit follow-ups.
- Before any real deployment (not before merge of item #1): address finding 2
  (`TrustProxies`) and revisit findings 4–5 (ingest cap/rate values) — these are the
  operationally material ones. Findings 1, 3, 6, 8 are code-quality/coverage follow-ups.
- Finding 7 (frontend build) should be confirmed under Node ≥22 before merge as a routine
  env step.

## Approval
- **Recommendation:** Approve with follow-ups
- **Project Owner decision / date:** _pending_

## Handoff
- **Inputs:** PRD-01, plan-01, design-01, tasks T1–T30, ADR-001…008, branch
  `feat/item-01-walking-skeleton` (PR #1).
- **Outputs:** this review.
- **Dependencies:** none blocking.
- **Outstanding Questions:** none blocking. Ops follow-ups (TrustProxies, placeholder
  cap/rate) recorded above for pre-deployment.
- **Next Agent:** Project Owner (approval decision). No rework required before approval;
  the Minor/Nit follow-ups can be scheduled by the Owner (item #1 or backlog).
