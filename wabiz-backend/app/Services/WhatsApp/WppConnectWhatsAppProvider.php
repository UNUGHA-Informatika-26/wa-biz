<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\WhatsAppConnection;

class WppConnectWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(private readonly WppConnectGateway $gateway) {}

    public function identifier(): string
    {
        return 'wppconnect';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        return $this->gateway->verify($connection);
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        return $this->gateway->sendText($connection, $request);
    }
}
