<?php

namespace App\Http\Controllers;

use App\Enums\AnalyticsWindow;
use App\Models\TeamInvitation;
use App\Services\DeliveryStatistics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private DeliveryStatistics $statistics,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $email = strtolower($user->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        // The dashboard route is always reached through the `{current_team}`
        // prefix behind EnsureTeamMembership, which aborts 403 before this
        // action runs unless the user belongs to that team — so `currentTeam`
        // is guaranteed here. This check is the controller-level guard T9's
        // completion notes describe (plan-11 § Test strategy): it exists so
        // `DeliveryStatistics::forTeam()`/`proxyBreakdown()`, which take a
        // plain non-nullable `int $teamId`, are never called without one,
        // rather than the service silently accepting a team-less caller.
        $team = $user->currentTeam;
        abort_if($team === null, 404);

        // AC17/plan-11 Technical ruling 8: an unrecognised or absent `window`
        // resolves to the default rather than a 422 — never propagated further.
        $window = AnalyticsWindow::tryFrom((string) $request->query('window')) ?? AnalyticsWindow::default();

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'statistics' => $this->statistics->forTeam($team->id, $window),
            'proxies' => $this->statistics->proxyBreakdown($team->id, $window),
        ]);
    }
}
