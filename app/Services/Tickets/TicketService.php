<?php

namespace App\Services\Tickets;

use App\Exceptions\Api\InvalidCategory;
use App\Exceptions\TicketActionBlockedException;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPhoto;
use App\Models\TicketPriority;
use App\Models\TicketResidentNotice;
use App\Models\TicketStatus;
use App\Models\TicketStatusChange;
use App\Models\Unit;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Integration\WebhookService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Rules of maintenance tickets shared by the agent API and the panel: protocol sequence, opening,
 * status lifecycle, priority and notices to the resident.
 */
class TicketService
{
    /**
     * Private disk where ticket photos are stored, under `tickets/{condominium_id}/{ticket_id}/`.
     */
    public const PHOTO_DISK = 'local';

    /**
     * Allowed status transitions; `resolvido` and `cancelado` are final.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        TicketStatus::ABERTO => [TicketStatus::EM_ANDAMENTO, TicketStatus::RESOLVIDO, TicketStatus::CANCELADO],
        TicketStatus::EM_ANDAMENTO => [TicketStatus::RESOLVIDO, TicketStatus::CANCELADO],
    ];

    /**
     * Statuses that can only be reached with a comment.
     *
     * @var list<string>
     */
    public const COMMENT_REQUIRED_STATUSES = [TicketStatus::RESOLVIDO, TicketStatus::CANCELADO];

    public const API_STATUS_OPEN = 'open';

    public const API_STATUS_CLOSED = 'closed';

    public const API_STATUS_ALL = 'all';

    /**
     * Status filters of the agent API: `open` (aberto + em_andamento), `closed` (resolvido + cancelado) or `all`.
     *
     * @var list<string>
     */
    public const API_STATUS_FILTERS = [self::API_STATUS_OPEN, self::API_STATUS_CLOSED, self::API_STATUS_ALL];

    public const API_LIST_LIMIT = 10;

    public const PER_PAGE = 25;

    public const TAB_ALL = 'todos';

    public const TAB_OPEN = 'abertos';

    public const TAB_IN_PROGRESS = 'em-andamento';

    public const TAB_DONE = 'concluidos';

    /**
     * Panel tabs and the statuses each one lists.
     *
     * @var array<string, list<string>>
     */
    public const TAB_STATUSES = [
        self::TAB_ALL => [TicketStatus::ABERTO, TicketStatus::EM_ANDAMENTO, TicketStatus::RESOLVIDO, TicketStatus::CANCELADO],
        self::TAB_OPEN => [TicketStatus::ABERTO],
        self::TAB_IN_PROGRESS => [TicketStatus::EM_ANDAMENTO],
        self::TAB_DONE => [TicketStatus::RESOLVIDO, TicketStatus::CANCELADO],
    ];

    /**
     * Timeline dot color by status slug, plus `notice` for resident notices.
     *
     * @var array<string, string>
     */
    public const TIMELINE_DOTS = [
        TicketStatus::ABERTO => '#5a5bd9',
        TicketStatus::EM_ANDAMENTO => '#f5a623',
        TicketStatus::RESOLVIDO => '#2fb35b',
        TicketStatus::CANCELADO => '#8e8e93',
        'notice' => '#30a4c9',
    ];

    public function __construct(private WebhookService $webhookService) {}

