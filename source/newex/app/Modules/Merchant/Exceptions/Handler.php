<?php

namespace App\Modules\Merchant\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler
{
    /**
     * Handle exceptions for Merchant API routes
     */
    public static function handle(Throwable $exception, Request $request): JsonResponse
    {
        // Custom API exceptions
        if ($exception instanceof MerchantApiException) {
            return $exception->render();
        }

        // Validation exceptions
        if ($exception instanceof ValidationException) {
            return self::handleValidationException($exception);
        }

        // Authentication exceptions
        if ($exception instanceof AuthenticationException) {
            return self::errorResponse('UNAUTHORIZED', 'Unauthenticated', 401);
        }

        // Model not found
        if ($exception instanceof ModelNotFoundException) {
            $model = class_basename($exception->getModel());
            return self::errorResponse('NOT_FOUND', "{$model} not found", 404);
        }

        // Route not found
        if ($exception instanceof NotFoundHttpException) {
            return self::errorResponse('NOT_FOUND', 'Endpoint not found', 404);
        }

        // Generic HTTP exceptions
        if ($exception instanceof HttpException) {
            return self::errorResponse(
                'HTTP_ERROR',
                $exception->getMessage() ?: 'HTTP Error',
                $exception->getStatusCode()
            );
        }

        // Invalid argument
        if ($exception instanceof \InvalidArgumentException) {
            return self::errorResponse('INVALID_REQUEST', $exception->getMessage(), 400);
        }

        // Generic error (don't expose details in production)
        $message = config('app.debug')
            ? $exception->getMessage()
            : 'An internal error occurred';

        return self::errorResponse('INTERNAL_ERROR', $message, 500);
    }

    /**
     * Handle validation exception
     */
    protected static function handleValidationException(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid',
                'details' => [
                    'errors' => $exception->errors(),
                ],
            ],
        ], 422);
    }

    /**
     * Create error response
     */
    protected static function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
