<?php

namespace App\Services\Practice;

use App\Services\Speech\KokoroTtsService;
use App\Services\Speech\OpenAiTtsService;
use App\Support\Settings;

final class TourAudio
{
    public static function path(object $service, string $locale, string $text, ?string $voice): string
    {
        $isOpenAi = $service instanceof OpenAiTtsService;
        $effective = $isOpenAi ? ($voice ?: $service->voice) : ($service->voice ?? 'af_heart');
        if ($isOpenAi) {
            $key = $service->getCacheKey($text, $effective, $locale);
        } else {
            $key = hash('sha256', Text::pyDumps([
                'text' => $text,
                'locale' => $locale,
                'provider' => 'local',
                'model' => 'kokoro',
                'voice' => $effective,
                'speed' => 1.0,
                'format' => 'mp3',
            ]));
        }
        $directory = rtrim(Settings::string('audio_storage_path', storage_path('app/audio')), '/').'/tour/'.$locale;
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Tour audio directory unavailable');
        }
        $path = $directory.'/'.$key.'.mp3';
        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }
        $lock = fopen($directory.'/'.$key.'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Tour lock unavailable');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Tour lock failed');
            }
            if (is_file($path) && filesize($path) > 0) {
                return $path;
            }
            $audio = $service->synthesize($text, $effective, $locale);
            if ($audio === '') {
                throw new \InvalidArgumentException('Empty tour narration');
            }
            $temporary = $directory.'/'.$key.'.'.bin2hex(random_bytes(4)).'.tmp';
            file_put_contents($temporary, $audio);
            rename($temporary, $path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $path;
    }

    public static function localIdentity(KokoroTtsService $service, string $locale, string $text, ?string $voice): string
    {
        return hash('sha256', Text::pyDumps([
            'format' => 'mp3',
            'locale' => $locale,
            'model' => 'kokoro',
            'provider' => 'local',
            'speed' => 1.0,
            'text' => $text,
            'voice' => $voice ?: $service->voice,
        ]));
    }
}
