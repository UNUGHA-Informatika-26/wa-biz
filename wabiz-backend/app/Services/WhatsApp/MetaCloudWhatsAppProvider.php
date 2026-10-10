<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    public function identifier(): string
    {
        return 'meta_cloud';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        [$accessToken, $phoneNumberId, $version, $baseUrl, $businessAccountId] = $this->configuration($connection);
        if ($businessAccountId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $businessAccountId) !== 1) {
            throw new WhatsAppProviderException('invalid_business_account_id');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout(max(1, (int) config('services.whatsapp.meta.connect_timeout', 5)))
                ->timeout(max(1, (int) config('services.whatsapp.meta.timeout', 20)))
                ->get($baseUrl.'/'.$version.'/'.$businessAccountId.'/phone_numbers', [
                    'fields' => 'id,display_phone_number,verified_name',
                ]);
        } catch (ConnectionException) {
            throw new WhatsAppProviderException('transport_error');
        }

        $this->throwForHttpFailure($response);

        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WhatsAppProviderException('malformed_provider_response', $response->status());
        }

        if (! is_array($body) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new WhatsAppProviderException('malformed_provider_response', $response->status());
        }

        foreach ($body['data'] as $phoneNumber) {
            if (is_array($phoneNumber) && ($phoneNumber['id'] ?? null) === $phoneNumberId) {
                return new WhatsAppConnectionVerificationResult(true, $this->identifier(), 'verified');
            }
        }

        throw new WhatsAppProviderException('phone_number_not_found', $response->status());
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        if (! $connection->enabled) {
            throw new WhatsAppProviderException('connection_disabled');
        }

        [$accessToken, $phoneNumberId, $version, $baseUrl] = $this->configuration($connection);

        $recipient = preg_replace('/\D+/', '', $request->recipientPhone) ?? '';
        if (strlen($recipient) < 7 || strlen($recipient) > 20 || trim($request->text) === '' || mb_strlen($request->text) > 4096) {
            throw new WhatsAppProviderException('invalid_message_request');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout(max(1, (int) config('services.whatsapp.meta.connect_timeout', 5)))
                ->timeout(max(1, (int) config('services.whatsapp.meta.timeout', 20)))
                ->post($baseUrl.'/'.$version.'/'.$phoneNumberId.'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $request->text],
                ]);
        } catch (ConnectionException) {
            throw new WhatsAppProviderException('transport_error');
        }

        $this->throwForHttpFailure($response, 'phone_number_not_found');

        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WhatsAppProviderException('malformed_provider_response', $response->status());
        }

        $externalMessageId = is_array($body) ? ($body['messages'][0]['id'] ?? null) : null;
        if (! is_string($externalMessageId) || trim($externalMessageId) === '') {
            throw new WhatsAppProviderException('unexpected_provider_response', $response->status());
        }

        return new WhatsAppSendResult(
            success: true,
            provider: $this->identifier(),
            externalMessageId: $externalMessageId,
            status: 'accepted',
        );
    }

    /** @return array{string, string, string, string, string} */
    private function configuration(WhatsAppConnection $connection): array
    {
        $credentials = $connection->credentials;
        $accessToken = is_array($credentials) && is_string($credentials['access_token'] ?? null)
            ? trim($credentials['access_token'])
            : '';
        if ($accessToken === '') {
            throw new WhatsAppProviderException('missing_credentials');
        }

        $phoneNumberId = trim((string) $connection->phone_number_id);
        if ($phoneNumberId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $phoneNumberId) !== 1) {
            throw new WhatsAppProviderException('invalid_phone_number_id');
        }

        $version = trim((string) config('services.whatsapp.meta.graph_api_version', ''));
        if (preg_match('/^v[0-9]+\.[0-9]+$/', $version) !== 1) {
            throw new WhatsAppProviderException('configuration_missing_api_version');
        }

        $baseUrl = rtrim((string) config('services.whatsapp.meta.base_url', 'https://graph.facebook.com'), '/');
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new WhatsAppProviderException('configuration_invalid_base_url');
        }

        return [$accessToken, $phoneNumberId, $version, $baseUrl, trim((string) $connection->business_account_id)];
    }

    private function throwForHttpFailure(Response $response, string $notFoundKind = 'not_found'): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $failureKind = match (true) {
            $status === 401 => 'authentication_error',
            $status === 403 => 'permission_denied',
            $status === 404 => $notFoundKind,
            $status === 409 => 'provider_conflict',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'provider_unavailable',
            default => 'provider_request_rejected',
        };

        throw new WhatsAppProviderException($failureKind, $status);
    }
}
