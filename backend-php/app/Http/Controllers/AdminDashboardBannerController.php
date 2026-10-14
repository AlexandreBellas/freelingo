<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ResolvesApiUser;
use App\Models\DashboardBanner;
use App\Services\Llm\BannerTranslator;
use App\Support\Iso;
use App\Support\TargetLanguages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardBannerController extends Controller
{
    use ResolvesApiUser;

    private const PROMPT = <<<'TXT'
Translate this dashboard announcement faithfully into all fifteen requested locales.
Return en, es, fr, pt, de, it, ru, nl, pl, ro, tr, sv, da, fi, and hr. Preserve the original tone and meaning.
Do not add claims, details, formatting, Markdown, or HTML. Every field must contain plain text only.
For the source locale, copy each provided field exactly.

Source locale: %s
Title: %s
Subtitle: %s
Description: %s
TXT;

    public function __construct(private readonly BannerTranslator $translator) {}

    public function show(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $banner = DashboardBanner::query()->find(1);

        return response()->json($banner ? $this->present($banner) : null);
    }

    public function translate(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $source = $this->translation($request->json()->all(), true);
        $locale = $source['source_locale'];
        unset($source['source_locale']);
        $prompt = sprintf(self::PROMPT, $locale, $source['title'], $source['subtitle'], $source['description']);
        try {
            $translations = $this->translator->translate($prompt);
        } catch (\Throwable) {
            throw new ApiException('Could not translate dashboard banner', 502);
        }
        if (! is_array($translations)) {
            throw new ApiException('Could not translate dashboard banner', 502);
        }
        $translations[$locale] = $source;

        return response()->json(['translations' => $translations]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->apiUser($request);
        $data = $request->json()->all();
        if (! is_array($data) || array_diff(array_keys($data), ['source_locale', 'is_active', 'translations']) !== []) {
            throw new ApiException('Validation error', 422);
        }
        if (! isset($data['source_locale'], $data['is_active'], $data['translations']) || ! is_bool($data['is_active'])) {
            throw new ApiException('Validation error', 422);
        }
        if (! in_array($data['source_locale'], TargetLanguages::BANNER_LOCALES, true)) {
            throw new ApiException('Validation error', 422);
        }
        if (! is_array($data['translations']) || array_diff(array_keys($data['translations']), TargetLanguages::BANNER_LOCALES) !== [] || array_diff(TargetLanguages::BANNER_LOCALES, array_keys($data['translations'])) !== []) {
            throw new ApiException('Validation error', 422);
        }
        $translations = [];
        foreach (TargetLanguages::BANNER_LOCALES as $locale) {
            $translations[$locale] = $this->translation($data['translations'][$locale] ?? null, false);
        }
        $banner = DB::transaction(function () use ($data, $translations) {
            $banner = DashboardBanner::query()->where('id', 1)->lockForUpdate()->first();
            $now = Carbon::now('UTC');
            if (! $banner) {
                return DashboardBanner::query()->create([
                    'id' => 1,
                    'source_locale' => $data['source_locale'],
                    'is_active' => $data['is_active'],
                    'translations' => $translations,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $changed = $banner->source_locale !== $data['source_locale'] || $banner->translations != $translations;
            $banner->source_locale = $data['source_locale'];
            $banner->is_active = $data['is_active'];
            $banner->translations = $translations;
            if ($changed) {
                $banner->revision = (int) $banner->revision + 1;
            }
            $banner->updated_at = $now;
            $banner->save();

            return $banner->refresh();
        });

        return response()->json($this->present($banner));
    }

    /**
     * @return array<string, string>
     */
    private function translation(mixed $value, bool $withLocale): array
    {
        if (! is_array($value)) {
            throw new ApiException('Validation error', 422);
        }
        $allowed = $withLocale
            ? ['source_locale', 'title', 'subtitle', 'description']
            : ['title', 'subtitle', 'description'];
        if (array_diff(array_keys($value), $allowed) !== [] || array_diff($allowed, array_keys($value)) !== []) {
            throw new ApiException('Validation error', 422);
        }
        $result = [];
        foreach (['title', 'subtitle', 'description'] as $field) {
            $text = $value[$field] ?? null;
            if (! is_string($text)) {
                throw new ApiException('Validation error', 422);
            }
            $text = trim($text);
            $max = $field === 'title' ? 160 : ($field === 'subtitle' ? 240 : 2000);
            if ($text === '' || mb_strlen($text) > $max) {
                throw new ApiException('Validation error', 422);
            }
            $result[$field] = $text;
        }
        if ($withLocale) {
            if (! in_array($value['source_locale'], TargetLanguages::BANNER_LOCALES, true)) {
                throw new ApiException('Validation error', 422);
            }
            $result['source_locale'] = $value['source_locale'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DashboardBanner $banner): array
    {
        return [
            'revision' => (int) $banner->revision,
            'translations' => $banner->translations,
            'source_locale' => $banner->source_locale,
            'is_active' => (bool) $banner->is_active,
            'created_at' => Iso::string($banner->created_at),
            'updated_at' => Iso::string($banner->updated_at),
        ];
    }
}
