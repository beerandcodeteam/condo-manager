<?php

namespace App\Support;

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Reservation;
use App\Models\RuleArticle;
use App\Models\Ticket;
use App\Models\ToolCallResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * How a tool call reads in the agent activity feed: who made it, what they did and the pill that sums up the outcome.
 *
 * @phpstan-type Presentation array{who: string, text: string, pill: array{text: string, variant: string}}
 */
class ToolCallPresenter
{
    /**
     * Text of each tool when the outcome does not change it (and for any `recusa`).
     *
     * @var array<string, string>
     */
    public const TEXTS = [
        AgentTool::RESIDENTS_LOOKUP => 'foi identificado',
        AgentTool::RULES_SEARCH => 'consultou o regimento',
        AgentTool::NOTICES_LIST => 'consultou comunicados',
        AgentTool::TICKETS_CREATE => 'abriu chamado',
        AgentTool::TICKETS_LIST => 'consultou status de chamado',
        AgentTool::TICKETS_SHOW => 'consultou status de chamado',
        AgentTool::AREAS_LIST => 'consultou disponibilidade',
        AgentTool::AREAS_AVAILABILITY => 'consultou disponibilidade',
        AgentTool::RESERVATIONS_CREATE => 'tentou reservar área',
        AgentTool::RESERVATIONS_LIST => 'consultou reservas',
        AgentTool::RESERVATIONS_CANCEL => 'tentou cancelar reserva',
        AgentTool::ESCALATIONS_CREATE => 'pediu atendimento humano',
    ];

    public const UNIDENTIFIED = 'Morador não identificado';

    /**
     * @var Collection<int, RuleArticle>
     */
    private Collection $articles;

    /**
     * @var Collection<int, Ticket>
     */
    private Collection $tickets;

    /**
     * @var Collection<int, Reservation>
     */
    private Collection $reservations;

    public function __construct()
    {
        $this->articles = new Collection;
        $this->tickets = new Collection;
        $this->reservations = new Collection;
    }

    /**
     * @return Presentation
     */
    public function present(AgentToolCall $toolCall): array
    {
        return $this->presentMany([$toolCall])[0];
    }

    /**
     * Presents the calls in order, loading the articles, tickets and reservations they reference at once.
     *
     * @param  iterable<AgentToolCall>  $toolCalls
     * @return list<Presentation>
     */
    public function presentMany(iterable $toolCalls): array
    {
        $toolCalls = (new EloquentCollection(collect($toolCalls)->values()->all()))
            ->loadMissing(['agentTool', 'result', 'resident.unit.block']);
        $this->loadEntities($toolCalls);

        $presentations = [];

        foreach ($toolCalls as $toolCall) {
            $presentations[] = ['who' => $this->who($toolCall), ...$this->describe($toolCall)];
        }

        return $presentations;
    }

    /**
     * "Primeiro nome · unidade" for identified residents, the formatted phone otherwise.
     */
    private function who(AgentToolCall $toolCall): string
    {
        $resident = $toolCall->resident;

        if ($resident !== null) {
            return "{$resident->first_name} · {$resident->unit->label}";
        }

        return $toolCall->phone === null ? self::UNIDENTIFIED : PhoneNumber::format($toolCall->phone);
    }

