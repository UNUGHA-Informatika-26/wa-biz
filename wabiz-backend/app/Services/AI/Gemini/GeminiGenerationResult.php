<?php

namespace App\Services\AI\Gemini;

final readonly class GeminiGenerationResult
{
    public function __construct(
        public string $text,
        public int $statusCode,
        public int $latencyMilliseconds,
    ) {}
}
