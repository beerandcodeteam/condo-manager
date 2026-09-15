<?php

use App\Exceptions\EscalationActionBlockedException;
use App\Models\Condominium;
use App\Models\Escalation;
use App\Models\EscalationStatus;
use App\Services\Escalations\EscalationService;
use App\Support\Panel\PanelDate;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Escalonamentos')] class extends Component
{
    use WithPagination;

    /**
     * Filter labels in display order.
     *
     * @var array<string, string>
     */
    public const FILTERS = [
        EscalationService::FILTER_OPEN => 'Em aberto',
        EscalationService::FILTER_RESOLVED => 'Resolvidos',
    ];

    /**
     * Livewire event that tells the sidebar badge the pending count may have changed.
     */
    public const UPDATED_EVENT = 'escalations-updated';

    #[Url(as: 'filtro', except: EscalationService::FILTER_OPEN)]
    public string $filter = EscalationService::FILTER_OPEN;

    public bool $showAnswerForm = false;

    public ?int $answeringId = null;

    public string $answerResponse = '';

    public function mount(): void
    {
        $this->authorize('escalations.operate');

        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = EscalationService::FILTER_OPEN;
        }
    }

    public function selectFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : EscalationService::FILTER_OPEN;

        $this->resetPage();
    }

    /**
     * Take the escalation; taking one held by someone else requires `escalations.reassign` (403 otherwise).
     */
    public function assign(int $escalationId, EscalationService $escalationService): void
    {
        $this->authorize('escalations.operate');

        try {
            $escalationService->assign($this->findEscalation($escalationId), Auth::user());
        } catch (EscalationActionBlockedException $exception) {
            $this->refreshQueue();
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->refreshQueue();

        $this->dispatch(self::UPDATED_EVENT);
        $this->dispatch('toast', type: 'success', message: 'Escalonamento assumido.');
    }

    public function promptAnswer(int $escalationId): void
    {
        $this->authorize('escalations.operate');

        $escalation = $this->findEscalation($escalationId);

        if (! $escalation->hasStatus(EscalationStatus::EM_ATENDIMENTO) || $escalation->assigned_user_id !== Auth::id()) {
            $this->refreshQueue();
            $this->dispatch('toast', type: 'error', message: 'Só o responsável pelo escalonamento pode respondê-lo.');

            return;
        }

        $this->answeringId = $escalation->id;
        $this->answerResponse = '';
        $this->resetValidation('answerResponse');
        $this->showAnswerForm = true;
    }

    public function saveAnswer(EscalationService $escalationService): void
    {
        $this->authorize('escalations.operate');
        $this->resetValidation('answerResponse');

        try {
            $escalationService->answer($this->findEscalation((int) $this->answeringId), $this->answerResponse, Auth::user());
        } catch (ValidationException $exception) {
            $this->addError('answerResponse', collect($exception->errors())->flatten()->first());

            return;
        } catch (EscalationActionBlockedException|AuthorizationException $exception) {
            $this->showAnswerForm = false;
            $this->refreshQueue();
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->showAnswerForm = false;
        $this->reset('answeringId', 'answerResponse');
        $this->refreshQueue();

        $this->dispatch(self::UPDATED_EVENT);
        $this->dispatch('toast', type: 'success', message: 'Resposta enviada ao morador.');
    }

    /**
     * @return LengthAwarePaginator<int, Escalation>
     */
    #[Computed]
    public function escalations(): LengthAwarePaginator
    {
        return app(EscalationService::class)->queue($this->condominium(), $this->filter);
    }

    #[Computed]
    public function canReassign(): bool
    {
        return Auth::user()?->can('escalations.reassign') ?? false;
    }

    private function findEscalation(int $escalationId): Escalation
    {
        return $this->condominium()->escalations()->find($escalationId) ?? abort(404);
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function refreshQueue(): void
    {
        unset($this->escalations);
    }
};
?>

<div class="flex max-w-[1000px] flex-col gap-4">
    <p class="text-[13px] text-ink-secondary">O agente entregou estas conversas porque a regra não cobria, a tool recusou ou o morador pediu um humano.</p>

    <x-ui.tabs>
        @foreach ($this::FILTERS as $filterKey => $filterLabel)
            <x-ui.tabs.tab
                wire:key="filter-{{ $filterKey }}"
                :active="$filter === $filterKey"
                wire:click="selectFilter('{{ $filterKey }}')"
                data-filter="{{ $filterKey }}"
            >{{ $filterLabel }}</x-ui.tabs.tab>
        @endforeach
    </x-ui.tabs>

    <div class="flex flex-col gap-3" data-escalation-queue>
        @forelse ($this->escalations as $escalation)
            @php
                $isPending = $escalation->hasStatus(EscalationStatus::PENDENTE);
                $isInProgress = $escalation->hasStatus(EscalationStatus::EM_ATENDIMENTO);
                $isMine = $isInProgress && $escalation->assigned_user_id === auth()->id();
            @endphp

            <div
                wire:key="escalation-{{ $escalation->id }}"
                class="grid grid-cols-[1fr_170px] items-start gap-5 rounded-card border border-line bg-white px-5 py-[18px] shadow-card"
                data-escalation-card="{{ $escalation->id }}"
            >
                <div class="flex min-w-0 flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.dot :color="EscalationService::reasonDot($escalation->reason->slug)" />
                        <span class="font-semibold">{{ $escalation->resident->name }} <span class="font-normal text-ink-tertiary">· {{ $escalation->unit->label }}</span></span>
                        <x-ui.pill data-escalation-reason>{{ $escalation->reason->name }}</x-ui.pill>
                        @unless ($escalation->hasStatus(EscalationStatus::RESOLVIDO))
                            <span class="text-[12px] text-ink-tertiary">· esperando {{ EscalationService::waitLabel($escalation->created_at) }}</span>
                        @endunless
                    </div>

                    <p class="break-words whitespace-pre-line text-ink-body">{{ $escalation->summary }}</p>

                    @if ($escalation->ticket !== null)
                        <div>
                            <a
                                href="{{ route('tickets.index', ['chamado' => $escalation->ticket->protocol_number]) }}"
                                class="text-[12px] font-medium text-accent hover:underline"
                                data-escalation-ticket="{{ $escalation->ticket->protocol_number }}"
                            >Chamado {{ $escalation->ticket->protocol_label }}</a>
                        </div>
                    @endif

                    @if ($escalation->hasStatus(EscalationStatus::RESOLVIDO))
                        <div class="rounded-[10px] bg-surface-soft px-3 py-2.5 text-[12px] text-ink-body" data-escalation-response>
                            <span class="font-semibold text-ink-tertiary">Resposta:</span> <span class="break-words whitespace-pre-line">{{ $escalation->response }}</span>
                        </div>
                    @endif
                </div>

                <div class="flex w-[170px] flex-col gap-2" data-escalation-actions>
                    @if ($isPending)
                        <x-ui.button wire:click="assign({{ $escalation->id }})" data-escalation-assign>Assumir</x-ui.button>
                    @elseif ($isMine)
                        <div class="rounded-control-lg bg-tag-ok-bg px-3.5 py-[9px] text-center font-semibold text-tag-ok-fg" data-escalation-holder>Com você</div>
                        <x-ui.button wire:click="promptAnswer({{ $escalation->id }})" data-escalation-answer>Responder</x-ui.button>
                    @elseif ($isInProgress)
                        <div class="truncate rounded-control-lg bg-tag-grey-bg px-3.5 py-[9px] text-center font-semibold text-tag-grey-fg" data-escalation-holder>Com {{ $escalation->assignedUser?->name }}</div>
                        @if ($this->canReassign)
                            <x-ui.button variant="secondary" wire:click="assign({{ $escalation->id }})" data-escalation-assign>Assumir</x-ui.button>
                        @endif
                    @else
                        <x-ui.pill variant="ok" class="self-start">Resolvido</x-ui.pill>
                        <div class="text-[12px] text-ink-secondary" data-escalation-responder>
                            <span class="block truncate">por {{ $escalation->respondedBy?->name ?? '—' }}</span>
                            @if ($escalation->resolved_at !== null)
                                <span class="block">{{ PanelDate::relative($escalation->resolved_at, withTime: true) }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.card>
                <x-ui.empty-state
                    :title="$filter === EscalationService::FILTER_RESOLVED ? 'Nenhum escalonamento resolvido' : 'Nenhum escalonamento em aberto'"
                    description="Quando o agente precisar de um humano, a conversa aparece aqui."
                />
            </x-ui.card>
        @endforelse
    </div>

    {{ $this->escalations->links() }}

    <x-ui.modal wire:model="showAnswerForm" title="Responder morador">
        <form id="escalation-answer-form" wire:submit="saveAnswer" class="flex flex-col gap-3">
            <x-ui.field label="Resposta" name="answerResponse" for="escalation-answer-response">
                <x-ui.textarea id="escalation-answer-response" wire:model="answerResponse" rows="5" placeholder="Escreva a resposta que o morador vai receber no WhatsApp." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="escalation-answer-form" loading="saveAnswer">Enviar resposta</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
