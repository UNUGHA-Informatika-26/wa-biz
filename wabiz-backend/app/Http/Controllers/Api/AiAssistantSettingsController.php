<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAiAssistantSettingsRequest;
use App\Models\AiAssistantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AiAssistantSettingsController extends Controller
{
    /** @var list<string> */
    private const CONFIGURATION_FIELDS = [
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
    ];

    public function show(Request $request): JsonResponse
    {
        $setting = AiAssistantSetting::query()
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (! $setting) {
            return response()->json([
                'success' => true,
                'configured' => false,
                'message' => 'AI Assistant settings have not been configured.',
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'configured' => true,
            'data' => $this->configuration($setting),
        ]);
    }

    public function update(UpdateAiAssistantSettingsRequest $request): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        $user = $request->user();
        $organization = $user->organization()->firstOrFail();

        $setting = $organization->aiAssistantSetting()->updateOrCreate(
            [],
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'configured' => true,
            'message' => 'AI Assistant settings saved.',
            'data' => $this->configuration($setting),
        ]);
    }

    /** @return array<string, mixed> */
    private function configuration(AiAssistantSetting $setting): array
    {
        return $setting->only(self::CONFIGURATION_FIELDS);
    }
}
