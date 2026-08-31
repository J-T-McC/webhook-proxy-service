<?php

namespace Tests\Feature\Destinations;

use App\Enums\DestinationValidationState;
use App\Models\Destination;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The public approval surface (#18 AC22–AC29). Every request here is made
 * WITHOUT authentication on purpose: the approver has no account.
 */
class DestinationValidationControllerTest extends TestCase
{
    private function showUrl(Destination $destination, ?string $nonce = null): string
    {
        return URL::temporarySignedRoute(
            'destinations.validate.show',
            now()->addDays(7),
            ['destination' => $destination->id, 'nonce' => $nonce ?? $destination->validation_nonce],
        );
    }

    private function storeUrl(Destination $destination, ?string $nonce = null): string
    {
        return URL::temporarySignedRoute(
            'destinations.validate.store',
            now()->addDays(7),
            ['destination' => $destination->id, 'nonce' => $nonce ?? $destination->validation_nonce],
        );
    }

    public function test_an_unauthenticated_visitor_can_open_a_signed_link(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('destinations/Validate')
                ->where('outcome', 'approvable'));
    }

    public function test_opening_the_link_does_not_approve_it(): void
    {
        // AC28, the load-bearing one: link scanners, mail preview fetchers and
        // corporate security proxies open URLs before any human sees them.
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination))->assertOk();

        $this->assertSame(
            DestinationValidationState::Pending,
            $destination->refresh()->validation_state,
            'A GET must never mutate state — approval is the POST.',
        );
    }

    public function test_posting_approves_the_destination(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->post($this->storeUrl($destination))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'approved'));

        $destination->refresh();

        $this->assertSame(DestinationValidationState::Validated, $destination->validation_state);
        $this->assertNotNull($destination->validated_at);
        $this->assertNull($destination->validation_nonce);
    }

    public function test_a_second_post_with_a_still_valid_signature_is_refused(): void
    {
        // The signature is still good — a signed URL is replayable. The nonce
        // is what makes the link single-use.
        $destination = Destination::factory()->pendingValidation()->createQuietly();
        $url = $this->storeUrl($destination);

        $this->post($url)->assertOk();

        $this->post($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'already_approved'));
    }

    public function test_a_superseded_nonce_is_refused_while_its_signature_is_still_valid(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();
        $oldUrl = $this->storeUrl($destination);

        // A newer challenge was sent; the old link must be inert.
        $destination->forceFill(['validation_nonce' => 'a-newer-nonce'])->save();

        $this->post($oldUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'invalid'));

        $this->assertSame(
            DestinationValidationState::Pending,
            $destination->refresh()->validation_state,
        );
    }

    public function test_a_tampered_signature_is_rejected_by_the_middleware(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination).'&tampered=1')->assertForbidden();
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get("/destinations/{$destination->id}/validate")->assertForbidden();
    }

    public function test_an_expired_challenge_is_refused_even_with_a_good_signature_and_nonce(): void
    {
        $destination = Destination::factory()->expiredValidation()->createQuietly();

        $this->post($this->storeUrl($destination))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'expired'));

        $this->assertSame(
            DestinationValidationState::Pending,
            $destination->refresh()->validation_state,
        );
    }

    public function test_an_unknown_destination_reports_the_same_outcome_as_a_wrong_nonce(): void
    {
        // The page must not be usable to discover which destination ids exist.
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination, 'wrong-nonce'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'invalid'));

        $missing = URL::temporarySignedRoute(
            'destinations.validate.show',
            now()->addDays(7),
            ['destination' => 999999, 'nonce' => 'anything'],
        );

        $this->get($missing)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'invalid'));
    }

    public function test_the_team_scope_does_not_hide_the_destination_from_its_own_approval_route(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outcome', 'approvable'));
    }

    public function test_the_page_never_receives_the_nonce_as_a_prop(): void
    {
        // AC24: a member who can read the link can approve their own
        // destination. The page is rendered from a signed URL, so the token is
        // in the address bar by necessity — but it must not also be handed to
        // the client as data that could be logged or echoed elsewhere.
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->get($this->showUrl($destination))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('nonce'));
    }
}
