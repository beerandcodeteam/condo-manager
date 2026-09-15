<?php

namespace App\Http\Middleware;

use App\Models\Condominium;
use App\Support\Tenancy\CurrentCondominium;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current condominium of API requests from the token owner only; no request
 * parameter can change it. Runs after EnsureCondominiumToken.
 */
class SetApiCondominium
{
    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $condominium = $request->user('sanctum');

        if ($condominium instanceof Condominium) {
            $this->currentCondominium->set($condominium);
        }

        return $next($request);
    }
}
