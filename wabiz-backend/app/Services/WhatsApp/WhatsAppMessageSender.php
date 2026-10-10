<?php

namespace App\Services\WhatsApp;

use App\DataTransferObjects\WhatsApp\WhatsAppOutboundMessageResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\ConversationReplyAuthorizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppMessageSender
{
    public function __construct(
        private readonly WhatsAppProviderRegistry $providers,
        private readonly IndonesianPhoneNumberNormalizer $phoneNormalizer,
        private readonly ConversationReplyAuthorizer $replyAuthorizer,
    ) {}

    public function sendText(User $actor, int $organizationId, int $conversationId, string $idempotencyKey, string $text): WhatsAppOutboundMessageResult
    {
        if (trim($text) === '' || mb_strlen($text) > 4096) {
            throw new WhatsAppOutboundException('invalid_message_request', 422);
        }

        $prepared = DB::transaction(function () use ($actor, $organizationId, $conversationId, $idempotencyKey, $text): array {
            $conversation = Conversation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();
            if (! $conversation) {
                throw new WhatsAppOutboundException('conversation_not_found', 404);
            }
            if (! $this->replyAuthorizer->canReply($actor, $conversation)) {
                throw new WhatsAppOutboundException('conversation_reply_forbidden', 403);
            }
            if ($conversation->channel !== 'whatsapp') {
                throw new WhatsAppOutboundException('conversation_channel_unsupported', 422);
            }
            if (! in_array($conversation->status, ['AI_ACTIVE', 'WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE'], true)) {
                throw new WhatsAppOutboundException('conversation_not_replyable', 409);
            }
            // A human send is an explicit takeover. Persist this before the provider call
            // so an inbound event cannot race and trigger an automatic AI reply.
            if ($conversation->status !== 'HUMAN_ACTIVE') {
                $conversation->forceFill(['status' => 'HUMAN_ACTIVE'])->save();
            }

            $client = Client::query()
                ->where('organization_id', $organizationId)
                ->whereKey($conversation->client_id)
                ->first();
            if (! $client || (int) $client->organization_id !== $organizationId) {
                throw new WhatsAppOutboundException('client_not_found', 404);
            }
            if (! is_string($client->phone_number) || trim($client->phone_number) === '') {
                throw new WhatsAppOutboundException('client_phone_unavailable', 422);
            }
            $recipientPhone = $this->phoneNormalizer->canonicalize($client->phone_number);
            if (! str_starts_with($recipientPhone, '+') || preg_match('/^\+[1-9][0-9]{6,19}$/', $recipientPhone) !== 1) {
                throw new WhatsAppOutboundException('client_phone_invalid', 422);
            }

            $existing = $conversation->messages()
                ->where('outbound_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->body !== $text || $existing->sender_type !== 'human') {
                    throw new WhatsAppOutboundException('idempotency_key_conflict', 409);
                }
                if ($existing->delivery_status === 'sent' && filled($existing->external_message_id)) {
                    return ['message' => $existing, 'provider' => null, 'connection' => null, 'client' => null, 'replayed' => true];
                }

                throw new WhatsAppOutboundException('delivery_already_attempted', 409);
            }

            $connection = $conversation->whatsapp_connection_id
                ? WhatsAppConnection::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($conversation->whatsapp_connection_id)
                    ->first()
                : WhatsAppConnection::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_primary', true)
                    ->first();
            if (! $connection || ! $connection->enabled || $connection->status !== 'connected') {
                throw new WhatsAppOutboundException('connection_inactive', 422);
            }

            try {
                $provider = $this->providers->forConnection($connection, $organizationId);
            } catch (WhatsAppProviderException $exception) {
                throw new WhatsAppOutboundException($exception->failureKind, $this->httpStatus($exception));
            }

            $message = $conversation->messages()->make([
                'direction' => 'outbound',
                'history_generation' => (int) $conversation->history_generation,
                'sender_type' => 'human',
                'message_type' => 'text',
                'body' => $text,
                'metadata' => ['provider' => $provider->identifier()],
            ]);
            $message->forceFill([
                'outbound_idempotency_key' => $idempotencyKey,
                'delivery_status' => 'pending',
                'external_message_id' => null,
                'sent_at' => null,
            ])->save();

            return [
                'message' => $message,
                'provider' => $provider,
                'connection' => $connection,
                'recipient_phone' => $recipientPhone,
                'replayed' => false,
            ];
        });

        if ($prepared['replayed']) {
            return new WhatsAppOutboundMessageResult($prepared['message'], true);
        }

        try {
            $providerResult = $prepared['provider']->sendText(
                $prepared['connection'],
                new WhatsAppSendTextRequest($prepared['recipient_phone'], $text),
            );
            if (! $providerResult->success || ! is_string($providerResult->externalMessageId)
                || trim($providerResult->externalMessageId) === '') {
                throw new WhatsAppProviderException('unexpected_provider_response');
            }
        } catch (WhatsAppProviderException $exception) {
            $deliveryStatus = $this->isAmbiguous($exception) ? 'unknown' : 'failed';
            $this->updateDeliveryState((int) $prepared['message']->id, $deliveryStatus);
            throw new WhatsAppOutboundException($exception->failureKind, $this->httpStatus($exception));
        } catch (Throwable) {
            $this->updateDeliveryState((int) $prepared['message']->id, 'unknown');
            throw new WhatsAppOutboundException('delivery_state_unconfirmed', 502);
        }

        try {
            $sentAt = now();
            $message = DB::transaction(function () use ($prepared, $providerResult, $sentAt): Message {
                $message = Message::query()->whereKey($prepared['message']->id)->lockForUpdate()->firstOrFail();
                $message->forceFill([
                    'external_message_id' => $providerResult->externalMessageId,
                    'delivery_status' => 'sent',
                    'sent_at' => $sentAt,
                ])->save();

                Conversation::query()
                    ->whereKey($message->conversation_id)
                    ->where('organization_id', $prepared['message']->conversation->organization_id)
                    ->where(fn ($query) => $query->whereNull('last_message_at')->orWhere('last_message_at', '<', $sentAt))
                    ->update(['last_message_at' => $sentAt, 'updated_at' => now()]);

                return $message->refresh();
            });
        } catch (Throwable) {
            // Meta may have accepted the send; the persisted pending state prevents an unsafe resend.
            throw new WhatsAppOutboundException('delivery_state_unconfirmed', 503);
        }

        return new WhatsAppOutboundMessageResult($message);
    }

    /** Deliver an already-persisted AI response without accepting tenant or recipient data from the job payload. */
    public function sendAiMessage(int $messageId): void
    {
        $claimed = DB::transaction(function () use ($messageId): bool {
            $candidate = Message::query()->find($messageId);
            if (! $candidate) {
                return false;
            }

            $conversation = Conversation::query()->whereKey($candidate->conversation_id)->lockForUpdate()->first();
            $message = $conversation
                ? Message::query()->where('conversation_id', $conversation->id)->whereKey($messageId)->lockForUpdate()->first()
                : null;
            if (! $conversation || ! $message || $message->direction !== 'outbound'
                || $message->sender_type !== 'ai' || $message->delivery_status !== 'pending') {
                return false;
            }

            $reason = $this->aiSuppressionReason($conversation, $message);
            if ($reason !== null) {
                $this->setAiDeliveryState($message, 'failed', $reason, 'suppressed');

                return false;
            }

            $message->forceFill(['delivery_status' => 'sending'])->save();

            return true;
        }, 1);

        if (! $claimed) {
            return;
        }
        try {
            DB::transaction(function () use ($messageId): void {
                $candidate = Message::query()->find($messageId);
                if (! $candidate) {
                    return;
                }

                $initialConversation = Conversation::query()->whereKey($candidate->conversation_id)->first();
                if (! $initialConversation) {
                    $this->setAiDeliveryState($candidate, 'failed', 'conversation_not_found', 'suppressed');

                    return;
                }

                // Lock the connection before the conversation, matching inbound webhook lock order.
                $connection = $initialConversation->whatsapp_connection_id
                    ? WhatsAppConnection::query()
                        ->where('organization_id', $initialConversation->organization_id)
                        ->whereKey($initialConversation->whatsapp_connection_id)
                        ->lockForUpdate()
                        ->first()
                    : null;
                $conversation = Conversation::query()
                    ->where('organization_id', $initialConversation->organization_id)
                    ->whereKey($initialConversation->id)
                    ->lockForUpdate()
                    ->first();
                $message = $conversation
                    ? Message::query()->where('conversation_id', $conversation->id)->whereKey($messageId)->lockForUpdate()->first()
                    : null;
                if (! $conversation || ! $message || $message->delivery_status !== 'sending') {
                    return;
                }

                $reason = $this->aiSuppressionReason($conversation, $message);
                if ($reason !== null) {
                    $this->setAiDeliveryState($message, 'failed', $reason, 'suppressed');

                    return;
                }

                $organizationId = (int) $conversation->organization_id;
                if (! $connection || (int) $connection->organization_id !== $organizationId
                    || ! $connection->enabled || $connection->status !== 'connected'
                    || (int) $conversation->whatsapp_connection_id !== (int) $connection->id) {
                    $this->setAiDeliveryState($message, 'failed', 'connection_unavailable');

                    return;
                }

                $client = Client::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($conversation->client_id)
                    ->lockForUpdate()
                    ->first();
                if (! $client || ! is_string($client->phone_number) || trim($client->phone_number) === '') {
                    $this->setAiDeliveryState($message, 'failed', 'client_phone_unavailable');

                    return;
                }

                try {
                    $recipientPhone = $this->phoneNormalizer->canonicalize($client->phone_number);
                } catch (Throwable) {
                    $this->setAiDeliveryState($message, 'failed', 'client_phone_invalid');

                    return;
                }
                if (preg_match('/^\+[1-9][0-9]{6,19}$/', $recipientPhone) !== 1) {
                    $this->setAiDeliveryState($message, 'failed', 'client_phone_invalid');

                    return;
                }

                try {
                    $provider = $this->providers->forConnection($connection, $organizationId);
                } catch (WhatsAppProviderException $exception) {
                    $this->setAiDeliveryState($message, 'failed', $exception->failureKind);

                    return;
                }

                try {
                    $providerResult = $provider->sendText(
                        $connection,
                        new WhatsAppSendTextRequest($recipientPhone, (string) $message->body),
                    );
                    if (! $providerResult->success || ! is_string($providerResult->externalMessageId)
                        || trim($providerResult->externalMessageId) === '') {
                        throw new WhatsAppProviderException('unexpected_provider_response');
                    }
                } catch (Throwable $exception) {
                    $failureKind = $exception instanceof WhatsAppProviderException
                        ? $exception->failureKind
                        : 'delivery_outcome_unknown';
                    $ambiguous = ! $exception instanceof WhatsAppProviderException || $this->isAmbiguous($exception);
                    $this->setAiDeliveryState(
                        $message,
                        $ambiguous ? 'unknown' : 'failed',
                        $ambiguous ? 'delivery_outcome_unknown' : $failureKind,
                    );
                    Log::warning('AI WhatsApp delivery failed.', [
                        'failure_type' => class_basename($exception),
                        'message_id' => $message->id,
                        'conversation_id' => $conversation->id,
                        'organization_id' => $organizationId,
                        'result' => $ambiguous ? 'unknown' : 'failed',
                    ]);

                    return;
                }

                $sentAt = now();
                $message->forceFill([
                    'delivery_status' => 'sent',
                    'external_message_id' => $providerResult->externalMessageId,
                    'sent_at' => $sentAt,
                    'metadata' => [
                        ...(is_array($message->metadata) ? $message->metadata : []),
                        'delivery_provider' => $connection->provider,
                    ],
                ])->save();
                if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                    $conversation->forceFill(['last_message_at' => $sentAt])->save();
                }
            }, 1);
        } catch (Throwable $exception) {
            // A provider may have accepted the message before a database commit failure.
            try {
                $message = Message::query()->whereKey($messageId)->where('delivery_status', 'sending')->first();
                if ($message) {
                    $this->setAiDeliveryState($message, 'unknown', 'delivery_outcome_unknown');
                }
            } catch (Throwable) {
                // Keep the durable sending marker; never automatically retry an uncertain delivery.
            }
            Log::warning('AI WhatsApp delivery state could not be confirmed.', [
                'failure_type' => class_basename($exception),
                'message_id' => $messageId,
                'result' => 'unknown',
            ]);
        }
    }

    private function aiSuppressionReason(Conversation $conversation, Message $message): ?string
    {
        if ($message->direction !== 'outbound' || $message->sender_type !== 'ai' || $message->ai_source_message_id === null) {
            return 'invalid_ai_message';
        }
        if ($conversation->channel !== 'whatsapp') {
            return 'unsupported_channel';
        }
        if ($conversation->status !== 'AI_ACTIVE') {
            return 'conversation_not_ai_active';
        }
        if ((int) $message->history_generation !== (int) $conversation->history_generation) {
            return 'history_generation_changed';
        }

        return Message::query()
            ->whereKey($message->ai_source_message_id)
            ->where('conversation_id', $conversation->id)
            ->where('history_generation', (int) $conversation->history_generation)
            ->where('direction', 'inbound')
            ->where('sender_type', 'customer')
            ->exists() ? null : 'source_message_not_active';
    }

    private function setAiDeliveryState(Message $message, string $status, string $reason, ?string $deliveryState = null): void
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if ($deliveryState !== null) {
            $metadata['delivery_state'] = $deliveryState;
            $metadata['suppression_reason'] = $reason;
        } else {
            $metadata['delivery_error'] = $reason;
        }
        $message->forceFill(['delivery_status' => $status, 'metadata' => $metadata])->save();
    }

    private function isAmbiguous(WhatsAppProviderException $exception): bool
    {
        return in_array($exception->failureKind, [
            'transport_error', 'provider_unavailable', 'malformed_provider_response',
            'unexpected_provider_response', 'delivery_state_unconfirmed',
        ], true);
    }

    private function updateDeliveryState(int $messageId, string $status): void
    {
        try {
            Message::query()->whereKey($messageId)->where('delivery_status', 'pending')
                ->update(['delivery_status' => $status, 'updated_at' => now()]);
        } catch (QueryException) {
            // Keeping a pending state is safer than retrying a possibly accepted Meta request.
        }
    }

    private function httpStatus(WhatsAppProviderException $exception): int
    {
        return match ($exception->failureKind) {
            'authentication_error' => 401,
            'permission_denied' => 403,
            'phone_number_not_found', 'not_found' => 404,
            'provider_conflict' => 409,
            'rate_limited' => 429,
            'provider_unavailable', 'transport_error', 'malformed_provider_response', 'unexpected_provider_response' => 502,
            default => 422,
        };
    }
}
