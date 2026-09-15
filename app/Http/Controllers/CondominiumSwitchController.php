<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetPanelCondominium;
use App\Models\Condominium;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CondominiumSwitchController extends Controller
{
    /**
     * Select the condominium the super admin operates in the panel.
     */
    public function __invoke(Request $request, Condominium $condominium): RedirectResponse
    {
        $request->session()->put(SetPanelCondominium::SESSION_KEY, $condominium->id);

        return redirect()->route('dashboard');
    }
}
