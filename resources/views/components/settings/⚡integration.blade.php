<?php

use App\Models\AgentTool;
use App\Models\Condominium;
use App\Services\Integration\AgentToolUsageService;
use App\Services\Integration\ApiTokenService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showGenerateForm = false;

    public string $tokenName = '';

    public bool $showPlainTextToken = false;

    public ?string $plainTextToken = null;

    public function mount(): void
    {
        $this->authorize('integration.manage');
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return app(ApiTokenService::class)->tokens($this->condominium());
    }

    /**
     * @return Collection<int, AgentTool>
     */
    #[Computed]
    public function tools(): Collection
    {
        return app(AgentToolUsageService::class)->recentCalls($this->condominium());
    }

    public function openGenerateForm(): void
    {
        $this->authorize('integration.manage');

        $this->reset('tokenName');
        $this->resetValidation();
        $this->showGenerateForm = true;
    }

    public function generate(ApiTokenService $apiTokenService): void
    {
        $this->authorize('integration.manage');

        $validated = $this->validate(
            ['tokenName' => ['required', 'string', 'max:255']],
            attributes: ['tokenName' => 'nome'],
        );

        $newToken = $apiTokenService->generate($this->condominium(), $validated['tokenName']);

        $this->showGenerateForm = false;
        $this->reset('tokenName');
        unset($this->tokens);

        $this->plainTextToken = $newToken->plainTextToken;
        $this->showPlainTextToken = true;
    }

    /**
     * The plain text token is shown only once: it is discarded as soon as its modal closes.
     */
    public function updatedShowPlainTextToken(bool $isVisible): void
    {
        if (! $isVisible) {
            $this->plainTextToken = null;
        }
    }

    public function dismissPlainTextToken(): void
    {
        $this->showPlainTextToken = false;
        $this->plainTextToken = null;
    }

    public function revoke(int $tokenId, ApiTokenService $apiTokenService): void
    {
        $this->authorize('integration.manage');

        $apiTokenService->revoke($this->condominium(), $tokenId);

        unset($this->tokens);

        $this->dispatch('toast', type: 'success', message: 'Token revogado.');
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }
};
?>

<x-ui.card data-settings-card="integration">
    <x-slot:header>
        <div>Integração · tools do agente</div>
        <div class="text-[12px] font-normal text-ink-secondary">Endpoints que o fluxo n8n chama com o token do condomínio.</div>
    </x-slot:header>

    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-2">
            <span class="flex-1 text-[12px] font-semibold tracking-[0.04em] text-ink-tertiary uppercase">Tokens de API</span>
            <x-ui.button size="sm" variant="secondary" wire:click="openGenerateForm">Gerar token</x-ui.button>
        </div>

        @forelse ($this->tokens as $token)
            <div wire:key="token-{{ $token->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-control-lg bg-surface-soft px-3 py-2.5" data-api-token>
                <span class="min-w-0">
                    <span class="block truncate font-medium">{{ $token->name }}</span>
                    <span class="block text-[12px] text-ink-secondary">
                        Criado em {{ $token->created_at?->timezone(config('condo.timezone'))->format('d/m/Y H:i') }}
                        · {{ $token->last_used_at ? 'Último uso '.$token->last_used_at->timezone(config('condo.timezone'))->format('d/m/Y H:i') : 'Nunca usado' }}
                    </span>
                </span>
                <button
                    type="button"
                    wire:click="revoke({{ $token->id }})"
                    wire:confirm="Revogar o token &quot;{{ $token->name }}&quot;? O n8n que usa esse token deixará de ter acesso."
                    class="text-[12px] font-medium text-tag-esc-fg hover:underline"
                >Revogar</button>
            </div>
        @empty
            <p class="rounded-control-lg bg-surface-soft px-3 py-2.5 text-[12px] text-ink-secondary">Nenhum token gerado.</p>
        @endforelse

        <div class="mt-1 text-[12px] font-semibold tracking-[0.04em] text-ink-tertiary uppercase">Endpoints</div>

        <div class="flex flex-col">
            @foreach ($this->tools as $tool)
                @php($methodVariant = match ($tool->http_method) { 'GET' => 'ok', 'POST' => 'ia', 'DELETE' => 'esc', default => 'grey' })
                <div wire:key="tool-{{ $tool->id }}" class="grid grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-black/5 py-2 text-[12px] last:border-b-0" data-agent-tool="{{ $tool->slug }}">
                    <x-ui.pill :variant="$methodVariant" class="justify-center font-mono text-[10px]">{{ $tool->http_method }}</x-ui.pill>
                    <span class="min-w-0">
                        <span class="block truncate font-mono text-ink">{{ $tool->route }}</span>
                        <span class="block text-ink-secondary"><span class="font-medium text-ink-body">{{ $tool->name }}</span> · {{ $tool->description }}</span>
                    </span>
                    <span class="whitespace-nowrap text-ink-tertiary tabular-nums" data-recent-calls>{{ $tool->recent_calls_count }} / 7d</span>
                </div>
            @endforeach
        </div>
    </div>

    <x-ui.modal wire:model="showGenerateForm" title="Gerar token" size="sm">
        <form id="api-token-form" wire:submit="generate" class="flex flex-col gap-3">
            <x-ui.field label="Nome" name="tokenName" for="api-token-name" hint="Ex.: n8n produção.">
                <x-ui.input id="api-token-name" wire:model="tokenName" required />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" size="sm" form="api-token-form" loading="generate">Gerar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showPlainTextToken" title="Token gerado" size="md">
        @if (filled($plainTextToken))
            <p>Copie o token agora. Por segurança ele <strong>não será exibido novamente</strong>.</p>

            <div x-data="{ copied: false }" class="flex items-center gap-2 rounded-control border border-line-strong bg-surface-input px-3 py-2 font-mono text-[12px]">
                <span x-ref="token" class="min-w-0 flex-1 break-all text-ink-body" data-plain-text-token>{{ $plainTextToken }}</span>
                <button
                    type="button"
                    x-on:click="navigator.clipboard.writeText($refs.token.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
                    class="shrink-0 font-sans text-[12px] font-medium text-accent hover:text-accent-hover"
                    x-text="copied ? 'Copiado' : 'Copiar'"
                >Copiar</button>
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button size="sm" wire:click="dismissPlainTextToken">Fechar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-ui.card>
