<?php

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationOrigin;
use App\Models\ReservationStatus;
use App\Models\Resident;
use App\Models\ToolCallResult;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Sao_Paulo'));

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->token = $this->condominium->createToken('n8n')->plainTextToken;
    $this->resident = Resident::factory()->for($this->condominium)->create(['name' => 'Paula Ribeiro', 'phone' => '+5511999990000']);

    $this->area = CommonArea::factory()->for($this->condominium)->create(['name' => 'Churrasqueira']);
    $this->slot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '17:00:00', 'ends_at' => '23:00:00']);
});

function lastToolCall(): AgentToolCall
{
    return AgentToolCall::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

test('creates a reservation and responds 201 in the documented format', function () {
    $response = $this->withToken($this->token)
        ->postJson(route('api.v1.reservations_create'), [
            'phone' => '+55 11 99999-0000',
            'area_id' => $this->area->id,
            'slot_id' => $this->slot->id,
            'date' => '2026-09-27',
        ])
        ->assertCreated();

    $reservation = Reservation::query()->withoutGlobalScopes()->sole();

    $response->assertExactJson([
        'id' => $reservation->id,
        'status' => 'confirmada',
        'area' => 'Churrasqueira',
        'date' => '2026-09-27',
        'starts' => '17:00',
        'ends' => '23:00',
    ]);

    expect($reservation)
        ->resident_id->toBe($this->resident->id)
        ->unit_id->toBe($this->resident->unit_id)
        ->reservation_origin_id->toBe(ReservationOrigin::idFor(ReservationOrigin::WHATSAPP))
        ->and(lastToolCall())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::RESERVATIONS_CREATE))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->resident_id->toBe($this->resident->id)
        ->entities->toBe(['reservation_id' => $reservation->id]);
});

test('each refusal creates no reservation and is logged as recusa with its code', function (Closure $payload, int $status, string $code, ?array $extras) {
    Reservation::factory()->for($this->slot, 'slot')->create(['date' => '2026-09-28']);
    $existingCount = Reservation::query()->withoutGlobalScopes()->count();

    $response = $this->withToken($this->token)
        ->postJson(route('api.v1.reservations_create'), $payload->call($this))
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    if ($extras !== null) {
        $response->assertJson($extras);
    }

    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe($existingCount)
        ->and(lastToolCall())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::RESERVATIONS_CREATE))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->http_status->toBe($status)
        ->error_code->toBe($code)
        ->entities->toBeNull();
})->with([
    'unknown resident' => [
        fn () => ['phone' => '+5511900000000', 'area_id' => $this->area->id, 'slot_id' => $this->slot->id, 'date' => '2026-09-27'],
        403, 'resident_not_found', null,
    ],
    'inactive area' => [
        function () {
            $this->area->update(['is_active' => false]);

            return ['phone' => '+5511999990000', 'area_id' => $this->area->id, 'slot_id' => $this->slot->id, 'date' => '2026-09-27'];
        },
        422, 'area_unavailable', null,
    ],
    'unknown area' => [
        fn () => ['phone' => '+5511999990000', 'area_id' => 999999, 'slot_id' => $this->slot->id, 'date' => '2026-09-27'],
        422, 'area_unavailable', null,
    ],
    'slot of another area' => [
        fn () => ['phone' => '+5511999990000', 'area_id' => $this->area->id, 'slot_id' => CommonAreaSlot::factory()->for(CommonArea::factory()->for($this->condominium), 'area')->create()->id, 'date' => '2026-09-27'],
        422, 'area_unavailable', null,
    ],
    'area of another condominium' => [
        function () {
            $otherSlot = CommonAreaSlot::factory()->create();

            return ['phone' => '+5511999990000', 'area_id' => $otherSlot->common_area_id, 'slot_id' => $otherSlot->id, 'date' => '2026-09-27'];
        },
        422, 'area_unavailable', null,
    ],
    'inside the minimum advance' => [
        fn () => ['phone' => '+5511999990000', 'area_id' => $this->area->id, 'slot_id' => $this->slot->id, 'date' => '2026-09-15'],
        422, 'advance_notice_violation', ['min_advance_hours' => 24, 'max_advance_days' => 60],
    ],
    'beyond the maximum advance' => [
        fn () => ['phone' => '+5511999990000', 'area_id' => $this->area->id, 'slot_id' => $this->slot->id, 'date' => '2026-11-15'],
        422, 'advance_notice_violation', ['min_advance_hours' => 24, 'max_advance_days' => 60],
    ],
    'taken slot' => [
        fn () => ['phone' => '+5511999990000', 'area_id' => $this->area->id, 'slot_id' => $this->slot->id, 'date' => '2026-09-28'],
        422, 'slot_unavailable', ['message' => 'Faixa já reservada nesta data.'],
    ],
    'missing fields' => [
        fn () => ['phone' => '+5511999990000'],
        422, 'validation_error', null,
    ],
]);

