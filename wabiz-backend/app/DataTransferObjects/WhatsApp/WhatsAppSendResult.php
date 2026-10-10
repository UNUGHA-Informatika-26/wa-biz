<?php

namespace App\DataTransferObjects\WhatsApp;

final readonly class WhatsAppSendResult
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public bool $success,
        public string $provider,
        public ?string $externalMessageId,
        public string $status,
        public array $metadata = [],
    ) {}
}
