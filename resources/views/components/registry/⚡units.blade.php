<?php

use App\Exceptions\DeletionBlockedException;
use App\Models\Block;
use App\Models\Condominium;
use App\Models\Unit;
use App\Services\Registry\BlockService;
use App\Services\Registry\UnitService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showBlockForm = false;

    public ?int $editingBlockId = null;

    public string $blockName = '';

    public bool $showUnitForm = false;

    public ?int $editingUnitId = null;

    public string $unitNumber = '';

    public string $unitBlockId = '';

    public function mount(): void
    {
        $this->authorize('residents.manage');
    }

    /**
     * @return Collection<int, Block>
     */
    #[Computed]
    public function blocks(): Collection
    {
        return $this->condominium()->blocks()->withCount('units')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function units(): Collection
    {
        return $this->condominium()->units()
            ->orderByLabel()
            ->with('block')
            ->withCount('residents')
            ->get();
    }

    public function createBlock(): void
    {
        $this->authorize('residents.manage');

        $this->resetBlockForm();
        $this->showBlockForm = true;
    }

    public function editBlock(int $blockId): void
    {
        $this->authorize('residents.manage');

        $block = $this->findBlock($blockId);

        $this->resetBlockForm();
        $this->editingBlockId = $block->id;
        $this->blockName = $block->name;
        $this->showBlockForm = true;
    }

    public function saveBlock(BlockService $blockService): void
    {
        $this->authorize('residents.manage');

        $condominium = $this->condominium();

        $validated = $this->validate([
            'blockName' => [
                'required',
                'string',
                'max:50',
                Rule::unique('blocks', 'name')->where('condominium_id', $condominium->id)->ignore($this->editingBlockId),
            ],
        ], [
            'blockName.unique' => 'Já existe um bloco com este nome neste condomínio.',
        ], [
            'blockName' => 'nome do bloco',
        ]);

        if ($this->editingBlockId === null) {
            $blockService->create($condominium, $validated['blockName']);
        } else {
            $blockService->rename($this->findBlock($this->editingBlockId), $validated['blockName']);
        }

        $this->showBlockForm = false;
        $this->resetBlockForm();
        $this->refreshLists();

        $this->dispatch('toast', type: 'success', message: 'Bloco salvo.');
    }

    public function deleteBlock(int $blockId, BlockService $blockService): void
    {
        $this->authorize('residents.manage');

        try {
            $blockService->delete($this->findBlock($blockId));
        } catch (DeletionBlockedException $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->refreshLists();

        $this->dispatch('toast', type: 'success', message: 'Bloco excluído.');
    }

    public function createUnit(): void
    {
        $this->authorize('residents.manage');

        $this->resetUnitForm();
        $this->showUnitForm = true;
    }

    public function editUnit(int $unitId): void
    {
        $this->authorize('residents.manage');

        $unit = $this->findUnit($unitId);

        $this->resetUnitForm();
        $this->editingUnitId = $unit->id;
        $this->unitNumber = $unit->number;
        $this->unitBlockId = (string) $unit->block_id;
        $this->showUnitForm = true;
    }

    public function saveUnit(UnitService $unitService): void
    {
        $this->authorize('residents.manage');

        $condominium = $this->condominium();
        $blockId = filled($this->unitBlockId) ? (int) $this->unitBlockId : null;

        $uniqueNumber = Rule::unique('units', 'number')->ignore($this->editingUnitId);
        $uniqueNumber = $blockId !== null
            ? $uniqueNumber->where('block_id', $blockId)
            : $uniqueNumber->where('condominium_id', $condominium->id)->whereNull('block_id');

        $validated = $this->validate([
            'unitNumber' => ['required', 'string', 'max:20', $uniqueNumber],
            'unitBlockId' => ['nullable', 'integer', Rule::exists('blocks', 'id')->where('condominium_id', $condominium->id)],
        ], [
            'unitNumber.unique' => $blockId !== null
                ? 'Já existe uma unidade com este número neste bloco.'
                : 'Já existe uma unidade sem bloco com este número neste condomínio.',
        ], [
            'unitNumber' => 'número da unidade',
            'unitBlockId' => 'bloco',
        ]);

        $attributes = ['number' => $validated['unitNumber'], 'block_id' => $blockId];

        if ($this->editingUnitId === null) {
            $unitService->create($condominium, $attributes);
        } else {
            $unitService->update($this->findUnit($this->editingUnitId), $attributes);
        }

        $this->showUnitForm = false;
        $this->resetUnitForm();
        $this->refreshLists();

        $this->dispatch('toast', type: 'success', message: 'Unidade salva.');
    }

    public function deleteUnit(int $unitId, UnitService $unitService): void
    {
        $this->authorize('residents.manage');

        try {
            $unitService->delete($this->findUnit($unitId));
        } catch (DeletionBlockedException $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->refreshLists();

        $this->dispatch('toast', type: 'success', message: 'Unidade excluída.');
    }

    private function refreshLists(): void
    {
        unset($this->blocks, $this->units);

        $this->dispatch('registry-changed');
    }

    private function findBlock(int $blockId): Block
    {
        return $this->condominium()->blocks()->findOrFail($blockId);
    }

    private function findUnit(int $unitId): Unit
    {
        return $this->condominium()->units()->findOrFail($unitId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function resetBlockForm(): void
    {
        $this->reset('editingBlockId', 'blockName');
        $this->resetValidation();
    }

    private function resetUnitForm(): void
    {
        $this->reset('editingUnitId', 'unitNumber', 'unitBlockId');
        $this->resetValidation();
    }
};
?>

<div class="grid grid-cols-[300px_minmax(0,1fr)] items-start gap-3.5">
    <x-ui.card data-registry-card="blocks">
        <x-slot:header>Blocos</x-slot:header>
        <x-slot:action>
            <button type="button" wire:click="createBlock" class="text-accent hover:text-accent-hover">+ Bloco</button>
        </x-slot:action>

        <div class="flex flex-col gap-2">
            @forelse ($this->blocks as $block)
                <div wire:key="block-{{ $block->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-control-lg bg-surface-soft px-3 py-2" data-block-row>
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{ $block->label }}</span>
                        <span class="block text-[12px] text-ink-secondary">{{ $block->units_count }} {{ $block->units_count === 1 ? 'unidade' : 'unidades' }}</span>
                    </span>
                    <span class="flex gap-3 text-[12px] font-medium">
                        <button type="button" wire:click="editBlock({{ $block->id }})" class="text-accent hover:text-accent-hover">Renomear</button>
                        <button type="button" wire:click="deleteBlock({{ $block->id }})" wire:confirm="Excluir o bloco &quot;{{ $block->name }}&quot;?" class="text-tag-esc-fg hover:underline">Excluir</button>
                    </span>
                </div>
            @empty
                <p class="rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px] text-ink-secondary">Sem blocos. Unidades podem ser cadastradas diretamente no condomínio.</p>
            @endforelse
        </div>
    </x-ui.card>

    <div class="flex min-w-0 flex-col gap-3">
        <div class="flex items-center">
            <span class="flex-1 text-[14px] font-semibold">Unidades</span>
            <x-ui.button size="sm" wire:click="createUnit">+ Unidade</x-ui.button>
        </div>

        @php($columns = '100px minmax(0,1fr) 110px 140px')

        <x-ui.table :columns="$columns">
            <x-slot:head>
                <span>Unidade</span>
                <span>Bloco</span>
                <span>Moradores</span>
                <span></span>
            </x-slot:head>

            @forelse ($this->units as $unit)
                <x-ui.table.row wire:key="unit-{{ $unit->id }}" data-unit-row>
                    <span class="font-semibold">{{ $unit->label }}</span>
                    <span class="truncate text-ink-body">{{ $unit->block?->label ?? 'Sem bloco' }}</span>
                    <span class="text-ink-body tabular-nums">{{ $unit->residents_count }}</span>
                    <span class="flex justify-end gap-3 text-[12px] font-medium">
                        <button type="button" wire:click="editUnit({{ $unit->id }})" class="text-accent hover:text-accent-hover">Editar</button>
                        <button type="button" wire:click="deleteUnit({{ $unit->id }})" wire:confirm="Excluir a unidade {{ $unit->label }}?" class="text-tag-esc-fg hover:underline">Excluir</button>
                    </span>
                </x-ui.table.row>
            @empty
                <x-ui.empty-state title="Nenhuma unidade cadastrada" description="Cadastre as unidades para vincular moradores, chamados e reservas." />
            @endforelse
        </x-ui.table>
    </div>

    <x-ui.modal wire:model="showBlockForm" :title="$editingBlockId === null ? 'Novo bloco' : 'Renomear bloco'" size="sm">
        <form id="block-form" wire:submit="saveBlock" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="blockName" for="block-name" hint="Ex.: A, B ou Torre 1.">
                <x-ui.input id="block-name" wire:model="blockName" required />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="block-form" loading="saveBlock">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showUnitForm" :title="$editingUnitId === null ? 'Nova unidade' : 'Editar unidade'" size="sm">
        <form id="unit-form" wire:submit="saveUnit" class="flex flex-col gap-3">
            <x-ui.field label="Número" name="unitNumber" for="unit-number">
                <x-ui.input id="unit-number" wire:model="unitNumber" placeholder="Ex.: 101" required />
            </x-ui.field>

            <x-ui.field label="Bloco" name="unitBlockId" for="unit-block">
                <x-ui.select id="unit-block" wire:model="unitBlockId" placeholder="Sem bloco">
                    @foreach ($this->blocks as $block)
                        <option value="{{ $block->id }}" wire:key="unit-block-option-{{ $block->id }}">{{ $block->label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="unit-form" loading="saveUnit">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
