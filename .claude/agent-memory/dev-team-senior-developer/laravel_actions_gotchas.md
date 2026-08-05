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
  variants (see `App\Providers\AppServiceProvider` for the existing precedent).
