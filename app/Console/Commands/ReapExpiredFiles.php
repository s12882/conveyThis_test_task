<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\FileDeletionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('files:reap-expired')]
#[Description('Safety net: delete any files past their expiry that were not cleaned up by their delayed TTL job.')]
class ReapExpiredFiles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FileDeletionService $fileDeletionService): int
    {
        $expiredFiles = File::where('expires_at', '<=', now())->get();

        foreach ($expiredFiles as $file) {
            $fileDeletionService->delete($file->id, 'ttl_safety_net', 'local');
        }

        $this->info("Reaped {$expiredFiles->count()} expired file(s).");

        return self::SUCCESS;
    }
}
