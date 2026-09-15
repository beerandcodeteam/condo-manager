<?php

use App\Exceptions\Api\InvalidCategory;
use App\Exceptions\TicketActionBlockedException;
use App\Models\Block;
use App\Models\Condominium;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketOrigin;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\Unit;
use App\Services\Tickets\TicketService;
use App\Support\Panel\PanelDate;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Chamados')] class extends Component
{
    use WithFileUploads, WithPagination;

    /**
     * Tab labels in display order.
     *
     * @var array<string, string>
     */
    public const TABS = [
        TicketService::TAB_ALL => 'Todos',
        TicketService::TAB_OPEN => 'Abertos',
        TicketService::TAB_IN_PROGRESS => 'Em andamento',
        TicketService::TAB_DONE => 'Concluídos',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_PILL_VARIANTS = [
        TicketStatus::ABERTO => 'esc',
        TicketStatus::EM_ANDAMENTO => 'warn',
        TicketStatus::RESOLVIDO => 'ok',
        TicketStatus::CANCELADO => 'grey',
    ];

    /**
     * @var array<string, string>
     */
    public const PRIORITY_DOT_COLORS = [
        TicketPriority::ALTA => '#e5484d',
        TicketPriority::MEDIA => '#f5a623',
        TicketPriority::BAIXA => '#8e8e93',
    ];

    /**
     * Status transitions offered in the drawer: button label, form title, comment label and success toast.
     *
     * @var array<string, array{label: string, title: string, comment: string, success: string}>
     */
    public const STATUS_ACTIONS = [
        TicketStatus::EM_ANDAMENTO => [
            'label' => 'Iniciar atendimento',
            'title' => 'Iniciar atendimento',
            'comment' => 'Comentário (opcional)',
            'success' => 'Atendimento iniciado.',
        ],
        TicketStatus::RESOLVIDO => [
            'label' => 'Marcar concluído',
            'title' => 'Marcar chamado como concluído',
            'comment' => 'Comentário',
            'success' => 'Chamado concluído.',
        ],
        TicketStatus::CANCELADO => [
            'label' => 'Cancelar chamado',
            'title' => 'Cancelar chamado',
            'comment' => 'Motivo do cancelamento',
            'success' => 'Chamado cancelado.',
        ],
    ];

    /**
     * TicketService validation keys → fields of the new ticket form.
     *
     * @var array<string, string>
     */
    public const CREATE_ERROR_FIELDS = [
        'description' => 'newDescription',
        'priority' => 'newPriority',
        'unit_id' => 'newUnitId',
        'resident_id' => 'newResidentId',
    ];

    #[Url(as: 'aba', except: TicketService::TAB_ALL)]
    public string $tab = TicketService::TAB_ALL;

    #[Url(as: 'categoria', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'prioridade', except: '')]
    public string $priorityFilter = '';

    #[Url(as: 'bloco', except: '')]
    public string $blockFilter = '';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    /**
     * Protocol number of the ticket open in the drawer.
     */
    #[Url(as: 'chamado', except: '')]
    public string $selectedProtocol = '';

    public bool $showDrawer = false;

    public bool $showStatusForm = false;

    public string $statusTarget = '';

    public string $statusComment = '';

    public bool $showNoticeForm = false;

    public string $noticeMessage = '';

    public bool $showCreateForm = false;

    public string $newDescription = '';

    public string $newLocation = '';

    public string $newCategoryId = '';

    public string $newPriority = TicketPriority::MEDIA;

    public string $newUnitId = '';

    public string $newResidentId = '';

    /**
     * @var array<int, TemporaryUploadedFile>
     */
    public array $newPhotos = [];

    public function mount(): void
    {
        $this->authorize('tickets.operate');

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = TicketService::TAB_ALL;
        }

        if ($this->selectedTicket === null) {
            $this->selectedProtocol = '';
        }

        $this->showDrawer = $this->selectedTicket !== null;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['categoryFilter', 'priorityFilter', 'blockFilter', 'search'], true)) {
            $this->resetPage();
            unset($this->counts);
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : TicketService::TAB_ALL;

        $this->resetPage();
    }

    public function openTicket(int $protocol): void
    {
        $this->authorize('tickets.operate');

        $this->selectedProtocol = (string) $protocol;
        unset($this->selectedTicket, $this->timeline);

        if ($this->selectedTicket === null) {
            $this->selectedProtocol = '';
        }

        $this->showDrawer = $this->selectedTicket !== null;
    }

    public function updatedShowDrawer(bool $isOpen): void
    {
        if (! $isOpen) {
            $this->selectedProtocol = '';
            $this->showStatusForm = false;
            $this->showNoticeForm = false;
            unset($this->selectedTicket, $this->timeline);
        }
    }

    public function create(): void
    {
        $this->authorize('tickets.operate');

        $this->resetCreateForm();
        $this->showCreateForm = true;
    }

    public function updatedNewUnitId(): void
    {
        $this->newResidentId = '';
        unset($this->formResidents);
    }

    public function saveTicket(TicketService $ticketService): void
    {
        $this->authorize('tickets.operate');

        $condominium = $this->condominium();

        $validated = $this->validate([
            'newDescription' => ['required', 'string'],
            'newLocation' => ['nullable', 'string', 'max:255'],
            'newCategoryId' => ['nullable', 'integer'],
            'newPriority' => ['required', 'string', Rule::in([TicketPriority::ALTA, TicketPriority::MEDIA, TicketPriority::BAIXA])],
            'newUnitId' => ['nullable', 'integer', Rule::exists('units', 'id')->where('condominium_id', $condominium->id)],
            'newResidentId' => ['nullable', 'integer', Rule::exists('residents', 'id')->where('condominium_id', $condominium->id)],
            'newPhotos' => ['array', 'max:'.config('condo.tickets.max_photos')],
            'newPhotos.*' => ['file', 'mimes:'.implode(',', config('condo.tickets.photo_mimes')), 'max:'.config('condo.tickets.max_photo_kb')],
        ], attributes: [
            'newDescription' => 'descrição',
            'newLocation' => 'local',
            'newCategoryId' => 'categoria',
            'newPriority' => 'prioridade',
            'newUnitId' => 'unidade',
            'newResidentId' => 'morador',
            'newPhotos' => 'fotos',
            'newPhotos.*' => 'foto',
        ]);

        try {
            $ticket = $ticketService->open(
                condominium: $condominium,
                description: $validated['newDescription'],
                origin: TicketOrigin::PAINEL,
                location: $validated['newLocation'],
                category: filled($validated['newCategoryId']) ? (int) $validated['newCategoryId'] : null,
                priority: $validated['newPriority'],
                unit: filled($validated['newUnitId']) ? (int) $validated['newUnitId'] : null,
                resident: filled($validated['newResidentId']) ? (int) $validated['newResidentId'] : null,
                user: Auth::user(),
                photos: array_values($this->newPhotos),
            );
        } catch (InvalidCategory $exception) {
            $this->addError('newCategoryId', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(self::CREATE_ERROR_FIELDS[$field] ?? 'newDescription', $messages[0]);
            }

            return;
        }

        $this->showCreateForm = false;
        $this->resetCreateForm();
        $this->resetPage();
        $this->refreshList();
        $this->openTicket($ticket->protocol_number);

        $this->dispatch('toast', type: 'success', message: "Chamado {$ticket->protocol_label} aberto.");
    }

    /**
     * Active categories offered when opening a ticket.
     *
     * @return Collection<int, TicketCategory>
     */
    #[Computed]
    public function formCategories(): Collection
    {
        return $this->condominium()->ticketCategories()->active()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function formUnits(): Collection
    {
        return $this->condominium()->units()->orderByLabel()->with('block')->get();
    }

    /**
     * Active residents of the unit chosen in the form.
     *
     * @return Collection<int, Resident>
     */
    #[Computed]
    public function formResidents(): Collection
    {
        if (! ctype_digit($this->newUnitId)) {
            return new Collection;
        }

        return $this->condominium()->residents()
            ->where('unit_id', (int) $this->newUnitId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Open the status form for the transition: comment optional for `em_andamento`, required for `resolvido`/`cancelado`.
     */
    public function promptStatus(string $status): void
    {
        $this->authorize('tickets.operate');

        $this->statusTarget = array_key_exists($status, self::STATUS_ACTIONS) ? $status : '';
        $this->statusComment = '';
        $this->resetValidation('statusComment');
        $this->showStatusForm = $this->statusTarget !== '';
    }

    public function saveStatus(TicketService $ticketService): void
    {
        $this->authorize('tickets.operate');

        $ticket = $this->selectedTicketOrFail();
        $this->resetValidation('statusComment');

        try {
            $ticketService->changeStatus($ticket, $this->statusTarget, $this->statusComment, Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('statusComment', collect($exception->errors())->flatten()->first());

            return;
        } catch (TicketActionBlockedException $exception) {
            $this->showStatusForm = false;
            $this->refreshList();
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $successMessage = self::STATUS_ACTIONS[$this->statusTarget]['success'];

        $this->showStatusForm = false;
        $this->reset('statusTarget', 'statusComment');
        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: $successMessage);
    }

    public function changePriority(string $priority, TicketService $ticketService): void
    {
        $this->authorize('tickets.operate');

        try {
            $ticketService->changePriority($this->selectedTicketOrFail(), $priority);
        } catch (ValidationException|TicketActionBlockedException $exception) {
            $this->refreshList();
            $this->dispatch('toast', type: 'error', message: $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->first()
                : $exception->getMessage());

            return;
        }

        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: 'Prioridade atualizada.');
    }

    public function promptNotice(): void
    {
        $this->authorize('tickets.operate');

        $this->noticeMessage = '';
        $this->resetValidation('noticeMessage');
        $this->showNoticeForm = true;
    }

    public function sendNotice(TicketService $ticketService): void
    {
        $this->authorize('tickets.operate');
        $this->resetValidation('noticeMessage');

        try {
            $notice = $ticketService->notifyResident($this->selectedTicketOrFail(), $this->noticeMessage, Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('noticeMessage', collect($exception->errors())->flatten()->first());

            return;
        } catch (TicketActionBlockedException $exception) {
            $this->showNoticeForm = false;
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->showNoticeForm = false;
        $this->reset('noticeMessage');
        $this->refreshList();

        $this->dispatch('toast', type: 'success', message: $notice->webhook_delivery_id === null
            ? 'Aviso registrado — condomínio sem webhook configurado.'
            : 'Aviso enviado ao morador.');
    }

    /**
     * Ticket open in the drawer, with what the drawer shows.
     */
    #[Computed]
    public function selectedTicket(): ?Ticket
    {
        if (! ctype_digit($this->selectedProtocol) || strlen($this->selectedProtocol) > 9) {
            return null;
        }

        return $this->condominium()->tickets()
            ->where('protocol_number', (int) $this->selectedProtocol)
            ->with(['status', 'priority', 'category', 'unit.block', 'resident', 'openedBy', 'photos' => fn ($query) => $query->orderBy('id')])
            ->first();
    }

    /**
     * @return list<array{type: string, text: string, at: \Carbon\CarbonInterface|null, by: string|null, dot: string}>
     */
    #[Computed]
    public function timeline(): array
    {
        return $this->selectedTicket === null ? [] : app(TicketService::class)->timeline($this->selectedTicket);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(TicketService::class)->tabCounts($this->condominium(), $this->filters());
    }

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    #[Computed]
    public function tickets(): LengthAwarePaginator
    {
        return app(TicketService::class)->paginate($this->condominium(), [
            ...$this->filters(),
            'tab' => $this->tab,
        ]);
    }

    /**
     * @return Collection<int, TicketCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->condominium()->ticketCategories()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, TicketPriority>
     */
    #[Computed]
    public function priorities(): Collection
    {
        return TicketPriority::query()->orderBy('id')->get();
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
     * @return array{category_id: int|null, priority: string|null, block_id: int|null, search: string}
     */
    private function filters(): array
    {
        return [
            'category_id' => ctype_digit($this->categoryFilter) ? (int) $this->categoryFilter : null,
            'priority' => filled($this->priorityFilter) ? $this->priorityFilter : null,
            'block_id' => ctype_digit($this->blockFilter) ? (int) $this->blockFilter : null,
            'search' => $this->search,
        ];
    }

    private function selectedTicketOrFail(): Ticket
    {
        return $this->selectedTicket ?? abort(404);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function resetCreateForm(): void
    {
        $this->reset('newDescription', 'newLocation', 'newCategoryId', 'newPriority', 'newUnitId', 'newResidentId', 'newPhotos');
        $this->resetValidation();
    }

    private function refreshList(): void
    {
        unset($this->counts, $this->tickets, $this->selectedTicket, $this->timeline);
    }
};
?>

<div class="flex max-w-[1200px] flex-col gap-4">
    <x-ui.tabs>
        @foreach ($this::TABS as $tabKey => $tabLabel)
            <x-ui.tabs.tab
                wire:key="tab-{{ $tabKey }}"
                :active="$tab === $tabKey"
                :count="$this->counts[$tabKey]"
                wire:click="selectTab('{{ $tabKey }}')"
                data-tab="{{ $tabKey }}"
            >{{ $tabLabel }}</x-ui.tabs.tab>
        @endforeach

        <x-slot:actions>
            <x-ui.button size="sm" wire:click="create" data-new-ticket>+ Novo chamado</x-ui.button>
        </x-slot:actions>
    </x-ui.tabs>

    <div class="flex flex-wrap items-center gap-2">
        <div class="w-[280px]">
            <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por protocolo ou descrição" aria-label="Buscar chamado por protocolo ou descrição" />
        </div>
        <div class="w-[170px]">
            <x-ui.select wire:model.live="categoryFilter" placeholder="Todas as categorias" aria-label="Filtrar por categoria">
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}" wire:key="category-filter-{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-[170px]">
            <x-ui.select wire:model.live="priorityFilter" placeholder="Todas as prioridades" aria-label="Filtrar por prioridade">
                @foreach ($this->priorities as $priority)
                    <option value="{{ $priority->slug }}" wire:key="priority-filter-{{ $priority->slug }}">{{ $priority->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        @if ($this->blocks->isNotEmpty())
            <div class="w-[150px]">
                <x-ui.select wire:model.live="blockFilter" placeholder="Todos os blocos" aria-label="Filtrar por bloco">
                    @foreach ($this->blocks as $block)
                        <option value="{{ $block->id }}" wire:key="block-filter-{{ $block->id }}">{{ $block->label }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        @endif
    </div>

    @php($columns = '90px minmax(0,2fr) 90px 130px 100px 120px 110px')

    <x-ui.table :columns="$columns">
        <x-slot:head>
            <span>Protocolo</span>
            <span>Chamado</span>
            <span>Unidade</span>
            <span>Categoria</span>
            <span>Prioridade</span>
            <span>Status</span>
            <span>Aberto</span>
        </x-slot:head>

        @forelse ($this->tickets as $ticket)
            <x-ui.table.row
                clickable
                wire:click="openTicket({{ $ticket->protocol_number }})"
                wire:key="ticket-{{ $ticket->id }}"
                @class(['bg-[#f0f0f6]' => $selectedProtocol === (string) $ticket->protocol_number])
                data-ticket-row="{{ $ticket->protocol_number }}"
            >
                <span class="font-mono text-[12px] text-[#4a4a55]">{{ $ticket->protocol_label }}</span>
                <span class="min-w-0">
                    <span class="block truncate font-medium">{{ $ticket->description }}</span>
                    <span class="block text-[12px] text-ink-secondary">{{ $ticket->origin_label }}</span>
                </span>
                <span>{{ $ticket->unit_label }}</span>
                <span class="text-ink-body">{{ $ticket->category?->name ?? '—' }}</span>
                <span class="flex items-center gap-1.5">
                    <x-ui.dot :size="7" :color="$this::PRIORITY_DOT_COLORS[$ticket->priority->slug]" />{{ $ticket->priority->name }}
                </span>
                <span><x-ui.pill :variant="$this::STATUS_PILL_VARIANTS[$ticket->status->slug]">{{ $ticket->status->name }}</x-ui.pill></span>
                <span class="text-[12px] text-ink-secondary">{{ PanelDate::relative($ticket->created_at) }}</span>
            </x-ui.table.row>
        @empty
            <x-ui.empty-state title="Nenhum chamado encontrado" description="Chamados abertos pelo agente ou pelo painel aparecem aqui." />
        @endforelse
    </x-ui.table>

    {{ $this->tickets->links() }}

    <x-ui.drawer wire:model.live="showDrawer" data-ticket-drawer>
        @if ($ticket = $this->selectedTicket)
            <x-slot:header>
                <div class="flex items-center gap-2.5">
                    <span class="font-mono text-[12px] text-ink-secondary">{{ $ticket->protocol_label }}</span>
                    <x-ui.pill :variant="$this::STATUS_PILL_VARIANTS[$ticket->status->slug]">{{ $ticket->status->name }}</x-ui.pill>
                </div>
                <h2 class="mt-2.5 text-[18px] font-semibold tracking-[-0.01em] break-words">{{ Str::limit($ticket->description, 80) }}</h2>
                <div class="mt-1 text-[12px] text-ink-secondary">{{ $ticket->unit_label }} · {{ $ticket->category?->name ?? '—' }} · {{ $ticket->origin_label }}</div>
            </x-slot:header>

            <section>
                <h3 class="mb-1.5 text-[12px] font-semibold text-ink-tertiary">{{ $ticket->isFromWhatsapp() ? 'Descrição gerada pelo agente' : 'Descrição' }}</h3>
                <p class="whitespace-pre-line break-words text-ink-body">{{ $ticket->description }}</p>
                @if (filled($ticket->location))
                    <p class="mt-1.5 text-[12px] text-ink-secondary">Local: {{ $ticket->location }}</p>
                @endif
            </section>

            @if ($ticket->photos->isNotEmpty())
                <section>
                    <h3 class="mb-2 text-[12px] font-semibold text-ink-tertiary">Fotos enviadas</h3>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($ticket->photos as $photo)
                            <a
                                href="{{ route('tickets.photos.show', $photo) }}"
                                target="_blank"
                                rel="noopener"
                                wire:key="ticket-photo-{{ $photo->id }}"
                                class="block overflow-hidden rounded-[10px] bg-surface-soft"
                                data-ticket-photo="{{ $photo->id }}"
                            >
                                <img src="{{ route('tickets.photos.show', $photo) }}" alt="Foto {{ $loop->iteration }} do chamado {{ $ticket->protocol_label }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section>
                <h3 class="mb-2.5 text-[12px] font-semibold text-ink-tertiary">Linha do tempo</h3>
                <ol class="flex flex-col gap-3" data-ticket-timeline>
                    @foreach ($this->timeline as $entry)
                        <li class="grid grid-cols-[14px_1fr] gap-2.5" data-timeline-entry="{{ $entry['type'] }}">
                            <span class="mt-1 size-2.5 rounded-full border-2 border-white shadow-[0_0_0_1px_rgba(0,0,0,.1)]" style="background: {{ $entry['dot'] }}"></span>
                            <span class="min-w-0">
                                <span class="block font-medium break-words">{{ $entry['text'] }}</span>
                                <span class="block text-[11px] text-ink-tertiary">{{ collect([$entry['at'] === null ? null : PanelDate::relative($entry['at'], withTime: true), $entry['by']])->filter()->join(' · ') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <x-slot:footer class="flex-col">
                @unless ($ticket->isFinal())
                    <div class="flex items-center gap-2" data-ticket-transitions>
                        @php($primaryStatus = $ticket->status->slug === 'aberto' ? 'em_andamento' : 'resolvido')
                        <x-ui.button class="flex-1" wire:click="promptStatus('{{ $primaryStatus }}')" data-status-action="{{ $primaryStatus }}">{{ $this::STATUS_ACTIONS[$primaryStatus]['label'] }}</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="promptStatus('cancelado')" data-status-action="cancelado">Cancelar chamado</x-ui.button>
                    </div>
                @endunless

                <div class="flex items-center gap-2">
                    @unless ($ticket->isFinal())
                        <div class="w-[150px]" data-priority-select>
                            <x-ui.select wire:change="changePriority($event.target.value)" aria-label="Prioridade do chamado" wire:key="priority-select-{{ $ticket->id }}-{{ $ticket->priority->slug }}">
                                @foreach ($this->priorities as $priority)
                                    <option value="{{ $priority->slug }}" @selected($priority->slug === $ticket->priority->slug)>Prioridade {{ Str::lower($priority->name) }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @endunless

                    <span class="flex-1"></span>

                    @if ($ticket->resident_id === null)
                        <span class="text-[12px] text-ink-tertiary" data-notify-hint>Sem morador vinculado</span>
                        <x-ui.button variant="secondary" disabled title="Chamado sem morador vinculado: não há para quem enviar o aviso." data-notify-resident>Avisar morador</x-ui.button>
                    @else
                        <x-ui.button variant="secondary" wire:click="promptNotice" title="Enviar mensagem a {{ $ticket->resident->name }}" data-notify-resident>Avisar morador</x-ui.button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-ui.drawer>

    <x-ui.modal wire:model="showCreateForm" title="Novo chamado">
        <form id="ticket-create-form" wire:submit="saveTicket" class="flex flex-col gap-3">
            <x-ui.field label="Descrição" name="newDescription" for="ticket-new-description">
                <x-ui.textarea id="ticket-new-description" wire:model="newDescription" rows="4" placeholder="Descreva o problema encontrado" required />
            </x-ui.field>

            <x-ui.field label="Local" name="newLocation" for="ticket-new-location">
                <x-ui.input id="ticket-new-location" wire:model="newLocation" maxlength="255" placeholder="Ex.: garagem G2, vagas 41–44" />
            </x-ui.field>

            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Categoria" name="newCategoryId" for="ticket-new-category">
                    <x-ui.select id="ticket-new-category" wire:model="newCategoryId" placeholder="Sem categoria">
                        @foreach ($this->formCategories as $category)
                            <option value="{{ $category->id }}" wire:key="form-category-{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Prioridade" name="newPriority" for="ticket-new-priority">
                    <x-ui.select id="ticket-new-priority" wire:model="newPriority">
                        @foreach ($this->priorities as $priority)
                            <option value="{{ $priority->slug }}" wire:key="form-priority-{{ $priority->slug }}">{{ $priority->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Unidade (opcional)" name="newUnitId" for="ticket-new-unit">
                    <x-ui.select id="ticket-new-unit" wire:model.live="newUnitId" placeholder="Área comum">
                        @foreach ($this->formUnits as $unit)
                            <option value="{{ $unit->id }}" wire:key="form-unit-{{ $unit->id }}">{{ $unit->label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Morador (opcional)" name="newResidentId" for="ticket-new-resident">
                    <x-ui.select
                        id="ticket-new-resident"
                        wire:model="newResidentId"
                        wire:key="form-residents-{{ $newUnitId }}"
                        :placeholder="blank($newUnitId) ? 'Selecione a unidade' : 'Sem morador'"
                        :disabled="blank($newUnitId)"
                    >
                        @foreach ($this->formResidents as $resident)
                            <option value="{{ $resident->id }}" wire:key="form-resident-{{ $resident->id }}">{{ $resident->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <x-ui.field label="Fotos" name="newPhotos" hint="Até 5 fotos de até 10MB (jpg, png, webp ou heic).">
                <x-ui.file-drop
                    wire:model="newPhotos"
                    label="+ Adicionar fotos"
                    accept=".jpg,.jpeg,.png,.webp,.heic,image/jpeg,image/png,image/webp,image/heic"
                    multiple
                />
                @foreach ($errors->get('newPhotos.*') as $photoErrors)
                    <p class="mt-1 text-[12px] text-tag-esc-fg" role="alert">{{ $photoErrors[0] }}</p>
                @endforeach
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="ticket-create-form" loading="saveTicket">Abrir chamado</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showStatusForm" :title="$this::STATUS_ACTIONS[$statusTarget]['title'] ?? 'Alterar status'">
        <form id="ticket-status-form" wire:submit="saveStatus" class="flex flex-col gap-3">
            <x-ui.field :label="$this::STATUS_ACTIONS[$statusTarget]['comment'] ?? 'Comentário'" name="statusComment" for="ticket-status-comment">
                <x-ui.textarea id="ticket-status-comment" wire:model="statusComment" rows="4" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Voltar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="ticket-status-form" loading="saveStatus">Confirmar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showNoticeForm" title="Avisar morador">
        <form id="ticket-notice-form" wire:submit="sendNotice" class="flex flex-col gap-3" x-data="{ length: $wire.noticeMessage.length }">
            <x-ui.field label="Mensagem" name="noticeMessage" for="ticket-notice-message">
                <x-ui.textarea
                    id="ticket-notice-message"
                    wire:model="noticeMessage"
                    x-on:input="length = $event.target.value.length"
                    maxlength="{{ config('condo.tickets.notice_max_length') }}"
                    rows="5"
                    placeholder="Ex.: visita técnica agendada para amanhã às 9h."
                />
            </x-ui.field>
            <div class="text-right text-[12px] text-ink-tertiary tabular-nums" data-notice-counter><span x-text="length">{{ mb_strlen($noticeMessage) }}</span>/{{ config('condo.tickets.notice_max_length') }}</div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="ticket-notice-form" loading="sendNotice">Enviar aviso</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
