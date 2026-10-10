<?php

namespace App\DataTransferObjects\AI;

final readonly class ConversationHandoffResult
{
    public function __construct(
        public ConversationHandoffStatus $status,
        public string $reason,
        public ?int $departmentId = null,
        public ?int $csMemberId = null,
    ) {}
}
