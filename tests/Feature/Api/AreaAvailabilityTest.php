<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ToolCallResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;

    $this->area = CommonArea::factory()->for($this->condominium)->create([
        'name' => 'Churrasqueira',
        'description' => 'Churrasqueira coberta do térreo',
        'min_advance_hours' => 24,
        'max_advance_days' => 60,
        'cancellation_deadline_hours' => 12,
    ]);
    $this->daySlot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '10:00:00', 'ends_at' => '16:00:00']);
    $this->nightSlot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '17:00:00', 'ends_at' => '23:00:00']);
});

test('lists active areas with slots and rules, without inactive areas or deleted slots', function () {
    CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '08:00:00', 'ends_at' => '09:00:00'])->delete();
    CommonAreaSlot::factory()->for(CommonArea::factory()->inactive()->for($this->condominium)->state(['name' => 'Quadra']), 'area')->create();
    CommonArea::factory()->create(['name' => 'Piscina']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_list'))
        ->assertOk()
        ->assertExactJson([
            'areas' => [
                [
                    'id' => $this->area->id,
                    'name' => 'Churrasqueira',
                    'description' => 'Churrasqueira coberta do térreo',
                    'slots' => [
                        ['id' => $this->daySlot->id, 'starts' => '10:00', 'ends' => '16:00'],
                        ['id' => $this->nightSlot->id, 'starts' => '17:00', 'ends' => '23:00'],
                    ],
                    'rules' => [
                        'min_advance_hours' => 24,
                        'max_advance_days' => 60,
                        'cancellation_deadline_hours' => 12,
                    ],
                ],
            ],
        ]);

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::AREAS_LIST))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO));
});

test('empty area list is logged as vazio', function () {
    $this->area->update(['is_active' => false]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_list'))
        ->assertOk()
        ->assertExactJson(['areas' => []]);

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole()->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::VAZIO));
});

test('taken slot and slot outside the advance window come as unavailable', function () {
    Reservation::factory()->for($this->daySlot, 'slot')->create(['date' => '2026-09-16']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-09-16']))
        ->assertOk()
        ->assertExactJson([
            'area' => ['id' => $this->area->id, 'name' => 'Churrasqueira'],
            'date' => '2026-09-16',
            'slots' => [
                ['id' => $this->daySlot->id, 'starts' => '10:00', 'ends' => '16:00', 'available' => false],
                ['id' => $this->nightSlot->id, 'starts' => '17:00', 'ends' => '23:00', 'available' => true],
            ],
        ]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-09-15']))
        ->assertOk()
        ->assertJsonPath('slots.0.available', false)
        ->assertJsonPath('slots.1.available', false);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-11-15']))
        ->assertOk()
        ->assertJsonPath('slots.0.available', false)
        ->assertJsonPath('slots.1.available', false);

    expect(AgentToolCall::query()->withoutGlobalScopes()->latest('id')->first()->agent_tool_id)->toBe(AgentTool::idFor(AgentTool::AREAS_AVAILABILITY));
});

test('inactive, unknown or other condominium area responds 404 area_not_found', function (int|string $areaId) {
    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $areaId, 'date' => '2026-09-20']))
        ->assertNotFound()
        ->assertExactJson(['code' => 'area_not_found', 'message' => 'Área comum não encontrada.']);

    expect(AgentToolCall::query()->withoutGlobalScopes()->sole())
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->error_code->toBe('area_not_found');
})->with([
    'inactive' => fn () => CommonArea::factory()->inactive()->for(test()->condominium)->create()->id,
    'unknown' => fn () => 999999,
    'not numeric' => fn () => 'churrasqueira',
    'other condominium' => fn () => CommonArea::factory()->create()->id,
]);

test('missing or invalid date responds 422', function (?string $date) {
    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', array_filter(['area' => $this->area->id, 'date' => $date])))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_error')
        ->assertJsonValidationErrors(['date']);
})->with([null, '27/09/2026', '2026-02-30', '2026-9-7', 'year zero outside the database range' => '0000-01-01']);

test('deleted slots do not appear in the availability', function () {
    $this->daySlot->delete();

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonCount(1, 'slots')
        ->assertJsonPath('slots.0.id', $this->nightSlot->id);
});