    /**
     * Tickets of the condominium for the panel list, most recently opened first.
     *
     * @param  array{tab?: string|null, category_id?: int|null, priority?: string|null, block_id?: int|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(Condominium $condominium, array $filters = []): LengthAwarePaginator
    {
        $statuses = self::TAB_STATUSES[$filters['tab'] ?? self::TAB_ALL] ?? self::TAB_STATUSES[self::TAB_ALL];

        return $this->filteredQuery($condominium, $filters)
            ->whereIn('tickets.ticket_status_id', array_map(TicketStatus::idFor(...), $statuses))
            ->with(['status', 'priority', 'category', 'unit.block', 'openedBy'])
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Ticket count of each panel tab, honoring the other list filters.
     *
     * @param  array{category_id?: int|null, priority?: string|null, block_id?: int|null, search?: string|null}  $filters
     * @return array<string, int>
     */
    public function tabCounts(Condominium $condominium, array $filters = []): array
    {
        $countsByStatus = $this->filteredQuery($condominium, $filters)
            ->toBase()
            ->selectRaw('ticket_status_id, count(*) as aggregate')
            ->groupBy('ticket_status_id')
            ->pluck('aggregate', 'ticket_status_id');

        return collect(self::TAB_STATUSES)
            ->map(fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($countsByStatus[TicketStatus::idFor($status)] ?? 0)))
            ->all();
    }

    /**
     * Status changes and resident notices of the ticket merged in chronological order, as shown in the drawer.
     *
     * @return list<array{type: string, text: string, at: CarbonInterface|null, by: string|null, dot: string}>
     */
    public function timeline(Ticket $ticket): array
    {
        $ticket->loadMissing(['statusChanges.toStatus', 'statusChanges.user', 'residentNotices.user']);

        $openedByAgent = $ticket->ticket_origin_id === TicketOrigin::idFor(TicketOrigin::WHATSAPP);

        $statusEntries = $ticket->statusChanges->map(fn (TicketStatusChange $statusChange): array => [
            'type' => 'status',
            'id' => $statusChange->id,
            'text' => match (true) {
                $statusChange->from_ticket_status_id === null => $openedByAgent ? 'Chamado aberto pelo agente' : 'Chamado aberto manualmente',
                filled($statusChange->comment) => "{$statusChange->toStatus->name} · {$statusChange->comment}",
                default => $statusChange->toStatus->name,
            },
            'at' => $statusChange->created_at,
            'by' => $statusChange->user?->name,
            'dot' => self::TIMELINE_DOTS[$statusChange->toStatus->slug] ?? self::TIMELINE_DOTS['notice'],
        ]);

        $noticeEntries = $ticket->residentNotices->map(fn (TicketResidentNotice $notice): array => [
            'type' => 'notice',
            'id' => $notice->id,
            'text' => "Aviso ao morador: {$notice->message}",
            'at' => $notice->created_at,
            'by' => $notice->user?->name,
            'dot' => self::TIMELINE_DOTS['notice'],
        ]);

        return array_values($statusEntries->concat($noticeEntries)
            ->sortBy([
                fn (array $a, array $b): int => $a['at']?->getTimestamp() <=> $b['at']?->getTimestamp(),
                fn (array $a, array $b): int => ($a['type'] === 'notice') <=> ($b['type'] === 'notice'),
                fn (array $a, array $b): int => $a['id'] <=> $b['id'],
            ])
            ->map(fn (array $entry): array => [
                'type' => $entry['type'],
                'text' => $entry['text'],
                'at' => $entry['at'],
                'by' => $entry['by'],
                'dot' => $entry['dot'],
            ])
            ->all());
    }

    /**
     * Latest tickets of the unit for the agent, most recent first, limited to API_LIST_LIMIT.
     *
     * @return Collection<int, Ticket>
     */
    public function latestOfUnit(Unit $unit, string $statusFilter = self::API_STATUS_ALL): Collection
    {
        return $unit->tickets()
            ->when($statusFilter === self::API_STATUS_OPEN, fn (Builder $query) => $query->open())
            ->when($statusFilter === self::API_STATUS_CLOSED, fn (Builder $query) => $query->closed())
            ->with(['category', 'priority', 'status'])
            ->latest()
            ->latest('id')
            ->limit(self::API_LIST_LIMIT)
            ->get();
    }

    /**
     * Next protocol number of the condominium: continuous, never restarts and independent per condominium.
     * Locks the condominium row, so it must run inside the transaction that opens the ticket.
     */
    public function nextProtocol(Condominium $condominium): int
    {
        return DB::transaction(function () use ($condominium): int {
            $lastProtocol = (int) Condominium::query()
                ->whereKey($condominium->id)
                ->lockForUpdate()
                ->value('last_ticket_protocol');

            $nextProtocol = $lastProtocol + 1;

            Condominium::query()->whereKey($condominium->id)->toBase()->update(['last_ticket_protocol' => $nextProtocol]);

            $condominium->forceFill(['last_ticket_protocol' => $nextProtocol])->syncOriginalAttribute('last_ticket_protocol');

            return $nextProtocol;
        });
    }

    /**
     * Open an `aberto` ticket with its protocol, the initial status history entry and the photos, all in one transaction.
     * Opening never dispatches webhooks. Photo files already written are removed when the transaction fails.
     *
     * @param  int|string|null  $category  category id or slug; must be active
     * @param  list<UploadedFile>  $photos
     *
     * @throws InvalidCategory
     * @throws ValidationException
     */
    public function open(
        Condominium $condominium,
        string $description,
        string $origin,
        ?string $location = null,
        int|string|null $category = null,
        ?string $priority = null,
        Unit|int|null $unit = null,
        Resident|int|null $resident = null,
        ?User $user = null,
        array $photos = [],
    ): Ticket {
        $description = trim($description);
        $location = filled($location) ? trim($location) : null;
        $priority = filled($priority) ? $priority : TicketPriority::MEDIA;

        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'A descrição do chamado é obrigatória.']);
        }

        if (! in_array($priority, [TicketPriority::ALTA, TicketPriority::MEDIA, TicketPriority::BAIXA], true)) {
            throw ValidationException::withMessages(['priority' => 'Prioridade inválida.']);
        }

        $ticketCategory = $this->resolveCategory($condominium, $category);
        $residentModel = $this->resolveResident($condominium, $resident);
        $unitModel = $this->resolveUnit($condominium, $unit) ?? $residentModel?->unit;

        if ($residentModel !== null && $residentModel->unit_id !== $unitModel?->id) {
            throw ValidationException::withMessages(['resident_id' => 'O morador não pertence à unidade informada.']);
        }

        if ($origin === TicketOrigin::WHATSAPP && $residentModel === null) {
            throw ValidationException::withMessages(['resident_id' => 'Chamado aberto pelo WhatsApp precisa de um morador.']);
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($condominium, $description, $origin, $location, $ticketCategory, $priority, $unitModel, $residentModel, $user, $photos, &$storedPaths): Ticket {
                $ticket = new Ticket([
                    'ticket_status_id' => TicketStatus::idFor(TicketStatus::ABERTO),
                    'ticket_priority_id' => TicketPriority::idFor($priority),
                    'ticket_origin_id' => TicketOrigin::idFor($origin),
                    'ticket_category_id' => $ticketCategory?->id,
                    'unit_id' => $unitModel?->id,
                    'resident_id' => $residentModel?->id,
                    'opened_by_user_id' => $user?->id,
                    'description' => $description,
                    'location' => $location,
                ]);

                $ticket->condominium_id = $condominium->id;
                $ticket->protocol_number = $this->nextProtocol($condominium);
                $ticket->save();

                $this->recordStatusChange($ticket, null, TicketStatus::ABERTO, null, $user);

                foreach ($photos as $photo) {
                    $path = $photo->store($this->photoDirectory($ticket), self::PHOTO_DISK);

                    if ($path === false) {
                        throw new RuntimeException('Não foi possível salvar a foto do chamado.');
                    }

                    $storedPaths[] = $path;

                    $ticketPhoto = new TicketPhoto([
                        'ticket_id' => $ticket->id,
                        'file_path' => $path,
                        'mime_type' => $photo->getMimeType() ?? $photo->getClientMimeType(),
                        'size_bytes' => (int) $photo->getSize(),
                    ]);

                    $ticketPhoto->condominium_id = $condominium->id;
                    $ticketPhoto->save();
                }

                return $ticket;
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk(self::PHOTO_DISK)->delete($storedPaths);
            }

            throw $exception;
        }
    }

    /**
     * Move the ticket to another status, recording the history entry and, when a resident is linked,
     * dispatching `ticket.status_changed` with `{protocol, status, comment}`.
     *
     * @throws TicketActionBlockedException when the ticket is final or the transition is not allowed
     * @throws ValidationException when the comment is missing for `resolvido` or `cancelado`
     */
    public function changeStatus(Ticket $ticket, string $to, ?string $comment, User $user): Ticket
    {
        $comment = filled($comment) ? trim($comment) : null;

        return DB::transaction(function () use ($ticket, $to, $comment, $user): Ticket {
            $lockedTicket = Ticket::query()->withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $ticket->setRawAttributes($lockedTicket->getAttributes(), true);
            $ticket->unsetRelation('status');

            $from = $ticket->status->slug;

            if ($ticket->status->is_final) {
                throw new TicketActionBlockedException('Chamado finalizado não pode mudar de status.');
            }

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new TicketActionBlockedException('Transição de status inválida.');
            }

            if ($comment === null && in_array($to, self::COMMENT_REQUIRED_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'comment' => $to === TicketStatus::RESOLVIDO
                        ? 'Informe um comentário para concluir o chamado.'
                        : 'Informe um comentário para cancelar o chamado.',
                ]);
            }

            $ticket->ticket_status_id = TicketStatus::idFor($to);
            $ticket->save();
            $ticket->unsetRelation('status');

            $this->recordStatusChange($ticket, $from, $to, $comment, $user);

            if ($ticket->resident_id !== null) {
                $this->webhookService->dispatch(WebhookEvent::TICKET_STATUS_CHANGED, $ticket, $ticket->resident?->phone, [
                    'protocol' => $ticket->protocol_number,
                    'status' => $to,
                    'comment' => $comment,
                ]);
            }

            return $ticket;
        });
    }

    /**
     * Change the priority of a non-final ticket. No status history entry and no webhook.
     *
     * @throws TicketActionBlockedException when the ticket is final
     * @throws ValidationException when the priority does not exist
     */
    public function changePriority(Ticket $ticket, string $prioritySlug): Ticket
    {
        if ($ticket->isFinal()) {
            throw new TicketActionBlockedException('Chamado finalizado não pode mudar de prioridade.');
        }

        if (! in_array($prioritySlug, [TicketPriority::ALTA, TicketPriority::MEDIA, TicketPriority::BAIXA], true)) {
            throw ValidationException::withMessages(['priority' => 'Prioridade inválida.']);
        }

        $ticket->ticket_priority_id = TicketPriority::idFor($prioritySlug);
        $ticket->save();
        $ticket->unsetRelation('priority');

        return $ticket;
    }

    /**
     * Send a free message to the resident of the ticket, in any status: records the notice and dispatches
     * `ticket.resident_notified` with `{protocol, message}`. `webhook_delivery_id` stays null when the
     * condominium has no webhook configured.
     *
     * @throws TicketActionBlockedException when the ticket has no resident
     * @throws ValidationException when the message is empty or longer than the limit
     */
    public function notifyResident(Ticket $ticket, string $message, User $user): TicketResidentNotice
    {
        if ($ticket->resident_id === null) {
            throw new TicketActionBlockedException('Chamado sem morador vinculado não pode receber aviso.');
        }

        $message = trim($message);
        $maxLength = (int) config('condo.tickets.notice_max_length');

        Validator::make(['message' => $message], ['message' => ['required', 'string', "max:{$maxLength}"]], attributes: ['message' => 'mensagem'])->validate();

        return DB::transaction(function () use ($ticket, $message, $user): TicketResidentNotice {
            $delivery = $this->webhookService->dispatch(WebhookEvent::TICKET_RESIDENT_NOTIFIED, $ticket, $ticket->resident?->phone, [
                'protocol' => $ticket->protocol_number,
                'message' => $message,
            ]);

            $notice = new TicketResidentNotice([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $message,
                'webhook_delivery_id' => $delivery?->id,
            ]);

            $notice->condominium_id = $ticket->condominium_id;
            $notice->save();

            return $notice;
        });
    }

    public function photoDirectory(Ticket $ticket): string
    {
        return "tickets/{$ticket->condominium_id}/{$ticket->id}";
    }

    /**
     * @param  array{category_id?: int|null, priority?: string|null, block_id?: int|null, search?: string|null}  $filters
     * @return Builder<Ticket>
     */
    private function filteredQuery(Condominium $condominium, array $filters): Builder
    {
        $search = ltrim(trim((string) ($filters['search'] ?? '')), '#');

        return $condominium->tickets()->getQuery()
            ->when(filled($filters['category_id'] ?? null), fn (Builder $query) => $query->where('tickets.ticket_category_id', $filters['category_id']))
            ->when(filled($filters['priority'] ?? null), fn (Builder $query) => $query->where('tickets.ticket_priority_id', TicketPriority::query()->slug((string) $filters['priority'])->value('id')))
            ->when(filled($filters['block_id'] ?? null), fn (Builder $query) => $query->whereIn('tickets.unit_id', Unit::query()->select('id')->where('block_id', $filters['block_id'])))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('tickets.description', "%{$search}%");

                if (ctype_digit($search) && strlen($search) <= 9) {
                    $query->orWhere('tickets.protocol_number', (int) $search);
                }
            }));
    }

    /**
     * @throws InvalidCategory when the category does not exist in the condominium or is inactive
     */
    private function resolveCategory(Condominium $condominium, int|string|null $category): ?TicketCategory
    {
        if ($category === null || $category === '') {
            return null;
        }

        $query = $condominium->ticketCategories()->active();

        $ticketCategory = is_int($category)
            ? $query->whereKey($category)->first()
            : $query->where('slug', $category)->first();

        return $ticketCategory ?? throw new InvalidCategory;
    }

    /**
     * @throws ValidationException
     */
    private function resolveUnit(Condominium $condominium, Unit|int|null $unit): ?Unit
    {
        if ($unit === null) {
            return null;
        }

        $unitModel = $unit instanceof Unit ? $unit : $condominium->units()->find($unit);

        if ($unitModel === null || $unitModel->condominium_id !== $condominium->id) {
            throw ValidationException::withMessages(['unit_id' => 'Unidade não encontrada neste condomínio.']);
        }

        return $unitModel;
    }

    /**
     * @throws ValidationException
     */
    private function resolveResident(Condominium $condominium, Resident|int|null $resident): ?Resident
    {
        if ($resident === null) {
            return null;
        }

        $residentModel = $resident instanceof Resident ? $resident : $condominium->residents()->find($resident);

        if ($residentModel === null || $residentModel->condominium_id !== $condominium->id) {
            throw ValidationException::withMessages(['resident_id' => 'Morador não encontrado neste condomínio.']);
        }

        return $residentModel;
    }

    private function recordStatusChange(Ticket $ticket, ?string $fromStatus, string $toStatus, ?string $comment, ?User $user): TicketStatusChange
    {
        $statusChange = new TicketStatusChange([
            'ticket_id' => $ticket->id,
            'from_ticket_status_id' => $fromStatus === null ? null : TicketStatus::idFor($fromStatus),
            'to_ticket_status_id' => TicketStatus::idFor($toStatus),
            'comment' => $comment,
            'user_id' => $user?->id,
        ]);

        $statusChange->condominium_id = $ticket->condominium_id;
        $statusChange->save();

        return $statusChange;
    }
}
