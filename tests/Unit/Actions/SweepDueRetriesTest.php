<?php

namespace Tests\Unit\Actions;

use App\Actions\RetryDelivery;
use App\Actions\SweepDueRetries;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
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
    private function retryingDelivery(CarbonInterface $nextAttemptAt, ?Proxy $proxy = null): Delivery
    {
        $destination = $proxy !== null
            ? Destination::factory()->for($proxy)->createQuietly()
            : Destination::factory()->createQuietly();
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

    public function test_excludes_an_overdue_retrying_delivery_whose_proxy_is_paused(): void
    {
        // Item #15, Q-15-01(3): a retry is a dispatch, so it must not fire while
        // the proxy is paused — and must not spend the attempt it did not make.
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['paused_at' => now()]);
        $grace = (int) config('retry.sweep_grace_seconds');
        $delivery = $this->retryingDelivery(now()->subSeconds($grace + 60), $proxy);

        SweepDueRetries::run();

        RetryDelivery::assertNotPushed();
        $this->assertSame(DeliveryStatus::Retrying, $delivery->fresh()->status, 'The retry budget must be untouched while paused.');
    }

    public function test_for_proxy_dispatches_this_proxys_overdue_retries_immediately_regardless_of_grace(): void
    {
        // AC4 parity: on resume, waiting retries fire immediately rather than
        // waiting for the next per-minute sweep tick.
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly();
        // Barely overdue — well inside the sweep grace period, so handle()
        // would leave it untouched, but forProxy() (the resume path) must not
        // wait out that grace.
        $delivery = $this->retryingDelivery(now()->subSeconds(1), $proxy);

        app(SweepDueRetries::class)->forProxy($proxy->id);

        RetryDelivery::assertPushed(1);
        RetryDelivery::assertPushed(fn ($action, array $params) => $params === [$delivery->id, 2]);
    }

    public function test_for_proxy_never_touches_another_proxys_overdue_retry(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly();
        $other = Proxy::factory()->createQuietly();
        $this->retryingDelivery(now()->subSeconds(60), $other);

        app(SweepDueRetries::class)->forProxy($proxy->id);

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

    public function test_an_overdue_retry_for_an_unvalidated_destination_is_not_dispatched(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->unvalidated()->createQuietly();

        Delivery::factory()->for($proxy)->for($destination)->createQuietly([
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subHour(),
        ]);

        SweepDueRetries::run();

        Queue::assertNothingPushed();
    }

    public function test_resuming_a_proxy_does_not_dispatch_retries_to_an_unvalidated_destination(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly();
        $destination = Destination::factory()->for($proxy)->unvalidated()->createQuietly();

        Delivery::factory()->for($proxy)->for($destination)->createQuietly([
            'status' => DeliveryStatus::Retrying,
            'next_attempt_at' => now()->subHour(),
        ]);

        // forProxy() shares overdueQuery(), so the gate must hold on the resume
        // path too — otherwise a resume re-opens the hole the sweep closes.
        SweepDueRetries::make()->forProxy($proxy->id);

        Queue::assertNothingPushed();
    }
}
