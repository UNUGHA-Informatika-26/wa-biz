<?php

namespace App\Services\WhatsApp;

use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Private server-side client for the loopback WPPConnect instance. */
class WppConnectGateway
{
    /** @return array{status: string, phone_number: ?string} */
    public function start(WhatsAppConnection $connection): array
    {
        $this->assertProvider($connection);
        $session = $this->sessionRef($connection);
        $token = $this->token($connection, $session);

        $connection->forceFill(['status' => 'connecting', 'enabled' => false])->save();
        $this->request($connection, 'POST', "/api/{$session}/start-session", ['waitQrCode' => false]);

        return $this->refreshStatus($connection);
    }

    /** @return array{status: string, phone_number: ?string} */
    public function refreshStatus(WhatsAppConnection $connection): array
    {
        $this->assertProvider($connection);
        $session = $this->sessionRef($connection);
        $body = $this->request($connection, 'GET', "/api/{$session}/status-session")->json();
        $gatewayStatus = strtoupper((string) (is_array($body) ? ($body['status'] ?? '') : ''));
        $status = match ($gatewayStatus) {
            'CONNECTED', 'OPEN' => 'connected',
            'QRCODE', 'QR_READY' => 'qr_ready',
            'CONFLICT', 'UNPAIRED', 'UNPAIRED_IDLE' => 'reconnecting',
            'CLOSED', 'DISCONNECTED', '' => 'disconnected',
            default => 'connecting',
        };

        $phone = null;
        if ($status === 'connected') {
            $phoneBody = $this->request($connection, 'GET', "/api/{$session}/get-phone-number")->json();
            $phone = $this->normalizePhone(is_array($phoneBody) ? ($phoneBody['response'] ?? null) : null);
            if ($phone !== null) {
                $connection->phone_number = $phone;
            }
        }

        $previousStatus = $connection->status;
        $connection->forceFill([
            'status' => $status,
            'enabled' => $status === 'connected',
            'last_connected_at' => $status === 'connected'
                ? ($previousStatus === 'connected' ? $connection->last_connected_at : now())
                : $connection->last_connected_at,
        ])->save();

        return ['status' => $status, 'phone_number' => $phone ?? ($connection->phone_number ?: null)];
    }

    /** @return array{mime_type: string, base64: string} */
    public function qr(WhatsAppConnection $connection): array
    {
        $this->assertProvider($connection);
        $session = $this->sessionRef($connection);
        $response = $this->request($connection, 'GET', "/api/{$session}/qrcode-session");
        $mime = strtolower(trim(explode(';', $response->header('Content-Type') ?? '')[0]));
        if ($mime !== 'image/png' || $response->body() === '') {
            throw new WhatsAppProviderException('qr_not_available', $response->status());
        }

        $connection->forceFill(['status' => 'qr_ready'])->save();

        return ['mime_type' => $mime, 'base64' => base64_encode($response->body())];
    }

    public function disconnect(WhatsAppConnection $connection): void
    {
        $this->assertProvider($connection);
        if (! $connection->gateway_session_ref) {
            $connection->forceFill(['status' => 'disconnected', 'enabled' => false])->save();

            return;
        }
        $session = $this->sessionRef($connection);
        $endpoint = $connection->status === 'connected' ? 'logout-session' : 'close-session';
        try {
            $this->request($connection, 'POST', "/api/{$session}/{$endpoint}");
        } catch (WhatsAppProviderException $exception) {
            if ($endpoint === 'close-session' && $exception->statusCode === 500) {
                // WPPConnect clears its client state before closing Chromium.
                // A second close is idempotent and confirms the session is shut.
                $this->request($connection, 'POST', "/api/{$session}/close-session");
            } elseif (! in_array($exception->statusCode, [404, 409], true)) {
                throw $exception;
            }
        }

        $credentials = $connection->credentials ?? [];
        unset($credentials['wppconnect_token']);
        $connection->forceFill(['status' => 'disconnected', 'enabled' => false, 'credentials' => $credentials])->save();
    }

