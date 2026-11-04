<?php

use App\Http\Middleware\SecurityHeaders;
use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Orders\Exceptions\EmptyCartException;
use App\Modules\Orders\Exceptions\InvalidOrderTransitionException;
use App\Modules\Products\Exceptions\CategoryInUseException;
use App\Modules\Products\Exceptions\InsufficientStockException;
use App\Modules\Products\Exceptions\ProductHasPendingOrdersException;
use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

return Application::configure(basePath: dirname(__DIR__))
    // API routes are registered by each module's service provider under
    // /api/v1 (see App\Modules\ModuleServiceProvider).
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The project is backend-only: every response, including validation
        // failures and unauthenticated requests, is rendered as JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Domain exceptions stay HTTP-agnostic; their status codes live here.
        $exceptions->map(
            InsufficientStockException::class,
            fn (InsufficientStockException $e): HttpException => new ConflictHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            CategoryInUseException::class,
            fn (CategoryInUseException $e): HttpException => new ConflictHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            ProductHasPendingOrdersException::class,
            fn (ProductHasPendingOrdersException $e): HttpException => new ConflictHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            EmptyCartException::class,
            fn (EmptyCartException $e): HttpException => new UnprocessableEntityHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            InvalidOrderTransitionException::class,
            fn (InvalidOrderTransitionException $e): HttpException => new UnprocessableEntityHttpException($e->getMessage(), $e),
        );

        // The inventory service is stopped, too slow or failing: answer at
        // once with a clear 503 and a hint to retry, never a hanging request.
        $exceptions->map(
            InventoryUnavailableException::class,
            fn (InventoryUnavailableException $e): HttpException => new ServiceUnavailableHttpException(5, $e->getMessage(), $e),
        );

        $exceptions->map(
            JWTException::class,
            fn (JWTException $e): HttpException => new UnauthorizedHttpException('Bearer', 'The token is invalid or can no longer be refreshed.', $e),
        );

        $exceptions->respond(
            fn (Response $response): Response => $response instanceof JsonResponse
                ? ApiError::reshape($response, (bool) config('app.debug'))
                : $response,
        );
    })->create();
