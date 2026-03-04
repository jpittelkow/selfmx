<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:smtp,mailgun,sendgrid,ses,postmark'],
            'host' => ['required_if:provider,smtp', 'nullable', 'string'],
            'port' => ['required_if:provider,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['sometimes', 'string', 'in:tls,ssl'],
            'username' => ['sometimes', 'nullable', 'string'],
            'password' => ['sometimes', 'nullable', 'string'],
            'from_address' => ['required', 'string', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
            'mailgun_domain' => ['required_if:provider,mailgun', 'nullable', 'string'],
            'mailgun_secret' => ['required_if:provider,mailgun', 'nullable', 'string'],
            'sendgrid_api_key' => ['required_if:provider,sendgrid', 'nullable', 'string'],
            'ses_key' => ['required_if:provider,ses', 'nullable', 'string'],
            'ses_secret' => ['required_if:provider,ses', 'nullable', 'string'],
            'ses_region' => ['required_if:provider,ses', 'nullable', 'string'],
            'postmark_token' => ['required_if:provider,postmark', 'nullable', 'string'],
        ];
    }
}
