<?php

use App\Models\CommonArea;
use App\Models\CommonAreaSlot;
use App\Models\Condominium;
use App\Services\Reservations\CommonAreaService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $isActive = true;

    public string $minAdvanceHours = '24';

    public string $maxAdvanceDays = '60';

    public string $cancellationDeadlineHours = '24';

    /**
     * Slots edited in the form; `id` is null for new slots.
     *
     * @var list<array{id: int|null, starts: string, ends: string}>
     */
    public array $areaSlots = [];

    public function mount(): void
    {
        $this->authorize('reservations.manage');
    }

    /**
     * @return Collection<int, CommonArea>
     */
    #[Computed]
    public function areas(): Collection
    {
        return $this->condominium()->commonAreas()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('reservations.manage');

        $this->resetForm();
        $this->areaSlots = [['id' => null, 'starts' => '', 'ends' => '']];
        $this->showForm = true;
    }

    public function edit(int $areaId): void
    {
        $this->authorize('reservations.manage');

        $area = $this->findArea($areaId);

        $this->resetForm();
        $this->editingId = $area->id;
        $this->name = $area->name;
        $this->description = (string) $area->description;
        $this->isActive = $area->is_active;
        $this->minAdvanceHours = (string) $area->min_advance_hours;
        $this->maxAdvanceDays = (string) $area->max_advance_days;
        $this->cancellationDeadlineHours = (string) $area->cancellation_deadline_hours;
        $this->areaSlots = array_values($area->slots()->get()->map(fn (CommonAreaSlot $slot): array => [
            'id' => $slot->id,
            'starts' => $slot->starts,
            'ends' => $slot->ends,
        ])->all());
        $this->showForm = true;
    }

    public function addSlot(): void
    {
        $this->areaSlots[] = ['id' => null, 'starts' => '', 'ends' => ''];
    }

    public function removeSlot(int $index): void
    {
        unset($this->areaSlots[$index]);

        $this->areaSlots = array_values($this->areaSlots);
        $this->resetValidation('areaSlots');
    }

    public function save(CommonAreaService $commonAreaService): void
    {
        $this->authorize('reservations.manage');

        $condominium = $this->condominium();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('common_areas', 'name')->where('condominium_id', $condominium->id)->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'],
            'minAdvanceHours' => ['required', 'integer', 'min:0', 'max:8760'],
            'maxAdvanceDays' => ['required', 'integer', 'min:1', 'max:730'],
            'cancellationDeadlineHours' => ['required', 'integer', 'min:0', 'max:8760'],
            'areaSlots' => ['array'],
            'areaSlots.*.starts' => ['required', 'date_format:H:i'],
            'areaSlots.*.ends' => ['required', 'date_format:H:i'],
        ], [
            'name.unique' => 'Já existe uma área com este nome.',
        ], [
            'name' => 'nome',
            'description' => 'descrição',
            'minAdvanceHours' => 'antecedência mínima',
            'maxAdvanceDays' => 'antecedência máxima',
            'cancellationDeadlineHours' => 'prazo de cancelamento',
            'areaSlots.*.starts' => 'início',
            'areaSlots.*.ends' => 'fim',
        ]);

        $isCreating = $this->editingId === null;

        try {
            $commonAreaService->save(
                $condominium,
                $isCreating ? null : $this->findArea($this->editingId),
                [
                    'name' => $validated['name'],
                    'description' => $validated['description'],
                    'is_active' => (bool) $validated['isActive'],
                    'min_advance_hours' => (int) $validated['minAdvanceHours'],
                    'max_advance_days' => (int) $validated['maxAdvanceDays'],
                    'cancellation_deadline_hours' => (int) $validated['cancellationDeadlineHours'],
                ],
                array_values($this->areaSlots),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError(Str::replaceStart('slots', 'areaSlots', $field), $message);
                }
            }

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->areas);

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Área criada.' : 'Área atualizada.');
    }

    public function rulesSummary(CommonArea $area): string
    {
        return app(CommonAreaService::class)->rulesSummary($area);
    }

    private function findArea(int $areaId): CommonArea
    {
        return $this->condominium()->commonAreas()->findOrFail($areaId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'isActive', 'minAdvanceHours', 'maxAdvanceDays', 'cancellationDeadlineHours', 'areaSlots');
        $this->resetValidation();
    }
};
?>

