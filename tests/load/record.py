#!/usr/bin/env python3
"""Append one run's result to history.ndjson (docs/briefs/load-testing-harness.md).

Newline-delimited JSON, one object per run, append-only. A run never rewrites an
earlier line, so the file merges cleanly and the history stays trustworthy.

Every row carries the git SHA. A recorded number with no record of what the code
was at the time is noise, not history.
"""

import argparse
import json
import subprocess
import sys
from datetime import datetime, timezone


def git(*args: str) -> str:
    try:
        return subprocess.check_output(["git", *args], text=True).strip()
    except subprocess.CalledProcessError:
        return "unknown"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--history", required=True)
    parser.add_argument("--scenario", required=True)
    parser.add_argument("--summary", required=True)
    parser.add_argument("--before", required=True)
    parser.add_argument("--after", required=True)
    parser.add_argument("--elapsed", type=int, required=True)
    parser.add_argument("--sink-min", type=int, required=True)
    parser.add_argument("--sink-max", type=int, required=True)
    parser.add_argument("--delivery-workers", type=int, required=True)
    parser.add_argument("--advancer-workers", type=int, required=True)
    parser.add_argument("--rate", required=True)
    parser.add_argument("--mode", required=True)
    parser.add_argument("--order", required=True)
    args = parser.parse_args()

    stats = json.loads(sys.stdin.read() or "{}")
    delivered = stats.get("total", 0)

    try:
        with open(args.summary) as handle:
            summary = json.load(handle)
    except (OSError, json.JSONDecodeError):
        summary = {}

    metrics = summary.get("metrics", {})
    duration = metrics.get("http_req_duration", {})
    requests = metrics.get("http_reqs", {})
    failed = metrics.get("http_req_failed", {})

    ingested = int(requests.get("count", 0))

    # MySQL's own counters, sampled either side of the run. Deltas cover the
    # ingest requests and the worker processes together, which is the whole
    # point: the caching change this harness exists to measure targets both.
    before = [int(value) for value in args.before.split(",") if value]
    after = [int(value) for value in args.after.split(",") if value]
    labels = ["select", "insert", "update"]
    queries = {
        label: after[index] - before[index]
        for index, label in enumerate(labels)
        if index < len(before) and index < len(after)
    }

    row = {
        "ts": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "sha": git("rev-parse", "--short", "HEAD"),
        "branch": git("rev-parse", "--abbrev-ref", "HEAD"),
        "dirty": git("status", "--porcelain") != "",
        "scenario": args.scenario,
        "mode": args.mode,
        "rate": args.rate,
        "sink_delay_ms": [args.sink_min, args.sink_max],
        "delivery_workers": args.delivery_workers,
        "advancer_workers": args.advancer_workers,
        "ingested": ingested,
        "delivered": delivered,
        "elapsed_s": args.elapsed,
        # From k6's own rate, not from elapsed: elapsed covers the drain wait
        # as well, which would understate the rate actually driven.
        "ingest_rps": round(requests.get("rate", 0), 1),
        "drain_rate_per_s": round(delivered / args.elapsed, 1) if args.elapsed else 0,
        "ingest_p50_ms": round(duration.get("med", 0), 2),
        "ingest_p95_ms": round(duration.get("p(95)", 0), 2),
        "ingest_p99_ms": round(duration.get("p(99)", 0), 2),
        "error_rate": round(failed.get("value", 0), 4),
        "queries": queries,
        # The headline number for the caching work: reads per event ingested.
        # Deterministic where latency is not, so it moves the moment caching
        # works and does not move when the machine is merely busy.
        "selects_per_ingest": round(queries.get("select", 0) / ingested, 2) if ingested else None,
        "fifo_order": args.order,
    }

    with open(args.history, "a") as handle:
        handle.write(json.dumps(row) + "\n")

    print(json.dumps(row, indent=2))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
