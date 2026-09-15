<?php

use App\Models\Condominium;
use App\Models\User;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
});

test('dashboard renders the overview title with an empty state in the panel layout', function (string $state) {
    $condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $user = User::factory()->{$state}()->for($condominium)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<title>Visão geral · Síndico Conversacional</title>', false)
        ->assertSee('<h1 class="min-w-0 flex-1 truncate text-[17px] font-semibold tracking-[-0.01em]">Visão geral</h1>', false)
        ->assertSee('w-[236px]', false)
        ->assertSee('Nenhum dado para exibir ainda')
        ->assertSee('Residencial Aurora');
})->with(['sindico', 'zelador']);

test('dashboard route is protected by the dashboard.view gate', function () {
    expect(app('router')->getRoutes()->getByName('dashboard')->gatherMiddleware())
        ->toContain('can:dashboard.view');
});
