<?php

use App\Http\Middleware\SetPanelCondominium;
use App\Models\Condominium;
use App\Models\TicketCategory;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->superAdmin = User::factory()->superAdmin()->create();
});

test('super admin without a selected condominium opens the condominium list', function () {
    $aurora = Condominium::factory()->create(['name' => 'Residencial Aurora', 'city' => 'Curitiba · PR']);
    Unit::factory()->count(3)->for($aurora)->create();
    User::factory()->sindico()->count(2)->for($aurora)->create();

    $this->actingAs($this->superAdmin)
        ->get(route('condominiums.index'))
        ->assertOk()
        ->assertSee('Condomínios')
        ->assertSeeInOrder(['Nome', 'Cidade', 'Unidades', 'Síndicos', 'Criado em'])
        ->assertSeeInOrder(['Residencial Aurora', 'Curitiba · PR', '3', '2', $aurora->created_at->timezone('America/Sao_Paulo')->format('d/m/Y')]);
});

test('unit counts ignore the condominium selected in the session', function () {
    $aurora = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $serena = Condominium::factory()->create(['name' => 'Villa Serena']);
    Unit::factory()->count(4)->for($aurora)->create();

    $this->actingAs($this->superAdmin)
        ->withSession([SetPanelCondominium::SESSION_KEY => $serena->id])
        ->get(route('condominiums.index'))
        ->assertOk()
        ->assertSee('data-condominium-card', false)
        ->assertSeeInOrder(['Residencial Aurora', '4', 'Villa Serena', '0']);
});

test('super admin creates a condominium with the 6 default categories and selects it', function () {
    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.condominiums')
        ->call('create')
        ->set('name', 'Villa Serena')
        ->set('city', 'Florianópolis · SC')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('condominiums.index'));

    $condominium = Condominium::where('name', 'Villa Serena')->firstOrFail();

    expect($condominium->city)->toBe('Florianópolis · SC')
        ->and($condominium->ticketCategories()->orderBy('id')->pluck('name', 'slug')->all())->toBe(TicketCategory::DEFAULTS)
        ->and(session(SetPanelCondominium::SESSION_KEY))->toBe($condominium->id);
});

test('super admin edits the name and city of a condominium', function () {
    $condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.condominiums')
        ->call('edit', $condominium->id)
        ->assertSet('name', 'Residencial Aurora')
        ->set('name', 'Residencial Aurora II')
        ->set('city', 'Curitiba · PR')
        ->call('save')
        ->assertHasNoErrors();

    expect($condominium->fresh())
        ->name->toBe('Residencial Aurora II')
        ->city->toBe('Curitiba · PR');
});

test('duplicated name and empty name are rejected', function (string $name, string $message) {
    Condominium::factory()->create(['name' => 'Residencial Aurora']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.condominiums')
        ->call('create')
        ->set('name', $name)
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee($message);

    expect(Condominium::count())->toBe(1);
})->with([
    'duplicated' => ['Residencial Aurora', 'Já existe um condomínio com este nome.'],
    'empty' => ['', 'O campo nome é obrigatório.'],
]);

test('search filters the list by name', function () {
    Condominium::factory()->create(['name' => 'Residencial Aurora']);
    Condominium::factory()->create(['name' => 'Villa Serena']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.condominiums')
        ->set('search', 'aurora')
        ->assertSee('Residencial Aurora')
        ->assertDontSee('Villa Serena');
});

test('the list is paginated with 20 condominiums per page', function () {
    Condominium::factory()->count(21)->sequence(fn ($sequence) => ['name' => sprintf('Condomínio %02d', $sequence->index + 1)])->create();

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.condominiums')
        ->assertSee('Condomínio 20')
        ->assertDontSee('Condomínio 21');
});

test('sindico receives 403', function () {
    $sindico = User::factory()->sindico()->create();

    $this->actingAs($sindico)
        ->get(route('condominiums.index'))
        ->assertForbidden();

    Livewire::actingAs($sindico)
        ->test('pages::platform.condominiums')
        ->assertForbidden();
});
