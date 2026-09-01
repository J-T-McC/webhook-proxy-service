import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import http from 'k6/http';

/**
 * FIFO scenarios (docs/briefs/load-testing-harness.md).
 *
 * One virtual user owns one FIFO proxy for the whole run and sends to it in a
 * loop. That assignment is the point: `X-Load-Seq` is only a usable ordering
 * oracle if the order the harness *sent* in matches the order it numbered, and
 * an arrival-rate executor sharing virtual users across proxies would not
 * guarantee that. A sequential per-proxy sender does, so any out-of-order
 * arrival the sink records is the application's, not the load generator's.
 *
 * Overlap still happens, which is what makes the run worth anything: ingest
 * returns in milliseconds while the sink holds each delivery for hundreds, so
 * a proxy's line is always several events deep.
 */

const proxies = new SharedArray('proxies', () => {
    const seeded = JSON.parse(
        open(__ENV.LOAD_SEED_FILE || '/seed/proxies.json'),
    );

    return seeded.proxies.filter((proxy) => proxy.mode === 'fifo');
});

export const options = {
    // p(99) is not in k6's default summary export, and the history row records it.
    summaryTrendStats: ['med', 'p(95)', 'p(99)', 'max'],
    scenarios: {
        fifo: {
            executor: 'constant-vus',
            // One virtual user per proxy, never more: two users on one proxy
            // would interleave their sequence numbers and the ordering check
            // would report the harness's own race as an application defect.
            vus: proxies.length,
            duration: __ENV.LOAD_DURATION || '30s',
        },
    },
    // The run's verdict comes from the sink's ordering report and the drain
    // check in run.sh, not from here. These only catch a broken run.
    thresholds: {
        http_req_failed: ['rate<0.01'],
    },
};

const body = JSON.stringify({
    id: 'evt_load',
    type: 'load.test',
    data: { amount: 1000, currency: 'usd', description: 'x'.repeat(512) },
});

export default function () {
    // __VU is 1-based, so this maps each user onto exactly one proxy.
    const proxy = proxies[(__VU - 1) % proxies.length];

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
                // Per-user counter. Sequential within this user, and this user owns
                // the proxy, so it is sequential within the proxy.
                'X-Load-Seq': String(__ITER),
            },
        },
    );

    check(response, {
        'ingest accepted': (r) => r.status >= 200 && r.status < 300,
    });

    // Paced deliberately. Ingest returns in milliseconds, so an unpaced user
    // would enqueue tens of thousands of events against a FIFO line that drains
    // at the sink's pace, and the run would spend an hour draining a backlog
    // that proves nothing extra. This rate still exceeds what the workers can
    // clear, so every proxy's line stays several events deep — which is the
    // overlap the scenario exists to create — but the backlog stays bounded.
    sleep(Number(__ENV.LOAD_THINK || 0.8));
}
