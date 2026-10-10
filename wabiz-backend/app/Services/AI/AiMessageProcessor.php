<?php

namespace App\Services\AI;

use App\Contracts\AiOrchestrator;
use App\DataTransferObjects\AI\AiDecision;
use App\DataTransferObjects\AI\AiMessageProcessingResult;
use App\DataTransferObjects\AI\AiMessageProcessingStatus;
use App\DataTransferObjects\AI\AiProviderResponse;
use App\DataTransferObjects\AI\ConversationHandoffStatus;
use App\Jobs\SendAiConversationMessage;
use App\Models\AiAssistantSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiMessageProcessor
{
    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly AiContextBuilder $contextBuilder,
        private readonly RegistrationIntentClassifier $intentClassifier,
        private readonly AiResponseSafetyPolicy $responseSafetyPolicy,
        private readonly ConversationHandoffService $handoffService,
    ) {}

    public function process(Message $inboundMessage): AiMessageProcessingResult
    {
        if (! $inboundMessage->exists || ! $inboundMessage->getKey()) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'message_not_persisted');
        }

        $message = Message::query()->find($inboundMessage->getKey());
        if (! $message) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'message_not_found');
        }
        if ($message->direction !== 'inbound' || $message->sender_type !== 'customer') {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'message_not_customer_inbound', message: $message);
        }

        $conversation = Conversation::query()->find($message->conversation_id);
        if (! $conversation) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'conversation_not_found', message: $message);
        }
        $organization = Organization::query()->find($conversation->organization_id);
        if (! $organization || ! $organization->clients()
            ->whereKey($conversation->client_id)->exists()) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'organization_scope_invalid', $conversation, $message);
        }

        $eligibility = $this->eligibilityReason($conversation);
        if ($eligibility !== null) {
            return $this->result(AiMessageProcessingStatus::IGNORED, $eligibility, $conversation, $message, $organization);
        }

        if (Message::query()->where('ai_source_message_id', $message->id)->exists()) {
            return $this->result(AiMessageProcessingStatus::ALREADY_PROCESSED, 'ai_response_already_exists', $conversation, $message, $organization);
        }

        $setting = AiAssistantSetting::query()->where('organization_id', $organization->id)->first();
        if (! $setting) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'settings_not_configured', $conversation, $message, $organization);
        }
        if (! $setting->enabled) {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'assistant_disabled', $conversation, $message, $organization);
        }

        try {
            $intent = $this->intentClassifier->classify($message);
            $decision = $this->orchestrator->decide($organization, $conversation, $message);
        } catch (Throwable $exception) {
            Log::warning('AI decision pipeline failed.', [
                'failure_type' => class_basename($exception),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'organization_id' => $organization->id,
            ]);

            return $this->result(AiMessageProcessingStatus::FAILED, 'decision_pipeline_failed', $conversation, $message, $organization);
        }

        if ($decision->responseAction === 'IGNORE') {
            return $this->result(AiMessageProcessingStatus::IGNORED, $decision->reason, $conversation, $message, $organization, $decision);
        }
        $requiresHandoff = $decision->handoffRequired || $decision->responseAction === 'HANDOFF';
        if ($requiresHandoff) {
            // Handoff is an internal assignment/state change; it does not create a customer-facing message.
            return $this->executeHandoff($conversation, $message, $organization, $decision, null, 'handoff_required');
        }
        if (! in_array($decision->responseAction, ['RESPOND', 'HANDOFF'], true)) {
            return $this->result(AiMessageProcessingStatus::FAILED, 'unsupported_decision_action', $conversation, $message, $organization, $decision);
        }

        $responseText = is_string($decision->responseText) ? trim($decision->responseText) : '';
        if ($responseText === '') {
            return $this->result(AiMessageProcessingStatus::IGNORED, 'empty_response', $conversation, $message, $organization, $decision);
        }

        $providerResponse = new AiProviderResponse(
            responseText: $responseText,
            confidence: $decision->confidence,
            metadata: [],
        );
        try {
            $context = $this->contextBuilder->build($organization, $conversation, $message, $intent);
        } catch (Throwable) {
            return $this->result(AiMessageProcessingStatus::FAILED, 'safety_context_unavailable', $conversation, $message, $organization, $decision);
        }
        if (! $this->responseSafetyPolicy->allows($context, $providerResponse)) {
            return $this->result(AiMessageProcessingStatus::FAILED, 'response_failed_safety_validation', $conversation, $message, $organization, $decision);
        }

        try {
            $result = DB::transaction(function () use ($conversation, $message, $organization, $responseText, $decision, $intent, $context): AiMessageProcessingResult {
                $lockedConversation = Conversation::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($conversation->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedConversation || $lockedConversation->status !== 'AI_ACTIVE') {
                    return $this->result(AiMessageProcessingStatus::IGNORED, 'conversation_not_ai_active', $conversation, $message, $organization, $decision);
                }
                $lockedMessage = Message::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->whereKey($message->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedMessage || $lockedMessage->direction !== 'inbound' || $lockedMessage->sender_type !== 'customer') {
                    return $this->result(AiMessageProcessingStatus::IGNORED, 'message_no_longer_eligible', $lockedConversation, $message, $organization, $decision);
                }
                if ((int) $lockedMessage->history_generation !== (int) $lockedConversation->history_generation) {
                    return $this->result(AiMessageProcessingStatus::IGNORED, 'message_before_history_reset', $lockedConversation, $message, $organization, $decision);
                }

                if (Message::query()->where('ai_source_message_id', $lockedMessage->id)->exists()) {
                    return $this->result(AiMessageProcessingStatus::ALREADY_PROCESSED, 'ai_response_already_exists', $lockedConversation, $message, $organization, $decision);
                }

                $lockedSetting = AiAssistantSetting::query()
                    ->where('organization_id', $organization->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedSetting || ! $lockedSetting->enabled) {
                    return $this->result(AiMessageProcessingStatus::IGNORED, 'assistant_disabled_during_processing', $lockedConversation, $message, $organization, $decision);
                }
                if ($lockedSetting->only(array_keys($context->settings)) !== $context->settings) {
                    return $this->result(AiMessageProcessingStatus::FAILED, 'settings_changed_during_processing', $lockedConversation, $message, $organization, $decision);
                }

                $createdAt = now();
                $outbound = $lockedConversation->messages()->make([
                    'direction' => 'outbound',
                    'history_generation' => (int) $lockedConversation->history_generation,
                    'sender_type' => 'ai',
                    'message_type' => 'text',
                    'body' => $responseText,
                    'external_message_id' => null,
                    'delivery_status' => 'pending',
                    'metadata' => [
                        'provider' => $this->safeProviderName($decision),
                        'intent' => in_array($intent, ['REGISTRATION', 'GENERAL', 'UNKNOWN'], true) ? $intent : 'UNKNOWN',
                        'confidence' => $this->safeConfidence($decision->confidence),
                        'processing_source' => 'ai_message_processor',
                    ],
                    'sent_at' => null,
                ]);
                $outbound->forceFill(['ai_source_message_id' => $lockedMessage->id]);
                $outbound->setCreatedAt($createdAt);
                $outbound->setUpdatedAt($createdAt);
                $lockedConversation->messages()->save($outbound);

                if (! $lockedConversation->last_message_at || $createdAt->greaterThan($lockedConversation->last_message_at)) {
                    $lockedConversation->forceFill(['last_message_at' => $createdAt])->save();
                }

                return $this->result(AiMessageProcessingStatus::PROCESSED, 'ai_response_stored', $lockedConversation, $message, $organization, $decision, (int) $outbound->id);
            }, 3);
        } catch (QueryException $exception) {
            Log::warning('AI outbound persistence failed.', [
                'failure_type' => class_basename($exception),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'organization_id' => $organization->id,
            ]);
            if (Message::query()->where('ai_source_message_id', $message->id)->exists()) {
                return $this->result(AiMessageProcessingStatus::ALREADY_PROCESSED, 'ai_response_already_exists', $conversation, $message, $organization, $decision);
            }

            return $this->result(AiMessageProcessingStatus::FAILED, 'outbound_message_persistence_failed', $conversation, $message, $organization, $decision);
        } catch (Throwable $exception) {
            Log::warning('AI outbound persistence failed.', [
                'failure_type' => class_basename($exception),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'organization_id' => $organization->id,
            ]);

            return $this->result(AiMessageProcessingStatus::FAILED, 'outbound_message_persistence_failed', $conversation, $message, $organization, $decision);
        }

        if ($result->status === AiMessageProcessingStatus::PROCESSED && $result->outboundMessageId !== null) {
            try {
                SendAiConversationMessage::dispatch($result->outboundMessageId)->afterCommit();
            } catch (Throwable $exception) {
                $pendingMessage = Message::query()->whereKey($result->outboundMessageId)
                    ->where('delivery_status', 'pending')->first();
                if ($pendingMessage) {
                    $metadata = is_array($pendingMessage->metadata) ? $pendingMessage->metadata : [];
                    $metadata['delivery_error'] = 'queue_dispatch_outcome_unknown';
                    $pendingMessage->forceFill(['delivery_status' => 'unknown', 'metadata' => $metadata])->save();
                }
                Log::warning('AI WhatsApp delivery job could not be confirmed.', [
                    'failure_type' => class_basename($exception),
                    'message_id' => $result->outboundMessageId,
                    'conversation_id' => $conversation->id,
                    'organization_id' => $organization->id,
                    'result' => 'unknown',
                ]);
            }
        }

        return $result;
    }

    private function executeHandoff(
        Conversation $conversation,
        Message $message,
        Organization $organization,
        AiDecision $decision,
        ?int $outboundMessageId,
        string $fallbackReason,
    ): AiMessageProcessingResult {
        try {
            $handoff = $this->handoffService->execute($conversation, $decision);
        } catch (Throwable $exception) {
            Log::warning('Conversation handoff execution failed.', [
                'failure_type' => class_basename($exception),
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'organization_id' => $organization->id,
            ]);

            return $this->result(
                AiMessageProcessingStatus::FAILED,
                'handoff_execution_failed',
                $conversation,
                $message,
                $organization,
                $decision,
                $outboundMessageId,
            );
        }
        if (in_array($handoff->status, [
            ConversationHandoffStatus::TRANSFERRED,
            ConversationHandoffStatus::ALREADY_HANDOFF,
            ConversationHandoffStatus::HANDOFF_UNASSIGNED,
        ], true)) {
            return $this->result(
                AiMessageProcessingStatus::HANDOFF_REQUIRED,
                $handoff->reason === 'handoff_transferred' ? $fallbackReason : $handoff->reason,
                $conversation->fresh(),
                $message,
                $organization,
                $decision,
                $outboundMessageId,
            );
        }

        return $this->result(
            AiMessageProcessingStatus::FAILED,
            $handoff->reason,
            $conversation,
            $message,
            $organization,
            $decision,
            $outboundMessageId,
        );
    }

    private function eligibilityReason(Conversation $conversation): ?string
    {
        return match ($conversation->status) {
            'AI_ACTIVE' => null,
            'ARCHIVED' => 'conversation_archived',
            'HUMAN_ACTIVE' => 'human_is_active',
            'RESOLVED' => 'conversation_resolved',
            'WAITING_HUMAN' => 'waiting_for_human',
            'TRANSFERRED_TO_HUMAN' => 'transferred_to_human',
            default => 'conversation_status_not_eligible',
        };
    }

    private function safeProviderName(AiDecision $decision): string
    {
        $provider = $decision->metadata['provider'] ?? null;

        return in_array($provider, ['gemini', 'deterministic'], true) ? $provider : 'unknown';
    }

    private function safeConfidence(?float $confidence): ?float
    {
        return $confidence !== null && is_finite($confidence) && $confidence >= 0 && $confidence <= 1
            ? $confidence
            : null;
    }

    private function result(
        AiMessageProcessingStatus $status,
        string $reason,
        ?Conversation $conversation = null,
        ?Message $message = null,
        ?Organization $organization = null,
        ?AiDecision $decision = null,
        ?int $outboundMessageId = null,
    ): AiMessageProcessingResult {
        Log::info('AI message processing finished.', array_filter([
            'message_id' => $message?->id,
            'conversation_id' => $conversation?->id,
            'organization_id' => $organization?->id,
            'intent' => $decision?->intent,
            'action' => $decision?->responseAction,
            'provider' => $decision ? $this->safeProviderName($decision) : null,
            'result' => $status->value,
            'reason' => $reason,
            'outbound_message_id' => $outboundMessageId,
        ], fn (mixed $value): bool => $value !== null));

        return new AiMessageProcessingResult($status, $reason, $decision, $outboundMessageId);
    }
}
