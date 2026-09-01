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
 * Reached by somebody with no account: the URL's signature is the entire
 * authorisation. `show()` renders and never mutates — a GET that approved on
 * load would be fired by link scanners and mail preview fetchers. The nonce,
 * not the signature, is what makes a link single-use and what lets a newer
 * challenge void an older one without a revocation list.
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
     * The destination, unscoped — the approver has no team. Soft-deleted ones
     * are deliberately not resolved: nothing to approve.
     */
    private function find(int $destination): ?Destination
    {
        return Destination::query()->withoutGlobalScope(TeamScope::class)->find($destination);
    }

    /**
     * The asking team's name — the one identifying fact this page owes its
     * visitor (AC27), never on the `invalid` outcome. By id: the visitor has
     * no team for a scoped relation to resolve through.
     */
    private function teamName(Destination $destination): string
    {
        return Team::query()->whereKey($destination->team_id)->value('name') ?? '';
    }

    /**
     * Which screen this request is owed (design-18 Screen 4).
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
            // Its own signature: one signature covers one URL. Against the
            // challenge expiry, not the link's grace, so it cannot outlive it.
            'approveUrl' => URL::temporarySignedRoute(
                'destinations.validate.store',
                $destination->validation_challenge_expires_at,
                ['destination' => $destination->id, 'nonce' => $nonce],
            ),
        ];
    }
}
