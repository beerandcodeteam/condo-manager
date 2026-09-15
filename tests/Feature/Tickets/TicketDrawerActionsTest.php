<?php

use App\Models\Condominium;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Queue::fake();

    $this->condominium = Condominium::factory()->withWebhook()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create(['name' => 'Renata Moura']);
});

test('the primary action follows the status and final tickets render no transition nor priority selector', function () {
    $aberto = Ticket::factory()->aberto()->for($this->condominium)->create();
    $emAndamento = Ticket::factory()->emAndamento()->for($this->condominium)->create();
    $resolvido = Ticket::factory()->resolvido()->for($this->condominium)->create();
    $cancelado = Ticket::factory()->cancelado()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')->call('openTicket', $aberto->protocol_number);

    expect($component->html())
        ->toContain('data-ticket-transitions')
        ->toMatch('/data-status-action="em_andamento".*?Iniciar atendimento/s')
        ->toMatch('/data-status-action="cancelado".*?Cancelar chamado/s')
        ->toContain('data-priority-select')
        ->not->toContain('Marcar concluído');

    $component->call('openTicket', $emAndamento->protocol_number);

    expect($component->html())
        ->toMatch('/data-status-action="resolvido".*?Marcar concluído/s')
        ->toMatch('/data-status-action="cancelado".*?Cancelar chamado/s')
        ->not->toContain('Iniciar atendimento');

    foreach ([$resolvido, $cancelado] as $finalTicket) {
        $component->call('openTicket', $finalTicket->protocol_number);

        expect($component->html())
            ->not->toContain('data-ticket-transitions')
            ->not->toContain('data-status-action')
            ->not->toContain('data-priority-select')
            ->not->toContain('Reabrir')
            ->not->toContain('Atribuir zelador')
            ->toContain('data-notify-resident');
    }
});

test('starting the service moves the ticket to em_andamento and refreshes list, counts and timeline', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();
    TicketStatusChange::factory()->for($ticket)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->assertSeeText('Abertos 1')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'em_andamento')
        ->assertSet('showStatusForm', true)
        ->assertSee('Comentário (opcional)')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertSet('showStatusForm', false)
        ->assertDispatched('toast', type: 'success', message: 'Atendimento iniciado.')
        ->assertSeeText('Abertos 0')
        ->assertSeeText('Em andamento 1')
        ->assertSee('Marcar concluído')
        ->assertSee('Em andamento');

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::query()->latest('id')->first())
        ->comment->toBeNull()
        ->user_id->toBe($this->sindico->id)
        ->and(WebhookDelivery::count())->toBe(1);
});

test('concluding without comment shows the error and keeps the status', function () {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'resolvido')
        ->assertSee('Marcar chamado como concluído')
        ->set('statusComment', '   ')
        ->call('saveStatus')
        ->assertHasErrors('statusComment')
        ->assertSet('showStatusForm', true)
        ->assertSee('Informe um comentário para concluir o chamado.');

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::EM_ANDAMENTO))
        ->and(TicketStatusChange::count())->toBe(0);
});

test('concluding with a comment resolves the ticket and adds it to the timeline', function () {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'resolvido')
        ->set('statusComment', 'Elevador liberado pela Atlas')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Chamado concluído.')
        ->assertSee('Resolvido · Elevador liberado pela Atlas')
        ->assertSeeText('Concluídos 1')
        ->assertDontSee('data-ticket-transitions', false);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::RESOLVIDO));
});

test('cancelling with a comment works and without comment is refused', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'cancelado')
        ->call('saveStatus')
        ->assertHasErrors('statusComment')
        ->assertSee('Informe um comentário para cancelar o chamado.')
        ->set('statusComment', 'Aberto em duplicidade')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'Chamado cancelado.')
        ->assertSee('Cancelado · Aberto em duplicidade');

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::CANCELADO))
        ->and(TicketStatusChange::sole()->comment)->toBe('Aberto em duplicidade');
});

test('a service error in pt-BR is shown when the ticket was finalized meanwhile', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'em_andamento');

    Ticket::whereKey($ticket->id)->update(['ticket_status_id' => TicketStatus::idFor(TicketStatus::CANCELADO)]);

    $component->call('saveStatus')
        ->assertDispatched('toast', type: 'error', message: 'Chamado finalizado não pode mudar de status.')
        ->assertSet('showStatusForm', false);

    expect($ticket->fresh()->ticket_status_id)->toBe(TicketStatus::idFor(TicketStatus::CANCELADO));
});

test('changing the priority persists and updates the list', function () {
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('changePriority', 'alta')
        ->assertDispatched('toast', type: 'success', message: 'Prioridade atualizada.')
        ->assertSee('background: #e5484d', false);

    expect($ticket->fresh()->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::ALTA))
        ->and(TicketStatusChange::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
});

