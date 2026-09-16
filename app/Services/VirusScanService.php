<?php

namespace App\Services;

use Socket\Raw\Factory;
use Xenolope\Quahog\Client;
use Xenolope\Quahog\Result;

class VirusScanService
{
    protected string $host;

    protected int $port;

    protected int $timeout;

    public function __construct()
    {
        $this->host = config('services.clamav.host');
        $this->port = config('services.clamav.port');
        $this->timeout = config('services.clamav.timeout');
    }

    /**
     * Scan raw file contents via clamd's INSTREAM protocol.
     *
     * Connection failures are allowed to throw so the caller (a queued job)
     * retries through Laravel's normal job-retry mechanism rather than being
     * silently swallowed here.
     */
    public function scanStream(string $contents): Result
    {
        $socket = (new Factory)->createClient("tcp://{$this->host}:{$this->port}", $this->timeout);

        $client = new Client($socket, $this->timeout, PHP_NORMAL_READ);

        return $client->scanStream($contents);
    }
}
