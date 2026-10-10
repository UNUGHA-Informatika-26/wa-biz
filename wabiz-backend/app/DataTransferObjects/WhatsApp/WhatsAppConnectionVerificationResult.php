<?php

namespace App\DataTransferObjects\WhatsApp;

final readonly class WhatsAppConnectionVerificationResult
{
    public function __construct(
        public bool $success,
        public string $provider,
        public string $status,
    ) {}
}
