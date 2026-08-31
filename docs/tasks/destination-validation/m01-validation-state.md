# M1 — Validation state on `destinations`

Implements plan-18 § Data Model and ADR-027 decisions 1 and 2. Nothing in this milestone changes
behaviour: it adds the column, the vocabulary and the backfill, and leaves every destination
validated exactly as it is today.

## T1 — Add the `DestinationValidationState` enum

- **Description:** String-backed enum with three cases — `Unvalidated`, `Pending`, `Validated`.
  Expired is **not** a case; it is derived in T2. Follows the architecture standard that persisted
  domain vocabulary lives in `Enums/` and is the source of truth for casts and `Rule::enum`.
- **Dependencies:** none.
- **Files:** `app/Enums/DestinationValidationState.php`
- **AC-trace:** PRD-18 AC1; ADR-027 decision 1.
- **Verify step:** `composer types:check` passes with the enum referenced from the model cast in T2.
- **Testing:** none warranted on its own — it is vocabulary with no behaviour. T2 covers it through
  the cast.

## T2 — Migration, model columns and the derived Expired accessor

- **Description:** Add `validation_state` (not null, defaults to `unvalidated`), `validated_at`,
  `validation_challenge_sent_at`, `validation_challenge_expires_at` and `validation_nonce` to
  `destinations`. Cast `validation_state` to the T1 enum and the three timestamps to `datetime`. Add
  a read accessor giving the **four** product-facing states, where Expired is `Pending` with
  `validation_challenge_expires_at` in the past. Add a query scope for the enforcement gate so the
  four call sites in M2 share one definition rather than repeating a `where`.
- **Dependencies:** T1.
- **Files:** `database/migrations/<timestamp>_add_validation_state_to_destinations_table.php`,
  `app/Models/Destination.php`, `database/factories/DestinationFactory.php`
- **AC-trace:** PRD-18 AC1, AC2; ADR-027 decision 1.
- **Verify step:** `./vendor/bin/sail artisan migrate` then tinker a destination and confirm the
  accessor reports Expired for a pending row with a past expiry and Pending for a future one.
- **Testing:** `tests/Unit/Models/DestinationValidationStateTest.php` — the derived accessor across
  all four states, and that the gate scope excludes unvalidated, pending and expired rows. Per
  `docs/standards/testing.md` do **not** test migration mechanics; test the behaviour on the model.
  The factory needs states for each case so later milestones can build fixtures.

## T3 — Backfill existing destinations to validated

- **Description:** In the same migration as T2, set every existing row to `validated` with
  `validated_at` at migration time. This is PRD-18 AC30, approved by the Owner with the PRD.
- **Dependencies:** T2.
- **Files:** the T2 migration.
- **AC-trace:** PRD-18 AC30; ADR-027 decision 2.
- **Verify step:** with rows present before migrating, confirm every row reads `validated`
  afterwards and that a newly created destination defaults to `unvalidated`.
- **Testing:** covered by T2's model tests plus one test asserting a **new** destination is
  `unvalidated` by default — the backfill must not leak into the default.
