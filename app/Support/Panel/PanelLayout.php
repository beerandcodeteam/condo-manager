<?php

namespace App\Support\Panel;

use App\Models\Condominium;
use App\Models\User;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Support\Facades\Auth;

/**
 * Data of the panel layout (sidebar card, condominium switcher, navigation and footer) for the signed-in user.
 */
class PanelLayout
{
    /**
     * Sidebar navigation item → panel route name (gate resolved through PanelRoutes).
     *
     * @var array<string, string>
     */
    public const NAVIGATION_ROUTES = [
        'dashboard' => 'dashboard',
        'escalations' => 'escalations.index',
        'tickets' => 'tickets.index',
        'reservations' => 'reservations.index',
        'notices' => 'notices.index',
        'rule-documents' => 'rule-documents.index',
        'residents' => 'residents.index',
        'settings' => 'settings',
        'condominiums' => 'condominiums.index',
        'users' => 'users.index',
    ];

    public function __construct(private CurrentCondominium $currentCondominium) {}

    /**
     * @return array{name: string, city: string|null, units: int}|null
     */
    public function condominium(): ?array
    {
        $condominium = $this->currentCondominium->get();

        if ($condominium === null) {
            return null;
        }

        return [
            'name' => $condominium->name,
            'city' => $condominium->city,
            'units' => $condominium->units()->count(),
        ];
    }

    public function canSwitch(): bool
    {
        return $this->user()?->can(PanelRoutes::gateFor('condominium.switch')) ?? false;
    }

    /**
     * Condominiums offered by the super admin switcher, highlighting the current one.
     *
     * @return list<array{name: string, city: string|null, url: string, current: bool}>
     */
    public function condominiums(): array
    {
        if (! $this->canSwitch()) {
            return [];
        }

        $currentCondominiumId = $this->currentCondominium->id();

        return array_values(Condominium::query()
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->map(fn (Condominium $condominium): array => [
                'name' => $condominium->name,
                'city' => $condominium->city,
                'url' => route('condominium.switch', $condominium),
                'current' => $condominium->id === $currentCondominiumId,
            ])
            ->all());
    }

    public function newCondominiumUrl(): ?string
    {
        return $this->canSwitch() ? PanelRoutes::condominiumsUrl() : null;
    }

    /**
     * Visibility of each sidebar item according to the user's gates.
     *
     * @return array<string, array{visible: bool}>
     */
    public function navigation(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return collect(self::NAVIGATION_ROUTES)
            ->map(fn (string $routeName): array => [
                'visible' => $user->can(PanelRoutes::gateFor($routeName)),
            ])
            ->all();
    }

    /**
     * @return array{name: string, role: string, condominium: string|null}|null
     */
    public function footer(): ?array
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        return [
            'name' => $user->name,
            'role' => $user->role->name,
            'condominium' => $this->currentCondominium->get()?->name,
        ];
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
