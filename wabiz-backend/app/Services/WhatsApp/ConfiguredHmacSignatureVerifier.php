<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppWebhookSignatureVerifier;

class ConfiguredHmacSignatureVerifier implements WhatsAppWebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature, ?string $secret): bool
    {
        if ($secret === null || strlen($secret) < 32 || $signature === null
            || ! preg_match('/^sha256=([a-f0-9]{64})$/i', $signature, $matches)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($matches[1]));
    }
}
