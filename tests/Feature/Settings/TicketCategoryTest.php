<?php

use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('settings lists name, slug, active toggle and ticket count of each category', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador', 'slug' => 'elevador']);
    Ticket::factory()->count(2)->for($this->condominium)->for($category, 'category')->create();
    TicketCategory::factory()->inactive()->for($this->condominium)->create(['name' => 'Jardinagem', 'slug' => 'jardinagem']);
    TicketCategory::factory()->create(['name' => 'Piscina', 'slug' => 'piscina']);

    $this->actingAs($this->sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('Categorias de chamado')
        ->assertSeeInOrder(['Nome', 'Slug', 'Chamados', 'Ativa'])
        ->assertSeeInOrder(['Elevador', 'elevador', '2', 'aria-checked="true"'], false)
        ->assertSeeInOrder(['Jardinagem', 'jardinagem', '0', 'aria-checked="false"'], false)
        ->assertDontSee('Piscina');
});

test('creating generates the slug from the name', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('create')
        ->set('name', 'Portão Eletrônico')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('portao-eletronico');

    $category = TicketCategory::sole();

    expect($category->condominium_id)->toBe($this->condominium->id)
        ->and($category->name)->toBe('Portão Eletrônico')
        ->and($category->slug)->toBe('portao-eletronico')
        ->and($category->is_active)->toBeTrue();
});

test('duplicated name is rejected', function () {
    TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador', 'slug' => 'elevador']);
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('create')
        ->set('name', 'Elevador')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Já existe uma categoria com este nome.');

    expect(TicketCategory::count())->toBe(1);
});

test('name producing an existing slug is rejected', function () {
    TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elétrica', 'slug' => 'eletrica']);
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('create')
        ->set('name', 'Eletrica')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(TicketCategory::count())->toBe(1);
});

test('the same name is accepted in another condominium', function () {
    TicketCategory::factory()->create(['name' => 'Elevador', 'slug' => 'elevador']);
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('create')
        ->set('name', 'Elevador')
        ->call('save')
        ->assertHasNoErrors();

    expect(TicketCategory::withoutGlobalScopes()->where('slug', 'elevador')->count())->toBe(2);
});

test('deleting a category in use is blocked', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create();
    Ticket::factory()->for($this->condominium)->for($category, 'category')->create();
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('delete', $category->id)
        ->assertDispatched('toast', type: 'error', message: 'Categoria em uso: inative em vez de excluir.');

    expect(TicketCategory::find($category->id))->not->toBeNull();
});

test('deleting an unused category works', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create();
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('delete', $category->id)
        ->assertDispatched('toast', type: 'success', message: 'Categoria excluída.');

    expect(TicketCategory::find($category->id))->toBeNull();
});

test('deactivating a category in use works', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create();
    Ticket::factory()->for($this->condominium)->for($category, 'category')->create();
    actingInPanel($this->sindico);

    $component = Livewire::test('settings.ticket-categories')->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeTrue();
});

test('renaming keeps the slug', function () {
    $category = TicketCategory::factory()->for($this->condominium)->create(['name' => 'Elevador', 'slug' => 'elevador']);
    actingInPanel($this->sindico);

    Livewire::test('settings.ticket-categories')
        ->call('edit', $category->id)
        ->assertSet('name', 'Elevador')
        ->set('name', 'Elevadores')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh())
        ->name->toBe('Elevadores')
        ->slug->toBe('elevador');
});

test('a category of another condominium cannot be changed', function () {
    $otherCategory = TicketCategory::factory()->create();
    actingInPanel($this->sindico);

    expect(fn () => Livewire::test('settings.ticket-categories')->call('delete', $otherCategory->id))
        ->toThrow(ModelNotFoundException::class);
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('settings'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('settings.ticket-categories')->assertForbidden();
});
