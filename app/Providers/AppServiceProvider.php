<?php

namespace App\Providers;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->components->securitySchemes['sanctum'] =
                    SecurityScheme::http('bearer');
            })
            ->withOperationTransformers(function (
                Operation $operation,
                RouteInfo $routeInfo
            ) {
                $middleware = $routeInfo->route->gatherMiddleware();

                if (in_array('auth:sanctum', $middleware, true)) {
                    $operation->security = [
                        new SecurityRequirement([
                            'sanctum' => [],
                        ]),
                    ];
                }
        });

        RateLimiter::for('login', function(Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function ($request, $headers) {
                    return ApiResponse::error(
                        'Too many attempts. Please try again later.',
                        ErrorCode::TOO_MANY_REQUESTS->value,
                        429,
                        [
                            'Retry-After' => $headers['Retry-After'] ?? 60,
                        ]
                    );
                });
        });

        RateLimiter::for('api', function(Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()->id)
                ->response(function($request, $headers) {
                    return ApiResponse::error(
                        'Too many attempts. Please try again later.',
                        ErrorCode::TOO_MANY_REQUESTS->value,
                        429,
                        [
                            'Retry-After' => $headers['Retry-After'] ?? 60,
                        ]
                    );
                });
        });
    }
}
