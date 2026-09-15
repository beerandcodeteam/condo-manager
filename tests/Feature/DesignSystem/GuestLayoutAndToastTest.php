<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Livewire\Livewire;

class ToastDispatchingComponent extends Component
{
    public function save(): void
    {
        $this->dispatch('toast', type: 'success', message: 'Chamado atualizado.');
    }

    public function render(): string
    {
        return '<div><button wire:click="save">Salvar</button></div>';
    }
}

beforeEach(function () {
    $this->withoutVite();
});

test('guest layout renders a 360px central card with the product name', function () {
    $this->blade('<x-layouts::guest title="Entrar">Formulário de login</x-layouts::guest>')
        ->assertSee('<title>Entrar · Síndico Conversacional</title>', false)
        ->assertSee('bg-canvas', false)
        ->assertSee('place-items-center', false)
        ->assertSee('w-[360px]', false)
        ->assertSee('rounded-card border border-line bg-white', false)
        ->assertSeeInOrder(['Síndico Conversacional', 'Formulário de login']);
});

test('toast is rendered in the top right corner and hides after 4 seconds', function () {
    $this->blade('<x-ui.toast />')
        ->assertSee('fixed top-4 right-4', false)
        ->assertSee('setTimeout(() => this.dismiss(id), 4000)', false)
        ->assertSee('x-on:toast.window="push($event.detail)"', false)
        ->assertSee('bg-tag-ok-bg text-tag-ok-fg', false)
        ->assertSee('bg-tag-esc-bg text-tag-esc-fg', false);
});

test('toast shows success and error session flash messages', function () {
    Route::get('/_toast-test', fn () => view('layouts.guest', ['slot' => new HtmlString('Login')]))
        ->middleware('web');

    $this->withSession(['success' => 'Morador salvo.', 'error' => 'Falha ao enviar.'])
        ->get('/_toast-test')
        ->assertOk()
        ->assertSee('Morador salvo.')
        ->assertSee('Falha ao enviar.');
});

test('toast is included in both layouts', function () {
    $this->blade('<x-layouts::guest>Login</x-layouts::guest>')->assertSee('data-toast-stack', false);
    $this->blade('<x-layouts::app title="Visão geral">Painel</x-layouts::app>')->assertSee('data-toast-stack', false);
});

test('livewire components trigger toasts through the toast event', function () {
    Livewire::test(ToastDispatchingComponent::class)
        ->call('save')
        ->assertDispatched('toast', type: 'success', message: 'Chamado atualizado.');
});
