<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to the API documentation (UI and JSON specs).
 *
 * Web authentication in this app flows exclusively through Filament, which
 * rejects any user whose canAccessPanel() returns false. The read-only
 * `developer` role is deliberately excluded from the panel, so it has no way to
 * obtain a Filament session. To still let developers read the docs without ever
 * touching the admin, this middleware authenticates them via stateless HTTP Basic
 * auth and then authorizes them through the `viewApiDocs` gate.
 *
 * Resolution order:
 *  1. Local environment — always allowed (frictionless dev).
 *  2. Already authenticated (e.g. a super_admin with a Filament session) — reuse it.
 *  3. Otherwise prompt for HTTP Basic credentials (single request, no session).
 *  4. Finally, the `viewApiDocs` ability decides (super_admin or developer).
 */
class EnsureCanViewApiDocs
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request  Incoming HTTP request.
     * @param  Closure(Request): Response  $next  Next middleware in the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        $guard = Auth::guard('web');

        // No existing session — challenge for HTTP Basic credentials. onceBasic
        // authenticates for this request only and returns a 401 response on failure.
        if (! $guard->check()) {
            if ($response = $guard->onceBasic('email')) {
                return $response;
            }
        }

        if ($guard->check() && Gate::forUser($guard->user())->allows('viewApiDocs')) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN);
    }
}
