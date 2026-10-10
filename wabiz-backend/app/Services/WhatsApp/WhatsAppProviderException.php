<?php

namespace App\Services\WhatsApp;

use RuntimeException;

class WhatsAppProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $failureKind,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct('WhatsApp provider request failed.');
    }
}
