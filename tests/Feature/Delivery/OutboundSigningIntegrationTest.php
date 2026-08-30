<?php

namespace Tests\Feature\Delivery;

use App\Actions\DeliverToDestination;
use App\Actions\ProcessIngestedWebhook;
use App\Actions\RetryDelivery;
use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\SecretPurpose;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SecretStore;
use App\Support\StandardWebhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Lorisleiva\Actions\ActionManager;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * T40 — the end-to-end pinning pass across T34-T39 (AC54-AC64), through the
 * real send/retry/replay path rather than unit calls. One test method per
 * named bullet. No production code — test-only, per this task's own
 * description.
 */
class OutboundSigningIntegrationTest extends TestCase
{
    use DrainsQueuedDeliveries;

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function ingestUrl(Proxy $proxy): string
    {
        return 'https://localhost/ingest/'.$proxy->ingest_token;
    }

    public function test_ac54_enabling_signing_signs_every_destination_including_one_added_afterward_and_a_proxy_without_signing_signs_none(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();

        // Signing off: no WebhookProxy-Signature anywhere.
        $this->postJson($this->ingestUrl($proxy), ['a' => 1])->assertStatus(202);

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('WebhookProxy-Signature'));

        app(SecretStore::class)->generate($proxy, SecretPurpose::Signing);

        // Added AFTER signing was already enabled — no per-row lookup, no
        // per-row state (AC54): it still gets signed with the shared key.
        Destination::factory()->for($proxy)->createQuietly();

