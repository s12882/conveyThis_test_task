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
        /** @var File|null $file */
        $file = File::find($id);

        if (!$file) {
            // Claude:
            // Can legitimately happen: e.g. the TTL safety-net reaper reads
            // a batch of expired file IDs, and one gets manually deleted by
            // a user before the reaper's loop reaches it.
            Log::warning("FileDeletionService: file #{$id} not found (already deleted?), skipping.");

            return false;
        }

        try {
            $file->deleteWithReason($reason);

            if (Storage::disk($disk)->exists($file->stored_path)) {
                Storage::disk($disk)->delete($file->stored_path);
            }
        } catch (Throwable $e) {
            Log::error("Failed to delete file #{$file->id} ({$file->original_name}): {$e->getMessage()}");

            return false;
        }

        try {
            app(AMPQService::class)->publishFileDeletion([
                'file_id' => $file->id,
                'original_name' => $file->original_name,
                'size_bytes' => $file->size_bytes,
                'deletion_reason' => $reason,
                'deleted_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            // Claude:
            // The file itself is already deleted at this point — a
            // notification failure (e.g. RabbitMQ temporarily unreachable)
            // shouldn't make the caller think the deletion itself failed.
            Log::error("File #{$file->id} deleted, but failed to publish the deletion-notification event: {$e->getMessage()}");
        }

        return true;
    }
}
