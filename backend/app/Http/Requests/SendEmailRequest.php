<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mailbox_id' => ['required', 'integer', 'exists:mailboxes,id'],
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:998'],
            'body_html' => ['required', 'string'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'in_reply_to' => ['sometimes', 'nullable', 'string'],
            'references' => ['sometimes', 'nullable', 'string'],
            'thread_id' => ['sometimes', 'nullable', 'integer', 'exists:email_threads,id'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:25600'],
            'send_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
