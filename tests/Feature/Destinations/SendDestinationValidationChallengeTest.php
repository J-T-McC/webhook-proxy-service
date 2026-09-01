<?php

namespace Tests\Feature\Destinations;

use App\Actions\SendDestinationValidationChallenge;
use App\Enums\DestinationValidationSendFailure;
use App\Enums\DestinationValidationState;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Services\OutboundAddressGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guarded challenge send (#18 AC14–AC22).
 */
class SendDestinationValidationChallengeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every destination URL in these tests is a fake host, so the guard is
        // driven with a fixed public answer except where the test is about the
        // guard's own refusal.
        $this->app->bind(
            OutboundAddressGuard::class,
            fn () => new OutboundAddressGuard(fn (string $host) => ['93.184.216.34']),
        );
    }

    public function test_a_successful_send_moves_the_destination_to_pending_with_a_nonce_and_expiry(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertTrue(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(DestinationValidationState::Pending, $destination->validation_state);
        $this->assertNotNull($destination->validation_nonce);
        $this->assertNotNull($destination->validation_challenge_sent_at);
        $this->assertTrue($destination->validation_challenge_expires_at->isFuture());
    }

    public function test_the_challenge_never_carries_the_destinations_stored_credential(): void
    {
        // AC17, and the reason it exists: a URL edit triggers an automatic
        // send, so a challenge carrying the credential would let a member move
        // a destination to a host they control and have the product post the
        // credential to it.
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly([
            'credential_header_name' => 'X-Secret-Token',
            'credential_secret' => 'super-secret-value',
        ]);

        SendDestinationValidationChallenge::run($destination);

        Http::assertSent(function ($request) {
            $this->assertFalse($request->hasHeader('X-Secret-Token'));
            $this->assertStringNotContainsString('super-secret-value', $request->body());

            return true;
        });
    }

    public function test_the_challenge_body_is_fixed_and_carries_no_event_data(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        SendDestinationValidationChallenge::run($destination);

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('destination_validation', $body['type']);
            $this->assertArrayHasKey('validation_url', $body);
            $this->assertSame(['type', 'message', 'validation_url'], array_keys($body));

            return true;
        });
    }

    public function test_a_redirect_response_fails_the_send_rather_than_being_followed(): void
    {
        // AC19: pinning cannot extend to a second hop, so a redirect is a
        // failed challenge rather than something to chase.
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/hook'])]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $this->assertSame(
            DestinationValidationState::Unvalidated,
            $destination->refresh()->validation_state,
        );
    }

    public function test_a_refused_address_leaves_the_destination_untouched(): void
    {
        Http::fake();

        $this->app->bind(
            OutboundAddressGuard::class,
            fn () => new OutboundAddressGuard(fn (string $host) => ['10.0.0.1']),
        );

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        Http::assertNothingSent();
        $this->assertSame(
            DestinationValidationState::Unvalidated,
            $destination->refresh()->validation_state,
        );
    }

    public function test_a_non_2xx_response_is_a_successful_send(): void
    {
        // AC18 (review-18 finding 1): any HTTP response means the request
        // reached the host — a human there can still find the link in the
        // body. A signature-verifying receiver that 4xxes the unfamiliar
        // payload is the common case this feature exists for; only
        // connection-level failures and refusals fail the send.
        Http::fake(['*' => Http::response('nope', 500)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertTrue(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(DestinationValidationState::Pending, $destination->validation_state);
        $this->assertNotNull($destination->validation_nonce);
    }

    public function test_a_connection_failure_does_not_leave_the_destination_pending_against_a_link_nobody_received(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(DestinationValidationState::Unvalidated, $destination->validation_state);
        $this->assertNull($destination->validation_nonce);
    }

    public function test_a_validated_destination_is_refused_a_send(): void
    {
        // AC6 (review-18 finding 4): exactly one route out of Validated
        // exists — the URL edit. A send here would force-fill the destination
        // back to Pending, a manual un-validation the state machine forbids.
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->validated()->createQuietly();

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        Http::assertNothingSent();
        $this->assertSame(
            DestinationValidationState::Validated,
            $destination->refresh()->validation_state,
        );
    }

    public function test_the_challenge_uses_the_destinations_configured_http_method(): void
    {
        // AC17 (review-18 finding 5): sent using the destination's configured
        // method — a PUT-only endpoint must be able to receive its challenge.
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly([
            'http_method' => 'PUT',
        ]);

        SendDestinationValidationChallenge::run($destination);

        Http::assertSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_a_fresh_send_replaces_the_previous_nonce_and_voids_the_old_link(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->pendingValidation()->createQuietly();
        $original = $destination->validation_nonce;

        SendDestinationValidationChallenge::run($destination);

        $this->assertNotSame(
            $original,
            $destination->refresh()->validation_nonce,
            'A signed URL is replayable on its own; the nonce is what makes a newer '
            .'challenge void an older link.',
        );
    }

    public function test_a_validation_send_creates_no_delivery_or_attempt_rows(): void
    {
        // AC42: a validation send is not a delivery and must be absent from
        // item #11's measures.
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        SendDestinationValidationChallenge::run($destination);

        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame(0, DeliveryAttempt::query()->count());
    }

    public function test_a_second_send_inside_five_minutes_is_refused_with_a_retry_after(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $action = app(SendDestinationValidationChallenge::class);

        $this->assertNull(
            $action->availableIn($destination),
            'A destination that has never been challenged may be challenged now.',
        );

        $this->assertTrue($action->handle($destination));
        $this->assertFalse($action->handle($destination));
        $this->assertNotNull(
            $action->availableIn($destination),
            'A blocked caller must be told when it may try again, not given a dead button.',
        );
    }

    public function test_the_per_destination_daily_limit_holds(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        config(['destination_validation.rate_limits.per_destination_per_5_minutes' => 100]);
        config(['destination_validation.rate_limits.per_destination_per_day' => 2]);

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $action = app(SendDestinationValidationChallenge::class);

        $this->assertTrue($action->handle($destination));
        $this->assertTrue($action->handle($destination));
        $this->assertFalse($action->handle($destination));
    }

    public function test_the_per_team_daily_limit_holds_across_destinations(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        config(['destination_validation.rate_limits.per_destination_per_5_minutes' => 100]);
        config(['destination_validation.rate_limits.per_team_per_day' => 1]);

        $first = Destination::factory()->unvalidated()->createQuietly();
        $second = Destination::factory()->unvalidated()->createQuietly([
            'team_id' => $first->team_id,
            'proxy_id' => $first->proxy_id,
        ]);

        $action = app(SendDestinationValidationChallenge::class);

        $this->assertTrue($action->handle($first));
        $this->assertFalse(
            $action->handle($second),
            'The team limit bounds the whole team, not one destination at a time.',
        );
    }

    public function test_a_rate_limited_send_does_not_reach_the_network(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Two destinations on one team, with the team limit already spent by
        // the first, so the second is blocked before the guard or the client
        // is reached.
        config(['destination_validation.rate_limits.per_team_per_day' => 1]);

        $first = Destination::factory()->unvalidated()->createQuietly();
        $second = Destination::factory()->unvalidated()->createQuietly([
            'team_id' => $first->team_id,
            'proxy_id' => $first->proxy_id,
        ]);

        $action = app(SendDestinationValidationChallenge::class);
        $action->handle($first);

        Http::fake();

        $this->assertFalse($action->handle($second));

        Http::assertNothingSent();
        $this->assertSame(
            DestinationValidationState::Unvalidated,
            $second->refresh()->validation_state,
        );
    }

    public function test_a_send_that_reaches_the_destination_records_the_status_it_returned(): void
    {
        // AC35: "arrived and was rejected" has to read differently from "never
        // arrived", so the status is stored whether or not it was a 2xx.
        Http::fake(['*' => Http::response('no thanks', 404)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $this->givePreviousOutcome($destination, DestinationValidationSendFailure::Unreachable);

        $this->assertTrue(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(404, $destination->validation_last_send_status);
        $this->assertNull(
            $destination->validation_last_send_failure,
            'A send that arrived must clear the earlier failure, or the row describes two attempts at once.',
        );
    }

    public function test_a_refused_address_records_why_the_send_never_left(): void
    {
        Http::fake();

        $this->app->bind(
            OutboundAddressGuard::class,
            fn () => new OutboundAddressGuard(fn (string $host) => ['10.0.0.1']),
        );

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $this->givePreviousOutcome($destination, status: 200);

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(
            DestinationValidationSendFailure::AddressRefused,
            $destination->validation_last_send_failure,
        );
        $this->assertNull($destination->validation_last_send_status);
    }

    public function test_a_connection_failure_records_that_the_address_could_not_be_reached(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $this->givePreviousOutcome($destination, status: 200);

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(
            DestinationValidationSendFailure::Unreachable,
            $destination->validation_last_send_failure,
        );
        $this->assertNull($destination->validation_last_send_status);
    }

    public function test_a_redirect_records_that_the_address_redirected_rather_than_the_status(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/hook'])]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(
            DestinationValidationSendFailure::Redirected,
            $destination->validation_last_send_failure,
        );
        // The 302 is not stored as a returned status: a redirect is a failed
        // send under AC19, and the member's remedy is the address, not the code.
        $this->assertNull($destination->validation_last_send_status);
    }

    public function test_the_stored_failure_is_a_key_and_never_the_underlying_error_text(): void
    {
        // design-18 fixes the member-facing wording for each reason and forbids
        // implementation jargon in it, so the exception message must not become
        // a stored, renderable string.
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: nope.test'));

        $destination = Destination::factory()->unvalidated()->createQuietly();

        SendDestinationValidationChallenge::run($destination);

        $this->assertStringNotContainsString(
            'cURL',
            (string) $destination->refresh()->getRawOriginal('validation_last_send_failure'),
        );
    }

    public function test_a_rate_limited_send_leaves_the_previous_outcome_standing(): void
    {
        // Nothing was sent, so the previous outcome is still the most recent
        // one. Overwriting it would tell the member the opposite of what
        // happened to their last real attempt.
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->unvalidated()->createQuietly();
        $this->givePreviousOutcome($destination, status: 418);

        $action = app(SendDestinationValidationChallenge::class);
        $this->assertTrue($action->handle($destination));
        $this->assertFalse($action->handle($destination));

        $this->assertSame(200, $destination->refresh()->validation_last_send_status);
    }

    public function test_a_send_refused_at_a_validated_destination_leaves_the_previous_outcome_standing(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $destination = Destination::factory()->validated()->createQuietly();
        $this->givePreviousOutcome($destination, status: 201);

        $this->assertFalse(SendDestinationValidationChallenge::run($destination));

        $destination->refresh();

        $this->assertSame(201, $destination->validation_last_send_status);
        $this->assertNull($destination->validation_last_send_failure);
    }

    public function test_the_sent_link_outlives_the_challenge_so_a_late_click_is_explained_rather_than_403ed(): void
    {
        // The `signed` middleware runs before the controller. Minted against
        // the challenge expiry, the two lapse at the same instant, the
        // middleware refuses the request, and the controller's `expired`
        // branch — design-18 Screen 4's Expired outcome — can never run. The
        // approver gets a bare 403 with no way to know a new link can be
        // requested.
        Http::fake(['*' => Http::response('ok', 200)]);
        config([
            'destination_validation.challenge_ttl_days' => 7,
            'destination_validation.link_grace_days' => 14,
        ]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertTrue(SendDestinationValidationChallenge::run($destination));

        $link = null;
        Http::assertSent(function ($request) use (&$link) {
            $link = json_decode($request->body(), true)['validation_url'];

            return true;
        });

        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);

        $this->assertSame(
            $destination->refresh()->validation_challenge_expires_at->addDays(14)->timestamp,
            (int) $query['expires'],
            'The signature must outlive the challenge by exactly the grace period.',
        );
    }

    public function test_the_grace_period_moves_the_signature_only_and_never_the_approval_deadline(): void
    {
        // The whole safety of the grace period rests on this: the approval gate
        // is the stored expiry, and the grace period does not touch it.
        Http::fake(['*' => Http::response('ok', 200)]);
        config([
            'destination_validation.challenge_ttl_days' => 7,
            'destination_validation.link_grace_days' => 14,
        ]);

        $destination = Destination::factory()->unvalidated()->createQuietly();

        SendDestinationValidationChallenge::run($destination);

        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $destination->refresh()->validation_challenge_expires_at->timestamp,
            5,
            'The stored deadline is set from the challenge TTL alone.',
        );
    }

    /**
     * Seed an outcome from an earlier send, so a test can show that the next
     * one replaces it rather than merging with it. `forceFill` because the
     * validation columns are deliberately not fillable.
     */
    private function givePreviousOutcome(
        Destination $destination,
        ?DestinationValidationSendFailure $failure = null,
        ?int $status = null,
    ): void {
        $destination->forceFill([
            'validation_last_send_status' => $status,
            'validation_last_send_failure' => $failure,
        ])->save();
    }
}
