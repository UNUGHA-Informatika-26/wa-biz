<?php

namespace App\DataTransferObjects\AI;

enum ConversationHandoffStatus: string
{
    case TRANSFERRED = 'TRANSFERRED';
    case ALREADY_HANDOFF = 'ALREADY_HANDOFF';
    case HANDOFF_UNASSIGNED = 'HANDOFF_UNASSIGNED';
    case REJECTED = 'REJECTED';
}
