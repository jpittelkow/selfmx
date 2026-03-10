<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $responseBody = [],
    ) {
        parent::__construct($message, $httpStatus);
    }
}
