<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFileRequest;
use App\Jobs\DeleteExpiredFile;
use App\Jobs\ScanUploadedFile;
use App\Models\File;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class FileUploadController extends Controller
{
    public function create(): View
    {
        return view('files.upload');
    }

    public function store(StoreFileRequest $request): JsonResponse
    {
        $uploaded = $request->file('file');

        $storedPath = $uploaded->store('uploads');

        $file = File::create([
            'original_name' => $uploaded->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $uploaded->getMimeType(),
            'size_bytes' => $uploaded->getSize(),
            'expires_at' => now()->addHours(config('files.ttl_hours'))
        ]);

        DeleteExpiredFile::dispatch($file->id, 'local')->delay($file->expires_at);
        ScanUploadedFile::dispatch($file->id)->onQueue('scans');

        return response()->json($file, 201);
    }
}
