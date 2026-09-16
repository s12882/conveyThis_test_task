@php
    $isActive = $sortBy === $column;
    $nextOrder = $isActive && $order === 'asc' ? 'desc' : 'asc';
@endphp
<a href="{{ request()->fullUrlWithQuery(['sort_by' => $column, 'order' => $nextOrder]) }}" class="text-decoration-none text-reset">
    {{ $label }}
    @if ($isActive)
        <span aria-hidden="true">{{ $order === 'asc' ? '▲' : '▼' }}</span>
    @endif
</a>
