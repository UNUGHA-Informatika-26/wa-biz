<?php

namespace App\DataTransferObjects\WhatsApp;

use App\Models\Message;

final readonly class WhatsAppOutboundMessageResult
{
    public function __construct(
        public Message $message,
        public bool $replayed = false,
    ) {}
}
