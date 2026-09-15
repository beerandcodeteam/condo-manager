<?php

use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('creates a super admin without condominium asking for the hidden password', function () {
    $this->artisan('app:create-super-admin', ['--name' => 'Ana Lima', '--email' => 'ana@example.com'])
        ->expectsQuestion('Senha (mínimo 8 caracteres)', 'segredo-forte')
        ->assertSuccessful();

    $user = User::where('email', 'ana@example.com')->sole();

    expect($user->name)->toBe('Ana Lima')
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->condominium_id)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('segredo-forte', $user->password))->toBeTrue();
});

test('password shorter than 8 characters fails without creating the user', function () {
    $this->artisan('app:create-super-admin', ['--name' => 'Ana Lima', '--email' => 'ana@example.com'])
        ->expectsQuestion('Senha (mínimo 8 caracteres)', 'curta')
        ->assertFailed();

    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
});

test('duplicated email fails without creating a user', function () {
    User::factory()->superAdmin()->create(['email' => 'ana@example.com']);

    $this->artisan('app:create-super-admin', ['--name' => 'Outra Ana', '--email' => 'ana@example.com', '--password' => 'segredo-forte'])
        ->assertFailed();

    expect(User::where('email', 'ana@example.com')->count())->toBe(1)
        ->and(User::where('name', 'Outra Ana')->exists())->toBeFalse();
});
