# Load testing harness

## What and why

A local k6 harness the Project Owner runs by hand after a change, to catch code
that slows down ingest and queue processing before it ships. Results are
appended to a history file committed to the repository, so a change can be
compared against every run that came before it.

The immediate driver is a planned caching change that will reduce database
reads during ingest and queue processing. This harness establishes the baseline
that change will be measured against, and it must be able to push the ingest
path hard enough to find where it falls over, before and after.

This is deliberately local-only. It is not wired into CI, and it is not
capacity planning for a production deployment — no deployment target has been
chosen (`docs/stack/stack.md`, Deployment row).

## Decisions

**No `Http::fake()`.** It swaps the facade inside a single PHP process. k6
drives the application over real HTTP, and delivery happens in a separate
`queue:work` process, so neither one would see the fake. A real sink server on
loopback stands in for destination endpoints instead, which also keeps the
Guzzle and socket path in the measurement.

**The sink is a Node script, not `php -S`.** A sink that sleeps to simulate a
slow destination must serve other requests while it sleeps. `php -S` is
single-process and would block, which would mean measuring the sink rather than
the application.

**The sink binds to `127.0.0.2`, not `127.0.0.1`.** `IngestHostGuard::pointsBackToIngest()`
compares hosts and ignores ports, so a sink sharing the application's host would
be refused by the delivery-loop guard.

**The seeder creates destinations already in the `Validated` state.**
`OutboundAddressGuard` refuses loopback addresses and has no environment
override. It is only consulted by `SendDestinationValidationChallenge`, not by
`DeliverToDestination`, so seeding past the challenge is enough and no
production code needs a test-only branch.

**No test-only branch in application code.** No environment flag that swaps the
HTTP client or disables real delivery. The sink is the only substitution.

**Fixed worker counts, not Horizon.** `config/horizon.php` balances processes
automatically, so two runs of identical code would get different concurrency and
would not be comparable. The harness starts an explicit number of
`queue:work` processes per queue and records those counts in every result row.

**Runs against the Sail environment as it stands.** No Octane, no FrankenPHP,
no separate web server — Owner ruling, 2026-09-01. Sail already provides MySQL
and Redis, so the queue and cache both run on Redis as production intends.

The consequence is worth stating plainly rather than discovering later: Sail
serves requests through a single-process `artisan serve`, so requests queue at
the web tier. Ingest requests per second is therefore a number comparable
between two runs on this machine, not a capacity figure, and a caching change
that removes database reads may barely move it because the bottleneck sits in
front of the application.

The two signals that do not have that problem carry the weight instead. Queries
per ingest is deterministic and drops the moment caching works. Queue drain
rate is measured in the worker processes, which the web server does not touch
at all — and queue processing is half of what the caching change targets.

**Query count per ingest is a first-class metric.** The planned caching change
removes database reads. At modest load that barely moves latency, but the query
count drops immediately and is deterministic, which makes it a far better
signal on a developer laptop than milliseconds. Latency is still recorded.

## Scenarios

Both processing modes must be exercised with real overlap — events arriving
while earlier events for the same proxy are still in flight. The k6 arrival
rate is set above the drain rate so a backlog actually forms; without one,
nothing is contended and the run proves nothing.

1. `async-throughput` — many Async proxies, sink delay 0, 8 delivery workers.
   The primary regression scenario: application time dominates the measurement.
2. `fifo-parallel` — many FIFO proxies, sink delay a fixed 150ms, 4 advancer
   and 8 delivery workers. Confirms that several proxies advance in parallel
   while each proxy's own line stays ordered, which is what
   `config/horizon.php` claims when it sets `maxProcesses` above 1 for
   `supervisor-default`.
3. `fifo-head-of-line` — a few FIFO proxies, one pointed at a slow sink,
   the rest at a fast one. Confirms a slow destination stalls only its own
   proxy's line.
4. `mixed` — FIFO and Async proxies sharing one worker pool.
5. `ingest-breakpoint` — a ramping arrival rate against the ingest endpoint
   until error rate or p95 latency passes a threshold. Records the highest
   sustained rate reached. This is the before-and-after number for the
   caching change.

Sink delays are fixed, never randomised: a random 300–600ms delay would add
±150ms of variance to the signal and hide exactly the regressions the harness
exists to catch.

FIFO scenarios assert that delivery order per proxy matches ingest order once
the queue drains, and fail the run loudly if it does not. An ordering
regression under concurrency is the failure that matters most here, and a
timing-only benchmark would not see it.

## Tasks

- T1 Node sink server, with delay and port from the environment.
- T2 `load:seed` artisan command — teams, proxies in both modes, pre-validated
  destinations pointing at the sink. Mirrors `e2e:seed`, including its
  production guard and `--json` output.
- T3 Query-count instrumentation for the ingest path, enabled only by the
  harness environment.
- T4 k6 scripts for the five scenarios.
- T5 `run.sh` — boot the sink, seed, start workers, run k6, drain, verify FIFO
  order, tear down, append a result row. Plus a `composer perf` wrapper.
- T6 `history.ndjson` and `README.md`.

## Done when

- All five scenarios run from a single command and append one row each.
- Rows are newline-delimited JSON carrying the git SHA, branch, scenario, sink
  delay, arrival rate, both worker counts, latency percentiles, drain rate,
  queries per ingest, and the FIFO order-check result.
- FIFO order violations fail the run.
- The README states the honest limits: same-machine comparisons only, median of
  three runs, discard the warm-up run, and treat deltas under roughly 15% as
  machine variance rather than signal.
- `composer lint`, `composer types:check` and the existing suite pass.

## Deferred

Moving the harness into CI is out of scope. The k6 scripts and sink
are kept machine-agnostic and all environment detail sits in one file, so that
move would be a workflow file plus a different environment.
