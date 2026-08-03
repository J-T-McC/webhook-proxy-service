<?php

namespace App\Data;

/**
 * Page-level proxy permission affordances for the acting user on a team
 * (ADR-009 §4 tier 1, Amendment A5). Create/view are team-wide, not
 * ownership-scoped — per-record update/delete flags live on ProxyResource.
 */
readonly class ProxyPermissions
{
    public function __construct(
        public bool $canCreateProxy,
        public bool $canViewProxy,
    ) {
        //
    }
}
