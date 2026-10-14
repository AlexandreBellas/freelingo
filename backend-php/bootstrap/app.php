<?php

require_once __DIR__.'/polyfills.php';

use App\Exceptions\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('api.plain')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['refresh_token']);
        $middleware->group('api.plain', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\AuthenticateApi::class,
            'admin' => \App\Http\Middleware\RequireAdmin::class,
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );
        $exceptions->render(function (ApiException $e) {
            return response()->json(['detail' => $e->detail], $e->status);
        });
        $exceptions->render(function (ValidationException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        });
        $exceptions->render(function (HttpExceptionInterface $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Error';

            return response()->json(['detail' => $message], $e->getStatusCode());
        });
    })->create();
