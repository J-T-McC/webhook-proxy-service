# Test Name History

> Reference index, maintained alongside `docs/standards/testing.md`. Adopted
> 2026-08-30.

Artifacts under `docs/` are written once and read long afterwards. A completed
task plan, a review table or an ADR names the test files that existed when it was
written, and those names do not change when the suite is reorganised — rewriting
them would misreport what was actually done at the time.

This file is the other half of that arrangement. When a name in `docs/` does not
resolve to a file in `tests/`, look it up here rather than assuming the document
is wrong or the coverage is missing.

**Do not "fix" a stale name in a historical artifact by rewriting it.** Add the
mapping here instead. The exception is a name that was wrong the day it was
written, which is a factual error and should be corrected in place.

## Renamed or restructured

All of the following changed on 2026-08-30 in pull request #53.

| Name in older documents | Where its coverage lives now |
| --- | --- |
| `ProxyIndexShowTest` | Split by action into `ProxyIndexTest` and `ProxyShowTest`. Index-list, permissions-prop and guest-redirect assertions went to the first; ingest URL, response config, retry-field and cross-team-404 assertions to the second. |
| `ProxyCanFlagsTest` | Split by action. The `is_creator` index-list case and the no-N+1 policy proof are in `ProxyIndexTest`; the show-payload and null-`created_by` cases are in `ProxyShowTest`. |
| `ProxyIndexPermissionsTest` | Merged into `ProxyIndexTest`, still data-provided over the three roles. |
| `ProxyControllerPagePropsTest` | Split three ways: the create-form prop test to `ProxyStoreTest`, the edit-form prop test to `ProxyUpdateTest`, the absent-on-index test to `ProxyIndexTest`. |
| `tests/Feature/Proxies/ProxyPolicyTest.php` | `tests/Unit/Policies/ProxyPolicyTest.php`. Unchanged content; it issues no HTTP request and asserts only against the Gate. |
| `tests/Unit/Pipeline/DeliverStepTest.php` | `tests/Feature/Delivery/DeliverStepFanOutTest.php`. It fakes HTTP and drains jobs for real, which makes it a feature test. The separate `tests/Unit/Actions/DeliverStepTest.php`, which fakes the queue, is untouched and still carries that name. |
| Any `XAcceptanceTest` | `XTest`. Twenty-three classes dropped the suffix; see `docs/standards/testing.md`. References inside `docs/` were updated at the same time, so this row matters mainly when reading git history. |
| `WebhookEventCaptureAcceptanceTest` | `tests/Feature/Ingest/IngestEventCaptureTest.php`, renamed rather than merely de-suffixed because `tests/Unit/Services/WebhookEventCaptureTest.php` already held the plain name. |

## Deleted

| Name in older documents | Why it is gone |
| --- | --- |
| `tests/Unit/Migrations/AnalyticsIndexesTest.php` | Removed 2026-08-30 (#53). Migration-mechanics tests hardcode migration filenames and block squashing, and their `migrate:rollback` calls escape the per-test transaction. See the rule in `docs/standards/testing.md`. |
| `tests/Unit/Migrations/SensitiveDataHandlingSchemaTest.php` | Same removal. Its two unique-index cases survive as `test_a_second_current_row_for_the_same_proxy_and_purpose_is_rejected` and `test_any_number_of_superseded_rows_with_null_is_current_are_allowed` in `tests/Unit/Models/ProxySecretTest.php`. |
| `tests/Unit/Migrations/RemoveInboundVerificationMigrationTest.php` | Same removal. Its assertion that `SecretPurpose::cases()` holds exactly one case went with it and has no replacement; adding a case would be a deliberate act, and the cast itself is exercised throughout `tests/Unit/Models/ProxySecretTest.php`. |
| `tests/Feature/ExampleTest.php` | Removed 2026-08-30 (#53). `tests/Feature/HomeTest.php` covered the same route more strictly. |
| `tests/Unit/ExampleTest.php` | Removed 2026-08-30 (#53). Asserted only that true is true. |
| `tests/Feature/Ingest/InboundVerificationIntegrationTest.php` | Removed with inbound verification itself (ADR-026, milestone `m10-inbound-verification-removal`). The capability no longer exists, so there is no replacement coverage to look for. |
| `tests/Feature/Proxies/VerificationValidationTest.php` | Same removal (ADR-026). |
| `tests/Feature/Proxies/ProxyVerificationOverlapControllerTest.php` | Same removal (ADR-026). The outbound equivalent, `ProxySigningControllerTest`, is a different capability and still exists. |
| `tests/Unit/Services/InboundVerifierTest.php` | Same removal (ADR-026). |
| `tests/Unit/Enums/VerificationSchemeTest.php` | Same removal (ADR-026). |
| `tests/Unit/Verification/SharedSecretSchemeTest.php` | Same removal (ADR-026). |
| `tests/Unit/Verification/StandardWebhooksSchemeTest.php` | Same removal (ADR-026). Outbound Standard Webhooks signing is a separate capability, tested by `tests/Unit/Support/StandardWebhooksTest.php`. |

## Proposed in a plan but never created under that path

These names appear in the **Testing** line of a task, which states where a test
was expected to go. The implementation put it elsewhere, and the task's own
completion notes are the record of that. They were never wrong about coverage,
only about the path, so leave them as written.

| Path named in the plan | What was actually built |
| --- | --- |
| `tests/Unit/Actions/DeliverToDestinationTest.php` | `tests/Feature/Delivery/DeliverToDestinationTest.php` |
| `tests/Unit/Actions/ProcessIngestedWebhookTest.php` | `tests/Feature/Ingest/ProcessIngestedWebhookTest.php` |
| `tests/Unit/Http/Requests/ReplayEventRequestTest.php` | Never created. Replay request validation is covered through the endpoint, in `tests/Feature/Replay/ProxyEventReplayControllerTest.php`. |

## Not a stale path

`docs/standards/testing.md` names
`tests/Feature/Http/Controllers/Auth/AuthenticationTest.php` as an illustration
of the namespace-mirroring layout that was considered and rejected for
`tests/Feature/`. It is deliberately a path that does not exist. The real file is
`tests/Feature/Auth/AuthenticationTest.php`.

`docs/tasks/sensitive-data-handling/m04-revealed-payload-envelope.md` carries a
paragraph headed "File path correction, not a deviation" which quotes both the
path its own Testing line named and the path the file actually occupies. Both
halves must stay as they are or the paragraph stops making sense.

`docs/status-history.md` is a frozen pre-compaction archive and is never edited.
