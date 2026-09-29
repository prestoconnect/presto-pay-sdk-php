<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // /presto/notify is called by Presto's own servers, which cannot hold a Laravel session or CSRF
        // token. /checkout, /payments/*/reverse and /payments/*/refund are exempted so they stay
        // curl-friendly JSON endpoints (matching the Go SDK sample), the same reason a plain PHP or Symfony
        // sample doesn't need a token to begin with -- see sample/README.md for what a production app would
        // do instead (a CSRF meta tag read by the page's own fetch() call).
        $middleware->validateCsrfTokens(except: ['checkout', 'payments/*/reverse', 'payments/*/refund', 'presto/notify']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
