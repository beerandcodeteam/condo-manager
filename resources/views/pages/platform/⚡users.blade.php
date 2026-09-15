<?php

use App\Models\Condominium;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\UserService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Usuários')] class extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'condominio', except: '')]
    public string $condominiumFilter = '';

    #[Url(as: 'papel', except: '')]
    public string $roleFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = Role::SINDICO;

    public string $condominiumId = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->authorize('platform.manage');
    }

    public function updatedCondominiumFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return app(UserService::class)->manageable()
            ->with(['role', 'condominium'])
            ->when(filled($this->condominiumFilter), fn ($query) => $query->where('condominium_id', (int) $this->condominiumFilter))
            ->when(
                in_array($this->roleFilter, UserService::ASSIGNABLE_ROLES, true),
                fn ($query) => $query->where('role_id', Role::idFor($this->roleFilter)),
            )
            ->orderBy('name')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return Collection<int, Condominium>
     */
    #[Computed]
    public function condominiums(): Collection
    {
        return Condominium::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->whereIn('slug', UserService::ASSIGNABLE_ROLES)->orderBy('id')->get(['id', 'slug', 'name']);
    }

    public function create(): void
    {
        $this->authorize('platform.manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $userId, UserService $userService): void
    {
        $this->authorize('platform.manage');

        $user = $userService->manageable()->with('role')->findOrFail($userId);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->slug;
        $this->condominiumId = (string) $user->condominium_id;
        $this->showForm = true;
    }

    public function save(UserService $userService): void
    {
        $this->authorize('platform.manage');

        $isCreating = $this->editingId === null;
        $user = $isCreating ? null : $userService->manageable()->findOrFail($this->editingId);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', 'string', Rule::in(UserService::ASSIGNABLE_ROLES)],
            'condominiumId' => ['required', 'integer', Rule::exists('condominiums', 'id')],
        ];

        if ($isCreating) {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        $validated = $this->validate($rules, [
            'email.unique' => 'Este e-mail já está cadastrado.',
            'role.in' => 'Escolha o papel Síndico ou Zelador.',
        ], [
            'name' => 'nome',
            'email' => 'e-mail',
            'role' => 'papel',
            'condominiumId' => 'condomínio',
            'password' => 'senha',
        ]);

        $attributes = [
            'name' => trim($validated['name']),
            'email' => Str::lower(trim($validated['email'])),
            'role' => $validated['role'],
            'condominium_id' => (int) $validated['condominiumId'],
        ];

        if ($isCreating) {
            $userService->create([...$attributes, 'password' => $validated['password']]);
        } else {
            $userService->update($user, $attributes);
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->users);

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Usuário criado.' : 'Usuário atualizado.');
    }

    public function toggleActive(int $userId, UserService $userService): void
    {
        $this->authorize('platform.manage');

        $user = $userService->manageable()->findOrFail($userId);

        $userService->setActive($user, ! $user->is_active);

        unset($this->users);

        $this->dispatch('toast', type: 'success', message: $user->is_active ? 'Usuário ativado.' : 'Usuário desativado.');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'role', 'condominiumId', 'password', 'password_confirmation');
        $this->resetValidation();
    }
};
?>

<div class="flex max-w-[1100px] flex-col gap-4">
    <div class="flex items-center gap-2">
        <div class="w-[240px]">
            <x-ui.select wire:model.live="condominiumFilter" placeholder="Todos os condomínios" aria-label="Filtrar por condomínio">
                @foreach ($this->condominiums as $condominium)
                    <option value="{{ $condominium->id }}" wire:key="filter-condominium-{{ $condominium->id }}">{{ $condominium->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-[180px]">
            <x-ui.select wire:model.live="roleFilter" placeholder="Todos os papéis" aria-label="Filtrar por papel">
                @foreach ($this->roles as $roleOption)
                    <option value="{{ $roleOption->slug }}" wire:key="filter-role-{{ $roleOption->slug }}">{{ $roleOption->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <span class="flex-1"></span>
        <x-ui.button size="sm" wire:click="create">+ Usuário</x-ui.button>
    </div>

    @php($columns = 'minmax(0,1.3fr) minmax(0,1.4fr) 100px minmax(0,1.2fr) 90px 150px')

    <x-ui.table :columns="$columns">
        <x-slot:head>
            <span>Nome</span>
            <span>E-mail</span>
            <span>Papel</span>
            <span>Condomínio</span>
            <span>Status</span>
            <span></span>
        </x-slot:head>

        @forelse ($this->users as $user)
            <x-ui.table.row wire:key="user-{{ $user->id }}" data-user-row>
                <span class="flex min-w-0 items-center gap-2.5">
                    <x-ui.avatar :name="$user->name" />
                    <span class="truncate font-medium">{{ $user->name }}</span>
                </span>
                <span class="truncate text-ink-body">{{ $user->email }}</span>
                <span class="text-ink-body">{{ $user->role->name }}</span>
                <span class="truncate text-ink-body">{{ $user->condominium?->name ?? '—' }}</span>
                <span>
                    @if ($user->is_active)
                        <x-ui.pill variant="ok">Ativo</x-ui.pill>
                    @else
                        <x-ui.pill variant="grey">Inativo</x-ui.pill>
                    @endif
                </span>
                <span class="flex justify-end gap-3 text-[12px] font-medium">
                    <button type="button" wire:click="edit({{ $user->id }})" class="text-accent hover:text-accent-hover">Editar</button>
                    <button type="button" wire:click="toggleActive({{ $user->id }})" class="text-ink-secondary hover:text-ink">{{ $user->is_active ? 'Desativar' : 'Ativar' }}</button>
                </span>
            </x-ui.table.row>
        @empty
            <x-ui.empty-state title="Nenhum usuário encontrado" description="Cadastre síndicos e zeladores ou ajuste os filtros." />
        @endforelse
    </x-ui.table>

    {{ $this->users->links() }}

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Novo usuário' : 'Editar usuário'">
        <form id="user-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="name" for="user-name">
                <x-ui.input id="user-name" wire:model="name" required />
            </x-ui.field>

            <x-ui.field label="E-mail" name="email" for="user-email">
                <x-ui.input id="user-email" type="email" wire:model="email" required />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Papel" name="role" for="user-role">
                    <x-ui.select id="user-role" wire:model="role">
                        @foreach ($this->roles as $roleOption)
                            <option value="{{ $roleOption->slug }}" wire:key="form-role-{{ $roleOption->slug }}">{{ $roleOption->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Condomínio" name="condominiumId" for="user-condominium">
                    <x-ui.select id="user-condominium" wire:model="condominiumId" placeholder="Selecione">
                        @foreach ($this->condominiums as $condominium)
                            <option value="{{ $condominium->id }}" wire:key="form-condominium-{{ $condominium->id }}">{{ $condominium->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            @if ($editingId === null)
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.field label="Senha inicial" name="password" for="user-password" hint="Mínimo de 8 caracteres.">
                        <x-ui.input id="user-password" type="password" wire:model="password" autocomplete="new-password" required />
                    </x-ui.field>

                    <x-ui.field label="Confirmar senha" name="password_confirmation" for="user-password-confirmation">
                        <x-ui.input id="user-password-confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
                    </x-ui.field>
                </div>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="user-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
