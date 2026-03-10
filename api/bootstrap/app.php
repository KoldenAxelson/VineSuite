<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\TenancyServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'throttle.token' => \App\Http\Middleware\ThrottleByTokenType::class,
        ]);

        // Force JSON on all API routes — prevents HTML error pages
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Stripe webhook needs to be excluded from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/v1/stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render API exceptions in the standard envelope format.
        // Only applies to /api/* requests — Filament routes use default HTML rendering.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = [
                        'field' => $field,
                        'message' => $message,
                    ];
                }
            }

            return response()->json([
                'data' => null,
                'meta' => (object) [],
                'errors' => $errors,
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'meta' => (object) [],
                'errors' => [['message' => $e->getMessage() ?: 'Unauthenticated.']],
            ], 401);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $e->getMessage() ?: 'Resource not found.';

            return response()->json([
                'data' => null,
                'meta' => (object) [],
                'errors' => [['message' => $message]],
            ], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'meta' => (object) [],
                'errors' => [['message' => $e->getMessage() ?: 'An error occurred.']],
            ], $e->getStatusCode());
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = 500;
            $message = app()->isProduction()
                ? 'Internal server error.'
                : $e->getMessage();

            return response()->json([
                'data' => null,
                'meta' => (object) (app()->isProduction() ? [] : [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]),
                'errors' => [['message' => $message]],
            ], $status);
        });
    })->create();
