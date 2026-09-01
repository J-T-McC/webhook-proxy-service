#!/usr/bin/env bash
#
# Load harness runner (docs/briefs/load-testing-harness.md).
#
# Boots the sink, points the application at its own database, seeds it, starts a
# fixed number of workers, drives k6 at it, waits for the queue to drain, then
# appends one result row to history.ndjson.
#
# Usage:
#   tests/load/run.sh <scenario>
#
# Scenarios: async-throughput | fifo-parallel | fifo-head-of-line | mixed | ingest-breakpoint

set -euo pipefail

SCENARIO="${1:-async-throughput}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="${ROOT}/tests/load"
HISTORY="${HERE}/history.ndjson"
WORK="$(mktemp -d)"

# The application, its workers and k6 all run inside Sail. The sink is the one
# piece on the host, reached through the host.docker.internal mapping that
# compose.yaml already declares.
NETWORK="$(basename "${ROOT}")_sail"
SINK_HOST="host.docker.internal"
SINK_PORT="${LOAD_SINK_PORT:-9000}"
SLOW_SINK_PORT="${LOAD_SLOW_SINK_PORT:-9001}"
LOAD_DB="webhook_load"
BASE_URL="http://laravel.test"

# Per-scenario settings. Delivery workers serve the `webhooks` queue; advancer
# workers serve `default`, where AdvanceProxyFifoQueue and the sweepers run.
# Both counts are fixed rather than left to Horizon, whose autoscaling would
# give two runs of identical code different concurrency.
case "${SCENARIO}" in
    async-throughput)
        FIFO_PROXIES=0; ASYNC_PROXIES=20
        DELIVERY_WORKERS=8; ADVANCER_WORKERS=2
        SINK_MIN_MS=0; SINK_MAX_MS=0
        MODE=constant; RATE="${LOAD_RATE:-50}"; DURATION="${LOAD_DURATION:-30s}"
        SCRIPT=throughput.js; ASSERT_ORDER=0
        ;;
    fifo-parallel)
        FIFO_PROXIES=20; ASYNC_PROXIES=0
        DELIVERY_WORKERS=8; ADVANCER_WORKERS=4
        SINK_MIN_MS=300; SINK_MAX_MS=600
        MODE=constant; RATE=0; DURATION="${LOAD_DURATION:-30s}"
        SCRIPT=fifo.js; ASSERT_ORDER=1
        ;;
    fifo-head-of-line)
        FIFO_PROXIES=4; ASYNC_PROXIES=0
        DELIVERY_WORKERS=8; ADVANCER_WORKERS=4
        SINK_MIN_MS=0; SINK_MAX_MS=0
        MODE=constant; RATE=0; DURATION="${LOAD_DURATION:-30s}"
        SCRIPT=fifo.js; ASSERT_ORDER=1
        ;;
    mixed)
        FIFO_PROXIES=10; ASYNC_PROXIES=10
        DELIVERY_WORKERS=8; ADVANCER_WORKERS=4
        SINK_MIN_MS=300; SINK_MAX_MS=600
        MODE=constant; RATE="${LOAD_RATE:-25}"; DURATION="${LOAD_DURATION:-30s}"
        SCRIPT=throughput.js; ASSERT_ORDER=0
        ;;
    ingest-breakpoint)
        FIFO_PROXIES=0; ASYNC_PROXIES=20
        DELIVERY_WORKERS=8; ADVANCER_WORKERS=2
        SINK_MIN_MS=0; SINK_MAX_MS=0
        MODE=ramp; RATE=0; DURATION=ramp
        SCRIPT=throughput.js; ASSERT_ORDER=0
        ;;
    *)
        echo "unknown scenario: ${SCENARIO}" >&2
        exit 2
        ;;
esac

ENV_BACKUP="${ROOT}/.env.load-backup"

restore_env() {
    if [[ -f "${ENV_BACKUP}" ]]; then
        cp "${ENV_BACKUP}" "${ROOT}/.env"
        rm -f "${ENV_BACKUP}"
    fi
}

sail() { "${ROOT}/vendor/bin/sail" "$@"; }
mysql_q() { docker compose exec -T mysql mysql -uroot -ppassword -N -B -e "$1" 2>/dev/null; }

