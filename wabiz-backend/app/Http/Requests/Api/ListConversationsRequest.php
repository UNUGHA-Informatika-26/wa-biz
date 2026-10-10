<?php

namespace App\Http\Requests\Api;

use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Http\FormRequest;

class ListConversationsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:AI_ACTIVE,WAITING_HUMAN,TRANSFERRED_TO_HUMAN,HUMAN_ACTIVE,RESOLVED,ARCHIVED'],
            'unread' => ['sometimes', 'boolean'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cs_member_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'whatsapp_connection_id' => ['sometimes', 'nullable', 'integer', 'min:1', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && ! WhatsAppConnection::query()
                    ->where('organization_id', $this->user()?->organization_id)
                    ->whereKey($value)->exists()) {
                    $fail('The selected WhatsApp connection is unavailable.');
                }
            }],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
