<?php

use App\Exceptions\EscalationActionBlockedException;
use App\Jobs\SendWebhookDelivery;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationAssignment;
use App\Models\EscalationStatus;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Escalations\EscalationService;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Queue::fake();
    $this->travelTo('2026-09-15 13:00:00');

    $this->condominium = Condominium::factory()->withWebhook()->create();
    $this->sindico = User::factory()->sindico()->for($this->condominium)->create();
    $this->zelador = User::factory()->zelador()->for($this->condominium)->create();
    $this->service = app(EscalationService::class);
});

test('zelador assumes a pendente escalation', function () {
    $escalation = Escalation::factory()->pendente()->for($this->condominium)->create();

    $this->service->assign($escalation, $this->zelador);

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO))
        ->assigned_user_id->toBe($this->zelador->id)
        ->and($escalation->fresh()->assigned_at->toDateTimeString())->toBe('2026-09-15 13:00:00')
        ->and(EscalationAssignment::query()->withoutGlobalScopes()->sole())
        ->escalation_id->toBe($escalation->id)
        ->condominium_id->toBe($this->condominium->id)
        ->user_id->toBe($this->zelador->id)
        ->previous_user_id->toBeNull();
});

test('síndico assumes in place of the zelador recording previous_user_id', function () {
    $escalation = Escalation::factory()->pendente()->for($this->condominium)->create();
    $this->service->assign($escalation, $this->zelador);

    $this->travel(10)->minutes();
    $this->service->assign($escalation, $this->sindico);

    $assignments = EscalationAssignment::query()->withoutGlobalScopes()->orderBy('id')->get();

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO))
        ->assigned_user_id->toBe($this->sindico->id)
        ->and($escalation->fresh()->assigned_at->toDateTimeString())->toBe('2026-09-15 13:10:00')
        ->and($assignments)->toHaveCount(2)
        ->and($assignments[1])
        ->user_id->toBe($this->sindico->id)
        ->previous_user_id->toBe($this->zelador->id);
});

test('zelador cannot assume an escalation held by another user', function () {
    $escalation = Escalation::factory()->emAtendimento()->for($this->condominium)->create(['assigned_user_id' => $this->sindico->id]);

    expect(fn () => $this->service->assign($escalation, $this->zelador))
        ->toThrow(AuthorizationException::class);

    expect($escalation->fresh()->assigned_user_id)->toBe($this->sindico->id)
        ->and(EscalationAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the current assignee assuming again changes nothing', function () {
    $assignedAt = now()->subHour();
    $escalation = Escalation::factory()->emAtendimento()->for($this->condominium)->create([
        'assigned_user_id' => $this->zelador->id,
        'assigned_at' => $assignedAt,
    ]);

    $this->service->assign($escalation, $this->zelador);

    expect($escalation->fresh()->assigned_at->toDateTimeString())->toBe($assignedAt->toDateTimeString())
        ->and($escalation->fresh()->assigned_user_id)->toBe($this->zelador->id)
        ->and(EscalationAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a resolved escalation cannot be assumed', function () {
    $escalation = Escalation::factory()->resolvido()->for($this->condominium)->create();
    $responderId = $escalation->assigned_user_id;

    expect(fn () => $this->service->assign($escalation, $this->sindico))
        ->toThrow(EscalationActionBlockedException::class, 'Escalonamento resolvido não pode ser assumido.');

    expect($escalation->fresh())
        ->escalation_status_id->toBe(EscalationStatus::idFor(EscalationStatus::RESOLVIDO))
        ->assigned_user_id->toBe($responderId)
        ->and(EscalationAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('assuming and reassigning dispatch no webhook', function () {
    $escalation = Escalation::factory()->pendente()->for($this->condominium)->create();

    $this->service->assign($escalation, $this->zelador);
    $this->service->assign($escalation, $this->sindico);

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);

    Queue::assertNotPushed(SendWebhookDelivery::class);
});
