<?php

namespace App\Services\Email;

class SendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
