<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\VirusScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ScanUploadedFile implements ShouldQueue
{
    use Queueable;

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
    public function handle(VirusScanService $scanner): void
    {
        /** @var File $file */
        $file = File::find($this->fileId);

        if (Storage::disk('public')->exists($file->stored_path)) {
            // TODO implement scan with VirusScanService
            $file->scan_status = 'clean'; // TODO: create status ENUM
            $file->save();
        }
    }
}