        Http::fake(['*' => Http::response('ok', 200)]);
        $this->postJson($this->ingestUrl($proxy), ['b' => 2])->assertStatus(202);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded, 'One request per destination.');
        foreach ($recorded as [$request]) {
            $this->assertTrue($request->hasHeader('WebhookProxy-Signature'));
        }
    }

    public function test_ac55_ac59_the_signature_verifies_against_the_specification_over_exact_dispatched_bytes_and_the_body_is_unchanged(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $signingProxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($signingProxy)->createQuietly(['url' => 'https://signed.test/hook']);
        app(SecretStore::class)->replace($signingProxy, SecretPurpose::Signing, 'whsec_verify_me');

        $plainProxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($plainProxy)->createQuietly(['url' => 'https://plain.test/hook']);

        $payload = ['hello' => 'world', 'n' => 1];
        $body = json_encode($payload, 0);

        $this->postJson($this->ingestUrl($signingProxy), $payload)->assertStatus(202);
        $this->postJson($this->ingestUrl($plainProxy), $payload)->assertStatus(202);

        $signedRequest = Http::recorded(fn ($request): bool => str_contains($request->url(), 'signed.test'))->values()[0][0];
        $plainRequest = Http::recorded(fn ($request): bool => str_contains($request->url(), 'plain.test'))->values()[0][0];

        // Signing changes nothing but the headers (AC59) — the same bytes
        // reach the destination whether or not the proxy signs.
        $this->assertSame($body, $signedRequest->body());
        $this->assertSame($signedRequest->body(), $plainRequest->body());

        $this->assertTrue(StandardWebhooks::verify(
            $signedRequest->header('WebhookProxy-Id')[0],
            (int) $signedRequest->header('WebhookProxy-Timestamp')[0],
            $signedRequest->body(),
            $signedRequest->header('WebhookProxy-Signature')[0],
            ['whsec_verify_me'],
        ));
    }

    public function test_ac56_ac57_the_generated_secret_is_whsec_prefixed_base64_returned_once_with_no_store(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $response = $this->actingAs($user)
            ->postJson(route('proxies.signing.store', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
            ]))
            ->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');

        $secret = $response->json('secret');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertNotFalse(
            base64_decode(substr($secret, strlen('whsec_')), true),
            'The material after whsec_ must be valid base64.',
        );

        // Absent from any subsequent response — composes with T37's own
        // coverage, exercised again here per this task's own bullet.
        $show = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $show->getContent());
    }

    public function test_ac58_during_an_overlap_the_signature_carries_one_entry_per_live_secret_after_expiry_exactly_one(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        $store = app(SecretStore::class);
        $store->replace($proxy, SecretPurpose::Signing, 'whsec_superseded');
        $store->replace($proxy, SecretPurpose::Signing, 'whsec_current');

        $this->postJson($this->ingestUrl($proxy), ['overlap' => true])->assertStatus(202);

        $overlapRequest = Http::recorded()[0][0];
        $entries = explode(' ', $overlapRequest->header('WebhookProxy-Signature')[0]);
        $this->assertCount(2, $entries);

        foreach (['whsec_current', 'whsec_superseded'] as $secret) {
            $this->assertTrue(StandardWebhooks::verify(
                $overlapRequest->header('WebhookProxy-Id')[0],
                (int) $overlapRequest->header('WebhookProxy-Timestamp')[0],
                $overlapRequest->body(),
                $overlapRequest->header('WebhookProxy-Signature')[0],
                [$secret],
            ));
        }

        // No sweeper run (T15) — liveness is a property of the data alone.
        DB::table('proxy_secrets')
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing->value)
            ->whereNull('is_current')
            ->update(['expires_at' => now()->subMinute()]);

        Http::fake(['*' => Http::response('ok', 200)]);
        $this->postJson($this->ingestUrl($proxy), ['overlap' => false])->assertStatus(202);

        $afterExpiryRequest = Http::recorded()[0][0];
        $this->assertCount(1, explode(' ', $afterExpiryRequest->header('WebhookProxy-Signature')[0]));
    }

    public function test_ac60_webhook_id_is_identical_on_a_retry_different_on_a_replay_and_different_per_destination(): void
    {
        Http::fake([
            'https://a.test/*' => Http::sequence()
                ->push('nope', 500)
                ->whenEmpty(Http::response('ok', 200)),
            'https://b.test/*' => Http::response('ok', 200),
        ]);

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $destinationA = Destination::factory()->for($proxy)->createQuietly(['url' => 'https://a.test/hook']);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://b.test/hook']);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_secret');

        // No Queue::fake — sync driver drains the scheduled retry inline.
        $this->postJson($this->ingestUrl($proxy), ['x' => 1])->assertStatus(202);

        $aRequests = collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), 'a.test'))->values();
        $bRequests = collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), 'b.test'))->values();

        $this->assertCount(2, $aRequests, 'attempt 1 (failed) + the retry.');
        $attempt1Id = $aRequests[0][0]->header('WebhookProxy-Id')[0];
        $retryId = $aRequests[1][0]->header('WebhookProxy-Id')[0];
        $this->assertSame($attempt1Id, $retryId);

        $bId = $bRequests[0][0]->header('WebhookProxy-Id')[0];
        $this->assertNotSame(
            $attempt1Id,
            $bId,
            'Different destinations of the same dispatch get different WebhookProxy-Ids despite the shared signing key (AC60).',
        );

        $event = WebhookEvent::query()->where('proxy_id', $proxy->id)->firstOrFail();

        $this->actingAs($user)
            ->post(route('proxies.events.replay', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]), ['destinations' => [$destinationA->id]])
            ->assertRedirect();

        $aRequestsAfterReplay = collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), 'a.test'))->values();
        $this->assertCount(3, $aRequestsAfterReplay);
        $replayId = $aRequestsAfterReplay[2][0]->header('WebhookProxy-Id')[0];

        $this->assertNotSame($attempt1Id, $replayId, 'A replay mints a fresh dispatch_uuid, so its WebhookProxy-Id is new.');
    }

    public function test_adr021_decision5_disabling_deletes_every_row_and_re_enabling_signs_with_a_different_secret(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->createQuietly();
        $store = app(SecretStore::class);
        $originalSecret = $store->generate($proxy, SecretPurpose::Signing);

        $this->actingAs($user)
            ->delete(route('proxies.signing.destroy', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertRedirect();

        $this->assertSame([], $store->liveFor($proxy, SecretPurpose::Signing));

        $newSecret = $this->actingAs($user)
            ->postJson(route('proxies.signing.store', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->json('secret');

        $this->assertNotSame($originalSecret, $newSecret);

        Http::fake(['*' => Http::response('ok', 200)]);
        $this->postJson($this->ingestUrl($proxy), ['x' => 1])->assertStatus(202);

        $request = Http::recorded()[0][0];
        $this->assertTrue(StandardWebhooks::verify(
            $request->header('WebhookProxy-Id')[0],
            (int) $request->header('WebhookProxy-Timestamp')[0],
            $request->body(),
            $request->header('WebhookProxy-Signature')[0],
            [$newSecret],
        ));
        $this->assertFalse(StandardWebhooks::verify(
            $request->header('WebhookProxy-Id')[0],
            (int) $request->header('WebhookProxy-Timestamp')[0],
            $request->body(),
            $request->header('WebhookProxy-Signature')[0],
            [$originalSecret],
        ), 'The disabled secret must never verify a post-re-enable dispatch.');
    }

    public function test_ac61_the_signing_secret_appears_nowhere_but_the_one_time_display_and_the_signature_computation(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        Destination::factory()->for($proxy)->createQuietly();
        $secret = 'whsec_'.Str::random(40);
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, $secret);

        $loggedLinesContainingSecret = [];
        Log::listen(function ($event) use (&$loggedLinesContainingSecret, $secret): void {
            $line = $event->message.' '.json_encode($event->context);
            if (str_contains($line, $secret)) {
                $loggedLinesContainingSecret[] = $line;
            }
        });

        // --- Not in a queued job's arguments: positional, the same
        // technique AdvanceProxyFifoQueueTest uses for its own scalars.
        // `ProcessIngestedWebhook::run()` (not `::dispatch()`) so the
        // Delivery row is created synchronously while the queue is faked —
        // AsyncDispatchTest's own established pattern for
        // inspecting a pushed-but-undrained DeliverToDestination job.
        //
        // One `Http::fake()` closure for the WHOLE test, branched on
        // `$shouldFail` rather than a second `Http::fake([...])` call:
        // `Illuminate\Http\Client\Factory::fake()` MERGES each array-form
        // call's stub onto the existing `stubCallbacks` collection rather
        // than replacing it, and request resolution takes the FIRST
        // matching stub (`->filter()->first()`) — so a later
        // `Http::fake(['*' => 500])` never actually overrides an earlier
        // `Http::fake(['*' => 200])` registered against the same '*'
        // pattern in the same test. Discovered here (not assumed) by
        // dumping `Http::recorded()` and finding a 500-faked request still
        // reporting status 200. A plain closure with `use (&$shouldFail)`
        // (by reference), not an arrow `fn`, since `fn` captures its
        // enclosing variables BY VALUE at definition time — the second bug
        // this took two rounds of dumping to isolate, since an arrow
        // function here would silently freeze `$shouldFail` at `false`
        // forever regardless of the later reassignment below. ---
        $shouldFail = false;
        Queue::fake();
        Http::fake(function () use (&$shouldFail) {
            return $shouldFail ? Http::response('boom', 500) : Http::response('ok', 200);
        });

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        ProcessIngestedWebhook::run($event->ingest_id);

        $delivery = Delivery::query()->where('webhook_event_id', $event->id)->firstOrFail();
        DeliverToDestination::assertPushed(1, fn ($job, array $params): bool => $params === [$delivery->id, 1]);

        Queue::pushed(ActionManager::$jobDecorator, function (JobDecorator $job) use ($secret): bool {
            $this->assertStringNotContainsString($secret, serialize($job->getParameters()));

            return true;
        });

        // Drain the queued job for real, then check every other surface.
        $this->drainQueuedDeliveries();

        // --- Not in a delivery-attempt record. ---
        $attempt = DeliveryAttempt::where('delivery_id', $delivery->id)->firstOrFail();
        foreach ($attempt->getAttributes() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($secret, $value);
            }
        }

        // --- Not in a failure record. ---
        $shouldFail = true;
        Queue::fake();
        $failingEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
        ]);
        ProcessIngestedWebhook::run($failingEvent->ingest_id);
        $this->drainQueuedDeliveries();

        $failedAttempt = DeliveryAttempt::where('status', AttemptStatus::Failed)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($secret, (string) $failedAttempt->error_summary);

        // --- Not in any subsequent response (page prop, analytics, payload view). ---
        $show = $this->actingAs($user)
            ->get(route('proxies.show', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $show->getContent());

        $edit = $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $edit->getContent());

        $eventsIndex = $this->actingAs($user)
            ->get(route('proxies.events.index', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $eventsIndex->getContent());

        $eventShow = $this->actingAs($user)
            ->get(route('proxies.events.show', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $eventShow->getContent());

        $payloadView = $this->actingAs($user)
            ->get(route('proxies.events.payload', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => $event->id,
            ]))
            ->assertOk();
        $this->assertStringNotContainsString($secret, $payloadView->getContent());

        // --- Not in a log line, across everything triggered above. ---
        $this->assertSame([], $loggedLinesContainingSecret);
    }

    public function test_ac64_outbound_webhook_headers_are_the_proxys_own_even_when_the_inbound_request_carried_them(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly();
        Destination::factory()->for($proxy)->createQuietly();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_real_secret');

        $this->withHeaders([
            'WebhookProxy-Id' => 'forged-id',
            'WebhookProxy-Timestamp' => '1',
            'WebhookProxy-Signature' => 'v1,forged',
        ])->postJson($this->ingestUrl($proxy), ['x' => 1])->assertStatus(202);

        $request = Http::recorded()[0][0];

        $this->assertNotSame('forged-id', $request->header('WebhookProxy-Id')[0]);
        $this->assertNotSame('1', $request->header('WebhookProxy-Timestamp')[0]);
        $this->assertNotSame('v1,forged', $request->header('WebhookProxy-Signature')[0]);
        $this->assertTrue(StandardWebhooks::verify(
            $request->header('WebhookProxy-Id')[0],
            (int) $request->header('WebhookProxy-Timestamp')[0],
            $request->body(),
            $request->header('WebhookProxy-Signature')[0],
            ['whsec_real_secret'],
        ));
    }

    public function test_r3_a_retry_against_a_soft_deleted_proxy_still_resolves_and_still_signs(): void
    {
        $proxy = Proxy::factory()->create();
        $destination = Destination::factory()->for($proxy)->createQuietly();
        app(SecretStore::class)->replace($proxy, SecretPurpose::Signing, 'whsec_secret');

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => '{"a":1}',
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'status' => DeliveryStatus::Retrying,
        ]);
        $proxy->delete();

        Http::fake(['*' => Http::response('ok', 200)]);

        app(RetryDelivery::class)->handle($delivery->id, 2);

        Http::assertSentCount(1);
        $request = Http::recorded()[0][0];

        $this->assertTrue($request->hasHeader('WebhookProxy-Signature'), 'The proxy still signs after being soft-deleted.');
        $this->assertTrue(StandardWebhooks::verify(
            $request->header('WebhookProxy-Id')[0],
            (int) $request->header('WebhookProxy-Timestamp')[0],
            $request->body(),
            $request->header('WebhookProxy-Signature')[0],
            ['whsec_secret'],
        ));

        $this->assertSame(DeliveryStatus::Succeeded, $delivery->fresh()->status);
    }
}
