<?php

namespace App\Services\Library\Koha;

use RuntimeException;

/** A refusal or failure from Koha's API, with what Koha said. */
class KohaError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly array $data = [])
    {
        parent::__construct($message, $status);
    }

    public function errorCode(): ?string
    {
        return $this->data['error_code'] ?? null;
    }
}
