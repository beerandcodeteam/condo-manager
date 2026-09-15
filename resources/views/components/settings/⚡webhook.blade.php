<?php

use App\Models\Condominium;
use App\Services\Integration\WebhookService;
use App\Support\Tenancy\CurrentCondominium;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $url = '';

    public bool $showSecret = false;

    public ?string $plainTextSecret = null;

    public function mount(): void
    {
        $this->authorize('integration.manage');

        $this->url = (string) $this->condominium()->webhook_url;
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return $this->condominium()->hasWebhook();
    }

    #[Computed]
    public function hasSecret(): bool
    {
        return filled($this->condominium()->getRawOriginal('webhook_secret'));
    }

    public function saveUrl(WebhookService $webhookService): void
    {
        $this->authorize('integration.manage');

        $schemes = $webhookService->allowedSchemes();

        $validated = $this->validate(
            ['url' => ['nullable', 'string', 'max:255', 'url:'.implode(',', $schemes)]],
            ['url.url' => in_array('http', $schemes, true)
                ? 'Informe uma URL válida começando com http:// ou https://.'
                : 'Informe uma URL válida começando com https://.'],
            ['url' => 'URL'],
        );

        $webhookService->updateUrl($this->condominium(), $validated['url']);

        $this->url = (string) $this->condominium()->webhook_url;
        unset($this->isConfigured);

        $this->dispatch('toast', type: 'success', message: 'Webhook salvo.');
    }

    public function regenerateSecret(WebhookService $webhookService): void
    {
        $this->authorize('integration.manage');

        $this->plainTextSecret = $webhookService->regenerateSecret($this->condominium());
        $this->showSecret = true;

        unset($this->hasSecret);
    }

    /**
     * The plain text secret is shown only once: it is discarded as soon as its modal closes.
     */
    public function updatedShowSecret(bool $isVisible): void
    {
        if (! $isVisible) {
            $this->plainTextSecret = null;
        }
    }

    public function dismissSecret(): void
    {
        $this->showSecret = false;
        $this->plainTextSecret = null;
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }
};
?>

<x-ui.card data-settings-card="webhook">
    <x-slot:header>
        <div>Integração · webhook do n8n</div>
        <div class="text-[12px] font-normal text-ink-secondary">Eventos assinados (HMAC) enviados quando um humano altera algo para o morador.</div>
    </x-slot:header>

    <div class="flex flex-col gap-3.5">
        <div class="flex items-center gap-2 rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px]" data-webhook-status>
            @if ($this->isConfigured)
                <x-ui.pill variant="ok">Configurado</x-ui.pill>
                <span class="min-w-0 flex-1 truncate font-mono text-ink-body">{{ $url }}</span>
            @else
                <x-ui.pill variant="grey">Webhook não configurado</x-ui.pill>
                <span class="flex-1 text-ink-secondary">Sem URL, nenhum evento é enviado.</span>
            @endif
        </div>

        <form wire:submit="saveUrl" class="flex flex-col gap-3.5">
            <x-ui.field layout="grid" label="URL" name="url" for="webhook-url">
                <x-ui.input id="webhook-url" type="url" wire:model="url" placeholder="https://n8n.exemplo.com/webhook/condominio" class="font-mono text-[12px]" />
            </x-ui.field>

            <x-ui.field layout="grid" label="Segredo">
                <div class="flex items-center gap-3">
                    <span class="flex-1 font-mono text-[12px] text-ink-body">{{ $this->hasSecret ? '••••••••••••••••' : 'Nenhum segredo gerado' }}</span>
                    <button
                        type="button"
                        wire:click="regenerateSecret"
                        @if ($this->hasSecret) wire:confirm="Regenerar o segredo? O n8n precisará usar o novo segredo para validar os eventos." @endif
                        class="text-[12px] font-medium text-accent hover:text-accent-hover"
                    >{{ $this->hasSecret ? 'Regenerar segredo' : 'Gerar segredo' }}</button>
                </div>
            </x-ui.field>

            <div class="flex justify-end">
                <x-ui.button type="submit" size="sm" loading="saveUrl">Salvar</x-ui.button>
            </div>
        </form>
    </div>

    <x-ui.modal wire:model="showSecret" title="Segredo do webhook" size="md">
        @if (filled($plainTextSecret))
            <p>Copie o segredo agora. Por segurança ele <strong>não será exibido novamente</strong>.</p>

            <div x-data="{ copied: false }" class="flex items-center gap-2 rounded-control border border-line-strong bg-surface-input px-3 py-2 font-mono text-[12px]">
                <span x-ref="secret" class="min-w-0 flex-1 break-all text-ink-body" data-plain-text-secret>{{ $plainTextSecret }}</span>
                <button
                    type="button"
                    x-on:click="navigator.clipboard.writeText($refs.secret.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
                    class="shrink-0 font-sans text-[12px] font-medium text-accent hover:text-accent-hover"
                    x-text="copied ? 'Copiado' : 'Copiar'"
                >Copiar</button>
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button size="sm" wire:click="dismissSecret">Fechar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.card>
