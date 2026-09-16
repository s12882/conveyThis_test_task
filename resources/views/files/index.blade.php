@extends('layouts.app')

@section('title', 'Manage Files')

@section('content')
    <h1 class="h4 mb-3">Manage Files</h1>

    @if ($files->isEmpty())
        <p class="text-muted">No files uploaded yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>@include('files.partials.sort-link', ['column' => 'size_bytes', 'label' => 'Size'])</th>
                        <th>Status</th>
                        <th>@include('files.partials.sort-link', ['column' => 'created_at', 'label' => 'Uploaded'])</th>
                        <th>@include('files.partials.sort-link', ['column' => 'expires_at', 'label' => 'Expires'])</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($files as $file)
                        <tr>
                            <td>{{ $file->original_name }}</td>
                            <td>{{ \Illuminate\Support\Number::fileSize($file->size_bytes, precision: 1) }}</td>
                            <td>
                                @php
                                    $badgeVariant = match ($file->scan_status) {
                                        'clean' => 'success',
                                        'infected' => 'danger',
                                        'error' => 'dark',
                                        default => 'secondary',
                                    };
                                @endphp
                                <x-badge :variant="$badgeVariant">{{ ucfirst($file->scan_status) }}</x-badge>
                            </td>
                            <td>{{ $file->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $file->expires_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end">
                                <x-button
                                    variant="danger"
                                    size="sm"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#delete-modal"
                                    data-file-name="{{ $file->original_name }}"
                                    data-delete-url="{{ route('files.destroy', $file) }}"
                                >Delete</x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $files->links() }}
    @endif

    @include('files.partials.delete-modal')
@endsection
