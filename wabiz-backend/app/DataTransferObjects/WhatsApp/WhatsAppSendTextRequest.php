<?php

namespace App\DataTransferObjects\WhatsApp;

final readonly class WhatsAppSendTextRequest
{
    public function __construct(
        public string $recipientPhone,
        public string $text,
    ) {}
}
