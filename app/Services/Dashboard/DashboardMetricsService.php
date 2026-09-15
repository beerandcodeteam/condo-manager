<?php

namespace App\Services\Dashboard;

use App\Models\AgentTool;
use App\Models\AgentToolCall;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\TicketPriority;
use App\Models\ToolCallResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Indicators of the overview, computed on demand from the operational tables (nothing is pre-aggregated).
 * "Today" is the calendar day in the condominium timezone.
 */
class DashboardMetricsService
{
    public const RESOLUTION_DAYS = 7;

    /**
     * Tools behind each bar of "Resolução por tool · 7 dias", in display order.
     *
     * @var array<string, list<string>>
     */
    public const RESOLUTION_TOOLS = [
        'Regimento/convenção' => [AgentTool::RULES_SEARCH],
        'Comunicados' => [AgentTool::NOTICES_LIST],
        'Abrir chamado' => [AgentTool::TICKETS_CREATE],
        'Status de chamado' => [AgentTool::TICKETS_LIST, AgentTool::TICKETS_SHOW],
        'Reservar área' => [AgentTool::RESERVATIONS_CREATE],
    ];

    /**
     * SQL identity of the person behind a tool call: the resident when identified, the phone otherwise.
     */
    private const ATTENDEE_KEY = "coalesce('r' || agent_tool_calls.resident_id::text, 'p' || agent_tool_calls.phone)";

    /**
     * Distinct residents (or unidentified phones) with at least one tool call today.
     */
    public function attendancesToday(Condominium $condominium): int
    {
        return $this->countAttendees($this->toolCallsBetween($condominium, ...$this->todayRange()));
    }

    /**
     * Distinct residents (or unidentified phones) with at least one tool call yesterday.
     */
    public function attendancesYesterday(Condominium $condominium): int
    {
        [$todayStart] = $this->todayRange();

        return $this->countAttendees($this->toolCallsBetween($condominium, $todayStart->subDay(), $todayStart));
    }

    /**
     * Rounded variation of today's attendances against yesterday; null when yesterday had none.
     */
    public function deltaPercent(Condominium $condominium): ?int
    {
        $yesterday = $this->attendancesYesterday($condominium);

        if ($yesterday === 0) {
            return null;
        }

        return (int) round(($this->attendancesToday($condominium) - $yesterday) / $yesterday * 100);
    }

    /**
     * Attendances of today solved without a human: attendees with no escalation created today
     * (phone-only attendances always count as resolved).
     *
     * @return array{resolved: int, total: int, percent: int|null}
     */
    public function resolvedByAgent(Condominium $condominium): array
    {
        [$start, $end] = $this->todayRange();
        $total = $this->attendancesToday($condominium);

        $escalated = $this->toolCallsBetween($condominium, $start, $end)
            ->whereNotNull('resident_id')
            ->whereIn('resident_id', Escalation::query()
                ->withoutGlobalScopes()
                ->select('resident_id')
                ->where('condominium_id', $condominium->id)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end))
            ->distinct()
            ->count('resident_id');

        $resolved = $total - $escalated;

