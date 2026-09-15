<?php

namespace App\Services\Escalations;

use App\Exceptions\Api\EscalationTicketNotFound;
use App\Exceptions\EscalationActionBlockedException;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationAssignment;
use App\Models\EscalationReason;
use App\Models\EscalationStatus;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Integration\WebhookService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Rules of human escalations shared by the agent API and the panel: creation by the agent, the panel queue,
 * assignment to a team member and the answer to the resident.
 */
class EscalationService
{
    /**
     * Largest protocol number that fits the `tickets.protocol_number` integer column.
     */
    public const MAX_PROTOCOL = 2147483647;

    public const PER_PAGE = 25;

    public const FILTER_OPEN = 'abertos';

    public const FILTER_RESOLVED = 'resolvidos';

    /**
     * Queue filters of the panel and the statuses each one lists.
     *
     * @var array<string, list<string>>
     */
    public const FILTER_STATUSES = [
        self::FILTER_OPEN => [EscalationStatus::PENDENTE, EscalationStatus::EM_ATENDIMENTO],
        self::FILTER_RESOLVED => [EscalationStatus::RESOLVIDO],
    ];

    /**
     * Card dot color by reason slug; reasons not listed use `#f5a623`.
     *
     * @var array<string, string>
     */
    public const REASON_DOTS = [
        EscalationReason::PEDIU_HUMANO => '#e5484d',
    ];

    public const DEFAULT_REASON_DOT = '#f5a623';

    public function __construct(private WebhookService $webhookService) {}

