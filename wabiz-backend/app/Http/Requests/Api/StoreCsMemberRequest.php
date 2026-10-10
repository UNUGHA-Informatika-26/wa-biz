<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCsMemberRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'department_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('departments', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('active', true),
                ),
            ],
            'display_name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'phone_number' => ['required_without:user_id', 'nullable', 'string', 'max:30', 'regex:/^[+0-9().\s-]{8,30}$/'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
