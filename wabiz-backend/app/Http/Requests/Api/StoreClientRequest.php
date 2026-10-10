<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:customer,prospect,vip,new'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
