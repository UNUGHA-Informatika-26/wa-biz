<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiAssistantSettingsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'enabled' => ['sometimes', 'boolean'],
            'assistant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'communication_style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'language' => ['sometimes', 'nullable', 'string', 'max:50'],
            'additional_instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],

            'institution_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'institution_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'student_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'caregiver_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'teacher_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'services' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'google_maps_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'additional_information' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'faqs' => ['sometimes', 'nullable', 'array', 'max:100'],
            'faqs.*.question' => ['required_with:faqs', 'string', 'max:1000'],
            'faqs.*.answer' => ['required_with:faqs', 'string', 'max:4000'],
            'service_hours' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'registration_enabled' => ['sometimes', 'boolean'],
            'registration_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'registration_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_cs_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_department_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('active', true)),
            ],
            'registration_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