    public function verify(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        $state = $this->refreshStatus($connection);
        if ($state['status'] !== 'connected') {
            throw new WhatsAppProviderException('connection_inactive');
        }

        return new WhatsAppConnectionVerificationResult(true, 'wppconnect', 'connected');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $message): WhatsAppSendResult
    {
        if (! $connection->enabled || $connection->status !== 'connected') {
            throw new WhatsAppProviderException('connection_inactive');
        }
        $phone = preg_replace('/\D+/', '', $message->recipientPhone) ?? '';
        if (strlen($phone) < 7 || strlen($phone) > 20 || trim($message->text) === '' || mb_strlen($message->text) > 4096) {
            throw new WhatsAppProviderException('invalid_message_request');
        }

        $body = $this->request($connection, 'POST', '/api/'.$this->sessionRef($connection).'/send-message', [
            'phone' => [$phone],
            'isGroup' => false,
            'message' => $message->text,
        ])->json();
        $sent = is_array($body) ? ($body['response'][0] ?? null) : null;
        $id = is_array($sent) ? ($sent['id'] ?? $sent['_data']['id']['id'] ?? null) : null;
        if (! is_string($id) || trim($id) === '') {
            throw new WhatsAppProviderException('malformed_provider_response');
        }

        return new WhatsAppSendResult(true, 'wppconnect', $id, 'accepted');
    }

    private function sessionRef(WhatsAppConnection $connection): string
    {
        if (! $connection->gateway_session_ref) {
            $connection->forceFill(['gateway_session_ref' => (string) Str::uuid()])->save();
        }

        return (string) $connection->gateway_session_ref;
    }

    private function token(WhatsAppConnection $connection, string $session): string
    {
        $credentials = $connection->credentials ?? [];
        if (is_string($credentials['wppconnect_token'] ?? null) && $credentials['wppconnect_token'] !== '') {
            return $credentials['wppconnect_token'];
        }

        $secret = (string) config('services.wppconnect.secret_key');
        if ($secret === '') {
            throw new WhatsAppProviderException('configuration_missing_secret');
        }

        $response = Http::acceptJson()->connectTimeout(3)->timeout(15)
            ->post($this->baseUrl()."/api/{$session}/".rawurlencode($secret).'/generate-token');
        $this->check($response);
        $token = $response->json('full');
        if (! is_string($token) || $token === '') {
            throw new WhatsAppProviderException('malformed_provider_response', $response->status());
        }

        $credentials['wppconnect_token'] = $token;
        $connection->forceFill(['credentials' => $credentials])->save();

        return $token;
    }

    private function request(WhatsAppConnection $connection, string $method, string $path, ?array $payload = null): Response
    {
        $credentials = $connection->credentials ?? [];
        $session = $this->sessionRef($connection);
        $fullToken = $credentials['wppconnect_token'] ?? null;
        if (! is_string($fullToken) || $fullToken === '') {
            $fullToken = $this->token($connection, $session);
        }
        [$tokenSession, $bearerToken] = array_pad(explode(':', $fullToken, 2), 2, null);
        if ($tokenSession !== $session || ! is_string($bearerToken) || $bearerToken === '') {
            throw new WhatsAppProviderException('malformed_provider_response');
        }

        try {
            // WPPConnect's auth middleware expects only the bcrypt token in the
            // Authorization header when the session name is already in the URL.
            $request = Http::acceptJson()->withToken($bearerToken)->connectTimeout(3)->timeout(15);
            $response = $payload === null
                ? $request->send($method, $this->baseUrl().$path)
                : $request->send($method, $this->baseUrl().$path, ['json' => $payload]);
        } catch (ConnectionException) {
            throw new WhatsAppProviderException('transport_error');
        }
        $this->check($response);

        return $response;
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        throw new WhatsAppProviderException(match (true) {
            $status === 401 || $status === 400 => 'authentication_error',
            $status === 403 => 'permission_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'provider_conflict',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'provider_unavailable',
            default => 'provider_request_rejected',
        }, $status);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.wppconnect.base_url', 'http://127.0.0.1:21465'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || ! in_array($parts['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || (int) ($parts['port'] ?? 0) !== 21465) {
            throw new WhatsAppProviderException('configuration_invalid_base_url');
        }

        return $url;
    }

    private function assertProvider(WhatsAppConnection $connection): void
    {
        if ($connection->provider !== 'wppconnect') {
            throw new WhatsAppProviderException('unsupported_provider');
        }
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['user'] ?? null;
        }
        if (! is_string($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', preg_replace('/@.*$/', '', $value) ?? '') ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 20 ? '+'.$digits : null;
    }
}
