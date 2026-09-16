@extends('layouts.app')

@section('title', 'Upload a file')

@section('content')
    <div class="card">
        <div class="card-body">
            <h1 class="h4 card-title mb-3">Upload a file</h1>
            <p class="text-muted">PDF or DOCX, up to {{ (int) (config('files.max_size_kb') / 1024) }}MB.</p>

            <form
                id="upload-form"
                novalidate
                data-upload-url="{{ route('files.store') }}"
                data-max-size-bytes="{{ config('files.max_size_kb') * 1024 }}"
                data-allowed-extensions="pdf,docx"
            >
                @csrf

                <div class="mb-3">
                    <input
                        type="file"
                        class="form-control"
                        id="file-input"
                        name="file"
                        accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        required
                    >
                    <div class="invalid-feedback" id="file-feedback"></div>
                </div>

                <div class="progress mb-3 d-none" id="upload-progress" style="height: 6px;">
                    <div class="progress-bar" role="progressbar" style="width: 0"></div>
                </div>

                <x-button variant="primary" type="submit" id="upload-submit">Upload</x-button>
            </form>
        </div>
    </div>
@endsection
