<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Proxy;
use App\Models\User;

/**
 * Authorization for proxy management actions (PRD-02 AC1/AC4/AC5/AC6; ADR-009 §3,
 * Amendment A2.3).
 *
 * Permission-based, never a role check: every decision resolves the proxy's owning
 * team and gates on a `TeamPermission` via `$user->hasTeamPermission($team, ...)`,
 * so a role held on a different team confers nothing (AC4). Update/delete compose
 * the base CRUD permission with an ownership axis — the actor must either have
 * created the record or hold the matching `-any` bypass permission (Admin/Owner).
 * "Ownership-limited" means the role's bundle lacks the bypass; the policy never
 * names a role.
 */
class ProxyPolicy
{
    /**
     * Determine whether the user can view any proxies.
     *
     * Team-membership presence, not a permission — the list route is guarded by the
     * team scope/membership middleware and renders only the current team's proxies.
     */
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    /**
     * Determine whether the user can view the proxy.
     */
    public function view(User $user, Proxy $proxy): bool
    {
        return $user->hasTeamPermission($proxy->team, TeamPermission::ViewProxy);
    }

    /**
     * Determine whether the user can view the team-wide event queue (every
     * proxy the team owns, not one). Same permission {@see view()} gates a
     * single proxy on, checked at the team level directly since this ability
     * has no single `Proxy` instance to resolve a team from.
     */
    public function viewEventQueue(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->hasTeamPermission($team, TeamPermission::ViewProxy);
    }

    /**
     * Determine whether the user can create proxies on their acting team.
     */
    public function create(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->hasTeamPermission($team, TeamPermission::CreateProxy);
    }

    /**
     * Determine whether the user can update the proxy.
     */
    public function update(User $user, Proxy $proxy): bool
    {
        return $user->hasTeamPermission($proxy->team, TeamPermission::UpdateProxy)
            && $this->ownsOrCanManageAny($user, $proxy, TeamPermission::UpdateAnyProxy);
    }

    /**
     * Determine whether the user can delete the proxy.
     */
    public function delete(User $user, Proxy $proxy): bool
    {
        return $user->hasTeamPermission($proxy->team, TeamPermission::DeleteProxy)
            && $this->ownsOrCanManageAny($user, $proxy, TeamPermission::DeleteAnyProxy);
    }

    /**
     * Determine whether the user can replay a captured event on the proxy
     * (AC14; ADR-017 Decision 4). Single-axis — no ownership limit applies to
     * replay at all, unlike update/delete, so there is no `-any` bypass to
     * compose.
     */
    public function replay(User $user, Proxy $proxy): bool
    {
        return $user->hasTeamPermission($proxy->team, TeamPermission::ReplayProxy);
    }

    /**
     * The ownership axis: the actor created the proxy, or holds the given `-any`
     * bypass permission on the proxy's owning team. A null `created_by` matches no
     * user, so it is a safe deny for ownership-limited roles (ADR-009 Amendment A3).
     */
    protected function ownsOrCanManageAny(User $user, Proxy $proxy, TeamPermission $bypass): bool
    {
        return (int) $proxy->created_by === (int) $user->id
            || $user->hasTeamPermission($proxy->team, $bypass);
    }
}
