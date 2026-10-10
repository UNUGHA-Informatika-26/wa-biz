<?php

namespace App\Contracts;

use App\DataTransferObjects\AI\AiContext;
use App\DataTransferObjects\AI\AiProviderResponse;

interface AiProviderInterface
{
    public function generateResponse(AiContext $context): AiProviderResponse;
}
