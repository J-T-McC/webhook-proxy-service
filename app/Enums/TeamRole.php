<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
                // Full proxy CRUD plus both ownership-bypass cases: Admin manages
                // any team proxy regardless of creator (ADR-009 Amendment A2.2).
                TeamPermission::ViewProxy,
                TeamPermission::CreateProxy,
                TeamPermission::UpdateProxy,
                TeamPermission::DeleteProxy,
                TeamPermission::UpdateAnyProxy,
                TeamPermission::DeleteAnyProxy,
                // Replay (ADR-017 Decision 4): no ownership limit applies (AC14).
                TeamPermission::ReplayProxy,
            ],
            // Full proxy CRUD but NO -any bypass: Member acts only on proxies they
            // created (ADR-009 Amendment A2.2). Team-admin permissions unchanged.
            // Replay is the one proxy action with no ownership limit at all (AC14) —
            // granted here directly, not via a bypass case.
            self::Member => [
                TeamPermission::ViewProxy,
                TeamPermission::CreateProxy,
                TeamPermission::UpdateProxy,
                TeamPermission::DeleteProxy,
                TeamPermission::ReplayProxy,
            ],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }
}
