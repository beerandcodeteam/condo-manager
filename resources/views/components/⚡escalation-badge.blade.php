<?php

use App\Services\Escalations\EscalationService;
use App\Support\Tenancy\CurrentCondominium;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Count of `pendente` escalations of the current condominium in the sidebar, hidden when zero.
 * Re-counted on every page load and whenever the queue dispatches `escalations-updated`.
 */
new class extends Component
{
    #[On('escalations-updated')]
    public function refreshCount(): void
    {
        unset($this->pendingCount);
    }

    #[Computed]
    public function pendingCount(): int
    {
        $condominium = app(CurrentCondominium::class)->get();

        return $condominium === null ? 0 : app(EscalationService::class)->pendingCount($condominium);
    }
};
?>

<span class="contents" data-escalation-badge>
    @if ($this->pendingCount > 0)
        <x-ui.count-badge>{{ $this->pendingCount }}</x-ui.count-badge>
    @endif
</span>
