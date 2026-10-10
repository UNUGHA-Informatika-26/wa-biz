<?php

namespace App\Services\AI;

use App\Contracts\AiProviderInterface;
use App\DataTransferObjects\AI\AiContext;
use App\DataTransferObjects\AI\AiProviderResponse;

class DeterministicAiProvider implements AiProviderInterface
{
    public function generateResponse(AiContext $context): AiProviderResponse
    {
        $settings = $context->settings;
        $facts = [];
        foreach ([
            'institution_name' => 'Nama',
            'institution_description' => 'Tentang',
            'student_count' => 'Jumlah siswa/santri',
            'caregiver_count' => 'Jumlah pengasuh',
            'teacher_count' => 'Jumlah guru',
            'services' => 'Layanan',
            'address' => 'Alamat',
            'google_maps_url' => 'Google Maps',
            'additional_information' => 'Informasi tambahan',
            'service_hours' => 'Jam layanan',
            'phone' => 'Telepon',
            'website' => 'Website',
            'location_description' => 'Lokasi',
        ] as $field => $label) {
            if (filled($settings[$field] ?? null)) {
                $facts[] = $label.': '.$settings[$field];
            }
        }
        foreach ($settings['faqs'] ?? [] as $faq) {
            if (is_array($faq) && filled($faq['question'] ?? null) && filled($faq['answer'] ?? null)) {
                $facts[] = 'FAQ: '.trim((string) $faq['question']).' — '.trim((string) $faq['answer']);
            }
        }

        if ($facts === []) {
            return new AiProviderResponse(
                responseText: 'Informasi tersebut belum tersedia pada konfigurasi saat ini. Silakan sampaikan pertanyaan yang lebih spesifik.',
                confidence: 1.0,
                metadata: ['provider' => 'deterministic', 'basis' => 'no_configured_institution_facts'],
            );
        }

        $assistantName = filled($settings['assistant_name'] ?? null) ? $settings['assistant_name'] : null;
        $opening = $assistantName ? 'Informasi dari '.$assistantName.':' : 'Informasi yang tersedia:';

        return new AiProviderResponse(
            responseText: $opening."\n".implode("\n", $facts),
            confidence: 1.0,
            metadata: ['provider' => 'deterministic', 'basis' => 'configured_settings'],
        );
    }
}