cleanup() {
    local status=$?

    # Restore .env first and unconditionally. Everything else is disposable;
    # leaving the developer's .env pointed at the load database is not, because
    # the next `artisan migrate:fresh` in this checkout would then land on the
    # wrong database.
    restore_env

    docker compose exec -T laravel.test pkill -f 'artisan queue:work' >/dev/null 2>&1 || true
    [[ -n "${SINK_PID:-}" ]] && kill "${SINK_PID}" 2>/dev/null || true
    [[ -n "${SLOW_SINK_PID:-}" ]] && kill "${SLOW_SINK_PID}" 2>/dev/null || true
    rm -rf "${WORK}"

    exit "${status}"
}
trap cleanup EXIT INT TERM

echo "==> ${SCENARIO}"

# --- point the application at the load database -----------------------------
# Sail's web tier reads .env per request, so the swap reaches the ingest
# endpoint as well as the workers. The trap above puts it back.
#
# The backup lives beside .env rather than in the temporary directory, so it
# survives a `kill -9` that never runs the trap. A run that finds one left over
# restores it before doing anything else: the alternative is a developer whose
# .env silently points at the load database until they next notice.
if [[ -f "${ENV_BACKUP}" ]]; then
    echo "    recovering .env from an interrupted previous run"
    restore_env
fi

cp "${ROOT}/.env" "${ENV_BACKUP}"
if grep -q '^DB_DATABASE=' "${ROOT}/.env"; then
    sed -i.bak "s/^DB_DATABASE=.*/DB_DATABASE=${LOAD_DB}/" "${ROOT}/.env" && rm -f "${ROOT}/.env.bak"
else
    echo "DB_DATABASE=${LOAD_DB}" >> "${ROOT}/.env"
fi

# --- sinks ------------------------------------------------------------------
LOAD_SINK_PORT="${SINK_PORT}" LOAD_SINK_DELAY_MIN_MS="${SINK_MIN_MS}" LOAD_SINK_DELAY_MAX_MS="${SINK_MAX_MS}" \
    node "${HERE}/sink.js" > "${WORK}/sink.log" 2>&1 &
SINK_PID=$!

# The head-of-line scenario needs a second, deliberately slow sink so that one
# proxy's destination is slow while the others are not. Proving a slow
# destination stalls only its own proxy's line requires both in the same run.
if [[ "${SCENARIO}" == "fifo-head-of-line" ]]; then
    LOAD_SINK_PORT="${SLOW_SINK_PORT}" LOAD_SINK_DELAY_MIN_MS=600 LOAD_SINK_DELAY_MAX_MS=600 \
        node "${HERE}/sink.js" > "${WORK}/slow-sink.log" 2>&1 &
    SLOW_SINK_PID=$!
fi

sleep 1

# --- migrate and seed -------------------------------------------------------
sail artisan migrate --force --quiet
sail artisan load:seed \
    --fifo="${FIFO_PROXIES}" --async="${ASYNC_PROXIES}" \
    --sink="http://${SINK_HOST}:${SINK_PORT}" --json > "${WORK}/proxies.json"

# In the head-of-line scenario, move one proxy's destinations onto the slow
# sink. Done here rather than in the seeder so the seeder keeps its single
# rule: every destination it writes addresses the sink it was given.
if [[ "${SCENARIO}" == "fifo-head-of-line" ]]; then
    SLOW_PROXY="$(python3 -c "import json;print(json.load(open('${WORK}/proxies.json'))['proxies'][0]['id'])")"
    mysql_q "UPDATE ${LOAD_DB}.destinations
             SET url = REPLACE(url, ':${SINK_PORT}', ':${SLOW_SINK_PORT}')
             WHERE proxy_id = ${SLOW_PROXY};"
    echo "    proxy ${SLOW_PROXY} moved to the slow sink"
fi

# --- pre-flight: nothing may point anywhere but a sink ----------------------
# The third of the three measures keeping a run off a real endpoint, after the
# dedicated database and the seeder's own rule. This one catches a row that
# arrived by any other route.
STRAY="$(mysql_q "SELECT COUNT(*) FROM ${LOAD_DB}.destinations
                  WHERE url NOT LIKE 'http://${SINK_HOST}:%' AND deleted_at IS NULL;")"
