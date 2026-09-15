<?php

use App\Exceptions\EscalationActionBlockedException;
use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Resident;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Escalations\EscalationService;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();
    $this->travelTo('2026-09-15 13:00:00');

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->zelador = User::factory()->zelador()->for($this->condominium)->create();
    $this->resident = Resident::factory()->for($this->condominium)->create(['phone' => '+5511999990000']);
    $this->service = app(EscalationService::class);
});

test('the assignee answers and the escalation becomes resolvido with a webhook delivery', function () {
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create([
        'assigned_user_id' => $this->zelador->id,
        'escalation_reason_id' => EscalationReason::idFor(EscalationReason::TOOL_RECUSOU),
    ]);

    $this->service->answer($escalation, '  Liberamos o salão no dia 21/09, pode confirmar?  ', $this->zelador);

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::RESOLVIDO))
        ->response->toBe('Liberamos o salão no dia 21/09, pode confirmar?')
        ->responded_by_user_id->toBe($this->zelador->id)
        ->assigned_user_id->toBe($this->zelador->id)
        ->and($escalation->fresh()->resolved_at->toDateTimeString())->toBe('2026-09-15 13:00:00');

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->sole();

    expect($delivery->webhook_event_id)->toBe(WebhookEvent::idFor(WebhookEvent::ESCALATION_ANSWERED))
        ->and($delivery->subject_type)->toBe((new Escalation)->getMorphClass())
        ->and($delivery->subject_id)->toBe($escalation->id)
        ->and($delivery->resident_phone)->toBe('+5511999990000')
        ->and($delivery->payload['event'])->toBe('escalation.answered')
        ->and($delivery->payload['data'])->toEqual([
            'escalation_id' => $escalation->id,
            'reason' => 'tool_recusou',
            'response' => 'Liberamos o salão no dia 21/09, pode confirmar?',
        ]);

    Queue::assertPushed(SendWebhookDelivery::class, 1);
});

test('a pendente escalation cannot be answered', function () {
    $escalation = Escalation::factory()->pendente()->for($this->resident)->create();

    expect(fn () => $this->service->answer($escalation, 'Resposta', $this->sindico))
        ->toThrow(EscalationActionBlockedException::class, 'Assuma o escalonamento antes de responder.');

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::PENDENTE))
        ->response->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('whoever is not the assignee, síndico included, cannot answer', function (string $role) {
    $holder = User::factory()->zelador()->for($this->condominium)->create();
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $holder->id]);

    expect(fn () => $this->service->answer($escalation, 'Resposta', $this->{$role}))
        ->toThrow(AuthorizationException::class);

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO))
        ->response->toBeNull()
        ->responded_by_user_id->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['sindico', 'zelador']);

test('a resolved escalation is not answered again', function () {
    $escalation = Escalation::factory()->resolvido()->for($this->resident)->create([
        'assigned_user_id' => $this->sindico->id,
        'responded_by_user_id' => $this->sindico->id,
        'response' => 'Primeira resposta',
    ]);

    expect(fn () => $this->service->answer($escalation, 'Segunda resposta', $this->sindico))
        ->toThrow(EscalationActionBlockedException::class, 'Este escalonamento já foi respondido.');

    expect($escalation->fresh()->response)->toBe('Primeira resposta')
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an empty response is an error', function (string $response) {
    $escalation = Escalation::factory()->emAtendimento()->for($this->resident)->create(['assigned_user_id' => $this->sindico->id]);

    expect(fn () => $this->service->answer($escalation, $response, $this->sindico))
        ->toThrow(ValidationException::class, 'Informe a resposta ao morador.');

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO))
        ->response->toBeNull()
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['', '   ']);
