<?php

namespace App\Services\Email;

class ImportResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {}

    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => array_slice($this->errors, 0, 10),
        ];
    }
}
