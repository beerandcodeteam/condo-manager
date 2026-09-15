<?php

use App\Models\Condominium;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->superAdmin = User::factory()->superAdmin()->create(['name' => 'Admin da Plataforma']);
    [$this->condominiumA, $this->condominiumB] = Condominium::factory()->count(2)->sequence(
        ['name' => 'Residencial Aurora'],
        ['name' => 'Villa Serena'],
    )->create();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function newUserForm(array $overrides = []): array
{
    return [
        'name' => 'José Carvalho',
        'email' => 'jose@example.com',
        'role' => Role::ZELADOR,
        'condominiumId' => (string) test()->condominiumA->id,
        'password' => 'senha-segura',
        'password_confirmation' => 'senha-segura',
        ...$overrides,
    ];
}

test('super admin creates a zelador in A', function () {
    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('create')
        ->fill(newUserForm())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('José Carvalho');

    $user = User::where('email', 'jose@example.com')->firstOrFail();

    expect($user->isZelador())->toBeTrue()
        ->and($user->condominium_id)->toBe($this->condominiumA->id)
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('senha-segura', $user->password))->toBeTrue();
});

test('a condominium accepts several sindicos and zeladores', function () {
    User::factory()->sindico()->for($this->condominiumA)->create();
    User::factory()->zelador()->for($this->condominiumA)->create();

    $component = Livewire::actingAs($this->superAdmin)->test('pages::platform.users');

    $component->call('create')->fill(newUserForm(['email' => 'sindico2@example.com', 'role' => Role::SINDICO]))->call('save')->assertHasNoErrors();
    $component->call('create')->fill(newUserForm(['email' => 'zelador2@example.com']))->call('save')->assertHasNoErrors();

    expect(User::where('condominium_id', $this->condominiumA->id)->count())->toBe(4);
});

test('duplicated email is rejected', function () {
    User::factory()->sindico()->for($this->condominiumB)->create(['email' => 'jose@example.com']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('create')
        ->fill(newUserForm())
        ->call('save')
        ->assertHasErrors(['email'])
        ->assertSee('Este e-mail já está cadastrado.');

    expect(User::where('email', 'jose@example.com')->count())->toBe(1);
});

test('super_admin role is rejected', function () {
    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('create')
        ->fill(newUserForm(['role' => Role::SUPER_ADMIN]))
        ->call('save')
        ->assertHasErrors(['role']);

    expect(User::where('email', 'jose@example.com')->exists())->toBeFalse();
});

test('condominium is required', function () {
    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('create')
        ->fill(newUserForm(['condominiumId' => '']))
        ->call('save')
        ->assertHasErrors(['condominiumId' => 'required']);

    expect(User::where('email', 'jose@example.com')->exists())->toBeFalse();
});

test('initial password needs at least 8 characters and confirmation', function (string $password, string $confirmation) {
    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('create')
        ->fill(newUserForm(['password' => $password, 'password_confirmation' => $confirmation]))
        ->call('save')
        ->assertHasErrors(['password']);
})->with([
    'too short' => ['curta', 'curta'],
    'not confirmed' => ['senha-segura', 'outra-senha'],
]);

test('super admin edits name, email, role and condominium', function () {
    $user = User::factory()->sindico()->for($this->condominiumA)->create();

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('edit', $user->id)
        ->assertSet('role', Role::SINDICO)
        ->set('name', 'Renata Moura')
        ->set('email', 'renata@example.com')
        ->set('role', Role::ZELADOR)
        ->set('condominiumId', (string) $this->condominiumB->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh())
        ->name->toBe('Renata Moura')
        ->email->toBe('renata@example.com')
        ->condominium_id->toBe($this->condominiumB->id)
        ->isZelador()->toBeTrue();
});

test('super admin users are not listed nor editable', function () {
    $otherSuperAdmin = User::factory()->superAdmin()->create(['name' => 'Outro Admin']);
    User::factory()->sindico()->for($this->condominiumA)->create(['name' => 'Renata Moura']);

    $component = Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->assertSee('Renata Moura')
        ->assertDontSee('Outro Admin')
        ->assertDontSee('Admin da Plataforma');

    expect(fn () => $component->call('edit', $otherSuperAdmin->id))->toThrow(ModelNotFoundException::class);
});

test('filters by condominium and role', function () {
    User::factory()->sindico()->for($this->condominiumA)->create(['name' => 'Renata Moura']);
    User::factory()->zelador()->for($this->condominiumA)->create(['name' => 'José Carvalho']);
    User::factory()->sindico()->for($this->condominiumB)->create(['name' => 'Marcos Lima']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->set('condominiumFilter', (string) $this->condominiumA->id)
        ->assertSee('Renata Moura')
        ->assertSee('José Carvalho')
        ->assertDontSee('Marcos Lima')
        ->set('roleFilter', Role::ZELADOR)
        ->assertSee('José Carvalho')
        ->assertDontSee('Renata Moura');
});

test('list shows name, email, role, condominium and status', function () {
    User::factory()->zelador()->inactive()->for($this->condominiumA)->create(['name' => 'José Carvalho', 'email' => 'jose@example.com']);

    $this->actingAs($this->superAdmin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertSeeInOrder(['Nome', 'E-mail', 'Papel', 'Condomínio', 'Status'])
        ->assertSeeInOrder(['José Carvalho', 'jose@example.com', 'Zelador', 'Residencial Aurora', 'Inativo', 'Ativar']);
});

test('deactivating a user prevents login', function () {
    $user = User::factory()->sindico()->for($this->condominiumA)->create(['email' => 'renata@example.com']);

    Livewire::actingAs($this->superAdmin)
        ->test('pages::platform.users')
        ->call('toggleActive', $user->id)
        ->assertSee('Inativo');

    expect($user->fresh()->is_active)->toBeFalse();

    auth()->logout();

    Livewire::test('pages::auth.login')
        ->set('email', 'renata@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email']);

    $this->assertGuest();
});

test('sindico receives 403', function () {
    $sindico = User::factory()->sindico()->for($this->condominiumA)->create();

    $this->actingAs($sindico)
        ->get(route('users.index'))
        ->assertForbidden();

    Livewire::actingAs($sindico)
        ->test('pages::platform.users')
        ->assertForbidden();
});
