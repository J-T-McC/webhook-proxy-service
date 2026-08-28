<?php

namespace Tests\Feature\Delivery;

use App\Actions\DeliverToDestination;
use App\Enums\AttemptStatus;
use App\Enums\SecretPurpose;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use App\Services\SecretStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T39 (AC11; plan-10 § Architecture H, PRD-10 `## Amendment B` ruling 1) —
 * pins the partial-fan-out prohibition by name, as its own dedicated task: a
 * proxy whose signing secret cannot be decrypted dispatches to NONE of its
 * destinations for that attempt cycle, never some signed-successfully and
 * some silently unsigned. Kept independent of the broader T40 integration
 * suite because a partial-fan-out regression is exactly the kind of defect
 * a broad "does delivery succeed" test would quietly pass around — a
 * silently-unsigned fallback still returns 200 from a destination that
 * doesn't check its signature.
 */
class SigningAllOrNoneFailureTest extends TestCase
{
    private function corruptSigningSecret(Proxy $proxy): void
    {
        DB::table('proxy_secrets')
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing->value)
            ->update(['value' => 'not-valid-ciphertext']);
    }

    private function deliveryFor(Proxy $proxy, Destination $destination, WebhookEvent $event): Delivery
    {
        return Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
        ]);
    }

    public function test_a_corrupted_signing_secret_fails_every_destination_of_the_proxy_with_none_dispatched_signed_or_unsigned(): void
    {
        // A failed attempt schedules a real, delayed RetryDelivery (T14/T15); under
        // QUEUE_CONNECTION=sync a delayed dispatch still runs inline unless the queue
        // is faked, which would cascade this proxy's own corrupted secret through
        // every retry too. Faking the queue isolates this test to attempt 1 for each
        // destination — exactly the "that attempt cycle" AC11 names.
        Queue::fake();
        Http::fake();

        $proxy = Proxy::factory()->create();
        $destinations = Destination::factory()->count(3)->for($proxy)->create();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_secret');
        $this->corruptSigningSecret($proxy);

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        foreach ($destinations as $destination) {
            $delivery = $this->deliveryFor($proxy, $destination, $event);
            app(DeliverToDestination::class)->asJob($delivery->id, 1);
        }

        // Not one HTTP request was made, for any destination — signed or
        // unsigned. This is the forbidden partial-fan-out/fallback state,
        // asserted directly against the actual dispatched requests, not
        // merely against recorded status.
        Http::assertNothingSent();

        // Every destination of the proxy got its own recorded Failed
        // attempt — the exception reached DeliverToDestination::send(),
        // after the 'dispatched' row already existed, not lost as an
        // uncaught job failure.
        $this->assertSame(3, DeliveryAttempt::count());
        $attempts = DeliveryAttempt::all();
        $this->assertTrue($attempts->every(fn (DeliveryAttempt $attempt): bool => $attempt->status === AttemptStatus::Failed));

        // No destination succeeded while another failed for the same cause,
        // in the same cycle.
        $this->assertSame(0, DeliveryAttempt::where('status', AttemptStatus::Succeeded)->count());

        // The recorded error_summary names no part of the secret — the
        // exception's own fixed, value-free message (AC61).
        foreach ($attempts as $attempt) {
            $this->assertSame('The signing secret could not be decrypted.', $attempt->error_summary);
            $this->assertStringNotContainsString('whsec_secret', (string) $attempt->error_summary);
        }
    }

    public function test_a_proxy_with_signing_off_is_completely_unaffected(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->create();
        $destination = Destination::factory()->for($proxy)->create();
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        $delivery = $this->deliveryFor($proxy, $destination, $event);

        app(DeliverToDestination::class)->asJob($delivery->id, 1);

        $attempt = DeliveryAttempt::firstOrFail();
        $this->assertSame(AttemptStatus::Succeeded, $attempt->status);
        Http::assertSentCount(1);
    }

    public function test_a_proxy_with_a_healthy_signing_secret_is_completely_unaffected(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->create();
        $destinations = Destination::factory()->count(3)->for($proxy)->create();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_secret');

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);

        foreach ($destinations as $destination) {
            $delivery = $this->deliveryFor($proxy, $destination, $event);
            app(DeliverToDestination::class)->asJob($delivery->id, 1);
        }

        $this->assertSame(3, DeliveryAttempt::where('status', AttemptStatus::Succeeded)->count());
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->hasHeader('WebhookProxy-Signature'));
    }
}
