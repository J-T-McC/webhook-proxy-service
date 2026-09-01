# Load harness

A local k6 harness for catching code that slows down ingest and queue
processing. Run it by hand after a change; each run appends one row to
`history.ndjson`, which is committed, so a change can be compared against every
run before it.

See `docs/briefs/load-testing-harness.md` for why it is built this way.

## Running it

Sail must be up, and Node must be available on the host (the sink runs there).

```bash
composer perf                        # async-throughput, the default
composer perf fifo-parallel
composer perf ingest-breakpoint
```

Or directly, which is also how you override the defaults:

```bash
LOAD_DURATION=60s LOAD_RATE=100 tests/load/run.sh async-throughput
```

| Scenario | What it answers |
|---|---|
| `async-throughput` | Did the ingest and delivery path get slower? Sink is instant, so application time dominates. **This is the regression scenario.** |
| `fifo-parallel` | Do several FIFO proxies advance in parallel while each proxy's own line stays ordered? |
| `fifo-head-of-line` | Does one slow destination stall only its own proxy's line? |
| `mixed` | Do FIFO and Async contend badly when they share a worker pool? |
| `ingest-breakpoint` | How hard can ingest be pushed before it gives out? **This is the before-and-after number for caching work.** |

## Reading the results

`history.ndjson` is append-only, one JSON object per run. The fields that carry
the most weight:

- **`selects_per_ingest`** — database reads per event, from MySQL's own global
  counters. Deterministic, so it moves the moment caching works and does not
  move because the machine was busy. This is the best signal in the file.
- **`drain_rate_per_s`** — deliveries completed per second, measured in the
  worker processes. The web tier cannot distort it.
- **`ingest_p50_ms` / `ingest_p95_ms`** — latency, noisier than the two above.
- **`fifo_order`** — `ok`, `not-asserted`, or `VIOLATED:<n>`. A violation fails
  the run with a non-zero exit.

## What these numbers are and are not

**They are comparable only to other runs on this machine.** A developer laptop
under Docker is not a production host. Treat the file as a personal trend line,
not a benchmark anyone else can reproduce.

**Take the median of three runs, and throw away a warm-up run.** The first run
after booting Sail has a cold buffer pool and a cold opcache, and it will lie
to you.

**Deltas under roughly 15% are machine variance, not signal.** Do not chase
them. `selects_per_ingest` is the exception: it is a count, not a timing, so
any change in it is real.

**Ingest requests per second is not a capacity figure.** Sail serves through a
single-process `artisan serve`, so requests queue at the web tier before the
application is contended. The number is useful for comparing two runs here and
worthless for sizing a production deployment.

**The ingest endpoint is throttled** at `ingest.rate_limit_per_minute` (6000 a
minute, 100 a second) **per token**. Scenarios spread load across 20 proxies so
the throttle is not the binding constraint, but a scenario aimed at one proxy
would measure the rate limiter rather than the application.

Run it plugged in, with a quiet machine.

## Safety

A load run fires thousands of webhooks. Three independent measures keep it off
any real endpoint:

1. It runs against the `webhook_load` database. `load:seed` clears every table
   it seeds, so it refuses to run against a connection with any other database
   name — the check is a constant in the command, with no override.
2. `load:seed` only ever writes destinations addressed to the sink.
3. `run.sh` counts destinations that do not address the sink host and refuses
   to start if there are any.

`run.sh` points `.env` at the load database for the duration of the run and
restores it on exit, including on failure and on interrupt.

## Parts

| File | Role |
|---|---|
| `run.sh` | Orchestrates a run and appends the result row |
| `sink.js` | Stands in for destination endpoints, on the host |
| `scenarios/throughput.js` | Arrival-rate ingest load, constant or ramping |
| `scenarios/fifo.js` | One virtual user per FIFO proxy, so send order is provable |
| `record.py` | Builds the history row |
| `history.ndjson` | The committed result history |

k6 runs from the `grafana/k6` image on the Sail network, so nothing needs
installing on the host beyond Node and Docker.
