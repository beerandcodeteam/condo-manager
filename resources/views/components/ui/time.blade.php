@props([
    'invalid' => null,
])

<x-ui.input type="time" :invalid="$invalid" {{ $attributes->class('tabular-nums') }} />
