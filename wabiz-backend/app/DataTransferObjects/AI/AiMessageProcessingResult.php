<?php

namespace App\DataTransferObjects\AI;

final readonly class AiMessageProcessingResult
{
    public function __construct(
        public AiMessageProcessingStatus $status,
        public string $reason,
        public ?AiDecision $decision = null,
        public ?int $outboundMessageId = null,
    ) {}
}
