<?php

use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\CommonArea;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\Unit;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

/**
 * Runs the write inside a savepoint so the Postgres test transaction survives the violation.
 */
function expectUniqueConstraintViolation(Closure $write): void
{
    expect(fn () => DB::transaction($write))->toThrow(UniqueConstraintViolationException::class);
}

test('a second active reservation for the same slot and date violates the partial index', function () {
    $reservation = Reservation::factory()->create(['date' => '2026-09-20']);

    expectUniqueConstraintViolation(fn () => Reservation::factory()->for($reservation->slot, 'slot')->create(['date' => '2026-09-20']));
});

test('a new reservation is accepted after the first one is cancelled', function () {
    $reservation = Reservation::factory()->create(['date' => '2026-09-20']);
    $reservation->update(['cancelled_at' => now()]);

    $newReservation = Reservation::factory()->for($reservation->slot, 'slot')->create(['date' => '2026-09-20']);

    expect(Reservation::active()->pluck('id')->all())->toBe([$newReservation->id]);
});

test('the same slot can be reserved on another date', function () {
    $reservation = Reservation::factory()->create(['date' => '2026-09-20']);

    Reservation::factory()->for($reservation->slot, 'slot')->create(['date' => '2026-09-21']);

    expect(Reservation::active()->count())->toBe(2);
});

test('a unit number repeated in the same block fails', function () {
    $block = Block::factory()->create();
    Unit::factory()->for($block)->create(['number' => '102']);

    expectUniqueConstraintViolation(fn () => Unit::factory()->for($block)->create(['number' => '102']));
});

test('a unit number repeated in another block is accepted', function () {
    $blockA = Block::factory()->create(['name' => 'A']);
    $blockB = Block::factory()->for($blockA->condominium)->create(['name' => 'B']);
    Unit::factory()->for($blockA)->create(['number' => '102']);

    Unit::factory()->for($blockB)->create(['number' => '102']);

    expect(Unit::where('number', '102')->count())->toBe(2);
});

test('two units without block and with the same number in the same condominium fail', function () {
    $condominium = Condominium::factory()->create();
    Unit::factory()->for($condominium)->create(['number' => '102']);

    expectUniqueConstraintViolation(fn () => Unit::factory()->for($condominium)->create(['number' => '102']));
});

test('a protocol number repeated in the same condominium fails', function () {
    $ticket = Ticket::factory()->create();

    expectUniqueConstraintViolation(fn () => Ticket::factory()->for($ticket->condominium)->create(['protocol_number' => $ticket->protocol_number]));
});

test('a protocol number repeated in another condominium is accepted', function () {
    $ticket = Ticket::factory()->create();

    Ticket::factory()->create(['protocol_number' => $ticket->protocol_number]);

    expect(Ticket::where('protocol_number', $ticket->protocol_number)->count())->toBe(2);
});

test('a resident phone repeated in the same condominium fails', function () {
    $resident = Resident::factory()->create();

    expectUniqueConstraintViolation(fn () => Resident::factory()->for($resident->condominium)->create(['phone' => $resident->phone]));
});

test('a resident phone repeated in another condominium is accepted', function () {
    $resident = Resident::factory()->create();

    Resident::factory()->create(['phone' => $resident->phone]);

    expect(Resident::where('phone', $resident->phone)->count())->toBe(2);
});

test('deleting the personal access token nulls agent_tool_calls.personal_access_token_id', function () {
    $condominium = Condominium::factory()->create();
    $token = $condominium->createToken('n8n')->accessToken;
    $toolCall = AgentToolCall::factory()->for($condominium)->create(['personal_access_token_id' => $token->id]);

    $token->delete();

    expect($toolCall->fresh())->not->toBeNull()
        ->and($toolCall->fresh()->personal_access_token_id)->toBeNull();
});

test('names that must be unique per condominium fail when repeated in the same condominium', function (Closure $createDuplicate) {
    expectUniqueConstraintViolation($createDuplicate);
})->with([
    'block name' => function () {
        $block = Block::factory()->create();
        Block::factory()->for($block->condominium)->create(['name' => $block->name]);
    },
    'ticket category name' => function () {
        $category = TicketCategory::factory()->create();
        TicketCategory::factory()->for($category->condominium)->create(['name' => $category->name]);
    },
    'ticket category slug' => function () {
        $category = TicketCategory::factory()->create();
        TicketCategory::factory()->for($category->condominium)->create(['slug' => $category->slug]);
    },
    'common area name' => function () {
        $area = CommonArea::factory()->create();
        CommonArea::factory()->for($area->condominium)->create(['name' => $area->name]);
    },
    'condominium name' => function () {
        Condominium::factory()->create(['name' => 'Residencial Aurora']);
        Condominium::factory()->create(['name' => 'Residencial Aurora']);
    },
]);

test('names unique per condominium can repeat across condominiums', function () {
    $block = Block::factory()->create();
    $category = TicketCategory::factory()->create();
    $area = CommonArea::factory()->create();

    Block::factory()->create(['name' => $block->name]);
    TicketCategory::factory()->create(['name' => $category->name, 'slug' => $category->slug]);
    CommonArea::factory()->create(['name' => $area->name]);

    expect(Block::where('name', $block->name)->count())->toBe(2)
        ->and(TicketCategory::where('slug', $category->slug)->count())->toBe(2)
        ->and(CommonArea::where('name', $area->name)->count())->toBe(2);
});
