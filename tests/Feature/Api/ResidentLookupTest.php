<?php

use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Unit;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
});

test('active resident responds with the exact resident and unit format', function () {
    $block = Block::factory()->for($this->condominium)->create(['name' => 'B']);
    $unit = Unit::factory()->for($this->condominium)->for($block)->create(['number' => '101']);
    $resident = Resident::factory()->for($unit)->create(['name' => 'Ana Souza', 'phone' => '+5511999990000']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+55 11 99999-0000']))
        ->assertOk()
        ->assertExactJson([
            'exists' => true,
            'resident' => ['id' => $resident->id, 'name' => 'Ana Souza', 'phone' => '+5511999990000'],
            'unit' => ['id' => $unit->id, 'number' => '101', 'block' => 'B'],
        ]);
});

test('an unencoded plus sign in the query string is accepted as the documented example', function () {
    $resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);

    $this->withToken($this->token)
        ->getJson('/api/v1/residents/lookup?phone=+5511999990000')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('resident.id', $resident->id);

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole()->phone)->toBe('+5511999990000');
});

test('unit without block responds with null block', function () {
    $unit = Unit::factory()->for($this->condominium)->create(['block_id' => null, 'number' => '12']);
    Resident::factory()->for($unit)->create(['phone' => '+5511999990000']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertJsonPath('unit.number', '12')
        ->assertJsonPath('unit.block', null);
});

test('inactive resident responds only exists false', function () {
    Resident::factory()->inactive()->for($this->condominium)->create(['phone' => '+5511999990000']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson(['exists' => false]);
});

test('phone of a resident of another condominium responds exists false', function () {
    Resident::factory()->for(Condominium::factory())->create(['phone' => '+5511999990000']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.residents_lookup', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson(['exists' => false]);
});

test('missing or invalid phone responds 422 validation error', function (array $query) {
    $this->withToken($this->token)
        ->getJson(route('api.v1.residents_lookup', $query))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['phone']);
})->with([
    'missing' => [[]],
    'invalid' => [['phone' => '11999']],
]);
