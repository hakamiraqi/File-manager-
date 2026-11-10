<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/admin.php');

            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/share.php');

            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/guest-access.php');

            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/library.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureOfflineActivated::class,
            \App\Http\Middleware\IpAccessControl::class,
            \App\Http\Middleware\SetApplicationLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);

        $middleware->api(append: [\App\Http\Middleware\EnsureOfflineActivated::class]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'web-json' => \App\Http\Middleware\WebPageAsJson::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
