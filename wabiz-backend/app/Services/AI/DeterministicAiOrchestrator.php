<?php

namespace App\Services\AI;

use App\Contracts\AiOrchestrator;
use App\Contracts\AiProviderInterface;
use App\DataTransferObjects\AI\AiContext;
use App\DataTransferObjects\AI\AiDecision;
use App\DataTransferObjects\AI\AiProviderResponse;
use App\Models\AiAssistantSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DeterministicAiOrchestrator implements AiOrchestrator
{
    public function __construct(
        private readonly RegistrationIntentClassifier $classifier,
        private readonly AiContextBuilder $contextBuilder,
        private readonly AiProviderInterface $provider,
        private readonly RegistrationHandoffResolver $handoffResolver,
        private readonly AiResponseSafetyPolicy $responseSafetyPolicy,
        private readonly DeterministicAiProvider $fallbackProvider,
    ) {}

    public function decide(Organization $organization, Conversation $conversation, Message $incomingMessage): AiDecision
    {
        if ((int) $conversation->organization_id !== (int) $organization->id
            || (int) $incomingMessage->conversation_id !== (int) $conversation->id
            || $incomingMessage->direction !== 'inbound') {
            throw new InvalidArgumentException('AI decisions require organization-scoped conversation messages.');
        }

        $intent = $this->classifier->classify($incomingMessage);
        $context = $this->contextBuilder->build($organization, $conversation, $incomingMessage, $intent);
        $setting = AiAssistantSetting::query()->where('organization_id', $organization->id)->first();

        if (! $setting || ! $setting->enabled) {
            return new AiDecision(
                intent: $intent,
                responseAction: 'IGNORE',
                responseText: null,
                handoffRequired: false,
                handoffDepartmentId: null,
                handoffCsMemberId: null,
                reason: $setting ? 'assistant_disabled' : 'settings_not_configured',
                confidence: $this->intentConfidence($intent),
                metadata: ['execution' => 'not_performed'],
            );
        }

        if ($intent === RegistrationIntentClassifier::UNKNOWN) {
            return new AiDecision(
                intent: $intent,
                responseAction: 'IGNORE',
                responseText: null,
                handoffRequired: false,
                handoffDepartmentId: null,
                handoffCsMemberId: null,
                reason: 'unsupported_or_empty_message',
                confidence: null,
                metadata: ['execution' => 'not_performed'],
            );
        }

        if ($intent === RegistrationIntentClassifier::REGISTRATION) {
            return $this->registrationDecision($organization, $context);
        }

        $providerResponse = $this->provider->generateResponse($context);
        if (! $this->responseSafetyPolicy->allows($context, $providerResponse)) {
            Log::warning('AI provider response rejected by business safety policy.', [
                'provider' => $providerResponse->metadata['provider'] ?? 'unknown',
                'reason' => 'unconfigured_registration_url_phone_or_status',
            ]);
            $fallback = $this->fallbackProvider->generateResponse($context);
            $providerResponse = new AiProviderResponse(
                responseText: $fallback->responseText,
                confidence: $fallback->confidence,
                metadata: $fallback->metadata + ['fallback_reason' => 'business_safety_policy_rejected'],
            );
            if (! $this->responseSafetyPolicy->allows($context, $providerResponse)) {
                $providerResponse = new AiProviderResponse(
                    responseText: null,
                    confidence: null,
                    metadata: ['provider' => 'deterministic', 'fallback_reason' => 'no_safe_configured_response'],
                );
            }
        }

        return new AiDecision(
            intent: $intent,
            responseAction: filled($providerResponse->responseText) ? 'RESPOND' : 'IGNORE',
            responseText: $providerResponse->responseText,
            handoffRequired: false,
            handoffDepartmentId: null,
            handoffCsMemberId: null,
            reason: 'provider_response_generated',
            confidence: $providerResponse->confidence ?? $this->intentConfidence($intent),
            metadata: $providerResponse->metadata + ['execution' => 'decision_only'],
        );
    }

    private function registrationDecision(Organization $organization, AiContext $context): AiDecision
    {
        $registration = $context->registration;
        if (($registration['registration_enabled'] ?? false) !== true) {
            return $this->respond(
                'Pendaftaran saat ini belum tersedia berdasarkan konfigurasi yang ada.',
                'registration_not_enabled',
            );
        }

        $description = filled($registration['registration_description'] ?? null)
            ? trim((string) $registration['registration_description'])
            : null;
        $url = filled($registration['registration_url'] ?? null)
            ? trim((string) $registration['registration_url'])
            : null;
        $phone = filled($registration['registration_cs_phone'] ?? null)
            ? trim((string) $registration['registration_cs_phone'])
            : null;

        $registrationDepartmentId = filter_var($registration['registration_department_id'] ?? null, FILTER_VALIDATE_INT);
        if ($registrationDepartmentId !== false && $registrationDepartmentId !== null) {
            $mapping = $this->handoffResolver->resolveDepartment($organization->id, $registrationDepartmentId);

            return new AiDecision(
                intent: RegistrationIntentClassifier::REGISTRATION,
                responseAction: 'HANDOFF',
                responseText: null,
                handoffRequired: true,
                handoffDepartmentId: $mapping['department_id'],
                handoffCsMemberId: $mapping['cs_member_id'],
                reason: $mapping['mapped'] ? 'registration_department_handoff' : 'registration_department_without_active_cs',
                confidence: 1.0,
                metadata: ['execution' => 'decision_only', 'assignment_resolved' => $mapping['mapped']],
            );
        }

        if ($url !== null) {
            $parts = array_filter([
                $description,
                'Pendaftaran dapat dilakukan melalui tautan yang dikonfigurasi: '.$url,
                $phone ? 'Kontak pendaftaran yang dikonfigurasi: '.$phone : null,
            ]);

            return $this->respond(implode("\n", $parts), 'configured_registration_url');
        }

        if ($phone !== null) {
            $mapping = $this->handoffResolver->resolve($organization->id, $phone);

            return new AiDecision(
                intent: RegistrationIntentClassifier::REGISTRATION,
                responseAction: 'HANDOFF',
                responseText: $description ?: 'Informasi pendaftaran perlu dikonfirmasi oleh petugas. Kontak yang dikonfigurasi: '.$phone,
                handoffRequired: true,
                handoffDepartmentId: $mapping['department_id'],
                handoffCsMemberId: $mapping['cs_member_id'],
                reason: $mapping['mapped'] ? 'registration_requires_configured_cs' : 'registration_contact_not_mapped_to_active_cs',
                confidence: 1.0,
                metadata: ['execution' => 'decision_only', 'assignment_resolved' => $mapping['mapped']],
            );
        }

        if ($description !== null) {
            return $this->respond($description, 'configured_registration_description');
        }

        return $this->respond(
            'Informasi pendaftaran belum tersedia pada konfigurasi saat ini. Silakan tanyakan informasi yang ingin diketahui.',
            'registration_details_not_configured',
        );
    }

    private function respond(string $text, string $reason): AiDecision
    {
        return new AiDecision(
            intent: RegistrationIntentClassifier::REGISTRATION,
            responseAction: 'RESPOND',
            responseText: $text,
            handoffRequired: false,
            handoffDepartmentId: null,
            handoffCsMemberId: null,
            reason: $reason,
            confidence: 1.0,
            metadata: ['execution' => 'decision_only'],
        );
    }

    private function intentConfidence(string $intent): ?float
    {
        return match ($intent) {
            RegistrationIntentClassifier::REGISTRATION => 0.95,
            RegistrationIntentClassifier::GENERAL => 0.55,
            default => null,
        };
    }
}
