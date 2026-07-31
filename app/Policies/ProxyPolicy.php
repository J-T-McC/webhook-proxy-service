<?php

namespace App\Policies;

use App\Models\Proxy;
use App\Models\User;

/**
 * Authorization for proxy management actions (AC5/AC6/AC15/AC16e).
 *
 * Expressed against proxy *actions* (view/update/delete) and team ownership so the
 * roles seam (#2) can layer richer permission checks later without reshaping the
 * controller/authorization surface. At item #1 any member of the owning team may
 * perform every action.
 */
class ProxyPolicy
{
    /**
     * Determine whether the user can view any proxies.
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
        return $this->ownsThroughTeam($user, $proxy);
    }

    /**
     * Determine whether the user can create proxies.
     */
    public function create(User $user): bool
    {
        return $user->current_team_id !== null;
    }

    /**
     * Determine whether the user can update the proxy.
     */
    public function update(User $user, Proxy $proxy): bool
    {
        return $this->ownsThroughTeam($user, $proxy);
    }

    /**
     * Determine whether the user can delete the proxy.
     */
    public function delete(User $user, Proxy $proxy): bool
    {
        return $this->ownsThroughTeam($user, $proxy);
    }

    /**
     * The proxy belongs to a team the user is a member of.
     */
    protected function ownsThroughTeam(User $user, Proxy $proxy): bool
    {
        return $user->teams()->whereKey($proxy->team_id)->exists();
    }
}
