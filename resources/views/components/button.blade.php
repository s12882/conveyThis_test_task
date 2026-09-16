@props([
    'variant' => 'primary',
    'size' => null,
    'type' => 'button',
])

<button
    type="{{ $type }}"
    {{ $attributes->merge(['class' => 'btn btn-'.$variant.($size ? ' btn-'.$size : '')]) }}
>{{ $slot }}</button>
