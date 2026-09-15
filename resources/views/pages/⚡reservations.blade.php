<?php

use App\Exceptions\Api\AreaUnavailable;
use App\Exceptions\Api\SlotUnavailable;
use App\Exceptions\ReservationActionBlockedException;
use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Models\Reservation;
use App\Models\ReservationCancellationOrigin;
use App\Models\ReservationOrigin;
use App\Models\Resident;
use App\Models\Unit;
use App\Services\Reservations\CommonAreaService;
use App\Services\Reservations\ReservationService;
use App\Support\Panel\PanelDate;
use App\Support\Tenancy\CurrentCondominium;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Reservas de áreas comuns')] class extends Component
{
    /**
     * @var list<string>
     */
    public const WEEKDAYS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

    /**
     * Monday (Y-m-d) of the week shown in the grid; empty for the current week.
     */
    #[Url(as: 'semana', except: '')]
    public string $week = '';

    public ?int $selectedReservationId = null;

    public bool $showDetails = false;

    public bool $showCancelForm = false;

    public string $cancelReason = '';

    public bool $showManualForm = false;

    public string $manualAreaId = '';

    public string $manualDate = '';

    public string $manualSlotId = '';

    public string $manualUnitId = '';

    public string $manualResidentId = '';

    public function mount(): void
    {
        $this->authorize('reservations.manage');

        $this->week = $this->normalizedWeek($this->week);
    }

    public function createManual(): void
    {
        $this->authorize('reservations.manage');

        $this->resetManualForm();
        $this->manualDate = $this->reservationService()->today();
        $this->showManualForm = true;
    }

    public function updatedManualAreaId(): void
    {
        $this->manualSlotId = '';
        unset($this->manualSlots);
    }

    public function updatedManualDate(): void
    {
        $this->manualSlotId = '';
        unset($this->manualSlots);
    }

    public function updatedManualUnitId(): void
    {
        $this->manualResidentId = '';
        unset($this->manualResidents);
    }

    /**
     * Slots of the area chosen in the manual form on the chosen date; taken slots are offered disabled.
     *
     * @return list<array{id: int, label: string, taken: bool}>
     */
    #[Computed]
    public function manualSlots(): array
    {
        $area = ctype_digit($this->manualAreaId) ? $this->areas->firstWhere('id', (int) $this->manualAreaId) : null;

        if ($area === null || ! CarbonImmutable::hasFormat($this->manualDate, 'Y-m-d')) {
            return [];
        }

        return array_map(fn (array $slot): array => [
            'id' => $slot['id'],
            'label' => "{$slot['starts']}–{$slot['ends']}",
            'taken' => $slot['taken'],
        ], $this->reservationService()->availability($area, $this->manualDate));
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function manualUnits(): Collection
    {
        return $this->condominium()->units()->orderByLabel()->with('block')->get();
    }

    /**
     * Active residents of the unit chosen in the manual form.
     *
     * @return Collection<int, Resident>
     */
    #[Computed]
    public function manualResidents(): Collection
    {
        if (! ctype_digit($this->manualUnitId)) {
            return new Collection;
        }

        return $this->condominium()->residents()
            ->where('unit_id', (int) $this->manualUnitId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function saveManual(ReservationService $reservationService): void
    {
        $this->authorize('reservations.manage');

        $condominium = $this->condominium();

        $validated = $this->validate([
            'manualAreaId' => ['required', 'integer', Rule::exists('common_areas', 'id')->where('condominium_id', $condominium->id)->where('is_active', true)],
            'manualDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$reservationService->today()],
            'manualSlotId' => ['required', 'integer'],
            'manualUnitId' => ['required', 'integer', Rule::exists('units', 'id')->where('condominium_id', $condominium->id)],
            'manualResidentId' => [
                'required',
                'integer',
                Rule::exists('residents', 'id')->where('condominium_id', $condominium->id)->where('unit_id', (int) $this->manualUnitId)->where('is_active', true),
            ],
        ], [
            'manualDate.after_or_equal' => 'Não é possível reservar uma data passada.',
            'manualResidentId.exists' => 'Escolha um morador ativo da unidade selecionada.',
            'manualAreaId.exists' => 'Escolha uma área ativa.',
        ], [
            'manualAreaId' => 'área',
            'manualDate' => 'data',
            'manualSlotId' => 'faixa',
            'manualUnitId' => 'unidade',
            'manualResidentId' => 'morador',
        ]);

        $area = $condominium->commonAreas()->findOrFail((int) $validated['manualAreaId']);
        $slot = CommonAreaSlot::query()->withTrashed()->where('condominium_id', $condominium->id)->find((int) $validated['manualSlotId']);
        $resident = $condominium->residents()->findOrFail((int) $validated['manualResidentId']);

        try {
            if ($slot === null) {
                throw new AreaUnavailable;
            }

            $reservation = $reservationService->create($area, $slot, $validated['manualDate'], $resident, ReservationOrigin::PAINEL, Auth::user());
        } catch (AreaUnavailable) {
            $this->addError('manualSlotId', 'Escolha uma faixa desta área.');

            return;
        } catch (SlotUnavailable) {
            unset($this->manualSlots);
            $this->addError('manualSlotId', 'Faixa já reservada nesta data.');

            return;
        } catch (ValidationException $exception) {
            $this->addError('manualDate', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->showManualForm = false;
        $this->resetManualForm();
        $this->goToWeek($reservationService->weekStart($reservation->date->format('Y-m-d')));

        $this->dispatch('toast', type: 'success', message: 'Reserva criada.');
    }

    public function previousWeek(): void
    {
        $this->goToWeek($this->weekStart->subWeek());
    }

    public function nextWeek(): void
    {
        $this->goToWeek($this->weekStart->addWeek());
    }

    public function currentWeek(): void
    {
        $this->week = '';
        $this->refreshAgenda();
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->reservationService()->weekStart($this->week);
    }

    /**
     * Header label of the week, e.g. "14 – 20 set 2026" or "28 set – 4 out 2026".
     */
    #[Computed]
    public function weekLabel(): string
    {
        $start = $this->weekStart;
        $end = $start->addDays(6);
        $month = fn (CarbonImmutable $date): string => PanelDate::MONTHS[$date->month - 1];

        return match (true) {
            $start->year !== $end->year => "{$start->day} {$month($start)} {$start->year} – {$end->day} {$month($end)} {$end->year}",
            $start->month !== $end->month => "{$start->day} {$month($start)} – {$end->day} {$month($end)} {$end->year}",
            default => "{$start->day} – {$end->day} {$month($end)} {$end->year}",
        };
    }

    /**
     * Days of the week shown as grid columns.
     *
     * @return list<array{date: string, label: string, is_today: bool}>
     */
    #[Computed]
    public function days(): array
    {
        $today = $this->reservationService()->today();

        return array_map(function (int $offset) use ($today): array {
            $date = $this->weekStart->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'label' => self::WEEKDAYS[$offset].' '.$date->day,
                'is_today' => $date->toDateString() === $today,
            ];
        }, range(0, 6));
    }

    /**
     * Active areas (grid rows and rules card), with their slots.
     *
     * @return Collection<int, CommonArea>
     */
    #[Computed]
    public function areas(): Collection
    {
        return $this->condominium()->commonAreas()->active()->with('slots')->orderBy('name')->get();
    }

    /**
     * Not cancelled reservations of the week grouped by area id and date.
     *
     * @return array<int, array<string, list<Reservation>>>
     */
    #[Computed]
    public function grid(): array
    {
        $grid = [];

        foreach ($this->reservationService()->weekReservations($this->condominium(), $this->weekStart) as $reservation) {
            $grid[$reservation->common_area_id][$reservation->date->format('Y-m-d')][] = $reservation;
        }

        return $grid;
    }

    /**
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function whatsappReservations(): Collection
    {
        return $this->reservationService()->latestWhatsappReservations($this->condominium(), (int) config('condo.reservations.whatsapp_card_limit'));
    }

    #[Computed]
    public function selectedReservation(): ?Reservation
    {
        if ($this->selectedReservationId === null) {
            return null;
        }

        return $this->condominium()->reservations()
            ->with(['area', 'resident', 'unit.block', 'origin', 'status', 'cancellationOrigin'])
            ->find($this->selectedReservationId);
    }

    public function openReservation(int $reservationId): void
    {
        $this->authorize('reservations.manage');

        $this->selectedReservationId = $reservationId;
        unset($this->selectedReservation);

        if ($this->selectedReservation === null) {
            $this->selectedReservationId = null;
        }

        $this->showDetails = $this->selectedReservation !== null;
    }

    public function promptCancel(): void
    {
        $this->authorize('reservations.manage');

        if ($this->selectedReservation === null) {
            return;
        }

        $this->cancelReason = '';
        $this->resetValidation('cancelReason');
        $this->showCancelForm = true;
    }

    public function cancelReservation(ReservationService $reservationService): void
    {
        $this->authorize('reservations.manage');

        $reservation = $this->selectedReservation;

        if ($reservation === null) {
            $this->showCancelForm = false;

            return;
        }

        $this->resetValidation('cancelReason');

        try {
            $reservationService->cancelBySyndic($reservation, $this->cancelReason, Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('cancelReason', collect($exception->errors())->flatten()->first());

            return;
        } catch (ReservationActionBlockedException $exception) {
            $this->showCancelForm = false;
            $this->refreshAgenda();
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->showCancelForm = false;
        $this->showDetails = false;
        $this->selectedReservationId = null;
        $this->cancelReason = '';
        $this->refreshAgenda();

        $this->dispatch('toast', type: 'success', message: 'Reserva cancelada. O morador será avisado.');
    }

    /**
     * Chip text of the resident: last name and unit, e.g. "Souza · 402B".
     */
    public function residentChip(Reservation $reservation): string
    {
        return Str::afterLast(trim($reservation->resident->name), ' ').' · '.$reservation->unit->label;
    }

    /**
     * "Churrasqueira · sáb 20/09 · 12h–18h".
     */
    public function reservationSummary(Reservation $reservation): string
    {
        $weekday = self::WEEKDAYS[$reservation->date->dayOfWeekIso - 1];

        return "{$reservation->area->name} · {$weekday} {$reservation->date->format('d/m')} · {$reservation->hour_range}";
    }

    public function originLabel(Reservation $reservation): string
    {
        return $reservation->reservation_origin_id === ReservationOrigin::idFor(ReservationOrigin::WHATSAPP) ? 'WhatsApp (agente)' : 'Painel (manual)';
    }

    public function cancellationNote(Reservation $reservation): ?string
    {
        return match ($reservation->cancellationOrigin?->slug) {
            ReservationCancellationOrigin::MORADOR => 'Cancelada pelo morador',
            ReservationCancellationOrigin::SINDICO => 'Cancelada pelo síndico',
            default => null,
        };
    }

    /**
     * Rules card line of the area: slots, advance window and cancellation deadline.
     */
    public function areaRules(CommonArea $area): string
    {
        $slots = $area->slots->map(fn (CommonAreaSlot $slot): string => $slot->hour_range)->join(', ');

        return collect([$slots, app(CommonAreaService::class)->rulesSummary($area)])->filter()->join(' · ');
    }

    /**
     * Earliest date (today, condominium timezone) offered in the manual reservation form.
     */
    public function reservationDateMin(): string
    {
        return $this->reservationService()->today();
    }

    public function canBeCancelled(Reservation $reservation): bool
    {
        return $reservation->isConfirmed() && $reservation->date->format('Y-m-d') >= $this->reservationService()->today();
    }

    private function goToWeek(CarbonImmutable $weekStart): void
    {
        $this->week = $this->normalizedWeek($weekStart->toDateString());
        $this->refreshAgenda();
    }

    /**
     * The Monday of the given date, or empty when it is the current week.
     */
    private function normalizedWeek(string $week): string
    {
        $weekStart = $this->reservationService()->weekStart($week)->toDateString();

        return $weekStart === $this->reservationService()->weekStart()->toDateString() ? '' : $weekStart;
    }

    private function refreshAgenda(): void
    {
        unset($this->weekStart, $this->weekLabel, $this->days, $this->areas, $this->grid, $this->whatsappReservations, $this->selectedReservation, $this->manualSlots);
    }

    private function resetManualForm(): void
    {
        $this->reset('manualAreaId', 'manualDate', 'manualSlotId', 'manualUnitId', 'manualResidentId');
        $this->resetValidation();
        unset($this->manualSlots, $this->manualResidents);
    }

    private function reservationService(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }
};
?>

<div class="grid max-w-[1240px] grid-cols-[minmax(0,1fr)_minmax(260px,320px)] items-start gap-3.5">
    <div class="flex min-w-0 flex-col gap-3.5">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousWeek" class="rounded-control-sm border border-line-strong bg-white px-2.5 py-1.5 hover:bg-surface-soft" aria-label="Semana anterior" data-week-previous>‹</button>
            <span class="text-[14px] font-semibold" data-week-label>{{ $this->weekLabel }}</span>
            <button type="button" wire:click="nextWeek" class="rounded-control-sm border border-line-strong bg-white px-2.5 py-1.5 hover:bg-surface-soft" aria-label="Próxima semana" data-week-next>›</button>
            @if ($week !== '')
                <button type="button" wire:click="currentWeek" class="ml-1 text-[12px] font-medium text-accent hover:text-accent-hover">Hoje</button>
            @endif
            <span class="flex-1"></span>
            <x-ui.button size="sm" wire:click="createManual" data-new-reservation>+ Reserva manual</x-ui.button>
        </div>

        <div class="overflow-hidden rounded-card border border-line bg-white shadow-card" data-reservation-grid>
            <div class="grid grid-cols-[140px_repeat(7,minmax(0,1fr))] border-b border-line">
                <span class="px-3.5 py-2.5"></span>
                @foreach ($this->days as $day)
                    <span
                        wire:key="day-{{ $day['date'] }}"
                        @class([
                            'px-1.5 py-2.5 text-center text-[12px]',
                            'font-semibold text-accent' => $day['is_today'],
                            'font-medium text-ink-secondary' => ! $day['is_today'],
                        ])
                        data-day="{{ $day['date'] }}"
                        @if ($day['is_today']) data-today @endif
                    >{{ $day['label'] }}</span>
                @endforeach
            </div>

            @forelse ($this->areas as $area)
                <div wire:key="grid-area-{{ $area->id }}" class="grid min-h-16 grid-cols-[140px_repeat(7,minmax(0,1fr))] border-b border-black/5 last:border-b-0" data-grid-area="{{ $area->id }}">
                    <span class="border-r border-black/5 px-3.5 py-3 font-medium">
                        {{ $area->name }}
                        @if (filled($area->description))
                            <span class="block truncate text-[11px] font-normal text-ink-tertiary" title="{{ $area->description }}">{{ Str::limit($area->description, 40) }}</span>
                        @endif
                    </span>

                    @foreach ($this->days as $day)
                        <span wire:key="cell-{{ $area->id }}-{{ $day['date'] }}" class="flex min-w-0 flex-col gap-1 border-r border-black/[.03] p-1.5 last:border-r-0" data-cell="{{ $area->id }}-{{ $day['date'] }}">
                            @foreach ($this->grid[$area->id][$day['date']] ?? [] as $reservation)
                                @php($isWhatsapp = $reservation->origin->slug === 'whatsapp')
                                <button
                                    type="button"
                                    wire:key="chip-{{ $reservation->id }}"
                                    wire:click="openReservation({{ $reservation->id }})"
                                    @class([
                                        'block w-full rounded-[6px] px-[7px] py-1 text-left text-[11px] leading-[1.3] font-medium',
                                        'bg-tag-ok-bg text-tag-ok-fg' => $isWhatsapp,
                                        'bg-tag-ia-bg text-tag-ia-fg' => ! $isWhatsapp,
                                    ])
                                    data-reservation-chip="{{ $reservation->id }}"
                                    data-origin="{{ $reservation->origin->slug }}"
                                >
                                    <span class="block">{{ $reservation->hour_range }}</span>
                                    <span class="block truncate font-normal opacity-85">{{ $this->residentChip($reservation) }}</span>
                                </button>
                            @endforeach
                        </span>
                    @endforeach
                </div>
            @empty
                <x-ui.empty-state title="Nenhuma área ativa" description="Cadastre áreas comuns e faixas de horário em Configurações." />
            @endforelse
        </div>
    </div>

    <div class="flex min-w-0 flex-col gap-3.5">
        <x-ui.card title="Pedidos pelo WhatsApp" data-whatsapp-requests>
            <div class="flex flex-col gap-3">
                @forelse ($this->whatsappReservations as $request)
                    <div wire:key="whatsapp-{{ $request->id }}" class="flex flex-col gap-1 border-b border-black/5 pb-3 last:border-b-0 last:pb-0" data-whatsapp-request="{{ $request->id }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate font-medium">{{ $request->resident->name }} · {{ $request->unit->label }}</span>
                            @if ($request->isConfirmed())
                                <x-ui.pill variant="ok">Confirmada</x-ui.pill>
                            @else
                                <x-ui.pill>Cancelada</x-ui.pill>
                            @endif
                        </div>
                        <div class="text-[12px] text-ink-body">{{ $this->reservationSummary($request) }}</div>
                        <div class="text-[11px] text-ink-tertiary">{{ $this->cancellationNote($request) ?? 'Pedido '.PanelDate::relative($request->created_at, withTime: true) }}</div>
                    </div>
                @empty
                    <p class="text-[12px] text-ink-secondary">Nenhuma reserva feita pelo agente ainda.</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card data-tool-rules>
            <x-slot:header>
                Regras aplicadas pela tool
                <span class="mt-1 block text-[12px] font-normal text-ink-secondary">O agente só confirma se <span class="font-mono">reservar_area</span> aceitar.</span>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                @forelse ($this->areas as $area)
                    <div wire:key="rules-{{ $area->id }}" class="flex flex-col gap-0.5 rounded-control-sm bg-surface-soft px-2.5 py-2 text-[12px]" data-area-rules="{{ $area->id }}">
                        <span class="font-medium">{{ $area->name }}</span>
                        <span class="text-ink-secondary">{{ $this->areaRules($area) }}</span>
                    </div>
                @empty
                    <p class="text-[12px] text-ink-secondary">Nenhuma área ativa.</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showDetails" title="Reserva" size="sm" data-reservation-details>
        @if ($reservation = $this->selectedReservation)
            <dl class="grid grid-cols-[90px_minmax(0,1fr)] gap-x-3 gap-y-2 text-[13px]">
                <dt class="text-ink-secondary">Data</dt>
                <dd>{{ $reservation->date->format('d/m/Y') }}</dd>
                <dt class="text-ink-secondary">Faixa</dt>
                <dd>{{ $reservation->starts }}–{{ $reservation->ends }}</dd>
                <dt class="text-ink-secondary">Área</dt>
                <dd>{{ $reservation->area->name }}</dd>
                <dt class="text-ink-secondary">Unidade</dt>
                <dd>{{ $reservation->unit->label }}</dd>
                <dt class="text-ink-secondary">Morador</dt>
                <dd>{{ $reservation->resident->name }}</dd>
                <dt class="text-ink-secondary">Origem</dt>
                <dd>{{ $this->originLabel($reservation) }}</dd>
                <dt class="text-ink-secondary">Status</dt>
                <dd>{{ $reservation->status->name }}</dd>
            </dl>

            <x-slot:footer>
                <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Fechar</x-ui.button>
                @if ($this->canBeCancelled($reservation))
                    <x-ui.button size="sm" wire:click="promptCancel" data-cancel-reservation>Cancelar reserva</x-ui.button>
                @endif
            </x-slot:footer>
        @endif
    </x-ui.modal>

    <x-ui.modal wire:model="showManualForm" title="Reserva manual" data-manual-reservation>
        <form id="reservation-manual-form" wire:submit="saveManual" class="flex flex-col gap-3">
            <p class="text-[12px] text-ink-secondary">A reserva manual não segue a antecedência da área; só recusa datas passadas e faixas já reservadas.</p>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Área" name="manualAreaId" for="reservation-manual-area">
                    <x-ui.select id="reservation-manual-area" wire:model.live="manualAreaId" placeholder="Selecione a área">
                        @foreach ($this->areas as $area)
                            <option value="{{ $area->id }}" wire:key="manual-area-{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Data" name="manualDate" for="reservation-manual-date">
                    <x-ui.input id="reservation-manual-date" type="date" wire:model.live="manualDate" min="{{ $this->reservationDateMin() }}" required />
                </x-ui.field>

                <x-ui.field label="Faixa" name="manualSlotId" for="reservation-manual-slot" class="col-span-2">
                    <x-ui.select
                        id="reservation-manual-slot"
                        wire:model="manualSlotId"
                        wire:key="manual-slots-{{ $manualAreaId }}-{{ $manualDate }}"
                        :placeholder="blank($manualAreaId) ? 'Selecione a área' : 'Selecione a faixa'"
                        :disabled="blank($manualAreaId)"
                    >
                        @foreach ($this->manualSlots as $slot)
                            <option value="{{ $slot['id'] }}" wire:key="manual-slot-{{ $slot['id'] }}" @disabled($slot['taken'])>{{ $slot['label'] }}{{ $slot['taken'] ? ' · reservada' : '' }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Unidade" name="manualUnitId" for="reservation-manual-unit">
                    <x-ui.select id="reservation-manual-unit" wire:model.live="manualUnitId" placeholder="Selecione a unidade">
                        @foreach ($this->manualUnits as $unit)
                            <option value="{{ $unit->id }}" wire:key="manual-unit-{{ $unit->id }}">{{ $unit->label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Morador" name="manualResidentId" for="reservation-manual-resident">
                    <x-ui.select
                        id="reservation-manual-resident"
                        wire:model="manualResidentId"
                        wire:key="manual-residents-{{ $manualUnitId }}"
                        :placeholder="blank($manualUnitId) ? 'Selecione a unidade' : 'Selecione o morador'"
                        :disabled="blank($manualUnitId)"
                    >
                        @foreach ($this->manualResidents as $resident)
                            <option value="{{ $resident->id }}" wire:key="manual-resident-{{ $resident->id }}">{{ $resident->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="reservation-manual-form" loading="saveManual">Reservar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showCancelForm" title="Cancelar reserva">
        <form id="reservation-cancel-form" wire:submit="cancelReservation" class="flex flex-col gap-3">
            <p class="text-[12px] text-ink-secondary">O morador é avisado pelo WhatsApp com o motivo informado.</p>
            <x-ui.field label="Motivo do cancelamento" name="cancelReason" for="reservation-cancel-reason">
                <x-ui.textarea id="reservation-cancel-reason" wire:model="cancelReason" rows="3" placeholder="Ex.: manutenção elétrica no salão." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Voltar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="reservation-cancel-form" loading="cancelReservation">Cancelar reserva</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
