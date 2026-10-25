<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    public function __construct(
        public readonly mixed $detail,
        public readonly int $status = 400,
    ) {
        parent::__construct(is_string($detail) ? $detail : 'API error');
    }
}
