<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLLMProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:claude,openai,gemini,ollama,azure,bedrock'],
            'model' => ['required', 'string'],
            'api_key' => ['sometimes', 'nullable', 'string'],
            'base_url' => ['sometimes', 'nullable', 'string'],
            'endpoint' => ['sometimes', 'nullable', 'string'],
            'region' => ['sometimes', 'nullable', 'string'],
            'access_key' => ['sometimes', 'nullable', 'string'],
            'secret_key' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
