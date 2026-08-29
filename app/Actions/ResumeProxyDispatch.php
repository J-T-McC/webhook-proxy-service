<?php

namespace App\Actions;

use App\Enums\ProcessingMode;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Item #15 (pause and resume dispatch) AC4: on resume, the proxy's waiting
 * work dispatches by itself — no member action, no waiting for the next
 * scheduler tick.
 *
 * FIFO has a persisted backlog (`fifo_dispatches`) and a single advancer, so
 * resuming it is exactly nudging that advancer once (its own claim guard
 * already lifted the moment `paused_at` cleared). Async has no such backlog —
 * a paused Async proxy's original dispatch was skipped entirely by
 * {@see ProcessIngestedWebhook}'s own pause guard, leaving no `deliveries`
 * row at all — so "waiting work" for Async is exactly the set of this
 * proxy's captured, uncleaned events with zero `deliveries` rows. Retries are
 * mode-agnostic and are always nudged directly via
 * {@see SweepDueRetries::forProxy()}, rather than waiting for that sweep's
 * own next tick.
 */
class ResumeProxyDispatch
{
    use AsAction;

    public function __construct(private SweepDueRetries $retries) {}

    public function handle(Proxy $proxy): void
    {
        if ($proxy->processing_mode === ProcessingMode::Fifo) {
            AdvanceProxyFifoQueue::dispatch($proxy->id);
        } else {
            $this->dispatchUndispatchedEvents($proxy);
        }

        $this->retries->forProxy($proxy->id);
    }

    /**
     * Every Async event this proxy captured while paused (or that raced the
     * pause boundary either way) and never dispatched — cleaned events are
     * excluded (AC11: an expired event is never dispatched on resume).
     */
    private function dispatchUndispatchedEvents(Proxy $proxy): void
    {
        WebhookEvent::query()
            ->where('proxy_id', $proxy->id)
            ->whereNull('payload_cleaned_at')
            ->whereDoesntHave('deliveries')
            ->orderBy('id')
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    ProcessIngestedWebhook::dispatch($event->ingest_id);
                }
            });
    }
}
