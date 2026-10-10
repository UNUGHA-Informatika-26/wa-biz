<?php

namespace App\DataTransferObjects\AI;

final readonly class AiProviderResponse
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ?string $responseText,
        public ?float $confidence = null,
        public array $metadata = [],
    ) {}
}
