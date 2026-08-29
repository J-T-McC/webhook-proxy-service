<?php

namespace Tests\Feature\Proxies;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\ProcessingMode;
use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Item #15 (pause and resume dispatch). Gated by the existing proxy `update`
 * permission (AC6), mirroring `ProxySigningControllerTest`.
 */
class ProxyPauseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function pauseRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.pause.store', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    private function resumeRoute(User $user, Proxy $proxy): string
    {
        return route('proxies.pause.destroy', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
        ]);
    }

    public function test_pausing_sets_paused_at(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id, 'created_by' => $user->id]);

        $this->assertNull($proxy->paused_at);

        $this->actingAs($user)->post($this->pauseRoute($user, $proxy))->assertRedirect();

        $this->assertNotNull($proxy->fresh()->paused_at);
    }

    public function test_resuming_clears_paused_at(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'created_by' => $user->id,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();

        $this->actingAs($user)->delete($this->resumeRoute($user, $proxy))->assertRedirect();

        $this->assertNull($proxy->fresh()->paused_at);
    }

    public function test_resuming_a_fifo_proxy_dispatches_the_advancer_immediately(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'created_by' => $user->id,
            'processing_mode' => ProcessingMode::Fifo,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $event->ingest_id,
        ]);

        $this->actingAs($user)->delete($this->resumeRoute($user, $proxy));

        AdvanceProxyFifoQueue::assertPushed(1);
        AdvanceProxyFifoQueue::assertPushed(fn ($job, array $params) => $params[0] === $proxy->id);
    }

    public function test_resuming_an_async_proxy_dispatches_every_undispatched_captured_event(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'created_by' => $user->id,
            'processing_mode' => ProcessingMode::Async,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();

        // Captured while paused (AC2 — ingest never pauses) and never dispatched.
        $waiting = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        // Already dispatched before the pause — must not be re-dispatched.
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $dispatched = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        Delivery::factory()->createQuietly([
            'webhook_event_id' => $dispatched->id,
            'destination_id' => $destination->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Succeeded,
        ]);
        // Expired while paused — must never dispatch on resume (AC11).
        $expired = WebhookEvent::factory()->cleaned()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        $this->actingAs($user)->delete($this->resumeRoute($user, $proxy));

        ProcessIngestedWebhook::assertPushed(1);
        ProcessIngestedWebhook::assertPushed(fn ($job, array $params) => $params[0] === $waiting->ingest_id);
    }

    public function test_resuming_dispatches_this_proxys_overdue_retries_immediately(): void
    {
        Queue::fake();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $user->current_team_id,
            'created_by' => $user->id,
        ]);
        $proxy->forceFill(['paused_at' => now()])->save();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $delivery = Delivery::factory()->createQuietly([
            'webhook_event_id' => $event->id,
            'destination_id' => $destination->id,
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subSecond(),
        ]);

        $this->actingAs($user)->delete($this->resumeRoute($user, $proxy));

        RetryDelivery::assertPushed(1);
        RetryDelivery::assertPushed(fn ($job, array $params) => $params[0] === $delivery->id);
    }

    public function test_a_member_without_update_rights_on_a_teammates_proxy_is_forbidden_on_both_endpoints(): void
    {
        $owner = $this->actingUser();
        $team = $owner->currentTeam;
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'created_by' => $owner->id]);

        $member = User::factory()->createQuietly();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        $this->actingAs($member)->post($this->pauseRoute($member, $proxy))->assertForbidden();
        $this->actingAs($member)->delete($this->resumeRoute($member, $proxy))->assertForbidden();

        $this->assertNull($proxy->fresh()->paused_at);
    }
}
