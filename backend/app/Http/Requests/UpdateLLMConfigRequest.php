<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLLMConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:single,aggregation,council'],
            'providers' => ['sometimes', 'array'],
            'providers.*.provider' => ['required', 'string'],
            'providers.*.api_key' => ['sometimes', 'nullable', 'string'],
            'providers.*.model' => ['sometimes', 'string'],
            'providers.*.is_enabled' => ['sometimes', 'boolean'],
            'providers.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
