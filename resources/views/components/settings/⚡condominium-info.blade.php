<?php

use App\Models\Condominium;
use App\Rules\E164Phone;
use App\Services\Settings\CondominiumInfoService;
use App\Support\Tenancy\CurrentCondominium;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $city = '';

    public string $whatsappNumber = '';

    public string $caretakerName = '';

    public string $caretakerPhone = '';

    public string $quietHoursStart = '';

    public string $quietHoursEnd = '';

    public function mount(): void
    {
        $this->authorize('condominium.settings');

        $this->fillFromCondominium($this->condominium());
    }

    #[Computed]
    public function canRename(): bool
    {
        return auth()->user()->isSuperAdmin();
    }

    #[Computed]
    public function unitsSummary(): string
    {
        return app(CondominiumInfoService::class)->unitsSummary($this->condominium());
    }

    public function save(CondominiumInfoService $condominiumInfoService): void
    {
        $this->authorize('condominium.settings');

        $condominium = $this->condominium();

        $rules = [
            'city' => ['nullable', 'string', 'max:255'],
            'whatsappNumber' => ['nullable', 'string', new E164Phone],
            'caretakerName' => ['nullable', 'string', 'max:255'],
            'caretakerPhone' => ['nullable', 'string', new E164Phone],
            'quietHoursStart' => ['nullable', 'date_format:H:i', 'required_with:quietHoursEnd'],
            'quietHoursEnd' => ['nullable', 'date_format:H:i', 'required_with:quietHoursStart'],
        ];

        if ($this->canRename) {
            $rules['name'] = ['required', 'string', 'max:255', Rule::unique('condominiums', 'name')->ignore($condominium->id)];
        }

        $validated = $this->validate($rules, [
            'name.unique' => 'Já existe um condomínio com este nome.',
            'quietHoursStart.required_with' => 'Informe o início e o fim do horário de silêncio.',
            'quietHoursEnd.required_with' => 'Informe o início e o fim do horário de silêncio.',
        ], [
            'name' => 'nome',
            'city' => 'cidade',
            'whatsappNumber' => 'número WhatsApp',
            'caretakerName' => 'nome do zelador',
            'caretakerPhone' => 'telefone do zelador',
            'quietHoursStart' => 'início do horário de silêncio',
            'quietHoursEnd' => 'fim do horário de silêncio',
        ]);

        $condominiumInfoService->update($condominium, auth()->user(), [
            'name' => $validated['name'] ?? null,
            'city' => $validated['city'],
            'whatsapp_number' => $validated['whatsappNumber'],
            'caretaker_name' => $validated['caretakerName'],
            'caretaker_phone' => $validated['caretakerPhone'],
            'quiet_hours_start' => $validated['quietHoursStart'],
            'quiet_hours_end' => $validated['quietHoursEnd'],
        ]);

        $this->fillFromCondominium($condominium->refresh());

        $this->dispatch('toast', type: 'success', message: 'Dados do condomínio salvos.');
    }

    private function condominium(): Condominium
    {
        return app(CurrentCondominium::class)->getOrFail();
    }

    private function fillFromCondominium(Condominium $condominium): void
    {
        $this->name = $condominium->name;
        $this->city = (string) $condominium->city;
        $this->whatsappNumber = (string) $condominium->whatsapp_number;
        $this->caretakerName = (string) $condominium->caretaker_name;
        $this->caretakerPhone = (string) $condominium->caretaker_phone;
        $this->quietHoursStart = Str::substr((string) $condominium->quiet_hours_start, 0, 5);
        $this->quietHoursEnd = Str::substr((string) $condominium->quiet_hours_end, 0, 5);
    }
};
?>

<x-ui.card title="Condomínio" data-settings-card="condominium">
    <form wire:submit="save" class="flex flex-col gap-3.5">
        <x-ui.field layout="grid" label="Nome" name="name" for="settings-name">
            @if ($this->canRename)
                <x-ui.input id="settings-name" wire:model="name" required />
            @else
                <span id="settings-name" class="block rounded-control border border-line-strong bg-surface-input px-3 py-2 text-[13px] text-ink" data-readonly-name>{{ $name }}</span>
            @endif
        </x-ui.field>

        <x-ui.field layout="grid" label="Cidade" name="city" for="settings-city">
            <x-ui.input id="settings-city" wire:model="city" placeholder="Ex.: Curitiba · PR" />
        </x-ui.field>

        <x-ui.field layout="grid" label="Unidades">
            <span class="block rounded-control border border-line-strong bg-surface-input px-3 py-2 text-[13px] text-ink tabular-nums" data-units-summary>{{ $this->unitsSummary }}</span>
        </x-ui.field>

        <x-ui.field layout="grid" label="Número WhatsApp" name="whatsappNumber" for="settings-whatsapp">
            <x-ui.input id="settings-whatsapp" wire:model="whatsappNumber" placeholder="+554130002200" />
        </x-ui.field>

        <x-ui.field layout="grid" label="Zelador" for="settings-caretaker-name">
            <div class="grid grid-cols-2 gap-2">
                <x-ui.input id="settings-caretaker-name" wire:model="caretakerName" placeholder="Nome" aria-label="Nome do zelador" />
                <x-ui.input wire:model="caretakerPhone" placeholder="+5541995550102" aria-label="Telefone do zelador" />
            </div>
            @error('caretakerName')
                <p class="mt-1 text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
            @enderror
            @error('caretakerPhone')
                <p class="mt-1 text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
            @enderror
        </x-ui.field>

        <x-ui.field layout="grid" label="Horário de silêncio" for="settings-quiet-start">
            <div class="flex items-center gap-2">
                <x-ui.time id="settings-quiet-start" wire:model="quietHoursStart" aria-label="Início do horário de silêncio" />
                <span class="text-ink-secondary">–</span>
                <x-ui.time wire:model="quietHoursEnd" aria-label="Fim do horário de silêncio" />
            </div>
            @error('quietHoursStart')
                <p class="mt-1 text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
            @enderror
            @error('quietHoursEnd')
                @if ($message !== $errors->first('quietHoursStart'))
                    <p class="mt-1 text-[12px] text-tag-esc-fg" role="alert">{{ $message }}</p>
                @endif
            @enderror
        </x-ui.field>

        <div class="flex justify-end">
            <x-ui.button type="submit" size="sm" loading="save">Salvar</x-ui.button>
        </div>
    </form>
</x-ui.card>
