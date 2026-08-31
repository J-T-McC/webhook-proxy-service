<?php

namespace App\Http\Controllers;

use App\Enums\DestinationValidationState;
use App\Models\Destination;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public approval surface for destination validation (#18 AC26–AC29).
 *
 * Reached by somebody with **no account**, arriving cold from a link that was
 * posted to the destination's own URL. There is no session, no team and no
 * navigation — the signature on the URL is the entire authorisation, and the
 * `signed` middleware is what checks it.
 *
 * `show()` renders and never mutates. Approval is `store()`, a POST, because a
 * GET that approved on load would be fired by link scanners, mail preview
 * fetchers and corporate security proxies that open URLs before any human sees
 * them.
 *
 * The nonce, not the signature, is what makes a link single-use: a signed URL
 * is replayable by anyone holding it, so approval additionally requires the
 * nonce to still be the destination's current one. That is also what makes a
 * newer challenge void an older link without any revocation list.
 */
class DestinationValidationController extends Controller
{
    public function show(Request $request, int $destination): Response
    {
        return Inertia::render(
            'destinations/Validate',
            $this->outcomeFor($this->find($destination), (string) $request->query('nonce')),
        );
    }

    public function store(Request $request, int $destination): Response
    {
        $model = $this->find($destination);
        $nonce = (string) $request->query('nonce');
        $outcome = $this->outcomeFor($model, $nonce);

        if ($outcome['outcome'] !== 'approvable' || $model === null) {
            return Inertia::render('destinations/Validate', $outcome);
        }

        // Single-use: the nonce is cleared here, so replaying this POST with a
        // still-valid signature lands on `already_approved` rather than
        // approving a second time.
        $model->forceFill([
            'validation_state' => DestinationValidationState::Validated,
            'validated_at' => now(),
            'validation_nonce' => null,
            'validation_challenge_expires_at' => null,
        ])->save();

        return Inertia::render('destinations/Validate', [
            'outcome' => 'approved',
            'destinationUrl' => $model->url,
            'teamName' => $this->teamName($model),
        ]);
    }

    /**
     * The destination, ignoring the team scope. The approver has no team, so
     * the scope would otherwise hide every destination from its own approval
     * route. Soft-deleted destinations are deliberately NOT resolved: a deleted
     * destination has nothing to approve.
     */
    private function find(int $destination): ?Destination
    {
        return Destination::query()->withoutGlobalScope(TeamScope::class)->find($destination);
    }

    /**
     * The asking team's name — the one identifying fact this page owes its
     * visitor (AC27; review-18 finding 3): they are deciding whether to accept
     * a stranger's traffic, and AC17 carves the team name out of its
     * no-team-data rule for exactly this page. Resolved by id because the
     * visitor has no team of their own for a scoped relation to resolve
     * through. Never on the `invalid` outcome — an unresolvable link has no
     * challenge to name a team from.
     */
    private function teamName(Destination $destination): string
    {
        return Team::query()->whereKey($destination->team_id)->value('name') ?? '';
    }

    /**
     * Which of the four screens this request is owed (design-18 Screen 4).
     * They are four distinct outcomes rather than one screen with a variable
     * message, because the reader's next action differs in each case.
     *
     * @return array{outcome: string, destinationUrl?: string, approveUrl?: string, teamName?: string}
     */
    private function outcomeFor(?Destination $destination, string $nonce): array
    {
        // A missing destination and a wrong nonce are reported identically, so
        // the page cannot be used to discover which destination ids exist.
        if ($destination === null) {
            return ['outcome' => 'invalid'];
        }

        if ($destination->validation_state === DestinationValidationState::Validated) {
            return [
                'outcome' => 'already_approved',
                'destinationUrl' => $destination->url,
                'teamName' => $this->teamName($destination),
            ];
        }

        if ($destination->validation_nonce === null
            || $nonce === ''
            || ! hash_equals($destination->validation_nonce, $nonce)) {
            return ['outcome' => 'invalid'];
        }

        if ($destination->validation_challenge_expires_at?->isPast()) {
            return [
                'outcome' => 'expired',
                'destinationUrl' => $destination->url,
                'teamName' => $this->teamName($destination),
            ];
        }

        return [
            'outcome' => 'approvable',
            'destinationUrl' => $destination->url,
            'teamName' => $this->teamName($destination),
            // The POST needs its own signature — a signature is over one URL,
            // so the GET's does not carry across to the store route. Minted
            // against the same challenge expiry, so the approve button cannot
            // outlive the link that produced it.
            'approveUrl' => URL::temporarySignedRoute(
                'destinations.validate.store',
                $destination->validation_challenge_expires_at,
                ['destination' => $destination->id, 'nonce' => $nonce],
            ),
        ];
    }
}
