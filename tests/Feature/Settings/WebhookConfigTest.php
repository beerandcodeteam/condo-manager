<?php

use App\Models\Condominium;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $this->superAdmin = User::factory()->superAdmin()->create();
});

test('without url the card shows webhook not configured', function () {
    $this->actingAs($this->superAdmin)
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('data-settings-card="webhook"', false)
        ->assertSee('Webhook não configurado')
        ->assertSee('Gerar segredo');
});

test('http url is rejected outside the local environment', function () {
    actingInPanel($this->superAdmin, $this->condominium);

    Livewire::test('settings.webhook')
        ->set('url', 'http://n8n.example.com/webhook/aurora')
        ->call('saveUrl')
        ->assertHasErrors(['url'])
        ->assertSee('Informe uma URL válida começando com https://.');

    expect($this->condominium->fresh()->webhook_url)->toBeNull();
});

test('http url is accepted in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');
    actingInPanel($this->superAdmin, $this->condominium);

    Livewire::test('settings.webhook')
        ->set('url', 'http://localhost:5678/webhook/aurora')
        ->call('saveUrl')
        ->assertHasNoErrors();

    expect($this->condominium->fresh()->webhook_url)->toBe('http://localhost:5678/webhook/aurora');
});

test('https url is accepted', function () {
    actingInPanel($this->superAdmin, $this->condominium);

    Livewire::test('settings.webhook')
        ->set('url', 'https://n8n.example.com/webhook/aurora')
        ->call('saveUrl')
        ->assertHasNoErrors()
        ->assertSee('Configurado')
        ->assertDontSee('Webhook não configurado');

    expect($this->condominium->fresh()->webhook_url)->toBe('https://n8n.example.com/webhook/aurora');
});

test('generating a secret stores a 64 character secret encrypted and shows it once', function () {
    actingInPanel($this->superAdmin, $this->condominium);

    $component = Livewire::test('settings.webhook')
        ->call('regenerateSecret')
        ->assertSet('showSecret', true);

    $secret = $component->get('plainTextSecret');
    $condominium = $this->condominium->fresh();

    expect($secret)->toHaveLength(64)
        ->and($condominium->webhook_secret)->toBe($secret)
        ->and($condominium->getRawOriginal('webhook_secret'))->not->toBe($secret);

    $component->assertSee($secret)
        ->call('dismissSecret')
        ->assertSet('plainTextSecret', null)
        ->assertDontSee($secret)
        ->assertSee('Regenerar segredo');
});

test('regenerating replaces the secret', function () {
    $this->condominium->update(['webhook_secret' => str_repeat('a', 64)]);
    actingInPanel($this->superAdmin, $this->condominium);

    $component = Livewire::test('settings.webhook')
        ->assertSee('Regenerar segredo')
        ->call('regenerateSecret');

    $newSecret = $component->get('plainTextSecret');

    expect($newSecret)->not->toBe(str_repeat('a', 64))
        ->and($this->condominium->fresh()->webhook_secret)->toBe($newSecret);
});

test('sindico does not see the card and receives 403', function () {
    $sindico = User::factory()->sindico()->for($this->condominium)->create();

    $this->actingAs($sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertDontSee('data-settings-card="webhook"', false)
        ->assertDontSee('webhook do n8n');

    actingInPanel($sindico);

    Livewire::test('settings.webhook')->assertForbidden();

    expect($this->condominium->fresh()->webhook_secret)->toBeNull();
});
