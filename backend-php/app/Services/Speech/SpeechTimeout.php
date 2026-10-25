<?php

namespace App\Services\Speech;

/**
 * Raised when speech synthesis exceeds its deadline.
 * The tour endpoint maps this to HTTP 504.
 */
final class SpeechTimeout extends \RuntimeException
{
}
