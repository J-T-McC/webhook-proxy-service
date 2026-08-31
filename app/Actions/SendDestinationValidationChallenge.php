<?php

namespace App\Actions;

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
 * `pending`.
 *
 * **This is not a delivery and must never become one.** It does not route
 * through `DeliverToDestination` or the pipeline, writes no `deliveries` or
 * `delivery_attempts` row, and is absent from item #11's measures (AC42). Most
 * importantly it does **not** attach the destination's stored credential
 * (AC17): a URL edit triggers an automatic send, so a challenge that carried
 * the credential would let a member move a destination's URL to a host they
 * control and have the product post the credential to it.
 *
 * **The request is guarded, and the guard is the point.** A challenge goes to a
 * URL nobody has vouched for yet — the exact vector this feature closes,
 * performed by the mitigation. {@see OutboundAddressGuard} resolves the host,
 * refuses reserved ranges, and returns the address that was checked; the
 * connection is then pinned to that address so a second resolution cannot land
 * somewhere else. Redirects are refused rather than followed (AC19): pinning
 * cannot extend to a second hop, and a challenge has no reason to be
 * redirected.
 *
 * **The link is a temporary signed URL** (`URL::temporarySignedRoute`), so
 * there is no token column, no generated secret and no hand-rolled expiry
 * check. A signed URL is not single-use on its own — the stored
 * `validation_nonce`, replaced on every send, is what makes it so and what
 * makes a newer challenge void an older one.
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

            return false;
        }

        $nonce = Str::random(40);
        $expiresAt = now()->addDays((int) config('destination_validation.challenge_ttl_days'));

        // Minted before the send and persisted after it succeeds: the link must
        // carry the nonce the destination will be checked against, and a failed
        // send must not leave a destination pending against a link nobody
        // received.
        $link = URL::temporarySignedRoute(
            'destinations.validate.show',
            $expiresAt,
            ['destination' => $destination->id, 'nonce' => $nonce],
        );

        try {
            $response = Http::withoutRedirecting()
                ->timeout((int) config('destination_validation.timeout_seconds'))
                ->withOptions(['curl' => $this->pinnedTo($destination->url, $address)])
                ->post($destination->url, $this->challengeBody($link));
        } catch (Throwable $e) {
            Log::info('destination.validation_send_failed', [
                'destination_id' => $destination->id,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        // A redirect is a failed challenge, not something to chase: the second
        // hop's address was never checked and cannot be pinned.
        if ($response->redirect() || ! $response->successful()) {
            Log::info('destination.validation_send_rejected', [
                'destination_id' => $destination->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        $destination->forceFill([
            'validation_state' => DestinationValidationState::Pending,
            'validated_at' => null,
            'validation_challenge_sent_at' => now(),
            'validation_challenge_expires_at' => $expiresAt,
            'validation_nonce' => $nonce,
        ])->save();

        return true;
    }

    /**
     * Seconds until this destination may be sent another challenge, or null if
     * it may be sent one now (AC21).
     *
     * The Validate button sends to an arbitrary URL, which is the vector this
     * whole feature exists to close — so the button is rate limited per
     * destination and per team. A caller that is blocked reports when it may
     * try again rather than presenting a dead control.
     *
     * Uses the `RateLimiter` facade directly rather than a named limiter
     * registered in a provider: named limiters exist to be resolved by the
     * `throttle` middleware, and this is not an HTTP boundary.
     */
    public function availableIn(Destination $destination): ?int
    {
        return $this->blockedBy($destination)['available_in'] ?? null;
    }

    /**
     * Which limit currently blocks this destination, or null if none does —
     * the tightest tripped limiter in check order, as a plain-language
     * description (design-18 Screen 2's three fixed strings, AC21 "the member
     * is told which one") plus the seconds until it clears.
     *
     * @return array{description: string, available_in: int}|null
     */
    public function blockedBy(Destination $destination): ?array
    {
        $descriptions = [
            'the once-per-5-minutes limit for this destination',
            "today's send limit for this destination",
            "today's send limit for this team",
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
     * cURL's `CURLOPT_RESOLVE`, which pins the connection to the address the
     * guard checked instead of resolving the host again. Without this the
     * check and the connection are two separate resolutions and an attacker
     * controlling the DNS record answers each differently.
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
