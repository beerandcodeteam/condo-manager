<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Panel\PanelRoutes;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
});

/**
 * Expected access per gate: [super_admin, sindico, zelador].
 *
 * @return array<string, array{string, string, bool}>
 */
function roleGateMatrix(): array
{
    $expectations = [
        'platform.manage' => [true, false, false],
        'integration.manage' => [true, false, false],
        'condominium.settings' => [true, true, false],
        'residents.manage' => [true, true, false],
        'categories.manage' => [true, true, false],
        'knowledge.manage' => [true, true, false],
        'reservations.manage' => [true, true, false],
        'webhooks.failures' => [true, true, false],
        'tickets.operate' => [true, true, true],
        'escalations.operate' => [true, true, true],
        'dashboard.view' => [true, true, true],
        'escalations.reassign' => [true, true, false],
    ];

    $dataset = [];

    foreach ($expectations as $gate => $allowedByRole) {
        foreach ([Role::SUPER_ADMIN, Role::SINDICO, Role::ZELADOR] as $index => $roleSlug) {
            $verb = $allowedByRole[$index] ? 'allows' : 'denies';
            $dataset["{$roleSlug} {$verb} {$gate}"] = [$roleSlug, $gate, $allowedByRole[$index]];
        }
    }

    return $dataset;
}

test('gate result for each role', function (string $roleSlug, string $gate, bool $expected) {
    $user = User::factory()->state(['role_id' => Role::idFor($roleSlug)])->create();

    expect(Gate::forUser($user)->allows($gate))->toBe($expected);
})->with(roleGateMatrix());

test('panel routes denied by the gate respond 403', function () {
    Route::middleware(['web', 'panel'])
        ->get('/_role-test/knowledge', fn () => 'ok')
        ->can('knowledge.manage');

    $this->actingAs(User::factory()->zelador()->create())
        ->get('/_role-test/knowledge')
        ->assertForbidden();

    $this->actingAs(User::factory()->sindico()->create())
        ->get('/_role-test/knowledge')
        ->assertOk();
});

test('registered panel routes use the gate from the central route map', function () {
    $registeredPanelRoutes = collect(PanelRoutes::GATES)
        ->filter(fn (string $gate, string $routeName): bool => Route::has($routeName));

    expect($registeredPanelRoutes)->not->toBeEmpty();

    $registeredPanelRoutes->each(function (string $gate, string $routeName): void {
        expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->toContain("can:{$gate}");
    });
});

test('every gate referenced by the route map is defined', function () {
    collect(PanelRoutes::GATES)->unique()->each(
        fn (string $gate) => expect(Gate::has($gate))->toBeTrue(),
    );
});

test('unmapped panel route names are rejected', function () {
    PanelRoutes::gateFor('unknown.route');
})->throws(InvalidArgumentException::class);
