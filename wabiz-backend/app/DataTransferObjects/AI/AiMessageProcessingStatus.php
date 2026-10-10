<?php

namespace App\DataTransferObjects\AI;

enum AiMessageProcessingStatus: string
{
    case PROCESSED = 'PROCESSED';
    case IGNORED = 'IGNORED';
    case HANDOFF_REQUIRED = 'HANDOFF_REQUIRED';
    case FAILED = 'FAILED';
    case ALREADY_PROCESSED = 'ALREADY_PROCESSED';
}
