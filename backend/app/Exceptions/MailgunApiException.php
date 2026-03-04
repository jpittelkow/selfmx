<?php

namespace App\Exceptions;

use RuntimeException;

class MailgunApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message, $httpStatus);
    }
}
