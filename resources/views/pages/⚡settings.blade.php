<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Configurações')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('condominium.settings');
    }
};
?>

<div class="grid max-w-[1100px] grid-cols-2 items-start gap-3.5">
    <div class="flex min-w-0 flex-col gap-3.5">
        <livewire:settings.condominium-info />

        @can('categories.manage')
            <livewire:settings.ticket-categories />
        @endcan
    </div>

    <div class="flex min-w-0 flex-col gap-3.5">
        @can('integration.manage')
            <livewire:settings.integration />
            <livewire:settings.webhook />
        @endcan
    </div>
</div>
