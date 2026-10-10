<?php

namespace Tests\Support;

use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WppConnectWhatsAppProvider;

class DenyWppConnectWhatsAppProvider extends WppConnectWhatsAppProvider
{
    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        throw new WhatsAppProviderException('provider_blocked_in_tests');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        throw new WhatsAppProviderException('provider_blocked_in_tests');
    }
}
