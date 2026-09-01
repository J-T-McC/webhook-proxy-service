/**
 * Destination sink for the load harness (docs/briefs/load-testing-harness.md).
 *
 * Stands in for the remote endpoints a proxy fans out to. `Http::fake()` cannot
 * do this job: it swaps the facade inside one PHP process, while deliveries are
 * made by separate `queue:work` processes that would never see it. A real
 * server also keeps the Guzzle and socket path inside the measurement.
 *
 * Node rather than `php -S`, because a sink that sleeps to simulate a slow
 * destination has to keep serving other requests while it sleeps. `php -S` is
 * single-process and would serialise them, which would measure the sink instead
 * of the application.
 *
 * Runs on the host while the application and its workers run in Sail, so the
 * destinations point at `host.docker.internal`, which `compose.yaml` already
 * maps to the host gateway. That also clears the delivery-loop guard for free:
 * `IngestHostGuard::pointsBackToIngest()` compares host strings, and
 * `host.docker.internal` is not the `localhost` that `ingest.url` carries.
 *
 * Binding defaults to all interfaces because the container cannot reach a
 * socket bound to the host's loopback. The sink holds no secrets and serves a
 * constant `ok`, but set LOAD_SINK_HOST to pin it if that matters on your
 * network.
 *
 * Deliveries arrive as POST or PUT. GET is reserved for the control endpoints,
 * so the two can never collide.
 *
 *   GET /__stats  — arrival counts, and the per-proxy ordering verdict
 *   GET /__reset  — drop all recorded arrivals
 */

import http from 'node:http';

const host = process.env.LOAD_SINK_HOST || '0.0.0.0';
const port = Number(process.env.LOAD_SINK_PORT || 9000);
const status = Number(process.env.LOAD_SINK_STATUS || 200);

// Delay is drawn from [MIN, MAX] to emulate the spread of a real remote
// endpoint. Setting both to the same value pins it, which the regression
// scenario does so that a latency change can only have come from the
// application.
const delayMinMs = Number(process.env.LOAD_SINK_DELAY_MIN_MS || 0);
const delayMaxMs = Number(process.env.LOAD_SINK_DELAY_MAX_MS || delayMinMs);

/**
 * Seeded generator, so the delay sequence is identical from run to run.
 *
 * The spread is what makes the sink behave like a real destination; drawing it
 * from an unseeded `Math.random()` would also make every run's total wait
 * different, and that difference would land in the recorded latency as noise
 * indistinguishable from an actual regression. Seeding keeps the realism and
 * drops the noise.
 */
function mulberry32(seed) {
    let a = seed;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const random = mulberry32(Number(process.env.LOAD_SINK_SEED || 20260901));

function nextDelayMs() {
    if (delayMaxMs <= delayMinMs) {
        return delayMinMs;
    }

    return delayMinMs + Math.floor(random() * (delayMaxMs - delayMinMs + 1));
}

/**
 * Arrival sequence numbers keyed by proxy, in the order they were received.
 *
 * k6 stamps `X-Load-Proxy` and `X-Load-Seq` on each ingest request, and neither
 * name is in `DeliveryUnit::STRIPPED_HEADERS`, so both are forwarded through to
 * the delivery. That makes the ordering check a property of what actually
 * arrived here, with no database query and no clock comparison.
 */
const arrivals = new Map();
let total = 0;

/**
 * A proxy's line is ordered when its sequence numbers arrive ascending.
 *
 * Only meaningful for proxies in FIFO mode; an Async proxy is expected to be
 * unordered and the harness does not assert on it.
 */
function orderingReport() {
    const perProxy = {};

    for (const [proxy, seqs] of arrivals) {
        let violations = 0;

        for (let i = 1; i < seqs.length; i++) {
            if (seqs[i] < seqs[i - 1]) {
                violations++;
            }
        }

        perProxy[proxy] = { received: seqs.length, violations };
    }

    return perProxy;
}

const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/__stats') {
        req.resume();
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ total, proxies: orderingReport() }));

        return;
    }

    if (req.method === 'GET' && req.url === '/__reset') {
        req.resume();
        arrivals.clear();
        total = 0;
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end('{"reset":true}');

        return;
    }

    // Drain the body. Without this the socket stays open and the delivery
    // blocks on a request the sink never finished reading.
    req.resume();

    const proxy = req.headers['x-load-proxy'];
    const seq = Number(req.headers['x-load-seq']);

    if (proxy !== undefined && Number.isFinite(seq)) {
        if (!arrivals.has(proxy)) {
            arrivals.set(proxy, []);
        }

        arrivals.get(proxy).push(seq);
    }

    total++;

    req.on('end', () => {
        const delay = nextDelayMs();

        if (delay > 0) {
            setTimeout(() => {
                res.writeHead(status).end('ok');
            }, delay);

            return;
        }

        res.writeHead(status).end('ok');
    });
});

server.listen(port, host, () => {
    process.stdout.write(
        `sink listening on http://${host}:${port} delay=${delayMinMs}-${delayMaxMs}ms status=${status}\n`,
    );
});

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        server.close(() => process.exit(0));
    });
}
