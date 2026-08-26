<?php

namespace Tests\Unit\Actions;

use App\Actions\RetryDelivery;
use App\Actions\SweepDueRetries;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SweepDueRetriesTest extends TestCase
{
    /**
     * A `retrying` delivery, its parent event/destination, and an existing
     * attempt-1 row — the state a real delayed `RetryDelivery` job would find,
     * minus the timing.
     */
    private function retryingDelivery(CarbonInterface $nextAttemptAt): Delivery
    {
        $destination = Destination::factory()->createQuietly();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $destination->proxy_id,
            'team_id' => $destination->team_id,
        ]);

        $delivery = Delivery::factory()->create([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => $nextAttemptAt,
        ]);

        DeliveryAttempt::factory()->failed()->createQuietly([
            'team_id' => $destination->team_id,
            'proxy_id' => $destination->proxy_id,
            'destination_id' => $destination->id,
            'delivery_id' => $delivery->id,
            'ingest_id' => $event->ingest_id,
            'attempt_number' => 1,
        ]);

        return $delivery;
    }

    public function test_redispatches_an_overdue_retrying_delivery(): void
    {
        Queue::fake();

        $grace = (int) config('retry.sweep_grace_seconds');
        $delivery = $this->retryingDelivery(now()->subSeconds($grace + 60));

        SweepDueRetries::run();

        RetryDelivery::assertPushed(1);
        RetryDelivery::assertPushed(fn ($action, array $params) => $params === [$delivery->id, 2]);
    }

    public function test_leaves_a_not_yet_due_delivery_untouched(): void
    {
        Queue::fake();

        $grace = (int) config('retry.sweep_grace_seconds');
        // Its next_attempt_at has passed, but not by more than the grace period.
        $this->retryingDelivery(now()->subSeconds(max(1, $grace - 30)));

        SweepDueRetries::run();

        RetryDelivery::assertNotPushed();
    }

    public function test_leaves_a_terminal_delivery_untouched(): void
    {
        Queue::fake();

        $grace = (int) config('retry.sweep_grace_seconds');
        $delivery = $this->retryingDelivery(now()->subSeconds($grace + 60));
        // Force a terminal status directly — proves the STATUS filter (not just
        // next_attempt_at) excludes it, even if a stale timestamp lingered.
        Delivery::query()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Failed]);

        SweepDueRetries::run();

        RetryDelivery::assertNotPushed();
    }

    public function test_a_double_fire_produces_exactly_one_new_attempt_row_not_two(): void
    {
        // No Queue::fake — under the sync driver each dispatched RetryDelivery
        // drains inline, exactly like the original delayed job racing the sweep.
        Http::fake(['*' => Http::response('ok', 200)]);

        $grace = (int) config('retry.sweep_grace_seconds');
        $delivery = $this->retryingDelivery(now()->subSeconds($grace + 60));

        // Simulates the sweeper and the original delayed job both firing: two
        // sweep passes back-to-back for the same overdue delivery.
        SweepDueRetries::run();
        SweepDueRetries::run();

        // Exactly one new row (attempt 2) — the pre-existing attempt-1 row plus
        // exactly one more, never a duplicate attempt 2.
        $this->assertSame(2, DeliveryAttempt::where('delivery_id', $delivery->id)->count());
        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }

    public function test_a_zero_sweep_grace_seconds_throws_instead_of_sweeping_every_retrying_delivery(): void
    {
        // Rider 1 (review-06 Minor 9): the accessor swap — SweepDueRetries now
        // reads RetryPolicy::sweepGraceSeconds(), which guards the key. A
        // blank/zero env used to make the cutoff `now()` and re-dispatch every
        // `retrying` delivery on every tick; it must now fail loudly instead.
        Queue::fake();
        Config::set('retry.sweep_grace_seconds', 0);
        $this->retryingDelivery(now()->subSeconds(60));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('retry.sweep_grace_seconds')");

        SweepDueRetries::run();

        RetryDelivery::assertNotPushed();
    }

    public function test_the_sweep_is_registered_to_run_every_minute(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => $e->description === 'Sweep due retries',
        );

        $this->assertNotNull($event, 'Expected the retry sweep to be scheduled.');
        $this->assertSame('* * * * *', $event->expression, 'The sweep must run everyMinute().');
    }
}
