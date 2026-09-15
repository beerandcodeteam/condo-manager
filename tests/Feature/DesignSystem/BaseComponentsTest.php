<?php

test('primary button renders accent styles and a button type', function () {
    $this->blade('<x-ui.button>Salvar</x-ui.button>')
        ->assertSee('Salvar')
        ->assertSee('type="button"', false)
        ->assertSee('bg-accent', false)
        ->assertSee('hover:bg-accent-hover', false)
        ->assertSee('text-white', false)
        ->assertSee('font-semibold', false);
});

test('secondary and ghost buttons render their variants', function () {
    $this->blade('<x-ui.button variant="secondary">Cancelar</x-ui.button>')
        ->assertSee('border-line-strong', false)
        ->assertSee('bg-white', false);

    $this->blade('<x-ui.button variant="ghost">Voltar</x-ui.button>')
        ->assertSee('bg-transparent', false)
        ->assertSee('text-ink-secondary', false);
});

test('button sizes render the expected font size and padding', function () {
    $this->blade('<x-ui.button size="sm">Novo</x-ui.button>')
        ->assertSee('text-[12px]', false)
        ->assertSee('px-3.5 py-[7px]', false);

    $this->blade('<x-ui.button>Novo</x-ui.button>')
        ->assertSee('text-[13px]', false)
        ->assertSee('py-[9px]', false);
});

test('button with href renders a link', function () {
    $this->blade('<x-ui.button href="/chamados">Ver</x-ui.button>')
        ->assertSee('<a href="/chamados"', false)
        ->assertDontSee('<button', false);
});

test('submit button keeps the given type', function () {
    $this->blade('<x-ui.button type="submit">Entrar</x-ui.button>')
        ->assertSee('type="submit"', false);
});

test('button bound to a livewire action shows a loading state for that action', function () {
    $this->blade('<x-ui.button wire:click="save">Salvar</x-ui.button>')
        ->assertSee('wire:click="save"', false)
        ->assertSee('wire:loading.attr="disabled"', false)
        ->assertSee('wire:target="save"', false)
        ->assertSee('animate-spin', false);
});

test('button without livewire action has no loading state', function () {
    $this->blade('<x-ui.button>Salvar</x-ui.button>')
        ->assertDontSee('wire:loading', false);
});

test('pill renders each tag palette', function (string $variant, string $classes) {
    $this->blade('<x-ui.pill :variant="$variant">Aberto</x-ui.pill>', ['variant' => $variant])
        ->assertSee('Aberto')
        ->assertSee($classes, false)
        ->assertSee('rounded-pill px-[9px] py-[3px] text-[11px]', false);
})->with([
    ['ia', 'bg-tag-ia-bg text-tag-ia-fg'],
    ['ok', 'bg-tag-ok-bg text-tag-ok-fg'],
    ['warn', 'bg-tag-warn-bg text-tag-warn-fg'],
    ['esc', 'bg-tag-esc-bg text-tag-esc-fg'],
    ['grey', 'bg-tag-grey-bg text-tag-grey-fg'],
]);

test('dot renders a named color class or an inline color', function () {
    $this->blade('<x-ui.dot color="red" />')
        ->assertSee('bg-dot-red', false)
        ->assertSee('size-2', false);

    $this->blade('<x-ui.dot color="#123456" size="7" />')
        ->assertSee('background: #123456', false)
        ->assertSee('size-[7px]', false);
});

test('avatar renders two initials with a deterministic palette color', function () {
    $first = (string) $this->blade('<x-ui.avatar name="renata moura silva" />');
    $second = (string) $this->blade('<x-ui.avatar name="renata moura silva" />');

    expect($first)->toBe($second)
        ->toContain('>RM</span>')
        ->toMatch('/background: #(5a5bd9|30a4c9|2fb35b|f5a623|e5484d|8e8e93)/');
});

test('avatar supports the design sizes', function (int $size, string $class) {
    $this->blade('<x-ui.avatar name="Ana" :size="$size" />', ['size' => $size])
        ->assertSee($class, false)
        ->assertSee('>A</span>', false);
})->with([
    [24, 'size-6'],
    [28, 'size-7'],
    [30, 'size-[30px]'],
    [34, 'size-[34px]'],
]);

test('count badge renders an accent pill', function () {
    $this->blade('<x-ui.count-badge>3</x-ui.count-badge>')
        ->assertSee('3')
        ->assertSee('bg-accent', false)
        ->assertSee('rounded-pill', false)
        ->assertSee('text-[11px]', false);
});
