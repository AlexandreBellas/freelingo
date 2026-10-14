<?php

namespace App\Services\Llm;

interface BannerTranslator
{
    /**
     * @return array<string, array{title: string, subtitle: string, description: string}>
     */
    public function translate(string $prompt): array;
}
