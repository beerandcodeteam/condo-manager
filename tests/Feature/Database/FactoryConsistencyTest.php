<?php

use App\Models\AgentToolCall;
use App\Models\Block;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\DocumentStatus;
use App\Models\Escalation;
use App\Models\EscalationAssignment;
use App\Models\EscalationStatus;
use App\Models\Notice;
use App\Models\Reservation;
use App\Models\ReservationOrigin;
use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Models\Role;
use App\Models\RuleArticle;
use App\Models\RuleDocument;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPhoto;
use App\Models\TicketPriority;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\ToolCallResult;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryStatus;
use App\Support\PhoneNumber;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

/**
 * @param  list<Model|null>  $related
 */
function expectSameCondominium(Model $model, array $related): void
{
    foreach ($related as $relatedModel) {
        expect($relatedModel)->not->toBeNull()
            ->and($relatedModel->condominium_id)->toBe($model->condominium_id);
    }
}

test('ticket factory keeps resident, unit and category in the same condominium', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->resident->unit_id)->toBe($ticket->unit_id);
    expectSameCondominium($ticket, [$ticket->unit, $ticket->resident, $ticket->resident->unit, $ticket->category]);
    expect(Condominium::count())->toBe(1);
});

test('ticket factory inherits unit and condominium from a given resident', function () {
    $resident = Resident::factory()->create();

    $ticket = Ticket::factory()->for($resident)->create();

    expect($ticket->unit_id)->toBe($resident->unit_id)
        ->and($ticket->condominium_id)->toBe($resident->condominium_id);
    expectSameCondominium($ticket, [$ticket->category]);
});

test('ticket factory numbers protocols sequentially per condominium', function () {
    $condominium = Condominium::factory()->create();

    $tickets = Ticket::factory()->count(3)->for($condominium)->create();

    expect($tickets->pluck('protocol_number')->all())->toBe([1, 2, 3])
        ->and($condominium->fresh()->last_ticket_protocol)->toBe(3);
});

test('reservation factory keeps area, slot, unit and resident in the same condominium', function () {
    $reservation = Reservation::factory()->create();

    expect($reservation->resident->unit_id)->toBe($reservation->unit_id)
        ->and($reservation->slot->common_area_id)->toBe($reservation->common_area_id)
        ->and($reservation->starts_at)->toBe($reservation->slot->starts_at)
        ->and($reservation->ends_at)->toBe($reservation->slot->ends_at);
    expectSameCondominium($reservation, [$reservation->area, $reservation->slot, $reservation->unit, $reservation->resident]);
    expect(Condominium::count())->toBe(1);
});

test('reservation factory inherits area and condominium from a given slot', function () {
    $slot = CommonAreaSlot::factory()->create();

    $reservation = Reservation::factory()->for($slot, 'slot')->create();

    expect($reservation->common_area_id)->toBe($slot->common_area_id);
    expectSameCondominium($reservation, [$slot, $reservation->unit, $reservation->resident]);
});

test('escalation factory keeps resident and unit in the same condominium', function (string $state) {
    $escalation = Escalation::factory()->{$state}()->create();

    expect($escalation->resident->unit_id)->toBe($escalation->unit_id);
    expectSameCondominium($escalation, [$escalation->unit, $escalation->resident]);
    expect(Condominium::count())->toBe(1);
})->with(['pendente', 'emAtendimento', 'resolvido']);

test('escalation states set status and responsible user', function () {
    $pending = Escalation::factory()->pendente()->create();
    $inProgress = Escalation::factory()->emAtendimento()->create();
    $resolved = Escalation::factory()->resolvido()->create();

    expect($pending->status->slug)->toBe(EscalationStatus::PENDENTE)
        ->and($pending->assigned_user_id)->toBeNull()
        ->and($inProgress->status->slug)->toBe(EscalationStatus::EM_ATENDIMENTO)
        ->and($inProgress->assignedUser->condominium_id)->toBe($inProgress->condominium_id)
        ->and($resolved->status->slug)->toBe(EscalationStatus::RESOLVIDO)
        ->and($resolved->responded_by_user_id)->toBe($resolved->assigned_user_id)
        ->and($resolved->resolved_at)->not->toBeNull();
});

test('child factories share the condominium of their parents', function () {
    $toolCallResident = Resident::factory()->create();
    $records = [
        TicketPhoto::factory()->create(),
        TicketStatusChange::factory()->create(),
        TicketResidentNotice::factory()->create(),
        EscalationAssignment::factory()->create(),
        RuleArticle::factory()->create(),
        CommonAreaSlot::factory()->create(),
        Unit::factory()->withBlock()->create(),
        Notice::factory()->create(),
        WebhookDelivery::factory()->create(),
        AgentToolCall::factory()->for($toolCallResident)->create(),
    ];

    expectSameCondominium($records[0], [$records[0]->ticket]);
    expectSameCondominium($records[1], [$records[1]->ticket]);
    expectSameCondominium($records[2], [$records[2]->ticket, $records[2]->user]);
    expectSameCondominium($records[3], [$records[3]->escalation, $records[3]->user]);
    expectSameCondominium($records[4], [$records[4]->ruleDocument, $records[4]->ruleDocument->uploadedBy]);
    expectSameCondominium($records[5], [$records[5]->area]);
    expectSameCondominium($records[6], [$records[6]->block]);
    expectSameCondominium($records[7], [$records[7]->createdBy]);
    expectSameCondominium($records[8], [$records[8]->subject]);
    expectSameCondominium($records[9], [$toolCallResident]);
    expect($records[9]->phone)->toBe($toolCallResident->phone);
});

