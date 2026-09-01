import { check } from 'k6';
import { SharedArray } from 'k6/data';
import http from 'k6/http';

/**
 * Async throughput and breakpoint scenarios
 * (docs/briefs/load-testing-harness.md).
 *
 * Drives ingest at a fixed arrival rate, or ramps it until the application
 * stops keeping up. Unlike the FIFO scenario this does not assert ordering —
 * an Async proxy is not expected to preserve any — so virtual users are shared
 * freely across proxies and the executor is free to add more of them.
 *
 * LOAD_MODE=constant  fixed rate for the regression comparison
 * LOAD_MODE=ramp      rising rate to find where the ingest path gives out
 *
 * The ramp's stages are deliberately long enough for a stage to reach steady
 * state. A ramp that climbs faster than the queue drains measures the ramp
 * rather than the application.
 */

const proxies = new SharedArray('proxies', () => {
    const seeded = JSON.parse(
        open(__ENV.LOAD_SEED_FILE || '/seed/proxies.json'),
    );

    return seeded.proxies.filter((proxy) => proxy.mode === 'async');
});

const rate = Number(__ENV.LOAD_RATE || 50);

const constant = {
    executor: 'constant-arrival-rate',
    rate,
    timeUnit: '1s',
    duration: __ENV.LOAD_DURATION || '30s',
    preAllocatedVUs: Math.max(10, rate),
    maxVUs: Math.max(50, rate * 4),
};

const ramp = {
    executor: 'ramping-arrival-rate',
    startRate: Number(__ENV.LOAD_START_RATE || 25),
    timeUnit: '1s',
    preAllocatedVUs: 50,
    maxVUs: 500,
    stages: [
        { target: 50, duration: '20s' },
        { target: 100, duration: '20s' },
        { target: 200, duration: '20s' },
        { target: 400, duration: '20s' },
        { target: 800, duration: '20s' },
    ],
};

export const options = {
    // p(99) is not in k6's default summary export, and the history row records it.
    summaryTrendStats: ['med', 'p(95)', 'p(99)', 'max'],
    scenarios: { ingest: __ENV.LOAD_MODE === 'ramp' ? ramp : constant },

    // On a ramp these are the breakpoint definition rather than a pass mark:
    // run.sh reads the last stage that held them and records that rate.
    // abortOnFail stops the run once the application is clearly past its limit,
    // instead of spending the remaining stages hammering something already
    // failing.
    thresholds: {
        http_req_failed: [
            { threshold: 'rate<0.05', abortOnFail: __ENV.LOAD_MODE === 'ramp' },
        ],
        http_req_duration: [
            {
                threshold: 'p(95)<2000',
                abortOnFail: __ENV.LOAD_MODE === 'ramp',
            },
        ],
    },
};

const body = JSON.stringify({
    id: 'evt_load',
    type: 'load.test',
    data: { amount: 1000, currency: 'usd', description: 'x'.repeat(512) },
});

export default function () {
    const proxy = proxies[__ITER % proxies.length];

    const response = http.post(
        `${__ENV.LOAD_BASE_URL}/ingest/${proxy.token}`,
        body,
        {
            headers: {
                'Content-Type': 'application/json',
                // The ingest route asserts HTTPS (EnsureIngestIsSecure). Sail
                // serves plain HTTP, and bootstrap/app.php already trusts
                // X-Forwarded-Proto from any proxy, so this is the same signal a
                // TLS-terminating load balancer would send in production rather
                // than a weakening of the guard.
                'X-Forwarded-Proto': 'https',
                'X-Load-Proxy': String(proxy.id),
                'X-Load-Seq': String(__ITER),
            },
        },
    );

    check(response, {
        'ingest accepted': (r) => r.status >= 200 && r.status < 300,
    });
}
