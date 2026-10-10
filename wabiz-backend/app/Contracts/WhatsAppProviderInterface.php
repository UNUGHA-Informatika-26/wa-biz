<?php

namespace App\Contracts;

use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\WhatsAppConnection;

interface WhatsAppProviderInterface
{
    public function identifier(): string;

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult;

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult;
}
