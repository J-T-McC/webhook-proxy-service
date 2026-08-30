<?php

namespace Tests\Concerns;

use App\Actions\DeliverToDestination;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\ActionManager;
use Lorisleiva\Actions\Decorators\JobDecorator;

/**
 * Drains every currently-faked, queued `DeliverToDestination` job in place —
 * standing in for a real queue worker (ADR-020 Decision 1/7). Runs each job
 * through its by-reference entry point (`asJob()`), exactly what
 * `JobDecorator::handle()` calls in production. Idempotent against
 * re-invocation: an already-settled attempt is a resume no-op (ADR-011
 * Decision 4).
 *
 * Needed because `Queue::fake()` fakes every queued job indiscriminately —
 * there is no way to scope it to exclude `DeliverToDestination` alone, since
 * Laravel's job-class matching checks `$job instanceof $class` against the
 * pushed `JobDecorator` wrapper, which is never an instance of the wrapped
 * action. Draining explicitly, after the triggering call, is the workable
 * substitute (originally established by
 * `Tests\Feature\Proxies\ProcessingModeSwitchTest`).
 */
trait DrainsQueuedDeliveries
{
    private function drainQueuedDeliveries(): void
    {
        Queue::pushed(ActionManager::$jobDecorator, function (JobDecorator $job) {
            if ($job->decorates(DeliverToDestination::class)) {
                app(DeliverToDestination::class)->asJob(...$job->getParameters());
            }

            return true;
        });
    }
}
