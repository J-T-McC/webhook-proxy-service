<?php

namespace App\Actions;

use App\Enums\DestinationValidationSendFailure;
use App\Enums\DestinationValidationState;
use App\Exceptions\RefusedOutboundAddress;
use App\Models\Destination;
use App\Services\OutboundAddressGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Sends a destination its validation challenge (#18 AC14–AC22) and moves it to
 * `pending`. Not a delivery: no pipeline, no `deliveries` row, no credential
 * attached (AC17) — a URL edit triggers a send, so a challenge carrying the
 * credential would post it to any host a member names. See plan-18 § Services.
 */
class SendDestinationValidationChallenge
{
    use AsAction;

    public function __construct(private readonly OutboundAddressGuard $guard) {}

    /**
     * Send the challenge. Returns whether the destination now holds an open
     * challenge; false means the send was refused or failed, in which case the
     * destination is left exactly as it was.
     */
    public function handle(Destination $destination): bool
    {
        // AC6: the URL edit is the only route out of Validated. A send here
        // would force-fill it back to Pending — manual un-validation.
        if ($destination->validation_state === DestinationValidationState::Validated) {
            Log::info('destination.validation_send_refused_validated', ['destination_id' => $destination->id]);

            return false;
        }

        if ($this->availableIn($destination) !== null) {
            Log::info('destination.validation_rate_limited', ['destination_id' => $destination->id]);

            return false;
        }

        $this->recordAttempt($destination);

        try {
            $address = $this->guard->resolve($destination->url);
        } catch (RefusedOutboundAddress $e) {
            Log::info('destination.validation_refused', [
                'destination_id' => $destination->id,
                'reason' => $e->getMessage(),
            ]);

            $this->recordFailure($destination, DestinationValidationSendFailure::AddressRefused);

            return false;
        }

        $nonce = Str::random(40);
        $expiresAt = now()->addDays((int) config('destination_validation.challenge_ttl_days'));

        // Outlives the challenge so a late click reaches the controller rather
        // than the `signed` middleware. Grants no approval window — see
        // `config/destination_validation.php`, Link Grace Period.
        $linkExpiresAt = $expiresAt->copy()
            ->addDays((int) config('destination_validation.link_grace_days'));

        // Minted before the send and persisted after it succeeds: the link must
        // carry the nonce the destination will be checked against, and a failed
        // send must not leave a destination pending against a link nobody
        // received.
        $link = URL::temporarySignedRoute(
            'destinations.validate.show',
            $linkExpiresAt,
            ['destination' => $destination->id, 'nonce' => $nonce],
        );

        try {
            // AC17: the destination's configured method, not always POST.
            $response = Http::withoutRedirecting()
                ->timeout((int) config('destination_validation.timeout_seconds'))
                ->withOptions(['curl' => $this->pinnedTo($destination->url, $address)])
                ->send($destination->http_method->value, $destination->url, [
                    'json' => $this->challengeBody($link),
                ]);
        } catch (Throwable $e) {
            Log::info('destination.validation_send_failed', [
                'destination_id' => $destination->id,
                'reason' => $e->getMessage(),
            ]);

            $this->recordFailure($destination, DestinationValidationSendFailure::Unreachable);

            return false;
        }

        // A redirect is a failed challenge: the second hop was never checked
        // and cannot be pinned (AC19). Any other response is a successful
        // send (AC18) — the request reached the host.
        if ($response->redirect()) {
            Log::info('destination.validation_send_rejected', [
                'destination_id' => $destination->id,
                'status' => $response->status(),
            ]);

            $this->recordFailure($destination, DestinationValidationSendFailure::Redirected);

            return false;
        }

        $destination->forceFill([
            'validation_state' => DestinationValidationState::Pending,
            'validated_at' => null,
            'validation_challenge_sent_at' => now(),
            'validation_challenge_expires_at' => $expiresAt,
            'validation_nonce' => $nonce,
            // AC35: it answered, so the status is the outcome.
            'validation_last_send_status' => $response->status(),
            'validation_last_send_failure' => null,
        ])->save();

        return true;
    }

    /**
     * Seconds until this destination may be challenged again, or null if now
     * (AC21). The `RateLimiter` facade directly: this is not an HTTP boundary,
     * so there is nothing for a named limiter to be resolved by.
     */
    public function availableIn(Destination $destination): ?int
    {
        return $this->blockedBy($destination)['available_in'] ?? null;
    }

    /**
     * The tightest tripped limiter, described in the words the caption uses,
     * plus the seconds until it clears (AC21). Null if none blocks.
     *
     * @return array{description: string, available_in: int}|null
     */
    public function blockedBy(Destination $destination): ?array
    {
        $descriptions = [
            '5-minute limit for this destination',
            "Today's limit for this destination",
            "Today's limit for this team",
        ];

        foreach ($this->limits($destination) as $index => [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return [
                    'description' => $descriptions[$index],
                    'available_in' => RateLimiter::availableIn($key),
                ];
            }
        }

        return null;
    }

    /**
     * Record why this send never arrived (AC35). Exactly one of the outcome
     * pair is ever set, so writing the failure clears the status.
     */
    private function recordFailure(Destination $destination, DestinationValidationSendFailure $failure): void
    {
        $destination->forceFill([
            'validation_last_send_status' => null,
            'validation_last_send_failure' => $failure,
        ])->save();
    }

    private function recordAttempt(Destination $destination): void
    {
        foreach ($this->limits($destination) as [$key, $max, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * The three limits, as `[key, maxAttempts, decaySeconds]`.
     *
     * @return list<array{string, int, int}>
     */
    private function limits(Destination $destination): array
    {
        $config = config('destination_validation.rate_limits');

        return [
            ["destination-validation:d:{$destination->id}:5m", (int) $config['per_destination_per_5_minutes'], 300],
            ["destination-validation:d:{$destination->id}:1d", (int) $config['per_destination_per_day'], 86400],
            ["destination-validation:t:{$destination->team_id}:1d", (int) $config['per_team_per_day'], 86400],
        ];
    }

    /**
     * The fixed challenge body (AC18). It carries no event data, no payload
     * and no credential — only what the recipient needs to understand what
     * they have been sent and how to approve it.
     *
     * @return array<string, string>
     */
    private function challengeBody(string $link): array
    {
        return [
            'type' => 'destination_validation',
            'message' => 'A webhook proxy has been configured to send events to this URL. '
                .'Open the link below to approve it. Until somebody does, no events will be sent.',
            'validation_url' => $link,
        ];
    }

    /**
     * Pins the connection to the address the guard checked, so check and
     * connection are not two resolutions an attacker can answer differently.
     *
     * Guzzle silently ignores `curl` options on a non-cURL handler and would
     * send this unpinned, so `composer.json` requires `ext-curl`. Dropping
     * that requirement reopens the DNS-rebinding gap this closes.
     *
     * @return array<int, array<int, string>>
     */
    private function pinnedTo(string $url, string $address): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'http' ? 80 : 443);

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]];
    }
}
