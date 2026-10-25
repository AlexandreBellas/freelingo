<?php

namespace App\Http\Controllers;

use App\Models\DashboardBanner;
use App\Services\Redis\ArrayRedis;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public const MAINTENANCE_KEY = 'maintenance_mode';

    public function __construct(private readonly ArrayRedis $redis) {}

    public function show(): JsonResponse
    {
        $maintenance = false;
        try {
            $maintenance = $this->redis->get(self::MAINTENANCE_KEY) === '1';
        } catch (\Throwable) {
        }

        $banner = DashboardBanner::query()->find(1);
        $publicBanner = null;
        if ($banner && $banner->is_active) {
            $publicBanner = [
                'revision' => $banner->revision,
                'translations' => $banner->translations,
            ];
        }

        $analytics = Settings::string('umami_script_url') !== '' && Settings::string('umami_website_id') !== '';

        return response()->json([
            'analytics_enabled' => $analytics,
            'allow_registration' => Settings::bool('allow_registration'),
            'stripe_enabled' => Settings::bool('stripe_enabled'),
            'stripe_trial_days' => Settings::int('stripe_trial_days', 7),
            'freemium_trial_enabled' => Settings::bool('freemium_trial_enabled'),
            'tts_provider' => Settings::string('tts_provider', 'local'),
            'openai_tts_voice' => Settings::string('openai_tts_voice', 'nova'),
            'maintenance_mode' => $maintenance,
            'price_monthly' => (float) Settings::get('price_monthly', 0),
            'price_yearly' => (float) Settings::get('price_yearly', 0),
            'total_price_monthly' => (float) Settings::get('total_price_monthly', 0),
            'total_price_yearly' => (float) Settings::get('total_price_yearly', 0),
            'dashboard_banner' => $publicBanner,
        ]);
    }
}
