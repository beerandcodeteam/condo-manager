<?php

use App\Models\Condominium;
use App\Models\User;
use App\Support\Panel\PanelRoutes;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    [$this->condominiumA, $this->condominiumB] = Condominium::factory()->count(2)->sequence(
        ['name' => 'Residencial Aurora', 'city' => 'Curitiba · PR'],
        ['name' => 'Villa Serena', 'city' => 'Florianópolis · SC'],
    )->create();
});

test('super admin switches to B and the overview shows the name of B', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $this->condominiumA->id])
        ->post(route('condominium.switch', $this->condominiumB))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('current_condominium_id', $this->condominiumB->id);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-condominium-card', false)
        ->assertSeeInOrder(['data-condominium-card', 'Villa Serena'], false)
        ->assertSee('Super admin · Villa Serena');
});

test('super admin without selection can pick a condominium', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(route('condominium.switch', $this->condominiumA))
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk()->assertSee('Super admin · Residencial Aurora');
});

test('switcher lists every condominium with initials and city, highlights the current and links to new condominium', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $this->condominiumB->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-condominium-switcher', false)
        ->assertSee('action="'.route('condominium.switch', $this->condominiumA).'"', false)
        ->assertSee('action="'.route('condominium.switch', $this->condominiumB).'"', false)
        ->assertSeeInOrder(['>RA</span>', 'Residencial Aurora', 'Curitiba · PR', '>VS</span>', 'Villa Serena', 'Florianópolis · SC'], false)
        ->assertSee('href="'.PanelRoutes::condominiumsUrl().'"', false)
        ->assertSee('+ Novo condomínio');

    $html = $this->get(route('dashboard'))->getContent();

    expect($html)->toMatch('/aria-current="true"[^>]*bg-tag-grey-bg[^>]*data-condominium-option[^>]*>.*?Villa Serena/s')
        ->not->toMatch('/aria-current="true"[^>]*data-condominium-option[^>]*>.*?Residencial Aurora.*?Villa Serena/s');
});

test('sindico and zelador do not see the switcher', function (string $state) {
    $user = User::factory()->{$state}()->for($this->condominiumA)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-condominium-switcher', false)
        ->assertDontSee('+ Novo condomínio')
        ->assertDontSee('Villa Serena');
})->with(['sindico', 'zelador']);

test('sindico calling the switch receives 403', function () {
    $sindico = User::factory()->sindico()->for($this->condominiumA)->create();

    $this->actingAs($sindico)
        ->post(route('condominium.switch', $this->condominiumB))
        ->assertForbidden()
        ->assertSessionMissing('current_condominium_id');
});

test('zelador calling the switch receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominiumA)->create();

    $this->actingAs($zelador)
        ->post(route('condominium.switch', $this->condominiumB))
        ->assertForbidden();
});

test('guest calling the switch is redirected to login', function () {
    $this->post(route('condominium.switch', $this->condominiumB))->assertRedirect(route('login'));
});
