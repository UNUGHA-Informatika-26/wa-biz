<?php

namespace App\Services\AI;

use App\DataTransferObjects\AI\AiContext;
use App\DataTransferObjects\AI\AiProviderResponse;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;

class AiResponseSafetyPolicy
{
    public function __construct(private readonly IndonesianPhoneNumberNormalizer $phoneNormalizer) {}

    public function allows(AiContext $context, AiProviderResponse $response): bool
    {
        $text = $response->responseText ?? '';
        if (trim($text) === '' || $this->containsCredentialOrInternalId($text)) {
            return false;
        }

        if (($context->registration['registration_enabled'] ?? false) !== true
            && preg_match('/\b(?:daftar\s+sekarang|silakan\s+(?:daftar|registrasi)|pendaftaran\s+(?:tersedia|dibuka|masih\s+buka)|registrasi\s+(?:tersedia|dibuka)|(?:you\s+can|register\s+now|registration\s+is\s+(?:open|available)))\b/iu', $text) === 1) {
            return false;
        }

        $allowedUrls = array_values(array_filter([
            $context->settings['website'] ?? null,
            $context->settings['google_maps_url'] ?? null,
            ($context->registration['registration_enabled'] ?? false) === true
                ? ($context->registration['registration_url'] ?? null)
                : null,
        ], fn (mixed $url): bool => is_string($url) && $url !== ''));
        preg_match_all('/(?:https?:\/\/|www\.)[^\s<>"\']+|\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s<>"\']*)?/iu', $text, $urlMatches);
        foreach ($urlMatches[0] as $candidate) {
            $candidate = rtrim($candidate, '.,!?;:)]}');
            if (! in_array($candidate, $allowedUrls, true)) {
                return false;
            }
        }

        $textWithoutUrls = preg_replace('/(?:https?:\/\/|www\.)[^\s<>"\']+|\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s<>"\']*)?/iu', '', $text) ?? $text;
        preg_match_all('/(?<!\w)\+?\d[\d\s().-]{5,}\d(?!\w)/u', $textWithoutUrls, $phoneMatches);
        $allowedPhones = array_values(array_filter([
            $context->settings['phone'] ?? null,
            ($context->registration['registration_enabled'] ?? false) === true
                ? ($context->registration['registration_cs_phone'] ?? null)
                : null,
        ], fn (mixed $phone): bool => is_string($phone) && $phone !== ''));
        $allowedCanonical = array_map(fn (string $phone): string => $this->phoneNormalizer->canonicalize($phone), $allowedPhones);
        foreach ($phoneMatches[0] as $candidate) {
            if (! in_array($this->phoneNormalizer->canonicalize($candidate), $allowedCanonical, true)) {
                return false;
            }
        }

        return true;
    }

    private function containsCredentialOrInternalId(string $text): bool
    {
        return preg_match('/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}=*|\bAIza[A-Za-z0-9_-]{20,}|\bsk-[A-Za-z0-9_-]{16,}|\b(?:api[\s_-]?key|access[\s_-]?token|refresh[\s_-]?token|token|password|secret|credential)\s*(?:[:=]|\bis\b)\s*["\']?[^\s"\']{8,}/iu', $text) === 1
            || preg_match('/\b(?:internal|organization|org|conversation|client|message|user|department|cs[_ -]?member|member)[_ -]?id\s*[:=#]\s*(?:\d+|[0-9a-f]{8}-[0-9a-f-]{27,})\b/iu', $text) === 1;
    }
}
