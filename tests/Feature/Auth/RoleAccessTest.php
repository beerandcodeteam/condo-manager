<?php

use App\Models\Condominium;
use App\Models\Role;
use App\Models\RuleDocument;
use App\Models\TicketPhoto;
use App\Models\User;
use App\Services\RuleDocuments\RuleDocumentService;
use App\Services\Tickets\TicketService;
use App\Support\Panel\PanelRoutes;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

/**
 * Expected status of every panel route per role: [super_admin, sindico, zelador].
 *
 * @return array<string, array{int, int, int}>
 */
function panelRouteStatuses(): array
{
    return [
        'dashboard' => [200, 200, 200],
        'escalations.index' => [200, 200, 200],
        'tickets.index' => [200, 200, 200],
        'tickets.photos.show' => [200, 200, 200],
        'reservations.index' => [200, 200, 403],
        'notices.index' => [200, 200, 403],
        'rule-documents.index' => [200, 200, 403],
        'rule-documents.download' => [200, 200, 403],
        'residents.index' => [200, 200, 403],
        'settings' => [200, 200, 403],
        'condominiums.index' => [200, 403, 403],
        'users.index' => [200, 403, 403],
        'condominium.switch' => [302, 403, 403],
    ];
}

/**
 * @return array<string, array{string, string, int}>
 */
function panelRouteRoleMatrix(): array
{
    $dataset = [];

    foreach (panelRouteStatuses() as $routeName => $statusByRole) {
        foreach ([Role::SUPER_ADMIN, Role::SINDICO, Role::ZELADOR] as $index => $roleSlug) {
            $dataset["{$roleSlug} on {$routeName} gets {$statusByRole[$index]}"] = [$roleSlug, $routeName, $statusByRole[$index]];
        }
    }

    return $dataset;
}

/**
 * Method and URL of a panel route, creating the records its parameters point to in the condominium.
 *
 * @return array{string, string}
 */
function panelRouteRequest(string $routeName, Condominium $condominium): array
{
    return match ($routeName) {
        'tickets.photos.show' => ['GET', route($routeName, tap(TicketPhoto::factory()->for($condominium)->create(), function (TicketPhoto $photo): void {
            Storage::disk(TicketService::PHOTO_DISK)->put($photo->file_path, 'jpeg');
        }))],
        'rule-documents.download' => ['GET', route($routeName, tap(RuleDocument::factory()->publicado()->for($condominium)->create(), function (RuleDocument $document): void {
            Storage::disk(RuleDocumentService::DISK)->put($document->file_path, '%PDF-1.4');
        }))],
        'condominium.switch' => ['POST', route($routeName, $condominium)],
        default => ['GET', route($routeName)],
    };
}

test('every panel route is covered by the route × role dataset', function () {
    expect(array_keys(panelRouteStatuses()))->toEqualCanonicalizing(array_keys(PanelRoutes::GATES));
});

test('panel route responds with the expected status for each role', function (string $roleSlug, string $routeName, int $expectedStatus) {
    Storage::fake(TicketService::PHOTO_DISK);
    Storage::fake(RuleDocumentService::DISK);

    $condominium = Condominium::factory()->create();
    $user = User::factory()->state([
        'role_id' => Role::idFor($roleSlug),
        'condominium_id' => $roleSlug === Role::SUPER_ADMIN ? null : $condominium->id,
    ])->create();
    [$method, $url] = panelRouteRequest($routeName, $condominium);

    $this->actingAs($user)
        ->withSession(['current_condominium_id' => $condominium->id])
        ->call($method, $url)
        ->assertStatus($expectedStatus);
})->with(panelRouteRoleMatrix());

test('panel records of another condominium respond 404', function (string $roleSlug, string $routeName) {
    Storage::fake(TicketService::PHOTO_DISK);
    Storage::fake(RuleDocumentService::DISK);

    [$condominiumA, $condominiumB] = Condominium::factory()->count(2)->create();
    $user = User::factory()->state([
        'role_id' => Role::idFor($roleSlug),
        'condominium_id' => $roleSlug === Role::SUPER_ADMIN ? null : $condominiumA->id,
    ])->create();
    [, $urlOfB] = panelRouteRequest($routeName, $condominiumB);

    $this->actingAs($user)
        ->withSession(['current_condominium_id' => $condominiumA->id])
        ->get($urlOfB)
        ->assertNotFound();
})->with([
    'super admin · ticket photo' => [Role::SUPER_ADMIN, 'tickets.photos.show'],
    'síndico · ticket photo' => [Role::SINDICO, 'tickets.photos.show'],
    'zelador · ticket photo' => [Role::ZELADOR, 'tickets.photos.show'],
    'super admin · rule document pdf' => [Role::SUPER_ADMIN, 'rule-documents.download'],
    'síndico · rule document pdf' => [Role::SINDICO, 'rule-documents.download'],
]);
