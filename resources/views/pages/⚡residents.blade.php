<?php

use App\Support\Tenancy\CurrentCondominium;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Moradores e unidades')] class extends Component
{
    public const TAB_RESIDENTS = 'moradores';

    public const TAB_UNITS = 'unidades';

    #[Url(as: 'aba', except: self::TAB_RESIDENTS)]
    public string $tab = self::TAB_RESIDENTS;

    public function mount(): void
    {
        $this->authorize('residents.manage');

        if (! in_array($this->tab, [self::TAB_RESIDENTS, self::TAB_UNITS], true)) {
            $this->tab = self::TAB_RESIDENTS;
        }
    }

    /**
     * @return array{residents: int, units: int}
     */
    #[Computed]
    public function counts(): array
    {
        $condominium = app(CurrentCondominium::class)->getOrFail();

        return [
            'residents' => $condominium->residents()->count(),
            'units' => $condominium->units()->count(),
        ];
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab === self::TAB_UNITS ? self::TAB_UNITS : self::TAB_RESIDENTS;
    }

    #[On('registry-changed')]
    public function refreshCounts(): void
    {
        unset($this->counts);
    }
};
?>

<div class="flex max-w-[1100px] flex-col gap-4">
    <x-ui.tabs>
        <x-ui.tabs.tab :active="$tab === 'moradores'" :count="$this->counts['residents']" wire:click="selectTab('moradores')" data-tab="moradores">Moradores</x-ui.tabs.tab>
        <x-ui.tabs.tab :active="$tab === 'unidades'" :count="$this->counts['units']" wire:click="selectTab('unidades')" data-tab="unidades">Unidades</x-ui.tabs.tab>
    </x-ui.tabs>

    @if ($tab === 'unidades')
        <livewire:registry.units wire:key="registry-units" />
    @else
        <livewire:registry.residents wire:key="registry-residents" />
    @endif
</div>