    /**
     * Escalations of the condominium in the panel queue, oldest first, with what the cards show.
     *
     * @return LengthAwarePaginator<int, Escalation>
     */
    public function queue(Condominium $condominium, string $filter = self::FILTER_OPEN): LengthAwarePaginator
    {
        $statuses = self::FILTER_STATUSES[$filter] ?? self::FILTER_STATUSES[self::FILTER_OPEN];

        return $condominium->escalations()
            ->whereIn('escalation_status_id', array_map(EscalationStatus::idFor(...), $statuses))
            ->with(['reason', 'resident', 'unit.block', 'ticket', 'assignedUser', 'respondedBy'])
            ->oldest()
            ->oldest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Number of `pendente` escalations of the condominium, shown in the sidebar badge.
     */
    public function pendingCount(Condominium $condominium): int
    {
        return $condominium->escalations()
            ->where('escalation_status_id', EscalationStatus::idFor(EscalationStatus::PENDENTE))
            ->count();
    }

    /**
     * Waiting time since the moment: "14 min" under an hour, "1 h 05" from then on.
     */
    public static function waitLabel(CarbonInterface $since, ?CarbonInterface $now = null): string
    {
        $minutes = max(0, (int) floor($since->diffInMinutes($now ?? now())));

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        return sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function reasonDot(string $reasonSlug): string
    {
        return self::REASON_DOTS[$reasonSlug] ?? self::DEFAULT_REASON_DOT;
    }

    /**
     * Create a `pendente` escalation for the resident, in the resident's unit, optionally linked to a ticket of that unit.
     *
     * @throws EscalationTicketNotFound when the protocol does not exist or belongs to another unit
     */
    public function create(Resident $resident, string $reasonSlug, string $summary, ?int $ticketProtocol = null): Escalation
    {
        $ticket = $ticketProtocol === null ? null : $this->ticketOfUnit($resident, $ticketProtocol);

        $escalation = new Escalation([
            'resident_id' => $resident->id,
            'unit_id' => $resident->unit_id,
            'ticket_id' => $ticket?->id,
            'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::PENDENTE),
            'escalation_reason_id' => EscalationReason::idFor($reasonSlug),
            'summary' => trim($summary),
        ]);

        $escalation->condominium_id = $resident->condominium_id;
        $escalation->save();

        return $escalation;
    }

    /**
     * Make the user responsible for the escalation: a `pendente` one moves to `em_atendimento`; one already
     * `em_atendimento` with someone else changes hands only for users allowed by `escalations.reassign`.
     * Every change records an assignment (with the replaced user) and never dispatches webhooks.
     * Taking an escalation the user already holds changes nothing.
     *
     * @throws EscalationActionBlockedException when the escalation is resolved
     * @throws AuthorizationException when another user holds it and the user cannot reassign
     */
    public function assign(Escalation $escalation, User $user): Escalation
    {
        return DB::transaction(function () use ($escalation, $user): Escalation {
            $this->refreshLocked($escalation);

            if ($escalation->hasStatus(EscalationStatus::RESOLVIDO)) {
                throw new EscalationActionBlockedException('Escalonamento resolvido não pode ser assumido.');
            }

            $previousUserId = $escalation->hasStatus(EscalationStatus::EM_ATENDIMENTO) ? $escalation->assigned_user_id : null;

            if ($previousUserId === $user->id) {
                return $escalation;
            }

            if ($previousUserId !== null) {
                Gate::forUser($user)->authorize('escalations.reassign');
            }

            $escalation->forceFill([
                'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::EM_ATENDIMENTO),
                'assigned_user_id' => $user->id,
                'assigned_at' => now(),
            ])->save();

            $escalation->unsetRelation('status');
            $escalation->unsetRelation('assignedUser');

            $assignment = new EscalationAssignment([
                'escalation_id' => $escalation->id,
                'user_id' => $user->id,
                'previous_user_id' => $previousUserId,
            ]);

            $assignment->condominium_id = $escalation->condominium_id;
            $assignment->save();

            return $escalation;
        });
    }

    /**
     * Record the answer of the assignee to the resident, resolving the escalation and dispatching
     * `escalation.answered` with `{escalation_id, reason, response}`.
     *
     * @throws EscalationActionBlockedException when the escalation is not `em_atendimento`
     * @throws AuthorizationException when the user is not the assignee
     * @throws ValidationException when the response is empty
     */
    public function answer(Escalation $escalation, string $response, User $user): Escalation
    {
        $response = trim($response);

        return DB::transaction(function () use ($escalation, $response, $user): Escalation {
            $this->refreshLocked($escalation);

            if ($escalation->hasStatus(EscalationStatus::RESOLVIDO)) {
                throw new EscalationActionBlockedException('Este escalonamento já foi respondido.');
            }

            if (! $escalation->hasStatus(EscalationStatus::EM_ATENDIMENTO)) {
                throw new EscalationActionBlockedException('Assuma o escalonamento antes de responder.');
            }

            if ($escalation->assigned_user_id !== $user->id) {
                throw new AuthorizationException('Só o responsável pelo escalonamento pode respondê-lo.');
            }

            if ($response === '') {
                throw ValidationException::withMessages(['response' => 'Informe a resposta ao morador.']);
            }

            $escalation->forceFill([
                'escalation_status_id' => EscalationStatus::idFor(EscalationStatus::RESOLVIDO),
                'response' => $response,
                'responded_by_user_id' => $user->id,
                'resolved_at' => now(),
            ])->save();

            $escalation->unsetRelation('status');
            $escalation->unsetRelation('respondedBy');

            $this->webhookService->dispatch(WebhookEvent::ESCALATION_ANSWERED, $escalation, $escalation->resident->phone, [
                'escalation_id' => $escalation->id,
                'reason' => $escalation->reason->slug,
                'response' => $response,
            ]);

            return $escalation;
        });
    }

    /**
     * @throws EscalationTicketNotFound
     */
    private function ticketOfUnit(Resident $resident, int $protocol): Ticket
    {
        if ($protocol < 1 || $protocol > self::MAX_PROTOCOL) {
            throw new EscalationTicketNotFound;
        }

        return Ticket::query()
            ->withoutGlobalScopes()
            ->where('condominium_id', $resident->condominium_id)
            ->where('unit_id', $resident->unit_id)
            ->where('protocol_number', $protocol)
            ->first() ?? throw new EscalationTicketNotFound;
    }

    /**
     * Reload the escalation attributes under a row lock, so concurrent actions see the latest state.
     */
    private function refreshLocked(Escalation $escalation): void
    {
        $lockedEscalation = Escalation::query()->withoutGlobalScopes()->whereKey($escalation->id)->lockForUpdate()->firstOrFail();

        $escalation->setRawAttributes($lockedEscalation->getAttributes(), true);
    }
}
