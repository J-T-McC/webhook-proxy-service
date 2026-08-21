<?php

namespace App\Data;

/**
 * Page-level proxy permission affordances for the acting user on a team
 * (ADR-009 §4 tier 1, Amendment B4). All booleans are derived once per page from
 * the current role's bundle — never per record. The client composes each proxy's
 * update/delete affordance from these page-level booleans plus the per-record
 * `is_creator` flag on ProxyResource: `canUpdateProxy && (is_creator ||
 * canUpdateAnyProxy)` (and likewise for delete), mirroring
 * ProxyPolicy::ownsOrCanManageAny without re-running the policy. The server
 * ProxyPolicy remains the authoritative gate (Amendment B2).
 */
readonly class ProxyPermissions
{
    public function __construct(
        public bool $canCreateProxy,
        public bool $canViewProxy,
        public bool $canUpdateProxy,
        public bool $canDeleteProxy,
        public bool $canUpdateAnyProxy,
        public bool $canDeleteAnyProxy,
        public bool $canReplayProxy,
    ) {
        //
    }
}
