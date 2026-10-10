<?php

namespace App\Http\Controllers\Api;

use App\Contracts\WhatsAppWebhookSignatureVerifier;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppWebhookEvent;
use App\Services\AI\AiMessageProcessor;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebhookSignatureVerifier $signatureVerifier,
        private readonly IndonesianPhoneNumberNormalizer $phoneNormalizer,
        private readonly AiMessageProcessor $aiMessageProcessor,
    ) {}

    public function receiveWppConnect(Request $request, string $token): JsonResponse
    {
        $expectedToken = config('services.wppconnect.webhook_token');
        if (! is_string($expectedToken) || strlen($expectedToken) < 32 || ! hash_equals($expectedToken, $token)) {
            return response()->json(['success' => false, 'message' => 'Webhook authentication failed.'], 401);
        }
        if (strlen($request->getContent()) > 1_048_576) {
            return response()->json(['success' => false, 'message' => 'Webhook payload is too large.'], 413);
        }
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }
        if (! is_array($payload) || ! is_string($payload['session'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }
        $eventType = $payload['event'] ?? null;
        if (in_array($eventType, ['onselfmessage', 'onack'], true) || ($payload['fromMe'] ?? false) === true) {
            $connection = WhatsAppConnection::query()
                ->where('provider', 'wppconnect')->where('gateway_session_ref', $payload['session'])
                ->where('enabled', true)->first();
            if (! $connection) {
                return response()->json(['success' => false, 'message' => 'WhatsApp connection not found or disabled.'], 404);
            }

            return response()->json(['success' => true, 'processed' => 0, 'ignored' => 1], 202);
        }
        if ($eventType !== 'onmessage' || (! is_array($payload['id'] ?? null) && ! is_string($payload['id'] ?? null))
            || ! is_string($payload['from'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }

        $messageId = is_string($payload['id']) ? $payload['id'] : ($payload['id']['_serialized'] ?? null);
        if (! is_string($messageId) || trim($messageId) === '' || strlen($messageId) > 200) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }
        if (($payload['fromMe'] ?? null) !== false || ($payload['isGroupMsg'] ?? false) === true
            || ! preg_match('/^[0-9]{7,20}@(c\.us|s\.whatsapp\.net)$/', $payload['from'])) {
            return response()->json(['success' => true, 'processed' => 0, 'ignored' => 1], 202);
        }
        $body = $payload['body'] ?? $payload['content'] ?? null;
        if (! is_string($body) || trim($body) === '' || strlen($body) > 50000
            || ! in_array($payload['type'] ?? null, ['chat', 'text'], true)) {
            return response()->json(['success' => true, 'processed' => 0, 'ignored' => 1], 202);
        }

        $sessionRef = $payload['session'];
        $eventId = 'wppconnect:'.$sessionRef.':'.$messageId;
        $connectionId = null;
        $organizationId = null;
        try {
            $outcome = DB::transaction(function () use ($payload, $body, $sessionRef, $eventId, &$connectionId, &$organizationId): int {
                $connection = WhatsAppConnection::query()
                    ->where('provider', 'wppconnect')->where('gateway_session_ref', $sessionRef)
                    ->where('enabled', true)->lockForUpdate()->first();
                if (! $connection) {
                    return 0;
                }
                $organization = $connection->organization()->lockForUpdate()->firstOrFail();
                $connectionId = (int) $connection->id;
                $organizationId = (int) $organization->id;
                $canonicalPhone = $this->phoneNormalizer->canonicalize(substr($payload['from'], 0, strpos($payload['from'], '@')));
                $inserted = WhatsAppWebhookEvent::query()->insertOrIgnore([
                    'organization_id' => $organization->id,
                    'whatsapp_connection_id' => $connection->id,
                    'provider' => 'wppconnect',
                    'external_event_id' => $eventId,
                    'event_type' => 'inbound_text_message',
                    'payload' => json_encode(['from' => $canonicalPhone, 'type' => 'text', 'body' => $body, 'timestamp' => $payload['t'] ?? $payload['timestamp'] ?? null], JSON_THROW_ON_ERROR),
                    'status' => 'received',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    return 2;
                }

                $client = $this->findClient((int) $organization->id, $canonicalPhone);
                if (! $client) {
                    $client = $organization->clients()->create([
                        'name' => filled($payload['notifyName'] ?? null) ? mb_substr($payload['notifyName'], 0, 255) : $canonicalPhone,
                        'phone_number' => $canonicalPhone,
                        'status' => 'new',
                    ]);
                }
                $conversation = Conversation::query()
                    ->where('organization_id', $organization->id)
                    ->where('whatsapp_connection_id', $connection->id)
                    ->where('client_id', $client->id)
                    ->where('channel', 'whatsapp')->whereNotIn('status', ['RESOLVED', 'ARCHIVED'])
                    ->orderByRaw('COALESCE(last_message_at, created_at) DESC')->orderByDesc('id')
                    ->lockForUpdate()->first();
                if (! $conversation) {
                    $conversation = $organization->conversations()->create([
                        'client_id' => $client->id,
                        'whatsapp_connection_id' => $connection->id,
                        'status' => 'AI_ACTIVE',
                        'channel' => 'whatsapp',
                        'started_at' => now(),
                    ]);
                }
                $timestamp = $payload['t'] ?? $payload['timestamp'] ?? null;
                $sentAt = is_numeric($timestamp) && (int) $timestamp > 0 && (int) $timestamp <= 4102444800
                    ? Carbon::createFromTimestamp((int) $timestamp) : now();
                $conversation->messages()->create([
                    'direction' => 'inbound',
                    'history_generation' => (int) $conversation->history_generation,
                    'sender_type' => 'customer',
                    'message_type' => 'text',
                    'body' => $body,
                    'external_message_id' => $eventId,
                    'metadata' => ['provider' => 'wppconnect', 'remote_id' => $payload['from']],
                    'sent_at' => $sentAt,
                ]);
                $conversation->increment('unread_count');
                if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                    $conversation->forceFill(['last_message_at' => $sentAt])->save();
                }
                WhatsAppWebhookEvent::query()->where('provider', 'wppconnect')->where('external_event_id', $eventId)
                    ->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
                $connection->forceFill(['status' => 'connected', 'last_webhook_at' => now()])->save();

                return 1;
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('WPPConnect webhook processing failed.', [
                'failure_type' => class_basename($exception),
            ]);

            return response()->json(['success' => false, 'message' => 'Webhook processing failed.'], 500);
        }
        if ($outcome === 0) {
            Log::warning('WPPConnect callback rejected: session not mapped.', [
                'event_type' => $eventType,
                'session_ref' => $sessionRef,
                'external_message_id' => $messageId,
                'result' => 'unknown_session',
            ]);

            return response()->json(['success' => false, 'message' => 'WhatsApp connection not found or disabled.'], 404);
        }
        Log::info('WPPConnect callback processed.', [
            'event_type' => $eventType,
            'session_ref' => $sessionRef,
            'connection_id' => $connectionId,
            'organization_id' => $organizationId,
            'external_message_id' => $messageId,
            'result' => $outcome === 1 ? 'processed' : 'duplicate',
        ]);

        if ($outcome === 1) {
            $inboundMessage = Message::query()->where('external_message_id', $eventId)->first();
            if ($inboundMessage) {
                try {
                    $this->aiMessageProcessor->process($inboundMessage);
                } catch (Throwable $exception) {
                    Log::warning('WPPConnect AI processing failed.', [
                        'failure_type' => class_basename($exception),
                        'message_id' => $inboundMessage->id,
                        'connection_id' => $connectionId,
                        'organization_id' => $organizationId,
                    ]);
                }
            }
        }

        return response()->json(['success' => true, 'processed' => $outcome === 1 ? 1 : 0, 'duplicates' => $outcome === 2 ? 1 : 0], 200);
    }

    public function verify(Request $request, string $provider): JsonResponse|Response
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:255'],
            'verify_token' => ['required', 'string', 'max:4096'],
            'challenge' => ['required', 'string', 'max:4096'],
        ]);
        $verified = DB::transaction(function () use ($provider, $data): bool {
            $connection = WhatsAppConnection::query()
                ->where('provider', $provider)->where('phone_number_id', $data['phone_number_id'])
                ->where('enabled', true)->lockForUpdate()->first();
            $credentials = $connection?->credentials;
            $expected = is_array($credentials) ? ($credentials['webhook_verify_token'] ?? null) : null;
            if (! $connection || ! is_string($expected) || ! hash_equals($expected, $data['verify_token'])) {
                return false;
            }

            $connection->forceFill(['status' => 'connected', 'last_connected_at' => now()])->save();

            return true;
        });
        if (! $verified) {
            return response()->json(['success' => false, 'message' => 'Webhook verification failed.'], 401);
        }

        return response($data['challenge'], 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request, string $provider): JsonResponse
    {
        $rawBody = $request->getContent();
        if (strlen($rawBody) > 1_048_576) {
            return response()->json(['success' => false, 'message' => 'Webhook payload is too large.'], 413);
        }
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.'], 422);
        }

        $validator = Validator::make($payload, [
            'phone_number_id' => ['required', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.id' => ['required', 'string', 'max:255'],
            'messages.*.from' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,30}$/'],
            'messages.*.type' => ['required', 'in:text'],
            'messages.*.text.body' => ['required', 'string', 'max:50000'],
            'messages.*.profile_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'messages.*.timestamp' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4102444800'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload.', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $connection = $this->connection($provider, $data['phone_number_id']);
        if (! $connection) {
            return response()->json(['success' => false, 'message' => 'WhatsApp connection not found or disabled.'], 404);
        }
        $credentials = $connection->credentials;
        $secret = is_array($credentials) ? ($credentials['webhook_secret'] ?? null) : null;
        $signature = $request->header('X-Wabiz-Signature');
        if (! $this->signatureVerifier->verify($rawBody, $signature, is_string($secret) ? $secret : null)) {
            return response()->json(['success' => false, 'message' => 'Webhook signature is invalid.'], 401);
        }

        try {
            $processed = 0;
            $duplicates = 0;
            foreach ($data['messages'] as $messageData) {
                $inboundMessage = $this->processMessage($connection->id, $provider, $data['phone_number_id'], $rawBody, $signature, $messageData);
                if ($inboundMessage === null) {
                    $duplicates++;

                    continue;
                }

                $processed++;
                $this->aiMessageProcessor->process($inboundMessage);
            }
        } catch (WebhookSignatureChanged) {
            return response()->json(['success' => false, 'message' => 'Webhook signature is invalid.'], 401);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['success' => true, 'processed' => $processed, 'duplicates' => $duplicates], 200);
    }

    /** @param array<string, mixed> $messageData */
    private function processMessage(int $connectionId, string $provider, string $phoneNumberId, string $rawBody, ?string $signature, array $messageData): ?Message
    {
        try {
            return DB::transaction(function () use ($connectionId, $provider, $phoneNumberId, $rawBody, $signature, $messageData): ?Message {
                $connection = WhatsAppConnection::query()->whereKey($connectionId)
                    ->where('provider', $provider)->where('phone_number_id', $phoneNumberId)
                    ->where('enabled', true)->lockForUpdate()->firstOrFail();
                $organization = $connection->organization()->lockForUpdate()->firstOrFail();
                $credentials = $connection->credentials;
                $secret = is_array($credentials) ? ($credentials['webhook_secret'] ?? null) : null;
                if (! $this->signatureVerifier->verify($rawBody, $signature, is_string($secret) ? $secret : null)) {
                    throw new WebhookSignatureChanged;
                }

                $normalizedPhone = $this->phoneNormalizer->canonicalize($messageData['from']);
                $safePayload = [
                    'from' => $normalizedPhone,
                    'type' => 'text',
                    'text' => ['body' => $messageData['text']['body']],
                    'profile_name' => $messageData['profile_name'] ?? null,
                    'timestamp' => $messageData['timestamp'] ?? null,
                ];
                $event = WhatsAppWebhookEvent::query()->insertOrIgnore([
                    'organization_id' => $organization->id,
                    'whatsapp_connection_id' => $connection->id,
                    'provider' => $provider,
                    'external_event_id' => $messageData['id'],
                    'event_type' => 'inbound_text_message',
                    'payload' => json_encode($safePayload, JSON_THROW_ON_ERROR),
                    'status' => 'received',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $event = WhatsAppWebhookEvent::query()
                    ->where('provider', $provider)->where('external_event_id', $messageData['id'])
                    ->lockForUpdate()->firstOrFail();
                if ($event->status === 'processed') {
                    return null;
                }
                $event->fill(['organization_id' => $organization->id, 'whatsapp_connection_id' => $connection->id, 'event_type' => 'inbound_text_message', 'payload' => $safePayload, 'status' => 'received', 'error_message' => null, 'processed_at' => null])->save();

                $client = $this->findClient($organization->id, $normalizedPhone);
                if (! $client) {
                    $client = $organization->clients()->create([
                        'name' => filled($messageData['profile_name'] ?? null) ? $messageData['profile_name'] : $normalizedPhone,
                        'phone_number' => $normalizedPhone,
                        'status' => 'new',
                    ]);
                }

                $conversation = Conversation::query()
                    ->where('organization_id', $organization->id)
                    ->where(function ($query) use ($connection, $organization): void {
                        $query->where('whatsapp_connection_id', $connection->id);
                        if ($organization->whatsappConnections()->count() === 1) {
                            $query->orWhereNull('whatsapp_connection_id');
                        }
                    })
                    ->where('client_id', $client->id)
                    ->where('channel', 'whatsapp')
                    ->whereNotIn('status', ['RESOLVED', 'ARCHIVED'])
                    ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
                    ->orderByDesc('id')->lockForUpdate()->first();
                if ($conversation && $conversation->whatsapp_connection_id === null) {
                    $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
                }
                if (! $conversation) {
                    $conversation = $organization->conversations()->create([
                        'client_id' => $client->id,
                        'whatsapp_connection_id' => $connection->id,
                        'status' => 'AI_ACTIVE',
                        'channel' => 'whatsapp',
                        'started_at' => now(),
                    ]);
                }

                $messageTimestamp = isset($messageData['timestamp'])
                    ? Carbon::createFromTimestamp((int) $messageData['timestamp'])
                    : now();
                $inboundMessage = $conversation->messages()->where('external_message_id', $messageData['id'])->first();
                if (! $inboundMessage) {
                    $inboundMessage = $conversation->messages()->create([
                        'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text',
                        'history_generation' => (int) $conversation->history_generation,
                        'body' => $messageData['text']['body'], 'external_message_id' => $messageData['id'],
                        'metadata' => ['provider' => $provider], 'sent_at' => $messageTimestamp,
                    ]);
                    $conversation->increment('unread_count');
                }
                if (! $conversation->last_message_at || $messageTimestamp->greaterThan($conversation->last_message_at)) {
                    $conversation->last_message_at = $messageTimestamp;
                    $conversation->save();
                }
                $event->fill(['status' => 'processed', 'processed_at' => now()])->save();
                $connection->fill(['status' => 'connected', 'last_webhook_at' => now()])->save();

                return $inboundMessage;
            }, 3);
        } catch (WebhookSignatureChanged $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($connectionId, $provider, (string) $messageData['id'], $messageData);
            throw $exception;
        }
    }

    private function findClient(int $organizationId, string $canonicalPhone): ?Client
    {
        $forms = $this->phoneNormalizer->matchingDigitForms($canonicalPhone);
        $client = Client::query()->where('organization_id', $organizationId)
            ->whereIn(DB::raw("regexp_replace(phone_number, '[^0-9]', '', 'g')"), $forms)
            ->orderBy('id')->first();

        return $client;
    }

    private function connection(string $provider, string $phoneNumberId): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()
            ->where('provider', $provider)->where('phone_number_id', $phoneNumberId)->where('enabled', true)->first();
    }

    /** @param array<string, mixed> $messageData */
    private function recordFailure(int $connectionId, string $provider, string $eventId, array $messageData): void
    {
        try {
            $connection = WhatsAppConnection::query()->whereKey($connectionId)->first();
            $safePayload = [
                'from' => $this->phoneNormalizer->canonicalize((string) ($messageData['from'] ?? '')),
                'type' => 'text', 'text' => ['body' => (string) ($messageData['text']['body'] ?? '')],
                'profile_name' => $messageData['profile_name'] ?? null,
                'timestamp' => $messageData['timestamp'] ?? null,
            ];
            WhatsAppWebhookEvent::query()->insertOrIgnore([[
                'organization_id' => $connection?->organization_id,
                'whatsapp_connection_id' => $connection?->id,
                'provider' => $provider, 'external_event_id' => $eventId,
                'event_type' => 'inbound_text_message', 'payload' => json_encode($safePayload, JSON_THROW_ON_ERROR),
                'status' => 'failed', 'error_message' => 'Inbound webhook processing failed.',
                'created_at' => now(), 'updated_at' => now(),
            ]]);
            WhatsAppWebhookEvent::query()->where('provider', $provider)->where('external_event_id', $eventId)
                ->where('status', '!=', 'processed')
                ->update(['organization_id' => $connection?->organization_id, 'whatsapp_connection_id' => $connection?->id,
                    'payload' => json_encode($safePayload, JSON_THROW_ON_ERROR), 'status' => 'failed',
                    'error_message' => 'Inbound webhook processing failed.', 'updated_at' => now()]);
        } catch (Throwable) {
            // Preserve the original failure and do not expose exception details.
        }
    }
}

class WebhookSignatureChanged extends \RuntimeException {}
