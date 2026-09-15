<?php

use App\Models\AgentToolCall;
use App\Models\Condominium;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('forArticle only returns calls whose entities.article_ids contain the id', function () {
    $toolCalls = AgentToolCall::factory()
        ->count(6)
        ->sequence(
            ['entities' => ['article_ids' => [14]]],
            ['entities' => ['article_ids' => [12, 14, 31]]],
            ['entities' => ['article_ids' => [12, 140]]],
            ['entities' => ['article_ids' => []]],
            ['entities' => ['ticket_id' => 14]],
            ['entities' => null],
        )
        ->create();

    expect(AgentToolCall::forArticle(14)->count())->toBe(2)
        ->and(AgentToolCall::forArticle(14)->pluck('id')->all())->toEqualCanonicalizing([$toolCalls[0]->id, $toolCalls[1]->id]);
});

test('entities are cast to an array', function () {
    $toolCall = AgentToolCall::factory()->create(['entities' => ['article_ids' => [12, 14]]]);

    expect($toolCall->fresh()->entities)->toBe(['article_ids' => [12, 14]]);
});

test('tool call belongs to the token used', function () {
    $condominium = Condominium::factory()->create();
    $token = $condominium->createToken('n8n')->accessToken;

    $toolCall = AgentToolCall::factory()->for($condominium)->create(['personal_access_token_id' => $token->id]);

    expect($toolCall->personalAccessToken->is($token))->toBeTrue();
});
