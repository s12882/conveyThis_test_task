@props([
    'variant' => 'secondary',
])

<span {{ $attributes->merge(['class' => 'badge bg-'.$variant]) }}>{{ $slot }}</span>
