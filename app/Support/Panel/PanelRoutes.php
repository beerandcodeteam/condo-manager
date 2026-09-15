<?php

namespace App\Support\Panel;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Central route → gate map of the panel. Every new panel route must be registered here
 * and use `->can(PanelRoutes::gateFor('<route>'))`; the sidebar reads the same map.
 */
final class PanelRoutes
{
    /**
     * @var array<string, string>
     */
    public const GATES = [
        'dashboard' => 'dashboard.view',
        'escalations.index' => 'escalations.operate',
        'tickets.index' => 'tickets.operate',
        'tickets.photos.show' => 'tickets.operate',
        'reservations.index' => 'reservations.manage',
        'notices.index' => 'knowledge.manage',
        'rule-documents.index' => 'knowledge.manage',
        'residents.index' => 'residents.manage',
        'settings' => 'condominium.settings',
        'condominiums.index' => 'platform.manage',
        'users.index' => 'platform.manage',
        'condominium.switch' => 'platform.manage',
    ];

    /**
     * Path of the platform condominium list (Phase 5), used until its named route exists.
     */
    public const CONDOMINIUMS_PATH = '/plataforma/condominios';

    /**
     * URL of the platform condominium list, where the super admin picks or creates a condominium.
     */
    public static function condominiumsUrl(): string
    {
        return Route::has('condominiums.index') ? route('condominiums.index') : url(self::CONDOMINIUMS_PATH);
    }

    /**
     * Gate that protects the given panel route.
     *
     * @throws InvalidArgumentException
     */
    public static function gateFor(string $routeName): string
    {
        return self::GATES[$routeName]
            ?? throw new InvalidArgumentException("Panel route [{$routeName}] has no gate in PanelRoutes::GATES.");
    }
}
