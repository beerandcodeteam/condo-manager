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

        @can('reservations.manage')
            <livewire:settings.common-areas />
        @endcan
    </div>

    <div class="flex min-w-0 flex-col gap-3.5">
        @can('integration.manage')
            <livewire:settings.integration />
            <livewire:settings.webhook />
        @endcan
    </div>

    @can('webhooks.failures')
        <div class="col-span-2 min-w-0">
            <livewire:settings.webhook-failures />
        </div>
    @endcan
</div>
