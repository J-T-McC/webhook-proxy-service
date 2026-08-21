---
name: laravel-actions-gotchas
description: lorisleiva/laravel-actions gotchas — AsCommand registration, Carbon import paths used across the app
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
