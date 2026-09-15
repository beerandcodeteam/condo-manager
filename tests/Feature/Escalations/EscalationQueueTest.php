<?php

use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->withoutVite();
    Queue::fake();
    $this->travelTo('2026-09-15 13:00:00');

    $this->condominium = Condominium::factory()->withWebhook()->create(['name' => 'Residencial Aurora']);
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create(['name' => 'Renata Moura']);
    $this->zelador = User::factory()->zelador()->for($this->condominium)->create(['name' => 'Carlos Zelador']);
    $this->unit = Unit::factory()->for($this->condominium)->create(['number' => '102', 'block_id' => null]);
    $this->resident = Resident::factory()->for($this->unit)->create(['condominium_id' => $this->condominium->id, 'name' => 'Ana Beatriz']);
});

test('the queue page shows the support text, filters and cards oldest first with reason, wait and ticket link', function () {
    $ticket = Ticket::factory()->for($this->resident)->create();

    Escalation::factory()->for($this->resident)->create([
        'escalation_reason_id' => EscalationReason::idFor(EscalationReason::SEM_REGRA),
        'summary' => 'Pergunta se pode instalar carregador na vaga.',
        'created_at' => now()->subMinutes(65),
    ]);
    Escalation::factory()->for($this->resident)->create([
        'escalation_reason_id' => EscalationReason::idFor(EscalationReason::PEDIU_HUMANO),
        'summary' => 'Cobrança de taxa extra de setembro que ela diz já ter pago.',
        'ticket_id' => $ticket->id,
        'created_at' => now()->subMinutes(14),
    ]);
    Escalation::factory()->for($this->resident)->create([
        'escalation_reason_id' => EscalationReason::idFor(EscalationReason::TOOL_RECUSOU),
        'summary' => 'Quer o salão de festas em 20/09 à noite.',
        'created_at' => now()->subMinutes(32),
    ]);

    $this->actingAs($this->zelador)
        ->get(route('escalations.index'))
        ->assertOk()
        ->assertSee('O agente entregou estas conversas porque a regra não cobria, a tool recusou ou o morador pediu um humano.')
        ->assertSeeInOrder(['Em aberto', 'Resolvidos'])
        ->assertSeeTextInOrder([
            'Sem regra', '· esperando 1 h 05', 'Pergunta se pode instalar carregador na vaga.',
            'Tool recusou', '· esperando 32 min', 'Quer o salão de festas em 20/09 à noite.',
            'Pediu humano', '· esperando 14 min', 'Cobrança de taxa extra de setembro que ela diz já ter pago.',
        ])
        ->assertSee('Ana Beatriz')
        ->assertSee('· 102')
        ->assertSee('grid-cols-[1fr_170px]', false)
        ->assertSee('style="background: #e5484d"', false)
        ->assertSee('style="background: #f5a623"', false)
        ->assertSee('href="'.route('tickets.index', ['chamado' => $ticket->protocol_number]).'"', false)
        ->assertSee("Chamado #{$ticket->protocol_number}");
});

test('resolved escalations are hidden by default and shown with response, responder and date in the resolved filter', function () {
    Escalation::factory()->pendente()->for($this->resident)->create(['summary' => 'Resumo pendente']);
    Escalation::factory()->emAtendimento()->for($this->resident)->create(['summary' => 'Resumo em atendimento', 'assigned_user_id' => $this->sindico->id]);
    Escalation::factory()->resolvido()->for($this->resident)->create([
        'summary' => 'Resumo resolvido',
        'assigned_user_id' => $this->sindico->id,
        'responded_by_user_id' => $this->sindico->id,
        'response' => 'Pagamento de agosto localizado, cobrança cancelada.',
        'resolved_at' => now()->subMinutes(5),
    ]);

    actingInPanel($this->sindico);

    Livewire::test('pages::escalations')
        ->assertSee('Resumo pendente')
        ->assertSee('Resumo em atendimento')
        ->assertDontSee('Resumo resolvido')
        ->call('selectFilter', 'resolvidos')
        ->assertSet('filter', 'resolvidos')
        ->assertSee('Resumo resolvido')
        ->assertSee('Pagamento de agosto localizado, cobrança cancelada.')
        ->assertSee('por Renata Moura')
        ->assertSee('hoje 09:55')
        ->assertDontSee('Resumo pendente')
        ->assertDontSee('Resumo em atendimento')
        ->assertDontSee('data-escalation-assign', false)
        ->assertDontSee('data-escalation-answer', false);
});

