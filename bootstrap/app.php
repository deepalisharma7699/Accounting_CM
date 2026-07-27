<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API is stateless: no session, no CSRF, always JSON.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            // authGuard — verifies the Bearer access token.
            'auth.jwt' => JwtAuthenticate::class,
            // permissionGuard — checks the route's required grant(s).
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Single funnel for every API error response (400/401/403/404/405/
        // 409/422/423/429/500) so clients only parse one envelope.
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request)
        );
    })->create();
