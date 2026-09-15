<?php

namespace App\Http\Middleware;

use App\Models\Condominium;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only API requests authenticated by a personal access token that belongs to a condominium.
 */
class EnsureCondominiumToken
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
        $tokenable = $request->user('sanctum');

        if (! $tokenable instanceof Condominium) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
