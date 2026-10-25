<?php

namespace App\Services\Analytics;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyticsService
{
    public function enabled(): bool
    {
        return $this->endpoint() !== null;
    }

    /**
     * @param  array<string, string|int|float|bool|null>|null  $data
     */
    public function track(string $name, string $userAgent, string $path = '/', ?array $data = null): bool
    {
        $endpoint = $this->endpoint();
        if ($endpoint === null) {
            return false;
        }
        try {
            if (! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name) || trim($userAgent) === '') {
                throw new \InvalidArgumentException('Invalid event name or missing User-Agent');
            }
            $parts = parse_url($path);
            if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || ! str_starts_with($parts['path'] ?? '', '/')) {
                throw new \InvalidArgumentException('Expected an application-relative path');
            }
            if ($data !== null) {
                if (count($data) > 50) {
                    throw new \InvalidArgumentException('Too many event properties');
                }
                foreach ($data as $key => $value) {
                    if (! is_string($key) || $key === '' || strlen($key) > 50) {
                        throw new \InvalidArgumentException('Invalid property name');
                    }
                    if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                        throw new \InvalidArgumentException('Event properties must be scalar values');
                    }
                    if (is_string($value) && strlen($value) > 500) {
                        throw new \InvalidArgumentException('Event property is too long');
                    }
                }
            }
            $payload = [
                'website' => $this->websiteId(),
                'hostname' => $this->hostname(),
                'url' => $parts['path'],
                'name' => $name,
            ];
            if ($data) {
                $payload['data'] = $data;
            }
            $body = json_encode(['type' => 'event', 'payload' => $payload], JSON_THROW_ON_ERROR);
            $response = Http::withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => $userAgent,
                ])
                ->timeout(3)
                ->withBody($body, 'application/json')
                ->post($endpoint);
            if (! $response->successful()) {
                throw new \RuntimeException('analytics http');
            }
            $result = $response->json();
            if (is_array($result) && ! empty($result['sessionId'])) {
                return true;
            }
            Log::warning('[analytics] Umami did not acknowledge the event');
        } catch (\Throwable) {
            Log::warning('[analytics] Event delivery failed');
        }

        return false;
    }

    private function endpoint(): ?string
    {
        $script = trim(Settings::string('umami_script_url'));
        $website = trim(Settings::string('umami_website_id'));
        $app = trim(Settings::string('app_base_url'));
        if ($script === '' || $website === '' || $app === '' || ! Str::isUuid($website)) {
            return null;
        }
        $scriptParts = parse_url($script);
        $appParts = parse_url($app);
        if (! is_array($scriptParts) || ! is_array($appParts)) {
            return null;
        }
        foreach ([$scriptParts, $appParts] as $url) {
            if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                return null;
            }
            if (! in_array($url['scheme'] ?? '', ['http', 'https'], true) || ! isset($url['host'])) {
                return null;
            }
        }
        $port = isset($scriptParts['port']) ? ':'.$scriptParts['port'] : '';

        return $scriptParts['scheme'].'://'.$scriptParts['host'].$port.'/api/send';
    }

    private function websiteId(): string
    {
        return trim(Settings::string('umami_website_id'));
    }

    private function hostname(): string
    {
        $parts = parse_url(trim(Settings::string('app_base_url')));

        return is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    }
}
