<?php

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            return ApiResponse::error(
                'Resource not found.', 
                ErrorCode::RESOURCE_NOT_FOUND->value,
                404
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return ApiResponse::error(
                'Resource not found.', 
                ErrorCode::RESOURCE_NOT_FOUND->value,
                404,
            );
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            return ApiResponse::error(
                $e->getMessage(), 
                ErrorCode::METHOD_NOT_ALLOWED->value,
                405);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            return ApiResponse::error(
                'You are not authorized to perform this action.', 
                ErrorCode::FORBIDDEN->value,
                403
            );
        });
    })->create();