<x-ui.card data-settings-card="common-areas">
    <x-slot:header>Áreas comuns</x-slot:header>
    <x-slot:action>
        <button type="button" wire:click="create" class="text-accent hover:text-accent-hover">+ Adicionar</button>
    </x-slot:action>

    <div class="flex flex-col gap-2">
        @forelse ($this->areas as $area)
            <div wire:key="common-area-{{ $area->id }}" class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-3 rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px]" data-common-area="{{ $area->id }}">
                <span class="flex min-w-0 items-center gap-2">
                    <span @class(['truncate text-[13px] font-medium', 'text-ink-tertiary' => ! $area->is_active])>{{ $area->name }}</span>
                    @unless ($area->is_active)
                        <x-ui.pill>Inativa</x-ui.pill>
                    @endunless
                </span>
                <span class="text-ink-secondary" data-rules-summary>{{ $this->rulesSummary($area) }}</span>
                <button type="button" wire:click="edit({{ $area->id }})" class="font-medium text-accent hover:text-accent-hover">Editar</button>
            </div>
        @empty
            <p class="rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px] text-ink-secondary">Nenhuma área comum cadastrada.</p>
        @endforelse
    </div>

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Nova área comum' : 'Editar área comum'">
        <form id="common-area-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="name" for="common-area-name">
                <x-ui.input id="common-area-name" wire:model="name" maxlength="100" required />
            </x-ui.field>

            <x-ui.field label="Descrição" name="description" for="common-area-description">
                <x-ui.textarea id="common-area-description" wire:model="description" rows="2" maxlength="1000" placeholder="Ex.: churrasqueira coberta do térreo" />
            </x-ui.field>

            <x-ui.toggle label="Ativa" description="Só áreas ativas aparecem para o agente e aceitam reservas." :checked="$isActive" wire:model="isActive" />

            <div class="grid grid-cols-3 gap-3">
                <x-ui.field label="Antecedência mínima (h)" name="minAdvanceHours" for="common-area-min-advance">
                    <x-ui.input id="common-area-min-advance" type="number" min="0" step="1" wire:model="minAdvanceHours" required />
                </x-ui.field>

                <x-ui.field label="Antecedência máxima (dias)" name="maxAdvanceDays" for="common-area-max-advance">
                    <x-ui.input id="common-area-max-advance" type="number" min="1" step="1" wire:model="maxAdvanceDays" required />
                </x-ui.field>

                <x-ui.field label="Cancelamento até (h)" name="cancellationDeadlineHours" for="common-area-cancellation">
                    <x-ui.input id="common-area-cancellation" type="number" min="0" step="1" wire:model="cancellationDeadlineHours" required />
                </x-ui.field>
            </div>

            <div class="flex flex-col gap-2" data-area-slots>
                <div class="flex items-center">
                    <span class="flex-1 text-[12px] font-medium text-ink-body">Faixas de horário</span>
                    <button type="button" wire:click="addSlot" class="text-[12px] font-medium text-accent hover:text-accent-hover">+ Faixa</button>
                </div>

                @foreach ($areaSlots as $index => $slot)
                    <div wire:key="area-slot-{{ $slot['id'] ?? 'new' }}-{{ $index }}" class="flex flex-col gap-1">
                        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2">
                            <x-ui.time wire:model="areaSlots.{{ $index }}.starts" aria-label="Início da faixa {{ $index + 1 }}" required />
                            <x-ui.time wire:model="areaSlots.{{ $index }}.ends" aria-label="Fim da faixa {{ $index + 1 }}" required />
                            <button type="button" wire:click="removeSlot({{ $index }})" class="text-[12px] text-tag-esc-fg hover:underline">Remover</button>
                        </div>
                        @foreach (['starts', 'ends'] as $slotField)
                            @error("areaSlots.{$index}.{$slotField}")
                                <p class="text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </div>
                @endforeach

                @if ($areaSlots === [])
                    <p class="text-[12px] text-ink-tertiary">Nenhuma faixa. Áreas sem faixas precisam ficar inativas.</p>
                @endif

                @foreach ($errors->get('areaSlots') as $slotsError)
                    <p class="text-[12px] text-tag-esc-fg" role="alert">{{ $slotsError }}</p>
                @endforeach
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="common-area-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.card>