    /**
     * @return array{text: string, pill: array{text: string, variant: string}}
     */
    private function describe(AgentToolCall $toolCall): array
    {
        $toolSlug = $toolCall->agentTool->slug;
        $resultSlug = $toolCall->result->slug;
        $text = self::TEXTS[$toolSlug] ?? $toolCall->agentTool->name;

        if ($resultSlug === ToolCallResult::RECUSA) {
            return $this->line($text, 'Recusada', 'esc');
        }

        $ticket = $this->tickets->get((int) ($toolCall->entities['ticket_id'] ?? 0));

        return match ($toolSlug) {
            AgentTool::RULES_SEARCH => $this->describeRulesSearch($toolCall),
            AgentTool::NOTICES_LIST => $this->line($text, 'Comunicados', 'ia'),
            AgentTool::TICKETS_CREATE => $this->line($text, $ticket === null ? 'Chamado' : "Chamado {$ticket->protocol_label}", 'warn'),
            AgentTool::TICKETS_LIST, AgentTool::TICKETS_SHOW => $this->line($text, $ticket === null ? 'Chamados' : "Chamado {$ticket->protocol_label}", 'grey'),
            AgentTool::AREAS_LIST, AgentTool::AREAS_AVAILABILITY => $this->line($text, 'Áreas', 'grey'),
            AgentTool::RESERVATIONS_CREATE => $this->describeReservation($toolCall),
            AgentTool::RESERVATIONS_LIST => $this->line($text, 'Reservas', 'grey'),
            AgentTool::RESERVATIONS_CANCEL => $resultSlug === ToolCallResult::SUCESSO
                ? $this->line('cancelou reserva', 'Reserva cancelada', 'grey')
                : $this->line($text, 'Reservas', 'grey'),
            AgentTool::ESCALATIONS_CREATE => $this->line($text, 'Escalado', 'esc'),
            AgentTool::RESIDENTS_LOOKUP => $this->line($text, 'Verificação', 'grey'),
            default => $this->line($text, $toolCall->agentTool->name, 'grey'),
        };
    }

    /**
     * @return array{text: string, pill: array{text: string, variant: string}}
     */
    private function describeRulesSearch(AgentToolCall $toolCall): array
    {
        $articleIds = $toolCall->entities['article_ids'] ?? [];

        if ($toolCall->result->slug !== ToolCallResult::SUCESSO || $articleIds === []) {
            return $this->line('consultou o regimento sem resultado', 'Sem artigo', 'grey');
        }

        $article = $this->articles->get((int) $articleIds[0]);

        return $this->line(self::TEXTS[AgentTool::RULES_SEARCH], $article === null ? 'Regimento' : "Regimento · {$article->reference}", 'ia');
    }

    /**
     * @return array{text: string, pill: array{text: string, variant: string}}
     */
    private function describeReservation(AgentToolCall $toolCall): array
    {
        $reservation = $this->reservations->get((int) ($toolCall->entities['reservation_id'] ?? 0));

        if ($toolCall->result->slug !== ToolCallResult::SUCESSO) {
            return $this->line(self::TEXTS[AgentTool::RESERVATIONS_CREATE], 'Reservas', 'grey');
        }

        $text = $reservation === null
            ? 'reservou área'
            : 'reservou '.Str::lower($reservation->area->name).' '.$reservation->date->format('d/m');

        return $this->line($text, 'Reserva confirmada', 'ok');
    }

    /**
     * @return array{text: string, pill: array{text: string, variant: string}}
     */
    private function line(string $text, string $pillText, string $pillVariant): array
    {
        return ['text' => $text, 'pill' => ['text' => $pillText, 'variant' => $pillVariant]];
    }

    /**
     * @param  EloquentCollection<int, AgentToolCall>  $toolCalls
     */
    private function loadEntities(EloquentCollection $toolCalls): void
    {
        $articleIds = $toolCalls->map(fn (AgentToolCall $toolCall): ?int => isset($toolCall->entities['article_ids'][0]) ? (int) $toolCall->entities['article_ids'][0] : null)->filter();
        $ticketIds = $toolCalls->map(fn (AgentToolCall $toolCall): ?int => $toolCall->entities['ticket_id'] ?? null)->filter();
        $reservationIds = $toolCalls->map(fn (AgentToolCall $toolCall): ?int => $toolCall->entities['reservation_id'] ?? null)->filter();

        $this->articles = RuleArticle::query()->whereKey($articleIds->unique()->all())->get(['id', 'reference'])->keyBy('id')->toBase();
        $this->tickets = Ticket::query()->whereKey($ticketIds->unique()->all())->get(['id', 'protocol_number'])->keyBy('id')->toBase();
        $this->reservations = Reservation::query()->with('area')->whereKey($reservationIds->unique()->all())->get()->keyBy('id')->toBase();
    }
}
