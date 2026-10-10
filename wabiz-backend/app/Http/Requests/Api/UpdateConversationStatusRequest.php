<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationStatusRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:AI_ACTIVE,WAITING_HUMAN,TRANSFERRED_TO_HUMAN,HUMAN_ACTIVE,RESOLVED,ARCHIVED'],
        ];
    }
}
