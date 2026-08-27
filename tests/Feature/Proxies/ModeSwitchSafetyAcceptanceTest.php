<?php

namespace Tests\Feature\Proxies;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\PurgeExpiredPayloads;
use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * T4 — end-to-end proof of plan-07 §Architecture E's "no code, and why":
 * switch safety and the downgrade lifecycle hold via existing, mode-
 * independent mechanisms, driven against the real mode-toggle surface #7
 * ships (PRD-07 AC1, AC2, AC3, AC5, AC9, AC10, AC11, AC13, AC17).
 */
class ModeSwitchSafetyAcceptanceTest extends TestCase
{
    use DrainsQueuedDeliveries;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function eventFor(Proxy $proxy, array $attributes = []): WebhookEvent
    {
        return WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            ...$attributes,
        ]);
    }

    // --- AC6(a)/AC9/AC13: composition follows the CURRENT mode, no orphaned output, no deletion ---

    public function test_switching_simple_to_enhanced_composes_capture_for_the_next_event_only(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['mode' => ProxyMode::Simple]);
        Destination::factory()->for($proxy)->createQuietly();

        $before = $this->eventFor($proxy);
        ProcessIngestedWebhook::run($before->ingest_id);
        $this->assertSame(0, DispatchedPayload::count());

        $proxy->update(['mode' => ProxyMode::Enhanced]);

        $after = $this->eventFor($proxy);
        ProcessIngestedWebhook::run($after->ingest_id);

        $this->assertSame(1, DispatchedPayload::count());
        $this->assertSame(0, DispatchedPayload::where('webhook_event_id', $before->id)->count());
        $this->assertSame(1, DispatchedPayload::where('webhook_event_id', $after->id)->count());
    }

    public function test_switching_enhanced_to_simple_stops_new_output_and_deletes_none(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['mode' => ProxyMode::Enhanced]);
        Destination::factory()->for($proxy)->createQuietly();

        $before = $this->eventFor($proxy);
        ProcessIngestedWebhook::run($before->ingest_id);
        $this->assertSame(1, DispatchedPayload::count());
        $existingRow = DispatchedPayload::where('webhook_event_id', $before->id)->firstOrFail();

        $proxy->update(['mode' => ProxyMode::Simple]);

        $after = $this->eventFor($proxy);
        ProcessIngestedWebhook::run($after->ingest_id);

        // No new row for the post-switch event, and the pre-switch row is untouched.
        $this->assertSame(1, DispatchedPayload::count());
        $this->assertSame(0, DispatchedPayload::where('webhook_event_id', $after->id)->count());
        $this->assertNotNull($existingRow->fresh());
        $this->assertSame($existingRow->id, DispatchedPayload::where('webhook_event_id', $before->id)->firstOrFail()->id);
    }

    /**
     * A queue redelivery straddling a switch, in either direction, never
     * duplicates a row (the `updateOrCreate` idempotency, keyed on the
     * UNIQUE `webhook_event_id`) and never errors — a re-run under a mode
     * that no longer composes the step is a structural no-op, not a failure.
     */
    public function test_a_redelivery_straddling_a_switch_never_duplicates_or_errors(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['mode' => ProxyMode::Simple]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        // Original dispatch under Simple: no row.
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->assertSame(0, DispatchedPayload::count());

        // Upgrade, then a "redelivery" of the SAME ingest id creates the row
        // for the first time (updateOrCreate: create branch).
        $proxy->update(['mode' => ProxyMode::Enhanced]);
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->assertSame(1, DispatchedPayload::count());

        // A further redelivery while still Enhanced hits the update branch of
        // the same unique key — still exactly one row, no error.
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->assertSame(1, DispatchedPayload::count());

        // Downgrade again; a further redelivery is a structural no-op for
        // capture (the step is not composed) — still exactly one row, no error.
        $proxy->update(['mode' => ProxyMode::Simple]);
        ProcessIngestedWebhook::run($event->ingest_id);
        $this->assertSame(1, DispatchedPayload::count());
    }

    // --- AC10/AC17: a downgrade mid-schedule loses, errors, duplicates, strands nothing ---

    /**
     * A downgrade with one FIFO line held `awaiting_retry` (a failed first
     * attempt) and a second event still pending: the held line's retry then
     * SUCCEEDS (rather than terminalizing, complementing T3's terminal-
     * failure case) — settling and advancing the line to the still-pending
     * second event, which is claimed and delivered in turn. Nothing is lost,
     * duplicated, or stranded.
     */
    public function test_a_downgrade_with_a_held_fifo_line_and_a_pending_sibling_advances_without_loss(): void
    {
        Queue::fake();
        Http::fakeSequence()->pushStatus(500)->whenEmpty(Http::response('ok', 200));

        $proxy = Proxy::factory()->createQuietly([
            'processing_mode' => ProcessingMode::Fifo,
            'mode' => ProxyMode::Enhanced,
            'retry_attempt_limit' => 5,
            'retry_backoff_strategy' => RetryBackoffStrategy::Fixed,
        ]);
        Destination::factory()->for($proxy)->createQuietly();

        $headEvent = $this->eventFor($proxy);
        $head = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $headEvent->id,
            'dispatch_uuid' => $headEvent->ingest_id,
        ]);
        $tailEvent = $this->eventFor($proxy);
        $tail = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $tailEvent->id,
            'dispatch_uuid' => $tailEvent->ingest_id,
        ]);

        // Head's first attempt is dispatched by reference and drained -> fails -> the line holds.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $head->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $tail->fresh()->status);
        $delivery = Delivery::query()->where('dispatch_uuid', $head->dispatch_uuid)->firstOrFail();

        // Downgrade mid-schedule.
        $proxy->update(['mode' => ProxyMode::Simple]);

        // The retry now succeeds.
        app(RetryDelivery::class)->handle($delivery->id, 2);
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Settled, $head->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);

        // The line advances: the previously-pending tail is claimed and
        // delivered in turn, nothing stranded.
        $this->assertSame(FifoDispatchStatus::Pending, $tail->fresh()->status);
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $tail->fresh()->status);
        $this->assertSame(
            DeliveryStatus::Succeeded,
            Delivery::query()->where('dispatch_uuid', $tail->fresh()->dispatch_uuid)->firstOrFail()->status,
        );
    }

    // --- AC13/AC20: retention expiry remains the only eraser, mode-independent ---

    public function test_an_expired_events_output_captured_under_enhanced_is_erased_normally_after_a_downgrade(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['mode' => ProxyMode::Enhanced]);
        Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy, ['created_at' => now()->subDays(31)]);

        ProcessIngestedWebhook::run($event->ingest_id);
        $output = DispatchedPayload::where('webhook_event_id', $event->id)->firstOrFail();

        // Downgrade AFTER capture — the output must still expire normally.
        $proxy->update(['mode' => ProxyMode::Simple]);

        PurgeExpiredPayloads::run();

        $rawEvent = DB::table('webhook_events')->where('id', $event->id)->first();
        $this->assertNotNull($rawEvent->payload_cleaned_at);
        $this->assertNull($rawEvent->body);

        $rawOutput = DB::table('dispatched_payloads')->where('id', $output->id)->first();
        $this->assertNull($rawOutput->body);
    }

    // --- AC13/AC11: replay on a now-Simple proxy touches no existing dispatched output ---

    public function test_a_replay_on_a_now_simple_proxy_neither_writes_nor_deletes_the_events_dispatched_output(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'mode' => ProxyMode::Enhanced]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = $this->eventFor($proxy);

        ProcessIngestedWebhook::run($event->ingest_id);
        $existing = DispatchedPayload::where('webhook_event_id', $event->id)->firstOrFail();
        $existingBody = $existing->body;

        $proxy->update(['mode' => ProxyMode::Simple]);

        $this->actingAs($user)->post(
            route('proxies.events.replay', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id, 'event' => $event->id]),
            ['destinations' => [$destination->id]],
        )->assertRedirect();

        $this->assertSame(1, DispatchedPayload::where('webhook_event_id', $event->id)->count());
        $fresh = $existing->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($existingBody, $fresh->body);
    }

    // --- AC1/AC2/AC3: switching mutates one column, no separate workflow, DB default is simple ---

    public function test_switching_mode_does_not_recreate_the_proxy_its_destinations_or_its_ingest_url(): void
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'mode' => ProxyMode::Simple]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $originalId = $proxy->id;
        $originalIngestUrl = $proxy->ingestUrl();
        $originalCreatedAt = $proxy->created_at;

        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'name' => $proxy->name,
                'mode' => 'enhanced',
                'processing_mode' => 'async',
                'destinations' => [
                    ['id' => $destination->id, 'url' => $destination->url, 'http_method' => $destination->http_method->value],
                ],
            ],
        )->assertRedirect();

        $fresh = $proxy->fresh();
        $this->assertSame($originalId, $fresh->id);
        $this->assertSame($originalIngestUrl, $fresh->ingestUrl());
        $this->assertSame($originalCreatedAt->timestamp, $fresh->created_at->timestamp);
        $this->assertSame($destination->id, $fresh->destinations()->firstOrFail()->id);
        $this->assertSame(ProxyMode::Enhanced, $fresh->mode);
    }

    /**
     * AC2: reachable at create AND edit through the same single `mode`
     * attribute — no separate mode-change route/workflow exists.
     */
    public function test_no_separate_mode_change_workflow_exists(): void
    {
        $this->assertFalse(Route::has('proxies.mode'));
        $this->assertFalse(Route::has('proxies.toggle-mode'));
        $this->assertFalse(Route::has('proxies.switch-mode'));
        $this->assertTrue(Route::has('proxies.store'));
        $this->assertTrue(Route::has('proxies.update'));
    }

    /**
     * AC3: `simple` is the database default for a proxy created without an
     * explicit choice — a real `save()` on a bare model, not the factory
     * (which always sets `mode` explicitly), so the DB column default is what
     * is actually under test (mirroring the house `new Model(...)->save()`
     * convention for auto-assign/DB-default behaviour).
     */
    public function test_simple_is_the_database_default_for_a_proxy_created_without_an_explicit_mode_choice(): void
    {
        $team = Team::factory()->createQuietly();

        $proxy = new Proxy;
        $proxy->team_id = $team->id;
        $proxy->name = 'No explicit mode';
        $proxy->ingest_token = 'plain-token-for-default-test';
        $proxy->ingest_token_hash = hash('sha256', 'plain-token-for-default-test', binary: true);
        $proxy->save();

        $this->assertSame(ProxyMode::Simple, $proxy->fresh()->mode);
    }

    // --- AC5: no new permission; the existing scope rules gate the mode change ---

    private function member(Team $team, TeamRole $role): User
    {
        $user = User::factory()->createQuietly();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_a_member_without_update_permission_cannot_change_a_proxys_mode_but_an_authorized_member_can(): void
    {
        $team = Team::factory()->createQuietly();
        $creator = $this->member($team, TeamRole::Member);
        $outsider = $this->member($team, TeamRole::Member);
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $creator->id, 'mode' => ProxyMode::Simple]);
        Destination::factory()->for($proxy)->createQuietly();

        $payload = [
            'name' => $proxy->name,
            'mode' => 'enhanced',
            'processing_mode' => 'async',
            'destinations' => $proxy->destinations()->get()->map(fn (Destination $d) => [
                'id' => $d->id, 'url' => $d->url, 'http_method' => $d->http_method->value,
            ])->all(),
        ];

        // A teammate without the ownership bypass and without having created
        // this proxy is denied — the existing `ProxyPolicy::update` scope
        // rules, unchanged, gate the mode field exactly as they gate any
        // other field.
        $this->actingAs($outsider)
            ->put(route('proxies.update', ['current_team' => $team->slug, 'proxy' => $proxy->id]), $payload)
            ->assertForbidden();
        $this->assertSame(ProxyMode::Simple, $proxy->fresh()->mode);

        // The creator (who holds UpdateProxy and owns the record) can.
        $this->actingAs($creator)
            ->put(route('proxies.update', ['current_team' => $team->slug, 'proxy' => $proxy->id]), $payload)
            ->assertRedirect();
        $this->assertSame(ProxyMode::Enhanced, $proxy->fresh()->mode);
    }

    /**
     * No new permission exists — the `TeamPermission` case list is pinned
     * unchanged. A future addition would need to update this list
     * deliberately, not by accident.
     */
    public function test_no_new_team_permission_was_added(): void
    {
        $this->assertSame(
            [
                'team:update', 'team:delete',
                'member:add', 'member:update', 'member:remove',
                'invitation:create', 'invitation:cancel',
                'proxy:view', 'proxy:create', 'proxy:update', 'proxy:delete',
                'proxy:update-any', 'proxy:delete-any',
                'proxy:replay',
            ],
            array_map(fn (TeamPermission $p) => $p->value, TeamPermission::cases()),
        );
    }
}