test('actions are rendered by state, user and role', function () {
    $pending = Escalation::factory()->pendente()->for($this->resident)->create();
    $withZelador = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $this->zelador->id]);
    $withSindico = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $this->sindico->id]);

    actingInPanel($this->zelador);

    $zeladorHtml = Livewire::test('pages::escalations')->html();

    expect(cardHtml($zeladorHtml, $pending))
        ->toContain('data-escalation-assign')
        ->toContain('Assumir')
        ->not->toContain('data-escalation-answer')
        ->and(cardHtml($zeladorHtml, $withZelador))
        ->toMatch('/data-escalation-holder>\s*Com você/')
        ->toContain('data-escalation-answer')
        ->not->toContain('data-escalation-assign')
        ->and(cardHtml($zeladorHtml, $withSindico))
        ->toMatch('/data-escalation-holder>\s*Com Renata Moura/')
        ->not->toContain('data-escalation-assign')
        ->not->toContain('data-escalation-answer');

    actingInPanel($this->sindico);

    $sindicoHtml = Livewire::test('pages::escalations')->html();

    expect(cardHtml($sindicoHtml, $withZelador))
        ->toMatch('/data-escalation-holder>\s*Com Carlos Zelador/')
        ->toContain('data-escalation-assign')
        ->toContain('border-line-strong')
        ->not->toContain('data-escalation-answer')
        ->and(cardHtml($sindicoHtml, $withSindico))
        ->toMatch('/data-escalation-holder>\s*Com você/')
        ->toContain('data-escalation-answer');
});

test('assuming from the queue updates the card and notifies the badge', function () {
    $escalation = Escalation::factory()->pendente()->for($this->resident)->create();

    actingInPanel($this->zelador);

    Livewire::test('pages::escalations')
        ->call('assign', $escalation->id)
        ->assertDispatched('escalations-updated')
        ->assertDispatched('toast', type: 'success', message: 'Escalonamento assumido.')
        ->assertSee('Com você')
        ->assertSee('Responder');

    expect($escalation->fresh()->assigned_user_id)->toBe($this->zelador->id);
});

test('zelador forcing to assume an escalation of another user gets 403', function () {
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $this->sindico->id]);

    actingInPanel($this->zelador);

    Livewire::test('pages::escalations')
        ->call('assign', $escalation->id)
        ->assertForbidden();

    expect($escalation->fresh()->assigned_user_id)->toBe($this->sindico->id);
});

test('answering through the modal requires a response and resolves the escalation', function () {
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create([
        'assigned_user_id' => $this->zelador->id,
        'summary' => 'Resumo a responder',
    ]);

    actingInPanel($this->zelador);

    Livewire::test('pages::escalations')
        ->call('promptAnswer', $escalation->id)
        ->assertSet('showAnswerForm', true)
        ->assertSee('Responder morador')
        ->set('answerResponse', '   ')
        ->call('saveAnswer')
        ->assertHasErrors('answerResponse')
        ->assertSee('Informe a resposta ao morador.')
        ->assertSet('showAnswerForm', true)
        ->set('answerResponse', 'Pode usar o salão no dia 21/09.')
        ->call('saveAnswer')
        ->assertHasNoErrors()
        ->assertSet('showAnswerForm', false)
        ->assertDispatched('escalations-updated')
        ->assertDispatched('toast', type: 'success', message: 'Resposta enviada ao morador.')
        ->assertDontSee('Resumo a responder');

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::RESOLVIDO))
        ->response->toBe('Pode usar o salão no dia 21/09.')
        ->responded_by_user_id->toBe($this->zelador->id)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a user who is not the assignee cannot open the answer modal', function () {
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $this->zelador->id]);

    actingInPanel($this->sindico);

    Livewire::test('pages::escalations')
        ->call('promptAnswer', $escalation->id)
        ->assertSet('showAnswerForm', false)
        ->assertDispatched('toast', type: 'error');
});

test('escalations of another condominium are not listed nor actionable', function () {
    Escalation::factory()->pendente()->for($this->resident)->create(['summary' => 'Resumo do Aurora']);
    $foreign = Escalation::factory()->pendente()->create(['summary' => 'Resumo de outro condomínio']);

    actingInPanel($this->sindico);

    Livewire::test('pages::escalations')
        ->assertSee('Resumo do Aurora')
        ->assertDontSee('Resumo de outro condomínio')
        ->call('assign', $foreign->id)
        ->assertNotFound();

    expect(Escalation::query()->withoutGlobalScopes()->find($foreign->id)->assigned_user_id)->toBeNull();
});

test('guests are redirected to the login', function () {
    $this->get(route('escalations.index'))->assertRedirect(route('login'));
});

function cardHtml(string $html, Escalation $escalation): string
{
    preg_match('/data-escalation-card="'.$escalation->id.'".*?(?=data-escalation-card="|<\/div>\s*<\/div>\s*$)/s', $html, $matches);

    return $matches[0] ?? '';
}
