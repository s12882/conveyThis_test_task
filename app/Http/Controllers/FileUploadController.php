<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFileRequest;
use App\Jobs\DeleteExpiredFile;
use App\Jobs\ScanUploadedFile;
use App\Models\File;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class FileUploadController extends Controller
{
    public function create(): View
    {
        return view('files.upload');
    }

    public function store(StoreFileRequest $request): JsonResponse
    {
        $uploaded = $request->file('file');

        try {
            $storedPath = $uploaded->store('uploads');
        } catch (Throwable $e) {
            // Claude:
            // E.g. the disk is full or otherwise unwritable — Laravel's
            // Flysystem-backed disks throw rather than returning false.
            Log::error('Failed to store uploaded file: '.$e->getMessage());

            return response()->json([
                'message' => 'Could not store the uploaded file. Please try again.',
            ], 503);
        }

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
