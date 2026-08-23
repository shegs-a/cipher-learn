<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a correlation id to every request so structured logs can be tied
 * back to a single call across the app and (later) the queue workers. Honours
 * an inbound `X-Request-Id` when a proxy/upstream already set one, otherwise
 * mints a UUID. The id is shared into the log context and echoed back on the
 * response header for client-side correlation.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();

        $request->headers->set('X-Request-Id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
