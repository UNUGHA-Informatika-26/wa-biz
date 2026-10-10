<?php

namespace App\DataTransferObjects\AI;

final readonly class AiDecision
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $intent,
        public string $responseAction,
        public ?string $responseText,
        public bool $handoffRequired,
        public ?int $handoffDepartmentId,
        public ?int $handoffCsMemberId,
        public string $reason,
        public ?float $confidence = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'response_action' => $this->responseAction,
            'response_text' => $this->responseText,
            'handoff_required' => $this->handoffRequired,
            'handoff_department_id' => $this->handoffDepartmentId,
            'handoff_cs_member_id' => $this->handoffCsMemberId,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }
}