test('recycled condominium is shared by every generated record', function () {
    $condominium = Condominium::factory()->create();

    $ticket = Ticket::factory()->recycle($condominium)->fromPanel()->create();
    $reservation = Reservation::factory()->recycle($condominium)->manual()->create();

    expect(Condominium::count())->toBe(1)
        ->and($ticket->condominium_id)->toBe($condominium->id)
        ->and($reservation->condominium_id)->toBe($condominium->id);
    expectSameCondominium($ticket, [$ticket->openedBy, $ticket->category]);
    expectSameCondominium($reservation, [$reservation->createdBy, $reservation->slot, $reservation->resident]);
});

test('user factory states set role, condominium and activity', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->condominium_id)->toBeNull()
        ->and(User::factory()->sindico()->create()->isSindico())->toBeTrue()
        ->and(User::factory()->zelador()->create()->isZelador())->toBeTrue()
        ->and(User::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('resident factory states and phones', function () {
    $resident = Resident::factory()->create();

    expect(PhoneNumber::normalize($resident->phone))->toBe($resident->phone)
        ->and(Resident::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(Resident::factory()->owner()->create()->residentProfile->slug)->toBe(ResidentProfile::PROPRIETARIO)
        ->and(Resident::factory()->tenant()->create()->residentProfile->slug)->toBe(ResidentProfile::INQUILINO);
});

test('rule document factory has one state per status', function (string $state, string $statusSlug) {
    expect(RuleDocument::factory()->{$state}()->create()->documentStatus->slug)->toBe($statusSlug);
})->with([
    'processando' => ['processando', DocumentStatus::PROCESSANDO],
    'em revisão' => ['emRevisao', DocumentStatus::EM_REVISAO],
    'falha na extração' => ['falhaExtracao', DocumentStatus::FALHA_EXTRACAO],
    'indexando' => ['indexando', DocumentStatus::INDEXANDO],
    'falha na indexação' => ['falhaIndexacao', DocumentStatus::FALHA_INDEXACAO],
    'publicado' => ['publicado', DocumentStatus::PUBLICADO],
    'substituído' => ['substituido', DocumentStatus::SUBSTITUIDO],
]);

test('rule article withEmbedding stores a 1536 dimension vector', function () {
    $article = RuleArticle::factory()->withEmbedding()->create()->fresh();

    expect($article->embedding)->toHaveCount(1536)
        ->and($article->embedded_at)->not->toBeNull();
});

test('ticket factory states', function () {
    expect(Ticket::factory()->aberto()->create()->status->slug)->toBe(TicketStatus::ABERTO)
        ->and(Ticket::factory()->emAndamento()->create()->status->slug)->toBe(TicketStatus::EM_ANDAMENTO)
        ->and(Ticket::factory()->resolvido()->create()->status->slug)->toBe(TicketStatus::RESOLVIDO)
        ->and(Ticket::factory()->cancelado()->create()->status->slug)->toBe(TicketStatus::CANCELADO)
        ->and(Ticket::factory()->highPriority()->create()->priority->slug)->toBe(TicketPriority::ALTA);

    $panelTicket = Ticket::factory()->fromPanel()->create();

    expect($panelTicket->origin->slug)->toBe(TicketOrigin::PAINEL)
        ->and($panelTicket->openedBy)->not->toBeNull()
        ->and($panelTicket->resident_id)->toBeNull();
});

test('reservation, notice, webhook and tool call factory states', function () {
    $cancelled = Reservation::factory()->cancelled()->create();

    expect($cancelled->cancelled_at)->not->toBeNull()
        ->and(Reservation::active()->count())->toBe(0)
        ->and(Reservation::factory()->manual()->create()->origin->slug)->toBe(ReservationOrigin::PAINEL)
        ->and(Notice::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(WebhookDelivery::factory()->enviado()->create()->status->slug)->toBe(WebhookDeliveryStatus::ENVIADO)
        ->and(WebhookDelivery::factory()->falhou()->create()->status->slug)->toBe(WebhookDeliveryStatus::FALHOU)
        ->and(AgentToolCall::factory()->sucesso()->create()->result->slug)->toBe(ToolCallResult::SUCESSO)
        ->and(AgentToolCall::factory()->vazio()->create()->result->slug)->toBe(ToolCallResult::VAZIO)
        ->and(AgentToolCall::factory()->recusa()->create()->result->slug)->toBe(ToolCallResult::RECUSA);
});

test('unit, block, category and area factories create valid records', function () {
    expect(Block::factory()->create()->units()->count())->toBe(0)
        ->and(Unit::factory()->create()->block_id)->toBeNull()
        ->and(TicketCategory::factory()->create()->is_active)->toBeTrue()
        ->and(CommonArea::factory()->create()->min_advance_hours)->toBe(24)
        ->and(User::factory()->create()->role->slug)->toBe(Role::SINDICO);
});
