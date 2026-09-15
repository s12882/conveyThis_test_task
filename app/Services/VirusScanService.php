<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class VirusScanService
{
    protected string $clamdHost;
    protected int $clamdPort;

    public function __construct()
    {
        $this->clamdHost = config('services.clamav.host', '127.0.0.1');
        $this->clamdPort = config('services.clamav.port', 3310);
    }

    public function scan(UploadedFile $file): bool
    {
        $socket = @fsockopen(
            $this->clamdHost,
            $this->clamdPort,
            $errno,
            $errstr,
            5
        );

        if (!$socket) {
            Log::error('ClamAV unavailable', ['error' => $errstr]);
            // TODO Fail closed: reschedule scan
            return false;
        }

        $fileContent = file_get_contents($file->getRealPath());
        $length = strlen($fileContent);

        fwrite($socket, "nINSTREAM\n");
        fwrite($socket, pack('N', $length) . $fileContent);
        fwrite($socket, pack('N', 0));

        $response = trim(fgets($socket));
        fclose($socket);

        return str_contains($response, 'OK');
    }
}