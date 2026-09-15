<?php

use App\Models\Condominium;
use App\Models\Notice;
use App\Services\Notices\NoticeService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Comunicados')] class extends Component
{
    public const TAB_ACTIVE = 'ativos';

    public const TAB_INACTIVE = 'inativos';

    #[Url(as: 'aba', except: self::TAB_ACTIVE)]
    public string $tab = self::TAB_ACTIVE;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $noticeTitle = '';

    public string $noticeBody = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('knowledge.manage');

        if (! in_array($this->tab, [self::TAB_ACTIVE, self::TAB_INACTIVE], true)) {
            $this->tab = self::TAB_ACTIVE;
        }
    }

    /**
     * @return array{active: int, inactive: int}
     */
    #[Computed]
    public function counts(): array
    {
        $counts = $this->condominium()->notices()
            ->toBase()
            ->selectRaw('count(*) filter (where is_active) as active, count(*) filter (where not is_active) as inactive')
            ->first();

        return [
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
        ];
    }

    /**
     * Notices of the selected tab, most recently updated first.
     *
     * @return Collection<int, Notice>
     */
    #[Computed]
    public function notices(): Collection
    {
        return $this->condominium()->notices()
            ->where('is_active', $this->tab === self::TAB_ACTIVE)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab === self::TAB_INACTIVE ? self::TAB_INACTIVE : self::TAB_ACTIVE;

        unset($this->notices);
    }

    public function create(): void
    {
        $this->authorize('knowledge.manage');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $noticeId): void
    {
        $this->authorize('knowledge.manage');

        $notice = $this->findNotice($noticeId);

        $this->resetForm();
        $this->editingId = $notice->id;
        $this->noticeTitle = $notice->title;
        $this->noticeBody = $notice->body;
        $this->isActive = $notice->is_active;
        $this->showForm = true;
    }

    public function save(NoticeService $noticeService): void
    {
        $this->authorize('knowledge.manage');

        $validated = $this->validate([
            'noticeTitle' => ['required', 'string', 'max:255'],
            'noticeBody' => ['required', 'string'],
            'isActive' => ['boolean'],
        ], attributes: [
            'noticeTitle' => 'título',
            'noticeBody' => 'texto',
            'isActive' => 'ativo',
        ]);

        $attributes = [
            'title' => $validated['noticeTitle'],
            'body' => $validated['noticeBody'],
            'is_active' => (bool) $validated['isActive'],
        ];

        $isCreating = $this->editingId === null;

        if ($isCreating) {
            $noticeService->create($this->condominium(), Auth::user(), $attributes);
        } else {
            $noticeService->update($this->findNotice($this->editingId), $attributes);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: $isCreating ? 'Comunicado criado.' : 'Comunicado atualizado.');
    }

    public function toggleActive(int $noticeId, NoticeService $noticeService): void
    {
        $this->authorize('knowledge.manage');

        $notice = $this->findNotice($noticeId);

        $noticeService->setActive($notice, ! $notice->is_active);

        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: $notice->is_active ? 'Comunicado ativado.' : 'Comunicado desativado.');
    }

    public function delete(int $noticeId, NoticeService $noticeService): void
    {
        $this->authorize('knowledge.manage');

        $noticeService->delete($this->findNotice($noticeId));

        if ($this->editingId === $noticeId) {
            $this->showForm = false;
            $this->resetForm();
        }

        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: 'Comunicado excluído.');
    }

    private function findNotice(int $noticeId): Notice
    {
        return $this->condominium()->notices()->findOrFail($noticeId);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function refreshList(): void
    {
        unset($this->counts, $this->notices);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'noticeTitle', 'noticeBody', 'isActive');
        $this->resetValidation();
    }
};
?>

