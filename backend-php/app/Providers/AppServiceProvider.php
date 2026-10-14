<?php

namespace App\Providers;

use App\Services\Analytics\HttpAnalytics;
use App\Services\Billing\HttpStripeGateway;
use App\Services\Billing\StripeGateway;
use App\Services\Llm\BannerTranslator;
use App\Services\Llm\LlmAdapter;
use App\Services\Llm\UnavailableBannerTranslator;
use App\Services\Practice\SpeechGateway;
use App\Services\Redis\ArrayRedis;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArrayRedis::class, fn () => new ArrayRedis);
        $this->app->singleton(LlmAdapter::class, fn () => LlmAdapter::fromSettings());
        $this->app->singleton(SpeechGateway::class, fn () => SpeechGateway::fromSettings());
        $this->app->singleton(StripeGateway::class, fn () => new HttpStripeGateway);
        $this->app->singleton(BannerTranslator::class, fn () => new UnavailableBannerTranslator);
    }

    public function boot(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            try {
                app(HttpAnalytics::class)->handle($event->request, $event->response->getStatusCode());
            } catch (\Throwable) {
            }
        });
    }
}
