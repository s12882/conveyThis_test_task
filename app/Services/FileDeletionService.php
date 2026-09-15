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
            $file->deletion_reason = $reason;
            $file->save();

            if (Storage::disk($disk)->exists($file->stored_path)) {
                return Storage::disk($disk)->delete($file->stored_path);
            }

            //TODO publish AMQP message
            $service = app(AMPQService::class);
            $service->publishMessage();

            return true;
        } catch (Throwable $e) {
            Log::error("Failed to delete file: {$file->original_name}. Error: " . $e->getMessage());
            return false;
        }
    }
}