<div class="flex max-w-[1100px] flex-col gap-4">
    <x-ui.tabs>
        <x-ui.tabs.tab :active="$tab === 'ativos'" :count="$this->counts['active']" wire:click="selectTab('ativos')" data-tab="ativos">Ativos ·</x-ui.tabs.tab>
        <x-ui.tabs.tab :active="$tab === 'inativos'" :count="$this->counts['inactive']" wire:click="selectTab('inativos')" data-tab="inativos">Inativos ·</x-ui.tabs.tab>

        <x-slot:actions>
            <x-ui.button size="sm" wire:click="create">+ Novo comunicado</x-ui.button>
        </x-slot:actions>
    </x-ui.tabs>

    <p class="text-[12px] text-ink-secondary">Só comunicados <b class="font-semibold text-ink">ativos</b> chegam ao agente. Inativos ficam no histórico e não são citados.</p>

    @if ($this->notices->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                :title="$tab === 'ativos' ? 'Nenhum comunicado ativo' : 'Nenhum comunicado inativo'"
                :description="$tab === 'ativos' ? 'O agente não tem avisos para informar aos moradores.' : 'Comunicados desativados aparecem aqui.'"
            />
        </x-ui.card>
    @else
        <div class="grid grid-cols-2 gap-3.5">
            @foreach ($this->notices as $notice)
                <article
                    wire:key="notice-{{ $notice->id }}"
                    @class([
                        'flex min-w-0 flex-col gap-2.5 rounded-card border border-line bg-white px-5 py-[18px] shadow-card',
                        'opacity-75' => ! $notice->is_active,
                    ])
                    data-notice="{{ $notice->id }}"
                >
                    <div class="flex items-center gap-2">
                        @if ($notice->is_active)
                            <x-ui.pill variant="ok">Ativo</x-ui.pill>
                        @else
                            <x-ui.pill>Inativo</x-ui.pill>
                        @endif

                        <span class="flex-1"></span>

                        <x-ui.toggle
                            :checked="$notice->is_active"
                            wire:click="toggleActive({{ $notice->id }})"
                            wire:key="notice-toggle-{{ $notice->id }}-{{ $notice->is_active ? 'on' : 'off' }}"
                            title="{{ $notice->is_active ? 'Desativar' : 'Ativar' }}"
                            data-notice-toggle
                        />
                    </div>

                    <h3 class="text-[15px] font-semibold tracking-[-0.01em] break-words">{{ $notice->title }}</h3>

                    <p class="text-[13px] whitespace-pre-line break-words text-ink-body">{{ $notice->body }}</p>

                    <div class="mt-0.5 flex items-center justify-between gap-3 text-[12px] text-ink-secondary">
                        <span>Atualizado em {{ $notice->updated_at->copy()->setTimezone(config('condo.timezone'))->format('d/m') }}</span>
                        <span class="flex items-center gap-3 font-medium">
                            <button
                                type="button"
                                wire:click="delete({{ $notice->id }})"
                                wire:confirm="Excluir o comunicado &quot;{{ $notice->title }}&quot;? Ele sai das abas e deixa de chegar ao agente."
                                class="text-tag-esc-fg hover:underline"
                            >Excluir</button>
                            <button type="button" wire:click="edit({{ $notice->id }})" class="text-accent hover:text-accent-hover">Editar</button>
                        </span>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <x-ui.modal wire:model="showForm" :title="$editingId === null ? 'Novo comunicado' : 'Editar comunicado'">
        <form id="notice-form" wire:submit="save" class="flex flex-col gap-3">
            <x-ui.field label="Título" name="noticeTitle" for="notice-title">
                <x-ui.input id="notice-title" wire:model="noticeTitle" maxlength="255" required />
            </x-ui.field>

            <x-ui.field label="Texto" name="noticeBody" for="notice-body">
                <x-ui.textarea id="notice-body" wire:model="noticeBody" rows="6" required />
            </x-ui.field>

            <x-ui.toggle label="Ativo" description="Só comunicados ativos chegam ao agente." :checked="$isActive" wire:model="isActive" />
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="notice-form" loading="save">Salvar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
