<?php

use App\Models\CommonArea;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\Reservation;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();

    $this->condominium = Condominium::factory()->withWebhook()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
});

test('lists only failed deliveries of the current condominium, most recent first', function () {
    $older = WebhookDelivery::factory()->falhou()->for($this->condominium)->create([
        'resident_phone' => '+5541998123344',
        'failed_at' => now()->subDay(),
    ]);
    $newer = WebhookDelivery::factory()->falhou()->for($this->condominium)->create([
        'resident_phone' => '+5541996550021',
        'last_response_code' => null,
        'last_error' => 'cURL error 28: Operation timed out',
        'failed_at' => now(),
    ]);
    $sent = WebhookDelivery::factory()->enviado()->for($this->condominium)->create();
    $pending = WebhookDelivery::factory()->for($this->condominium)->create();
    $otherCondominium = WebhookDelivery::factory()->falhou()->for(Condominium::factory())->create();

    $this->actingAs($this->sindico)
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('Falhas de webhook')
        ->assertSeeInOrder(['Evento', 'Morador', 'Referência', 'Tentativas', 'Último retorno', 'Data'])
        ->assertSeeInOrder([
            'data-webhook-failure="'.$newer->id.'"', '+55 41 99655-0021', 'cURL error 28: Operation timed out',
            'data-webhook-failure="'.$older->id.'"', 'Status do chamado alterado', '+55 41 99812-3344', '3', 'HTTP 500',
        ], false)
        ->assertDontSee('data-webhook-failure="'.$sent->id.'"', false)
        ->assertDontSee('data-webhook-failure="'.$pending->id.'"', false)
        ->assertDontSee('data-webhook-failure="'.$otherCondominium->id.'"', false);
});

test('reference label depends on the subject type', function () {
    $ticket = Ticket::factory()->for($this->condominium)->create(['protocol_number' => 4821]);
    $area = CommonArea::factory()->for($this->condominium)->create(['name' => 'Churrasqueira']);
    $reservation = Reservation::factory()->for($this->condominium)->for($area, 'area')->create(['date' => '2026-09-27']);
    $escalation = Escalation::factory()->for($this->condominium)->create();

    WebhookDelivery::factory()->falhou()->for($this->condominium)->for($ticket, 'subject')->create();
    WebhookDelivery::factory()->falhou()->for($this->condominium)->for($reservation, 'subject')->create(['webhook_event_id' => WebhookEvent::idFor(WebhookEvent::RESERVATION_CANCELLED)]);
    WebhookDelivery::factory()->falhou()->for($this->condominium)->for($escalation, 'subject')->create(['webhook_event_id' => WebhookEvent::idFor(WebhookEvent::ESCALATION_ANSWERED)]);

    actingInPanel($this->sindico);

    Livewire::test('settings.webhook-failures')
        ->assertSee('Chamado #4821')
        ->assertSee('Reserva · Churrasqueira · 27/09')
        ->assertSee("Escalonamento #{$escalation->id}")
        ->assertSee('Reserva cancelada')
        ->assertSee('Escalonamento respondido');
});

test('paginates 10 failures per page', function () {
    WebhookDelivery::factory()->falhou()->count(11)->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $component = Livewire::test('settings.webhook-failures');

    expect($component->instance()->failures)->toHaveCount(10)
        ->and($component->instance()->failures->total())->toBe(11);
});

test('list is read only without resend button', function () {
    WebhookDelivery::factory()->falhou()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('settings.webhook-failures')
        ->assertSeeHtml('data-webhook-failure=')
        ->assertDontSee('Reenviar')
        ->assertDontSeeHtml('<button');
});

test('without failures shows the empty message', function () {
    actingInPanel($this->sindico);

    Livewire::test('settings.webhook-failures')->assertSee('Nenhuma falha de envio.');
});

test('super admin sees the failures of the selected condominium', function () {
    $failure = WebhookDelivery::factory()->falhou()->for($this->condominium)->create();
    $otherFailure = WebhookDelivery::factory()->falhou()->for(Condominium::factory())->create();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->withSession(['current_condominium_id' => $this->condominium->id])
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('data-webhook-failure="'.$failure->id.'"', false)
        ->assertDontSee('data-webhook-failure="'.$otherFailure->id.'"', false);
});

test('zelador receives 403', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();

    $this->actingAs($zelador)
        ->get(route('settings'))
        ->assertForbidden();

    actingInPanel($zelador);

    Livewire::test('settings.webhook-failures')->assertForbidden();
});
