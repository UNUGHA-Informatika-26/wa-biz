<?php

namespace Tests\Support;

use App\Contracts\AiProviderInterface;
use App\DataTransferObjects\AI\AiContext;
use App\DataTransferObjects\AI\AiProviderResponse;
use App\Services\AI\GeminiAiProvider;
use LogicException;

class DenyAiProvider extends GeminiAiProvider implements AiProviderInterface
{
    public function generateResponse(AiContext $context): AiProviderResponse
    {
        throw new LogicException('AI provider calls are blocked by the test safety guard.');
    }
}
