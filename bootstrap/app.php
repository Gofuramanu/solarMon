<?php

use App\Http\Middleware\AuthenticateDeviceToken;
use App\Http\Middleware\EnsureDashboardAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'dashboard.access' => EnsureDashboardAccess::class,
            'device.auth' => AuthenticateDeviceToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();

if (! empty($_ENV['VERCEL'] ?? $_SERVER['VERCEL'] ?? getenv('VERCEL'))) {
    $app->useStoragePath('/tmp/storage');
}

return $app;
