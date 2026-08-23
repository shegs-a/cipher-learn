<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the current tenant from the authenticated user, so every
 * tenant-scoped query in the request is automatically constrained. Runs inside
 * the panel's auth middleware stack, i.e. only once a user is resolved.
 */
final class BindCurrentTenant
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->tenant_id !== null) {
            $this->tenancy->set($user->tenant_id);
        }

        return $next($request);
    }
}
