<?php

use App\Models\EscalationStatus;
use App\Models\TicketStatus;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('idFor returns the id of the seeded slug', function () {
    $seededId = DB::table('ticket_statuses')->where('slug', 'aberto')->value('id');

    expect(TicketStatus::idFor(TicketStatus::ABERTO))->toBe($seededId);
});

test('idFor throws when the slug does not exist', function () {
    TicketStatus::idFor('inexistente');
})->throws(ModelNotFoundException::class);

test('idFor memoizes the id within the request', function () {
    $ticketStatusId = TicketStatus::idFor(TicketStatus::RESOLVIDO);

    DB::enableQueryLog();

    expect(TicketStatus::idFor(TicketStatus::RESOLVIDO))->toBe($ticketStatusId)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('idFor does not share memoized ids between lookup classes', function () {
    EscalationStatus::idFor(EscalationStatus::PENDENTE);

    TicketStatus::idFor(EscalationStatus::PENDENTE);
})->throws(ModelNotFoundException::class);

test('slug scope filters by slug', function () {
    expect(TicketStatus::slug(TicketStatus::EM_ANDAMENTO)->sole()->name)->toBe('Em andamento');
});

test('ticket status casts is_final to boolean', function () {
    expect(TicketStatus::slug(TicketStatus::RESOLVIDO)->sole()->is_final)->toBeTrue()
        ->and(TicketStatus::slug(TicketStatus::ABERTO)->sole()->is_final)->toBeFalse();
});
