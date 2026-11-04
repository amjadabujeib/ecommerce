<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The single error format every failed request answers with:
 *
 *     {"status": 422, "code": "validation_failed", "message": "...", "errors": {...}}
 *
 * "errors" is only present for validation failures. Stack traces and internal
 * exception details are never included.
 */
final class ApiError
{
    /**
     * @var array<int, string>
     */
    private const array CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        419 => 'session_expired',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    /**
     * Rewrite a JSON error response rendered by the framework into the
     * application's error format, keeping its status code and headers.
     */
    public static function reshape(JsonResponse $response, bool $debug = false): JsonResponse
    {
        $status = $response->getStatusCode();
        $original = $response->getData(true);

        $message = match (true) {
            $status === 404 => 'The requested resource was not found.',
            // 503 messages are written for the user (e.g. the inventory
            // service is down), so they are kept; other 5xx stay generic.
            $status >= 500 && $status !== 503 && ! $debug => 'An unexpected server error occurred.',
            default => is_array($original) && is_string($original['message'] ?? null) && $original['message'] !== ''
                ? $original['message']
                : 'The request could not be completed.',
        };

        $errors = is_array($original) && is_array($original['errors'] ?? null) ? $original['errors'] : null;

        $body = [
            'status' => $status,
            'code' => match (true) {
                // A 422 without field errors is a business rule, not bad input.
                $status === 422 && $errors === null => 'unprocessable',
                default => self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'error'),
            },
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return $response->setData($body);
    }
}
