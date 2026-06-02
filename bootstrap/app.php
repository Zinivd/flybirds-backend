<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException; // Import the authentication exception class
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'superadmin' => \App\Http\Middleware\EnsureIsSuperAdmin::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions) {
        // FORCE API ROUTES TO RETURN REJECTED JSON PAYLOADS INSTEAD OF REDIRECTS
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated access token window. Please provide a valid Bearer token.'
                ], 401);
            }
        });
    })->create();