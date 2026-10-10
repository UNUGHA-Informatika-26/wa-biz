<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppConnectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['sometimes', 'required', 'string', 'regex:/^[a-z][a-z0-9_]{0,99}$/'],
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_account_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array', 'max:50'],
        ];
    }
}
