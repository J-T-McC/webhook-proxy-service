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
`queue:work` process, so neither one would see the fake. A real sink server stands in
for destination endpoints instead, which also keeps the
Guzzle and socket path in the measurement.

**The sink is a Node script, not `php -S`.** A sink that sleeps to simulate a
slow destination must serve other requests while it sleeps. `php -S` is
single-process and would block, which would mean measuring the sink rather than
the application.

**The sink runs on the host and is reached at `host.docker.internal`.**
`compose.yaml` already maps that name to the host gateway, so no new service is
added to Sail. It also clears the delivery-loop guard without any special
handling: `IngestHostGuard::pointsBackToIngest()` compares host strings, and
`host.docker.internal` is not the `localhost` that `ingest.url` carries. A
loopback alias such as `127.0.0.2` was the first approach and was dropped —
macOS does not alias it without `sudo ifconfig`, which is friction a local
harness should not carry.

**No real destination can be contacted, and that does not rest on seeding
discipline.** Three independent measures, because a load run that fires
thousands of webhooks at a real endpoint someone added during manual testing is
not a mistake worth risking once:

1. The harness runs against its own database, never the development one.
2. The seeder only ever creates destinations addressed to the sink.
3. `run.sh` refuses to start if any enabled destination in that database
   resolves to a host other than the configured sink host.

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

1. `async-throughput` — many Async proxies, sink delay pinned to 0, 8 delivery workers.
   The primary regression scenario: application time dominates the measurement.
2. `fifo-parallel` — many FIFO proxies, sink delay 300–600ms, 4 advancer
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

Sink delays are drawn from a range so the sink behaves like a real endpoint
rather than an instant one (Owner ruling, 2026-09-01), but the generator is
seeded, so the same sequence of delays is served on every run. That keeps the
realism without letting the spread land in the recorded latency as noise that
looks like a regression. The regression scenario pins the range to zero, so any
latency change there can only have come from the application.

FIFO scenarios assert that delivery order per proxy matches ingest order once
the queue drains, and fail the run loudly if it does not. An ordering
regression under concurrency is the failure that matters most here, and a
timing-only benchmark would not see it.

## Tasks

- T1 Node sink server, with delay and port from the environment.
- T2 `load:seed` artisan command — teams, proxies in both modes, pre-validated
  destinations pointing at the sink. Mirrors `e2e:seed`, including its
  production guard and `--json` output.
- T3 ~~Query-count instrumentation for the ingest path~~ — not needed. MySQL's
  own global counters give the same number with no application code at all,
  and they cover the worker processes as well as the ingest requests.
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

## Findings from validation

Three things only surfaced by running the harness against the application, all
fixed:

- The ingest route asserts HTTPS (`EnsureIngestIsSecure`), so every request
  returned 403. The scripts send `X-Forwarded-Proto: https`, which
  `bootstrap/app.php` already trusts from any proxy — the same signal a
  TLS-terminating load balancer sends. The guard itself is untouched.
- The FIFO scenario had no think time. Ingest returns in milliseconds, so
  unpaced virtual users enqueued a backlog far larger than the workers could
  ever clear. It is now paced above the drain rate but bounded.
- An interrupted run could leave `.env` pointing at the load database. The
  backup now sits beside `.env` rather than in a temporary directory, and a run
  that finds one restores it before doing anything else.

Also worth knowing when reading results: ingest is throttled at
`ingest.rate_limit_per_minute` (6000 a minute) **per token**. Scenarios spread
load across twenty proxies so the limiter is not what gets measured.

## Baselines

Recorded 2026-09-01 on the Owner's machine, in `tests/load/history.ndjson`.

| Scenario | Ingested | Delivered | p50 | p95 | Selects/ingest | Order |
|---|---|---|---|---|---|---|
| `async-throughput` | 450 | 900 | 16.4ms | 57.0ms | 5.0 | — |
| `fifo-parallel` | 480 | 960 | 27.6ms | 97.4ms | 6.0 | ok |
| `fifo-head-of-line` | 96 | 192 | 35.9ms | 84.7ms | 6.0 | ok |
| `mixed` | 500 | 1000 | 16.6ms | 23.7ms | 5.5 | — |
| `ingest-breakpoint` | 4496 | 9792 | 49.8ms | 2102ms | 5.44 | — |

Two findings worth carrying forward:

**Delivery is not the constraint.** Under the breakpoint ramp the workers
drained 123.9 a second while ingest sustained 74.9. The ramp aborted on p95
latency with zero failed requests and a flat 49.8ms median, which is a queue
forming in front of the web tier rather than the application failing. Caching
the queue-processing path therefore speeds up a stage that is already not
limiting, and the breakpoint number is unlikely to move much even if the
caching works. Judge that work on `selects_per_ingest` and `ingest_p50_ms`.

**FIFO and Async do not contend badly.** The `mixed` scenario produced the
lowest p95 of any scenario, not the highest.

**What `fifo-head-of-line` does not yet show.** It confirms that a slow
destination breaks nothing — every delivery arrives and ordering holds — but it
records no per-proxy timing, so the claim that a slow destination stalls only
its own line is demonstrated rather than measured. Quantifying it needs a
per-proxy first-to-last arrival span in the sink.

## Deferred

Moving the harness into CI is out of scope. The k6 scripts and sink
are kept machine-agnostic and all environment detail sits in one file, so that
move would be a workflow file plus a different environment.
