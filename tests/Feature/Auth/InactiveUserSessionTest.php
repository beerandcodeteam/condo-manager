<?php

use App\Models\User;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
});

test('user deactivated during the session is logged out on the next request', function () {
    $user = User::factory()->sindico()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->update(['is_active' => false]);

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Seu acesso foi desativado.');

    $this->assertGuest();

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('login page shows the deactivated access message', function () {
    $user = User::factory()->sindico()->inactive()->create();

    $this->actingAs($user)
        ->followingRedirects()
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Seu acesso foi desativado.');
});
