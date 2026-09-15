<?php

use App\Models\Condominium;
use App\Models\User;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
});

test('zelador sees only overview, escalations and tickets', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-nav-item="dashboard"', false)
        ->assertSee('data-nav-item="escalations"', false)
        ->assertSee('data-nav-item="tickets"', false)
        ->assertDontSee('data-nav-item="reservations"', false)
        ->assertDontSee('data-nav-item="notices"', false)
        ->assertDontSee('data-nav-item="rule-documents"', false)
        ->assertDontSee('data-nav-item="residents"', false)
        ->assertDontSee('data-nav-item="settings"', false)
        ->assertDontSee('data-nav-item="condominiums"', false)
        ->assertDontSee('data-nav-item="users"', false)
        ->assertDontSee('Comunicados')
        ->assertDontSee('Configurações');
});

test('sindico sees everything except the platform group', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->actingAs($sindico)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Escalonamentos')
        ->assertSee('Chamados')
        ->assertSee('Reservas')
        ->assertSee('Comunicados')
        ->assertSee('Regimento')
        ->assertSee('Moradores')
        ->assertSee('Configurações')
        ->assertDontSee('Plataforma')
        ->assertDontSee('Condomínios')
        ->assertDontSee('data-nav-item="users"', false);
});

test('super admin sees every item including the platform group', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Comunicados')
        ->assertSee('Configurações')
        ->assertSee('Plataforma')
        ->assertSee('Condomínios')
        ->assertSee('Usuários');
});

test('footer shows the role in portuguese and the current condominium name', function (string $state, string $roleLabel) {
    $user = User::factory()->{$state}()->create([
        'name' => 'Renata Moura',
        'condominium_id' => $state === 'superAdmin' ? null : $this->condominium->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Renata Moura')
        ->assertSee("{$roleLabel} · Residencial Aurora");
})->with([
    'super admin' => ['superAdmin', 'Super admin'],
    'síndico' => ['sindico', 'Síndico'],
    'zelador' => ['zelador', 'Zelador'],
]);
