<?php

namespace App\Contracts;

use App\DataTransferObjects\AI\AiDecision;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;

interface AiOrchestrator
{
    public function decide(Organization $organization, Conversation $conversation, Message $incomingMessage): AiDecision;
}
