<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        string $message = 'Request successful',
        mixed $data = [],
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status, $headers);
    }

    public static function error(
        string $message = 'An error occurred.',
        ?string $errorCode = null,
        int $statusCode = 400,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $errorCode,
        ], $statusCode, $headers);
    }
}
