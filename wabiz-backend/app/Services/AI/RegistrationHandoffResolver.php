<?php

namespace App\Services\AI;

use App\Models\CsMember;
use App\Models\Department;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;

class RegistrationHandoffResolver
{
    public function __construct(private readonly IndonesianPhoneNumberNormalizer $phoneNormalizer) {}

    /** @return array{department_id: int|null, cs_member_id: int|null, mapped: bool} */
    public function resolve(int $organizationId, string $configuredPhone): array
    {
        $canonical = $this->phoneNormalizer->canonicalize($configuredPhone);
        $forms = $this->phoneNormalizer->matchingDigitForms($canonical);
        $member = CsMember::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('department_id')
                    ->orWhereHas('department', fn ($departmentQuery) => $departmentQuery
                        ->where('organization_id', $organizationId)
                        ->where('active', true));
            })
            ->whereIn(DB::raw("regexp_replace(phone_number, '[^0-9]', '', 'g')"), $forms)
            ->with('department:id,organization_id,active')
            ->orderBy('id')
            ->first();

        if (! $member || ($member->department_id !== null
            && (! $member->department || ! $member->department->active
                || (int) $member->department->organization_id !== $organizationId))) {
            return ['department_id' => null, 'cs_member_id' => null, 'mapped' => false];
        }

        return [
            'department_id' => $member->department_id,
            'cs_member_id' => $member->id,
            'mapped' => true,
        ];
    }

    /** @return array{department_id: int, cs_member_id: int|null, mapped: bool} */
    public function resolveDepartment(int $organizationId, int $departmentId): array
    {
        $department = Department::query()
            ->where('organization_id', $organizationId)
            ->whereKey($departmentId)
            ->where('active', true)
            ->first();

        if (! $department) {
            return ['department_id' => $departmentId, 'cs_member_id' => null, 'mapped' => false];
        }

        $member = CsMember::query()
            ->where('organization_id', $organizationId)
            ->where('department_id', $department->id)
            ->where('active', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '<>', '')
            ->orderBy('id')
            ->first();

        return [
            'department_id' => (int) $department->id,
            'cs_member_id' => $member?->id,
            'mapped' => $member !== null,
        ];
    }
}
