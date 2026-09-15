<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Notice;
use App\Models\ToolCallResult;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
});

test('lists only active notices of the token condominium with id, title, text and updated_at', function () {
    $notice = Notice::factory()->for($this->condominium)->create([
        'title' => 'Manutenção da caixa d\'água',
        'body' => 'Quinta 18/09, das 9h às 14h, não haverá abastecimento nos blocos A e B.',
        'updated_at' => '2026-09-15 11:00:00',
    ]);
    Notice::factory()->inactive()->for($this->condominium)->create();
    Notice::factory()->for($this->condominium)->create()->delete();
    Notice::factory()->for(Condominium::factory())->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.notices_list'))
        ->assertOk()
        ->assertExactJson([
            'notices' => [[
                'id' => $notice->id,
                'title' => 'Manutenção da caixa d\'água',
                'text' => 'Quinta 18/09, das 9h às 14h, não haverá abastecimento nos blocos A e B.',
                'updated_at' => '2026-09-15T08:00:00-03:00',
            ]],
        ]);
});

test('notices are ordered by updated_at descending and query parameters are ignored', function () {
    $older = Notice::factory()->for($this->condominium)->create(['updated_at' => now()->subDays(3)]);
    $newest = Notice::factory()->for($this->condominium)->create(['updated_at' => now()->subHour()]);
    $middle = Notice::factory()->for($this->condominium)->create(['updated_at' => now()->subDay()]);
    Notice::factory()->inactive()->for($this->condominium)->create(['updated_at' => now()]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.notices_list', ['is_active' => 0, 'limit' => 1, 'q' => 'água']))
        ->assertOk()
        ->assertJsonCount(3, 'notices')
        ->assertJsonPath('notices.*.id', [$newest->id, $middle->id, $older->id]);
});

test('empty list responds notices [] and logs vazio', function () {
    Notice::factory()->inactive()->for($this->condominium)->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.notices_list'))
        ->assertOk()
        ->assertExactJson(['notices' => []]);

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::NOTICES_LIST))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::VAZIO))
        ->and($toolCall->http_status)->toBe(200);
});

test('list with notices logs sucesso', function () {
    Notice::factory()->for($this->condominium)->create();

    $this->withToken($this->token)
        ->getJson(route('api.v1.notices_list'))
        ->assertOk()
        ->assertJsonCount(1, 'notices');

    $toolCall = AgentToolCall::query()->withoutGlobalScopes()->sole();

    expect($toolCall->condominium_id)->toBe($this->condominium->id)
        ->and($toolCall->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::NOTICES_LIST))
        ->and($toolCall->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->and($toolCall->resident_id)->toBeNull()
        ->and($toolCall->entities)->toBeNull();
});
