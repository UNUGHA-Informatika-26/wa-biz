<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enabled',
    'assistant_name',
    'communication_style',
    'language',
    'additional_instructions',
    'institution_name',
    'institution_description',
    'student_count',
    'caregiver_count',
    'teacher_count',
    'services',
    'address',
    'google_maps_url',
    'additional_information',
    'faqs',
    'service_hours',
    'phone',
    'website',
    'latitude',
    'longitude',
    'location_description',
    'registration_enabled',
    'registration_url',
    'registration_status',
    'registration_cs_phone',
    'registration_department_id',
    'registration_description',
])]
class AiAssistantSetting extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'registration_enabled' => 'boolean',
            'faqs' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}
