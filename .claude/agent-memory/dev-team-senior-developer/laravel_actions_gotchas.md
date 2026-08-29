---
name: laravel-actions-gotchas
description: lorisleiva/laravel-actions gotchas — AsCommand registration, Carbon import paths, asJob() vs handle() dual entry points, Queue::fake() scoping limits, afterCommit-under-sync test behavior, PHPStan impure-check annotation
metadata:
  type: project
---

- **`AsAction` already composes `AsCommand`** (and `AsObject`/`AsController`/`AsListener`/`AsJob`/
  `AsFake`) — do not `use AsCommand;` separately alongside `AsAction`, it's redundant.
- **Declaring `public string $commandSignature` on an `AsAction` class does NOT auto-register an
  Artisan command.** `Lorisleiva\Actions\ActionManager::registerCommands('app/Actions')` must be
  called explicitly (scans for classes using the `AsCommand` trait with a `$commandSignature`
  property/`getCommandSignature()` method, then hooks `Artisan::starting`). This app has no such
  call anywhere pre-#5 (no prior `AsCommand` usage) — add
  `Lorisleiva\Actions\Facades\Actions::registerCommands();` to `routes/console.php` (the file
  `bootstrap/app.php` already wires as the `commands:` file) the first time an action needs a
  console entrypoint. Without it, `$this->artisan('your:signature')` in a test resolves to "command
  not defined" even though the class compiles fine.
- **Carbon import paths actually used in this codebase are `Carbon\CarbonImmutable` and
  `Carbon\CarbonInterval`** (nesbot/carbon), NOT `Illuminate\Support\CarbonImmutable`/
  `CarbonInterval` — those classes don't exist under `Illuminate\Support`. Only plain `Carbon` has
  an `Illuminate\Support\Carbon` subclass; reach for `Carbon\...` for the Immutable/Interval
  variants (see `App\Providers\AppServiceProvider` for the existing precedent). Consequence for
  test helpers: `now()` itself resolves to `Carbon\CarbonImmutable` here (not
  `Illuminate\Support\Carbon`) — type a helper parameter fed a `now()->sub...()` value as
  `Carbon\CarbonInterface` (the common interface both classes share), not
  `Illuminate\Support\Carbon`, or it rejects the argument with a `TypeError` (hit in T15's
  `SweepDueRetriesTest::retryingDelivery()`).
- **`AsJob`-only actions (no `AsAction`) have no `::run()`/`::make()` static helper** — `AsJob`
  (`vendor/lorisleiva/laravel-actions/src/Concerns/AsJob.php`) provides
  `dispatch`/`dispatchSync`/`assertPushed`/etc. but not `AsObject`'s `run`/`make` (those come only
  via `AsAction`, which composes `AsObject + AsJob + ...`). To invoke the job body directly (e.g.
  in a test, bypassing the queue entirely), container-resolve and call `handle()` yourself:
  `app(RetryDelivery::class)->handle($id, $n)` — the same container-resolution parity `::run()`
  would give, just without the `AsObject` convenience wrapper. Used for T14's `RetryDeliveryTest`.
- **`JobDecorator::handle()` prefers a method named `asJob()` over `handle()` when both exist**
  (`hasMethod('asJob')` checked first). This is the sanctioned way to give one action TWO distinct
  entry-point shapes: `::run(DeliveryUnit $unit)` for an in-process/already-resolved call (goes
  straight to `handle()` via `AsObject::run()`), and `::dispatch(int $id, int $n)` for a queued,
  by-reference call (goes through `JobDecorator::handle()`, which picks `asJob()`). Used by
  `DeliverToDestination` (ADR-020) to keep `handle(DeliveryUnit $unit)`'s signature/behaviour
  untouched while adding a scalar-args queue entry point alongside it.
- **`Queue::fake([SomeAction::class])` / `Queue::fake()->except([SomeAction::class])` do NOT scope
  to a lorisleiva action** — confirmed empirically (a probe test). Laravel's `QueueFake` matches via
  `$job instanceof $class`, but the object actually pushed is the `JobDecorator` wrapper, which is
  never `instanceof` the wrapped action. Both calls silently degrade to "fake nothing" (list is
  non-empty but nothing ever matches) or "fake everything" depending on which method — there is no
  partial fake for lorisleiva jobs via the native API. To let one action's queued job run for real
  while freezing another's, don't try to scope the fake: blanket-fake, then manually drain the one
  you want executed via `Queue::pushed(ActionManager::$jobDecorator, fn (JobDecorator $j) => ...)`
  and call the job's real entry point yourself (see `tests/Concerns/DrainsQueuedDeliveries.php`).
- **A dispatched-with-`->afterCommit()` job on the `sync` queue connection fires synchronously
  within a `RefreshDatabase`-wrapped test**, even though the test's own wrapping transaction never
  truly commits (confirmed empirically, contrary to the naive reading of
  `DatabaseTransactionsManager`'s level-based commit gating) — do not add `Queue::fake()` "just in
  case" to a test that wants to observe a real `afterCommit()` dispatch's side effects; it isn't
  needed and, if added, faking recording semantics differ from a real dispatch (see the entries
  above and below).
- **A DTO/value-object field fed from an Eloquent model's `datetime`-cast property should be typed
  `Carbon\CarbonInterface`, not `Illuminate\Support\Carbon`** — even though `AppServiceProvider`'s
  `Date::use(CarbonImmutable::class)` makes every such property `CarbonImmutable` at runtime,
  Larastan's own inference of a model's date-cast property still widens to a
  `CarbonImmutable|Illuminate\Support\Carbon` union, so a constructor param typed as the plain
  mutable `Carbon` fails PHPStan level 7 with an `argument.type` error the moment you pass it a
  value straight from a model property. `CarbonInterface` (implemented by both) is what runtime and
  static analysis agree on without a suppression (found building `App\Data\SecretStatus`, item #10
  T22).
- **PHPStan level 7's "remembering/forgetting returned values" flags a private method that queries
  the DB and is deliberately called twice expecting a possibly-different answer** (a check, a
  mutation, a re-check) as `booleanNot.alwaysFalse` on the second call — it assumes the method is
  pure. Annotate it `@phpstan-impure` rather than restructuring the check-then-recheck shape (this
  is exactly the point of a race-safety re-check, e.g. `AdvanceProxyFifoQueue::settleOrHold()`,
  ADR-020 Decision 3).
