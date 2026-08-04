<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case ViewProxy = 'proxy:view';
    case CreateProxy = 'proxy:create';
    case UpdateProxy = 'proxy:update';
    case DeleteProxy = 'proxy:delete';

    // Ownership-bypass axis (ADR-009 Amendment A2.1): "may act on records I did
    // not create." Held by Admin/Owner, omitted from Member — never a role check.
    case UpdateAnyProxy = 'proxy:update-any';
    case DeleteAnyProxy = 'proxy:delete-any';
}
