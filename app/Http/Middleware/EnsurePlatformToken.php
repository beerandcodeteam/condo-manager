<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only requests carrying the shared platform token (the n8n flow, before any condominium is known).
 *
 * This guards the tenant resolution endpoint, which mints condominium tokens: without it, anyone reaching
 * the URL could exchange a phone number for a token with write access to that condominium.
 */
class EnsurePlatformToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('condo.platform.token');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
