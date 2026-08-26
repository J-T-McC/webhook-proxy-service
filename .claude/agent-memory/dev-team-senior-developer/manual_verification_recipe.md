---
name: manual-verification-recipe
description: How to run a required manual-verification section (no frontend test harness in this project) — seed via sail tinker, check via headless Playwright with precise selectors
metadata:
  type: user
---

This project has no frontend test harness (deferred backlog item); tasks whose acceptance criteria
depend on rendered UI carry an explicit **manual verification** section instead. The repeatable
recipe:

1. **Seed the exact scenario via `./vendor/bin/sail tinker --execute '...'`** — build the real
   Eloquent rows the page reads (not fixtures the test suite already covers), using the project's
   factories/`createQuietly()` plus `forceFill(...)->save()` for fields a factory won't let you set
   directly (e.g. backdating `created_at`). Print back the ids/slugs you'll need for the URL as JSON
   at the end of the script.
   - To prove an ordering/derivation change actually reads the field you changed (not a coincidental
     proxy like insertion order or `id`), deliberately invert the two: give the row that should sort
     first a *higher* id but the *newer* value of the field under test. If the old (wrong) mechanism
     and the new (correct) one would disagree on the order, only the new one passes.
2. **Log in and view the page via the `playwright` skill**, headless, using a real login flow
   (`User::factory()->create()`'s default password is `password`) — never fake the session.
3. **Select precisely, not by broad text/class matching.** A generic `body.textContent` or
   class-substring selector will match unrelated UI (buttons, menu items) that happen to share the
   target's words — e.g. a "Replay" action button on the same page as "Replay — {time}" group labels.
   Read the actual Vue template first and select the exact tag the label lives in (e.g.
   `h3.text-sm.font-medium.text-muted-foreground`), or scope to a specific ancestor container.
4. **Clean up afterward** — `forceDelete()` the seeded rows (children before parents, FK order) and
   the throwaway user/team via another `sail tinker --execute` call, so the shared local dev database
   isn't left polluted for the next agent/session. `Team` has no `users()` relation — detach via
   `DB::table('team_members')->where('team_id', $team->id)->delete()`, not `$team->users()->detach()`.
5. **To prove a client-side submit-normalisation actually changes the wire payload** (not just
   in-memory form state), attach a Playwright `page.on('request', ...)` listener before the click and
   read `req.postDataJSON()` for the real `POST`/`PUT` — this is the only way to see what left the
   browser versus what the DB ends up holding; pair it with a `page.on('response', ...)` listener for
   the status code (a normalisation bug shows up as an unexpected 422, a persistence bug as a wrong
   status-200 body afterward).

Record the concrete seed shape, the exact selector/assertion, and the observed output verbatim in
the task's completion notes — "manually verified" with no steps is not verification.

**Standing trap (review-07 Finding 8): a fresh-build claim can actually be served from the Vite
dev server, not the build you just ran.** If `public/hot` exists on disk and a `pnpm run dev`
process is still running, Laravel's Vite helper serves assets from the dev server regardless of
what `pnpm run build` just wrote to `public/build` — a "verified against a fresh build" claim in
that state is false, and this exact trap once hid a production-only bug (`withAlpha`, PR #12).
Before trusting any live/manual verification pass: `ls public/hot` (should not exist) and confirm
no dev server is running; if `public/hot` is present, remove it or stop the dev process before
running `pnpm run build` and re-checking.

See also [[frontend_checks]] for the scoped-eslint / stale-worktree gotcha that often comes up in the
same tasks that require this recipe.
