<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetPanelCondominium;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\CurrentCondominium;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Roles allowed by each panel gate.
     *
     * @var array<string, list<string>>
     */
    public const GATE_ROLES = [
        'platform.manage' => [Role::SUPER_ADMIN],
        'integration.manage' => [Role::SUPER_ADMIN],
        'condominium.settings' => [Role::SUPER_ADMIN, Role::SINDICO],
        'residents.manage' => [Role::SUPER_ADMIN, Role::SINDICO],
        'categories.manage' => [Role::SUPER_ADMIN, Role::SINDICO],
        'knowledge.manage' => [Role::SUPER_ADMIN, Role::SINDICO],
        'reservations.manage' => [Role::SUPER_ADMIN, Role::SINDICO],
        'webhooks.failures' => [Role::SUPER_ADMIN, Role::SINDICO],
        'tickets.operate' => [Role::SUPER_ADMIN, Role::SINDICO, Role::ZELADOR],
        'escalations.operate' => [Role::SUPER_ADMIN, Role::SINDICO, Role::ZELADOR],
        'dashboard.view' => [Role::SUPER_ADMIN, Role::SINDICO, Role::ZELADOR],
        'escalations.reassign' => [Role::SUPER_ADMIN, Role::SINDICO],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentCondominium::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configurePanel();
    }

    /**
     * Define the role-based gates of the panel.
     */
    protected function configureGates(): void
    {
        foreach (self::GATE_ROLES as $ability => $roleSlugs) {
            Gate::define($ability, fn (User $user): bool => $user->hasAnyRole(...$roleSlugs));
        }
    }

    /**
     * Re-apply the panel middleware on Livewire update requests.
     */
    protected function configurePanel(): void
    {
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            SetPanelCondominium::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
