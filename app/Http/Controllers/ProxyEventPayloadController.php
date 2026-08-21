<?php

namespace App\Http\Controllers;

use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The masked payload viewer's fetch-on-reveal endpoint (T28; AC22, AC25;
 * ADR-017 Decision 6) — the **only** content-bearing response in #6. Gated on
 * the existing proxy **view** permission, no distinct reveal permission
 * (AC14/AC22). Guards on `payload_cleaned_at` (ADR-014 Decision 7), never
 * `body IS NULL`, and reads only `webhook_events.body` — the raw capture —
 * never `dispatched_payloads.body` (ADR-017 Impact, ADR-013 Decision 3's
 * confinement of that interpretation to `StoredPayloadLookup`). The response
 * is never logged (only identifiers), never cached, never proxied into any
 * resource or prop.
 */
class ProxyEventPayloadController extends Controller
{
    /**
     * The leading `{current_team}` route parameter is accepted so implicit
     * binding of `{proxy}` aligns correctly under the team-prefixed, scoped-
     * binding group; `{event}` resolves via `Proxy::webhookEvents()` scoped
     * binding, so a cross-team/cross-proxy event id 404s before this method
     * runs at all.
     */
    public function __invoke(Request $request, string $current_team, Proxy $proxy, WebhookEvent $event): Response
    {
        $this->authorize('view', $proxy);

        if ($event->payload_cleaned_at !== null) {
            // Lifecycle, never an error (AC15) — the expiry pass already erased
            // the content on schedule. An explicit empty response, not abort():
            // abort() renders the app's HTML error page body, which is itself
            // content this endpoint must never carry.
            return response('', 410);
        }

        Log::info('payload.revealed', [
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'event_id' => $event->id,
            'ingest_id' => $event->ingest_id,
        ]);

        /** @var string $body */
        $body = $event->body;

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
