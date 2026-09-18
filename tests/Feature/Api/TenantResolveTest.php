<?php

use App\Models\Condominium;
use App\Models\Resident;
use App\Services\Integration\ApiTokenService;
use Database\Seeders\LookupSeeder;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['condo.platform.token' => 'platform-secret']);
});

test('the platform token is required: without it no token is ever minted', function () {
    Resident::factory()->for(Condominium::factory()->create())->create(['phone' => '+5511999990000']);

    $this->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->withToken('wrong-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertUnauthorized();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('an empty platform token disables the endpoint instead of accepting anything', function () {
    config(['condo.platform.token' => null]);

    $this->withToken('')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertUnauthorized();
});

test('a phone in two condominiums returns both, so the caller disambiguates', function () {
    $aurora = Condominium::factory()->create(['name' => 'Residencial Aurora']);
    $solar = Condominium::factory()->create(['name' => 'Solar das Palmeiras']);

    Resident::factory()->for($aurora)->create(['name' => 'Ana', 'phone' => '+5511999990000']);
    Resident::factory()->for($solar)->create(['name' => 'Ana', 'phone' => '+5511999990000']);

    $response = $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+55 11 99999-0000'])
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonCount(2, 'matches');

    expect(collect($response->json('matches'))->pluck('condominium.name')->sort()->values()->all())
        ->toBe(['Residencial Aurora', 'Solar das Palmeiras']);
});

test('the returned token opens the agent endpoints scoped to its own condominium', function () {
    $aurora = Condominium::factory()->create();
    $solar = Condominium::factory()->create();

    Resident::factory()->for($aurora)->create(['name' => 'Ana', 'phone' => '+5511999990000']);
    Resident::factory()->for($solar)->create(['name' => 'Bruno', 'phone' => '+5511888880000']);

    $token = $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertOk()
        ->json('matches.0.token');

    $this->withToken($token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('resident.name', 'Ana');

    // The resident of the other condominium is invisible to this token.
    $this->withToken($token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511888880000']))
        ->assertOk()
        ->assertExactJson(['exists' => false]);
});

test('an unknown or inactive phone mints no token and reveals nothing', function () {
    Resident::factory()->for(Condominium::factory()->create())
        ->create(['phone' => '+5511999990000', 'is_active' => false]);

    $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertOk()
        ->assertExactJson(['exists' => false, 'matches' => []]);

    expect(PersonalAccessToken::count())->toBe(0);
});

test('the minted token expires and stops working', function () {
    config(['condo.platform.agent_token_ttl_minutes' => 15]);

    Resident::factory()->for(Condominium::factory()->create())->create(['phone' => '+5511999990000']);

    $token = $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->json('matches.0.token');

    $this->travel(16)->minutes();

    $this->withToken($token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511999990000']))
        ->assertUnauthorized();
});

test('the ephemeral tokens stay out of the panel listing', function () {
    $condominium = Condominium::factory()->create();
    Resident::factory()->for($condominium)->create(['phone' => '+5511999990000']);

    $condominium->createToken('n8n');

    $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '+5511999990000'])
        ->assertOk();

    expect($condominium->tokens()->count())->toBe(2)
        ->and(app(ApiTokenService::class)->tokens($condominium)->pluck('name')->all())->toBe(['n8n']);
});

test('a malformed phone is rejected before any lookup', function () {
    $this->withToken('platform-secret')
        ->postJson(route('api.v1.auth_token'), ['phone' => '5511999990000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error');
});
