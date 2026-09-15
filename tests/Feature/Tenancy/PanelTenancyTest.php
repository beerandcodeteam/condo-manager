<?php

use App\Models\Condominium;
use App\Models\Resident;
use App\Models\User;
use App\Support\Panel\PanelRoutes;
use App\Support\Tenancy\CurrentCondominium;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    Route::middleware(['web', 'panel', 'panel.condominium'])
        ->get('/_panel-tenancy-test/residents/{resident}', fn (Resident $resident) => $resident->name);

    Route::middleware(['web', 'panel', 'panel.condominium'])
        ->get('/_panel-tenancy-test/residents', fn () => Resident::orderBy('name')->pluck('name')->implode(','));

    [$this->condominiumA, $this->condominiumB] = Condominium::factory()->count(2)->sequence(
        ['name' => 'Residencial Aurora'],
        ['name' => 'Villa Serena'],
    )->create();
});

test('sindico of A accessing a resident of B by url receives 404', function () {
    $residentOfA = Resident::factory()->for($this->condominiumA)->create();
    $residentOfB = Resident::factory()->for($this->condominiumB)->create();
    $sindico = User::factory()->sindico()->for($this->condominiumA)->create();

    $this->actingAs($sindico)
        ->get("/_panel-tenancy-test/residents/{$residentOfB->id}")
        ->assertNotFound();

    $this->actingAs($sindico)
        ->get("/_panel-tenancy-test/residents/{$residentOfA->id}")
        ->assertOk()
        ->assertSee($residentOfA->name, false);
});

test('panel queries only return records of the user condominium', function () {
    Resident::factory()->for($this->condominiumA)->create(['name' => 'Ana']);
    Resident::factory()->for($this->condominiumB)->create(['name' => 'Bruno']);
    $zelador = User::factory()->zelador()->for($this->condominiumA)->create();

    $this->actingAs($zelador)
        ->get('/_panel-tenancy-test/residents')
        ->assertOk()
        ->assertSeeText('Ana')
        ->assertDontSeeText('Bruno');
});

test('super admin without a selected condominium is redirected to the condominium list', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->get('/dashboard')
        ->assertRedirect(PanelRoutes::condominiumsUrl());

    expect(app(CurrentCondominium::class)->id())->toBeNull();
});

test('super admin with a stale selection is redirected and the session key is cleared', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => 999999])
        ->get('/dashboard')
        ->assertRedirect(PanelRoutes::condominiumsUrl())
        ->assertSessionMissing('current_condominium_id');
});

test('super admin uses the condominium selected in the session', function () {
    $residentOfB = Resident::factory()->for($this->condominiumB)->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->withSession(['current_condominium_id' => $this->condominiumB->id])
        ->get("/_panel-tenancy-test/residents/{$residentOfB->id}")
        ->assertOk();

    expect(app(CurrentCondominium::class)->id())->toBe($this->condominiumB->id);
});

test('sindico with the condominium of B in the session keeps seeing A', function () {
    $residentOfB = Resident::factory()->for($this->condominiumB)->create();
    $sindico = User::factory()->sindico()->for($this->condominiumA)->create();

    $this->actingAs($sindico)
        ->withSession(['current_condominium_id' => $this->condominiumB->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Residencial Aurora')
        ->assertDontSee('Villa Serena');

    expect(app(CurrentCondominium::class)->id())->toBe($this->condominiumA->id);

    $this->actingAs($sindico)
        ->withSession(['current_condominium_id' => $this->condominiumB->id])
        ->get("/_panel-tenancy-test/residents/{$residentOfB->id}")
        ->assertNotFound();
});
