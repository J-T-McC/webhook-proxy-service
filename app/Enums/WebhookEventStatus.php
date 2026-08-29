<?php

namespace App\Enums;

/**
 * The stored dispatch-progress signal for a `WebhookEvent` (event queue view).
 * Written in exactly one place — `ProcessIngestedWebhook::handle()`, only for
 * the original dispatch (`$dispatchUuid === $ingestId`), never for a replay
 * and never by `PurgeExpiredPayloads`.
 *
 * Deliberately two cases, not three: an event whose payload has since been
 * cleaned (`payload_cleaned_at`) is never re-labelled here, because
 * `payload_cleaned_at` is already the single resolver of that signal
 * (ADR-014 Decision 7). A read surface that wants to show "expired" derives
 * it from `payload_cleaned_at` at read time instead of trusting this column
 * alone — see `WebhookEventQueueResource`.
 */
enum WebhookEventStatus: string
{
    /** Captured, not yet dispatched — the event queue's backlog. */
    case Pending = 'pending';

    /** The original dispatch has run (its `deliveries` rows exist). */
    case Dispatched = 'dispatched';
}
