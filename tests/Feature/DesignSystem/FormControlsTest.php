<?php

use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithFileUploads;

class FormControlsHostComponent extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $description = '';

    public string $category = '';

    public string $quietStart = '';

    public bool $notifyResident = false;

    /** @var array<int, mixed> */
    public array $photos = [];

    public function save(): void
    {
        $this->validate([
            'name' => ['required'],
        ], attributes: ['name' => 'nome']);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <form wire:submit="save">
                <x-ui.field label="Nome" name="name" layout="grid">
                    <x-ui.input wire:model="name" />
                </x-ui.field>
                <x-ui.field label="Descrição" name="description">
                    <x-ui.textarea wire:model="description" />
                </x-ui.field>
                <x-ui.select wire:model="category" placeholder="Selecione">
                    <option value="eletrica">Elétrica</option>
                </x-ui.select>
                <x-ui.time wire:model="quietStart" />
                <x-ui.toggle wire:model="notifyResident" label="Avisar morador" />
                <x-ui.file-drop wire:model="photos" multiple accept="image/*" label="+ Enviar fotos" />
            </form>
            BLADE;
    }
}

test('text controls render the design tokens', function (string $blade) {
    $this->blade($blade)
        ->assertSee('py-2', false)
        ->assertSee('border-line-strong', false)
        ->assertSee('rounded-control', false)
        ->assertSee('bg-surface-input', false)
        ->assertSee('focus:ring-accent/20', false);
})->with([
    'input' => '<x-ui.input name="name" />',
    'textarea' => '<x-ui.textarea name="body" />',
    'select' => '<x-ui.select name="category"><option>A</option></x-ui.select>',
    'time' => '<x-ui.time name="starts_at" />',
]);

test('input renders 8px 12px padding', function () {
    $this->blade('<x-ui.input name="name" />')
        ->assertSee('px-3 py-2', false)
        ->assertSee('type="text"', false);
});

test('time control renders a time input', function () {
    $this->blade('<x-ui.time name="starts_at" />')
        ->assertSee('type="time"', false)
        ->assertSee('name="starts_at"', false);
});

test('select renders placeholder before options', function () {
    $this->blade('<x-ui.select name="category" placeholder="Selecione"><option value="a">Elétrica</option></x-ui.select>')
        ->assertSeeInOrder(['Selecione', 'Elétrica']);
});

test('grid field renders a 150px label column', function () {
    $this->blade('<x-ui.field label="Cidade" name="city" layout="grid"><x-ui.input name="city" /></x-ui.field>')
        ->assertSee('grid-cols-[150px_minmax(0,1fr)]', false)
        ->assertSee('Cidade');
});

test('stacked field renders label above the control', function () {
    $this->blade('<x-ui.field label="Cidade" name="city"><x-ui.input name="city" /></x-ui.field>')
        ->assertSee('flex flex-col', false)
        ->assertSeeInOrder(['Cidade', 'name="city"'], false);
});

test('field shows the validation error below the control', function () {
    $this->withViewErrors(['city' => 'O campo cidade é obrigatório.'])
        ->blade('<x-ui.field label="Cidade" name="city"><x-ui.input name="city" /></x-ui.field>')
        ->assertSeeInOrder(['name="city"', 'O campo cidade é obrigatório.'], false)
        ->assertSee('text-tag-esc-fg', false)
        ->assertSee('aria-invalid="true"', false);
});

test('toggle renders switch dimensions and colors', function () {
    $this->blade('<x-ui.toggle label="Silêncio" :checked="true" name="quiet" />')
        ->assertSee('role="switch"', false)
        ->assertSee('aria-checked="true"', false)
        ->assertSee('h-6 w-10', false)
        ->assertSee('bg-switch-off', false)
        ->assertSee('aria-checked:bg-accent', false)
        ->assertSee('size-5 rounded-full bg-white', false)
        ->assertSee('transition-[left]', false)
        ->assertSee('name="quiet"', false);
});

test('file drop renders dashed accent button with file list and image previews', function () {
    $this->blade('<x-ui.file-drop label="+ Enviar PDF" accept="application/pdf" />')
        ->assertSee('+ Enviar PDF')
        ->assertSee('border-dashed border-black/15', false)
        ->assertSee('text-accent', false)
        ->assertSee('type="file"', false)
        ->assertSee('accept="application/pdf"', false)
        ->assertSee('data-file-list', false)
        ->assertSee('data-file-previews', false)
        ->assertSee('URL.createObjectURL', false);
});

test('form controls bind with wire:model inside livewire', function () {
    Livewire::test(FormControlsHostComponent::class)
        ->assertSeeHtml('wire:model="name"')
        ->assertSeeHtml('wire:model="description"')
        ->assertSeeHtml('wire:model="category"')
        ->assertSeeHtml('wire:model="quietStart"')
        ->assertSeeHtml('wire:model="notifyResident"')
        ->assertSeeHtml('wire:model="photos"')
        ->assertSeeHtml('wire:target="photos"')
        ->set('name', 'Aurora')
        ->set('notifyResident', true)
        ->set('quietStart', '22:00')
        ->assertSet('name', 'Aurora')
        ->assertSet('notifyResident', true)
        ->assertSet('quietStart', '22:00');
});

test('field shows pt-BR livewire validation errors', function () {
    Livewire::test(FormControlsHostComponent::class)
        ->call('save')
        ->assertHasErrors(['name' => 'required'])
        ->assertSee('O campo nome é obrigatório.');
});
