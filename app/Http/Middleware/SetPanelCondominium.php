<?php

namespace App\Http\Middleware;

use App\Models\Condominium;
use App\Models\User;
use App\Support\Panel\PanelRoutes;
use App\Support\Tenancy\CurrentCondominium;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current condominium of panel requests: the user's own condominium for
 * síndico/zelador and the condominium selected in the session for the super admin.
 */
class SetPanelCondominium
{
    public const SESSION_KEY = 'current_condominium_id';

    /**
     * Platform routes use the `optional` mode: the super admin may use them without a selected condominium.
     */
    public const OPTIONAL = 'optional';

    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            $selectedCondominiumId = $request->session()->get(self::SESSION_KEY);
            $condominium = is_int($selectedCondominiumId) ? Condominium::find($selectedCondominiumId) : null;

            if ($condominium === null) {
                $request->session()->forget(self::SESSION_KEY);

                if ($mode === self::OPTIONAL) {
                    return $next($request);
                }

                return redirect()->to(PanelRoutes::condominiumsUrl());
            }
        } else {
            $condominium = $user->condominium;

            abort_if($condominium === null, Response::HTTP_FORBIDDEN);
        }

        $this->currentCondominium->set($condominium);

        return $next($request);
    }
}
