<?php

namespace App\Providers;

use App\Services\Llm\LlmAdapter;
use App\Services\Practice\SpeechGateway;
use App\Services\Redis\ArrayRedis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArrayRedis::class, fn () => new ArrayRedis);
        $this->app->singleton(LlmAdapter::class, fn () => LlmAdapter::fromSettings());
        $this->app->singleton(SpeechGateway::class, fn () => SpeechGateway::fromSettings());
    }

    public function boot(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
