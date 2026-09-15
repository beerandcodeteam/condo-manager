<?php

use App\Models\Condominium;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('role helpers reflect the user role', function (string $roleSlug, bool $isSuperAdmin, bool $isSindico, bool $isZelador) {
    $user = User::create([
        'name' => 'Renata Moura',
        'email' => 'renata@example.com',
        'password' => 'password',
        'role_id' => Role::idFor($roleSlug),
    ]);

    expect($user->isSuperAdmin())->toBe($isSuperAdmin)
        ->and($user->isSindico())->toBe($isSindico)
        ->and($user->isZelador())->toBe($isZelador)
        ->and($user->role->slug)->toBe($roleSlug);
})->with([
    'super admin' => [Role::SUPER_ADMIN, true, false, false],
    'síndico' => [Role::SINDICO, false, true, false],
    'zelador' => [Role::ZELADOR, false, false, true],
]);

test('user casts is_active to boolean, hashes the password and belongs to a condominium', function () {
    $condominium = Condominium::create(['name' => 'Residencial Aurora']);

    $user = User::create([
        'name' => 'José Carvalho',
        'email' => 'jose@example.com',
        'password' => 'password',
        'role_id' => Role::idFor(Role::ZELADOR),
        'condominium_id' => $condominium->id,
    ])->fresh();

    expect($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe('password')
        ->and(password_verify('password', $user->password))->toBeTrue()
        ->and($user->condominium->is($condominium))->toBeTrue();
});