test('lists only upcoming confirmed reservations of the unit, including manual ones, with cancellable', function () {
    $housemate = Resident::factory()->for($this->resident->unit)->create(['condominium_id' => $this->condominium->id, 'name' => 'Marcos Ribeiro']);
    $daySlot = CommonAreaSlot::factory()->for($this->area, 'area')->create(['starts_at' => '10:00:00', 'ends_at' => '16:00:00']);

    $later = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-27']);
    $manual = Reservation::factory()->manual()->for($daySlot, 'slot')->for($housemate)->create(['date' => '2026-09-27']);
    $soon = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-16']);
    Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-14']);
    Reservation::factory()->cancelled()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-20']);
    Reservation::factory()->for($this->slot, 'slot')->for(Resident::factory()->for($this->condominium))->create(['date' => '2026-09-21']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.reservations_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson([
            'reservations' => [
                ['id' => $soon->id, 'area' => 'Churrasqueira', 'date' => '2026-09-16', 'starts' => '17:00', 'ends' => '23:00', 'resident' => 'Paula Ribeiro', 'cancellable' => true],
                ['id' => $manual->id, 'area' => 'Churrasqueira', 'date' => '2026-09-27', 'starts' => '10:00', 'ends' => '16:00', 'resident' => 'Marcos Ribeiro', 'cancellable' => true],
                ['id' => $later->id, 'area' => 'Churrasqueira', 'date' => '2026-09-27', 'starts' => '17:00', 'ends' => '23:00', 'resident' => 'Paula Ribeiro', 'cancellable' => true],
            ],
        ]);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00:00', 'America/Sao_Paulo'));

    $this->withToken($this->token)
        ->getJson(route('api.v1.reservations_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertJsonPath('reservations.0.id', $soon->id)
        ->assertJsonPath('reservations.0.cancellable', false);

    expect(lastToolCall())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::RESERVATIONS_LIST))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO));
});

test('empty reservation list is logged as vazio and unknown phone responds 403', function () {
    $this->withToken($this->token)
        ->getJson(route('api.v1.reservations_list', ['phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson(['reservations' => []]);

    expect(lastToolCall()->tool_call_result_id)->toBe(ToolCallResult::idFor(ToolCallResult::VAZIO));

    $this->withToken($this->token)
        ->getJson(route('api.v1.reservations_list', ['phone' => '+5511900000000']))
        ->assertForbidden()
        ->assertJsonPath('code', 'resident_not_found');
});

test('cancelling within the deadline frees the slot', function () {
    $reservation = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-27']);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-09-27']))
        ->assertJsonPath('slots.0.available', false);

    $this->withToken($this->token)
        ->deleteJson(route('api.v1.reservations_cancel', ['reservation' => $reservation->id, 'phone' => '+5511999990000']))
        ->assertOk()
        ->assertExactJson(['id' => $reservation->id, 'status' => 'cancelada']);

    expect($reservation->fresh()->reservation_status_id)->toBe(ReservationStatus::idFor(ReservationStatus::CANCELADA))
        ->and(lastToolCall())
        ->agent_tool_id->toBe(AgentTool::idFor(AgentTool::RESERVATIONS_CANCEL))
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::SUCESSO))
        ->entities->toBe(['reservation_id' => $reservation->id]);

    $this->withToken($this->token)
        ->getJson(route('api.v1.areas_availability', ['area' => $this->area->id, 'date' => '2026-09-27']))
        ->assertJsonPath('slots.0.available', true);
});

test('cancelling after the deadline responds 422 cancellation_deadline_passed', function () {
    $reservation = Reservation::factory()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-16']);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 18:00:00', 'America/Sao_Paulo'));

    $this->withToken($this->token)
        ->deleteJson(route('api.v1.reservations_cancel', ['reservation' => $reservation->id, 'phone' => '+5511999990000']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'cancellation_deadline_passed')
        ->assertJsonPath('cancellation_deadline_hours', 24);

    expect($reservation->fresh()->cancelled_at)->toBeNull()
        ->and(lastToolCall())
        ->tool_call_result_id->toBe(ToolCallResult::idFor(ToolCallResult::RECUSA))
        ->error_code->toBe('cancellation_deadline_passed')
        ->entities->toBe(['reservation_id' => $reservation->id]);
});

test('cancelling a reservation of another unit, already cancelled or unknown responds 404', function () {
    $otherUnit = Unit::factory()->for($this->condominium)->create();
    $otherUnitReservation = Reservation::factory()->for($this->slot, 'slot')->for(Resident::factory()->for($otherUnit))->create(['date' => '2026-09-27']);
    $cancelled = Reservation::factory()->cancelled()->for($this->slot, 'slot')->for($this->resident)->create(['date' => '2026-09-27']);

    foreach ([$otherUnitReservation->id, $cancelled->id, 999999] as $reservationId) {
        $this->withToken($this->token)
            ->deleteJson(route('api.v1.reservations_cancel', ['reservation' => $reservationId, 'phone' => '+5511999990000']))
            ->assertNotFound()
            ->assertJsonPath('code', 'reservation_not_found');
    }

    expect($otherUnitReservation->fresh()->cancelled_at)->toBeNull()
        ->and(lastToolCall()->error_code)->toBe('reservation_not_found');
});
