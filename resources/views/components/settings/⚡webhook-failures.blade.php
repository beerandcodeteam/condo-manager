<?php

use App\Models\Condominium;
use App\Models\WebhookDelivery;
use App\Services\Integration\WebhookService;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('webhooks.failures');
    }

    /**
     * @return LengthAwarePaginator<int, WebhookDelivery>
     */
    #[Computed]
    public function failures(): LengthAwarePaginator
    {
        return app(WebhookService::class)->failures($this->condominium());
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }
};
?>

<x-ui.card data-settings-card="webhook-failures">
    <x-slot:header>
        <div>Falhas de webhook</div>
        <div class="text-[12px] font-normal text-ink-secondary">Avisos ao morador que não chegaram ao n8n após {{ config('condo.webhooks.tries') }} tentativas. Avise o morador por outro meio.</div>
    </x-slot:header>

    <div class="flex flex-col gap-3">
        @php($columns = '150px 150px minmax(0,1fr) 80px minmax(0,1fr) 120px')

        <x-ui.table :columns="$columns">
            <x-slot:head>
                <span>Evento</span>
                <span>Morador</span>
                <span>Referência</span>
                <span>Tentativas</span>
                <span>Último retorno</span>
                <span>Data</span>
            </x-slot:head>

            @forelse ($this->failures as $delivery)
                <x-ui.table.row wire:key="webhook-failure-{{ $delivery->id }}" data-webhook-failure="{{ $delivery->id }}">
                    <span class="truncate font-medium">{{ $delivery->event->name }}</span>
                    <span class="text-ink-body tabular-nums">{{ filled($delivery->resident_phone) ? PhoneNumber::format($delivery->resident_phone) : '—' }}</span>
                    <span class="truncate text-ink-body" data-reference>{{ $delivery->reference_label }}</span>
                    <span class="text-ink-secondary tabular-nums">{{ $delivery->attempts }}</span>
                    <span class="truncate font-mono text-[12px] text-tag-esc-fg" title="{{ $delivery->last_error }}">{{ $delivery->last_response_code !== null ? 'HTTP '.$delivery->last_response_code : ($delivery->last_error ?? '—') }}</span>
                    <span class="text-ink-secondary tabular-nums">{{ $delivery->failed_at?->timezone(config('condo.timezone'))->format('d/m/Y H:i') }}</span>
                </x-ui.table.row>
            @empty
                <x-ui.empty-state title="Nenhuma falha de envio." />
            @endforelse
        </x-ui.table>

        {{ $this->failures->links() }}
    </div>
</x-ui.card>
