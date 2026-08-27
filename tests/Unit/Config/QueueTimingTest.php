<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Enforces the timing rule ADR-020 Decision 4 states (config/horizon.php's
 * `defaults` and `config/queue.php`'s `redis.retry_after` comments carry the
 * reasoning; this makes the rule survive an editor who does not read them):
 *
 *     DeliverToDestination::TIMEOUT_SECONDS (15)
 *         <  every Horizon supervisor `timeout` (60)
 *         <  ingest.fifo_lease_seconds (90)
 *         <  queue.connections.redis.retry_after (180)
 *
 * Only L1 and L2 are asserted here — the two links that are correctness
 * constraints (ADR-020 Decision 4). L3 (a supervisor's `timeout` exceeding the
 * longest unit of legitimate work it may run) is fitness, not correctness, and
 * is a judgement about the workload rather than a fact a config test can check.
 *
 * Asserted against the REAL resolved configuration, across `horizon.defaults`
 * AND every `horizon.environments.*` override — an override is exactly where
 * a future edit would land and silently break the ordering.
 */
class QueueTimingTest extends TestCase
{
    /**
     * Every supervisor's resolved config, per environment (including the
     * `defaults` baseline, which every environment starts from) — an
     * environment's override merges over `horizon.defaults` per-supervisor,
     * exactly as Horizon itself resolves it.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function resolvedSupervisorsPerEnvironment(): array
    {
        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = config('horizon.defaults');
        /** @var array<string, array<string, array<string, mixed>>> $environments */
        $environments = config('horizon.environments');

        $resolved = ['defaults' => $defaults];

        foreach ($environments as $envName => $overrides) {
            $merged = $defaults;

            foreach ($overrides as $supervisor => $override) {
                $merged[$supervisor] = array_merge($defaults[$supervisor] ?? [], $override);
            }

            $resolved[$envName] = $merged;
        }

        return $resolved;
    }

    /**
     * L1 (correctness): `retry_after` must exceed every supervisor `timeout`
     * on the connection it serves — otherwise Redis makes a reserved job
     * visible again while a worker is still running it, and a second worker
     * picks it up.
     */
    public function test_redis_retry_after_exceeds_every_supervisors_timeout_on_the_redis_connection(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->resolvedSupervisorsPerEnvironment() as $envName => $supervisors) {
            foreach ($supervisors as $supervisorName => $supervisor) {
                if (($supervisor['connection'] ?? null) !== 'redis') {
                    continue;
                }

                $timeout = (int) $supervisor['timeout'];

                $this->assertGreaterThan(
                    $timeout,
                    $retryAfter,
                    "[{$envName}.{$supervisorName}] queue.connections.redis.retry_after ({$retryAfter}) must ".
                    "exceed this supervisor's timeout ({$timeout}) — L1.",
                );
            }
        }
    }

    /**
     * L2 (correctness, specific to this project): the supervisor serving the
     * queue that carries `AdvanceProxyFifoQueue` must keep its `timeout`
     * BELOW `ingest.fifo_lease_seconds` — otherwise a live advancer's claim
     * can be reaped while it is still running (ADR-020's whole subject).
     */
    public function test_default_supervisors_timeout_stays_below_the_fifo_claim_lease(): void
    {
        $lease = (int) config('ingest.fifo_lease_seconds');

        foreach ($this->resolvedSupervisorsPerEnvironment() as $envName => $supervisors) {
            $this->assertArrayHasKey(
                'supervisor-default',
                $supervisors,
                "[{$envName}] must resolve a supervisor-default entry.",
            );

            $timeout = (int) $supervisors['supervisor-default']['timeout'];

            $this->assertLessThan(
                $lease,
                $timeout,
                "[{$envName}.supervisor-default] timeout ({$timeout}) must stay below ".
                "ingest.fifo_lease_seconds ({$lease}) — L2.",
            );
        }
    }
}
