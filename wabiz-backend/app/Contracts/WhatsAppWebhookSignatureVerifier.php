<?php

namespace App\Contracts;

interface WhatsAppWebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature, ?string $secret): bool;
}
