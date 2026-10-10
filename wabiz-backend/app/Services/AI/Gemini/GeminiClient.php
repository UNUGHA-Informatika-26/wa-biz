<?php

namespace App\Services\AI\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class GeminiClient
{
    /** @param array<string, mixed> $payload */
    public function generateContent(array $payload): GeminiGenerationResult
    {
        $apiKey = trim((string) config('services.gemini.api_key', ''));
        $model = trim((string) config('services.gemini.model', ''));
        $baseUrl = trim((string) config('services.gemini.base_url', ''));

        if ($apiKey === '') {
            throw new GeminiClientException('configuration_missing_api_key');
        }
        if ($model === '') {
            throw new GeminiClientException('configuration_missing_model');
        }
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new GeminiClientException('configuration_invalid_base_url');
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $model) !== 1) {
            throw new GeminiClientException('configuration_invalid_model');
        }

        $url = rtrim($baseUrl, '/').'/models/'.rawurlencode($model).':generateContent';
        $startedAt = hrtime(true);
        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(max(1, (int) config('services.gemini.connect_timeout', 5)))
                ->timeout(max(1, (int) config('services.gemini.timeout', 20)))
                ->post($url, $payload);
        } catch (ConnectionException) {
            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            throw new GeminiClientException('transport_error', null, $latency);
        }

        $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        if (! $response->successful()) {
            $kind = match (true) {
                $response->status() === 401 || $response->status() === 403 => 'authentication_error',
                $response->status() === 429 => 'rate_limited',
                $response->serverError() => 'provider_server_error',
                default => 'provider_request_rejected',
            };
            throw new GeminiClientException($kind, $response->status(), $latency);
        }

        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GeminiClientException('malformed_provider_json', $response->status(), $latency);
        }
        if (! is_array($body)) {
            throw new GeminiClientException('malformed_provider_response', $response->status(), $latency);
        }

        $blockReason = $body['promptFeedback']['blockReason'] ?? null;
        if (is_string($blockReason) && $blockReason !== '' && $blockReason !== 'BLOCK_REASON_UNSPECIFIED') {
            throw new GeminiClientException('prompt_blocked', $response->status(), $latency);
        }

        $candidate = $body['candidates'][0] ?? null;
        if (is_array($candidate)) {
            $finishReason = $candidate['finishReason'] ?? null;
            if (is_string($finishReason) && in_array(strtoupper($finishReason), [
                'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII',
                'IMAGE_SAFETY', 'MODEL_ARMOR', 'JAILBREAK',
            ], true)) {
                throw new GeminiClientException('candidate_blocked', $response->status(), $latency);
            }
        }

        $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? null) : null;
        if (! is_array($parts)) {
            throw new GeminiClientException('missing_candidate_content', $response->status(), $latency);
        }
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            throw new GeminiClientException('empty_provider_response', $response->status(), $latency);
        }

        return new GeminiGenerationResult($text, $response->status(), $latency);
    }
}
