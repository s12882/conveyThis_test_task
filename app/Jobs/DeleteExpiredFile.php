<?php

namespace App\Jobs;

use App\Services\FileDeletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteExpiredFile implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected int $fileId;
    protected string $disk;

    /**
     * Create a new job instance.
     */
    public function __construct(int $fileId, string $disk = 'public')
    {
        $this->fileId = $fileId;
        $this->disk = $disk;
    }

    /**
     * Execute the job.
     */
    public function handle(FileDeletionService $fileDeletionService): void
    {
        $fileDeletionService->delete($this->fileId, 'ttl_expired', $this->disk);
    }
}
