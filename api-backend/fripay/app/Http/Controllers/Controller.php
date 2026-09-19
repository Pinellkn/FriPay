<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Return a standardized error response (RFC 7807 style).
     */
    protected function errorResponse(
        string $type,
        string $title,
        int $status,
        string $detail = '',
        ?Request $request = null
    ): JsonResponse {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'request_id' => $request?->header('X-Request-Id') ?? '',
        ];

        return response()->json($body, $status);
    }

    /**
     * Return a success response with data.
     */
    protected function successResponse(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers);
    }

    /**
     * Return a paginated list response.
     */
    protected function paginatedResponse($paginator, ?callable $resourceCallback = null): JsonResponse
    {
        $data = $paginator->items();
        
        if ($resourceCallback && !empty($data)) {
            $data = collect($data)->map($resourceCallback)->values();
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_next' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
