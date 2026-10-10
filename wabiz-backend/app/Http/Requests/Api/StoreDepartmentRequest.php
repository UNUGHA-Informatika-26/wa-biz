<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where(
                    fn ($query) => $query->where('organization_id', $this->user()->organization_id),
                ),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
