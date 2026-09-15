<?php

use App\Models\Escalation;
use App\Models\Reservation;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('unresolved scope excludes resolved escalations', function () {
    $pending = Escalation::factory()->pendente()->create();
    $inProgress = Escalation::factory()->emAtendimento()->create();
    Escalation::factory()->resolvido()->create();

    expect(Escalation::unresolved()->pluck('id')->all())->toEqualCanonicalizing([$pending->id, $inProgress->id]);
});

test('webhook deliveries resolve their polymorphic subject', function () {
    $reservation = Reservation::factory()->create();

    $delivery = WebhookDelivery::factory()->for($reservation, 'subject')->create();

    expect($delivery->subject->is($reservation))->toBeTrue()
        ->and($delivery->condominium_id)->toBe($reservation->condominium_id)
        ->and($reservation->webhookDeliveries->pluck('id')->all())->toBe([$delivery->id]);
});
