<?php

use App\Models\Condominium;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    config(['condo.platform.token' => 'platform-secret']);
});

test('an unknown message is not an echo', function () {
    $this->withToken('platform-secret')
        ->getJson(route('api.v1.echo_check', ['message_id' => 'NUNCA-VISTO']))
        ->assertOk()
        ->assertJsonPath('sent_by_us', false);
});

test('a remembered message is recognised as our own', function () {
    $this->withToken('platform-secret')
        ->postJson(route('api.v1.echo_remember'), ['message_id' => 'BAE5ABC123'])
        ->assertCreated();

    $this->withToken('platform-secret')
        ->getJson(route('api.v1.echo_check', ['message_id' => 'BAE5ABC123']))
        ->assertOk()
        ->assertJsonPath('sent_by_us', true);
});

test('the guard forgets after its ttl, so ids do not pile up forever', function () {
    $this->withToken('platform-secret')
        ->postJson(route('api.v1.echo_remember'), ['message_id' => 'BAE5ABC123'])
        ->assertCreated();

    $this->travel((int) config('condo.conversations.echo_ttl_seconds') + 60)->seconds();

    $this->withToken('platform-secret')
        ->getJson(route('api.v1.echo_check', ['message_id' => 'BAE5ABC123']))
        ->assertJsonPath('sent_by_us', false);
});

test('the guard needs the platform token', function () {
    $this->getJson(route('api.v1.echo_check', ['message_id' => 'X']))
        ->assertUnauthorized();

    $this->postJson(route('api.v1.echo_remember'), ['message_id' => 'X'])
        ->assertUnauthorized();
});

test('a condominium token is not enough for the guard', function () {
    $token = Condominium::factory()->create()->createToken('n8n')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.echo_check', ['message_id' => 'X']))
        ->assertUnauthorized();
});

test('a missing message id is a validation error', function () {
    $this->withToken('platform-secret')
        ->postJson(route('api.v1.echo_remember'), [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error');
});

test('different ids do not collide', function () {
    $this->withToken('platform-secret')->postJson(route('api.v1.echo_remember'), ['message_id' => 'A']);

    $this->withToken('platform-secret')
        ->getJson(route('api.v1.echo_check', ['message_id' => 'B']))
        ->assertJsonPath('sent_by_us', false);
});
