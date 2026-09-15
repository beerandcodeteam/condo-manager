<?php

use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
});

test('login page renders email, password and remember me in the guest layout', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<title>Entrar · Síndico Conversacional</title>', false)
        ->assertSee('w-[360px]', false)
        ->assertSee('wire:model="email"', false)
        ->assertSee('wire:model="password"', false)
        ->assertSee('Lembrar de mim');
});

test('active sindico logs in and lands on the overview', function () {
    $user = User::factory()->sindico()->create(['email' => 'renata@example.com']);

    Livewire::test('pages::auth.login')
        ->set('email', 'renata@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Visão geral');
});

test('login redirects to the intended url', function () {
    User::factory()->sindico()->create(['email' => 'renata@example.com']);
    session()->put('url.intended', '/intended-page');

    Livewire::test('pages::auth.login')
        ->set('email', 'renata@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/intended-page');
});

test('wrong password or unknown email shows the same generic error', function (string $email, string $password) {
    User::factory()->sindico()->create(['email' => 'renata@example.com']);

    Livewire::test('pages::auth.login')
        ->set('email', $email)
        ->set('password', $password)
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('E-mail ou senha inválidos.');

    $this->assertGuest();
})->with([
    'wrong password' => ['renata@example.com', 'wrong-password'],
    'unknown email' => ['ninguem@example.com', 'password'],
]);

test('inactive user cannot log in', function () {
    User::factory()->sindico()->inactive()->create(['email' => 'renata@example.com']);

    Livewire::test('pages::auth.login')
        ->set('email', 'renata@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('E-mail ou senha inválidos.');

    $this->assertGuest();
});

test('the sixth attempt within one minute is blocked', function () {
    User::factory()->sindico()->create(['email' => 'renata@example.com']);

    $component = Livewire::test('pages::auth.login')->set('email', 'renata@example.com');

    foreach (range(1, 5) as $attempt) {
        $component->set('password', 'wrong-password')->call('login')->assertSee('E-mail ou senha inválidos.');
    }

    $component->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSeeText('Muitas tentativas. Tente novamente em')
        ->assertSeeText('segundos.')
        ->assertNoRedirect();

    $this->assertGuest();
});

test('logout ends the session and regenerates the csrf token', function () {
    $user = User::factory()->sindico()->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'old-token'])
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->token())->not->toBe('old-token');
});

test('guest visiting the dashboard is redirected to login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('home redirects authenticated users to the dashboard', function () {
    $this->actingAs(User::factory()->sindico()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('login page has no password recovery link', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Esqueci');
});