if [[ "${STRAY}" != "0" ]]; then
    echo "REFUSING TO RUN: ${STRAY} destination(s) in ${LOAD_DB} do not address the sink." >&2
    echo "A load run against a real endpoint would send it thousands of webhooks." >&2
    exit 1
fi

# --- workers ----------------------------------------------------------------
for _ in $(seq 1 "${DELIVERY_WORKERS}"); do
    docker compose exec -d laravel.test php artisan queue:work redis --queue=webhooks --tries=1 --quiet
done
for _ in $(seq 1 "${ADVANCER_WORKERS}"); do
    docker compose exec -d laravel.test php artisan queue:work redis --queue=default --tries=1 --quiet
done

# --- measure ----------------------------------------------------------------
# Query counts come from MySQL's own counters rather than from instrumentation
# inside the application. Nothing test-only has to exist in production code,
# and the number covers the workers as well as the ingest requests.
counters() { mysql_q "SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_select','Com_insert','Com_update');" | awk '{print $2}' | paste -sd, -; }
BEFORE="$(counters)"
curl -s "http://127.0.0.1:${SINK_PORT}/__reset" >/dev/null

STARTED_AT="$(date +%s)"

docker run --rm --network "${NETWORK}" \
    -v "${HERE}/scenarios:/scripts:ro" -v "${WORK}:/seed" \
    -e LOAD_BASE_URL="${BASE_URL}" -e LOAD_SEED_FILE=/seed/proxies.json \
    -e LOAD_MODE="${MODE}" -e LOAD_RATE="${RATE}" -e LOAD_DURATION="${DURATION}" \
    -e K6_SUMMARY_EXPORT=/seed/summary.json \
    grafana/k6:latest run --summary-export=/seed/summary.json "/scripts/${SCRIPT}" \
    2>&1 | tee "${WORK}/k6.log" || true

# --- drain ------------------------------------------------------------------
# Watching the sink rather than the queue: it is the only observer that sees a
# delivery actually complete, and it needs no knowledge of queue internals.
echo "==> draining"
LAST=-1; STABLE=0; WAITED=0
while (( STABLE < 5 && WAITED < 180 )); do
    NOW="$(curl -s "http://127.0.0.1:${SINK_PORT}/__stats" | python3 -c 'import json,sys;print(json.load(sys.stdin)["total"])')"
    if [[ "${NOW}" == "${LAST}" ]]; then STABLE=$((STABLE + 1)); else STABLE=0; fi
    LAST="${NOW}"; sleep 2; WAITED=$((WAITED + 2))
done

FINISHED_AT="$(date +%s)"
AFTER="$(counters)"
STATS="$(curl -s "http://127.0.0.1:${SINK_PORT}/__stats")"

# --- ordering verdict -------------------------------------------------------
ORDER_VERDICT="not-asserted"
if (( ASSERT_ORDER == 1 )); then
    VIOLATIONS="$(echo "${STATS}" | python3 -c 'import json,sys;print(sum(p["violations"] for p in json.load(sys.stdin)["proxies"].values()))')"
    if [[ "${VIOLATIONS}" == "0" ]]; then
        ORDER_VERDICT="ok"
    else
        ORDER_VERDICT="VIOLATED:${VIOLATIONS}"
    fi
fi

# --- record -----------------------------------------------------------------
python3 "${HERE}/record.py" \
    --history "${HISTORY}" --scenario "${SCENARIO}" \
    --summary "${WORK}/summary.json" \
    --before "${BEFORE}" --after "${AFTER}" \
    --elapsed "$((FINISHED_AT - STARTED_AT))" \
    --sink-min "${SINK_MIN_MS}" --sink-max "${SINK_MAX_MS}" \
    --delivery-workers "${DELIVERY_WORKERS}" --advancer-workers "${ADVANCER_WORKERS}" \
    --rate "${RATE}" --mode "${MODE}" --order "${ORDER_VERDICT}" \
    <<< "${STATS}"

if [[ "${ORDER_VERDICT}" == VIOLATED* ]]; then
    echo "FIFO ORDERING VIOLATED — ${ORDER_VERDICT}" >&2
    exit 1
fi
