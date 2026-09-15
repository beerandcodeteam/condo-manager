<?php

namespace App\Services\Platform;

use App\Http\Middleware\SetPanelCondominium;
use App\Models\Condominium;
use App\Models\TicketCategory;
use Illuminate\Support\Facades\DB;

class CondominiumService
{
    /**
     * Create a condominium with the default ticket categories and select it in the super admin session.
     *
     * @param  array{name: string, city?: string|null}  $attributes
     */
    public function create(array $attributes): Condominium
    {
        $condominium = DB::transaction(function () use ($attributes): Condominium {
            $condominium = Condominium::create([
                'name' => $attributes['name'],
                'city' => $attributes['city'] ?? null,
            ]);

            foreach (TicketCategory::DEFAULTS as $slug => $name) {
                $condominium->ticketCategories()->create(['slug' => $slug, 'name' => $name]);
            }

            return $condominium;
        });

        session()->put(SetPanelCondominium::SESSION_KEY, $condominium->id);

        return $condominium;
    }

    /**
     * @param  array{name: string, city?: string|null}  $attributes
     */
    public function update(Condominium $condominium, array $attributes): Condominium
    {
        $condominium->update([
            'name' => $attributes['name'],
            'city' => $attributes['city'] ?? null,
        ]);

        return $condominium;
    }
}
