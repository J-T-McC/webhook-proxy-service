<?php

namespace App\Http\Controllers;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Http\Requests\ReplayEventRequest;
use App\Models\Delivery;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Manual replay of a retained event to one, several, or all of the proxy's
 * current destinations (AC9/AC10; ADR-017 Decision 1). A replay is a new
 * dispatch through the same processing/dispatch path as a live event: a fresh
 * `dispatch_uuid`, one `deliveries` row per chosen destination (`kind =
 * replay`), dispatched by reference exactly as ingest does — never a parallel
 * send path (AC11/AC12).
 */
class ProxyEventReplayController extends Controller
{
    /**
     * The leading `{current_team}` route parameter is accepted so implicit
     * binding of `{proxy}`/`{event}` aligns correctly under the team-prefixed,
     * scoped-binding group.
     */
    public function store(ReplayEventRequest $request, string $current_team, Proxy $proxy, WebhookEvent $event): RedirectResponse
    {
        $this->authorize('replay', $proxy);

        $validatedDestinations = $request->validated('destinations');
        $destinationIds = array_map('intval', is_array($validatedDestinations) ? $validatedDestinations : []);
        $dispatchUuid = (string) Str::uuid();
        $isFifo = $proxy->processing_mode === ProcessingMode::Fifo;

        DB::transaction(function () use ($proxy, $event, $destinationIds, $dispatchUuid, $isFifo): void {
            // Replay eligibility is guarded on the cleaned signal, race-free
            // (ADR-017 Decision 3): re-select the event under lockForUpdate() inside
            // this transaction — either this commits first (and GC's compare-and-set
            // erase then skips it, held by H5/H2), or GC committed first and the
            // event is already cleaned, rejected here as a lifecycle outcome, never
            // an error (AC15).
            $stillEligible = WebhookEvent::query()
                ->whereKey($event->id)
                ->whereNull('payload_cleaned_at')
                ->lockForUpdate()
                ->exists();

            if (! $stillEligible) {
                throw ValidationException::withMessages([
                    'event' => __('This event has expired and can no longer be replayed.'),
                ]);
            }

            foreach ($destinationIds as $destinationId) {
                Delivery::query()->create([
                    'team_id' => $proxy->team_id,
                    'proxy_id' => $proxy->id,
                    'destination_id' => $destinationId,
                    'webhook_event_id' => $event->id,
                    'dispatch_uuid' => $dispatchUuid,
                    'kind' => DispatchKind::Replay,
                    'status' => DeliveryStatus::Pending,
                ]);
            }

            if ($isFifo) {
                // Joins the line at the back (ADR-016): the advancer's pending scan
                // orders by this row's own `id`, so a fresh row is correct by
                // construction — no explicit ordering value needed.
                FifoDispatch::create([
                    'team_id' => $proxy->team_id,
                    'proxy_id' => $proxy->id,
                    'webhook_event_id' => $event->id,
                    'dispatch_uuid' => $dispatchUuid,
                    'status' => FifoDispatchStatus::Pending,
                ]);
            }
        });

        if ($isFifo) {
            AdvanceProxyFifoQueue::dispatch($proxy->id)->afterCommit();
        } else {
            ProcessIngestedWebhook::dispatch($event->ingest_id, $dispatchUuid)->afterCommit();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Replay started.')]);

        return to_route('proxies.show', ['proxy' => $proxy->id]);
    }
}
