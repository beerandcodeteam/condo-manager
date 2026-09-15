<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Visão geral')] class extends Component
{
    //
};
?>

<div class="flex max-w-[1200px] flex-col gap-5">
    <x-ui.card>
        <x-ui.empty-state
            title="Nenhum dado para exibir ainda"
            description="Os indicadores do dia, a atividade do agente e a fila de atendimento humano aparecerão aqui."
        />
    </x-ui.card>
</div>
