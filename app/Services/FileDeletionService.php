<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FileDeletionService
{
    public function delete(int $id, string $reason, string $disk = 'public'): bool
    {
        try {
            /** @var File $file */
            $file = File::find($id);
            $file->deleteWithReason($reason);

            if (Storage::disk($disk)->exists($file->stored_path)) {
                Storage::disk($disk)->delete($file->stored_path);
            }

            app(AMPQService::class)->publishFileDeletion([
                'file_id' => $file->id,
                'original_name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'deletion_reason' => $reason,
                'deleted_at' => now()->toIso8601String(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error("Failed to delete file: {$file->original_name}. Error: " . $e->getMessage());
            return false;
        }
    }
}