        return [
            'resolved' => $resolved,
            'total' => $total,
            'percent' => $total === 0 ? null : (int) round($resolved / $total * 100),
        ];
    }

    /**
     * Tickets still being worked on (aberto or em_andamento).
     */
    public function openTickets(Condominium $condominium): int
    {
        return $condominium->tickets()->open()->count();
    }

    /**
     * Open tickets with priority alta.
     */
    public function urgentTickets(Condominium $condominium): int
    {
        return $condominium->tickets()
            ->open()
            ->where('ticket_priority_id', TicketPriority::idFor(TicketPriority::ALTA))
            ->count();
    }

    /**
     * Escalations waiting for a human (pendente or em_atendimento).
     */
    public function waitingEscalations(Condominium $condominium): int
    {
        return $condominium->escalations()->unresolved()->count();
    }

    /**
     * Average minutes the waiting escalations have been open; null when none is waiting.
     */
    public function averageWaitMinutes(Condominium $condominium): ?int
    {
        $now = now();

        /** @var \Illuminate\Support\Collection<int, CarbonInterface> $createdAts */
        $createdAts = $condominium->escalations()->unresolved()->pluck('created_at');

        if ($createdAts->isEmpty()) {
            return null;
        }

        $averageSeconds = $createdAts->avg(fn (CarbonInterface $createdAt): float => max(0, $createdAt->diffInSeconds($now)));

        return (int) round($averageSeconds / 60);
    }

    /**
     * Latest tool calls with what the activity feed shows.
     *
     * @return Collection<int, AgentToolCall>
     */
    public function recentActivity(Condominium $condominium): Collection
    {
        return $condominium->agentToolCalls()
            ->with(['resident.unit.block', 'agentTool', 'result'])
            ->latest()
            ->latest('id')
            ->limit((int) config('condo.dashboard.activity_limit'))
            ->get();
    }

    /**
     * Oldest escalations still waiting for a human.
     *
     * @return Collection<int, Escalation>
     */
    public function waitingTop(Condominium $condominium): Collection
    {
        return $condominium->escalations()
            ->unresolved()
            ->with(['resident', 'unit.block', 'reason'])
            ->oldest()
            ->oldest('id')
            ->limit((int) config('condo.dashboard.waiting_limit'))
            ->get();
    }

    /**
     * Share of `sucesso` calls in the last 7 days for each group of tools; percent is null without calls.
     *
     * @return list<array{label: string, percent: int|null}>
     */
    public function resolutionByTool7d(Condominium $condominium): array
    {
        /** @var \Illuminate\Support\Collection<int, object{agent_tool_id: int, total: int, successful: int}> $countsByTool */
        $countsByTool = $condominium->agentToolCalls()
            ->where('created_at', '>=', now()->subDays(self::RESOLUTION_DAYS))
            ->toBase()
            ->selectRaw('agent_tool_id, count(*) as total, count(*) filter (where tool_call_result_id = ?) as successful', [ToolCallResult::idFor(ToolCallResult::SUCESSO)])
            ->groupBy('agent_tool_id')
            ->get()
            ->keyBy('agent_tool_id');

        $bars = [];

        foreach (self::RESOLUTION_TOOLS as $label => $toolSlugs) {
            $counts = collect($toolSlugs)
                ->map(fn (string $slug) => $countsByTool->get(AgentTool::idFor($slug)))
                ->filter();
            $total = (int) $counts->sum('total');

            $bars[] = [
                'label' => $label,
                'percent' => $total === 0 ? null : (int) round($counts->sum('successful') / $total * 100),
            ];
        }

        return $bars;
    }

    /**
     * Start (inclusive) and end (exclusive) of today in the condominium timezone, in the storage timezone.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function todayRange(): array
    {
        $start = CarbonImmutable::now(config('condo.timezone'))->startOfDay();

        return [
            $start->setTimezone(config('app.timezone')),
            $start->addDay()->setTimezone(config('app.timezone')),
        ];
    }

    /**
     * @return HasMany<AgentToolCall, Condominium>
     */
    private function toolCallsBetween(Condominium $condominium, CarbonImmutable $start, CarbonImmutable $end): HasMany
    {
        return $condominium->agentToolCalls()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);
    }

    /**
     * @param  HasMany<AgentToolCall, Condominium>|Builder<AgentToolCall>  $query
     */
    private function countAttendees(HasMany|Builder $query): int
    {
        return (int) $query
            ->where(fn (Builder $query) => $query->whereNotNull('resident_id')->orWhereNotNull('phone'))
            ->toBase()
            ->selectRaw('count(distinct '.self::ATTENDEE_KEY.') as attendees')
            ->value('attendees');
    }
}
