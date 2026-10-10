<?php

namespace App\Services\AI\Gemini;

use RuntimeException;

class GeminiClientException extends RuntimeException
{
    public function __construct(
        public readonly string $failureKind,
        public readonly ?int $statusCode = null,
        public readonly ?int $latencyMilliseconds = null,
    ) {
        parent::__construct('Gemini provider request failed.');
    }
}
