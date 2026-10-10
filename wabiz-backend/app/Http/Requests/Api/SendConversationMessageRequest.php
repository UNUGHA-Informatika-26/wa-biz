<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SendConversationMessageRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:4096'],
            'sender_type' => ['prohibited'],
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
