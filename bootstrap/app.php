<?php

use App\Http\Middleware\EnsureBearerToken;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\FormatApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php', // khai báo các arisan command custom
        health: '/up', // url up này để check app die hay k
    )
    ->withCommands() // theo url app/console/command nó quét các file trong đấy

    // gán bí danh active cho thằng ensureUserIsActive
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [FormatApiResponse::class]);
        $middleware->alias([
            'api.token' => EnsureBearerToken::class,
            'active' => EnsureUserIsActive::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($request->is('api/*')) {
                return \App\Support\ApiResponse::fromException($exception);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $exception): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
