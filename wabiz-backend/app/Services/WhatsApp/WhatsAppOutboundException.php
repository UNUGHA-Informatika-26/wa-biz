<?php

namespace App\Services\WhatsApp;

use RuntimeException;

class WhatsAppOutboundException extends RuntimeException
{
    public function __construct(
        public readonly string $failureKind,
        public readonly int $httpStatus,
    ) {
        parent::__construct('WhatsApp outbound request could not be completed.');
    }
}
