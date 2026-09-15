<?php

use App\Models\Ticket;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('open scope returns aberto and em_andamento tickets', function () {
    $aberto = Ticket::factory()->aberto()->create();
    $emAndamento = Ticket::factory()->emAndamento()->create();
    Ticket::factory()->resolvido()->create();
    Ticket::factory()->cancelado()->create();

    expect(Ticket::open()->pluck('id')->all())->toEqualCanonicalizing([$aberto->id, $emAndamento->id]);
});

test('closed scope returns resolvido and cancelado tickets', function () {
    Ticket::factory()->aberto()->create();
    Ticket::factory()->emAndamento()->create();
    $resolvido = Ticket::factory()->resolvido()->create();
    $cancelado = Ticket::factory()->cancelado()->create();

    expect(Ticket::closed()->pluck('id')->all())->toEqualCanonicalizing([$resolvido->id, $cancelado->id]);
});

test('isFinal follows the ticket status', function (string $state, bool $isFinal) {
    expect(Ticket::factory()->{$state}()->create()->isFinal())->toBe($isFinal);
})->with([
    'aberto' => ['aberto', false],
    'em andamento' => ['emAndamento', false],
    'resolvido' => ['resolvido', true],
    'cancelado' => ['cancelado', true],
]);

test('protocol_label prefixes the protocol number with a hash', function () {
    expect(Ticket::factory()->make(['protocol_number' => 4821])->protocol_label)->toBe('#4821');
});
