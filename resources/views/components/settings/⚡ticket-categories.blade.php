<?php

use App\Exceptions\DeletionBlockedException;
use App\Models\Condominium;
use App\Models\TicketCategory;
use App\Services\Tickets\TicketCategoryService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public function mount(): void
    {
        $this->authorize('categories.manage');
    }

    /**
     * @return Collection<int, TicketCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->condominium()->ticketCategories()->withCount('tickets')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('categories.manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $categoryId): void
    {
        $this->authorize('categories.manage');

        $category = $this->findCategory($categoryId);

        $this->resetForm();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->showForm = true;
    }

    public function save(TicketCategoryService $ticketCategoryService): void
    {
        $this->authorize('categories.manage');

        $condominium = $this->condominium();
        $isCreating = $this->editingId === null;

        $nameRules = [
            'required',
            'string',
            'max:100',
            Rule::unique('ticket_categories', 'name')->where('condominium_id', $condominium->id)->ignore($this->editingId),
        ];

        if ($isCreating) {
            $nameRules[] = function (string $attribute, mixed $value, \Closure $fail) use ($condominium, $ticketCategoryService): void {
                $slug = $ticketCategoryService->slugFor((string) $value);

                if ($slug === '') {
                    $fail('Informe um nome com letras ou números.');
                } elseif ($condominium->ticketCategories()->where('slug', $slug)->exists()) {
                    $fail("Já existe uma categoria com o identificador \"{$slug}\".");
                }
            };
        }

        $validated = $this->validate(
            ['name' => $nameRules],
            ['name.unique' => 'Já existe uma categoria com este nome.'],
            ['name' => 'nome'],
        );

        if ($isCreating) {
            $ticketCategoryService->create($condominium, $validated['name']);
        } else {
            $ticketCategoryService->rename($this->findCategory($this->editingId), $validated['name']);
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->categories);

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Categoria criada.' : 'Categoria renomeada.');
    }

    public function toggleActive(int $categoryId, TicketCategoryService $ticketCategoryService): void
    {
        $this->authorize('categories.manage');

        $category = $this->findCategory($categoryId);

        $ticketCategoryService->setActive($category, ! $category->is_active);

        unset($this->categories);
    }

    public function delete(int $categoryId, TicketCategoryService $ticketCategoryService): void
    {
        $this->authorize('categories.manage');

        try {
            $ticketCategoryService->delete($this->findCategory($categoryId));
        } catch (DeletionBlockedException $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        unset($this->categories);

        $this->dispatch('toast', type: 'success', message: 'Categoria excluída.');
    }

    private function findCategory(int $categoryId): TicketCategory
    {
        return $this->condominium()->ticketCategories()->findOrFail($categoryId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name');
        $this->resetValidation();
    }
};
?>

<x-ui.card data-settings-card="ticket-categories">
    <x-slot:header>Categorias de chamado</x-slot:header>
    <x-slot:action>
        <button type="button" wire:click="create" class="text-accent hover:text-accent-hover">+ Categoria</button>
    </x-slot:action>

    <div class="flex flex-col gap-2">
        <div class="grid grid-cols-[minmax(0,1fr)_110px_70px_44px_110px] items-center gap-3 px-3 text-[11px] font-semibold tracking-[0.04em] text-ink-tertiary uppercase">
            <span>Nome</span>
            <span>Slug</span>
            <span>Chamados</span>
            <span>Ativa</span>
            <span></span>
        </div>

        @forelse ($this->categories as $category)
            <div wire:key="category-{{ $category->id }}" class="grid grid-cols-[minmax(0,1fr)_110px_70px_44px_110px] items-center gap-3 rounded-control-lg bg-surface-soft px-3 py-2" data-ticket-category="{{ $category->slug }}">
                <span @class(['truncate font-medium', 'text-ink-tertiary' => ! $category->is_active])>{{ $category->name }}</span>
                <span class="truncate font-mono text-[12px] text-ink-secondary">{{ $category->slug }}</span>
                <span class="text-ink-body tabular-nums" data-tickets-count>{{ $category->tickets_count }}</span>
                <x-ui.toggle
                    :checked="$category->is_active"
                    wire:click="toggleActive({{ $category->id }})"
                    wire:key="category-toggle-{{ $category->id }}-{{ $category->is_active ? 'on' : 'off' }}"
                />
                <span class="flex justify-end gap-3 text-[12px] font-medium">
                    <button type="button" wire:click="edit({{ $category->id }})" class="text-accent hover:text-accent-hover">Renomear</button>
                    <button
                        type="button"
                        wire:click="delete({{ $category->id }})"
                        wire:confirm="Excluir a categoria &quot;{{ $category->name }}&quot;?"
                        class="text-tag-esc-fg hover:underline"
                    >Excluir</button>
                </span>
            </div>
        @empty
            <p class="rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px] text-ink-secondary">Nenhuma categoria cadastrada.</p>
        @endforelse
    </div>

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Nova categoria' : 'Renomear categoria'" size="sm">
        <form id="ticket-category-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="name" for="ticket-category-name" :hint="$editingId === null ? 'O identificador (slug) é gerado a partir do nome.' : 'O identificador (slug) não muda ao renomear.'">
                <x-ui.input id="ticket-category-name" wire:model="name" required />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="ticket-category-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.card>
