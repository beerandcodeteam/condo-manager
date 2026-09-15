<?php

use App\Exceptions\DeletionBlockedException;
use App\Models\Block;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\ResidentProfile;
use App\Models\Unit;
use App\Rules\E164Phone;
use App\Services\Registry\ResidentService;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'bloco', except: '')]
    public string $blockFilter = '';

    #[Url(as: 'unidade', except: '')]
    public string $unitFilter = '';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: ResidentService::STATUS_ALL)]
    public string $statusFilter = ResidentService::STATUS_ALL;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $phone = '';

    public string $unitId = '';

    public string $profile = ResidentProfile::PROPRIETARIO;

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('residents.manage');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['blockFilter', 'unitFilter', 'search', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function selectBlock(?int $blockId = null): void
    {
        $this->blockFilter = $blockId === null ? '' : (string) $blockId;
        $this->unitFilter = '';
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Resident>
     */
    #[Computed]
    public function residents(): LengthAwarePaginator
    {
        return app(ResidentService::class)->paginate($this->condominium(), [
            'block_id' => filled($this->blockFilter) ? (int) $this->blockFilter : null,
            'unit_id' => filled($this->unitFilter) ? (int) $this->unitFilter : null,
            'search' => $this->search,
            'status' => $this->statusFilter,
        ]);
    }

    #[Computed]
    public function totalResidents(): int
    {
        return $this->condominium()->residents()->count();
    }

    /**
     * @return Collection<int, Block>
     */
    #[Computed]
    public function blocks(): Collection
    {
        return $this->condominium()->blocks()->orderBy('name')->get();
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
            ->when(filled($this->blockFilter), fn ($query) => $query->where('units.block_id', (int) $this->blockFilter))
            ->get();
    }

    /**
     * @return Collection<int, ResidentProfile>
     */
    #[Computed]
    public function profiles(): Collection
    {
        return ResidentProfile::query()->orderBy('id')->get(['id', 'slug', 'name']);
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function formUnits(): Collection
    {
        return $this->condominium()->units()->orderByLabel()->with('block')->get();
    }

    public function create(): void
    {
        $this->authorize('residents.manage');

        $this->resetForm();
        $this->unitId = $this->unitFilter;
        $this->showForm = true;
    }

    public function edit(int $residentId): void
    {
        $this->authorize('residents.manage');

        $resident = $this->findResident($residentId);

        $this->resetForm();
        $this->editingId = $resident->id;
        $this->name = $resident->name;
        $this->phone = $resident->phone;
        $this->unitId = (string) $resident->unit_id;
        $this->profile = $resident->residentProfile->slug;
        $this->isActive = $resident->is_active;
        $this->showForm = true;
    }

    public function save(ResidentService $residentService): void
    {
        $this->authorize('residents.manage');

        $condominium = $this->condominium();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                new E164Phone,
                function (string $attribute, mixed $value, \Closure $fail) use ($condominium): void {
                    $normalizedPhone = PhoneNumber::normalize((string) $value);

                    $isTaken = $normalizedPhone !== null && $condominium->residents()
                        ->where('phone', $normalizedPhone)
                        ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
                        ->exists();

                    if ($isTaken) {
                        $fail('Telefone já cadastrado neste condomínio.');
                    }
                },
            ],
            'unitId' => ['required', 'integer', Rule::exists('units', 'id')->where('condominium_id', $condominium->id)],
            'profile' => ['required', 'string', Rule::exists('resident_profiles', 'slug')],
            'isActive' => ['boolean'],
        ], attributes: [
            'name' => 'nome',
            'phone' => 'telefone',
            'unitId' => 'unidade',
            'profile' => 'perfil',
        ]);

        $attributes = [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'unit_id' => (int) $validated['unitId'],
            'profile' => $validated['profile'],
            'is_active' => (bool) $validated['isActive'],
        ];

        $isCreating = $this->editingId === null;

        if ($isCreating) {
            $residentService->create($condominium, $attributes);
        } else {
            $residentService->update($this->findResident($this->editingId), $attributes);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Morador cadastrado.' : 'Morador atualizado.');
    }

    public function toggleActive(int $residentId, ResidentService $residentService): void
    {
        $this->authorize('residents.manage');

        $resident = $this->findResident($residentId);

        $residentService->setActive($resident, ! $resident->is_active);

        unset($this->residents);
    }

    public function delete(int $residentId, ResidentService $residentService): void
    {
        $this->authorize('residents.manage');

        try {
            $residentService->delete($this->findResident($residentId));
        } catch (DeletionBlockedException $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: 'Morador excluído.');
    }

    private function refreshList(): void
    {
        unset($this->residents, $this->totalResidents);

        $this->dispatch('registry-changed');
    }

    private function findResident(int $residentId): Resident
    {
        return $this->condominium()->residents()->findOrFail($residentId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'phone', 'unitId', 'profile', 'isActive');
        $this->resetValidation();
    }
};
?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2">
        @php($chipClasses = 'rounded-pill px-3 py-[5px] text-[12px] transition-colors')

        <button
            type="button"
            wire:click="selectBlock"
            @class([$chipClasses, 'bg-ink font-semibold text-white' => blank($blockFilter), 'border border-black/8 bg-white font-medium hover:bg-surface-soft' => filled($blockFilter)])
            @if (blank($blockFilter)) aria-pressed="true" @endif
            data-block-chip="all"
        >Todos · {{ $this->totalResidents }}</button>

        @foreach ($this->blocks as $block)
            @php($isActiveChip = $blockFilter === (string) $block->id)
            <button
                type="button"
                wire:key="block-chip-{{ $block->id }}"
                wire:click="selectBlock({{ $block->id }})"
                @class([$chipClasses, 'bg-ink font-semibold text-white' => $isActiveChip, 'border border-black/8 bg-white font-medium hover:bg-surface-soft' => ! $isActiveChip])
                @if ($isActiveChip) aria-pressed="true" @endif
                data-block-chip="{{ $block->id }}"
            >{{ $block->label }}</button>
        @endforeach

        <span class="flex-1"></span>

        <x-ui.button size="sm" wire:click="create">+ Morador</x-ui.button>
    </div>

    <div class="flex items-center gap-2">
        <div class="w-[280px]">
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nome ou telefone" aria-label="Buscar morador por nome ou telefone" />
        </div>
        <div class="w-[180px]">
            <x-ui.select wire:model.live="unitFilter" placeholder="Todas as unidades" aria-label="Filtrar por unidade">
                @foreach ($this->units as $unit)
                    <option value="{{ $unit->id }}" wire:key="unit-filter-{{ $unit->id }}">{{ $unit->label }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-[140px]">
            <x-ui.select wire:model.live="statusFilter" aria-label="Filtrar por situação">
                <option value="todos">Todos</option>
                <option value="ativos">Ativos</option>
                <option value="inativos">Inativos</option>
            </x-ui.select>
        </div>
    </div>

    @php($columns = '90px minmax(0,1.6fr) 150px 120px 100px 60px 120px')

    <x-ui.table :columns="$columns">
        <x-slot:head>
            <span>Unidade</span>
            <span>Nome</span>
            <span>Telefone</span>
            <span>Perfil</span>
            <span>Interações</span>
            <span>Ativo</span>
            <span></span>
        </x-slot:head>

        @forelse ($this->residents as $resident)
            <x-ui.table.row wire:key="resident-{{ $resident->id }}" data-resident-row="{{ $resident->id }}">
                <span class="font-semibold">{{ $resident->unit->label }}</span>
                <span class="flex min-w-0 items-center gap-2.5">
                    <x-ui.avatar :name="$resident->name" />
                    <span @class(['truncate', 'text-ink-tertiary' => ! $resident->is_active])>{{ $resident->name }}</span>
                </span>
                <span class="text-ink-body tabular-nums">{{ PhoneNumber::format($resident->phone) }}</span>
                <span class="text-ink-body">{{ $resident->residentProfile->name }}</span>
                <span class="text-ink-secondary tabular-nums" data-interactions="{{ $resident->interactions_count }}">{{ $resident->interactions_count }}</span>
                <x-ui.toggle
                    :checked="$resident->is_active"
                    wire:click="toggleActive({{ $resident->id }})"
                    wire:key="resident-toggle-{{ $resident->id }}-{{ $resident->is_active ? 'on' : 'off' }}"
                />
                <span class="flex justify-end gap-3 text-[12px] font-medium">
                    <button type="button" wire:click="edit({{ $resident->id }})" class="text-accent hover:text-accent-hover">Editar</button>
                    <button type="button" wire:click="delete({{ $resident->id }})" wire:confirm="Excluir o morador {{ $resident->name }}?" class="text-tag-esc-fg hover:underline">Excluir</button>
                </span>
            </x-ui.table.row>
        @empty
            <x-ui.empty-state title="Nenhum morador encontrado" description="Cadastre moradores ou ajuste os filtros." />
        @endforelse
    </x-ui.table>

    {{ $this->residents->links() }}

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Novo morador' : 'Editar morador'">
        <form id="resident-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="name" for="resident-name">
                <x-ui.input id="resident-name" wire:model="name" required />
            </x-ui.field>

            <x-ui.field label="Telefone" name="phone" for="resident-phone" hint="Formato internacional, ex.: +5541999990000.">
                <x-ui.input id="resident-phone" type="tel" wire:model="phone" placeholder="+5541999990000" required />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Unidade" name="unitId" for="resident-unit">
                    <x-ui.select id="resident-unit" wire:model="unitId" placeholder="Selecione">
                        @foreach ($this->formUnits as $unit)
                            <option value="{{ $unit->id }}" wire:key="form-unit-{{ $unit->id }}">{{ $unit->label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Perfil" name="profile" for="resident-profile">
                    <x-ui.select id="resident-profile" wire:model="profile">
                        @foreach ($this->profiles as $profileOption)
                            <option value="{{ $profileOption->slug }}" wire:key="form-profile-{{ $profileOption->slug }}">{{ $profileOption->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <x-ui.toggle label="Ativo" description="Só moradores ativos são reconhecidos pelo agente." :checked="$isActive" wire:model="isActive" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="resident-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
