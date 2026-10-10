<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationAssignmentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'department_id' => ['present', 'nullable', 'integer', 'min:1'],
            'cs_member_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