test('changing the priority of a final ticket shows the service error', function () {
    $ticket = Ticket::factory()->resolvido()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('changePriority', 'alta')
        ->assertDispatched('toast', type: 'error', message: 'Chamado finalizado não pode mudar de prioridade.');

    expect($ticket->fresh()->ticket_priority_id)->toBe(TicketPriority::idFor(TicketPriority::MEDIA));
});

test('notifying the resident creates the notice, shows it on the timeline and confirms the delivery', function () {
    $ticket = Ticket::factory()->emAndamento()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptNotice')
        ->assertSet('showNoticeForm', true);

    expect($component->html())->toMatch('/data-notice-counter><span x-text="length">0<\/span>\/1000/');

    $component->set('noticeMessage', 'Visita técnica agendada para amanhã às 9h.')
        ->call('sendNotice')
        ->assertHasNoErrors()
        ->assertSet('showNoticeForm', false)
        ->assertDispatched('toast', type: 'success', message: 'Aviso enviado ao morador.')
        ->assertSee('Aviso ao morador: Visita técnica agendada para amanhã às 9h.');

    $notice = TicketResidentNotice::sole();

    expect($notice->user_id)->toBe($this->sindico->id)
        ->and($notice->webhook_delivery_id)->toBe(WebhookDelivery::sole()->id);
});

test('empty and too long notices show validation errors', function () {
    $ticket = Ticket::factory()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptNotice')
        ->call('sendNotice')
        ->assertHasErrors('noticeMessage')
        ->set('noticeMessage', Str::repeat('a', 1001))
        ->call('sendNotice')
        ->assertHasErrors('noticeMessage')
        ->assertSet('showNoticeForm', true);

    expect(TicketResidentNotice::count())->toBe(0);
});

test('the notify button is disabled with a hint when there is no resident', function () {
    $ticket = Ticket::factory()->fromPanel()->for($this->condominium)->create();

    actingInPanel($this->sindico);

    $component = Livewire::test('pages::tickets')->call('openTicket', $ticket->protocol_number);

    expect($component->html())
        ->toMatch('/<button[^>]*disabled[^>]*title="Chamado sem morador vinculado[^"]*"[^>]*data-notify-resident/s')
        ->toContain('Sem morador vinculado')
        ->not->toContain('wire:click="promptNotice"');

    $component->call('promptNotice')
        ->call('sendNotice')
        ->assertDispatched('toast', type: 'error', message: 'Chamado sem morador vinculado não pode receber aviso.');

    expect(TicketResidentNotice::count())->toBe(0);
});

test('a condominium without webhook shows the not sent toast', function () {
    $condominium = Condominium::factory()->create();
    $sindico = User::factory()->sindico()->for($condominium)->create();
    $ticket = Ticket::factory()->resolvido()->for($condominium)->create();

    actingInPanel($sindico);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptNotice')
        ->set('noticeMessage', 'O reparo foi concluído.')
        ->call('sendNotice')
        ->assertDispatched('toast', type: 'success', message: 'Aviso registrado — condomínio sem webhook configurado.')
        ->assertSee('Aviso ao morador: O reparo foi concluído.');

    expect(TicketResidentNotice::sole()->webhook_delivery_id)->toBeNull();
});

test('zelador runs the drawer actions', function () {
    $zelador = User::factory()->zelador()->for($this->condominium)->create();
    $ticket = Ticket::factory()->aberto()->for($this->condominium)->create();

    actingInPanel($zelador);

    Livewire::test('pages::tickets')
        ->call('openTicket', $ticket->protocol_number)
        ->call('promptStatus', 'em_andamento')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->call('changePriority', 'baixa')
        ->call('promptNotice')
        ->set('noticeMessage', 'Técnico a caminho.')
        ->call('sendNotice')
        ->call('promptStatus', 'resolvido')
        ->set('statusComment', 'Resolvido pelo zelador')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($ticket->fresh())
        ->ticket_status_id->toBe(TicketStatus::idFor(TicketStatus::RESOLVIDO))
        ->ticket_priority_id->toBe(TicketPriority::idFor(TicketPriority::BAIXA))
        ->and(TicketStatusChange::query()->pluck('user_id')->unique()->all())->toBe([$zelador->id])
        ->and(TicketResidentNotice::sole()->user_id)->toBe($zelador->id);
});

test('actions without an open ticket respond 404', function () {
    actingInPanel($this->sindico);

    Livewire::test('pages::tickets')
        ->call('changePriority', 'alta')
        ->assertNotFound();
});
