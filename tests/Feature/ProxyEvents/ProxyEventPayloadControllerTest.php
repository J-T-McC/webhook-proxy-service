<?php

namespace Tests\Feature\ProxyEvents;

use App\Models\Proxy;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T28 — `ProxyEventPayloadController` (AC22, AC25; ADR-017 Decision 6): the
 * only content-bearing response in #6. Retained/cleaned/unknown/cross-team
 * cases, the response-header assertions, and the identifiers-only logging
 * assertion.
 */
class ProxyEventPayloadControllerTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    private function route(User $user, Proxy $proxy, WebhookEvent $event): string
    {
        return route('proxies.events.payload', [
            'current_team' => $user->currentTeam->slug,
            'proxy' => $proxy->id,
            'event' => $event->id,
        ]);
    }

    public function test_a_retained_event_returns_the_raw_bytes_with_the_documented_headers(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'body' => '{"hello":"world"}',
        ]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $event))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertContent('{"hello":"world"}');
    }

    public function test_a_cleaned_event_returns_410_with_no_body_content(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $response = $this->actingAs($user)
            ->get($this->route($user, $proxy, $event))
            ->assertStatus(410);

        $this->assertStringNotContainsString('hello', $response->getContent() ?: '');
    }

    public function test_an_unknown_event_id_returns_404(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.events.payload', [
                'current_team' => $user->currentTeam->slug,
                'proxy' => $proxy->id,
                'event' => 999999,
            ]))
            ->assertNotFound();
    }

    public function test_a_cross_proxy_event_id_returns_404(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $otherProxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $foreignEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $otherProxy->id, 'team_id' => $otherProxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $proxy, $foreignEvent))
            ->assertNotFound();
    }

    public function test_a_cross_team_proxy_id_returns_404(): void
    {
        $user = $this->actingUser();
        $other = User::factory()->createQuietly();
        $foreignProxy = Proxy::factory()->createQuietly(['team_id' => $other->current_team_id]);
        $foreignEvent = WebhookEvent::factory()->createQuietly(['proxy_id' => $foreignProxy->id, 'team_id' => $foreignProxy->team_id]);

        $this->actingAs($user)
            ->get($this->route($user, $foreignProxy, $foreignEvent))
            ->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->get($this->route($user, $proxy, $event))->assertRedirect(route('login'));
    }

    public function test_a_non_member_is_denied(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $stranger = User::factory()->createQuietly();
        $stranger->switchTeam($stranger->currentTeam);

        $this->actingAs($stranger)
            ->get($this->route($user, $proxy, $event))
            ->assertStatus(404);
    }

    public function test_it_logs_identifiers_only_never_the_payload_body(): void
    {
        Log::spy();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'body' => '{"super":"secret"}',
        ]);

        $this->actingAs($user)->get($this->route($user, $proxy, $event))->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($proxy, $event) {
                $flattened = json_encode($context);

                return $message === 'payload.revealed'
                    && $context === [
                        'team_id' => $proxy->team_id,
                        'proxy_id' => $proxy->id,
                        'event_id' => $event->id,
                        'ingest_id' => $event->ingest_id,
                    ]
                    && ! str_contains((string) $flattened, 'secret');
            });
    }

    public function test_a_cleaned_events_reveal_attempt_is_never_logged(): void
    {
        Log::spy();

        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);
        $event = WebhookEvent::factory()->cleaned()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);

        $this->actingAs($user)->get($this->route($user, $proxy, $event))->assertStatus(410);

        Log::shouldNotHaveReceived('info');
    }
}
