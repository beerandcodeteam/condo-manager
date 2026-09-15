@props([
    'name' => '',
    'size' => 28,
])

@php
    $palette = ['#5a5bd9', '#30a4c9', '#2fb35b', '#f5a623', '#e5484d', '#8e8e93'];

    $initials = Str::substr(Str::initials((string) $name, capitalize: true), 0, 2);

    $background = $palette[crc32(mb_strtolower(trim((string) $name))) % count($palette)];

    $sizeClasses = match ((int) $size) {
        24 => 'size-6 text-[10px]',
        30 => 'size-[30px] text-[12px]',
        34 => 'size-[34px] text-[12px]',
        default => 'size-7 text-[11px]',
    };
@endphp

<span
    {{ $attributes->class("inline-grid shrink-0 place-items-center rounded-full font-semibold text-white {$sizeClasses}") }}
    style="background: {{ $background }}"
    title="{{ $name }}"
>{{ $initials }}</span>
