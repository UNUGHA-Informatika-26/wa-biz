<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationMessageRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:4096'],
            'message_type' => ['sometimes', 'string', 'in:text'],
            'metadata' => ['prohibited'],
            'direction' => ['prohibited'],
            'sender_type' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'external_message_id' => ['prohibited'],
        ];
    }

    public function idempotencyKey(): string
    {
        $validated = validator(
            ['idempotency_key' => $this->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();

        return $validated['idempotency_key'];
    }
}
