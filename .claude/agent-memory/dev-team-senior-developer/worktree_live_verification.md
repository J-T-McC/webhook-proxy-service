---
name: worktree-live-verification
description: How to get live browser/backend-test evidence from a git worktree when Sail's mount points at a different checkout/branch
metadata:
  type: project
---

Sail's `laravel.test` container bind-mounts the **primary checkout**
(`/Users/tyson/projects/webhook-proxy-service`), not a worktree directory. If you're
working in an isolated worktree (e.g. `.claude/worktrees/agent-*`) on its own branch
while another agent is actively committing to the primary checkout's branch,
`./vendor/bin/sail ...` from inside the worktree proves nothing about the worktree's
code — it runs against the *other* branch. Verify against the worktree directly
instead:

- `composer install` in the worktree (no vendor/ there by default) — safe, doesn't
  touch the primary checkout's vendor.
- `cp .env.example .env`, `php artisan key:generate`. Sqlite fails on this project's
  raw-SQL MySQL-specific migrations (see [[schema_migrations]] LONGBLOB ALTER) — set
  `DB_CONNECTION=mysql`/`DB_HOST=127.0.0.1`/`DB_PORT=3306` and point `DB_DATABASE` at a
  **new, throwaway database** on the already-running shared mysql container (`docker
  exec <mysql-container> mysql -uroot -ppassword -e "CREATE DATABASE ..."`) — never
  reuse `laravel`/`testing`/`testing_test_*`, those belong to the primary checkout and
  may be in concurrent use.
- `php artisan migrate:fresh --seed`, then seed real fixture data via
  `php artisan tinker --execute="..."` for whatever the UI needs (team + membership +
  proxy + destinations + event, etc.) — check factory definitions for required
  relations first (e.g. `Team::members()` not `Team::users()`).
- `pnpm run build` (needed before any live check — a stale bundle proves nothing),
  then `php artisan serve --host=127.0.0.1 --port=<free>` with `APP_URL` in `.env`
  matching that port, then the `playwright` skill against it.
- For the backend test gate, run `DB_DATABASE=<another throwaway> php artisan test
  --parallel` directly (not via sail) for the same mount-isolation reason.
- Cleanup after: kill the `artisan serve` process, `DROP DATABASE` the throwaway(s).
  `.env`, `vendor/`, `database.sqlite` are all gitignored — confirm with `git status`
  that only the intended source files are staged before committing.
