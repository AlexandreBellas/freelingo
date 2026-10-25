<?php

namespace App\Services\Email;

final class SafeHtml
{
    public function __construct(public readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
