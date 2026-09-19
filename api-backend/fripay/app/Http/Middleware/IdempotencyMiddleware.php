<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    /**
     * Handle idempotency for POST requests that trigger money movements.
     * Uses Idempotency-Key header to prevent duplicate processing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('POST')) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return response()->json([
                'type' => 'MISSING_IDEMPOTENCY_KEY',
                'title' => 'Clé d\'idempotence manquante',
                'status' => 400,
                'detail' => 'L\'en-tête Idempotency-Key est requis pour cette requête.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 400);
        }

        $cacheKey = 'idempotency_' . $key;

        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);
            return response()->json($cachedResponse['data'], $cachedResponse['status']);
        }

        $response = $next($request);

        // Only cache successful responses (2xx) to allow retry on errors
        if ($response->isSuccessful()) {
            Cache::put($cacheKey, [
                'data' => json_decode($response->getContent(), true),
                'status' => $response->getStatusCode(),
            ], now()->addHours(24));
        }

        return $response;
    }
}
