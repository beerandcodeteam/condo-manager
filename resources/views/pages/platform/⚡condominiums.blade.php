<?php

use App\Models\Condominium;
use App\Models\Role;
use App\Services\Platform\CondominiumService;
use App\Support\Tenancy\CondominiumScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Condomínios')] class extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $city = '';

    public function mount(): void
    {
        $this->authorize('platform.manage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Condominium>
     */
    #[Computed]
    public function condominiums(): LengthAwarePaginator
    {
        return Condominium::query()
            ->when(filled($this->search), fn (Builder $query) => $query->whereLike('name', '%'.trim($this->search).'%'))
            ->withCount([
                'units' => fn (Builder $query) => $query->withoutGlobalScope(CondominiumScope::class),
                'users as sindicos_count' => fn (Builder $query) => $query->where('role_id', Role::idFor(Role::SINDICO)),
            ])
            ->orderBy('name')
            ->paginate(self::PER_PAGE);
    }

    public function create(): void
    {
        $this->authorize('platform.manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $condominiumId): void
    {
        $this->authorize('platform.manage');

        $condominium = Condominium::findOrFail($condominiumId);

        $this->resetForm();
        $this->editingId = $condominium->id;
        $this->name = $condominium->name;
        $this->city = (string) $condominium->city;
        $this->showForm = true;
    }

    public function save(CondominiumService $condominiumService): void
    {
        $this->authorize('platform.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('condominiums', 'name')->ignore($this->editingId)],
            'city' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => 'Já existe um condomínio com este nome.',
        ], [
            'name' => 'nome',
            'city' => 'cidade',
        ]);

        $attributes = ['name' => trim($validated['name']), 'city' => filled($validated['city']) ? trim($validated['city']) : null];

        if ($this->editingId === null) {
            $condominium = $condominiumService->create($attributes);

            session()->flash('success', "Condomínio {$condominium->name} criado e selecionado.");

            $this->redirectRoute('condominiums.index');

            return;
        }

        $condominiumService->update(Condominium::findOrFail($this->editingId), $attributes);

        $this->showForm = false;
        $this->resetForm();

        $this->dispatch('toast', type: 'success', message: 'Condomínio atualizado.');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'city');
        $this->resetValidation();
    }
};
?>

<div class="flex max-w-[1100px] flex-col gap-4">
    <div class="flex items-center gap-2">
        <div class="w-[320px]">
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nome" aria-label="Buscar condomínio por nome" />
        </div>
        <span class="flex-1"></span>
        <x-ui.button size="sm" wire:click="create">+ Condomínio</x-ui.button>
    </div>

    @php($columns = 'minmax(0,1.6fr) minmax(0,1fr) 100px 100px 110px 70px')

    <x-ui.table :columns="$columns">
        <x-slot:head>
            <span>Nome</span>
            <span>Cidade</span>
            <span>Unidades</span>
            <span>Síndicos</span>
            <span>Criado em</span>
            <span></span>
        </x-slot:head>

        @forelse ($this->condominiums as $condominium)
            <x-ui.table.row wire:key="condominium-{{ $condominium->id }}" data-condominium-row>
                <span class="truncate font-semibold">{{ $condominium->name }}</span>
                <span class="truncate text-ink-body">{{ $condominium->city ?? '—' }}</span>
                <span class="text-ink-body tabular-nums">{{ $condominium->units_count }}</span>
                <span class="text-ink-body tabular-nums">{{ $condominium->sindicos_count }}</span>
                <span class="text-ink-secondary tabular-nums">{{ $condominium->created_at?->timezone(config('condo.timezone'))->format('d/m/Y') }}</span>
                <span class="text-right">
                    <button type="button" wire:click="edit({{ $condominium->id }})" class="text-[12px] font-medium text-accent hover:text-accent-hover">Editar</button>
                </span>
            </x-ui.table.row>
        @empty
            <x-ui.empty-state title="Nenhum condomínio encontrado" description="Cadastre um condomínio ou ajuste a busca." />
        @endforelse
    </x-ui.table>

    {{ $this->condominiums->links() }}

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Novo condomínio' : 'Editar condomínio'">
        <form id="condominium-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="name" for="condominium-name">
                <x-ui.input id="condominium-name" wire:model="name" required />
            </x-ui.field>

            <x-ui.field label="Cidade" name="city" for="condominium-city">
                <x-ui.input id="condominium-city" wire:model="city" placeholder="Ex.: Curitiba · PR" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="condominium-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
