<?php

use App\Models\Condominium;
use App\Models\Escalation;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Escalations\EscalationService;
use App\Support\Tenancy\CurrentCondominium;
use App\Support\ToolCallPresenter;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Visão geral')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    /**
     * The four indicators of the top row.
     *
     * @return list<array{key: string, label: string, value: string, delta: string, deltaColor: string}>
     */
    #[Computed]
    public function kpis(): array
    {
        $metrics = app(DashboardMetricsService::class);
        $condominium = $this->condominium();

        $deltaPercent = $metrics->deltaPercent($condominium);
        $resolved = $metrics->resolvedByAgent($condominium);
        $urgentTickets = $metrics->urgentTickets($condominium);
        $averageWait = $metrics->averageWaitMinutes($condominium);

        return [
            [
                'key' => 'attendances',
                'label' => 'Atendimentos hoje',
                'value' => (string) $metrics->attendancesToday($condominium),
                'delta' => $deltaPercent === null ? '—' : sprintf('%s%d%% vs. ontem', $deltaPercent >= 0 ? '+' : '-', abs($deltaPercent)),
                'deltaColor' => match (true) {
                    $deltaPercent === null => 'neutral',
                    $deltaPercent >= 0 => 'ok',
                    default => 'esc',
                },
            ],
            [
                'key' => 'resolved',
                'label' => 'Resolvidas pelo agente',
                'value' => $resolved['percent'] === null ? '—' : "{$resolved['percent']}%",
                'delta' => "{$resolved['resolved']} de {$resolved['total']} sem humano",
                'deltaColor' => 'neutral',
            ],
            [
                'key' => 'tickets',
                'label' => 'Chamados abertos',
                'value' => (string) $metrics->openTickets($condominium),
                'delta' => $urgentTickets === 0 ? 'nenhum urgente' : $urgentTickets.' '.($urgentTickets === 1 ? 'urgente' : 'urgentes'),
                'deltaColor' => $urgentTickets === 0 ? 'neutral' : 'esc',
            ],
            [
                'key' => 'escalations',
                'label' => 'Aguardando síndico',
                'value' => (string) $metrics->waitingEscalations($condominium),
                'delta' => $averageWait === null ? '—' : "tempo médio {$averageWait} min",
                'deltaColor' => 'neutral',
            ],
        ];
    }

    /**
     * Latest agent tool calls as feed lines.
     *
     * @return list<array{time: string, who: string, text: string, pill: array{text: string, variant: string}}>
     */
    #[Computed]
    public function activity(): array
    {
        $toolCalls = app(DashboardMetricsService::class)->recentActivity($this->condominium());
        $presentations = app(ToolCallPresenter::class)->presentMany($toolCalls);
        $lines = [];

        foreach ($toolCalls->values() as $index => $toolCall) {
            $lines[] = [
                'time' => $toolCall->created_at?->setTimezone(config('condo.timezone'))->format('H:i') ?? '',
                ...$presentations[$index],
            ];
        }

        return $lines;
    }

    /**
     * @return Collection<int, Escalation>
     */
    #[Computed]
    public function waiting(): Collection
    {
        return app(DashboardMetricsService::class)->waitingTop($this->condominium());
    }

    /**
     * @return list<array{label: string, percent: int|null}>
     */
    #[Computed]
    public function resolutionByTool(): array
    {
        return app(DashboardMetricsService::class)->resolutionByTool7d($this->condominium());
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }
};
?>

<div wire:poll.60s class="flex max-w-[1200px] flex-col gap-5" data-dashboard>
    <div class="grid grid-cols-4 gap-[14px]">
        @foreach ($this->kpis as $kpi)
            <x-ui.kpi-card
                wire:key="kpi-{{ $kpi['key'] }}"
                :label="$kpi['label']"
                :value="$kpi['value']"
                :delta="$kpi['delta']"
                :delta-color="$kpi['deltaColor']"
                data-kpi="{{ $kpi['key'] }}"
            />
        @endforeach
    </div>

    <div class="grid grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] items-start gap-[14px]">
        <x-ui.card title="Atividade do agente" :padded="false" data-agent-activity>
            @forelse ($this->activity as $index => $line)
                <div wire:key="activity-{{ $index }}" class="grid grid-cols-[56px_1fr_auto] items-center gap-[14px] border-t border-black/5 px-5 py-2.5" data-activity-line>
                    <span class="text-[12px] text-ink-tertiary tabular-nums">{{ $line['time'] }}</span>
                    <span class="min-w-0"><span class="font-medium">{{ $line['who'] }}</span> <span class="text-ink-secondary">· {{ $line['text'] }}</span></span>
                    <x-ui.pill :variant="$line['pill']['variant']">{{ $line['pill']['text'] }}</x-ui.pill>
                </div>
            @empty
                <div class="border-t border-black/5 px-5 py-6 text-center text-ink-secondary">Nenhuma atividade do agente ainda.</div>
            @endforelse
        </x-ui.card>

        <div class="flex flex-col gap-[14px]">
            <x-ui.card title="Aguardando humano" data-waiting-escalations>
                <x-slot:action>
                    <a href="{{ route('escalations.index') }}" class="text-accent hover:underline" data-waiting-queue-link>Ver fila</a>
                </x-slot:action>

                <div class="flex flex-col gap-2.5">
                    @forelse ($this->waiting as $escalation)
                        <div wire:key="waiting-{{ $escalation->id }}" class="flex items-start gap-2.5" data-waiting-escalation="{{ $escalation->id }}">
                            <x-ui.dot :color="EscalationService::reasonDot($escalation->reason->slug)" class="mt-1.5" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $escalation->resident->name }} · {{ $escalation->unit->label }}</span>
                                <span class="block text-[12px] text-ink-secondary">{{ $escalation->reason->name }}</span>
                            </span>
                            <span class="text-[11px] whitespace-nowrap text-ink-tertiary">{{ EscalationService::waitLabel($escalation->created_at) }}</span>
                        </div>
                    @empty
                        <p class="text-ink-secondary">Ninguém aguardando atendimento.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card title="Resolução por tool · 7 dias" data-resolution-by-tool>
                <div class="flex flex-col gap-[9px]">
                    @foreach ($this->resolutionByTool as $bar)
                        <div wire:key="resolution-{{ $loop->index }}" class="grid grid-cols-[150px_1fr_36px] items-center gap-2.5 text-[12px]" data-resolution-bar>
                            <span class="text-ink-body">{{ $bar['label'] }}</span>
                            <span class="block h-1.5 overflow-hidden rounded-[3px] bg-[#eeeef2]">
                                <span class="block h-full bg-accent" style="width: {{ $bar['percent'] ?? 0 }}%"></span>
                            </span>
                            <span class="text-right text-ink-secondary tabular-nums">{{ $bar['percent'] === null ? '—' : $bar['percent'].'%' }}</span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
