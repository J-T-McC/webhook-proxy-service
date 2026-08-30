# Review: Decoupled upstream response (with always-on raw capture) — item #3

- **Reviewer:** Reviewer (dev-team)
- **Date:** 2026-08-04
- **Feature / branch:** #3 `feat/item-03-decoupled-upstream-response` (PR #4 → `main`)
- **Recommendation:** **Approve with follow-ups** (final decision: Project Owner)

## Scope
Reviewed `main..feat/item-03-decoupled-upstream-response` against PRD-03 (11 ACs),
plan-03, the T1–T12 task plan, ADR-010 (incl. Amendment B), ADR-003/004, and
`docs/standards/`. Verified all task completion notes, the two orthogonal tracks
(response config T1–T8, raw capture T9–T12), and the Owner security acknowledgements
recorded in `docs/status.md` row #3 (body encrypted at capture; headers plaintext
until #10; APP_PREVIOUS_KEYS guard; mode-independent capture).

## Inputs
PRD-03, plan-03, ADR-010, ADR-003/004, task plan, `docs/standards/review.md`.
Read every changed source file plus all new/changed tests. Completion notes present
on all 12 tasks.

## Quality gates (actual, run this review — not trusting claimed results)
| Gate | Command | Result |
|---|---|---|
| Backend format | `composer lint` (Pint) | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` (PHPStan L7) | `{"result":"passed","errors":0}` |
| Backend tests | `./vendor/bin/sail test --parallel` | `269 passed`, `1041 assertions` |
| FE types | `pnpm types:check` (vue-tsc) | clean |
| FE lint | `pnpm lint:check` (eslint) | clean |
| FE format | `pnpm format:check` (prettier) | clean |

`pnpm run build` is not runnable in this sandbox (Node 21 < 22); Vue SFCs validated
via vue-tsc + eslint per the standing env limitation. No JS test framework exists
(backlog T31) — the frontend form (T7) is inspection-only, as accepted precedent.

## PRD acceptance-criteria coverage
| AC | Verdict | Evidence |
|---|---|---|
| AC1 user-defined response returned | Met | `ResponseResolver::resolve()`; `ResponseResolutionTest::test_configured_proxy_returns_exact_status_body_and_content_type` |
| AC2 response decoupled from delivery | Met | Resolver reads only `$proxy` columns; `test_response_is_identical_regardless_of_delivery_outcome` (success/500/throw) + `test_configured_response_holds_even_when_delivery_fails` |
| AC3 default 202 when unconfigured | Met | `?? Response::HTTP_ACCEPTED`; nullable no-default columns; `test_unconfigured_proxy_inherits_202_accepted_empty_body`, `ProxyStoreTest::test_creating_without_response_config_leaves_both_null` |
| AC4 configured status must be 2xx | Met | `between:200,299` on Store+Update; `ProxyRequestValidationTest` non-2xx (199/300/404) rejected, 200/299 accepted, null accepted |
| AC5 capture-before-response ordering | Met | `IngestController::__invoke` captures synchronously before resolve/dispatch/return; `IngestControllerTest::test_successful_ingest_commits_a_capture_row...`; failure path proves 2xx unreachable without commit |
| AC6 capture-write failure → 500 | Met | `try/catch(Throwable)` → `report()` + `abort(500)`, dispatch nothing; `test_capture_failure_returns_500...` (0 rows, 0 attempts, `Http::assertNothingSent()`) |
| AC7 capture in both modes | Met | No mode branch; `test_capture_happens_in_enhanced_mode_too`, `IngestEventCaptureTest::test_enhanced_mode_also_captures_the_raw_event` |
| AC8 raw/dispatched separation + immutability | Met | `webhook_events` raw-only, no dispatched-output/soft-delete columns (schema test); `body` encrypted cast; `test_captured_body_is_immutable_after_delivery_completes` |
| AC9 no parallel path | Met | Shared `ingest_id`; `test_no_parallel_path_delivery_attempts_stay_payload_free` asserts `delivery_attempts` has no body/payload/request_body/response_body column |
| AC10 delivery unchanged | Met | `PipelineFactory` comment-only change; `DeliverStep`/delivery untouched; fan-out still fire-and-forget |
| AC11 retention unchanged | Met | No GC/retention/`deleted_at` columns (schema test); config caps unchanged |

## Task acceptance-criteria coverage
All T1–T12 acceptance criteria verified against running code and tests:
config cap (T1), nullable no-default `proxies` columns verified via `information_schema`
(T2), resolver four-combination unit coverage (T3), Store+Update validation (T4),
store/update persistence incl. clear-to-null (T5), resource + TS types (T6), form
fields with a11y wiring mirroring the `name` field (T7), response acceptance tests
(T8), `webhook_events` with verified `longblob` `DATA_TYPE`, UNIQUE `ingest_id`, two
composite indexes, encrypted-body round-trip (T9), `WebhookEventCapture` service with
no `Auth` dependency (T10), synchronous pre-dispatch capture + 500 (T11), capture
acceptance tests (T12).

## Standards compliance
- **Security (architecture.md / coding.md never-log):** capture-failure path uses
  `report($e)` + `abort(500)` and never logs the raw body or token. The `body` is
  encrypted by the cast before it reaches the DB binding, so the reported exception
  cannot surface plaintext payload. `team_id`/`proxy_id` set explicitly from the
  proxy on the team-unscoped ingest path (no `Auth` read), mirroring
  `DeliverToDestination`; `BelongsToCurrentTeam` `creating` hook is a safe no-op here
  (team_id already set, no authed user). Ingest token not part of the capture insert.
  Body `encrypted` cast honored (ADR-010 Amendment B); headers plaintext-until-#10 is
  the Owner-acknowledged decision. **Pass.**
- **Data / migrations (architecture.md):** `webhook_events` FKs use default restrict
  `constrained()` (immutable fact table, matches `delivery_attempts`); no
  `SoftDeletes`/`deleted_at`; access-pattern composite indexes present; UNIQUE
  `ingest_id`; `body` `encrypted` cast, `headers`/`received_at`/`byte_size` casts via
  `casts()`. `proxies` columns nullable, no schema default, no backfill (forward-only,
  no destructive change). `LONGBLOB` via a raw `ALTER TABLE` since no Blueprint helper
  exists — MySQL-specific, consistent with the stack; `down()` drops the table.
  **Pass.**
- **Backend code (coding.md):** `WebhookEventCapture` is a Service (not an Action) —
  cannot be `::dispatch`ed, enforcing synchronous capture (ADR-010). `IngestController`
  stays thin; controller order is load-bearing and correct (capture → resolve →
  dispatch → return). Typed signatures, PHPStan L7 clean. **Pass.**
- **Frontend / a11y (design.md, manual):** two optional fields reuse `Label`/`Input`/
  `InputError`, `aria-invalid`, `aria-describedby`, focus-on-error mirroring the `name`
  field; semantic tokens inherited from shared primitives; copy conveys the
  acknowledgement-vs-delivery contract. No new UI primitive introduced. **Pass
  (inspection).**
- **Testing (testing.md):** factories use `createQuietly()`; no `RefreshDatabase`
  declared; cross-team isolation set explicitly; every AC exercised against running
  code. **Pass.**
- **Docs / process:** PRD Approved, plan self-certified, ADR-010 Accepted (Owner
  2026-08-04), task plan certified; all recorded in the artifacts and `docs/status.md`
  row #3. **Pass.**

## Findings

### Minor
- **M-1 — Response-body cap is character-counted, not byte-counted, despite the
  `_max_bytes` name.** `StoreProxyRequest`/`UpdateProxyRequest` use
  `'max:'.config('ingest.response_body_max_bytes')` (8192). Laravel's `max` on a
  string counts multibyte *characters*, so a multibyte UTF-8 body can persist up to
  ~4× the intended byte cap (~32 KiB worst case). The implementation follows plan-03
  §Validation verbatim (`max:<cap>`), and the Senior Developer flagged this in the T4
  completion note. Impact is low — the cap is an abuse/row-size bound on an
  acknowledgement contract, not a data channel — so this does not block. *Criterion:*
  plan-03 §Validation (config key named `response_body_max_bytes` implies a byte cap).
  *Follow-up:* either rename/document the cap as characters, or switch to a
  byte-accurate rule; route to the Senior Developer if the Owner wants byte-exactness.

### Observations (non-blocking, no action required)
- Configured response bodies are always labelled `Content-Type: text/plain;
  charset=utf-8` (a JSON ack is returned as text/plain). This is the deliberate,
  documented plan-03 decision under Q-03-04 (no content-type field in scope); a
  content-type selector is a future PM/Designer-gated follow-up, not a defect.
- `ProxyResource.is_creator` per-row and the index N+1 are pre-existing item #1/#2
  patterns, not introduced by #3.

## Recommendation
**Approve with follow-ups.** All 11 PRD ACs and all 12 task ACs are implemented and
independently verified; every quality gate is green (Pint, PHPStan L7, 269 tests /
1041 assertions, vue-tsc, eslint, prettier). The Owner security acknowledgements are
honored: raw bodies encrypted at capture, capture team-scoped via explicit
`team_id`/`proxy_id`, mode-independent capture, fail-closed 500 on capture failure.
One Minor follow-up (M-1) is a low-impact, plan-faithful deviation that does not block
release. The final release decision remains with the Project Owner.

## Handoff
- **Blockers/Majors:** none.
- **Minor (M-1):** to the Senior Developer only if the Owner elects byte-exact capping.
- **Decision:** Project Owner — approve PR #4 / release, accepting or scheduling M-1.
