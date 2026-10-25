<?php

namespace App\Services\Llm;

class UnavailableBannerTranslator implements BannerTranslator
{
    public function translate(string $prompt): array
    {
        throw new LlmException('provider unavailable');
    }
}
